<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$err = '';

// Handle Payroll Process (New Disbursement)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_payroll') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $employee_id      = (int)$_POST['employee_id'];
        $payroll_month    = (int)$_POST['payroll_month'];
        $payroll_year     = (int)$_POST['payroll_year'];
        $base_salary      = (float)$_POST['base_salary'];
        $allowances       = (float)($_POST['allowances'] ?? 0);
        $bonus            = (float)($_POST['bonus'] ?? 0);
        $advance_deducted = (float)($_POST['advance_deducted'] ?? 0);
        $late_deduction   = (float)($_POST['late_deduction'] ?? 0);
        $other_deduction  = (float)($_POST['other_deduction'] ?? 0);

        $gross = $base_salary + $allowances + $bonus;
        $totalDeductions = $advance_deducted + $late_deduction + $other_deduction;
        $netSalary = max(0, $gross - $totalDeductions);

        $payment_method  = $_POST['payment_method'] ?? 'Bank Transfer';
        $transaction_ref = trim($_POST['transaction_ref'] ?? '');
        $payment_date    = $_POST['payment_date'] ?: date('Y-m-d');

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("INSERT INTO payroll (
                employee_id, payroll_month, payroll_year, base_salary, allowances, bonus, 
                advance_deducted, late_deduction, other_deduction, net_salary, amount_paid, amount_pending, 
                status, payment_date, payment_method, transaction_ref, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Paid', ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                base_salary=VALUES(base_salary), allowances=VALUES(allowances), bonus=VALUES(bonus), 
                advance_deducted=VALUES(advance_deducted), late_deduction=VALUES(late_deduction), other_deduction=VALUES(other_deduction),
                net_salary=VALUES(net_salary), amount_paid=VALUES(amount_paid), status='Paid', 
                payment_date=VALUES(payment_date), payment_method=VALUES(payment_method), transaction_ref=VALUES(transaction_ref)");

            $ins->execute([
                $employee_id, $payroll_month, $payroll_year, $base_salary, $allowances, $bonus,
                $advance_deducted, $late_deduction, $other_deduction, $netSalary, $netSalary,
                $payment_date, $payment_method, $transaction_ref
            ]);

            // Mark all un-deducted advances for this employee as Deducted in this payroll period
            if ($advance_deducted > 0) {
                $updAdv = $pdo->prepare("UPDATE salary_advances SET status = 'Deducted', payroll_month = ?, payroll_year = ? WHERE employee_id = ? AND status = 'Paid'");
                $updAdv->execute([$payroll_month, $payroll_year, $employee_id]);
            }

            $pdo->commit();
            set_flash('Payroll processed and salary slip generated!', 'success');
            header("Location: payroll.php?month=$payroll_month&year=$payroll_year");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $err = 'Error processing payroll: ' . $e->getMessage();
        }
    }
}

// Handle Edit Payroll Record POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_payroll') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $payroll_id       = (int)$_POST['payroll_id'];
        $base_salary      = (float)$_POST['base_salary'];
        $allowances       = (float)($_POST['allowances'] ?? 0);
        $bonus            = (float)($_POST['bonus'] ?? 0);
        $advance_deducted = (float)($_POST['advance_deducted'] ?? 0);
        $late_deduction   = (float)($_POST['late_deduction'] ?? 0);
        $other_deduction  = (float)($_POST['other_deduction'] ?? 0);

        $gross = $base_salary + $allowances + $bonus;
        $totalDeductions = $advance_deducted + $late_deduction + $other_deduction;
        $netSalary = max(0, $gross - $totalDeductions);

        $payment_method  = $_POST['payment_method'] ?? 'Bank Transfer';
        $transaction_ref = trim($_POST['transaction_ref'] ?? '');
        $payment_date    = $_POST['payment_date'] ?: date('Y-m-d');

        if ($payroll_id <= 0) {
            $err = 'Invalid payroll record.';
        } else {
            $upd = $pdo->prepare("UPDATE payroll SET 
                base_salary = ?, allowances = ?, bonus = ?, advance_deducted = ?, 
                late_deduction = ?, other_deduction = ?, net_salary = ?, amount_paid = ?, 
                payment_date = ?, payment_method = ?, transaction_ref = ? 
                WHERE id = ?");
            $upd->execute([
                $base_salary, $allowances, $bonus, $advance_deducted,
                $late_deduction, $other_deduction, $netSalary, $netSalary,
                $payment_date, $payment_method, $transaction_ref, $payroll_id
            ]);

            set_flash('Salary details updated successfully!', 'success');
            header("Location: payroll.php?month=" . ($_GET['month'] ?? date('n')) . "&year=" . ($_GET['year'] ?? date('Y')));
            exit;
        }
    }
}

