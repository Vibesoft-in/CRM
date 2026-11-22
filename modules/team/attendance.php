<?php
// modules/team/attendance.php — Daily Attendance Marking
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

// Run migration if tables don't exist
try {
    $pdo->query("SELECT 1 FROM attendance LIMIT 1");
} catch (Exception $e) {
    $sql = file_get_contents(__DIR__ . '/../../database/migration_attendance.sql');
    $pdo->exec($sql);
}

// Settings
$settings = $pdo->query("SELECT * FROM attendance_settings LIMIT 1")->fetch();
if (!$settings) {
    $settings = ['expected_check_in'=>'10:00:00','expected_check_out'=>'19:00:00',
                 'grace_minutes'=>15,'late_deduction_per_hour'=>50,
                 'half_day_threshold_hours'=>4,'working_days_per_month'=>26,'overtime_after_hours'=>9];
}

$viewDate = $_GET['date'] ?? date('Y-m-d');
$csrf = csrf_token();

// Auto-process daily attendance rules (5 PM cutoff & 8 PM checkout cutoff)
auto_process_daily_attendance($pdo, $viewDate);

// Fetch all active employees
$employees = $pdo->query("SELECT id, full_name, employee_code, designation, department FROM employees WHERE status='active' ORDER BY full_name ASC")->fetchAll();

// Fetch today's attendance for all employees
$attStmt = $pdo->prepare("SELECT * FROM attendance WHERE attendance_date = ?");
$attStmt->execute([$viewDate]);

$attRecords = [];
foreach ($attStmt->fetchAll() as $row) {
    $attRecords[$row['employee_id']] = $row;
}

// Fetch breaks / lunch sessions for viewDate
$brkStmt = $pdo->prepare("SELECT * FROM lunch_sessions WHERE session_date = ? ORDER BY employee_id, id ASC");
$brkStmt->execute([$viewDate]);
$breaksByEmp = [];
foreach ($brkStmt->fetchAll(PDO::FETCH_ASSOC) as $bs) {
    $breaksByEmp[$bs['employee_id']][] = $bs;
}

