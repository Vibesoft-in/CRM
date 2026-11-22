<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_advance') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $employee_id    = (int)$_POST['employee_id'];
        $amount         = (float)$_POST['amount'];
        $request_date   = $_POST['request_date'] ?: date('Y-m-d');
        $reason         = trim($_POST['reason']);
        $payment_method = $_POST['payment_method'] ?? 'Bank Transfer';
        $payroll_month  = (int)date('n', strtotime($request_date));
        $payroll_year   = (int)date('Y', strtotime($request_date));
        
        if ($employee_id <= 0 || $amount <= 0) {
            $err = 'Employee and positive Advance Amount are required.';
        } else {
            // Note: Salary advance is recorded in salary_advances table.
            // To ensure business rule #4 (do not count advance twice as expense),
            // advance is deducted from payroll at month-end payroll processing.
            $ins = $pdo->prepare("INSERT INTO salary_advances (employee_id, request_date, amount, reason, payment_method, status, payroll_month, payroll_year, approved_by, created_at) VALUES (?, ?, ?, ?, ?, 'Paid', ?, ?, ?, NOW())");
            $ins->execute([$employee_id, $request_date, $amount, $reason, $payment_method, $payroll_month, $payroll_year, $_SESSION['user_id']]);
            
            set_flash('Salary advance recorded successfully!', 'success');
            header('Location: salary_advances.php');
            exit;
        }
    }
}

$selectedEmpId = (int)($_GET['employee_id'] ?? 0);

$where = ["1=1"];
$params = [];
if ($selectedEmpId > 0) {
    $where[] = "sa.employee_id = ?";
    $params[] = $selectedEmpId;
}

$whereSql = implode(" AND ", $where);
$stmt = $pdo->prepare("
    SELECT sa.*, emp.full_name, emp.employee_code
    FROM salary_advances sa
    JOIN employees emp ON emp.id = sa.employee_id
    WHERE $whereSql
    ORDER BY sa.request_date DESC
");
$stmt->execute($params);
$advances = $stmt->fetchAll();

$employees = $pdo->query("SELECT id, full_name, employee_code FROM employees WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();
?>

<div class="container-fluid">
  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-cash-stack text-primary me-2"></i>Salary Advances</h4>
      <p class="text-muted small mb-0">Record mid-month employee advance payments for automatic deduction in payroll.</p>
    </div>
    <button class="btn btn-warning shadow-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#addAdvanceModal">
      <i class="bi bi-plus-circle me-1"></i> Pay Salary Advance
    </button>
  </div>

  <?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Advances Table -->
  <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light text-muted small uppercase">
          <tr>
            <th class="ps-3">Date</th>
            <th>Employee</th>
            <th>Reason / Description</th>
            <th>Payment Method</th>
            <th class="text-end">Advance Amount</th>
            <th>Payroll Period</th>
            <th class="pe-3 text-center">Deduction Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($advances): ?>
            <?php foreach ($advances as $adv): ?>
              <tr>
                <td class="ps-3 fw-medium"><?= e($adv['request_date']) ?></td>
                <td>
                  <a href="employee_profile.php?id=<?= $adv['employee_id'] ?>" class="fw-bold text-dark text-decoration-none">
                    <?= e($adv['full_name']) ?>
                  </a>
                  <small class="d-block text-muted font-monospace"><?= e($adv['employee_code']) ?></small>
                </td>
                <td><?= e($adv['reason'] ?: 'Mid-month advance') ?></td>
                <td><span class="badge bg-light text-dark border"><?= e($adv['payment_method']) ?></span></td>
                <td class="text-end fw-bold text-danger">₹<?= money($adv['amount']) ?></td>
                <td><?= date("F Y", mktime(0,0,0,$adv['payroll_month'],1,$adv['payroll_year'])) ?></td>
                <td class="pe-3 text-center">
                  <?php if ($adv['status'] === 'Deducted'): ?>
                    <span class="badge bg-secondary"><i class="bi bi-check-all me-1"></i> Deducted in Payroll</span>
                  <?php else: ?>
                    <span class="badge bg-success"><i class="bi bi-clock me-1"></i> Paid (Pending Deduction)</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="text-center py-5 text-muted">No salary advances recorded.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: Add Salary Advance -->
<div class="modal fade" id="addAdvanceModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_advance">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-cash-stack text-warning me-2"></i>Record Salary Advance</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-medium">Select Employee <span class="text-danger">*</span></label>
            <select name="employee_id" class="form-select" required>
              <option value="">-- Select Employee --</option>
              <?php foreach ($employees as $emp): ?>
                <option value="<?= $emp['id'] ?>" <?= $selectedEmpId==$emp['id']?'selected':'' ?>>
                  <?= e($emp['full_name']) ?> (<?= e($emp['employee_code']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Advance Amount (₹) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" name="amount" class="form-control" placeholder="5000.00" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Advance Payout Date</label>
            <input type="date" name="request_date" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Payment Method</label>
            <select name="payment_method" class="form-select">
              <option value="Bank Transfer">Bank Transfer</option>
              <option value="UPI">UPI</option>
              <option value="Cash">Cash</option>
              <option value="Cheque">Cheque</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Reason / Note</label>
            <input type="text" name="reason" class="form-control" placeholder="e.g. Medical emergency advance">
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning fw-semibold"><i class="bi bi-check-circle me-1"></i> Disburse Advance</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>
