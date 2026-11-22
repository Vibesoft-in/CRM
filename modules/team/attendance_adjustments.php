<?php
// modules/team/attendance_adjustments.php — Manual Attendance Corrections with Mandatory Audit Log
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/audit_helper.php';
require_login();

$csrf = csrf_token();
$msg  = '';
$err  = '';

// Handle Manual Adjustment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = "Invalid security token.";
    } else {
        $empId      = intval($_POST['employee_id']);
        $date       = $_POST['attendance_date'];
        $newStatus  = $_POST['status'];
        $zeroHours  = isset($_POST['zero_hours']) ? (int)$_POST['zero_hours'] : 1;

        if (in_array($newStatus, ['absent', 'on_leave'], true)) {
            if ($zeroHours) {
                $newCheckIn  = null;
                $newCheckOut = null;
                $totalHours  = 0.00;
                try {
                    $pdo->prepare("UPDATE lunch_sessions SET status = 'cancelled', duration_seconds = 0 WHERE employee_id = ? AND session_date = ?")->execute([$empId, $date]);
                } catch (\Throwable $t) {}
            } else {
                $newCheckIn  = !empty($_POST['check_in']) ? $_POST['check_in'] . ':00' : null;
                $newCheckOut = !empty($_POST['check_out']) ? $_POST['check_out'] . ':00' : null;
            }
        } else {
            $newCheckIn  = !empty($_POST['check_in']) ? $_POST['check_in'] . ':00' : null;
            $newCheckOut = !empty($_POST['check_out']) ? $_POST['check_out'] . ':00' : null;
        }
        $reason     = trim($_POST['reason'] ?? '');

        if (empty($reason)) {
            $err = "A mandatory explanation / reason is required for any manual attendance correction.";
        } else {
            // Find existing attendance
            $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?");
            $stmt->execute([$empId, $date]);
            $oldAtt = $stmt->fetch();

            $oldStatus   = $oldAtt['status'] ?? 'none';
            $oldCheckIn  = $oldAtt['check_in'] ?? null;
            $oldCheckOut = $oldAtt['check_out'] ?? null;

            // Calculate hours if times given (deducting real lunch break duration)
            $totalHours = 0.00;
            if (in_array($newStatus, ['absent', 'on_leave'], true) && $zeroHours) {
                $totalHours = 0.00;
            } elseif ($newCheckIn && $newCheckOut) {
                $grossDiff = max(0, strtotime($newCheckOut) - strtotime($newCheckIn));
                $lunchSec = function_exists('get_today_lunch_duration_seconds') ? get_today_lunch_duration_seconds($pdo, $empId, $date) : 0;
                $netDiff = max(0, $grossDiff - $lunchSec);
                $totalHours = max(0, round($netDiff / 3600, 2));
            } elseif ($oldAtt && $oldAtt['total_hours'] && !(in_array($newStatus, ['absent', 'on_leave'], true) && $zeroHours)) {
                $totalHours = (float)$oldAtt['total_hours'];
            }

            // Update or insert attendance
            if ($oldAtt) {
                $attId = $oldAtt['id'];
                $upd = $pdo->prepare("UPDATE attendance SET status=?, check_in=?, check_out=?, total_hours=?, notes=CONCAT(COALESCE(notes,''), ' | Corrected: ', ?) WHERE id=?");
                $upd->execute([$newStatus, $newCheckIn, $newCheckOut, $totalHours, $reason, $attId]);
            } else {
                $ins = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, status, check_in, check_out, total_hours, notes, marked_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$empId, $date, $newStatus, $newCheckIn, $newCheckOut, $totalHours, "Manual Entry: $reason", $_SESSION['user_id']]);
                $attId = $pdo->lastInsertId();
            }

            // Mandatory Audit Trail in attendance_adjustments
            $adj = $pdo->prepare("INSERT INTO attendance_adjustments 
                (attendance_id, changed_by, old_status, new_status, old_check_in, new_check_in, old_check_out, new_check_out, reason) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $adj->execute([
                $attId, $_SESSION['user_id'], $oldStatus, $newStatus,
                $oldCheckIn, $newCheckIn, $oldCheckOut, $newCheckOut, $reason
            ]);

            // General audit log
            log_audit('attendance', 'manual_adjustment', $attId, [
                'employee_id' => $empId,
                'date' => $date,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'reason' => $reason
            ]);

            set_flash("Attendance record corrected and audit trail logged successfully.", "success");
            header("Location: attendance_adjustments.php");
            exit;
        }
    }
}

