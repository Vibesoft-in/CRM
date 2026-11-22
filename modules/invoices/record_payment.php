<?php
require_once '../../includes/functions.php';
require_once '../../includes/ledger_helper.php';
require_login();

// AJAX endpoint — return unpaid invoices as JSON (Must run BEFORE layout.php to avoid HTML output corruption)
if (isset($_GET['ajax_invoices'])) {
    header('Content-Type: application/json');
    $cid = (int)($_GET['customer_id'] ?? 0);
    if ($cid <= 0) { echo json_encode([]); exit; }
    $s = $pdo->prepare("SELECT id, invoice_no, total_amount, amount_paid, amount_due, invoice_date FROM invoices WHERE customer_id = ? AND status NOT IN ('paid','cancelled') ORDER BY invoice_date ASC");
    $s->execute([$cid]);
    echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

require_once '../../includes/layout.php';

$customer_id    = (int)($_GET['customer_id'] ?? 0);
$invoice_id     = (int)($_GET['invoice_id'] ?? 0);
$amount         = '';
$payment_date   = date('Y-m-d');
$payment_method = 'Bank Transfer';
$transaction_id = '';
$reference      = '';
$notes          = '';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id    = (int)($_POST['customer_id'] ?? 0);
    $amount         = (float)($_POST['amount'] ?? 0);
    $payment_date   = !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?? 'Bank Transfer';
    $transaction_id = trim($_POST['transaction_id'] ?? '');
    $reference      = trim($_POST['reference'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');

    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $allocations = [];
        if (!empty($_POST['invoice_alloc_id'])) {
            foreach ($_POST['invoice_alloc_id'] as $idx => $invId) {
                $allocAmt = (float)($_POST['invoice_alloc_amount'][$idx] ?? 0);
                if ($invId > 0 && $allocAmt > 0) {
                    $allocations[] = [
                        'invoice_id' => (int)$invId,
                        'amount'     => $allocAmt
                    ];
                }
            }
        }
        
        if ($customer_id <= 0 || $amount <= 0) {
            $err = 'Client and positive Payment Amount are required.';
        } else {
            try {
                $payId = record_client_payment($customer_id, $amount, $payment_date, $payment_method, $transaction_id, $reference, $notes, $allocations);
                set_flash('Payment recorded successfully and ledger updated!', 'success');
                header('Location: ' . BASE_URL . 'modules/customers/view.php?id=' . $customer_id . '&tab=payments');
                exit;
            } catch (Exception $e) {
                $err = 'Payment Error: ' . $e->getMessage();
            }
        }
    }
}

$customers = $pdo->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name ASC")->fetchAll();
?>

<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-cash-coin text-success me-2"></i>Record Client Payment</h4>
      <p class="text-muted small mb-0">Record full or partial payments, allocate against unpaid invoices, and update ledger balance.</p>
    </div>
    <a href="PaymentsReceived.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Payments</a>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="bi bi-exclamation-triangle me-2"></i><?= e($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <form method="post" class="needs-validation">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="row g-4">
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
          <div class="card-header bg-white py-3">
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-building me-2 text-primary"></i>Payment Details</h6>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label fw-medium">Select Client <span class="text-danger">*</span></label>
              <select name="customer_id" id="customerSelect" class="form-select" required>
                <option value="">-- Select Client --</option>
                <?php foreach ($customers as $c): ?>
                  <option value="<?= $c['id'] ?>" <?= $customer_id == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Amount Received (₹) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="amount" id="totalPaymentInput" class="form-control form-control-lg fw-bold text-success" placeholder="0.00" value="<?= e($amount) ?>" required>
            </div>

            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label fw-medium">Payment Date</label>
                <input type="date" name="payment_date" class="form-control" value="<?= e($payment_date) ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label fw-medium">Payment Method</label>
                <select name="payment_method" class="form-select">
                  <?php 
                    $methods = ['Bank Transfer', 'UPI', 'Cash', 'Card', 'Cheque', 'Other'];
                    foreach ($methods as $m):
                  ?>
                    <option value="<?= $m ?>" <?= $payment_method === $m ? 'selected' : '' ?>><?= $m ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Transaction ID / UTR</label>
              <input type="text" name="transaction_id" class="form-control" placeholder="e.g. UTR123456789" value="<?= e($transaction_id) ?>">
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Payment Reference / Notes</label>
              <textarea name="notes" class="form-control" rows="2" placeholder="Add payment notes..."><?= e($notes) ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- Right: Invoice Allocation Matrix -->
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
          <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-list-check me-2 text-primary"></i>Allocate to Open Invoices</h6>
            <span class="badge bg-light text-secondary border">Auto-Allocation</span>
          </div>
          <div class="card-body" id="allocContainer">
            <p class="text-center text-muted py-3">
              <?= $customer_id > 0 ? '<span class="spinner-border spinner-border-sm me-2"></span>Loading invoices...' : 'Select a client to view open invoices.' ?>
            </p>
          </div>
        </div>

        <div class="d-grid gap-2">
          <button type="submit" class="btn btn-success btn-lg shadow-sm fw-semibold">
            <i class="bi bi-check-circle me-1"></i> Record & Post Payment
          </button>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
const customerSelect   = document.getElementById('customerSelect');
const allocContainer   = document.getElementById('allocContainer');
const totalInput       = document.getElementById('totalPaymentInput');
const targetInvoiceId  = <?= (int)$invoice_id ?>;

function loadInvoices(cid) {
  if (!cid) {
    allocContainer.innerHTML = '<p class="text-center text-muted py-3">Select a client to view open invoices.</p>';
    return;
  }
  allocContainer.innerHTML = '<p class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm me-2"></span>Loading open invoices...</p>';
  
  fetch('record_payment.php?ajax_invoices=1&customer_id=' + cid)
    .then(r => {
      if (!r.ok) throw new Error('HTTP error ' + r.status);
      return r.json();
    })
    .then(invoices => {
      if (!Array.isArray(invoices) || !invoices.length) {
        allocContainer.innerHTML = '<div class="p-4 text-center text-muted"><i class="bi bi-check-circle fs-2 text-success d-block mb-2"></i>No pending invoices. Payment will be saved as unallocated customer credit balance.</div>';
        return;
      }
      let html = `<p class="text-muted small mb-2">Specify allocation per invoice. Auto-fills when you enter total amount.</p>
        <table class="table table-bordered align-middle mb-2">
          <thead class="table-light small"><tr><th>Invoice</th><th>Total</th><th>Due</th><th>Allocate (₹)</th></tr></thead><tbody>`;
      invoices.forEach(inv => {
        html += `<tr>
          <td class="fw-bold">${inv.invoice_no}<input type="hidden" name="invoice_alloc_id[]" value="${inv.id}"></td>
          <td>₹${parseFloat(inv.total_amount).toLocaleString('en-IN', {minimumFractionDigits:2})}</td>
          <td class="text-danger fw-bold">₹${parseFloat(inv.amount_due).toLocaleString('en-IN', {minimumFractionDigits:2})}</td>
          <td><input type="number" step="0.01" name="invoice_alloc_amount[]" class="form-control form-control-sm alloc-input" value="0.00" max="${inv.amount_due}" data-max="${inv.amount_due}" data-invoice-id="${inv.id}"></td>
        </tr>`;
      });
      html += `</tbody></table>
        <div class="d-flex justify-content-between small fw-bold">
          <span class="text-muted">Allocated: <span id="allocTotal" class="text-primary">₹0.00</span></span>
          <span class="text-muted">Remaining Balance: <span id="allocRemaining" class="text-danger">₹0.00</span></span>
        </div>`;
      allocContainer.innerHTML = html;
      bindAllocInputs();

      // If specific target invoice was passed in URL
      if (targetInvoiceId > 0) {
        const targetInv = invoices.find(i => i.id == targetInvoiceId);
        if (targetInv && (!totalInput.value || parseFloat(totalInput.value) === 0)) {
          totalInput.value = parseFloat(targetInv.amount_due).toFixed(2);
        }
      }

      if (totalInput?.value && parseFloat(totalInput.value) > 0) {
        autoAllocate(parseFloat(totalInput.value));
      }
    })
    .catch(err => {
      console.error('Failed to load invoices:', err);
      allocContainer.innerHTML = '<div class="p-3 text-center text-danger"><i class="bi bi-exclamation-triangle me-1"></i> Failed to load invoices. Please try again.</div>';
    });
}

function bindAllocInputs() {
  document.querySelectorAll('.alloc-input').forEach(inp => {
    inp.addEventListener('input', updateAllocSummary);
  });
}

function autoAllocate(totalAmt) {
  let rem = totalAmt || 0;
  document.querySelectorAll('.alloc-input').forEach(input => {
    const maxDue = parseFloat(input.getAttribute('data-max') || input.getAttribute('max')) || 0;
    if (rem > 0) {
      const alloc = Math.min(rem, maxDue);
      input.value = alloc.toFixed(2);
      rem -= alloc;
    } else {
      input.value = '0.00';
    }
  });
  updateAllocSummary();
}

function updateAllocSummary() {
  const total = parseFloat(totalInput?.value) || 0;
  let allocated = 0;
  document.querySelectorAll('.alloc-input').forEach(inp => allocated += parseFloat(inp.value) || 0);
  const allocTotalEl = document.getElementById('allocTotal');
  const allocRemainingEl = document.getElementById('allocRemaining');
  if (allocTotalEl) allocTotalEl.textContent = '₹' + allocated.toFixed(2);
  if (allocRemainingEl) allocRemainingEl.textContent = '₹' + Math.max(0, total - allocated).toFixed(2);
}

customerSelect?.addEventListener('change', () => loadInvoices(customerSelect.value));

totalInput?.addEventListener('input', function() {
  autoAllocate(parseFloat(this.value) || 0);
});

// Initial load if customer_id is selected
if (customerSelect?.value) {
  loadInvoices(customerSelect.value);
}
</script>

<?php include '../../includes/footer.php'; ?>
