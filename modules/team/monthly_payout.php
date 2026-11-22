<?php
// modules/team/monthly_payout.php — Monthly Payout Calculator
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$csrf = csrf_token();

// Filters
$filterMonth = intval($_GET['month'] ?? date('m'));
$filterYear  = intval($_GET['year'] ?? date('Y'));
$filterEmp   = intval($_GET['emp'] ?? 0);

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $filterMonth, $filterYear);
$monthStart  = "$filterYear-" . str_pad($filterMonth,2,'0',STR_PAD_LEFT) . "-01";
$monthEnd    = "$filterYear-" . str_pad($filterMonth,2,'0',STR_PAD_LEFT) . "-$daysInMonth";

// Settings
$settings = $pdo->query("SELECT * FROM attendance_settings LIMIT 1")->fetch();
if (!$settings) $settings = ['working_days_per_month'=>26,'late_deduction_per_hour'=>50,'half_day_threshold_hours'=>4];

$actualWorkingDays = get_working_days_count($pdo, $monthStart, $monthEnd);
$workingDays = max(1, $actualWorkingDays);
$lateDeductPerHour = floatval($settings['late_deduction_per_hour'] ?? 50);

// Employees
$employees = $pdo->query("SELECT id, full_name, employee_code, designation, department, base_salary, salary_type, employee_type FROM employees WHERE status='active' ORDER BY full_name ASC")->fetchAll();

// Handle "Push to Payroll" action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'push_payroll') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        set_flash('Security error', 'danger');
        header("Location: monthly_payout.php?month=$filterMonth&year=$filterYear");
        exit;
    }

    $empId         = intval($_POST['emp_id']);
    $netSalary     = (float)$_POST['net_salary'];
    $baseSalary    = (float)$_POST['base_salary'];
    $absentDeduct  = (float)$_POST['absent_deduction'];
    $halfDeduct    = (float)$_POST['half_deduction'];
    $lateDeduct    = (float)$_POST['late_deduction'];
    $leaveDeduct   = (float)($_POST['leave_deduction'] ?? 0);
    $totalDeduct   = $absentDeduct + $halfDeduct + $lateDeduct + $leaveDeduct;

    // Check if payroll exists
    $existing = $pdo->prepare("SELECT id FROM payroll WHERE employee_id=? AND payroll_month=? AND payroll_year=?");
    $existing->execute([$empId, $filterMonth, $filterYear]);
    $existRow = $existing->fetch();

    if ($existRow) {
        $pdo->prepare("UPDATE payroll SET base_salary=?, late_deduction=?, other_deduction=?, net_salary=?, amount_pending=net_salary-amount_paid WHERE id=?")
            ->execute([$baseSalary, $lateDeduct, $absentDeduct + $halfDeduct + $leaveDeduct, $netSalary, $existRow['id']]);
        set_flash('Payroll updated with attendance deductions!', 'success');
    } else {
        $pdo->prepare("INSERT INTO payroll (employee_id, payroll_month, payroll_year, base_salary, late_deduction, other_deduction, net_salary, amount_pending, status) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$empId, $filterMonth, $filterYear, $baseSalary, $lateDeduct, $absentDeduct + $halfDeduct + $leaveDeduct, $netSalary, $netSalary, 'Pending']);
        set_flash('Payroll created successfully from attendance data!', 'success');
    }

    header("Location: monthly_payout.php?month=$filterMonth&year=$filterYear");
    exit;
}

// Ensure daily attendance is up to date
auto_process_daily_attendance($pdo);

$holidaysMap  = get_active_holidays_map($pdo, (int)$filterYear);
$overridesMap = get_holiday_overrides_map($pdo, (int)$filterYear);

