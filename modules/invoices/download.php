<?php
require_once '../../includes/functions.php';
require_admin_panel();
require_once '../../vendor/autoload.php';
use Mpdf\Mpdf;

$id = intval($_GET['id'] ?? 0);
$action = $_GET['action'] ?? 'view';  // view = show in browser, pdf = generate PDF

// fetch invoice + customer
$stmt = $pdo->prepare("
  SELECT i.*, i.id AS invoice_id, 
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

if (!$invoice) { header('Location:list.php'); exit; }

$items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();

$company = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();

$taxBreakdown = get_invoice_tax_breakdown($invoice, [
    'id'              => $invoice['customer_id'] ?? 0,
    'name'            => $invoice['customer_name'] ?? '',
    'gstin'           => $invoice['customer_gstin'] ?? '',
    'state'           => $invoice['customer_state'] ?? '',
    'state_code'      => $invoice['customer_state_code'] ?? '',
    'billing_address' => $invoice['billing_address'] ?? ''
]);

// subtotal & tax calculation respecting tax-inclusive items
$subtotal = 0;
$totalTax = 0;
foreach ($items as &$it) {
    $qty = max(1, (int)($it['qty'] ?? 1));
    $rate = (float)($it['rate'] ?? 0);
    $disc = (float)($it['discount'] ?? 0);
    $taxRate = (float)($it['tax_rate'] ?? 0);
    $isInc = !empty($it['is_tax_inclusive']);

    if (isset($it['line_total']) && (float)$it['line_total'] > 0) {
        $taxableAmount = (float)$it['line_total'];
        $taxAmount = isset($it['line_tax']) ? (float)$it['line_tax'] : round($taxableAmount * ($taxRate / 100), 2);
    } else {
        $calc = calculate_invoice_line_item($qty, $rate, $taxRate, $disc, $isInc);
        $taxableAmount = $calc['taxable_amount'];
        $taxAmount = $calc['tax_amount'];
    }

    $it['computed_taxable'] = $taxableAmount;
    $it['computed_tax'] = $taxAmount;
    $subtotal += $taxableAmount;
    $totalTax += $taxAmount;
}
unset($it);

if (isset($invoice['taxable_value']) && (float)$invoice['taxable_value'] > 0) {
    $subtotal = (float)$invoice['taxable_value'];
}
$taxTotalDisplay = (isset($invoice['tax_total']) && (float)$invoice['tax_total'] > 0) ? (float)$invoice['tax_total'] : $totalTax;

// Resolve Company Logo (with fallback and inline Base64 for 100% reliable rendering in mPDF and browser)
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

// Resolve QR Code (local fallback as Base64)
$qrCodeBase64 = '';
$qrCandidates = [
    'assets/images/qr-code.jpg',
    'assets/images/qr-code.png',
    'assets/images/qr-placeholder.png'
];
foreach ($qrCandidates as $qCand) {
    $fullQPath = $baseAppDir . DIRECTORY_SEPARATOR . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $qCand);
    if (file_exists($fullQPath) && is_file($fullQPath)) {
        $qMime = mime_content_type($fullQPath) ?: 'image/jpeg';
        $qrCodeBase64 = 'data:' . $qMime . ';base64,' . base64_encode(file_get_contents($fullQPath));
        break;
    }
}

// Resolve Company Signature (with fallback and inline Base64 for mPDF/browser)
$sigBase64 = '';
if (!empty($company['signature'])) {
    $cleanSig = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $company['signature']), DIRECTORY_SEPARATOR);
    $fullSigPath = $baseAppDir . DIRECTORY_SEPARATOR . $cleanSig;
    if (file_exists($fullSigPath) && is_file($fullSigPath)) {
        $sigMime = mime_content_type($fullSigPath) ?: 'image/png';
        $sigBase64 = 'data:' . $sigMime . ';base64,' . base64_encode(file_get_contents($fullSigPath));
    }
}

