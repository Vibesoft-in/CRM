<?php
// employee/salary.php — Employee Salary Overview & Payout History
require_once __DIR__ . '/layout.php';

$empId = (int)($_SESSION['employee_id'] ?? 0);
if ($empId <= 0) {
    abort_forbidden("No verified employee profile linked to your account.");
}
\App\Security\SecurityHeaders::applyNoCache();

// 1. Fetch Employee Details
$eStmt = $pdo->prepare("SELECT e.*, d.name as dept_title, des.name as desig_title 
                        FROM employees e 
                        LEFT JOIN departments d ON d.id = e.department_id 
                        LEFT JOIN designations des ON des.id = e.designation_id 
                        WHERE e.id = ?");
$eStmt->execute([$empId]);
$empDetails = $eStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$baseSalary = (float)($empDetails['base_salary'] ?? 0);

// 2. Fetch all payroll snapshots for history & available months
$payStmt = $pdo->prepare("
    SELECT * FROM payroll 
    WHERE employee_id = ? 
    ORDER BY payroll_year DESC, payroll_month DESC
");
$payStmt->execute([$empId]);
$payrolls = $payStmt->fetchAll(PDO::FETCH_ASSOC);

// Determine active/selected month & year
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$selectedYear  = isset($_GET['year']) ? (int)$_GET['year'] : 0;

if ($selectedMonth <= 0 || $selectedYear <= 0) {
    if (!empty($payrolls)) {
        $selectedMonth = (int)$payrolls[0]['payroll_month'];
        $selectedYear  = (int)$payrolls[0]['payroll_year'];
    } else {
        $selectedMonth = (int)date('n');
        $selectedYear  = (int)date('Y');
    }
}

// Find payroll record for selected month/year
$curPay = null;
foreach ($payrolls as $p) {
    if ((int)$p['payroll_month'] === $selectedMonth && (int)$p['payroll_year'] === $selectedYear) {
        $curPay = $p;
        break;
    }
}

// 3. Build Available Months list for the dropdown
$availableMonths = [];
$now = time();
for ($i = 0; $i < 12; $i++) {
    $mTime = strtotime("-$i month", $now);
    $am = (int)date('n', $mTime);
    $ay = (int)date('Y', $mTime);
    $availableMonths[] = [
        'month'       => $am,
        'year'        => $ay,
        'month_label' => date('F Y', $mTime),
        'is_selected' => ($am === $selectedMonth && $ay === $selectedYear),
    ];
}

// 4. Calculate Attendance for the selected month
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $selectedMonth, $selectedYear);
$mStart = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
$mEnd   = sprintf('%04d-%02d-%02d', $selectedYear, $selectedMonth, $daysInMonth);

$attStmt = $pdo->prepare("SELECT 
    SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days
  FROM attendance 
  WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$attStmt->execute([$empId, $mStart, $mEnd]);
$attRow = $attStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$presentDays = (int)($attRow['present_days'] ?? 0);
$lateDays    = (int)($attRow['late_days'] ?? 0);
$absentDays  = (int)($attRow['absent_days'] ?? 0);

// 5. Build Earnings & Deductions breakdown
$earningsList = [];
$deductionsList = [];
$grossEarnings = 0.0;
$totalDeductions = 0.0;
$netPay = 0.0;

if ($curPay) {
    $netPay = (float)$curPay['net_salary'];
    
    // Check itemized payroll items
    $itemStmt = $pdo->prepare("SELECT * FROM payroll_items WHERE payroll_id = ? ORDER BY id ASC");
    $itemStmt->execute([$curPay['id']]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($items)) {
        foreach ($items as $it) {
            $amt = (float)$it['amount'];
            if ($it['type'] === 'earning') {
                $grossEarnings += $amt;
                $earningsList[] = ['head' => $it['name'], 'amount' => $amt];
            } else {
                $totalDeductions += $amt;
                $deductionsList[] = ['head' => $it['name'], 'amount' => $amt];
            }
        }
    } else {
        $base = (float)$curPay['base_salary'];
        $allow = (float)$curPay['allowances'];
        $bonus = (float)$curPay['bonus'];
        $grossEarnings = $base + $allow + $bonus;

        $earningsList[] = ['head' => 'Basic Salary', 'amount' => $base];
        if ($allow > 0) {
            $earningsList[] = ['head' => 'House Rent Allowance (HRA)', 'amount' => $allow];
        }
        if ($bonus > 0) {
            $earningsList[] = ['head' => 'Bonus / Incentives', 'amount' => $bonus];
        }

        $adv = (float)$curPay['advance_deducted'];
        $late = (float)$curPay['late_deduction'];
        $other = (float)$curPay['other_deduction'];
        $totalDeductions = $adv + $late + $other;

        if ($adv > 0) {
            $deductionsList[] = ['head' => 'Advance Salary Deducted', 'amount' => $adv];
        }
        if ($late > 0) {
            $lateLabel = $lateDays > 0 ? "Late Deduction ($lateDays Times)" : "Late Arrival Deduction";
            $deductionsList[] = ['head' => $lateLabel, 'amount' => $late];
        }
        if ($other > 0) {
            $absentLabel = $absentDays > 0 ? "Absent Cut ($absentDays Days)" : "Absence / Leave Deductions";
            $deductionsList[] = ['head' => $absentLabel, 'amount' => $other];
        }
    }
} else {
    $netPay = $baseSalary;
    $grossEarnings = $baseSalary;
    if ($baseSalary > 0) {
        $earningsList[] = ['head' => 'Basic Salary', 'amount' => $baseSalary];
    }
}

// 6. Upcoming Payment Details
$nextMonthTime = strtotime("+1 month", mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));
$nextMonthName = date('F Y', $nextMonthTime);
$nextPayDate   = "5 " . date('M Y', $nextMonthTime);
$upcomingAmount = $grossEarnings > 0 ? $grossEarnings : $baseSalary;

// 7. Calculate Total Paid and Pending across all history
$totalPaid = 0.0;
$totalPending = 0.0;
foreach ($payrolls as $p) {
    if (strtolower($p['status']) === 'paid') {
        $totalPaid += (float)$p['net_salary'];
    } else {
        $paid = (float)($p['amount_paid'] ?? 0);
        $totalPaid += $paid;
        $totalPending += max(0, (float)$p['net_salary'] - $paid);
    }
}

// 8. Fetch Salary Structure History (effective dates)
$salHistStmt = $pdo->prepare("SELECT * FROM employee_salary WHERE employee_id = ? ORDER BY effective_from DESC");
$salHistStmt->execute([$empId]);
$salStructures = $salHistStmt->fetchAll();

$monthLabel = date('F Y', mktime(0, 0, 0, $selectedMonth, 10, $selectedYear));
?>

<div class="container-fluid px-0">

  <!-- Header & Month Picker (Matching reference UI) -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h3 class="fw-bold mb-1 text-dark" style="font-family: inherit;">My Salary</h3>
      <p class="text-muted mb-0 small">View your monthly salary details, payslips and payment history.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
      <!-- Month Dropdown Selector -->
      <div class="dropdown">
        <button class="btn btn-white bg-white border shadow-sm rounded-pill px-3 py-2 dropdown-toggle d-flex align-items-center gap-2 fw-semibold text-dark" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-calendar3 text-muted"></i>
          <span><?= $monthLabel ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3 mt-1" style="max-height: 320px; overflow-y: auto;">
          <li class="dropdown-header text-uppercase fw-bold text-muted small">Select Month</li>
          <?php foreach ($availableMonths as $am): ?>
            <li>
              <a class="dropdown-item d-flex justify-content-between align-items-center py-2 <?= $am['is_selected'] ? 'active fw-bold' : '' ?>" href="?month=<?= $am['month'] ?>&year=<?= $am['year'] ?>">
                <span><?= $am['month_label'] ?></span>
                <?php if ($am['is_selected']): ?>
                  <i class="bi bi-check2 text-white"></i>
                <?php endif; ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <a href="<?= BASE_URL ?>employee/dashboard.php" class="btn btn-outline-secondary rounded-pill px-3">
        <i class="bi bi-arrow-left me-1"></i> Dashboard
      </a>
    </div>
  </div>

  <!-- Three Top Summary KPI Cards (Gross Earnings, Total Deductions, Net Pay) -->
  <div class="row g-3 mb-4">
    <!-- Card 1: Gross Earnings (Green) -->
    <div class="col-md-4">
      <div class="card border-0 shadow-sm p-3 h-100" style="border-radius:16px; background:#f0fdf4; border:1px solid #bbf7d0 !important;">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="rounded-3 p-2 d-flex align-items-center justify-content-center" style="background:#dcfce7; width:34px; height:34px;">
            <i class="bi bi-wallet2 text-success fs-6"></i>
          </div>
          <span class="text-uppercase fw-bold text-muted" style="font-size:0.75rem; letter-spacing:0.5px;">Gross Earnings</span>
        </div>
        <div class="fw-bold fs-3 text-dark mb-1">₹<?= number_format($grossEarnings, 2) ?></div>
        <div class="text-muted small" style="font-size:0.8rem;">Total Earnings</div>
      </div>
    </div>

    <!-- Card 2: Total Deductions (Red) -->
    <div class="col-md-4">
      <div class="card border-0 shadow-sm p-3 h-100" style="border-radius:16px; background:#fef2f2; border:1px solid #fecaca !important;">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="rounded-3 p-2 d-flex align-items-center justify-content-center" style="background:#fee2e2; width:34px; height:34px;">
            <i class="bi bi-pie-chart text-danger fs-6"></i>
          </div>
          <span class="text-uppercase fw-bold text-muted" style="font-size:0.75rem; letter-spacing:0.5px;">Total Deductions</span>
        </div>
        <div class="fw-bold fs-3 text-danger mb-1">₹<?= number_format($totalDeductions, 2) ?></div>
        <div class="text-muted small" style="font-size:0.8rem;">This Month</div>
      </div>
    </div>

    <!-- Card 3: Net Pay (Blue) -->
    <div class="col-md-4">
      <div class="card border-0 shadow-sm p-3 h-100" style="border-radius:16px; background:#eff6ff; border:1px solid #bfdbfe !important;">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="rounded-3 p-2 d-flex align-items-center justify-content-center" style="background:#dbeafe; width:34px; height:34px;">
            <i class="bi bi-cash-coin text-primary fs-6"></i>
          </div>
          <span class="text-uppercase fw-bold text-primary" style="font-size:0.75rem; letter-spacing:0.5px;">Net Pay</span>
        </div>
        <div class="fw-bold fs-3 text-primary mb-1">₹<?= number_format($netPay, 2) ?></div>
        <div class="text-muted small" style="font-size:0.8rem;">Amount to be Paid</div>
      </div>
    </div>
  </div>

  <!-- Collapsible Section 1: Earnings Breakdown -->
  <div class="card border-0 shadow-sm mb-3" style="border-radius:16px; border:1px solid #e2e8f0 !important;">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center" 
         role="button" data-bs-toggle="collapse" data-bs-target="#collapseEarnings" aria-expanded="true" style="border-radius:16px;">
      <div class="d-flex align-items-center gap-2">
        <div class="rounded-3 p-1 d-flex align-items-center justify-content-center" style="background:#dcfce7; width:28px; height:28px;">
          <i class="bi bi-wallet2 text-success" style="font-size:0.9rem;"></i>
        </div>
        <h5 class="fw-bold text-dark mb-0 fs-6">Earnings Breakdown</h5>
      </div>
      <i class="bi bi-chevron-down text-muted transition-icon"></i>
    </div>
    <div class="collapse show" id="collapseEarnings">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" style="font-size:0.88rem;">
            <thead class="table-light">
              <tr>
                <th class="ps-4 text-muted fw-bold">Earning Head</th>
                <th class="pe-4 text-end text-muted fw-bold">Amount (₹)</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($earningsList)): ?>
                <tr><td colspan="2" class="text-center text-muted py-3">No earning heads recorded.</td></tr>
              <?php else: ?>
                <?php foreach ($earningsList as $e): ?>
                  <tr>
                    <td class="ps-4 text-dark"><?= htmlspecialchars($e['head']) ?></td>
                    <td class="pe-4 text-end fw-semibold text-dark">₹<?= number_format($e['amount'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              <tr style="background:#f0fdf4;">
                <td class="ps-4 fw-bold text-success">Total Earnings (A)</td>
                <td class="pe-4 text-end fw-bold text-success fs-6">₹<?= number_format($grossEarnings, 2) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Collapsible Section 2: Deductions -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius:16px; border:1px solid #e2e8f0 !important;">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center" 
         role="button" data-bs-toggle="collapse" data-bs-target="#collapseDeductions" aria-expanded="true" style="border-radius:16px;">
      <div class="d-flex align-items-center gap-2">
        <div class="rounded-3 p-1 d-flex align-items-center justify-content-center" style="background:#fee2e2; width:28px; height:28px;">
          <i class="bi bi-pie-chart text-danger" style="font-size:0.9rem;"></i>
        </div>
        <h5 class="fw-bold text-dark mb-0 fs-6">Deductions</h5>
      </div>
      <i class="bi bi-chevron-down text-muted transition-icon"></i>
    </div>
    <div class="collapse show" id="collapseDeductions">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" style="font-size:0.88rem;">
            <thead class="table-light">
              <tr>
                <th class="ps-4 text-muted fw-bold">Deduction Head</th>
                <th class="pe-4 text-end text-muted fw-bold">Amount (₹)</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($deductionsList)): ?>
                <tr>
                  <td colspan="2" class="ps-4 py-3 text-muted">
                    <i class="bi bi-check-circle-fill text-success me-1"></i> No deductions recorded for this cycle.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($deductionsList as $d): ?>
                  <tr>
                    <td class="ps-4 text-dark"><?= htmlspecialchars($d['head']) ?></td>
                    <td class="pe-4 text-end fw-semibold text-danger">₹<?= number_format($d['amount'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              <tr style="background:#fef2f2;">
                <td class="ps-4 fw-bold text-danger">Total Deductions (B)</td>
                <td class="pe-4 text-end fw-bold text-danger fs-6">₹<?= number_format($totalDeductions, 2) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Section 3: Net Total Pay Banner (Calculator & Download) -->
  <div class="card border-0 shadow-sm p-4 mb-4 text-white" style="border-radius:18px; background:linear-gradient(135deg, #4f46e5 0%, #2563eb 50%, #38bdf8 100%);">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div class="d-flex align-items-center gap-3">
        <div class="rounded-4 p-3 d-flex align-items-center justify-content-center" style="background:rgba(255,255,255,0.2); width:54px; height:54px;">
          <i class="bi bi-calculator fs-3 text-white"></i>
        </div>
        <div>
          <div class="text-white-50 small fw-medium text-uppercase" style="letter-spacing:0.5px;">Net Total Pay (A – B)</div>
          <div class="fw-bold fs-2 text-white">₹<?= number_format($netPay, 2) ?></div>
        </div>
      </div>
      <div>
        <?php if ($curPay): ?>
          <a href="<?= BASE_URL ?>employee/download-salary-slip.php?id=<?= $curPay['id'] ?>" target="_blank" class="btn btn-light rounded-pill px-4 py-2 fw-bold text-primary shadow-sm">
            <i class="bi bi-download me-1"></i> Download Payslip
          </a>
        <?php else: ?>
          <button class="btn btn-light rounded-pill px-4 py-2 fw-bold text-muted shadow-sm" disabled>
            <i class="bi bi-clock me-1"></i> Payslip Pending
          </button>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Collapsible Section 4: Upcoming Payment Card -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius:16px; background:#fffbeb; border:1px solid #fde68a !important;">
    <div class="card-body p-3 p-md-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
          <div class="rounded-3 p-2 d-flex align-items-center justify-content-center" style="background:#fef3c7; width:44px; height:44px;">
            <i class="bi bi-calendar-event text-warning fs-4"></i>
          </div>
          <div>
            <div class="text-muted small fw-medium">Upcoming Payment</div>
            <div class="d-flex align-items-center gap-2">
              <h5 class="fw-bold text-dark mb-0"><?= $nextMonthName ?></h5>
              <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 small">
                <i class="bi bi-check-circle me-1"></i>On Time
              </span>
            </div>
            <div class="text-muted small" style="font-size:0.8rem;">Expected Payment Date: <?= $nextPayDate ?></div>
          </div>
        </div>
        <div class="text-end">
          <div class="text-muted small">Estimated Amount</div>
          <div class="fw-bold fs-4 text-dark">₹<?= number_format($upcomingAmount, 2) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Collapsible Section 5: Monthly Disbursement History (Previous Salary) -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius:16px; border:1px solid #e2e8f0 !important;">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center" 
         role="button" data-bs-toggle="collapse" data-bs-target="#collapseHistory" aria-expanded="true" style="border-radius:16px;">
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-clock-history text-secondary fs-5"></i>
        <h5 class="fw-bold text-dark mb-0 fs-6">Previous Salary (History)</h5>
      </div>
      <i class="bi bi-chevron-down text-muted transition-icon"></i>
    </div>
    <div class="collapse show" id="collapseHistory">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" style="font-size:0.88rem; min-width: 720px;">
            <thead class="table-light">
              <tr>
                <th class="ps-4 text-nowrap">Month</th>
                <th class="text-nowrap">Base Salary</th>
                <th class="text-nowrap">Allowances</th>
                <th class="text-nowrap">Deductions</th>
                <th class="text-nowrap">Net Disbursed</th>
                <th class="text-nowrap">Status</th>
                <th class="pe-4 text-end text-nowrap">Salary Slip</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($payrolls)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No payroll records finalized yet.</td></tr>
              <?php else: ?>
                <?php foreach ($payrolls as $p): 
                  $pMonthLabel = date('F Y', mktime(0,0,0, $p['payroll_month'], 1, $p['payroll_year']));
                  $pStatus = $p['status'];
                  $bColor = match($pStatus) {
                    'Paid' => 'bg-success-subtle text-success border border-success-subtle',
                    'Pending' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                    default => 'bg-secondary-subtle text-secondary'
                  };
                  $pDeductions = (float)$p['late_deduction'] + (float)$p['other_deduction'] + (float)($p['advance_deducted'] ?? 0);
                ?>
                <tr>
                  <td class="ps-4 fw-bold text-dark text-nowrap">
                    <a href="?month=<?= $p['payroll_month'] ?>&year=<?= $p['payroll_year'] ?>" class="text-decoration-none text-dark">
                      <?= $pMonthLabel ?>
                    </a>
                  </td>
                  <td class="text-nowrap">₹<?= number_format((float)$p['base_salary'], 2) ?></td>
                  <td class="text-nowrap">₹<?= number_format((float)($p['allowances'] ?? 0), 2) ?></td>
                  <td class="text-danger fw-medium text-nowrap">
                    <?= $pDeductions > 0 ? '−₹' . number_format($pDeductions, 2) : '₹0.00' ?>
                  </td>
                  <td class="fw-bold text-success fs-6 text-nowrap">₹<?= number_format((float)$p['net_salary'], 2) ?></td>
                  <td class="text-nowrap">
                    <span class="badge <?= $bColor ?> px-2 py-1 rounded-pill fw-semibold"><?= $pStatus ?></span>
                    <?php if (!empty($p['payment_date'])): ?>
                      <div class="text-muted small" style="font-size:0.7rem;"><?= date('d M Y', strtotime($p['payment_date'])) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="pe-4 text-end text-nowrap">
                    <a href="<?= BASE_URL ?>employee/download-salary-slip.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold">
                      <i class="bi bi-file-earmark-pdf me-1"></i> View
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Historical Salary Revisions Info (if exists) -->
  <?php if (!empty($salStructures)): ?>
  <div class="card border-0 shadow-sm mb-4" style="border-radius:14px; border:1px solid #e2e8f0 !important;">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center"
         role="button" data-bs-toggle="collapse" data-bs-target="#collapseRevisions" aria-expanded="false" style="border-radius:14px;">
      <h6 class="fw-bold text-dark mb-0"><i class="bi bi-file-earmark-text me-2 text-secondary"></i>Historical Salary Revisions</h6>
      <i class="bi bi-chevron-down text-muted transition-icon"></i>
    </div>
    <div class="collapse" id="collapseRevisions">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0" style="font-size:0.8rem; min-width: 520px;">
            <thead class="table-light">
              <tr>
                <th class="ps-4 text-nowrap">Effective Date</th>
                <th class="text-nowrap">Basic Salary</th>
                <th class="text-nowrap">Allowances</th>
                <th class="text-nowrap">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($salStructures as $ss): ?>
              <tr>
                <td class="ps-4 fw-medium"><?= date('d M Y', strtotime($ss['effective_from'])) ?></td>
                <td class="fw-semibold">₹<?= number_format((float)$ss['basic_salary'], 2) ?></td>
                <td>₹<?= number_format((float)($ss['housing_allowance'] + $ss['transport_allowance'] + $ss['other_allowance']), 2) ?></td>
                <td>
                  <span class="badge bg-<?= $ss['status'] === 'active' ? 'success' : 'secondary' ?>-subtle text-<?= $ss['status'] === 'active' ? 'success' : 'secondary' ?> rounded-pill">
                    <?= ucfirst($ss['status']) ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<style>
.transition-icon {
  transition: transform 0.2s ease-in-out;
}
[aria-expanded="false"] .transition-icon {
  transform: rotate(-90deg);
}
</style>

<?php require_once __DIR__ . '/footer.php'; ?>