// Build payout data per employee
$payoutData = [];
foreach ($employees as $emp) {
    if ($filterEmp > 0 && (int)$emp['id'] !== $filterEmp) continue;

    // Attendance summary using centralized A & R engine
    $attSummary = get_employee_month_attendance_summary($pdo, (int)$emp['id'], (int)$filterYear, (int)$filterMonth, $holidaysMap, $overridesMap);

    $presentDays       = $attSummary['present_days'];
    $absentDays        = $attSummary['absent_days'];
    $halfDays          = $attSummary['half_days'];
    $lateDays          = $attSummary['late_days'];
    $leaveDays         = $attSummary['leave_days'];
    $wfhDays           = 0;
    $lateHours         = $attSummary['total_late_hrs'];
    $totalHours        = $attSummary['total_work_hours'];
    $totalLateMinutes  = $attSummary['total_late_mins'];
    $attRows           = $attSummary['daily_rows'];

    $baseSalary = floatval($emp['base_salary']);
    $perDayRate = $workingDays > 0 ? ($baseSalary / $workingDays) : 0;

    // 4-day annual leave quota calculation: only extra leaves beyond 4 are unpaid
    $annualLeave = get_employee_annual_leave_summary($pdo, $emp['id'], $filterYear);
    $allowedQuota = $annualLeave['allowed_leaves']; // 4

    // Prior leaves taken this year before current month
    $priorStmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_days), 0) 
        FROM leave_requests 
        WHERE employee_id = ? AND status = 'approved' AND YEAR(start_date) = ? AND start_date < ?
    ");
    $priorStmt->execute([$emp['id'], $filterYear, $monthStart]);
    $priorLeavesTaken = (int)$priorStmt->fetchColumn();

    $paidSlotsRemaining = max(0, $allowedQuota - $priorLeavesTaken);
    $paidLeavesInMonth = min($leaveDays, $paidSlotsRemaining);
    $unpaidLeavesInMonth = max(0, $leaveDays - $paidLeavesInMonth);

    $absentDeduction  = round($absentDays * $perDayRate, 2);
    $halfDeduction    = round($halfDays * ($perDayRate / 2), 2);
    $leaveDeduction   = round($unpaidLeavesInMonth * $perDayRate, 2);
    $lateDeduction    = round($lateHours * $lateDeductPerHour, 2);
    $totalDeduction   = $absentDeduction + $halfDeduction + $leaveDeduction + $lateDeduction;
    $netSalary        = max(0, $baseSalary - $totalDeduction);

    // Check existing payroll
    $existPayroll = $pdo->prepare("SELECT * FROM payroll WHERE employee_id=? AND payroll_month=? AND payroll_year=?");
    $existPayroll->execute([$emp['id'], $filterMonth, $filterYear]);
    $payroll = $existPayroll->fetch();

    $payoutData[] = [
        'emp'                 => $emp,
        'present_days'        => $presentDays,
        'absent_days'         => $absentDays,
        'half_days'           => $halfDays,
        'late_days'           => $lateDays,
        'leave_days'          => $leaveDays,
        'paid_leaves'         => $paidLeavesInMonth,
        'unpaid_leaves'       => $unpaidLeavesInMonth,
        'wfh_days'            => $wfhDays,
        'late_hours'          => $lateHours,
        'total_hours'         => round($totalHours, 2),
        'base_salary'         => $baseSalary,
        'per_day_rate'        => round($perDayRate, 2),
        'working_days'        => $workingDays,
        'absent_deduction'    => $absentDeduction,
        'half_deduction'      => $halfDeduction,
        'leave_deduction'     => $leaveDeduction,
        'late_deduction'      => $lateDeduction,
        'total_deduction'     => $totalDeduction,
        'net_salary'          => $netSalary,
        'payroll'             => $payroll,
        'records'             => count($attRows),
    ];
}

