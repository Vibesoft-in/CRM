<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/ledger_helper.php';
require_login();

// Handle Edit Payment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_payment') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $payment_id     = (int)$_POST['payment_id'];
        $amount         = (float)($_POST['amount'] ?? 0);
        $payment_date   = $_POST['payment_date'] ?: date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'Bank Transfer';
        $transaction_id = trim($_POST['transaction_id'] ?? '');
        $reference      = trim($_POST['reference'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');
        $status         = $_POST['status'] ?? 'Completed';

        if ($payment_id > 0 && $amount > 0) {
            update_client_payment($payment_id, $amount, $payment_date, $payment_method, $transaction_id, $reference, $notes, $status);
            set_flash('Payment details updated successfully and financial metrics recalculated!', 'success');
        }
    }
    header("Location: PaymentsReceived.php");
    exit;
}

// Handle Delete Payment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_payment') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $payment_id = (int)$_POST['payment_id'];
        if ($payment_id > 0) {
            delete_client_payment($payment_id);
            set_flash('Payment deleted successfully. Invoice amounts due and ledger balances restored.', 'info');
        }
    }
    header("Location: PaymentsReceived.php");
    exit;
}

$search = trim($_GET['q'] ?? '');
$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(p.payment_no LIKE ? OR c.name LIKE ? OR p.transaction_id LIKE ? OR p.reference LIKE ?)";
    $term = "%$search%";
    $params = [$term, $term, $term, $term];
}

$whereSql = implode(" AND ", $where);

// Fetch payments with customer info
$stmt = $pdo->prepare("
    SELECT p.*, c.name AS customer_name, c.client_logo
    FROM payments p
    LEFT JOIN customers c ON c.id = p.customer_id
    WHERE $whereSql
    ORDER BY p.payment_date DESC, p.id DESC
");
$stmt->execute($params);
$paymentsList = $stmt->fetchAll();

// Metrics
$totalReceived = 0;
$totalUnallocated = 0;
foreach ($paymentsList as $p) {
    $totalReceived += (float)$p['amount'];
    $totalUnallocated += (float)$p['unallocated_amount'];
}
?>

<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-cash-stack text-success me-2"></i>Payments Received</h4>
      <p class="text-muted small mb-0">View all client payment history, transaction references, edit payment details, or delete incorrect payments.</p>
    </div>
    <div>
      <a href="record_payment.php" class="btn btn-success fw-semibold shadow-sm">
        <i class="bi bi-plus-circle me-1"></i> Record Client Payment
      </a>
    </div>
  </div>

  <?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm mb-4" role="alert" style="border-radius: 12px;">
      <i class="bi bi-check-circle-fill me-2 text-success"></i><?= $flash['message'] ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Summary Cards -->
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card border-0 shadow-sm p-3 rounded-3 bg-white border-start border-4 border-success">
        <small class="text-muted text-uppercase fw-bold" style="font-size: 0.75rem;">Total Payments Count</small>
        <h3 class="fw-bold text-dark mb-0 mt-1"><?= count($paymentsList) ?></h3>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card border-0 shadow-sm p-3 rounded-3 bg-white border-start border-4 border-primary">
        <small class="text-muted text-uppercase fw-bold" style="font-size: 0.75rem;">Total Amount Received</small>
        <h3 class="fw-bold text-success mb-0 mt-1">₹<?= money($totalReceived) ?></h3>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card border-0 shadow-sm p-3 rounded-3 bg-white border-start border-4 border-warning">
        <small class="text-muted text-uppercase fw-bold" style="font-size: 0.75rem;">Total Unallocated Balance</small>
        <h3 class="fw-bold text-warning mb-0 mt-1">₹<?= money($totalUnallocated) ?></h3>
      </div>
    </div>
  </div>

  <!-- Search Filter -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
    <div class="card-body py-3">
      <form method="get" class="row g-2 align-items-center">
        <div class="col-md-8">
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
            <input type="text" name="q" class="form-control" placeholder="Search payment #, client name, transaction ID..." value="<?= e($search) ?>">
          </div>
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-primary w-100"><i class="bi bi-filter me-1"></i> Filter</button>
        </div>
        <?php if ($search): ?>
          <div class="col-md-2">
            <a href="PaymentsReceived.php" class="btn btn-outline-secondary w-100">Reset</a>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Table -->
  <div class="card border-0 shadow-sm" style="border-radius: 12px;">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Payment #</th>
              <th>Date</th>
              <th>Client Name</th>
              <th>Method</th>
              <th>Txn ID / Ref</th>
              <th class="text-end">Amount (₹)</th>
              <th class="text-end">Unallocated (₹)</th>
              <th class="text-center">Status</th>
              <th class="text-end pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($paymentsList as $pay): ?>
              <tr>
                <td class="ps-3 fw-bold text-primary"><?= e($pay['payment_no']) ?></td>
                <td><?= date('d M Y', strtotime($pay['payment_date'])) ?></td>
                <td>
                  <a href="../customers/view.php?id=<?= $pay['customer_id'] ?>&tab=payments" class="text-dark text-decoration-none fw-semibold">
                    <?= e($pay['customer_name'] ?: 'Unknown Client') ?>
                  </a>
                </td>
                <td><span class="badge bg-light text-dark border"><?= e($pay['payment_method'] ?: $pay['payment_mode']) ?></span></td>
                <td><small class="text-muted"><?= e($pay['transaction_id'] ?: ($pay['reference'] ?: '—')) ?></small></td>
                <td class="text-end fw-bold text-success">₹<?= money($pay['amount']) ?></td>
                <td class="text-end text-muted">₹<?= money($pay['unallocated_amount']) ?></td>
                <td class="text-center">
                  <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                    <i class="bi bi-check-circle me-1"></i>Completed
                  </span>
                </td>
                <td class="text-end pe-3">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <li>
                        <a href="../customers/view.php?id=<?= $pay['customer_id'] ?>&tab=payments" class="dropdown-item">
                          <i class="bi bi-eye text-primary me-2"></i> View Client & Payments
                        </a>
                      </li>
                      <li>
                        <button type="button" class="dropdown-item edit-payment-btn"
                                data-payment='<?= json_encode($pay, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                          <i class="bi bi-pencil text-secondary me-2"></i> Edit Payment
                        </button>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <form method="post" onsubmit="return confirm('⚠️ Delete payment <?= e($pay['payment_no']) ?> of ₹<?= money($pay['amount']) ?> permanently? Invoice due amounts and ledger will be restored!');" class="m-0">
                          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                          <input type="hidden" name="action" value="delete_payment">
                          <input type="hidden" name="payment_id" value="<?= $pay['id'] ?>">
                          <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-trash me-2"></i> Delete Payment
                          </button>
                        </form>
                      </li>
                    </ul>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($paymentsList)): ?>
              <tr>
                <td colspan="9" class="text-center py-4 text-muted">No payments recorded yet.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Edit Payment Modal -->
