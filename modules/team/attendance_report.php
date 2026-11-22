<?php
// modules/team/attendance_report.php — Monthly Attendance Report
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

// Filters
$filterEmp   = intval($_GET['emp'] ?? 0);
$filterMonth = intval($_GET['month'] ?? date('m'));
$filterYear  = intval($_GET['year'] ?? date('Y'));

$employees = $pdo->query("SELECT id, full_name, employee_code, designation, department, base_salary FROM employees WHERE status='active' ORDER BY full_name ASC")->fetchAll();

// Settings
$settings = $pdo->query("SELECT * FROM attendance_settings LIMIT 1")->fetch();
if (!$settings) $settings = ['expected_check_in'=>'10:00:00','expected_check_out'=>'19:00:00','grace_minutes'=>15,'late_deduction_per_hour'=>50,'half_day_threshold_hours'=>4,'working_days_per_month'=>26];

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $filterMonth, $filterYear);
$monthStart  = "$filterYear-" . str_pad($filterMonth,2,'0',STR_PAD_LEFT) . "-01";
$monthEnd    = "$filterYear-" . str_pad($filterMonth,2,'0',STR_PAD_LEFT) . "-$daysInMonth";
$holidaysMap  = get_active_holidays_map($pdo, (int)$filterYear);
$overridesMap = get_holiday_overrides_map($pdo, (int)$filterYear);

// Fetch attendance for the selected employee (or all) using the exact same A & R engine
auto_process_daily_attendance($pdo);

$empData = [];
foreach ($employees as $emp) {
    if ($filterEmp > 0 && (int)$emp['id'] !== $filterEmp) continue;

    $summary = get_employee_month_attendance_summary($pdo, (int)$emp['id'], (int)$filterYear, (int)$filterMonth, $holidaysMap, $overridesMap);

    $empData[] = [
        'emp'              => $emp,
        'summary'          => $summary,
        'attByDate'        => $summary['att_by_date'],
        'breaksByDate'     => $summary['breaks_by_date'],
        'dailyRows'        => $summary['daily_rows'],
        'present'          => $summary['present_days'],
        'absent'           => $summary['absent_days'],
        'half_day'         => $summary['half_days'],
        'late_days'        => $summary['late_days'],
        'on_leave'         => $summary['leave_days'],
        'holiday'          => $summary['total_holidays'],
        'total_late_mins'  => $summary['total_late_mins'],
        'total_late_hrs'   => $summary['total_late_hrs'],
        'total_hours'      => $summary['total_work_hours'],
        'total_overtime'   => 0,
        'total_break_sec'  => $summary['total_break_sec'],
        'total_break_mins' => $summary['total_break_mins'],
        'total_break_hrs'  => $summary['total_break_hrs'],
    ];
}

