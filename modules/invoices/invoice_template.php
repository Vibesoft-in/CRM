<?php
require_once '../../includes/functions.php';
require_admin_panel();
require_once '../../vendor/autoload.php';
use Mpdf\Mpdf;

$id = intval($_GET['id'] ?? 0);

// fetch invoice + customer
// fetch invoice + customer
$stmt = $pdo->prepare("
  SELECT i.*, i.id AS invoice_id, 
         c.id AS customer_id, c.name AS customer_name, c.gstin AS customer_gstin,
         c.phone AS customer_phone, c.email AS customer_email,
         c.billing_address, c.shipping_address
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

// subtotal (taxable base value after discount, before GST)
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
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice</title>
<style>
/* ====== PDF + Browser A4 Styling ====== */
body {
  margin: 0;
  font-family: Arial, sans-serif;
  font-size: 12pt;
  background: #a8a4a4ff;
}
.invoice-a4 {
  width: 210mm;          /* exact A4 width */
  min-height: 297mm;     /* exact A4 height */
  margin: auto;
  background: #ffffffff;
  padding: 2mm 5mm;
  box-sizing: border-box;
 
}
.main_cantaner{
  position: relative;
  overflow: hidden;
  box-sizing: border-box;
  border: 1px solid;
   padding-bottom:  5mm;
   padding-top:2px;
   padding-left:5mm;
      padding-right:5mm;
}
.cancelled-stamp {
  position: absolute;
  top: 40%;
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
  z-index: 999;
  font-family: Arial, Helvetica, sans-serif;
  text-align: center;
}
.flex {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}
.text-end { text-align: right; }
.table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 8px;
}
.table th, .table td {
  border: 1px solid #000;
  padding: 6px 8px;
  word-wrap: break-word;
}
.table th {
  background: #eee;
}
.section-title {
  font-size: 14pt;
  font-weight: bold;
  margin-bottom: 6px;
}
.totals td {
  font-weight: bold;
}
.declaration {
  font-size: 12pt;
  line-height: 1.4;
  margin-top: 10px;
  text-align: justify;
  
}
.sign-stamp {
  display: flex;
  justify-content: space-between;
  margin-top: 20px;
  margin-left:-250px;
}
.sign-box, .stamp-box {
  border: 1px dashed #000;
  width: 80mm;
  height: 15mm;
   margin-left:4px;
   
}
@media screen {
  .download-btn {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #28a745;
    color: #fff;
    padding: 10px 20px;
    text-decoration: none;
    font-size: 14pt;
    border-radius: 8px;
  }
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
  <div class="flex">
    <div>
      <?php if (!empty($logoBase64)): ?>
        <img src="<?= $logoBase64 ?>" alt="Logo" style="height:48px; max-width:180px; object-fit:contain; margin-bottom:6px;">
      <?php elseif (!empty($company['logo'])): ?>
        <img src="<?= BASE_URL . e($company['logo']) ?>" alt="Logo" style="height:48px; max-width:180px; object-fit:contain; margin-bottom:6px;">
      <?php endif; ?>
      <p><strong><?= e($company['company_name']) ?></strong><br>
      <?= nl2br(e($company['address'])) ?><br>
      GSTIN: <?= e($company['gstin']) ?><br>
      Contact: <?= e($company['phone']) ?><br>
      Website: <?= e($company['website']) ?><br>
      Email: <?= e($company['email']) ?></p>
    </div>
    <div class="text-end">
      <h3>TAX INVOICE</h3>
      <table class="table">
        <tr><td>Invoice No.</td><td><?= e($invoice['invoice_no']) ?></td></tr>
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
    </div>
  </div>
<hr>
  <!-- Buyer / Consignee -->
  <div class="flex" style="margin-top:15px;">
    <div style="width:48%;">
      <div class="section-title">Consignee (Ship To)</div>
      <p><strong><?= e($invoice['customer_name']) ?></strong><br>
      <?= nl2br(e($invoice['shipping_address'])) ?><br>
      GSTIN: <?= e($invoice['customer_gstin']) ?><br>
      Contact: <?= e($invoice['customer_phone']) ?></p>
    </div>
    <div style="width:48%;" class="text-end">
      <div class="section-title">Buyer (Bill To)</div>
      <p><strong><?= e($invoice['customer_name']) ?></strong><br>
      <?= nl2br(e($invoice['billing_address'])) ?><br>
      GSTIN: <?= e($invoice['customer_gstin']) ?><br>
      Contact: <?= e($invoice['customer_phone']) ?></p>
    </div>
  </div>

  <!-- Items -->
  <div style="margin-top:15px;">
    <table class="table">
      <thead>
        <tr>
          <th>Sl</th><th>Description</th><th>HSN/SAC</th>
          <th>Qty</th><th>Rate</th><th>Disc%</th><th>Amount</th><th>GST%</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($items as $k=>$it): ?>
        <tr>
          <td><?= $k+1 ?></td>
          <td><?= e($it['name']) ?></td>
          <td><?= e($it['hsn_sac']) ?></td>
          <td><?= (int)round($it['qty'] ?? 1) ?></td>
          <td>
            <?= money($it['rate']) ?>
            <?php if (!empty($it['is_tax_inclusive'])): ?>
              <br><span style="font-size:7.5pt;color:#0d6efd;font-weight:bold;">(Incl. Tax)</span>
            <?php endif; ?>
          </td>
          <td><?= e($it['discount'] ?? 0) ?></td>
          <td><?= money($it['line_total']) ?></td>
          <td><?= e($it['tax_rate']) ?>%</td>
        </tr>
      <?php endforeach; ?>
        <tr class="totals"><td colspan="7" class="text-end">Total</td><td><?= money($subtotal) ?></td></tr>
        <tr class="totals"><td colspan="7" class="text-end">IGST</td><td><?= money($invoice['tax_total']) ?></td></tr>
        <tr class="totals"><td colspan="7" class="text-end">Grand Total</td><td><?= money($invoice['total_amount']) ?></td></tr>
      </tbody>
    </table>
  </div>

  <!-- Footer -->
  <div class="flex" style="margin-top:15px;">
    <div style="width:45%;">
      <h4>Scan & Pay</h4>
      <img src="https://www.avasam.com/wp-content/uploads/2019/10/qr-sample.png" width="100"><br>
      <div>
        <p><strong>UPI:</strong> <?= e($company['upi_id']) ?></p>
      <p><strong>Bank:</strong> <?= e($company['bank_name']) ?></p>
      <p><strong>A/C No:</strong> <?= e($company['account_number']) ?></p>
      <p><strong>IFSC:</strong> <?= e($company['ifsc_code']) ?></p>
      </div>
    </div>
    <div style="width:50%;" class="text-end">
      <p><strong>Amount in words:</strong><br><?= e(amount_in_words($invoice['total_amount'])) ?></p>
      <p class="declaration"><strong>Declaration:</strong><br>
      We declare that this invoice shows the actual price of the goods described and all particulars are true and correct.
      Goods once sold shall not be taken back. Responsibility ceases as goods leave our godown.</p>
      <p><strong>For <?= e($company['company_name']) ?></strong></p>
      <div class="sign-stamp">
        <div>
          <p>Authorized Signature</p>
          <div class="sign-box" style="display:flex; align-items:flex-end; justify-content:center;">
            <?php if (!empty($sigBase64)): ?>
              <img src="<?= $sigBase64 ?>" style="max-height:14mm; max-width:70mm; object-fit:contain;" alt="Signature">
            <?php endif; ?>
          </div>
        </div>
        <div>
          <p>Stamp</p>
          <div class="stamp-box"></div>
        </div>
      </div>
    </div>
  </div>




     </div>


</div>

<!-- Only show download button in browser -->
<?php if (!defined('PDF_EXPORT')): ?>
    <a href="download.php?id=<?= e($invoice['invoice_id']) ?>" class="download-btn">📥 Download PDF</a>
<?php endif; ?>

</body>
</html>
