<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/ledger_helper.php';
require_login();

$customers = $pdo->query("SELECT id, name, gstin, state, state_code, city, pincode, billing_address FROM customers WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$items = $pdo->query("SELECT id, name, hsn_sac, default_price, default_tax_rate FROM items ORDER BY name ASC")->fetchAll();
$businessGst = get_business_gst_state_details();

$allCustomerServices = $pdo->query("SELECT customer_id, service_name, billing_type, amount FROM customer_services WHERE status = 'active'")->fetchAll();
$customerServicesMap = [];
foreach ($allCustomerServices as $cs) {
    $customerServicesMap[$cs['customer_id']][] = $cs;
}

$preselectedCustomerId = intval($_GET['customer_id'] ?? 0);
$extraWorkItem = null;
if (!empty($_GET['extra_work_id'])) {
    $ewId = intval($_GET['extra_work_id']);
    $stmtEw = $pdo->prepare("SELECT * FROM extra_work WHERE id = ?");
    $stmtEw->execute([$ewId]);
    $extraWorkItem = $stmtEw->fetch();
    if ($extraWorkItem) {
        $preselectedCustomerId = intval($extraWorkItem['customer_id']);
    }
}

$projectItem = null;
if (!empty($_GET['project_id'])) {
    $pId = intval($_GET['project_id']);
    $stmtP = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
    $stmtP->execute([$pId]);
    $projectItem = $stmtP->fetch();
    if ($projectItem) {
        $preselectedCustomerId = intval($projectItem['customer_id']);
    }
}

$preselectedServices = [];
if ($preselectedCustomerId > 0 && !$extraWorkItem && !$projectItem) {
    $preselectedServices = $customerServicesMap[$preselectedCustomerId] ?? [];
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $customer_id = intval($_POST['customer_id']);
        $place = trim($_POST['place_of_supply'] ?? '');
        $date = $_POST['invoice_date'] ?: date('Y-m-d');
        $due_date = $_POST['due_date'] ?: date('Y-m-d', strtotime('+10 days'));
        $notes = trim($_POST['notes'] ?? '');
        $items_json = $_POST['items_json'] ?? '[]';
        $payment_mode = trim($_POST['payment_mode'] ?? 'Pending');
        $items_arr = json_decode($items_json, true);

        // Fetch selected customer for validation
        $cStmt = $pdo->prepare("SELECT id, name, gstin, state, state_code, billing_address FROM customers WHERE id = ?");
        $cStmt->execute([$customer_id]);
        $cust = $cStmt->fetch(PDO::FETCH_ASSOC);

        if ($customer_id <= 0 || !$cust) {
            $err = 'Please select a valid customer.';
        } elseif (!is_array($items_arr) || count($items_arr) === 0) {
            $err = 'Please add at least 1 item line to the invoice';
        } else {
            $invoice_type = ($_POST['invoice_type'] ?? 'gst') === 'personal' ? 'personal' : 'gst';
            $custGstin = trim($cust['gstin'] ?? '');

            // Derive place of supply
            if ($place === '') {
                $place = normalize_gst_state_code($cust['state_code'] ?: $cust['state']);
            } else {
                $place = normalize_gst_state_code($place) ?: $place;
            }

            // If GST invoice and client has GSTIN, billing state / place of supply is strictly required!
            if ($invoice_type === 'gst' && !empty($custGstin)) {
                if (empty($place)) {
                    $err = 'Selected client does not have a registered billing State. Please update the client billing address or enter a valid Place of Supply (State Code) before generating a GST invoice.';
                }
            }
        }

        if (!$err) {
            $pdo->beginTransaction();
            try {
                $invGen = generate_next_invoice_number($invoice_type, $date);
                $invoice_no = $invGen['invoice_no'];
                $serial_no = $invGen['serial_no'];

                $stmt = $pdo->prepare("INSERT INTO invoices 
                  (invoice_no, invoice_date, due_date, payment_mode, customer_id, place_of_supply, notes, status, invoice_type, serial_no, created_by, created_at) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, 'sent', ?, ?, ?, NOW())");
                $stmt->execute([$invoice_no, $date, $due_date, $payment_mode, $customer_id, $place, $notes, $invoice_type, $serial_no, $_SESSION['user_id']]);
                $invoice_id = $pdo->lastInsertId();

                $gstType = determine_gst_type($place, $cust['gstin'] ?? '', $invoice_type);
                $applyGst = ($gstType === 'INTRA' || $gstType === 'INTER');

                $taxable = 0;
                $tax = 0;
                foreach ($items_arr as $it) {
                    $name         = trim($it['name'] ?? '');
                    $hsn          = trim($it['hsn'] ?? '');
                    $qty          = max(1, (int)($it['qty'] ?? 1));
                    $rate         = (float)($it['rate'] ?? 0);
                    $tax_rate     = $applyGst ? (float)($it['tax_rate'] ?? 0) : 0.0;
                    $discount     = (float)($it['discount'] ?? 0);
                    $handler_name = trim($it['handler_name'] ?? '');
                    $is_inc       = !empty($it['is_tax_inclusive']);

                    $lineCalc    = calculate_invoice_line_item($qty, $rate, $tax_rate, $discount, $is_inc);
                    $line_amount = $lineCalc['taxable_amount'];
                    $line_tax    = $lineCalc['tax_amount'];

                    $stmt = $pdo->prepare("INSERT INTO invoice_items 
                      (invoice_id, name, hsn_sac, qty, rate, tax_rate, is_tax_inclusive, discount, line_tax, line_total, handler_name) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$invoice_id, $name, $hsn, $qty, $rate, $tax_rate, $is_inc ? 1 : 0, $discount, $line_tax, $line_amount, $handler_name]);

                    $taxable += $line_amount;
                    $tax += $line_tax;
                }

                $taxable = round($taxable, 2);
                $tax = round($tax, 2);
                $total = round($taxable + $tax, 2);
                $stmt = $pdo->prepare("UPDATE invoices SET taxable_value = ?, tax_total = ?, total_amount = ?, amount_due = ? WHERE id = ?");
                $stmt->execute([$taxable, $tax, $total, $total, $invoice_id]);

                // Post entry into Client Ledger
                post_invoice_to_ledger($invoice_id);

                // Sync to dedicated GST or Personal registry table (with accurate CGST/SGST/IGST)
                sync_invoice_to_subregistry($invoice_id);

                if (!empty($_POST['extra_work_id'])) {
                    $ewId = intval($_POST['extra_work_id']);
                    $pdo->prepare("UPDATE extra_work SET status = 'billed', invoice_id = ? WHERE id = ?")->execute([$invoice_id, $ewId]);
                }

                $pdo->commit();
                set_flash("Invoice " . e($invoice_no) . " created successfully!", "success");
                header('Location: ' . BASE_URL . "modules/invoices/view.php?id={$invoice_id}");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $err = "Failed to save invoice: " . $e->getMessage();
            }
        }
    }
}
?>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-file-earmark-plus text-primary me-2"></i>Create New Invoice</h4>
      <p class="text-muted small mb-0">Generate itemized billing statement with automated tax calculations and client ledger sync.</p>
    </div>
    <a href="list.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Invoices</a>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <i class="bi bi-exclamation-triangle me-2"></i><?= e($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <form method="post" id="invForm">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="items_json" id="items_json">
    <input type="hidden" name="extra_work_id" value="<?= e($_GET['extra_work_id'] ?? '') ?>">

    <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px;">
      <div class="card-header bg-white py-3 border-bottom-0">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-person me-2 text-primary"></i>Invoice Header Details</h6>
      </div>
      <div class="card-body">
        <!-- Billing Track & Serial Sequence Selector -->
        <div class="p-3 mb-3 rounded-3 border bg-light-subtle">
          <label class="form-label fw-bold text-dark d-block mb-2">
            <i class="bi bi-diagram-3 me-1 text-primary"></i> Billing Track & Serial Series <span class="text-danger">*</span>
          </label>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="d-flex align-items-center p-3 border rounded-3 bg-white shadow-sm h-100" style="cursor:pointer;" for="type_gst">
                <input class="form-check-input me-3 mt-0" type="radio" name="invoice_type" id="type_gst" value="gst" checked style="transform: scale(1.25);">
                <div>
                  <span class="fw-bold text-primary d-block"><i class="bi bi-building-check me-1"></i> Company / GST Tax Invoice (CA Bill)</span>
                  <small class="text-muted d-block">Tracked in official GST table. Continuous gap-free sequence (VS-<?= get_financial_year() ?>-<?= date('m') ?>-XXX) for CA filing.</small>
                </div>
              </label>
            </div>
            <div class="col-md-6">
              <label class="d-flex align-items-center p-3 border rounded-3 bg-white shadow-sm h-100" style="cursor:pointer;" for="type_personal">
                <input class="form-check-input me-3 mt-0" type="radio" name="invoice_type" id="type_personal" value="personal" style="transform: scale(1.25);">
                <div>
                  <span class="fw-bold text-warning d-block"><i class="bi bi-person-badge me-1"></i> Personal Account Bill (Non-GST)</span>
                  <small class="text-muted d-block">For personal payment clients. Managed in separate table & series (VSP-<?= get_financial_year() ?>-<?= date('m') ?>-XXX) so company series has zero gaps.</small>
                </div>
              </label>
            </div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label fw-medium">Customer / Client <span class="text-danger">*</span></label>
            <select name="customer_id" id="customer_id" class="form-select" required>
              <option value="">-- Select Client --</option>
              <?php foreach ($customers as $c): ?>
                <?php
                  $cStateCode = normalize_gst_state_code($c['state_code'] ?: $c['state']);
                ?>
                <option value="<?= e($c['id']) ?>" 
                        data-gstin="<?= e($c['gstin'] ?? '') ?>" 
                        data-state="<?= e($c['state'] ?? '') ?>" 
                        data-state-code="<?= e($cStateCode) ?>" 
                        <?= $preselectedCustomerId === (int)$c['id'] ? 'selected' : '' ?>>
                  <?= e($c['name']) ?> <?= $c['gstin'] ? '('.e($c['gstin']).')' : '(No GSTIN)' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div id="clientGstBadge" class="mt-2"></div>
          </div>

          <div class="col-md-2">
            <label class="form-label fw-medium">Invoice Date</label>
            <input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>

          <div class="col-md-2">
            <label class="form-label fw-medium">Due Date</label>
            <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+10 days')) ?>">
          </div>

          <div class="col-md-2">
            <label class="form-label fw-medium">Place of Supply (State Code)</label>
            <input type="text" name="place_of_supply" id="place_of_supply" class="form-control" placeholder="e.g. 05" maxlength="2">
            <small class="text-muted" style="font-size:0.72rem;">05 for UK, 09 for UP, etc.</small>
          </div>

          <div class="col-md-2">
            <label class="form-label fw-medium">Payment Mode</label>
            <select name="payment_mode" class="form-select">
              <option value="Pending" selected>Pending</option>
              <option value="Bank Transfer">Bank Transfer</option>
              <option value="UPI">UPI</option>
              <option value="Credit Card">Credit Card</option>
              <option value="Cheque">Cheque</option>
              <option value="Cash">Cash</option>
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
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="row g-4 mb-4">
      <div class="col-lg-7">
        <div class="card border-0 shadow-sm" style="border-radius: 14px;">
          <div class="card-header bg-white py-3 border-bottom-0">
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-sticky me-2 text-primary"></i>Invoice Notes & Terms</h6>
          </div>
          <div class="card-body">
            <textarea name="notes" class="form-control" rows="3" placeholder="Include bank details, payment instructions, or special notes..."></textarea>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card border-0 shadow-sm p-4" style="border-radius: 14px; background: #fafbfc;">
          <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Grand Total Summary</h6>
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Taxable Subtotal:</span>
            <span class="fw-semibold text-dark" id="dispTaxable">₹0.00</span>
          </div>
          <div id="dispGstBreakdown">
            <!-- Dynamically populated: CGST + SGST OR IGST OR No GST -->
          </div>
          <div class="d-flex justify-content-between mb-2" id="dispTaxTotalRow">
            <span class="text-muted">Total Tax Amount:</span>
            <span class="fw-semibold text-dark" id="dispTax">₹0.00</span>
          </div>
          <hr class="my-2">
          <div class="d-flex justify-content-between fs-5 fw-bold">
            <span class="text-dark">Net Amount Payable:</span>
            <span class="text-primary" id="dispTotal">₹0.00</span>
          </div>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary btn-lg shadow-sm fw-semibold" id="saveBtn">
        <i class="bi bi-check-circle me-1"></i> Save & Generate Invoice
      </button>
      <a class="btn btn-light btn-lg border" href="list.php">Cancel</a>
    </div>
  </form>
