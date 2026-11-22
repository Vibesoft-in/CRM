<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$id = intval($_GET['id'] ?? 0);

// fetch invoice + customer with aliases
$stmt = $pdo->prepare("
  SELECT 
    i.*,
    i.id AS invoice_id, 
    c.id AS customer_id, c.name AS customer_name, c.gstin AS customer_gstin,
    c.phone AS customer_phone, c.email AS customer_email, 
    c.billing_address, c.shipping_address,
    c.state AS customer_state, c.state_code AS customer_state_code,
    c.city AS customer_city, c.pincode AS customer_pincode
  FROM invoices i
  LEFT JOIN customers c ON c.id = i.customer_id
  WHERE i.id = ?
");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
  header('Location:list.php'); exit;
}

// fetch items
$items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();

// fetch company settings
$company = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();

$taxBreakdown = get_invoice_tax_breakdown($invoice, [
    'id'              => $invoice['customer_id'] ?? 0,
    'name'            => $invoice['customer_name'] ?? '',
    'gstin'           => $invoice['customer_gstin'] ?? '',
    'state'           => $invoice['customer_state'] ?? '',
    'state_code'      => $invoice['customer_state_code'] ?? '',
    'billing_address' => $invoice['billing_address'] ?? ''
]);

// ✅ calculate subtotal (taxable base value after discount, before GST)
$subtotal = 0;
foreach ($items as $it) {
    if (isset($it['line_total']) && (float)$it['line_total'] > 0) {
        $subtotal += (float)$it['line_total'];
    } else {
        $amount = (float)($it['qty'] ?? 0) * (float)($it['rate'] ?? 0);
        $discount = (float)($it['discount'] ?? 0);
        if ($discount > 0 && $discount <= 100) {
            $amount -= ($amount * ($discount / 100));
        }
        $subtotal += $amount;
    }
}
$subtotal = round($subtotal, 2);

// Resolve Company Logo (with fallback and inline Base64 for 100% reliable rendering)
$logoBase64 = '';
$logoCandidates = [
    $company['logo'] ?? '',
    'assets/images/logo.png',
    'assets/images/logo_1757080939.png',
    'assets/images/logo_1789985671.png'
];
$baseAppDir = dirname(__DIR__, 2);
foreach ($logoCandidates as $cand) {
    if (empty($cand)) continue;
    $cleanRel = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $cand), DIRECTORY_SEPARATOR);
    $fullPath = $baseAppDir . DIRECTORY_SEPARATOR . $cleanRel;
    if (file_exists($fullPath) && is_file($fullPath)) {
        $mime = mime_content_type($fullPath) ?: 'image/png';
        $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
        break;
    }
}
// Resolve Company Signature
$sigBase64 = '';
if (!empty($company['signature'])) {
    $cleanSig = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $company['signature']), DIRECTORY_SEPARATOR);
    $fullSigPath = $baseAppDir . DIRECTORY_SEPARATOR . $cleanSig;
    if (file_exists($fullSigPath) && is_file($fullSigPath)) {
        $sigMime = mime_content_type($fullSigPath) ?: 'image/png';
        $sigBase64 = 'data:' . $sigMime . ';base64,' . base64_encode(file_get_contents($fullSigPath));
    }
}
?>