$statusColors = [
    'present'        => ['bg'=>'#d1fae5','text'=>'#065f46','label'=>'P',  'name'=>'Present'],
    'late'           => ['bg'=>'#fef3c7','text'=>'#92400e','label'=>'L',  'name'=>'Late'],
    'absent'         => ['bg'=>'#fee2e2','text'=>'#991b1b','label'=>'A',  'name'=>'Absent'],
    'half_day'       => ['bg'=>'#e0f2fe','text'=>'#0369a1','label'=>'HD', 'name'=>'Half Day'],
    'on_leave'       => ['bg'=>'#ede9fe','text'=>'#5b21b6','label'=>'LV', 'name'=>'On Leave'],
    'work_from_home' => ['bg'=>'#dcfce7','text'=>'#166534','label'=>'W',  'name'=>'Work From Home'],
    'holiday'        => ['bg'=>'#fef3c7','text'=>'#b45309','label'=>'H',  'name'=>'Holiday / Sunday'],
];
?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
<style>
.material-symbols-outlined {
  font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
  vertical-align: middle;
  line-height: 1;
}
.cal-cell { width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: .7rem; font-weight: 700; cursor: default; position: relative; }
.cal-cell:hover .cal-tooltip { display: block; }
.cal-tooltip { display: none; position: absolute; bottom: 110%; left: 50%; transform: translateX(-50%); background: #1e293b; color: #fff; font-size: .65rem; border-radius: 6px; padding: .2em .5em; white-space: nowrap; z-index: 10; }
.stat-pill { display: inline-flex; align-items: center; gap: .4em; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 50rem; padding: .2em .75em; font-size: .75rem; font-weight: 600; }
.emp-section { border: 1px solid #e9ecef; border-radius: 14px; background: #fff; overflow: hidden; margin-bottom: 1.5rem; transition: box-shadow 0.2s ease; }
.emp-section:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.06); }
.emp-section-header { background: linear-gradient(135deg, #f8fafc, #f1f5f9); padding: 1rem 1.25rem; border-bottom: 1px solid #e9ecef; user-select: none; }
.att-table td, .att-table th { padding: .5rem .75rem; font-size: .8rem; }
.att-table th { background: #f8fafc; font-weight: 600; }
.status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.summary-bar { background: #f8fafc; border-radius: 10px; padding: 1rem; }

.emp-collapse-btn {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: #ffffff;
  color: #475569;
  transition: all 0.2s ease;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #cbd5e1;
}
.emp-collapse-btn:hover {
  background: #0d6efd;
  color: #ffffff;
  border-color: #0d6efd;
}
.emp-chevron {
  transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
  font-size: 20px;
  line-height: 1;
}
.emp-section-header.collapsed .emp-chevron,
.emp-collapse-btn.collapsed .emp-chevron,
.emp-collapse-btn[aria-expanded="false"] .emp-chevron {
  transform: rotate(-90deg);
}
</style>

<div class="container-fluid py-4">

  <!-- Header -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h3 class="fw-bold mb-0 d-flex align-items-center">
        <span class="material-symbols-outlined text-info me-2 fs-2">analytics</span>
        <span>Attendance Report</span>
      </h3>
      <p class="text-muted mb-0 small">Monthly attendance summary — present, absent, late, and hours</p>
    </div>
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center" id="toggleAllBtn" onclick="toggleAllSections()">
        <span class="material-symbols-outlined me-1" style="font-size:18px;">unfold_less</span>
        <span>Collapse All</span>
      </button>
      <button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center" onclick="window.print()">
        <span class="material-symbols-outlined me-1" style="font-size:18px;">print</span>
        <span>Print Report</span>
      </button>
    </div>
  </div>

  <!-- Filters -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
    <div class="card-body py-3">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-sm-4 col-md-3">
          <label class="form-label small fw-semibold mb-1">Employee</label>
          <select name="emp" class="form-select form-select-sm">
            <option value="">All Employees</option>
            <?php foreach ($employees as $emp): ?>
            <option value="<?= $emp['id'] ?>" <?= $filterEmp == $emp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-3 col-md-2">
          <label class="form-label small fw-semibold mb-1">Month</label>
          <select name="month" class="form-select form-select-sm">
            <?php for ($m=1; $m<=12; $m++): ?>
            <option value="<?= $m ?>" <?= $m == $filterMonth ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-sm-3 col-md-2">
          <label class="form-label small fw-semibold mb-1">Year</label>
          <select name="year" class="form-select form-select-sm">
            <?php for ($y=date('Y'); $y>=date('Y')-3; $y--): ?>
            <option value="<?= $y ?>" <?= $y == $filterYear ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-auto">
          <button class="btn btn-primary btn-sm d-inline-flex align-items-center">
            <span class="material-symbols-outlined me-1" style="font-size:18px;">filter_alt</span>
            <span>View Report</span>
          </button>
          <a href="attendance_report.php" class="btn btn-outline-secondary btn-sm ms-1 d-inline-flex align-items-center">
            <span class="material-symbols-outlined me-1" style="font-size:16px;">restart_alt</span>
            <span>Reset</span>
          </a>
        </div>
      </form>
    </div>
  </div>

  <!-- Legend -->
  <div class="d-flex gap-2 flex-wrap mb-4">
    <?php foreach ($statusColors as $k => $v): ?>
    <span style="background:<?= $v['bg'] ?>;color:<?= $v['text'] ?>;border-radius:6px;padding:.2em .6em;font-size:.72rem;font-weight:700;">
      <?= $v['label'] ?> = <?= $v['name'] ?? ucwords(str_replace('_',' ',$k)) ?>
    </span>
    <?php endforeach; ?>
    <span style="background:#f1f5f9;color:#64748b;border-radius:6px;padding:.2em .6em;font-size:.72rem;font-weight:700;">· = No Record</span>
  </div>

  <!-- Month Header -->
  <h5 class="fw-semibold mb-4 text-center d-flex align-items-center justify-content-center">
    <span class="material-symbols-outlined text-primary me-2 fs-5">calendar_month</span>
    <span><?= date('F Y', mktime(0,0,0,$filterMonth,1,$filterYear)) ?></span>
    <span class="text-muted small ms-2">(<?= $daysInMonth ?> days)</span>
  </h5>

  <!-- Per-Employee Sections -->
  <?php if (empty($empData)): ?>
  <div class="text-center py-5 text-muted">
    <span class="material-symbols-outlined fs-1 d-block mb-2 text-muted">group_off</span>
    No employees found.
  </div>
  <?php endif; ?>

  <?php foreach ($empData as $data):
    $emp = $data['emp'];
    $attByDate = $data['attByDate'];
    $breaksByDate = $data['breaksByDate'] ?? [];
    $lateHrs = $data['total_late_hrs'];
    $lateDeductionDisplay = $lateHrs * ($settings['late_deduction_per_hour'] ?? 50);
  ?>
  <div class="emp-section mb-4">
    <!-- Employee Header -->
    <div class="emp-section-header d-flex flex-wrap align-items-center justify-content-between gap-3" 
         role="button" 
         data-bs-toggle="collapse" 
         data-bs-target="#empCollapse_<?= $emp['id'] ?>" 
         aria-expanded="true"
         title="Click to collapse / expand">
      <div class="d-flex align-items-center gap-3">
        <img src="https://ui-avatars.com/api/?name=<?= urlencode($emp['full_name']) ?>&background=0d6efd&color=fff&size=80" class="rounded-circle" width="48" height="48" alt="">
        <div>
          <div class="fw-semibold text-dark fs-6"><?= htmlspecialchars($emp['full_name']) ?></div>
          <div class="text-muted small"><?= htmlspecialchars($emp['employee_code']) ?> · <?= htmlspecialchars($emp['designation'] ?? '') ?> · <?= htmlspecialchars($emp['department'] ?? '') ?></div>
        </div>
      </div>
      <!-- Summary Pills & Collapse Button -->
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="stat-pill"><span class="status-dot" style="background:#10b981;"></span><?= $data['present'] ?> Present</span>
        <span class="stat-pill"><span class="status-dot" style="background:#ef4444;"></span><?= $data['absent'] ?> Absent</span>
        <span class="stat-pill"><span class="status-dot" style="background:#f59e0b;"></span><?= $data['late_days'] ?> Late Days</span>
        <span class="stat-pill"><span class="status-dot" style="background:#3b82f6;"></span><?= $data['half_day'] ?> Half Day</span>
        <span class="stat-pill"><span class="material-symbols-outlined text-danger" style="font-size:14px;">schedule</span><?= format_minutes_hms($data['total_late_mins'], true) ?> Late</span>
        <span class="stat-pill"><span class="material-symbols-outlined text-success" style="font-size:14px;">history</span><?= format_hours_hms($data['total_hours'], true) ?></span>
        <?php if ($data['total_break_sec'] > 0): ?>
        <span class="stat-pill"><span class="material-symbols-outlined text-info" style="font-size:14px;">coffee</span><?= format_minutes_hms($data['total_break_mins'], true) ?> Breaks</span>
        <?php endif; ?>
        <?php if ($lateDeductionDisplay > 0): ?>
        <span class="stat-pill text-danger"><span class="material-symbols-outlined text-danger" style="font-size:14px;">remove_circle_outline</span>₹<?= number_format($lateDeductionDisplay,2) ?> Late Ded.</span>
        <?php endif; ?>

        <!-- Collapse/Expand Icon Button (without text) -->
        <button class="btn btn-sm emp-collapse-btn p-0 ms-1 shadow-none" 
                type="button" 
                data-bs-toggle="collapse" 
                data-bs-target="#empCollapse_<?= $emp['id'] ?>" 
                aria-expanded="true" 
                aria-label="Toggle details for <?= htmlspecialchars($emp['full_name']) ?>"
                onclick="event.stopPropagation();">
          <span class="material-symbols-outlined emp-chevron">expand_more</span>
        </button>
      </div>
    </div>

    <!-- Collapsible Container -->
    <div class="collapse show" id="empCollapse_<?= $emp['id'] ?>">
      <!-- Calendar Grid -->
      <div class="p-3">
      <div class="d-flex flex-wrap gap-1 mb-3">
        <?php 
        $dailyRows = $data['dailyRows'] ?? [];
        for ($d=1; $d<=$daysInMonth; $d++):
          $dateStr = "$filterYear-" . str_pad($filterMonth,2,'0',STR_PAD_LEFT) . "-" . str_pad($d,2,'0',STR_PAD_LEFT);
          $dayRow = $dailyRows[$dateStr] ?? null;
          $dayName = $dayRow ? $dayRow['day'] : date('D', strtotime($dateStr));
          $isNonWorking = $dayRow ? $dayRow['is_non_working'] : false;
          $sRaw = $dayRow ? $dayRow['status_raw'] : '';
          $cellLabel = $dayRow ? $dayRow['indicator'] : '·';
          $color = $statusColors[$sRaw] ?? ['bg'=>'#f1f5f9','text'=>'#64748b','label'=>$cellLabel];
          if (!$dayRow || $sRaw === 'upcoming') {
              $color = ['bg'=>'#f8fafc','text'=>'#cbd5e1','label'=>'·'];
              $cellLabel = '·';
          }
          $ttTitle = $dayRow ? $dayRow['status'] : 'No Record';
        ?>

        <div style="text-align:center;">
          <div class="text-muted" style="font-size:.6rem; margin-bottom:2px;"><?= $dayName ?></div>
          <div class="cal-cell" style="<?= "background:{$color['bg']};color:{$color['text']};" ?><?= ($isNonWorking) ? 'border:2px solid #fde68a;' : '' ?>">
            <?= $cellLabel ?>
            <span class="cal-tooltip">
              <?= $dateStr ?><br>
              <?= e($ttTitle) ?>
              <?php if ($dayRow && !empty($dayRow['check_in'])): ?><br>In: <?= date('h:i:s A', strtotime($dayRow['check_in'])) ?><?php endif; ?>
              <?php if ($dayRow && !empty($dayRow['check_out'])): ?><br>Out: <?= date('h:i:s A', strtotime($dayRow['check_out'])) ?><?php endif; ?>
              <?php if ($dayRow && $dayRow['break_seconds'] > 0): ?><br>Break: <?= $dayRow['break_duration'] ?><?php endif; ?>
              <?php if ($dayRow && $dayRow['late_minutes'] > 0): ?><br>Late: <?= format_minutes_hms($dayRow['late_minutes'], true) ?><?php endif; ?>
              <?php if ($dayRow && !empty($dayRow['remarks'])): ?><br><?= e($dayRow['remarks']) ?><?php endif; ?>
            </span>
          </div>
          <div style="font-size:.6rem; color:#94a3b8; margin-top:2px;"><?= $d ?></div>
        </div>
        <?php endfor; ?>
      </div>

      <!-- Detailed Table -->
      <div class="table-responsive">
        <table class="table table-hover att-table mb-0">
          <thead>
            <tr>
              <th>Date</th><th>Day</th><th>Status</th><th>Check-In</th><th>Check-Out</th><th>Break Details</th><th>Work Hours</th><th>Late Duration</th><th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $hasRows = false;
            foreach ($dailyRows as $dateStr => $row):
              if ($row['status_raw'] === 'upcoming') continue;
              $hasRows = true;
              $stRaw = $row['status_raw'];
              $sc = $statusColors[$stRaw] ?? ['bg'=>'#f1f5f9','text'=>'#64748b','label'=>$row['status']];
              $dayBrk = $breaksByDate[$dateStr] ?? null;
            ?>
            <tr>
              <td class="fw-medium"><?= date('d M Y', strtotime($dateStr)) ?></td>
              <td class="text-muted"><?= $row['day'] ?></td>
              <td><span style="background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;border-radius:50rem;padding:.2em .7em;font-size:.7rem;font-weight:700;"><?= e($row['status']) ?></span></td>
              <td><?= !empty($row['check_in']) ? date('h:i:s A', strtotime($row['check_in'])) : '—' ?></td>
              <td><?= !empty($row['check_out']) ? date('h:i:s A', strtotime($row['check_out'])) : '—' ?></td>
              <td>
                <?php if ($dayBrk && ($dayBrk['total_seconds'] > 0 || $dayBrk['has_active'])): 
                  $bMins = (int)round($dayBrk['total_seconds'] / 60);
                  $sessionCount = count($dayBrk['sessions']);
                  $timeParts = [];
                  foreach ($dayBrk['sessions'] as $s) {
                      $st = !empty($s['start_time']) ? date('h:i A', strtotime($s['start_time'])) : '';
                      $et = !empty($s['end_time']) ? date('h:i A', strtotime($s['end_time'])) : ($s['status'] === 'active' ? 'Active' : '');
                      if ($st) $timeParts[] = "$st" . ($et ? " - $et" : "");
                  }
                ?>
                  <div>
                    <span class="badge bg-light text-secondary border fw-semibold" style="font-size:0.72rem;">
                      <i class="bi bi-cup-hot me-1 text-primary"></i><?= format_minutes_hms($bMins, true) ?>
                      <?php if ($sessionCount > 1): ?>
                        <span class="text-muted fw-normal">(<?= $sessionCount ?>x)</span>
                      <?php endif; ?>
                    </span>
                    <?php if ($dayBrk['has_active']): ?>
                      <span class="badge bg-warning text-dark ms-1" style="font-size:0.65rem;"><i class="bi bi-hourglass-split me-1"></i>Active</span>
                    <?php endif; ?>
                    <?php if (!empty($timeParts)): ?>
                      <div class="text-muted" style="font-size:0.68rem; margin-top:2px;">
                        <?= htmlspecialchars(implode(', ', $timeParts)) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php elseif ($row['break_seconds'] > 0): ?>
                  <span class="badge bg-light text-secondary border fw-semibold" style="font-size:0.72rem;">
                    <i class="bi bi-cup-hot me-1 text-primary"></i><?= $row['break_duration'] ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($row['total_hours'] > 0): ?>
                  <span class="fw-semibold text-dark"><?= format_hours_hms($row['total_hours'], true) ?></span>
                  <span class="text-muted small">(<?= number_format($row['total_hours'],1) ?>h)</span>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td>
                <?php if ($row['late_minutes'] > 0): ?>
                  <span class="badge bg-warning text-dark"><?= format_minutes_hms($row['late_minutes'], true) ?></span>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="text-muted" style="font-size:0.75rem;"><?= htmlspecialchars($row['remarks'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$hasRows): ?>
            <tr><td colspan="9" class="text-center text-muted py-3">No attendance records for this month</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Monthly Summary Bar -->
    <div class="summary-bar mx-3 mb-3">
      <div class="row g-3 text-center">
        <div class="col"><div class="fw-bold text-success fs-5"><?= $data['present'] ?></div><div class="small text-muted">Present</div></div>
        <div class="col"><div class="fw-bold text-danger fs-5"><?= $data['absent'] ?></div><div class="small text-muted">Absent</div></div>
        <div class="col"><div class="fw-bold text-warning fs-5"><?= $data['late_days'] ?></div><div class="small text-muted">Late Days</div></div>
        <div class="col"><div class="fw-bold text-info fs-5"><?= $data['half_day'] ?></div><div class="small text-muted">Half Days</div></div>
        <div class="col"><div class="fw-bold text-primary fs-5"><?= $data['total_late_hrs'] ?></div><div class="small text-muted">Total Late Hrs</div></div>
        <div class="col"><div class="fw-bold text-dark fs-5"><?= $data['total_hours'] ?></div><div class="small text-muted">Total Work Hrs</div></div>
        <div class="col"><div class="fw-bold text-secondary fs-5"><?= $data['total_break_hrs'] ?></div><div class="small text-muted">Total Break Hrs</div></div>
        <?php if ($data['total_overtime'] > 0): ?>
        <div class="col"><div class="fw-bold text-success fs-5"><?= $data['total_overtime'] ?></div><div class="small text-muted">Overtime Hrs</div></div>
        <?php endif; ?>
      </div>
    </div>
  </div> <!-- end collapse -->
</div> <!-- end emp-section -->
<?php endforeach; ?>

</div>

<script>
let allSectionsExpanded = true;
function toggleAllSections() {
  allSectionsExpanded = !allSectionsExpanded;
  const collapses = document.querySelectorAll('.emp-section .collapse');
  collapses.forEach(el => {
    const bsCollapse = bootstrap.Collapse.getOrCreateInstance(el, { toggle: false });
    if (allSectionsExpanded) {
      bsCollapse.show();
    } else {
      bsCollapse.hide();
    }
  });

  const btn = document.getElementById('toggleAllBtn');
  if (btn) {
    btn.innerHTML = allSectionsExpanded
      ? '<span class="material-symbols-outlined me-1" style="font-size:18px;">unfold_less</span><span>Collapse All</span>'
      : '<span class="material-symbols-outlined me-1" style="font-size:18px;">unfold_more</span><span>Expand All</span>';
  }
}
</script>

<style>
@media print {
  #sidebar, .topbar, .no-print, #toggleAllBtn, .emp-collapse-btn { display: none !important; }
  #main { margin: 0 !important; }
  .emp-section { break-inside: avoid; }
  .emp-section .collapse { display: block !important; }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