</div>

<script>
const itemsCatalog = <?= json_encode($items) ?>;
const customerServicesMap = <?= json_encode($customerServicesMap) ?>;
const businessGst = <?= json_encode($businessGst) ?>;

(function(){
  const itemsTable = document.querySelector('#itemsTable tbody');
  const addRowBtn = document.querySelector('#addRow');
  const customerSelect = document.querySelector('#customer_id');
  const placeInput = document.querySelector('#place_of_supply');
  const typeRadios = document.querySelectorAll('input[name="invoice_type"]');

  function getGstCalculationMode() {
    const invType = document.querySelector('input[name="invoice_type"]:checked') ? document.querySelector('input[name="invoice_type"]:checked').value : 'gst';
    if (invType === 'personal') return 'NONE';

    const opt = customerSelect && customerSelect.selectedIndex >= 0 ? customerSelect.options[customerSelect.selectedIndex] : null;
    const gstin = opt ? (opt.dataset.gstin || '').trim() : '';
    if (!gstin) return 'NONE';

    let placeCode = (placeInput ? placeInput.value.trim() : '');
    if (!placeCode && opt && opt.dataset.stateCode) {
      placeCode = opt.dataset.stateCode.trim();
    }
    // Pad single digit
    if (placeCode.length === 1 && placeCode >= '1' && placeCode <= '9') {
      placeCode = '0' + placeCode;
    }

    if (!placeCode) return 'UNKNOWN';

    const bizCode = (businessGst.state_code || '05').trim();
    return (placeCode === bizCode) ? 'INTRA' : 'INTER';
  }

  function rowHtml(name = '', qty = 1, rate = 0, isTaxInc = false) {
    return `<tr>
      <td class="ps-3">
        <select class="form-select form-select-sm item-select">
          <option value="">-- Custom Item / Select --</option>
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
      <td><input type="text" class="form-control form-control-sm item-handler" placeholder="Assignee"></td>
      <td class="text-center pe-3"><button type="button" class="btn btn-sm btn-outline-danger removeRow"><i class="bi bi-trash"></i></button></td>
    </tr>`;
  }

  function recalcTotals() {
    const gstMode = getGstCalculationMode();
    let totalTaxable = 0;
    let totalTax = 0;
    let primaryTaxRate = 18;

    document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
      const qty = parseInt(tr.querySelector('.item-qty').value, 10) || 1;
      const rate = parseFloat(tr.querySelector('.item-rate').value) || 0;
      let taxRate = parseFloat(tr.querySelector('.item-tax').value) || 0;
      const discount = parseFloat(tr.querySelector('.item-discount').value) || 0;
      const isTaxInc = tr.querySelector('.item-tax-inc') ? tr.querySelector('.item-tax-inc').checked : false;
      const hintEl = tr.querySelector('.tax-breakdown-hint');

      if (gstMode === 'NONE') {
        taxRate = 0;
      } else if (taxRate > 0) {
        primaryTaxRate = taxRate;
      }

      let taxableAmount = 0;
      let taxAmount = 0;

      if (isTaxInc) {
        let grossInc = Math.round(qty * rate * 100) / 100;
        let discAmt = Math.round(grossInc * (discount / 100) * 100) / 100;
        let netInc = Math.max(0, Math.round((grossInc - discAmt) * 100) / 100);

        if (taxRate > 0) {
          taxableAmount = Math.round(((netInc * 100) / (100 + taxRate)) * 100) / 100;
          taxAmount = Math.round((netInc - taxableAmount) * 100) / 100;
        } else {
          taxableAmount = netInc;
          taxAmount = 0;
        }

        if (hintEl) {
          hintEl.style.display = 'block';
          if (gstMode === 'INTRA' && taxAmount > 0) {
            let half = Math.round((taxAmount / 2) * 100) / 100;
            let otherHalf = Math.round((taxAmount - half) * 100) / 100;
            let halfRate = Math.round((taxRate / 2) * 100) / 100;
            hintEl.innerHTML = `Base: ₹${taxableAmount.toFixed(2)}<br>CGST (${halfRate}%): ₹${half.toFixed(2)} | SGST (${halfRate}%): ₹${otherHalf.toFixed(2)}`;
          } else if (gstMode === 'INTER' && taxAmount > 0) {
            hintEl.innerHTML = `Base: ₹${taxableAmount.toFixed(2)}<br>IGST (${taxRate}%): ₹${taxAmount.toFixed(2)}`;
          } else {
            hintEl.innerHTML = `Base (No Tax): ₹${taxableAmount.toFixed(2)}`;
          }
        }
      } else {
        let grossBase = Math.round(qty * rate * 100) / 100;
        let discAmt = Math.round(grossBase * (discount / 100) * 100) / 100;
        let netBase = Math.max(0, Math.round((grossBase - discAmt) * 100) / 100);

        taxableAmount = netBase;
        taxAmount = Math.round(taxableAmount * (taxRate / 100) * 100) / 100;

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
    document.getElementById('dispTotal').innerText = '₹' + grandTotal.toFixed(2);

    const gstBreakdownEl = document.getElementById('dispGstBreakdown');
    const badgeEl = document.getElementById('clientGstBadge');
    const taxTotalRow = document.getElementById('dispTaxTotalRow');

    if (gstMode === 'INTRA') {
      let halfTax = Math.round((totalTax / 2) * 100) / 100;
      let otherHalfTax = Math.round((totalTax - halfTax) * 100) / 100;
      let halfRate = Math.round((primaryTaxRate / 2) * 100) / 100;

      gstBreakdownEl.innerHTML = `
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">CGST (${halfRate}%):</span>
          <span class="fw-semibold text-dark">₹${halfTax.toFixed(2)}</span>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">SGST (${halfRate}%):</span>
          <span class="fw-semibold text-dark">₹${otherHalfTax.toFixed(2)}</span>
        </div>
      `;
      document.getElementById('dispTax').innerText = '₹' + totalTax.toFixed(2);
      if (taxTotalRow) taxTotalRow.style.display = 'flex';
      if (badgeEl) badgeEl.innerHTML = `<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="bi bi-geo-alt-fill me-1"></i>Intra-State: Uttarakhand → Uttarakhand (CGST ${halfRate}% + SGST ${halfRate}%)</span>`;
    } else if (gstMode === 'INTER') {
      gstBreakdownEl.innerHTML = `
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">IGST (${primaryTaxRate}%):</span>
          <span class="fw-semibold text-dark">₹${totalTax.toFixed(2)}</span>
        </div>
      `;
      document.getElementById('dispTax').innerText = '₹' + totalTax.toFixed(2);
      if (taxTotalRow) taxTotalRow.style.display = 'flex';
      if (badgeEl) badgeEl.innerHTML = `<span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1"><i class="bi bi-airplane-engines me-1"></i>Inter-State: Uttarakhand → Other State (IGST ${primaryTaxRate}%)</span>`;
    } else if (gstMode === 'UNKNOWN') {
      gstBreakdownEl.innerHTML = `
        <div class="alert alert-warning py-1 px-2 small mb-2"><i class="bi bi-exclamation-triangle-fill me-1"></i>Client state missing. Enter Place of Supply code to calculate GST.</div>
      `;
      document.getElementById('dispTax').innerText = '₹0.00';
      if (taxTotalRow) taxTotalRow.style.display = 'none';
      if (badgeEl) badgeEl.innerHTML = `<span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1"><i class="bi bi-question-circle me-1"></i>State Missing (Enter Place of Supply)</span>`;
    } else {
      gstBreakdownEl.innerHTML = `
        <div class="text-muted small mb-2 fst-italic"><i class="bi bi-info-circle me-1"></i>Normal Bill Without GST (Client has no GSTIN)</div>
      `;
      document.getElementById('dispTax').innerText = '₹0.00';
      if (taxTotalRow) taxTotalRow.style.display = 'none';
      if (badgeEl) badgeEl.innerHTML = `<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1"><i class="bi bi-file-earmark-text me-1"></i>Bill Without GST (No GSTIN)</span>`;
    }
  }

  // Handle client change
  if (customerSelect) {
    customerSelect.addEventListener('change', function() {
      const opt = this.options[this.selectedIndex];
      if (opt && placeInput) {
        const stateCode = opt.dataset.stateCode || '';
        placeInput.value = stateCode;
      }
      const cid = parseInt(this.value);
      if (cid && customerServicesMap[cid] && customerServicesMap[cid].length > 0) {
        itemsTable.innerHTML = '';
        customerServicesMap[cid].forEach(cs => {
          itemsTable.insertAdjacentHTML('beforeend', rowHtml(cs.service_name, 1, parseFloat(cs.amount)));
        });
      }
      recalcTotals();
    });
  }

  if (placeInput) {
    placeInput.addEventListener('input', function() {
      if (this.value.length === 1 && this.value >= '1' && this.value <= '9') {
        // user typing
      }
      recalcTotals();
    });
  }

  typeRadios.forEach(r => r.addEventListener('change', recalcTotals));

  // Add initial row with prefilled services / extra work / project if available
  <?php if (!empty($preselectedServices)): ?>
    <?php foreach ($preselectedServices as $ps): ?>
      itemsTable.insertAdjacentHTML('beforeend', rowHtml(<?= json_encode($ps['service_name']) ?>, 1, <?= (float)$ps['amount'] ?>));
    <?php endforeach; ?>
  <?php elseif (isset($extraWorkItem) && $extraWorkItem): ?>
    itemsTable.insertAdjacentHTML('beforeend', rowHtml(<?= json_encode($extraWorkItem['title']) ?>, <?= (int)round($extraWorkItem['qty'] ?: 1) ?>, <?= (float)$extraWorkItem['rate'] ?>));
  <?php elseif (isset($projectItem) && $projectItem): ?>
    itemsTable.insertAdjacentHTML('beforeend', rowHtml(<?= json_encode("Project: " . $projectItem['project_name']) ?>, 1, <?= (float)$projectItem['agreed_amount'] ?>));
  <?php else: ?>
    itemsTable.insertAdjacentHTML('beforeend', rowHtml());
  <?php endif; ?>

  // Auto-fill place of supply for preselected customer
  if (customerSelect && customerSelect.selectedIndex > 0 && placeInput && !placeInput.value) {
    const opt = customerSelect.options[customerSelect.selectedIndex];
    if (opt && opt.dataset.stateCode) {
      placeInput.value = opt.dataset.stateCode;
    }
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
      alert('Please enter at least one valid item name.');
      return;
    }

    document.getElementById('items_json').value = JSON.stringify(arr);
    this.submit();
  });
})();
</script>

<?php include '../../includes/footer.php'; ?>