<style>
.invoice-a4 {
  width: 210mm;
  min-height: 297mm;
  margin: auto;
  padding: 8mm;
  background: #fff;
  box-sizing: border-box;
}
.invoice-inner {
  padding: 2mm;
  border: 1px solid #000;
  position: relative;
  overflow: hidden;
}
.cancelled-watermark-stamp {
  position: absolute;
  top: 42%;
  left: 50%;
  transform: translate(-50%, -50%) rotate(-25deg);
  border: 7px solid rgba(220, 53, 69, 0.72);
  color: rgba(220, 53, 69, 0.72);
  font-size: 64px;
  font-weight: 900;
  letter-spacing: 12px;
  padding: 12px 55px;
  text-transform: uppercase;
  border-radius: 12px;
  pointer-events: none;
  z-index: 500;
  font-family: Arial, Helvetica, sans-serif;
  text-align: center;
  user-select: none;
  box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.18);
}
@media print {
  body { margin: 0; -webkit-print-color-adjust: exact; }
  .invoice-a4 { margin: 0; border: 2px solid #000; page-break-after: always; }
  .invoice-inner { padding: 2mm; border: 2px solid #000; }
  .fixed-footer, .download-btn, .cancelled-alert-banner { display: none !important; }
}
.download-btn {
  position: fixed;
  bottom: 80px;
  right: 20px;
  z-index: 999;
}
.blink-btn { animation: blink 1.2s infinite; }
@keyframes blink { 0%, 49% {opacity:1;} 50%, 100% {opacity:0;} }
.fixed-footer {
  position: fixed;
  bottom: 0; left: 0;
  width: 100%;
  z-index: 999;
}
</style>

<?php if (($invoice['status'] ?? '') === 'cancelled'): ?>
  <div class="container my-3 cancelled-alert-banner">
    <div class="alert alert-danger d-flex flex-wrap align-items-center justify-content-between p-3 border-danger shadow-sm rounded-3">
      <div>
        <h6 class="fw-bold mb-1 text-danger"><i class="bi bi-x-circle-fill me-2"></i> THIS INVOICE IS CANCELED</h6>
        <p class="mb-0 small text-danger-emphasis">
          Reason: <strong><?= e($invoice['cancellation_reason'] ?: 'No specific reason entered.') ?></strong>
          <?php if (!empty($invoice['cancelled_at'])): ?>
            • Cancelled on <?= date('d M Y, h:i A', strtotime($invoice['cancelled_at'])) ?>
          <?php endif; ?>
        </p>
      </div>
      <div class="mt-2 mt-md-0">
        <form method="post" action="cancel.php" onsubmit="return confirm('Restore this invoice back to active state?');" class="d-inline">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="invoice_id" value="<?= e($invoice['invoice_id']) ?>">
          <input type="hidden" name="action" value="restore">
          <input type="hidden" name="return_to" value="view">
          <button type="submit" class="btn btn-sm btn-outline-danger fw-semibold">
            <i class="bi bi-arrow-counterclockwise me-1"></i> Restore / Reactivate
          </button>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="invoice-a4">
  <div class="invoice-inner">
    <?php if (($invoice['status'] ?? '') === 'cancelled'): ?>
      <div class="cancelled-watermark-stamp">CANCELED</div>
    <?php endif; ?>

    <!-- Header -->
    <div class="row">
      <div class="col-md-6">
        <?php if (!empty($logoBase64)): ?>
          <img src="<?= $logoBase64 ?>" height="50" alt="Company Logo" style="max-height:50px; max-width:180px; object-fit:contain;">
        <?php elseif (!empty($company['logo'])): ?>
          <img src="<?= BASE_URL . e($company['logo']) ?>" height="50" alt="Company Logo" style="max-height:50px; max-width:180px; object-fit:contain;">
        <?php endif; ?>
        <p class="mt-2"><strong><?= e($company['company_name']) ?></strong><br>
        <?= nl2br(e($company['address'])) ?><br>
        GSTIN: <?= e($company['gstin']) ?><br>
        Contact: <?= e($company['phone']) ?><br>
        Website: <?= e($company['website']) ?><br>
        Email: <?= e($company['email']) ?></p>
      </div>
      <div class="col-md-6 text-end">
        <h5 class="fw-bold">TAX INVOICE</h5>
        <table class="table table-sm border">
          <tr><td>Invoice No.</td><td><?= e($invoice['invoice_no']) ?></td></tr>
          <tr><td>Dated</td><td><?= date('d-m-Y', strtotime($invoice['invoice_date'])) ?></td></tr>
          <tr>
            <td>Mode/Terms</td>
            <td>
              <?php if (($invoice['status'] ?? '') === 'cancelled'): ?>
                <span class="text-danger fw-bold" style="color:#dc3545 !important;">Canceled</span>
              <?php else: ?>
                <?= e($invoice['payment_mode']) ?>
              <?php endif; ?>
            </td>
          </tr>
        </table>
      </div>
    </div>

    <hr>

    <!-- Buyer / Consignee -->
    <div class="row">
      <div class="col-md-6">
        <h6 class="fw-bold">Consignee (Ship To)</h6>
        <p><strong><?= e($invoice['customer_name']) ?></strong><br>
        <?= nl2br(e($invoice['shipping_address'] ?: $invoice['billing_address'])) ?><br>
        GSTIN: <?= e($invoice['customer_gstin'] ?: 'N/A') ?><br>
        Contact: <?= e($invoice['customer_phone']) ?><br>
        Email: <?= e($invoice['customer_email']) ?><br>
        </p>
      </div>
      <div class="col-md-6 text-end">
        <h6 class="fw-bold">Buyer (Bill To)</h6>
        <p><strong><?= e($invoice['customer_name']) ?></strong><br>
        <?= nl2br(e($invoice['billing_address'])) ?><br>
        <?php if (!empty($invoice['customer_city'])): ?>
          <?= e($invoice['customer_city']) ?><?= !empty($invoice['customer_pincode']) ? ' - ' . e($invoice['customer_pincode']) : '' ?><br>
        <?php endif; ?>
        <?php if (!empty($taxBreakdown['client_state_name'])): ?>
          State: <?= e($taxBreakdown['client_state_name']) ?> <?= !empty($taxBreakdown['place_of_supply']) ? '(Code: ' . e($taxBreakdown['place_of_supply']) . ')' : '' ?><br>
        <?php endif; ?>
        GSTIN: <?= e($invoice['customer_gstin'] ?: 'N/A') ?><br>
        Contact: <?= e($invoice['customer_phone']) ?><br>
        Email: <?= e($invoice['customer_email']) ?><br>
        </p>
      </div>
    </div>

    <!-- Items Table -->
    <div class="table-responsive mt-3">
      <table class="table table-bordered text-center">
        <thead class="table-light">
          <tr>
            <th>Sl</th><th>Description</th><th>HSN/SAC</th>
            <th>Qty</th><th>Rate</th><th>Disc %</th><th>Amount</th><th>GST(%)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($items as $k=>$it): ?>
          <tr>
            <td><?= $k+1 ?></td>
            <td><?= e($it['name']) ?><br> <a style="color:gray"><i><?= e($it['handler_name'] ?? '-') ?></i></a></td>
            <td><?= e($it['hsn_sac']) ?></td>
            <td><?= (int)round($it['qty'] ?? 1) ?></td>
            <td>
              <?= money($it['rate']) ?>
              <?php if (!empty($it['is_tax_inclusive'])): ?>
                <br><span class="badge bg-light text-primary border" style="font-size:0.65rem;">Incl. Tax</span>
              <?php endif; ?>
            </td>
            <td><?= e($it['discount'] ?? 0) ?></td>
            <td><?= money($it['line_total']) ?></td>
            <td><?= e($it['tax_rate']) ?>%</td>
          </tr>
          <?php endforeach; ?>

          <!-- ✅ Subtotal -->
          <tr>
            <td colspan="7" class="text-end">Taxable Subtotal</td>
            <td><?= money($subtotal) ?></td>
          </tr>

          <?php if ($taxBreakdown['gst_type'] === 'INTRA'): ?>
            <!-- ✅ Intra-state: CGST + SGST -->
            <tr>
              <td colspan="7" class="text-end">CGST (<?= $taxBreakdown['cgst_rate'] ?>%)</td>
              <td><?= money($taxBreakdown['cgst_amount']) ?></td>
            </tr>
            <tr>
              <td colspan="7" class="text-end">SGST (<?= $taxBreakdown['sgst_rate'] ?>%)</td>
              <td><?= money($taxBreakdown['sgst_amount']) ?></td>
            </tr>
          <?php elseif ($taxBreakdown['gst_type'] === 'INTER'): ?>
            <!-- ✅ Inter-state: IGST -->
            <tr>
              <td colspan="7" class="text-end">IGST (<?= $taxBreakdown['igst_rate'] ?>%)</td>
              <td><?= money($taxBreakdown['igst_amount']) ?></td>
            </tr>
          <?php endif; ?>

          <!-- ✅ Grand Total -->
          <tr>
            <td colspan="7" class="text-end fw-bold">Grand Total</td>
            <td class="fw-bold"><?= money($invoice['total_amount']) ?></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Footer with Payment + Declaration -->
    <table class="table table-bordered mt-3" style="width:100%; font-size:14px; border-collapse:collapse; border:1px solid #000;">
      <tr>
        <!-- Left Column: Payment -->
        <td style="width:50%; vertical-align:top; padding:14px;">
          <h6 style="margin:0 0 10px 0; font-size:15px; font-weight:bold;">Scan & Pay</h6>
          <?php if (!empty($company['upi_id'])): ?>
            <div style="text-align:center; margin-bottom:12px;">
              <img src="<?= BASE_URL ?>assets/images/qr-code.jpg" width="130" alt="QR Code"><br>
              <small style="font-size:12px;">(QR Code..)</small>
            </div>
            <p><strong>UPI ID:</strong> <?= e($company['upi_id']) ?></p>
          <?php else: ?>
            <div style="text-align:center; margin-bottom:12px;">
              <img src="<?= BASE_URL ?>assets/images/qr-placeholder.png" width="130" alt="QR Code"><br>
              <small style="font-size:12px;">(QR Code)</small>
            </div>
          <?php endif; ?>
          <p><strong>Bank:</strong> <?= e($company['bank_name']) ?></p>
          <p><strong>A/C Holder:</strong> <?= e($company['account_holder']) ?></p>
          <p><strong>A/C No:</strong> <?= e($company['account_number']) ?></p>
          <p><strong>IFSC:</strong> <?= e($company['ifsc_code']) ?></p>
        </td>
        <!-- Right Column: Declaration -->
        <td style="width:50%; vertical-align:top; padding:14px;">
          <p><strong>Amount Chargeable (in words):</strong><br>
            <?= e(amount_in_words($invoice['total_amount'])) ?>
          </p>
          <p style="font-size:13px; margin:12px 0 18px 0; text-align:justify; line-height:1.6;">
            <strong>Declaration</strong><br>
            We declare that this invoice shows the actual price of the goods described and that all particulars are true and correct.
            Goods once sold shall not be taken back. Our responsibility ceases as the goods leave our godown. If payment is not received
            in stipulated time, interest @18% shall be charged. Cheques dishonoured may attract a penalty plus GST.
          </p>
          <p style="margin:20px 0 30px 0; font-weight:bold; text-align:right; font-size:14px;">
            For <?= e($company['company_name']) ?>
          </p>
          <table style="width:100%; margin-top:12px; text-align:center; font-size:13px;">
            <tr>
              <td style="width:50%; padding:6px; vertical-align:bottom;">
                <p style="margin:0; font-weight:bold;">Authorized Signature</p>
                <?php if (!empty($sigBase64)): ?>
                  <div style="height:50px; margin-top:8px; display:flex; align-items:flex-end; justify-content:center;">
                    <img src="<?= $sigBase64 ?>" style="max-height:48px; max-width:140px; object-fit:contain;" alt="Authorized Signature">
                  </div>
                <?php else: ?>
                  <div style="border:1px dashed #000; height:50px; margin-top:8px;"></div>
                <?php endif; ?>
              </td>
              <td style="width:50%; padding:6px;">
                <p style="margin:0; font-weight:bold;">Stamp</p>
                <div style="border:1px dashed #000; height:50px; margin-top:8px;"></div>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </div>
</div>

<!-- Floating download button -->
<div class="download-btn">
  <a class="btn btn-sm btn-outline-success blink-btn" href="download.php?id=<?= e($invoice['invoice_id']) ?>">
    Download PDF
  </a>
</div>

<!-- Fixed Footer -->
<div class="fixed-footer bg-light border-top shadow-lg p-3">
  <div class="container d-flex flex-wrap justify-content-center align-items-center gap-2">
    <a href="download.php?id=<?= e($invoice['invoice_id']) ?>" class="btn btn-success"><i class="bi bi-download me-1"></i> Download PDF</a>
    
    <?php if (($invoice['status'] ?? '') !== 'cancelled'): ?>
      <a href="edit.php?id=<?= e($invoice['invoice_id']) ?>" class="btn btn-warning"><i class="bi bi-pencil me-1"></i> Edit Invoice</a>
      <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelInvoiceModal">
        <i class="bi bi-x-circle me-1"></i> Cancel Invoice
      </button>
    <?php else: ?>
      <button class="btn btn-secondary" disabled title="Cancelled invoices cannot be edited"><i class="bi bi-slash-circle me-1"></i> Edit (Disabled)</button>
      <form method="post" action="cancel.php" onsubmit="return confirm('Restore this invoice back to active state?');" class="d-inline m-0">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="invoice_id" value="<?= e($invoice['invoice_id']) ?>">
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="return_to" value="view">
        <button type="submit" class="btn btn-outline-success"><i class="bi bi-arrow-counterclockwise me-1"></i> Restore Invoice</button>
      </form>
    <?php endif; ?>

    <a href="../customers/view.php?id=<?= e($invoice['customer_id']) ?>" class="btn btn-primary"><i class="bi bi-person me-1"></i> Customer Profile</a>
    
    <form method="post" action="delete.php" onsubmit="return confirm('⚠️ WARNING: Deleting an invoice creates a serial gap. Consider Cancelling instead. Are you sure you want to permanently delete?');" class="d-inline m-0">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" value="<?= e($invoice['invoice_id']) ?>">
      <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-trash me-1"></i> Delete</button>
    </form>
  </div>
</div>

<!-- Cancel Invoice Modal -->
<div class="modal fade" id="cancelInvoiceModal" tabindex="-1" aria-labelledby="cancelInvoiceModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title fw-bold" id="cancelInvoiceModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i> Cancel Invoice <?= e($invoice['invoice_no']) ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="cancel.php">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="invoice_id" value="<?= e($invoice['invoice_id']) ?>">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="return_to" value="view">
        <div class="modal-body p-4">
          <div class="alert alert-warning small mb-3">
            <i class="bi bi-info-circle me-1"></i> <strong>Audit Note:</strong> The serial number will be preserved in the CA sequence to ensure zero invoice number gaps. Customer ledger balance will be automatically adjusted.
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">Reason for Cancellation <span class="text-danger">*</span></label>
            <textarea name="reason" class="form-control" rows="3" placeholder="e.g. Order cancelled by client, duplicate invoice, incorrect billing details" required></textarea>
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-danger fw-bold"><i class="bi bi-x-circle me-1"></i> Confirm Cancellation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>