$selectedMonth = (int)($_GET['month'] ?? date('n'));
$selectedYear  = (int)($_GET['year'] ?? date('Y'));

// Calculate the last date of the selected payroll month (e.g. 2026-06-30)
$selectedPeriodLastDay = date('Y-m-t', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));

// Fetch active employees whose joining date is on or before the selected month
$stmt = $pdo->prepare("
    SELECT emp.*,
      p.id AS payroll_id, p.base_salary AS p_base, p.allowances AS p_allowances, p.bonus AS p_bonus,
      p.advance_deducted, p.late_deduction, p.other_deduction, p.net_salary, p.amount_paid, p.status AS payroll_status,
      p.payment_date, p.payment_method, p.transaction_ref,
      (SELECT COALESCE(SUM(amount), 0) FROM salary_advances WHERE employee_id = emp.id AND status = 'Paid') AS pending_advance
    FROM employees emp
    LEFT JOIN payroll p ON p.employee_id = emp.id AND p.payroll_month = ? AND p.payroll_year = ?
    WHERE emp.status = 'active'
      AND (emp.joining_date IS NULL OR emp.joining_date <= ? OR p.id IS NOT NULL)
    ORDER BY emp.full_name ASC
");
$stmt->execute([$selectedMonth, $selectedYear, $selectedPeriodLastDay]);
$payrollRows = $stmt->fetchAll();


// Calculate Monthly Summary Metrics & Pending Salaries
$pendingRows = [];
$totalPendingAmt = 0;
$totalPaidAmt = 0;
$paidCount = 0;
$pendingCount = 0;
$totalBudget = 0;

foreach ($payrollRows as $r) {
    $adv = (float)$r['pending_advance'];
    $late = 0;
    $other = 0;
    if ($r['payroll_id']) {
        $adv = (float)$r['advance_deducted'];
        $late = (float)$r['late_deduction'];
        $other = (float)$r['other_deduction'];
    }
    $totDeductions = $adv + $late + $other;
    $baseSal = (float)($r['payroll_id'] ? $r['p_base'] : $r['base_salary']);
    $net = (float)($r['payroll_id'] ? $r['net_salary'] : max(0, $baseSal - $totDeductions));
    $totalBudget += $net;

    if ($r['payroll_status'] === 'Paid') {
        $totalPaidAmt += $net;
        $paidCount++;
    } else {
        $totalPendingAmt += $net;
        $pendingCount++;
        $r['computed_net'] = $net;
        $pendingRows[] = $r;
    }
}
?>

<div class="container-fluid">

  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-bank text-primary me-2"></i>Monthly Team Payroll</h4>
      <p class="text-muted small mb-0">Compute monthly salaries, manage deductions, edit payments, and generate official payslips.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="salary_advances.php" class="btn btn-outline-warning">
        <i class="bi bi-cash-stack me-1"></i> Salary Advances
      </a>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
      <i class="bi bi-exclamation-triangle me-2"></i><?= e($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- PENDING SALARY ALERT BANNER (If any salary is pending) -->
  <?php if ($pendingCount > 0): ?>
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; background: linear-gradient(135deg, #fff5f5 0%, #ffffff 100%); border-left: 5px solid #dc3545 !important;">
      <div class="card-body p-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
          <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle bg-danger text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px; font-size: 1.5rem;">
              <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <div>
              <h5 class="fw-bold text-danger mb-1">
                Pending Salary Alert — <?= date("F Y", mktime(0,0,0,$selectedMonth,1,$selectedYear)) ?>
              </h5>
              <p class="text-muted small mb-0">
                Total <strong><?= $pendingCount ?> employee(s)</strong> have unpaid pending salary for this month. Total Pending Amount: <strong class="text-danger fs-6">₹<?= money($totalPendingAmt) ?></strong>
              </p>
            </div>
          </div>
          <span class="badge bg-danger fs-6 px-3 py-2"><i class="bi bi-clock-history me-1"></i> <?= $pendingCount ?> Pending</span>
        </div>

        <!-- Quick Pending Employees Chips / Action Bar -->
        <div class="bg-white p-3 rounded-3 border">
          <label class="form-label small fw-bold text-muted uppercase mb-2 d-block"><i class="bi bi-person-exclamation text-danger me-1"></i> Unpaid Staff Members:</label>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($pendingRows as $pRow): ?>
              <div class="bg-light border rounded-3 p-2 px-3 d-flex align-items-center justify-content-between gap-3" style="min-width: 240px;">
                <div>
                  <span class="fw-bold text-dark d-block" style="font-size: 0.9rem;"><?= e($pRow['full_name']) ?></span>
                  <small class="text-muted"><?= e($pRow['designation'] ?: 'Staff') ?> &bull; <strong class="text-danger">₹<?= money($pRow['computed_net']) ?></strong></small>
                </div>
                <button class="btn btn-sm btn-success fw-semibold pay-payroll-btn px-2 py-1" 
                        data-emp='<?= json_encode($pRow, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                        data-bs-toggle="modal"
                        data-bs-target="#paySalaryModal"
                        title="Pay Salary Now">
                  <i class="bi bi-cash-coin"></i> Pay
                </button>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="alert alert-success d-flex align-items-center shadow-sm mb-4" role="alert" style="border-radius: 12px;">
      <i class="bi bi-check-circle-fill fs-4 me-3"></i>
      <div>
        <h6 class="fw-bold mb-0">All Salaries Paid for <?= date("F Y", mktime(0,0,0,$selectedMonth,1,$selectedYear)) ?>!</h6>
        <small>Great job! All active staff members have been fully disbursed for this month.</small>
      </div>
    </div>
  <?php endif; ?>

  <!-- Financial KPI Metrics for Selected Month -->
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card border-0 shadow-sm p-3" style="border-radius: 12px; background: #ffffff;">
        <small class="text-muted d-block uppercase fw-bold" style="font-size: 0.7rem;">TOTAL MONTHLY PAYROLL BUDGET</small>
        <span class="fw-bold fs-4 text-dark mt-1 d-block">₹<?= money($totalBudget) ?></span>
        <small class="text-muted" style="font-size: 0.75rem;"><i class="bi bi-people me-1"></i><?= count($payrollRows) ?> Active Staff Members</small>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card border-0 shadow-sm p-3" style="border-radius: 12px; background: #ffffff; border-left: 4px solid #198754 !important;">
        <small class="text-muted d-block uppercase fw-bold" style="font-size: 0.7rem;">TOTAL SALARY PAID</small>
        <span class="fw-bold fs-4 text-success mt-1 d-block">₹<?= money($totalPaidAmt) ?></span>
        <small class="text-success fw-semibold" style="font-size: 0.75rem;"><i class="bi bi-check-circle me-1"></i><?= $paidCount ?> Staff Disbursed</small>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card border-0 shadow-sm p-3" style="border-radius: 12px; background: #ffffff; border-left: 4px solid #dc3545 !important;">
        <small class="text-muted d-block uppercase fw-bold" style="font-size: 0.7rem;">TOTAL PENDING SALARY</small>
        <span class="fw-bold fs-4 text-<?= $totalPendingAmt>0?'danger':'muted' ?> mt-1 d-block">₹<?= money($totalPendingAmt) ?></span>
        <small class="text-<?= $totalPendingAmt>0?'danger':'muted' ?> fw-semibold" style="font-size: 0.75rem;"><i class="bi bi-clock me-1"></i><?= $pendingCount ?> Staff Pending</small>
      </div>
    </div>
  </div>

  <!-- Month Picker -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
    <div class="card-body p-3">
      <form method="get" class="row g-3 align-items-center">
        <div class="col-md-3">
          <label class="form-label small text-muted mb-1">Payroll Month</label>
          <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php for($m=1; $m<=12; $m++): ?>
              <option value="<?= $m ?>" <?= $selectedMonth==$m?'selected':'' ?>>
                <?= date("F", mktime(0,0,0,$m,1)) ?>
              </option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small text-muted mb-1">Payroll Year</label>
          <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php for($y=date("Y"); $y>=2024; $y--): ?>
              <option value="<?= $y ?>" <?= $selectedYear==$y?'selected':'' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </form>
    </div>
  </div>


  <!-- Payroll Table -->
  <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light text-muted small uppercase">
          <tr>
            <th class="ps-3">Employee</th>
            <th>Designation</th>
            <th>Base Salary</th>
            <th>Deductions Breakdown</th>
            <th>Net Payable</th>
            <th>Status</th>
            <th class="pe-3 text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($payrollRows): ?>
            <?php foreach ($payrollRows as $r): 
              $adv = (float)$r['pending_advance'];
              $late = 0;
              $other = 0;
              if ($r['payroll_id']) {
                  $adv = (float)$r['advance_deducted'];
                  $late = (float)$r['late_deduction'];
                  $other = (float)$r['other_deduction'];
              }
              $totDeductions = $adv + $late + $other;
              $baseSal = (float)($r['payroll_id'] ? $r['p_base'] : $r['base_salary']);
              $net = (float)($r['payroll_id'] ? $r['net_salary'] : max(0, $baseSal - $totDeductions));
            ?>
                <td class="ps-3">
                  <div class="d-flex align-items-center">
                    <?= get_employee_avatar_html($r, 36) ?>
                    <div>
                      <a href="employee_profile.php?id=<?= $r['id'] ?>" class="fw-bold text-dark text-decoration-none d-block">
                        <?= e($r['full_name']) ?>
                      </a>
                      <small class="text-muted font-monospace"><?= e($r['employee_code']) ?></small>
                    </div>
                  </div>
                </td>

                <td><?= e($r['designation'] ?: 'Team Member') ?></td>
                <td class="fw-semibold text-dark">₹<?= money($baseSal) ?></td>
                <td>
                  <?php if ($totDeductions > 0): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle" title="Advance: ₹<?= money($adv) ?> | Late: ₹<?= money($late) ?> | Other: ₹<?= money($other) ?>">
                      <i class="bi bi-dash-circle me-1"></i>Total -₹<?= money($totDeductions) ?>
                    </span>
                  <?php else: ?>
                    <span class="text-muted small">₹0.00</span>
                  <?php endif; ?>
                </td>
                <td class="fw-bold text-success fs-6">
                  ₹<?= money($net) ?>
                </td>
                <td>
                  <?php if ($r['payroll_status'] === 'Paid'): ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Paid</span>
                  <?php else: ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i> Pending</span>
                  <?php endif; ?>
                </td>
                <td class="pe-3 text-end">
                  <?php if ($r['payroll_status'] === 'Paid'): ?>
                    <div class="dropdown">
                      <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-three-dots-vertical"></i> Actions
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li>
                          <a href="salary_slip_pdf.php?id=<?= $r['payroll_id'] ?>" target="_blank" class="dropdown-item">
                            <i class="bi bi-file-earmark-pdf text-primary me-2"></i> View Payslip
                          </a>
                        </li>
                        <li>
                          <a href="employee_profile.php?id=<?= $r['id'] ?>&tab=salary_history" class="dropdown-item">
                            <i class="bi bi-graph-up-arrow text-warning me-2"></i> Salary Hike History
                          </a>
                        </li>
                        <li>
                          <button type="button" class="dropdown-item edit-payroll-btn" 
                                  data-payroll='<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                                  data-bs-toggle="modal"
                                  data-bs-target="#editSalaryModal">
                            <i class="bi bi-pencil text-secondary me-2"></i> Edit Salary & Deductions
                          </button>
                        </li>
                      </ul>
                    </div>

                  <?php else: ?>
                    <div class="dropdown">
                      <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-three-dots-vertical"></i> Actions
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li>
                          <button type="button" class="dropdown-item text-success fw-semibold pay-payroll-btn" 
                                  data-emp='<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                                  data-bs-toggle="modal"
                                  data-bs-target="#paySalaryModal">
                            <i class="bi bi-cash-coin text-success me-2"></i> Pay Salary
                          </button>
                        </li>
                        <li>
                          <a href="employee_profile.php?id=<?= $r['id'] ?>&tab=salary_history" class="dropdown-item">
                            <i class="bi bi-graph-up-arrow text-warning me-2"></i> Salary Hike History
                          </a>
                        </li>
                        <li>
                          <a href="employee_profile.php?id=<?= $r['id'] ?>&tab=payroll" class="dropdown-item">
                            <i class="bi bi-person text-secondary me-2"></i> Profile & Slips
                          </a>
                        </li>
                      </ul>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="text-center py-5 text-muted">No active employees found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: Pay Salary (New Disbursement) -->
<div class="modal fade" id="paySalaryModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="process_payroll">
        <input type="hidden" name="employee_id" id="modalEmpId">
        <input type="hidden" name="payroll_month" value="<?= $selectedMonth ?>">
        <input type="hidden" name="payroll_year" value="<?= $selectedYear ?>">
        
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-bank text-primary me-2"></i>Disburse Salary & Manage Deductions</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">Employee: <strong id="modalEmpName" class="text-dark fs-6"></strong></p>
          
          <div class="card border-0 bg-light mb-3">
            <div class="card-body">
              <h6 class="fw-bold text-success mb-3"><i class="bi bi-plus-circle me-1"></i>EARNINGS</h6>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label fw-medium">Base Salary (₹)</label>
                  <input type="number" step="0.01" name="base_salary" id="modalBaseSalary" class="form-control calc-pay" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-medium">Allowances (₹)</label>
                  <input type="number" step="0.01" name="allowances" id="modalAllowances" class="form-control calc-pay" value="0.00">
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-medium">Bonus (₹)</label>
                  <input type="number" step="0.01" name="bonus" id="modalBonus" class="form-control calc-pay" value="0.00">
                </div>
              </div>
            </div>
          </div>

          <div class="card border-0 bg-light mb-3">
            <div class="card-body">
              <h6 class="fw-bold text-danger mb-3"><i class="bi bi-dash-circle me-1"></i>MANAGE DEDUCTIONS</h6>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label fw-medium text-danger">Salary Advance Recovery (₹)</label>
                  <input type="number" step="0.01" name="advance_deducted" id="modalAdvance" class="form-control text-danger fw-bold calc-pay" value="0.00">
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-medium text-danger">Late / Absence Deduction (₹)</label>
                  <input type="number" step="0.01" name="late_deduction" id="modalLate" class="form-control text-danger calc-pay" value="0.00">
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-medium text-danger">Other Deductions (₹)</label>
                  <input type="number" step="0.01" name="other_deduction" id="modalOther" class="form-control text-danger calc-pay" value="0.00">
                </div>
              </div>
            </div>
          </div>

          <div class="p-3 bg-primary text-white rounded-3 mb-3 d-flex justify-content-between align-items-center">
            <span class="fw-bold">NET SALARY DISBURSED</span>
            <span class="fs-4 fw-bold" id="dispNetSalary">₹0.00</span>
          </div>

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-medium">Payment Date</label>
              <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Payment Method</label>
              <select name="payment_method" class="form-select">
                <option value="Bank Transfer" selected>Bank Transfer</option>
                <option value="UPI">UPI</option>
                <option value="Cheque">Cheque</option>
                <option value="Cash">Cash</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Transaction Reference</label>
              <input type="text" name="transaction_ref" class="form-control" placeholder="UTR / NEFT Ref">
            </div>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success fw-semibold"><i class="bi bi-check-circle me-1"></i> Disburse & Generate Slip</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Edit Salary & Deductions -->
<div class="modal fade" id="editSalaryModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit_payroll">
        <input type="hidden" name="payroll_id" id="editPayrollId">
        
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Salary & Deductions</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">Employee: <strong id="editEmpName" class="text-dark fs-6"></strong></p>
          
          <div class="card border-0 bg-light mb-3">
            <div class="card-body">
              <h6 class="fw-bold text-success mb-3"><i class="bi bi-plus-circle me-1"></i>EARNINGS</h6>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label fw-medium">Base Salary (₹)</label>
                  <input type="number" step="0.01" name="base_salary" id="editBaseSalary" class="form-control calc-edit" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-medium">Allowances (₹)</label>
                  <input type="number" step="0.01" name="allowances" id="editAllowances" class="form-control calc-edit" value="0.00">
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-medium">Bonus (₹)</label>
                  <input type="number" step="0.01" name="bonus" id="editBonus" class="form-control calc-edit" value="0.00">
                </div>
              </div>
            </div>
          </div>

          <div class="card border-0 bg-light mb-3">
            <div class="card-body">
              <h6 class="fw-bold text-danger mb-3"><i class="bi bi-dash-circle me-1"></i>MANAGE DEDUCTIONS</h6>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label fw-medium text-danger">Salary Advance Recovery (₹)</label>
                  <input type="number" step="0.01" name="advance_deducted" id="editAdvance" class="form-control text-danger fw-bold calc-edit" value="0.00">
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-medium text-danger">Late / Absence Deduction (₹)</label>
                  <input type="number" step="0.01" name="late_deduction" id="editLate" class="form-control text-danger calc-edit" value="0.00">
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-medium text-danger">Other Deductions (₹)</label>
                  <input type="number" step="0.01" name="other_deduction" id="editOther" class="form-control text-danger calc-edit" value="0.00">
                </div>
              </div>
            </div>
          </div>

          <div class="p-3 bg-primary text-white rounded-3 mb-3 d-flex justify-content-between align-items-center">
            <span class="fw-bold">RECALCULATED NET SALARY</span>
            <span class="fs-4 fw-bold" id="dispEditNetSalary">₹0.00</span>
          </div>

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-medium">Payment Date</label>
              <input type="date" name="payment_date" id="editPaymentDate" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Payment Method</label>
              <select name="payment_method" id="editPaymentMethod" class="form-select">
                <option value="Bank Transfer">Bank Transfer</option>
                <option value="UPI">UPI</option>
                <option value="Cheque">Cheque</option>
                <option value="Cash">Cash</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Transaction Reference</label>
              <input type="text" name="transaction_ref" id="editTransactionRef" class="form-control" placeholder="UTR / NEFT Ref">
            </div>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-check-circle me-1"></i> Update Salary & Payslip</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
function recalcPayNet() {
  const base  = parseFloat(document.getElementById('modalBaseSalary')?.value) || 0;
  const allow = parseFloat(document.getElementById('modalAllowances')?.value) || 0;
  const bonus = parseFloat(document.getElementById('modalBonus')?.value) || 0;
  const adv   = parseFloat(document.getElementById('modalAdvance')?.value) || 0;
  const late  = parseFloat(document.getElementById('modalLate')?.value) || 0;
  const other = parseFloat(document.getElementById('modalOther')?.value) || 0;

  const gross = base + allow + bonus;
  const ded   = adv + late + other;
  const net   = Math.max(0, gross - ded);
  const disp  = document.getElementById('dispNetSalary');
  if (disp) disp.textContent = '₹' + net.toFixed(2);
}

function recalcEditNet() {
  const base  = parseFloat(document.getElementById('editBaseSalary')?.value) || 0;
  const allow = parseFloat(document.getElementById('editAllowances')?.value) || 0;
  const bonus = parseFloat(document.getElementById('editBonus')?.value) || 0;
  const adv   = parseFloat(document.getElementById('editAdvance')?.value) || 0;
  const late  = parseFloat(document.getElementById('editLate')?.value) || 0;
  const other = parseFloat(document.getElementById('editOther')?.value) || 0;

  const gross = base + allow + bonus;
  const ded   = adv + late + other;
  const net   = Math.max(0, gross - ded);
  const disp  = document.getElementById('dispEditNetSalary');
  if (disp) disp.textContent = '₹' + net.toFixed(2);
}

document.querySelectorAll('.calc-pay').forEach(el => {
  el.addEventListener('input', recalcPayNet);
});
document.querySelectorAll('.calc-edit').forEach(el => {
  el.addEventListener('input', recalcEditNet);
});

document.querySelectorAll('.pay-payroll-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const data = JSON.parse(this.getAttribute('data-emp'));
    document.getElementById('modalEmpId').value = data.id;
    document.getElementById('modalEmpName').textContent = data.full_name;
    document.getElementById('modalBaseSalary').value = data.base_salary || 0;
    document.getElementById('modalAdvance').value = data.pending_advance || 0;
    document.getElementById('modalAllowances').value = '0.00';
    document.getElementById('modalBonus').value = '0.00';
    document.getElementById('modalLate').value = '0.00';
    document.getElementById('modalOther').value = '0.00';
    recalcPayNet();
  });
});

document.querySelectorAll('.edit-payroll-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const p = JSON.parse(this.getAttribute('data-payroll'));
    document.getElementById('editPayrollId').value = p.payroll_id;
    document.getElementById('editEmpName').textContent = p.full_name;
    document.getElementById('editBaseSalary').value = p.p_base || p.base_salary;
    document.getElementById('editAllowances').value = p.p_allowances || 0;
    document.getElementById('editBonus').value = p.p_bonus || 0;
    document.getElementById('editAdvance').value = p.advance_deducted || 0;
    document.getElementById('editLate').value = p.late_deduction || 0;
    document.getElementById('editOther').value = p.other_deduction || 0;
    document.getElementById('editPaymentDate').value = p.payment_date || '<?= date('Y-m-d') ?>';
    document.getElementById('editPaymentMethod').value = p.payment_method || 'Bank Transfer';
    document.getElementById('editTransactionRef').value = p.transaction_ref || '';
    recalcEditNet();
  });
});
</script>
