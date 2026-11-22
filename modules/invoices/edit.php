<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/ledger_helper.php';
require_login();

if (!isset($_GET['id'])) {
    set_flash("Invoice ID missing.", "danger");
    header("Location: list.php");
    exit;
}
$id = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch();
if (!$invoice) {
    set_flash("Invoice not found.", "danger");
    header("Location: list.php");
    exit;
}

if ($invoice['status'] === 'cancelled') {
    set_flash("Invoice #" . e($invoice['invoice_no']) . " is CANCELLED and disabled. It cannot be edited.", "warning");
    header("Location: view.php?id=" . $id);
    exit;
}

$itemsData = $pdo->query("SELECT id, name, hsn_sac, default_price, default_tax_rate FROM items ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$customers = $pdo->query("SELECT id, name, gstin FROM customers ORDER BY name ASC")->fetchAll();

$invItems = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$invItems->execute([$id]);
$invItems = $invItems->fetchAll();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $customer_id  = intval($_POST['customer_id']);
        $place        = trim($_POST['place_of_supply'] ?? '');
        $date         = $_POST['invoice_date'] ?: date('Y-m-d');
        $due_date     = $_POST['due_date'] ?: date('Y-m-d', strtotime('+10 days'));
        $notes        = trim($_POST['notes'] ?? '');
        $payment_mode = trim($_POST['payment_mode'] ?? 'Pending');
        $items_json   = $_POST['items_json'] ?? '[]';
        $items_arr    = json_decode($items_json, true);

        if (!is_array($items_arr) || count($items_arr) === 0) {
            $err = 'Please add at least 1 item line to the invoice';
        }

        $invoice_type = in_array($_POST['invoice_type'] ?? '', ['gst', 'personal']) ? $_POST['invoice_type'] : ($invoice['invoice_type'] ?? 'gst');

        if (!$err) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE invoices SET invoice_date=?, due_date=?, payment_mode=?, customer_id=?, place_of_supply=?, notes=?, invoice_type=? WHERE id=?");
                $stmt->execute([$date, $due_date, $payment_mode, $customer_id, $place, $notes, $invoice_type, $id]);

                $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$id]);

                $taxable = 0; $tax = 0;
                foreach ($items_arr as $it) {
                    $name         = trim($it['name'] ?? '');
                    $hsn          = trim($it['hsn'] ?? '');
                    $qty          = max(1, (int)($it['qty'] ?? 1));
                    $rate         = (float)($it['rate'] ?? 0);
                    $tax_rate     = (float)($it['tax_rate'] ?? 0);
                    $discount     = (float)($it['discount'] ?? 0);
                    $handler_name = trim($it['handler_name'] ?? '');
                    $is_inc       = !empty($it['is_tax_inclusive']);

                    $lineCalc    = calculate_invoice_line_item($qty, $rate, $tax_rate, $discount, $is_inc);
                    $line_amount = $lineCalc['taxable_amount'];
                    $line_tax    = $lineCalc['tax_amount'];

                    $stmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, hsn_sac, qty, rate, discount, tax_rate, is_tax_inclusive, line_tax, line_total, handler_name) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$id, $name, $hsn, $qty, $rate, $discount, $tax_rate, $is_inc ? 1 : 0, $line_tax, $line_amount, $handler_name]);

                    $taxable += $line_amount;
                    $tax += $line_tax;
                }

                $total = round($taxable + $tax, 2);
                $stmt = $pdo->prepare("UPDATE invoices SET taxable_value=?, tax_total=?, total_amount=? WHERE id=?");
                $stmt->execute([$taxable, $tax, $total, $id]);

                // Recalculate status & sync ledger
                update_invoice_status($id);
                post_invoice_to_ledger($id);
                sync_invoice_to_subregistry($id);

                $pdo->commit();
                set_flash("Invoice " . e($invoice['invoice_no']) . " updated successfully!", "success");
                header("Location: view.php?id={$id}");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $err = "Update failed: " . $e->getMessage();
            }
        }
    }
}
?>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Invoice #<?= e($invoice['invoice_no']) ?></h4>
      <p class="text-muted small mb-0">Update itemized billing details, payment terms, and notes.</p>
    </div>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Invoice</a>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <i class="bi bi-exclamation-triangle me-2"></i><?= e($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <form id="invForm" method="post" class="needs-validation">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="items_json" id="items_json">

    <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px;">
      <div class="card-header bg-white py-3 border-bottom-0">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-info-circle me-2 text-primary"></i>Invoice Header Details</h6>
      </div>
      <div class="card-body">
        <?php $currentInvType = $invoice['invoice_type'] ?? 'gst'; ?>
        <!-- Billing Track & Serial Sequence Selector -->
        <div class="p-3 mb-3 rounded-3 border bg-light-subtle">
          <label class="form-label fw-bold text-dark d-block mb-2">
            <i class="bi bi-diagram-3 me-1 text-primary"></i> Billing Track & Serial Series <span class="text-danger">*</span>
          </label>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="d-flex align-items-center p-3 border rounded-3 bg-white shadow-sm h-100" style="cursor:pointer;" for="type_gst">
                <input class="form-check-input me-3 mt-0" type="radio" name="invoice_type" id="type_gst" value="gst" <?= $currentInvType === 'gst' ? 'checked' : '' ?> style="transform: scale(1.25);">
                <div>
                  <span class="fw-bold text-primary d-block"><i class="bi bi-building-check me-1"></i> Company / GST Tax Invoice (CA Bill)</span>
                  <small class="text-muted d-block">Tracked in official GST table. Continuous gap-free sequence for CA filing.</small>
                </div>
              </label>
            </div>
            <div class="col-md-6">
              <label class="d-flex align-items-center p-3 border rounded-3 bg-white shadow-sm h-100" style="cursor:pointer;" for="type_personal">
                <input class="form-check-input me-3 mt-0" type="radio" name="invoice_type" id="type_personal" value="personal" <?= $currentInvType === 'personal' ? 'checked' : '' ?> style="transform: scale(1.25);">
                <div>
                  <span class="fw-bold text-warning d-block"><i class="bi bi-person-badge me-1"></i> Personal Account Bill (Non-GST)</span>
                  <small class="text-muted d-block">For personal payment clients. Managed in separate table & series so company series has zero gaps.</small>
                </div>
              </label>
            </div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label fw-medium">Customer / Client <span class="text-danger">*</span></label>
            <select name="customer_id" class="form-select" required>
              <option value="">-- Select Client --</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= (int)$c['id'] === (int)$invoice['customer_id'] ? 'selected' : '' ?>>
                  <?= e($c['name']) ?> <?= $c['gstin'] ? '('.e($c['gstin']).')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label fw-medium">Invoice Date</label>
            <input type="date" name="invoice_date" class="form-control" value="<?= e($invoice['invoice_date']) ?>" required>
          </div>

          <div class="col-md-2">
            <label class="form-label fw-medium">Due Date</label>
            <input type="date" name="due_date" class="form-control" value="<?= e($invoice['due_date'] ?: date('Y-m-d', strtotime($invoice['invoice_date'] . ' +10 days'))) ?>">
          </div>

          <div class="col-md-2">
            <label class="form-label fw-medium">Place of Supply</label>
            <input type="text" name="place_of_supply" class="form-control" value="<?= e($invoice['place_of_supply']) ?>">
          </div>

          <div class="col-md-2">
            <label class="form-label fw-medium">Payment Mode</label>
            <select name="payment_mode" class="form-select">
              <?php 
                $modes = ["Pending", "Bank Transfer", "UPI", "Credit Card", "Cheque", "Cash"];
                foreach ($modes as $m) {
                  $sel = ($invoice['payment_mode'] == $m) ? "selected" : "";
                  echo "<option value='$m' $sel>$m</option>";
                }
              ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- Items Section -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px;">
      <div class="card-header bg-white py-3 border-bottom-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-list-task me-2 text-primary"></i>Line Items & Services</h6>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addRow"><i class="bi bi-plus-lg me-1"></i> Add Row</button>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" id="itemsTable">
            <thead class="bg-light text-muted small uppercase">
              <tr>
                <th class="ps-3" style="width: 25%;">Item / Service Name</th>
                <th style="width: 12%;">HSN/SAC</th>
                <th style="width: 10%;">Qty</th>
                <th style="width: 12%;">Rate (₹)</th>
                <th style="width: 10%;">Tax %</th>
                <th style="width: 10%;">Disc %</th>
                <th style="width: 15%;">Handler Name</th>
                <th class="text-end pe-3" style="width: 6%;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($invItems as $it): ?>
                <tr>
                  <td class="ps-3">
                    <select class="form-select form-select-sm item-select">
                      <option value=""><?= e($it['name']) ?></option>
                      <?php foreach ($itemsData as $d): ?>
                        <option value="<?= $d['id'] ?>" data-name="<?= e($d['name']) ?>" data-hsn="<?= e($d['hsn_sac']) ?>" data-price="<?= e($d['default_price']) ?>" data-tax="<?= e($d['default_tax_rate']) ?>"
                          <?= strtolower($d['name']) == strtolower($it['name']) ? 'selected' : '' ?>>
                          <?= e($d['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control form-control-sm mt-1 item-name" value="<?= e($it['name']) ?>">
                  </td>
                  <td><input type="text" class="form-control form-control-sm item-hsn" value="<?= e($it['hsn_sac']) ?>"></td>
                  <td><input type="number" min="1" step="1" class="form-control form-control-sm item-qty" value="<?= (int)round($it['qty'] ?? 1) ?>"></td>
                  <td><input type="number" min="0" step="0.01" class="form-control form-control-sm item-rate" value="<?= e($it['rate']) ?>"></td>
                  <td>
                    <input type="number" min="0" step="0.01" class="form-control form-control-sm item-tax" value="<?= e($it['tax_rate']) ?>">
                    <div class="form-check form-switch mt-1" title="Toggle ON if entered rate already includes GST">
                      <input class="form-check-input item-tax-inc" type="checkbox" role="switch" <?= !empty($it['is_tax_inclusive']) ? 'checked' : '' ?>>
                      <label class="form-check-label text-muted" style="font-size:0.68rem; line-height:1.2; display:block;">Tax Included</label>
                    </div>
                    <div class="tax-breakdown-hint text-primary mt-1" style="font-size:0.68rem; line-height:1.2; display:none;"></div>
                  </td>
                  <td><input type="number" min="0" step="0.01" class="form-control form-control-sm item-discount" value="<?= e($it['discount']) ?>"></td>
                  <td><input type="text" class="form-control form-control-sm item-handler" value="<?= e($it['handler_name']) ?>"></td>
                  <td class="text-end pe-3"><button type="button" class="btn btn-sm btn-outline-danger removeRow"><i class="bi bi-trash"></i></button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="row g-4 mb-4">
      <div class="col-lg-7">
        <div class="card border-0 shadow-sm" style="border-radius: 14px;">
          <div class="card-header bg-white py-3 border-bottom-0">
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-sticky me-2 text-primary"></i>Invoice Notes</h6>
          </div>
          <div class="card-body">
            <textarea name="notes" class="form-control" rows="3"><?= e($invoice['notes']) ?></textarea>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card border-0 shadow-sm p-4" style="border-radius: 14px; background: #fafbfc;">
          <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Grand Total Summary</h6>
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Taxable Subtotal:</span>
            <span class="fw-semibold text-dark" id="dispTaxable">₹<?= money($invoice['taxable_value']) ?></span>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Total Tax Amount:</span>
            <span class="fw-semibold text-dark" id="dispTax">₹<?= money($invoice['tax_total']) ?></span>
          </div>
          <hr class="my-2">
          <div class="d-flex justify-content-between fs-5 fw-bold">
            <span class="text-dark">Net Amount Payable:</span>
            <span class="text-primary" id="dispTotal">₹<?= money($invoice['total_amount']) ?></span>
          </div>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary btn-lg shadow-sm fw-semibold">
        <i class="bi bi-check-circle me-1"></i> Update Invoice
      </button>
      <a class="btn btn-light btn-lg border" href="view.php?id=<?= $id ?>">Cancel</a>
    </div>
  </form>
</div>

<script>
const itemsCatalog = <?= json_encode($itemsData) ?>;

(function(){
  const itemsTable = document.querySelector('#itemsTable tbody');
  const addRowBtn = document.querySelector('#addRow');

  function rowHtml(name = '', qty = 1, rate = 0, isTaxInc = false) {
    return `<tr>
      <td class="ps-3">
        <select class="form-select form-select-sm item-select">
          <option value="">-- Select --</option>
          ${itemsCatalog.map(it => 
            `<option value="${it.id}" data-name="${it.name}" data-hsn="${it.hsn_sac}" data-price="${it.default_price}" data-tax="${it.default_tax_rate}">
              ${it.name}
            </option>`).join('')}
        </select>
        <input type="text" class="form-control form-control-sm mt-1 item-name" placeholder="Item name" value="${name}">
      </td>
      <td><input type="text" class="form-control form-control-sm item-hsn" placeholder="998314"></td>
      <td><input type="number" min="1" step="1" class="form-control form-control-sm item-qty" value="${qty}"></td>
      <td><input type="number" min="0" step="0.01" class="form-control form-control-sm item-rate" value="${rate}"></td>
      <td>
        <input type="number" min="0" step="0.01" class="form-control form-control-sm item-tax" value="18">
        <div class="form-check form-switch mt-1" title="Toggle ON if entered rate already includes GST">
          <input class="form-check-input item-tax-inc" type="checkbox" role="switch" ${isTaxInc ? 'checked' : ''}>
          <label class="form-check-label text-muted" style="font-size:0.68rem; line-height:1.2; display:block;">Tax Included</label>
        </div>
        <div class="tax-breakdown-hint text-primary mt-1" style="font-size:0.68rem; line-height:1.2; display:none;"></div>
      </td>
      <td><input type="number" min="0" step="0.01" class="form-control form-control-sm item-discount" value="0"></td>
      <td><input type="text" class="form-control form-control-sm item-handler" placeholder="Assignee/Handler"></td>
      <td class="text-end pe-3"><button type="button" class="btn btn-sm btn-outline-danger removeRow"><i class="bi bi-trash"></i></button></td>
    </tr>`;
  }

  function recalcTotals() {
    let totalTaxable = 0;
    let totalTax = 0;

    document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
      const qty = parseInt(tr.querySelector('.item-qty').value, 10) || 1;
      const rate = parseFloat(tr.querySelector('.item-rate').value) || 0;
      const taxRate = parseFloat(tr.querySelector('.item-tax').value) || 0;
      const discount = parseFloat(tr.querySelector('.item-discount').value) || 0;
      const isTaxInc = tr.querySelector('.item-tax-inc') ? tr.querySelector('.item-tax-inc').checked : false;
      const hintEl = tr.querySelector('.tax-breakdown-hint');

      let taxableAmount = 0;
      let taxAmount = 0;

      if (isTaxInc) {
        let grossInc = qty * rate;
        let discAmt = grossInc * (discount / 100);
        let netInc = Math.max(0, grossInc - discAmt);

        if (taxRate > 0) {
          taxableAmount = Math.round(((netInc * 100) / (100 + taxRate)) * 100) / 100;
          taxAmount = Math.round((netInc - taxableAmount) * 100) / 100;
        } else {
          taxableAmount = netInc;
          taxAmount = 0;
        }

        if (hintEl) {
          hintEl.style.display = 'block';
          hintEl.innerHTML = `Base: ₹${taxableAmount.toFixed(2)}<br>GST: ₹${taxAmount.toFixed(2)}`;
        }
      } else {
        let grossBase = qty * rate;
        let discAmt = grossBase * (discount / 100);
        let netBase = Math.max(0, grossBase - discAmt);

        taxableAmount = netBase;
        taxAmount = Math.round((taxableAmount * (taxRate / 100)) * 100) / 100;

        if (hintEl) {
          hintEl.style.display = 'none';
          hintEl.innerHTML = '';
        }
      }

      totalTaxable += taxableAmount;
      totalTax += taxAmount;
    });

    totalTaxable = Math.round(totalTaxable * 100) / 100;
    totalTax = Math.round(totalTax * 100) / 100;
    const grandTotal = Math.round((totalTaxable + totalTax) * 100) / 100;

    document.getElementById('dispTaxable').innerText = '₹' + totalTaxable.toFixed(2);
    document.getElementById('dispTax').innerText = '₹' + totalTax.toFixed(2);
    document.getElementById('dispTotal').innerText = '₹' + grandTotal.toFixed(2);
  }

  recalcTotals();

  addRowBtn.addEventListener('click', () => {
    itemsTable.insertAdjacentHTML('beforeend', rowHtml());
    recalcTotals();
  });

  itemsTable.addEventListener('change', function(e){
    if (e.target && e.target.classList.contains('item-select')) {
      const opt = e.target.options[e.target.selectedIndex];
      const tr = e.target.closest('tr');
      if (opt.value) {
        tr.querySelector('.item-name').value = opt.dataset.name || opt.text.trim();
        tr.querySelector('.item-hsn').value = opt.dataset.hsn || '';
        tr.querySelector('.item-rate').value = opt.dataset.price || 0;
        tr.querySelector('.item-tax').value = opt.dataset.tax || 18;
      }
    }
    recalcTotals();
  });

  itemsTable.addEventListener('input', recalcTotals);

  itemsTable.addEventListener('click', function(e){
    if (e.target && (e.target.classList.contains('removeRow') || e.target.closest('.removeRow'))) {
      const tr = e.target.closest('tr');
      tr.remove();
      recalcTotals();
    }
  });

  document.querySelector('#invForm').addEventListener('submit', function(e){
    e.preventDefault();
    const arr = [];
    document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
      const name = tr.querySelector('.item-name').value.trim();
      if (!name) return;
      arr.push({
        name: name,
        hsn: tr.querySelector('.item-hsn').value.trim(),
        qty: parseInt(tr.querySelector('.item-qty').value, 10) || 1,
        rate: parseFloat(tr.querySelector('.item-rate').value) || 0,
        tax_rate: parseFloat(tr.querySelector('.item-tax').value) || 0,
        discount: parseFloat(tr.querySelector('.item-discount').value) || 0,
        is_tax_inclusive: tr.querySelector('.item-tax-inc') ? (tr.querySelector('.item-tax-inc').checked ? 1 : 0) : 0,
        handler_name: tr.querySelector('.item-handler').value.trim() || ''
      });
    });

    if (arr.length === 0) {
      alert('Please enter at least one valid item line.');
      return;
    }

    document.getElementById('items_json').value = JSON.stringify(arr);
    this.submit();
  });
})();
</script>

<?php include '../../includes/footer.php'; ?>
