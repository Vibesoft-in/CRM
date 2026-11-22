<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$sort = $_GET['sort'] ?? 'highest_pending';

$orderSql = "i.amount_due DESC";
if ($sort === 'oldest_due') {
    $orderSql = "i.due_date ASC";
} elseif ($sort === 'most_overdue') {
    $orderSql = "DATEDIFF(CURDATE(), i.due_date) DESC";
}

$stmt = $pdo->prepare("
    SELECT i.*, c.name AS client_name, c.phone, c.email,
      DATEDIFF(CURDATE(), COALESCE(i.due_date, DATE(i.created_at))) AS days_overdue
    FROM invoices i
    JOIN customers c ON c.id = i.customer_id
    WHERE i.status NOT IN ('paid','cancelled') AND i.amount_due > 0
    ORDER BY $orderSql
");
$stmt->execute();
$rows = $stmt->fetchAll();

$totalOutstanding = array_sum(array_column($rows, 'amount_due'));
?>

<div class="container-fluid">
  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Outstanding Payment Report</h4>
      <p class="text-muted small mb-0">Consolidated list of unpaid & overdue invoices with aging analysis.</p>
    </div>
    <div class="d-flex gap-2">
      <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i> Print Report</button>
    </div>
  </div>

  <!-- Summary Card -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; background: #fff5f5; border-left: 5px solid #dc3545;">
    <div class="card-body p-3 d-flex justify-content-between align-items-center">
      <div>
        <span class="text-muted small d-block">TOTAL RECEIVABLE OUTSTANDING</span>
        <h3 class="fw-bold mb-0 text-danger">₹<?= money($totalOutstanding) ?></h3>
      </div>
      <div>
        <span class="badge bg-danger fs-6"><?= count($rows) ?> Overdue Invoices</span>
      </div>
    </div>
  </div>

  <!-- Sort Bar -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
    <div class="card-body p-3">
      <form method="get" class="row g-2 align-items-center">
        <div class="col-md-4">
          <label class="form-label small text-muted mb-1">Sort Invoices By</label>
          <select name="sort" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="highest_pending" <?= $sort==='highest_pending'?'selected':'' ?>>Highest Pending Amount</option>
            <option value="oldest_due" <?= $sort==='oldest_due'?'selected':'' ?>>Oldest Due Date</option>
            <option value="most_overdue" <?= $sort==='most_overdue'?'selected':'' ?>>Most Days Overdue</option>
          </select>
        </div>
      </form>
    </div>
  </div>

  <!-- Report Table -->
  <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light text-muted small uppercase">
          <tr>
            <th class="ps-3">Client</th>
            <th>Invoice No</th>
            <th>Invoice Date</th>
            <th>Due Date</th>
            <th>Days Overdue</th>
            <th class="text-end">Invoice Total</th>
            <th class="text-end">Paid</th>
            <th class="text-end pe-3">Pending Due</th>
            <th class="text-center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows): ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="ps-3">
                  <a href="../customers/view.php?id=<?= $r['customer_id'] ?>" class="fw-bold text-dark text-decoration-none">
                    <?= e($r['client_name']) ?>
                  </a>
                  <small class="d-block text-muted"><i class="bi bi-telephone me-1"></i><?= e($r['phone'] ?: 'N/A') ?></small>
                </td>
                <td class="fw-bold text-primary"><?= e($r['invoice_no']) ?></td>
                <td><?= e($r['invoice_date'] ?: date('Y-m-d', strtotime($r['created_at']))) ?></td>
                <td class="text-danger fw-semibold"><?= e($r['due_date'] ?: 'N/A') ?></td>
                <td>
                  <?php if ($r['days_overdue'] > 0): ?>
                    <span class="badge bg-danger"><?= $r['days_overdue'] ?> days</span>
                  <?php else: ?>
                    <span class="badge bg-warning text-dark">Due soon</span>
                  <?php endif; ?>
                </td>
                <td class="text-end fw-semibold">₹<?= money($r['total_amount']) ?></td>
                <td class="text-end text-success">₹<?= money($r['amount_paid']) ?></td>
                <td class="text-end pe-3 fw-bold text-danger">₹<?= money($r['amount_due']) ?></td>
                <td class="text-center">
                  <a href="../invoices/record_payment.php?customer_id=<?= $r['customer_id'] ?>&invoice_id=<?= $r['id'] ?>" class="btn btn-sm btn-success">
                    Record Payment
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="9" class="text-center py-5 text-success">
                <i class="bi bi-check-circle fs-1 text-success d-block mb-2"></i>
                Awesome! No outstanding overdue payments.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>