<div class="modal fade" id="editPaymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit_payment">
        <input type="hidden" name="payment_id" id="edit_payment_id">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-cash-coin text-success me-2"></i>Edit Payment Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-medium">Payment Number</label>
            <input type="text" id="edit_payment_no_display" class="form-control bg-light fw-bold" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Amount Received (₹) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" name="amount" id="edit_payment_amount" class="form-control form-control-lg fw-bold text-success" required>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-medium">Payment Date</label>
              <input type="date" name="payment_date" id="edit_payment_date" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Payment Method</label>
              <select name="payment_method" id="edit_payment_method" class="form-select">
                <option value="Bank Transfer">Bank Transfer</option>
                <option value="UPI">UPI</option>
                <option value="Cash">Cash</option>
                <option value="Card">Card</option>
                <option value="Cheque">Cheque</option>
                <option value="Other">Other</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Transaction ID / UTR</label>
            <input type="text" name="transaction_id" id="edit_payment_transaction_id" class="form-control" placeholder="e.g. UTR123456789">
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Payment Reference</label>
            <input type="text" name="reference" id="edit_payment_reference" class="form-control" placeholder="e.g. 50% Advance">
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Payment Notes</label>
            <textarea name="notes" id="edit_payment_notes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success fw-semibold"><i class="bi bi-check-circle me-1"></i> Update Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.edit-payment-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const pay = JSON.parse(this.getAttribute('data-payment'));
    document.getElementById('edit_payment_id').value = pay.id;
    document.getElementById('edit_payment_no_display').value = pay.payment_no || '';
    document.getElementById('edit_payment_amount').value = pay.amount || 0;
    document.getElementById('edit_payment_date').value = pay.payment_date || '';
    document.getElementById('edit_payment_method').value = pay.payment_method || pay.payment_mode || 'Bank Transfer';
    document.getElementById('edit_payment_transaction_id').value = pay.transaction_id || '';
    document.getElementById('edit_payment_reference').value = pay.reference || '';
    document.getElementById('edit_payment_notes').value = pay.notes || '';
    
    const modal = new bootstrap.Modal(document.getElementById('editPaymentModal'));
    modal.show();
  });
});
</script>

<?php include '../../includes/footer.php'; ?>