// Fetch active employees
$employees = $pdo->query("SELECT id, employee_code, full_name, department FROM employees WHERE status='active' ORDER BY full_name ASC")->fetchAll();

// Fetch recent adjustment history
$histStmt = $pdo->query("
    SELECT aa.*, e.full_name as emp_name, e.employee_code, u.name as admin_name 
    FROM attendance_adjustments aa
    JOIN attendance a ON a.id = aa.attendance_id
    JOIN employees e ON e.id = a.employee_id
    LEFT JOIN users u ON u.id = aa.changed_by
    ORDER BY aa.created_at DESC
    LIMIT 50
");
$adjustments = $histStmt->fetchAll();
?>

<div class="container-fluid py-4">

  <!-- Header -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h3 class="fw-bold mb-0"><i class="bi bi-pencil-square text-warning-emphasis me-2"></i>Manual Attendance Adjustments</h3>
      <p class="text-muted mb-0 small">Admin manual override with complete auditable history and change reasons</p>
    </div>
    <div class="d-flex gap-2">
      <a href="attendance.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-calendar-check me-1"></i> Live Attendance
      </a>
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newAdjModal">
        <i class="bi bi-plus-circle me-1"></i> New Adjustment
      </button>
    </div>
  </div>

  <?php $flash = get_flash(); if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius:12px;">
    <i class="bi bi-check-circle-fill me-2"></i><?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <?php if ($err): ?>
  <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius:12px;">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($err) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- Adjustments Audit Log Table -->
  <div class="card border-0 shadow-sm" style="border-radius:16px;">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
      <h5 class="fw-bold text-dark mb-0"><i class="bi bi-shield-check me-2 text-success"></i>Audit Trail History</h5>
      <span class="badge bg-light text-muted border"><?= count($adjustments) ?> Entries</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
          <thead class="table-light">
            <tr>
              <th class="ps-4">Timestamp</th>
              <th>Employee</th>
              <th>Old Status</th>
              <th>New Status</th>
              <th>Check-In (Old → New)</th>
              <th>Modified By</th>
              <th class="pe-4">Mandatory Reason</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($adjustments)): ?>
              <tr><td colspan="7" class="text-center text-muted py-5">No manual attendance modifications recorded yet.</td></tr>
            <?php else: ?>
              <?php foreach ($adjustments as $adj): ?>
              <tr>
                <td class="ps-4 text-nowrap text-muted"><?= date('d M Y, h:i A', strtotime($adj['created_at'])) ?></td>
                <td>
                  <div class="fw-bold text-dark"><?= e($adj['emp_name']) ?></div>
                  <div class="text-muted small" style="font-size:0.7rem;"><?= e($adj['employee_code']) ?></div>
                </td>
                <td><span class="badge bg-secondary-subtle text-secondary"><?= ucfirst($adj['old_status'] ?? 'None') ?></span></td>
                <td><span class="badge bg-primary-subtle text-primary fw-semibold"><?= ucfirst($adj['new_status']) ?></span></td>
                <td>
                  <span class="text-muted"><?= $adj['old_check_in'] ? date('h:i A', strtotime($adj['old_check_in'])) : 'None' ?></span>
                  <i class="bi bi-arrow-right mx-1 text-primary"></i>
                  <span class="fw-bold text-dark"><?= $adj['new_check_in'] ? date('h:i A', strtotime($adj['new_check_in'])) : '—' ?></span>
                </td>
                <td class="fw-medium text-dark"><i class="bi bi-person-fill me-1 text-secondary"></i><?= e($adj['admin_name'] ?? 'Admin') ?></td>
                <td class="pe-4 text-muted small" style="max-width:250px;"><?= e($adj['reason']) ?></td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- NEW ADJUSTMENT MODAL -->
