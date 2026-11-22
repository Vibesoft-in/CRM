<?php
// employee/salary-slips.php — List of downloadable salary slips
require_once __DIR__ . '/layout.php';

$empId = (int)($curEmp['id'] ?? 0);
if ($empId <= 0) {
    abort_forbidden("No employee profile linked to your account.");
}

$payStmt = $pdo->prepare("
    SELECT * FROM payroll 
    WHERE employee_id = ? 
    ORDER BY payroll_year DESC, payroll_month DESC
");
$payStmt->execute([$empId]);
$payrolls = $payStmt->fetchAll();
?>

<div class="container-fluid px-0">

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h3 class="fw-bold mb-0"><i class="bi bi-receipt text-primary me-2"></i>My Salary Slips</h3>
      <p class="text-muted mb-0 small">Download official monthly payslips for bank, tax, or record purposes</p>
    </div>
    <a href="<?= BASE_URL ?>employee/dashboard.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i> Dashboard
    </a>
  </div>

  <div class="card border-0 shadow-sm" style="border-radius:16px;">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
      <h6 class="fw-bold text-dark mb-0"><i class="bi bi-receipt me-2 text-primary"></i>Available Payslips</h6>
      <span class="text-muted small d-md-none" style="font-size:0.72rem;"><i class="bi bi-arrow-left-right me-1"></i>Scrollable</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.85rem; min-width: 650px;">
          <thead class="table-light">
            <tr>
              <th class="ps-4 text-nowrap">Month & Year</th>
              <th class="text-nowrap">Basic Salary</th>
              <th class="text-nowrap">Net Salary</th>
              <th class="text-nowrap">Payment Date</th>
              <th class="text-nowrap">Status</th>
              <th class="pe-4 text-end text-nowrap">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($payrolls)): ?>
              <tr><td colspan="6" class="text-center text-muted py-5">No salary slips generated yet.</td></tr>
            <?php else: ?>
              <?php foreach ($payrolls as $p): 
                $monthLabel = date('F Y', mktime(0,0,0, $p['payroll_month'], 1, $p['payroll_year']));
              ?>
              <tr>
                <td class="ps-4 fw-bold text-dark text-nowrap"><?= $monthLabel ?></td>
                <td class="text-nowrap">₹<?= number_format((float)$p['base_salary'], 2) ?></td>
                <td class="fw-bold text-success text-nowrap">₹<?= number_format((float)$p['net_salary'], 2) ?></td>
                <td class="text-muted text-nowrap"><?= !empty($p['payment_date']) ? date('d M Y', strtotime($p['payment_date'])) : '—' ?></td>
                <td class="text-nowrap"><span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><?= $p['status'] ?></span></td>
                <td class="pe-4 text-end text-nowrap">
                  <a href="<?= BASE_URL ?>employee/download-salary-slip.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-primary fw-semibold shadow-sm">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i> Download Payslip
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

<?php require_once __DIR__ . '/footer.php'; ?>