// Totals
$grandBaseSalary    = array_sum(array_column($payoutData, 'base_salary'));
$grandNetSalary     = array_sum(array_column($payoutData, 'net_salary'));
$grandTotalDeduct   = array_sum(array_column($payoutData, 'total_deduction'));
?>
<style>
.payout-card { border-radius: 14px; border: 1px solid #e9ecef; background: #fff; overflow: hidden; transition: all .2s; }
.payout-card:hover { box-shadow: 0 6px 25px rgba(0,0,0,.08); }
.payout-header { background: linear-gradient(135deg, #0d6efd, #0a58ca); color: #fff; padding: 0.9rem 1.25rem; cursor: pointer; user-select: none; transition: background 0.2s ease; }
.payout-header:hover { background: linear-gradient(135deg, #0b5ed7, #084298); }
.payout-chevron { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; background: rgba(255,255,255,0.18); transition: background .2s ease; }
.payout-header:hover .payout-chevron { background: rgba(255,255,255,0.28); }
.payout-chevron i { display: inline-block; transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
.payout-header[aria-expanded="true"] .payout-chevron i,
.payout-card.is-expanded .payout-chevron i { transform: rotate(180deg); }
.deduct-row { display: flex; justify-content: space-between; padding: .35rem 0; border-bottom: 1px dashed #f1f5f9; font-size: .85rem; }
.deduct-row:last-child { border: none; }
.net-row { background: linear-gradient(135deg, #f0fdf4, #dcfce7); border-radius: 10px; padding: .75rem 1rem; margin-top: .5rem; }
.stat-chip { display: inline-flex; align-items: center; gap: .3em; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 50rem; padding: .2em .65em; font-size: .72rem; font-weight: 600; }
.pushed-badge { background: #d1fae5; color: #065f46; border-radius: 50rem; padding: .2em .75em; font-size: .72rem; font-weight: 700; }
.grand-total { background: linear-gradient(135deg, #1e293b, #334155); color: #fff; border-radius: 14px; padding: 1.25rem; }
</style>

<div class="container-fluid py-4">

  <!-- Header -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h3 class="fw-bold mb-0"><i class="bi bi-currency-rupee text-success me-2"></i>Monthly Payout Calculator</h3>
      <p class="text-muted mb-0 small">Auto-calculates net salary based on attendance, absences, and late penalties</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleAllPayoutCards(true)" title="Expand all cards">
        <i class="bi bi-arrows-expand me-1"></i>Expand All
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllPayoutCards(false)" title="Collapse all cards">
        <i class="bi bi-arrows-collapse me-1"></i>Collapse All
      </button>
      <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
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
          <button class="btn btn-primary btn-sm"><i class="bi bi-calculator me-1"></i>Calculate</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Deduction Settings Info -->
  <div class="alert alert-light border mb-4" style="border-radius:12px;">
    <div class="d-flex flex-wrap gap-4 align-items-center">
      <div><i class="bi bi-calendar-check text-primary me-1"></i><strong>Working Days:</strong> <?= $workingDays ?>/month</div>
      <div><i class="bi bi-clock text-danger me-1"></i><strong>Late Deduction:</strong> ₹<?= number_format($lateDeductPerHour, 2) ?>/hour</div>
      <div><i class="bi bi-calendar2-x text-warning me-1"></i><strong>Month:</strong> <?= date('F Y', mktime(0,0,0,$filterMonth,1,$filterYear)) ?> (<?= $daysInMonth ?> days)</div>
      <a href="attendance.php" class="btn btn-xs btn-outline-secondary btn-sm ms-auto" style="font-size:.75rem;"><i class="bi bi-gear me-1"></i>Change Settings</a>
    </div>
  </div>

  <!-- Flash message -->
  <?php $flash = get_flash(); if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show" role="alert">
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- Payout Cards -->
  <?php if (empty($payoutData)): ?>
  <div class="text-center py-5 text-muted"><i class="bi bi-people fs-1 d-block mb-2"></i>No employees found.</div>
  <?php else: ?>
  <!-- Card Controls Bar -->
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="text-muted small">
      Showing <strong><?= count($payoutData) ?></strong> employee payout<?= count($payoutData) > 1 ? 's' : '' ?> · Click any card header to expand or collapse details
    </div>
    <div class="btn-group btn-group-sm shadow-sm" role="group">
      <button type="button" class="btn btn-outline-primary" onclick="toggleAllPayoutCards(true)">
        <i class="bi bi-arrows-expand me-1"></i>Expand All
      </button>
      <button type="button" class="btn btn-outline-secondary" onclick="toggleAllPayoutCards(false)">
        <i class="bi bi-arrows-collapse me-1"></i>Collapse All
      </button>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <?php foreach ($payoutData as $pd):
      $emp = $pd['emp'];
      $pct = $pd['base_salary'] > 0 ? round(($pd['net_salary'] / $pd['base_salary']) * 100) : 0;
      $isZeroAttendance = ($pd['records'] === 0);
    ?>
    <div class="col-12 col-xl-6">
      <div class="payout-card shadow-sm">
        <!-- Card Header (Clickable: Default Collapsed) -->
        <div class="payout-header d-flex align-items-center justify-content-between"
             role="button"
             data-bs-toggle="collapse"
             data-bs-target="#payoutCollapse_<?= $emp['id'] ?>"
             aria-expanded="false"
             aria-controls="payoutCollapse_<?= $emp['id'] ?>"
             title="Click to view details">
          <div class="d-flex align-items-center gap-3">
            <img src="https://ui-avatars.com/api/?name=<?= urlencode($emp['full_name']) ?>&background=ffffff33&color=fff&size=80" class="rounded-circle shadow-sm" width="42" height="42" alt="">
            <div>
              <div class="fw-semibold text-white fs-6 lh-sm"><?= htmlspecialchars($emp['full_name']) ?></div>
              <div style="opacity:.85; font-size:.75rem;" class="mt-1">
                <?= htmlspecialchars($emp['employee_code']) ?> · <?= htmlspecialchars($emp['designation'] ?? 'Staff') ?>
                <?php if ($pd['payroll']): ?>
                  <span class="badge bg-light text-dark ms-1" style="font-size: .65rem; vertical-align: middle;"><?= $pd['payroll']['status'] ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="d-flex align-items-center gap-3">
            <div class="text-end">
              <div style="font-size:.7rem; opacity:.85; line-height: 1;">Net Payable</div>
              <div class="fw-bold fs-5 text-white lh-sm">₹<?= number_format($pd['net_salary'], 2) ?></div>
              <div style="font-size:.7rem; opacity:.75;">Base: ₹<?= number_format($pd['base_salary'], 2) ?></div>
            </div>
            <div class="payout-chevron text-white">
              <i class="bi bi-chevron-down fs-6"></i>
            </div>
          </div>
        </div>

        <!-- Collapsible Card Body (Default: Collapsed) -->
        <div class="collapse payout-collapse" id="payoutCollapse_<?= $emp['id'] ?>">
          <div class="p-3 border-top">
            <!-- Attendance Stats Row -->
            <div class="d-flex flex-wrap gap-2 mb-3">
              <span class="stat-chip"><span style="width:8px;height:8px;border-radius:50%;background:#10b981;display:inline-block;"></span><?= $pd['present_days'] ?> Present</span>
              <span class="stat-chip"><span style="width:8px;height:8px;border-radius:50%;background:#ef4444;display:inline-block;"></span><?= $pd['absent_days'] ?> Absent</span>
              <span class="stat-chip"><span style="width:8px;height:8px;border-radius:50%;background:#3b82f6;display:inline-block;"></span><?= $pd['half_days'] ?> Half Day</span>
              <span class="stat-chip"><span style="width:8px;height:8px;border-radius:50%;background:#f59e0b;display:inline-block;"></span><?= $pd['late_days'] ?> Late Days</span>
              <span class="stat-chip"><i class="bi bi-clock text-danger" style="font-size:.65rem;"></i><?= $pd['late_hours'] ?> Hrs Late</span>
              <span class="stat-chip"><i class="bi bi-briefcase text-muted" style="font-size:.65rem;"></i><?= $pd['total_hours'] ?> Work Hrs</span>
            </div>

            <!-- Calculation Breakdown -->
            <div class="mb-3">
              <div class="small fw-semibold text-muted mb-2 text-uppercase" style="letter-spacing:.05em;">Salary Calculation</div>
              <div class="deduct-row">
                <span class="text-muted">Per Day Rate <span class="text-muted small">(₹<?= number_format($pd['base_salary'],0) ?> ÷ <?= $workingDays ?> days)</span></span>
                <span class="fw-medium">₹<?= number_format($pd['per_day_rate'], 2) ?></span>
              </div>
              <div class="deduct-row">
                <span class="text-muted">Base Salary</span>
                <span class="fw-semibold text-dark">₹<?= number_format($pd['base_salary'], 2) ?></span>
              </div>
              <?php if ($pd['absent_deduction'] > 0): ?>
              <div class="deduct-row">
                <span class="text-danger"><i class="bi bi-dash-circle me-1"></i>Absent Deduction <span class="text-muted small">(<?= $pd['absent_days'] ?> days × ₹<?= number_format($pd['per_day_rate'],2) ?>)</span></span>
                <span class="fw-semibold text-danger">−₹<?= number_format($pd['absent_deduction'], 2) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($pd['half_deduction'] > 0): ?>
              <div class="deduct-row">
                <span class="text-warning"><i class="bi bi-dash-circle me-1"></i>Half Day Deduction <span class="text-muted small">(<?= $pd['half_days'] ?> × ₹<?= number_format($pd['per_day_rate']/2,2) ?>)</span></span>
                <span class="fw-semibold text-warning">−₹<?= number_format($pd['half_deduction'], 2) ?></span>
              </div>
              <?php endif; ?>
              <?php if (!empty($pd['leave_deduction']) && $pd['leave_deduction'] > 0): ?>
              <div class="deduct-row">
                <span class="text-danger"><i class="bi bi-calendar2-x me-1"></i>Unpaid Leave Deduction <span class="text-muted small">(<?= $pd['unpaid_leaves'] ?> extra days × ₹<?= number_format($pd['per_day_rate'],2) ?>)</span></span>
                <span class="fw-semibold text-danger">−₹<?= number_format($pd['leave_deduction'], 2) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($pd['late_deduction'] > 0): ?>
              <div class="deduct-row">
                <span class="text-orange" style="color:#ea580c;"><i class="bi bi-clock me-1"></i>Late Deduction <span class="text-muted small">(<?= $pd['late_hours'] ?> hrs × ₹<?= number_format($lateDeductPerHour,2) ?>)</span></span>
                <span class="fw-semibold" style="color:#ea580c;">−₹<?= number_format($pd['late_deduction'], 2) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($pd['total_deduction'] > 0): ?>
              <div class="deduct-row" style="border-top: 1px solid #e2e8f0; padding-top:.4rem;">
                <span class="text-muted fw-medium">Total Deductions</span>
                <span class="fw-bold text-danger">−₹<?= number_format($pd['total_deduction'], 2) ?></span>
              </div>
              <?php endif; ?>
            </div>

            <!-- Net Salary -->
            <div class="net-row d-flex align-items-center justify-content-between">
              <div>
                <div class="fw-bold text-success fs-5">₹<?= number_format($pd['net_salary'], 2) ?></div>
                <div class="text-muted small">Net Payable Amount</div>
              </div>
              <div class="text-end">
                <div class="fw-bold text-success"><?= $pct ?>%</div>
                <div class="small text-muted">of base</div>
              </div>
            </div>

            <!-- Progress Bar -->
            <div class="progress mt-2 mb-3" style="height:6px; border-radius:50rem;">
              <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
              <?php if ($pd['total_deduction'] > 0): ?>
              <div class="progress-bar bg-danger" style="width:<?= 100-$pct ?>%"></div>
              <?php endif; ?>
            </div>

            <!-- Payroll Status & Push Button -->
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <?php if ($pd['payroll']): ?>
                  <?php $pStatus = $pd['payroll']['status']; ?>
                  <span class="badge bg-<?= $pStatus==='Paid' ? 'success' : ($pStatus==='Pending'?'warning':'info') ?>">
                    Payroll: <?= $pStatus ?>
                  </span>
                  <?php if ($pd['payroll']['amount_paid'] > 0): ?>
                    <span class="text-muted small ms-1">Paid: ₹<?= number_format($pd['payroll']['amount_paid'],2) ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted small"><i class="bi bi-info-circle me-1"></i>No payroll yet</span>
                <?php endif; ?>
                <?php if ($isZeroAttendance): ?>
                  <div class="small text-warning mt-1"><i class="bi bi-exclamation-triangle me-1"></i>No attendance records this month</div>
                <?php endif; ?>
              </div>
              <?php if (!$isZeroAttendance): ?>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="push_payroll">
                <input type="hidden" name="emp_id" value="<?= $emp['id'] ?>">
                <input type="hidden" name="base_salary" value="<?= $pd['base_salary'] ?>">
                <input type="hidden" name="net_salary" value="<?= $pd['net_salary'] ?>">
                <input type="hidden" name="absent_deduction" value="<?= $pd['absent_deduction'] ?>">
                <input type="hidden" name="half_deduction" value="<?= $pd['half_deduction'] ?>">
                <input type="hidden" name="late_deduction" value="<?= $pd['late_deduction'] ?>">
                <input type="hidden" name="leave_deduction" value="<?= $pd['leave_deduction'] ?? 0 ?>">
                <button class="btn btn-sm <?= $pd['payroll'] ? 'btn-outline-success' : 'btn-success' ?>" title="Push to Payroll">
                  <i class="bi bi-arrow-up-circle me-1"></i><?= $pd['payroll'] ? 'Update Payroll' : 'Push to Payroll' ?>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Grand Total -->
  <?php if (count($payoutData) > 1): ?>
  <div class="grand-total mb-4">
    <h6 class="fw-bold mb-3 text-white opacity-90"><i class="bi bi-calculator me-2"></i>Grand Total — <?= date('F Y', mktime(0,0,0,$filterMonth,1,$filterYear)) ?></h6>
    <div class="row g-3 text-center">
      <div class="col-md-4">
        <div class="small opacity-75 mb-1">Total Base Salary</div>
        <div class="fw-bold fs-4">₹<?= number_format($grandBaseSalary, 2) ?></div>
      </div>
      <div class="col-md-4">
        <div class="small opacity-75 mb-1">Total Deductions</div>
        <div class="fw-bold fs-4 text-danger">−₹<?= number_format($grandTotalDeduct, 2) ?></div>
      </div>
      <div class="col-md-4">
        <div class="small opacity-75 mb-1">Total Net Payable</div>
        <div class="fw-bold fs-4 text-success">₹<?= number_format($grandNetSalary, 2) ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<style>
@media print {
  #sidebar, .topbar, .no-print, form, button { display: none !important; }
  #main { margin: 0 !important; }
  .payout-card { break-inside: avoid; border: 1px solid #ccc !important; }
  .payout-collapse { display: block !important; height: auto !important; visibility: visible !important; }
}
</style>

<script>
function toggleAllPayoutCards(expand) {
  document.querySelectorAll('.payout-collapse').forEach(function(el) {
    if (window.bootstrap && bootstrap.Collapse) {
      const bsCollapse = bootstrap.Collapse.getOrCreateInstance(el, { toggle: false });
      if (expand) {
        bsCollapse.show();
      } else {
        bsCollapse.hide();
      }
    } else {
      if (expand) {
        el.classList.add('show');
      } else {
        el.classList.remove('show');
      }
    }
  });
}

document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.payout-collapse').forEach(function(el) {
    el.addEventListener('show.bs.collapse', function() {
      const card = this.closest('.payout-card');
      if (card) {
        card.classList.add('is-expanded');
        const header = card.querySelector('.payout-header');
        if (header) header.setAttribute('aria-expanded', 'true');
      }
    });
    el.addEventListener('hide.bs.collapse', function() {
      const card = this.closest('.payout-card');
      if (card) {
        card.classList.remove('is-expanded');
        const header = card.querySelector('.payout-header');
        if (header) header.setAttribute('aria-expanded', 'false');
      }
    });
  });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