$isPdf = ($action === 'pdf');
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice - <?= e($invoice['invoice_no']) ?></title>
<style>
/* ====== PDF + Browser A4 Styling ====== */
body {
  margin: 0;
  font-family: Arial, sans-serif;
  font-size: 11pt;
  background: #f4f6f9;
}
.invoice-a4 {
  width: 210mm;
  min-height: 297mm;
  margin: 10px auto;
  background: #fff;
  padding: 8mm;
  box-sizing: border-box;
}
.main_cantaner {
  position: relative;
  overflow: hidden;
  box-sizing: border-box;
  border: 1px solid #000;
  padding: 8px 10px 15px 10px;
}
.cancelled-stamp {
  position: absolute;
  top: 40%;
  left: 50%;
  transform: translate(-50%, -50%) rotate(-25deg);
  border: 6px solid rgba(220, 53, 69, 0.72);
  color: rgba(220, 53, 69, 0.72);
  font-size: 52pt;
  font-weight: 900;
  letter-spacing: 10px;
  padding: 10px 50px;
  text-transform: uppercase;
  border-radius: 12px;
  pointer-events: none;
  z-index: 999;
  text-align: center;
}
.flex { display: flex; justify-content: space-between; align-items: flex-start; }
.text-end { text-align: right; }
.table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.table th, .table td { border: 1px solid #000; padding: 6px 8px; word-wrap: break-word; }
.table th { background: #eee; }
.section-title { font-size: 14pt; font-weight: bold; margin-bottom: 6px; }
.totals td { font-weight: bold; }
.declaration { font-size: 10pt; line-height: 1.4; margin-top: 10px; text-align: justify; }
@media screen {
  .download-btn {
    position: fixed; bottom: 20px; right: 20px;
    background: #0d6efd; color: #fff; padding: 12px 24px;
    text-decoration: none; font-size: 13pt; font-weight: 600; border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 1000;
  }
  .download-btn:hover { background: #0b5ed7; }
}
@media print {
  body { background: #fff; }
  .download-btn { display: none !important; }
}
</style>
</head>
<body>
<div class="invoice-a4">
  <div class="main_cantaner">
    <?php if (($invoice['status'] ?? '') === 'cancelled'): ?>
      <div class="cancelled-stamp">CANCELED</div>
    <?php endif; ?>

    <!-- Header -->
    <table style="width:100%; border:none; border-collapse:collapse; margin-bottom:10px;">
      <tr>
        <td style="width:58%; vertical-align:top; border:none; padding:0;">
          <?php if (!empty($logoBase64)): ?>
            <img src="<?= $logoBase64 ?>" alt="VibeSoft Logo" style="height:48px; max-width:180px; object-fit:contain; margin-bottom:6px;">
          <?php elseif (!empty($company['logo'])): ?>
            <img src="<?= BASE_URL . e($company['logo']) ?>" alt="VibeSoft Logo" style="height:48px; max-width:180px; object-fit:contain; margin-bottom:6px;">
          <?php endif; ?>
          <p style="margin:4px 0 0 0; font-size:10.5pt; line-height:1.4;">
            <strong><?= e($company['company_name']) ?></strong><br>
            <?= nl2br(e($company['address'])) ?><br>
            GSTIN: <?= e($company['gstin']) ?><br>
            Contact: <?= e($company['phone']) ?><br>
            Website: <?= e($company['website']) ?><br>
            Email: <?= e($company['email']) ?>
          </p>
        </td>
        <td style="width:42%; vertical-align:top; text-align:right; border:none; padding:0;">
          <h3 style="margin:0 0 6px 0; font-size:15pt; text-align:right;">TAX INVOICE</h3>
          <table class="table" style="margin-left:auto; width:100%; font-size:10pt;">
            <tr><td style="width:40%;">Invoice No.</td><td style="font-weight:bold;"><?= e($invoice['invoice_no']) ?></td></tr>
            <tr><td>Dated</td><td><?= date('d-m-Y', strtotime($invoice['invoice_date'])) ?></td></tr>
            <tr>
              <td>Mode/Terms</td>
              <td>
                <?php if (($invoice['status'] ?? '') === 'cancelled'): ?>
                  <span style="color:#dc3545; font-weight:bold;">Canceled</span>
                <?php else: ?>
                  <?= e($invoice['payment_mode']) ?>
                <?php endif; ?>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>

    <hr style="border:none; border-top:1px solid #000; margin:10px 0;">

    <!-- Buyer / Consignee -->
    <table style="width:100%; border:none; border-collapse:collapse; margin-bottom:10px; font-size:10.5pt;">
      <tr>
        <td style="width:50%; vertical-align:top; border:none; padding:0 8px 0 0;">
          <h4 style="margin:0 0 4px 0; font-size:11pt;">Consignee (Ship To)</h4>
          <p style="margin:0; line-height:1.4;">
            <strong><?= e($invoice['customer_name']) ?></strong><br>
            <?= nl2br(e($invoice['shipping_address'] ?: $invoice['billing_address'])) ?><br>
            GSTIN: <?= e($invoice['customer_gstin']) ?><br>
            Contact: <?= e($invoice['customer_phone']) ?>
          </p>
        </td>
        <td style="width:50%; vertical-align:top; text-align:right; border:none; padding:0 0 0 8px;">
          <h4 style="margin:0 0 4px 0; font-size:11pt; text-align:right;">Buyer (Bill To)</h4>
          <p style="margin:0; line-height:1.4; text-align:right;">
            <strong><?= e($invoice['customer_name']) ?></strong><br>
            <?= nl2br(e($invoice['billing_address'])) ?><br>
            <?php if (!empty($invoice['customer_city'])): ?>
              <?= e($invoice['customer_city']) ?><?= !empty($invoice['customer_pincode']) ? ' - ' . e($invoice['customer_pincode']) : '' ?><br>
            <?php endif; ?>
            <?php if (!empty($taxBreakdown['client_state_name'])): ?>
              State: <?= e($taxBreakdown['client_state_name']) ?> <?= !empty($taxBreakdown['place_of_supply']) ? '(Code: ' . e($taxBreakdown['place_of_supply']) . ')' : '' ?><br>
            <?php endif; ?>
            GSTIN: <?= e($invoice['customer_gstin'] ?: 'N/A') ?><br>
            Contact: <?= e($invoice['customer_phone']) ?>
          </p>
        </td>
      </tr>
    </table>

    <!-- Items Table -->
    <div style="margin-top:10px;">
      <table class="table" style="font-size:10pt;">
        <thead>
          <tr>
            <th style="width:5%;">SI</th>
            <th style="width:38%;">Description</th>
            <th style="width:12%;">HSN/SAC</th>
            <th style="width:7%;">Qty</th>
            <th style="width:12%;">Rate</th>
            <th style="width:8%;">Disc%</th>
            <th style="width:10%;">Amount</th>
            <th style="width:8%;">GST%</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $k => $it): ?>
          <tr>
            <td style="text-align:center;"><?= $k+1 ?></td>
            <td><?= e($it['name']) ?></td>
            <td style="text-align:center;"><?= e($it['hsn_sac'] ?? '-') ?></td>
            <td style="text-align:center;"><?= (int)round($it['qty'] ?? 1) ?></td>
            <td style="text-align:right;">
              <?= money($it['rate']) ?>
              <?php if (!empty($it['is_tax_inclusive'])): ?>
                <br><small style="color:#0d6efd; font-size:8pt;">(Incl. Tax)</small>
              <?php endif; ?>
            </td>
            <td style="text-align:center;"><?= (float)($it['discount'] ?? 0) ?>%</td>
            <td style="text-align:right;"><?= money($it['computed_taxable']) ?></td>
            <td style="text-align:center;"><?= e($it['tax_rate']) ?>%</td>
          </tr>
        <?php endforeach; ?>
          <tr class="totals"><td colspan="6" style="text-align:right;">Subtotal (Taxable Value)</td><td colspan="2" style="text-align:right;"><?= money($subtotal) ?></td></tr>
          <?php if ($taxBreakdown['gst_type'] === 'INTRA'): ?>
            <tr class="totals"><td colspan="6" style="text-align:right;">CGST (<?= $taxBreakdown['cgst_rate'] ?>%)</td><td colspan="2" style="text-align:right;"><?= money($taxBreakdown['cgst_amount']) ?></td></tr>
            <tr class="totals"><td colspan="6" style="text-align:right;">SGST (<?= $taxBreakdown['sgst_rate'] ?>%)</td><td colspan="2" style="text-align:right;"><?= money($taxBreakdown['sgst_amount']) ?></td></tr>
          <?php elseif ($taxBreakdown['gst_type'] === 'INTER'): ?>
            <tr class="totals"><td colspan="6" style="text-align:right;">IGST (<?= $taxBreakdown['igst_rate'] ?>%)</td><td colspan="2" style="text-align:right;"><?= money($taxBreakdown['igst_amount']) ?></td></tr>
          <?php endif; ?>
          <tr class="totals"><td colspan="6" style="text-align:right; font-size:11pt; font-weight:bold;">Grand Total</td><td colspan="2" style="text-align:right; font-size:11pt; font-weight:bold;"><?= money($invoice['total_amount']) ?></td></tr>
        </tbody>
      </table>
    </div>

    <!-- Footer Section -->
    <div style="border:1px solid #ccc; padding:12px; margin-top:15px; border-radius:4px; background:#fafafa;">
      <table style="width:100%; border:none; border-collapse:collapse;">
        <tr>
          <!-- Left: Scan & Pay -->
          <td style="width:42%; border-right:1px solid #ddd; padding-right:12px; vertical-align:top;">
            <h4 style="margin:0 0 8px 0; color:#111; font-size:12pt;">Scan & Pay</h4>
            <?php if (!empty($qrCodeBase64)): ?>
              <img src="<?= $qrCodeBase64 ?>" width="105" alt="QR Code" style="margin-bottom:6px; border:1px solid #ccc; padding:3px; background:#fff;"><br>
            <?php else: ?>
              <img src="https://invoice.vibesoft.in/assets/images/qr-code.jpg" width="105" alt="QR Code" style="margin-bottom:6px; border:1px solid #ccc; padding:3px; background:#fff;"><br>
            <?php endif; ?>
            <div style="font-size:9.5pt; line-height:1.4; color:#333;">
              <p style="margin:2px 0;"><strong>UPI:</strong> <?= e($company['upi_id']) ?></p>
              <p style="margin:2px 0;"><strong>Bank:</strong> <?= e($company['bank_name']) ?></p>
              <p style="margin:2px 0;"><strong>A/C No:</strong> <?= e($company['account_number']) ?></p>
              <p style="margin:2px 0;"><strong>IFSC:</strong> <?= e($company['ifsc_code']) ?></p>
            </div>
          </td>

          <!-- Right: Amount + Declaration + Signature/Stamp -->
          <td style="width:58%; padding-left:12px; vertical-align:top;">
            <div style="margin-bottom:10px;">
              <h5 style="margin:0; font-size:9pt; color:#555; text-transform:uppercase;">Amount in Words:</h5>
              <p style="margin:2px 0 0 0; font-weight:bold; font-size:10.5pt; color:#111;"><?= e(amount_in_words($invoice['total_amount'])) ?></p>
            </div>

            <p class="declaration" style="font-size:9pt; line-height:1.3; color:#555; margin:8px 0 15px 0;">
              <strong>Declaration:</strong> We declare that this invoice shows the actual price of the goods/services described and all particulars are true and correct.
            </p>

            <div style="text-align:right; margin-top:15px;">
              <p style="margin:0; font-size:10pt;"><strong>For <?= e($company['company_name']) ?></strong></p>
              <table style="margin-left:auto; margin-top:12px; border:none; border-collapse:collapse;">
                <tr>
                  <td style="width:110px; text-align:center; padding:0 8px; border:none; vertical-align:bottom;">
                    <?php if (!empty($sigBase64)): ?>
                      <div style="height:35px; margin-bottom:3px; text-align:center;">
                        <img src="<?= $sigBase64 ?>" style="max-height:35px; max-width:105px; object-fit:contain;" alt="Authorized Signature">
                      </div>
                    <?php else: ?>
                      <div style="border:1px dashed #999; height:35px; margin-bottom:3px; background:#fff;"></div>
                    <?php endif; ?>
                    <span style="font-size:8.5pt; color:#666;">Authorized Signatory</span>
                  </td>
                  <td style="width:90px; text-align:center; padding:0 8px; border:none;">
                    <div style="border:1px dashed #999; height:35px; margin-bottom:3px; background:#fff;"></div>
                    <span style="font-size:8.5pt; color:#666;">Company Stamp</span>
                  </td>
                </tr>
              </table>
            </div>
          </td>
        </tr>
      </table>
    </div>

  </div>
</div>

<?php if (!$isPdf): ?>
  <a href="download.php?id=<?= e($invoice['invoice_id']) ?>&action=pdf" class="download-btn">📥 Download PDF</a>
<?php endif; ?>
</body>
</html>
<?php
$html = ob_get_clean();

if ($action === 'pdf') {
    $mpdf = new Mpdf([
        'format' => 'A4',
        'margin_left' => 3,
        'margin_right' => 3,
        'margin_top' => 2,
        'margin_bottom' => 2,
    ]);
    if (($invoice['status'] ?? '') === 'cancelled') {
        $mpdf->SetWatermarkText('CANCELED', 0.2);
        $mpdf->showWatermarkText = true;
    }
    $mpdf->WriteHTML($html);
    $filename = "Invoice-" . $invoice['invoice_no'] . ".pdf";
    $mpdf->Output($filename, "D"); // D = download, I = inline view
    exit;
}

echo $html; // normal browser preview