// Summary counts
$presentCount = 0; $absentCount = 0; $lateCount = 0; $halfDayCount = 0; $leaveCount = 0; $wfhCount = 0;
foreach ($attRecords as $a) {
    if (in_array($a['status'], ['present','late'])) $presentCount++;
    if ($a['status'] === 'absent') $absentCount++;
    if ($a['status'] === 'late') $lateCount++;
    if ($a['status'] === 'half_day') $halfDayCount++;
    if ($a['status'] === 'on_leave') $leaveCount++;
    if ($a['status'] === 'work_from_home') $wfhCount++;
}
$unmarkedCount = count($employees) - count($attRecords);
?>
<style>
.att-card { border-radius: 14px; transition: all .2s; }
.att-card:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(0,0,0,.1)!important; }
.status-badge { font-size: .72rem; padding: .3em .75em; border-radius: 50rem; font-weight: 600; }
.s-present  { background:#d1fae5; color:#065f46; }
.s-late     { background:#fef3c7; color:#92400e; }
.s-absent   { background:#fee2e2; color:#991b1b; }
.s-half_day { background:#e0f2fe; color:#0369a1; }
.s-on_leave { background:#ede9fe; color:#5b21b6; }
.s-work_from_home { background:#d1fae5; color:#065f46; }
.s-holiday  { background:#f0fdf4; color:#166534; }
.s-unmarked { background:#f1f5f9; color:#64748b; }
.emp-row { border-radius: 10px; padding: .7rem 1rem; background:#fff; margin-bottom: .5rem; border: 1px solid #e9ecef; transition: all .2s; }
.emp-row:hover { border-color: #0d6efd; box-shadow: 0 4px 15px rgba(13,110,253,.08); }
.summary-stat { text-align: center; padding: 1rem; border-radius: 14px; }
.time-badge { font-size: .75rem; color: #64748b; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: .15em .5em; }
</style>

<div class="container-fluid py-4">

  <!-- Header -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h3 class="fw-bold mb-0"><i class="bi bi-calendar-check text-primary me-2"></i>Attendance</h3>
      <p class="text-muted mb-0 small">Mark and track daily attendance for all team members</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <form method="get" class="d-flex gap-2 align-items-center">
        <input type="date" name="date" value="<?= htmlspecialchars($viewDate) ?>" class="form-control form-control-sm" onchange="this.form.submit()">
      </form>
      <button class="btn btn-sm btn-outline-secondary" onclick="gotoToday()"><i class="bi bi-calendar-today me-1"></i>Today</button>
      <button class="btn btn-sm btn-outline-warning" onclick="recalculateLate('<?= $viewDate ?>')" title="Re-evaluate late arrivals using current grace period (<?= $settings['grace_minutes'] ?>m)">
        <i class="bi bi-arrow-repeat me-1"></i>Recalculate Late (<?= date('d M', strtotime($viewDate)) ?>)
      </button>
      <button class="btn btn-sm btn-outline-danger" onclick="bulkAbsent()" title="Mark all unmarked as Absent">
        <i class="bi bi-person-dash me-1"></i>Mark Unmarked Absent
      </button>
      <button class="btn btn-sm btn-outline-info" onclick="processDailyRules('<?= $viewDate ?>')" title="Auto-process 5 PM Absent & 8 PM Half Day Rules for this date">
        <i class="bi bi-clock-history me-1"></i>Sync Rules (8 PM)
      </button>
      <a href="holidays.php" class="btn btn-sm btn-outline-primary" title="Manage Company & Festival Holidays">
        <i class="bi bi-calendar-event me-1"></i>Holidays
      </a>
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#settingsModal">
        <i class="bi bi-gear me-1"></i>Settings
      </button>
    </div>
  </div>

  <!-- Date Navigation -->
  <div class="d-flex align-items-center gap-3 mb-4">
    <a href="?date=<?= date('Y-m-d', strtotime($viewDate . ' -1 day')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
    <h5 class="mb-0 fw-semibold"><?= date('l, d F Y', strtotime($viewDate)) ?></h5>
    <a href="?date=<?= date('Y-m-d', strtotime($viewDate . ' +1 day')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-right"></i></a>
  </div>

  <!-- Summary Cards -->
  <div class="row g-3 mb-4">
    <?php
    $summaries = [
      ['Present', $presentCount, 'success', 'person-check-fill'],
      ['Absent', $absentCount, 'danger', 'person-x-fill'],
      ['Late', $lateCount, 'warning', 'clock-history'],
      ['Half Day', $halfDayCount, 'info', 'calendar-half'],
      ['On Leave', $leaveCount, 'purple', 'calendar2-x'],
      ['WFH', $wfhCount, 'teal', 'house-check'],
      ['Unmarked', $unmarkedCount, 'secondary', 'question-circle'],
      ['Total', count($employees), 'dark', 'people-fill'],
    ];
    foreach ($summaries as [$label, $count, $color, $icon]): ?>
    <div class="col-6 col-md-3 col-xl-<?= count($summaries) > 6 ? '3' : '2' ?>">
      <div class="summary-stat bg-<?= $color === 'purple' ? 'white' : 'white' ?> border shadow-sm">
        <div class="fs-2 text-<?= $color === 'teal' ? 'success' : $color ?> mb-1"><i class="bi bi-<?= $icon ?>"></i></div>
        <div class="fs-3 fw-bold text-<?= $color === 'teal' ? 'success' : $color ?>"><?= $count ?></div>
        <div class="text-muted small"><?= $label ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Employee List -->
  <div class="card border-0 shadow-sm" style="border-radius:14px;">
    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center py-3" style="border-radius:14px 14px 0 0;">
      <h6 class="fw-semibold mb-0"><i class="bi bi-people me-2 text-primary"></i>Team Attendance — <?= date('d M Y', strtotime($viewDate)) ?></h6>
      <div class="input-group input-group-sm" style="max-width:220px;">
        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
        <input type="text" id="empSearch" class="form-control bg-light" placeholder="Search employee...">
      </div>
    </div>
    <div class="card-body p-3" id="employeeListWrap">
      <?php foreach ($employees as $emp): 
        $att = $attRecords[$emp['id']] ?? null;
        $status = $att['status'] ?? 'unmarked';
        $checkIn  = !empty($att['check_in'])  ? date('h:i:s A', strtotime($att['check_in']))  : '—';
        $checkOut = !empty($att['check_out']) ? date('h:i:s A', strtotime($att['check_out'])) : '—';
        $lateMin = (int)($att['late_minutes'] ?? 0);
        $totalHrs = (float)($att['total_hours'] ?? 0);

        // Break details
        $empBreaks = $breaksByEmp[$emp['id']] ?? [];
        $empBreakSec = 0;
        $hasActiveBreak = false;
        $breakTimings = [];
        foreach ($empBreaks as $eb) {
            if ($eb['status'] === 'active') {
                $hasActiveBreak = true;
                if (!empty($eb['start_time'])) {
                    $startSec = strtotime($viewDate . ' ' . $eb['start_time']);
                    $empBreakSec += max(0, time() - $startSec);
                    $breakTimings[] = date('h:i A', strtotime($eb['start_time'])) . ' - now (active)';
                }
            } else {
                $empBreakSec += (int)($eb['duration_seconds'] ?? 0);
                $st = !empty($eb['start_time']) ? date('h:i A', strtotime($eb['start_time'])) : '';
                $et = !empty($eb['end_time']) ? date('h:i A', strtotime($eb['end_time'])) : '';
                if ($st && $et) $breakTimings[] = "$st - $et";
            }
        }
      ?>
      <div class="emp-row d-flex flex-wrap align-items-center gap-2" data-name="<?= strtolower(htmlspecialchars($emp['full_name'])) ?>">
        <div class="flex-shrink-0">
          <img src="https://ui-avatars.com/api/?name=<?= urlencode($emp['full_name']) ?>&background=0d6efd&color=fff&size=40" class="rounded-circle" width="40" height="40" alt="">
        </div>
        <div class="flex-grow-1">
          <div class="fw-semibold small"><?= htmlspecialchars($emp['full_name']) ?></div>
          <div class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars($emp['employee_code']) ?> · <?= htmlspecialchars($emp['designation'] ?? '') ?></div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <?php if ($att): ?>
            <span class="status-badge s-<?= $status ?>"><?= ucwords(str_replace('_', ' ', $status)) ?></span>
            <?php if ($checkIn !== '—'): ?>
              <span class="time-badge"><i class="bi bi-box-arrow-in-right me-1"></i><?= $checkIn ?></span>
            <?php endif; ?>
            <?php if ($checkOut !== '—'): ?>
              <span class="time-badge"><i class="bi bi-box-arrow-right me-1"></i><?= $checkOut ?></span>
            <?php endif; ?>
            <?php if ($hasActiveBreak): ?>
              <span class="badge bg-warning text-dark" style="font-size:.68rem;" title="<?= htmlspecialchars(implode(', ', $breakTimings)) ?>">
                <i class="bi bi-cup-hot-fill me-1"></i>On Break (<?= format_minutes_hms((int)round($empBreakSec / 60), true) ?>)
              </span>
            <?php elseif ($empBreakSec > 0): ?>
              <span class="badge bg-light text-secondary border" style="font-size:.68rem;" title="<?= htmlspecialchars(implode(', ', $breakTimings)) ?>">
                <i class="bi bi-cup-hot me-1 text-primary"></i>Break: <?= format_minutes_hms((int)round($empBreakSec / 60), true) ?>
              </span>
            <?php endif; ?>
            <?php if ($lateMin > 0): ?>
              <span class="badge bg-warning text-dark" style="font-size:.68rem;"><i class="bi bi-clock me-1"></i><?= format_minutes_hms($lateMin, true) ?> late</span>
            <?php endif; ?>
            <?php if ($totalHrs > 0): ?>
              <span class="badge bg-light text-secondary border" style="font-size:.68rem;"><i class="bi bi-stopwatch me-1"></i><?= format_hours_hms($totalHrs, true) ?></span>
            <?php endif; ?>
          <?php else: ?>
            <span class="status-badge s-unmarked">Not Marked</span>
          <?php endif; ?>
        </div>
        <button class="btn btn-sm btn-primary" style="font-size:.75rem; border-radius:8px;"
          onclick="openMarkModal(<?= $emp['id'] ?>, '<?= htmlspecialchars($emp['full_name']) ?>', '<?= htmlspecialchars($att['status'] ?? '') ?>', '<?= $att['check_in'] ?? '' ?>', '<?= $att['check_out'] ?? '' ?>', '<?= htmlspecialchars($att['notes'] ?? '') ?>', '<?= htmlspecialchars($att['leave_type'] ?? '') ?>')">
          <i class="bi bi-pencil-square"></i> <?= $att ? 'Edit' : 'Mark' ?>
        </button>
      </div>
      <?php endforeach; ?>
      <?php if (empty($employees)): ?>
        <div class="text-center text-muted py-5"><i class="bi bi-people fs-1 d-block mb-2"></i>No active employees found.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ================= MARK ATTENDANCE MODAL ================= -->
<div class="modal fade" id="markModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-calendar-check text-primary me-2"></i>Mark Attendance</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-2">
        <div class="alert alert-light border mb-3 py-2">
          <i class="bi bi-person-circle me-2 text-primary"></i><strong id="markEmpName">Employee</strong>
          <span class="text-muted small ms-2">· <?= date('d M Y', strtotime($viewDate)) ?></span>
        </div>
        <form id="markForm">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="mark_attendance">
          <input type="hidden" name="employee_id" id="markEmpId">
          <input type="hidden" name="date" value="<?= $viewDate ?>">

          <div class="mb-3">
            <label class="form-label fw-semibold small">Status</label>
            <div class="d-flex flex-wrap gap-2" id="statusBtns">
              <?php
              $statuses = [
                ['present','Present','success'],
                ['absent','Absent','danger'],
                ['late','Late','warning'],
                ['half_day','Half Day','info'],
                ['on_leave','On Leave','secondary'],
                ['work_from_home','WFH','teal'],
                ['holiday','Holiday','dark'],
              ];
              foreach ($statuses as [$val, $label, $color]):
              ?>
              <button type="button" class="btn btn-sm btn-outline-<?= $color === 'teal' ? 'success' : $color ?> status-opt" data-val="<?= $val ?>"><?= $label ?></button>
              <?php endforeach; ?>
            </div>
            <input type="hidden" name="status" id="markStatus" value="present">
          </div>

          <div class="row g-3 mb-3" id="timeFields">
            <div class="col-6">
              <label class="form-label fw-semibold small">Check-In Time</label>
              <input type="time" step="1" name="check_in" id="markCheckIn" class="form-control">
              <small class="text-muted">Expected: <?= date('h:i A', strtotime($settings['expected_check_in'])) ?> (+<?= $settings['grace_minutes'] ?>m grace)</small>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold small">Check-Out Time</label>
              <input type="time" step="1" name="check_out" id="markCheckOut" class="form-control">
              <small class="text-muted">Expected: <?= date('h:i A', strtotime($settings['expected_check_out'])) ?></small>
            </div>
          </div>

          <div class="mb-3" id="leaveTypeField" style="display:none;">
            <label class="form-label fw-semibold small">Leave Type</label>
            <select name="leave_type" class="form-select form-select-sm">
              <option value="">Select Leave Type</option>
              <option>Sick Leave</option><option>Casual Leave</option>
              <option>Earned Leave</option><option>Personal Leave</option>
              <option>Emergency Leave</option><option>Unpaid Leave</option>
            </select>
          </div>

          <div id="markZeroHoursGroup" class="p-2 px-3 mb-3 rounded-3 border border-danger-subtle bg-danger-subtle bg-opacity-25" style="display:none;">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="zero_hours" id="markZeroHoursCheck" value="1" checked>
              <label class="form-check-label small fw-semibold text-danger" for="markZeroHoursCheck">
                <i class="bi bi-shield-x me-1"></i> Reset worked hours & minutes to 0
              </label>
            </div>
            <div class="text-muted small mt-1" style="font-size:0.75rem;">
              Clears punch-in, punch-out, and lunch breaks. Working hours on this day will be 0h 00m.
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small">Notes (Optional)</label>
            <textarea name="notes" id="markNotes" class="form-control form-control-sm" rows="2" placeholder="Any remarks..."></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer border-0">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="submitMark()">
          <i class="bi bi-check-circle me-1"></i>Save Attendance
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ================= SETTINGS MODAL ================= -->
<div class="modal fade" id="settingsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
      <div class="modal-header border-0"><h5 class="modal-title fw-bold"><i class="bi bi-gear me-2 text-primary"></i>Attendance Settings</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="settingsForm">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="update_settings">
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label small fw-semibold">Expected Check-In</label>
              <input type="time" name="expected_check_in" class="form-control form-control-sm" value="<?= substr($settings['expected_check_in'],0,5) ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Expected Check-Out</label>
              <input type="time" name="expected_check_out" class="form-control form-control-sm" value="<?= substr($settings['expected_check_out'],0,5) ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Grace Period (minutes)</label>
              <input type="number" name="grace_minutes" class="form-control form-control-sm" value="<?= $settings['grace_minutes'] ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Late Deduction / Hour (₹)</label>
              <input type="number" step="0.01" name="late_deduction_per_hour" class="form-control form-control-sm" value="<?= $settings['late_deduction_per_hour'] ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Half Day Threshold (hrs)</label>
              <input type="number" step="0.5" name="half_day_threshold_hours" class="form-control form-control-sm" value="<?= $settings['half_day_threshold_hours'] ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Working Days / Month</label>
              <input type="number" name="working_days_per_month" class="form-control form-control-sm" value="<?= $settings['working_days_per_month'] ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Overtime After (hrs)</label>
              <input type="number" step="0.5" name="overtime_after_hours" class="form-control form-control-sm" value="<?= $settings['overtime_after_hours'] ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Daily Shift Target (hrs)</label>
              <input type="number" step="0.25" name="daily_target_hours" class="form-control form-control-sm" value="<?= htmlspecialchars($settings['daily_target_hours'] ?? '7.00') ?>" required>
              <small class="text-muted" style="font-size:0.7rem;">Default 7h / working day</small>
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Annual Allowed Leaves</label>
              <input type="number" name="annual_allowed_leaves" class="form-control form-control-sm" value="<?= htmlspecialchars($settings['annual_allowed_leaves'] ?? '4') ?>" required>
              <small class="text-muted" style="font-size:0.7rem;">Default 4 leaves / year</small>
            </div>

            <!-- Geofencing & Office Location -->
            <div class="col-12 mt-3 pt-2 border-top">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-geo-alt-fill text-danger me-1"></i>Office Geofencing & Location Radius</h6>
                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 fw-semibold" onclick="detectAdminCurrentLocation()">
                  <i class="bi bi-crosshair me-1"></i>Set Current Location
                </button>
              </div>
              <small class="text-muted d-block mb-2">Employees must be within office radius to mark attendance. Outside punches are blocked.</small>
            </div>

            <div class="col-12 mb-2">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="geofence_enabled" id="geoEnabled" value="1" <?= !empty($settings['geofence_enabled']) ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold small text-dark" for="geoEnabled">
                  Enforce Office Location Geofence (Require GPS verification)
                </label>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Office Latitude</label>
              <input type="text" name="office_latitude" id="settingOfficeLat" class="form-control form-control-sm font-monospace" value="<?= htmlspecialchars($settings['office_latitude'] ?? '29.22006923') ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Office Longitude</label>
              <input type="text" name="office_longitude" id="settingOfficeLng" class="form-control form-control-sm font-monospace" value="<?= htmlspecialchars($settings['office_longitude'] ?? '78.96778362') ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Allowed Radius (Meters)</label>
              <input type="number" name="geofence_radius_meters" class="form-control form-control-sm" value="<?= htmlspecialchars($settings['geofence_radius_meters'] ?? '200') ?>" placeholder="e.g. 200">
              <small class="text-muted" style="font-size:0.7rem;">Default 200m. Covers office premises.</small>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Office Name / Address</label>
              <input type="text" name="office_address" class="form-control form-control-sm" value="<?= htmlspecialchars($settings['office_address'] ?? 'Vibesoft, Chamunda Vihar Phase 1, Ramnagar Road, Kashipur, Uttarakhand 244713') ?>">
            </div>

            <div class="col-12 mt-2 pt-2 border-top">
              <label class="form-label small fw-semibold text-dark mb-1"><i class="bi bi-arrow-repeat me-1 text-primary"></i>Late Re-evaluation on Save:</label>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="recalculate_scope" id="scopeDay" value="<?= $viewDate ?>" checked>
                <label class="form-check-label small fw-semibold text-primary" for="scopeDay">
                  Recalculate late status for this viewing date (<?= date('d M Y', strtotime($viewDate)) ?>)
                </label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="recalculate_scope" id="scopeAll" value="all">
                <label class="form-check-label small text-muted" for="scopeAll">
                  Recalculate late status for all attendance records
                </label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="recalculate_scope" id="scopeNone" value="">
                <label class="form-check-label small text-muted" for="scopeNone">
                  Save settings only (do not touch existing records)
                </label>
              </div>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer border-0">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="saveSettings()"><i class="bi bi-save me-1"></i>Save Settings</button>
      </div>
    </div>
  </div>
</div>

<script>
const API = '<?= BASE_URL ?>modules/team/attendance_api.php';

function gotoToday() {
  const today = new Date().toISOString().split('T')[0];
  window.location.href = '?date=' + today;
}

// Employee search
document.getElementById('empSearch').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('.emp-row').forEach(row => {
    row.style.display = row.dataset.name.includes(q) ? '' : 'none';
  });
});

// Mark modal
function openMarkModal(empId, empName, status, checkIn, checkOut, notes, leaveType) {
  document.getElementById('markEmpName').textContent = empName;
  document.getElementById('markEmpId').value = empId;
  document.getElementById('markCheckIn').value = (checkIn && checkIn.length === 5) ? checkIn + ':00' : (checkIn || '');
  document.getElementById('markCheckOut').value = (checkOut && checkOut.length === 5) ? checkOut + ':00' : (checkOut || '');
  document.getElementById('markNotes').value = notes || '';
  setStatus(status || 'present');
  new bootstrap.Modal('#markModal').show();
}

function setStatus(val) {
  document.getElementById('markStatus').value = val;
  document.querySelectorAll('.status-opt').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.val === val);
    btn.classList.toggle('fw-bold', btn.dataset.val === val);
  });
  const needsLeave = (val === 'on_leave');
  document.getElementById('leaveTypeField').style.display = needsLeave ? '' : 'none';
  const noTime = ['absent','holiday','on_leave'].includes(val);
  document.getElementById('timeFields').style.display = noTime ? 'none' : '';
  const zeroGroup = document.getElementById('markZeroHoursGroup');
  if (zeroGroup) zeroGroup.style.display = (val === 'absent' || val === 'on_leave') ? '' : 'none';
}

document.querySelectorAll('.status-opt').forEach(btn => {
  btn.addEventListener('click', () => setStatus(btn.dataset.val));
});

function submitMark() {
  const form = document.getElementById('markForm');
  const data = new FormData(form);
  fetch(API, { method: 'POST', body: data })
    .then(r => r.json())
    .then(res => {
      if (res.success) { location.reload(); }
      else alert(res.msg || 'Error saving attendance');
    });
}

function processDailyRules(date) {
  if (!confirm('Run 5:00 PM Absent & 8:00 PM Half Day Rules for ' + date + '?')) return;
  const data = new FormData();
  data.append('action', 'auto_process_attendance');
  data.append('csrf', '<?= $csrf ?>');
  data.append('date', date);

  fetch(API, { method: 'POST', body: data })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        alert(res.msg || 'Daily attendance rules processed!');
        location.reload();
      } else {
        alert(res.msg || 'Error processing daily attendance');
      }
    });
}

function bulkAbsent() {
  if (!confirm('Mark all unmarked employees as Absent for this date?')) return;
  fetch(API, {
    method: 'POST',
    body: new URLSearchParams({ action: 'bulk_absent', date: '<?= $viewDate ?>' })
  }).then(r => r.json()).then(() => location.reload());
}

function saveSettings() {
  const form = document.getElementById('settingsForm');
  const data = new FormData(form);
  fetch(API, { method: 'POST', body: data })
    .then(r => r.json())
    .then(res => {
      if (res.success) { bootstrap.Modal.getInstance('#settingsModal').hide(); location.reload(); }
      else alert(res.msg || 'Error saving settings');
    });
}

function detectAdminCurrentLocation() {
  if (!navigator.geolocation) {
    alert('Geolocation is not supported by your browser.');
    return;
  }
  navigator.geolocation.getCurrentPosition(pos => {
    document.getElementById('settingOfficeLat').value = pos.coords.latitude.toFixed(8);
    document.getElementById('settingOfficeLng').value = pos.coords.longitude.toFixed(8);
    alert('Live Office Coordinates Captured:\nLatitude: ' + pos.coords.latitude.toFixed(6) + '\nLongitude: ' + pos.coords.longitude.toFixed(6));
  }, err => {
    alert('Unable to capture location: ' + err.message + '\nPlease check device GPS or browser location permission.');
  }, { enableHighAccuracy: true, timeout: 10000 });
}

function recalculateLate(date) {
  if (!confirm('Recalculate late arrivals for ' + date + ' using currently configured grace period?')) return;
  const data = new FormData();
  data.append('action', 'recalculate_late');
  data.append('csrf', '<?= $csrf ?>');
  data.append('date', date);

  fetch(API, { method: 'POST', body: data })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        alert(res.msg || 'Late arrivals successfully recalculated!');
        location.reload();
      } else {
        alert(res.msg || 'Error recalculating late records');
      }
    });
}
</script>

<?php require_once '../../includes/footer.php'; ?>