<div class="modal fade" id="newAdjModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i>Correct Attendance</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">

          <div class="mb-3">
            <label class="form-label small fw-semibold">Employee <span class="text-danger">*</span></label>
            <select name="employee_id" class="form-select" required>
              <option value="">Select Employee</option>
              <?php foreach ($employees as $emp): ?>
                <option value="<?= $emp['id'] ?>"><?= e($emp['full_name']) ?> (<?= e($emp['employee_code']) ?> · <?= e($emp['department']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label small fw-semibold">Date <span class="text-danger">*</span></label>
              <input type="date" name="attendance_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">New Status <span class="text-danger">*</span></label>
              <select name="status" id="newAdjStatus" class="form-select" required onchange="handleAdjStatusChange()">
                <option value="present">Present</option>
                <option value="late">Late Arrival</option>
                <option value="half_day">Half Day</option>
                <option value="absent">Absent</option>
                <option value="on_leave">On Leave</option>
                <option value="work_from_home">Work From Home</option>
              </select>
            </div>
          </div>

          <div class="row g-2 mb-3" id="adjTimeFieldsRow">
            <div class="col-6">
              <label class="form-label small fw-semibold">Check-In Time</label>
              <input type="time" name="check_in" id="adjCheckIn" class="form-control" value="10:00">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Check-Out Time</label>
              <input type="time" name="check_out" id="adjCheckOut" class="form-control" value="19:00">
            </div>
          </div>

          <div id="adjZeroHoursGroup" class="p-2 px-3 mb-3 rounded-3 border border-danger-subtle bg-danger-subtle bg-opacity-25 d-none">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="zero_hours" id="adjZeroHoursCheck" value="1" checked onchange="handleAdjZeroHoursToggle()">
              <label class="form-check-label small fw-semibold text-danger" for="adjZeroHoursCheck">
                <i class="bi bi-shield-x me-1"></i> Reset worked hours & minutes to 0 (Clear punch-in, punch-out & breaks)
              </label>
            </div>
            <div class="text-muted small mt-1" style="font-size:0.75rem;">
              When enabled, this day will not count towards working hours or working days for the employee.
            </div>
          </div>

          <div id="adjNoTimeNotice" class="alert alert-light border small text-muted py-2 px-3 mb-3 d-none" style="border-radius:10px;">
            <i class="bi bi-info-circle-fill text-info me-1"></i> Time entry is cleared. Hours worked on this day will be recorded as <strong>0h 00m</strong>.
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold text-danger">Mandatory Reason for Change <span class="text-danger">*</span></label>
            <textarea name="reason" class="form-control" rows="3" placeholder="e.g. Biometric machine failure, approved outdoor client installation duty, emergency..." required></textarea>
          </div>

          <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
            <i class="bi bi-save me-1"></i> Apply Correction & Log Audit
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function handleAdjStatusChange() {
  const statusSel = document.getElementById('newAdjStatus');
  const timeRow = document.getElementById('adjTimeFieldsRow');
  const zeroGroup = document.getElementById('adjZeroHoursGroup');
  const noTimeNotice = document.getElementById('adjNoTimeNotice');
  const checkIn = document.getElementById('adjCheckIn');
  const checkOut = document.getElementById('adjCheckOut');
  const zeroCheck = document.getElementById('adjZeroHoursCheck');

  if (!statusSel || !timeRow) return;

  const val = statusSel.value;
  if (val === 'absent' || val === 'on_leave') {
    if (zeroGroup) zeroGroup.classList.remove('d-none');
    handleAdjZeroHoursToggle();
  } else {
    if (zeroGroup) zeroGroup.classList.add('d-none');
    timeRow.classList.remove('d-none');
    if (noTimeNotice) noTimeNotice.classList.add('d-none');
    checkIn.disabled = false;
    checkOut.disabled = false;

    if (val === 'late') {
      checkIn.value = '10:30';
      checkOut.value = '19:00';
    } else if (val === 'half_day') {
      checkIn.value = '10:00';
      checkOut.value = '14:00';
    } else { // 'present', 'work_from_home'
      checkIn.value = '10:00';
      checkOut.value = '19:00';
    }
  }
}

function handleAdjZeroHoursToggle() {
  const statusSel = document.getElementById('newAdjStatus');
  const timeRow = document.getElementById('adjTimeFieldsRow');
  const noTimeNotice = document.getElementById('adjNoTimeNotice');
  const checkIn = document.getElementById('adjCheckIn');
  const checkOut = document.getElementById('adjCheckOut');
  const zeroCheck = document.getElementById('adjZeroHoursCheck');

  if (!statusSel || !timeRow) return;
  const val = statusSel.value;

  if (val === 'absent' || val === 'on_leave') {
    if (zeroCheck && zeroCheck.checked) {
      timeRow.classList.add('d-none');
      if (noTimeNotice) noTimeNotice.classList.remove('d-none');
      checkIn.value = '';
      checkOut.value = '';
      checkIn.disabled = true;
      checkOut.disabled = true;
    } else {
      timeRow.classList.remove('d-none');
      if (noTimeNotice) noTimeNotice.classList.add('d-none');
      checkIn.disabled = false;
      checkOut.disabled = false;
    }
  }
}

document.addEventListener('DOMContentLoaded', function() {
  handleAdjStatusChange();
  const modalEl = document.getElementById('newAdjModal');
  if (modalEl) {
    modalEl.addEventListener('shown.bs.modal', handleAdjStatusChange);
  }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
