<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_admin_panel();

// Auto-sync any GST invoices from invoices table that are not yet in gst_invoices registry
$unsyncedGst = $pdo->query("SELECT id FROM invoices WHERE (invoice_type = 'gst' OR invoice_type IS NULL OR invoice_type = '') AND id NOT IN (SELECT invoice_id FROM gst_invoices)")->fetchAll(PDO::FETCH_COLUMN);
if (!empty($unsyncedGst)) {
    foreach ($unsyncedGst as $uId) {
        sync_invoice_to_subregistry($uId);
    }
}

// Handle Export to CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $where = ["1=1"];
    $params = [];

    $fy = trim($_GET['fy'] ?? '');
    if ($fy !== '') {
        $where[] = "g.financial_year = ?";
        $params[] = $fy;
    }

    $month = trim($_GET['month'] ?? '');
    if ($month !== '') {
        $where[] = "g.month_str = ?";
        $params[] = str_pad($month, 2, '0', STR_PAD_LEFT);
    }

    $whereSql = implode(" AND ", $where);
    $stmt = $pdo->prepare("
        SELECT g.serial_no, g.invoice_no, g.invoice_date, c.name AS customer_name, c.gstin AS customer_gstin,
               c.state, i.place_of_supply, g.taxable_value, g.cgst_amount, g.sgst_amount, g.igst_amount,
               g.tax_total, g.total_amount, g.status, g.cancellation_reason
        FROM gst_invoices g
        LEFT JOIN invoices i ON i.id = g.invoice_id
        LEFT JOIN customers c ON c.id = g.customer_id
        WHERE $whereSql
        ORDER BY g.serial_no ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=GST_Invoices_CA_Filing_' . date('Ymd_His') . '.csv');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, [
        'Serial No',
        'Invoice Number',
        'Invoice Date',
        'Customer Name',
        'Customer GSTIN',
        'Place of Supply',
        'Taxable Value (INR)',
        'CGST (INR)',
        'SGST (INR)',
        'IGST (INR)',
        'Total Tax (INR)',
        'Invoice Total (INR)',
        'Status',
        'Cancellation Reason'
    ]);

    foreach ($rows as $r) {
        fputcsv($output, [
            $r['serial_no'],
            $r['invoice_no'],
            $r['invoice_date'],
            $r['customer_name'] ?: 'N/A',
            $r['customer_gstin'] ?: 'Unregistered',
            $r['place_of_supply'] ?: ($r['state'] ?: '-'),
            number_format((float)$r['taxable_value'], 2, '.', ''),
            number_format((float)$r['cgst_amount'], 2, '.', ''),
            number_format((float)$r['sgst_amount'], 2, '.', ''),
            number_format((float)$r['igst_amount'], 2, '.', ''),
            number_format((float)$r['tax_total'], 2, '.', ''),
            number_format((float)$r['total_amount'], 2, '.', ''),
            strtoupper($r['status']),
            $r['cancellation_reason'] ?: ''
        ]);
    }
    fclose($output);
    exit;
}

$search = trim($_GET['q'] ?? '');
$fyFilter = trim($_GET['fy'] ?? get_financial_year());
$monthFilter = trim($_GET['month'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = ["1=1"];
$params = [];

if ($fyFilter !== '') {
    $where[] = "g.financial_year = ?";
    $params[] = $fyFilter;
}

if ($monthFilter !== '') {
    $where[] = "g.month_str = ?";
    $params[] = str_pad($monthFilter, 2, '0', STR_PAD_LEFT);
}

if ($statusFilter !== '') {
    $where[] = "g.status = ?";
    $params[] = $statusFilter;
}

if ($search !== '') {
    $where[] = "(g.invoice_no LIKE ? OR c.name LIKE ? OR c.gstin LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$whereSql = implode(" AND ", $where);
$stmt = $pdo->prepare("
    SELECT g.*, c.name AS customer_name, c.gstin AS customer_gstin, c.client_logo,
           i.place_of_supply, i.payment_mode
    FROM gst_invoices g
    LEFT JOIN invoices i ON i.id = g.invoice_id
    LEFT JOIN customers c ON c.id = g.customer_id
    WHERE $whereSql
    ORDER BY g.serial_no ASC
");
$stmt->execute($params);
$records = $stmt->fetchAll();

// Metrics
$totTaxable = 0;
$totTax = 0;
$totGrand = 0;
$activeCount = 0;
$cancelledCount = 0;

foreach ($records as $rec) {
    if ($rec['status'] === 'cancelled') {
        $cancelledCount++;
    } else {
        $activeCount++;
        $totTaxable += (float)$rec['taxable_value'];
        $totTax += (float)$rec['tax_total'];
        $totGrand += (float)$rec['total_amount'];
    }
}

// Available financial years in database
$fyList = $pdo->query("SELECT DISTINCT financial_year FROM gst_invoices WHERE financial_year IS NOT NULL ORDER BY financial_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($fyList)) {
    $fyList = [get_financial_year()];
}
?>

<div class="container-fluid">
  <!-- Page Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1">
        <i class="bi bi-file-earmark-spreadsheet text-primary me-2"></i>CA GST Invoices Filing Registry
      </h4>
      <p class="text-muted small mb-0">
        Strictly sequential GST tax invoices with zero number gaps. Dedicated for Chartered Accountant (CA) filing & audit reports.
      </p>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary shadow-sm fw-semibold" href="list.php">
        <i class="bi bi-arrow-left me-1"></i> Back to Invoices
      </a>
      <a class="btn btn-primary shadow-sm fw-semibold" href="create.php">
        <i class="bi bi-plus-circle me-1"></i> Create New Invoice
      </a>
      <a class="btn btn-success shadow-sm fw-semibold" href="gst_registry.php?export=csv&fy=<?= urlencode($fyFilter) ?>&month=<?= urlencode($monthFilter) ?>">
        <i class="bi bi-download me-1"></i> Export for CA (Excel/CSV)
      </a>
    </div>
  </div>

  <!-- Metric Summaries -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center" style="border-radius: 12px; border-left: 4px solid #0d6efd !important;">
        <span class="text-muted small uppercase fw-bold" style="font-size: 0.72rem;">TOTAL INVOICES IN SERIES</span>
        <h4 class="fw-bold text-dark mb-0 mt-1"><?= count($records) ?> <small class="text-muted fs-6">(<?= $activeCount ?> Active, <?= $cancelledCount ?> Cancelled)</small></h4>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center" style="border-radius: 12px; border-left: 4px solid #198754 !important;">
        <span class="text-muted small uppercase fw-bold" style="font-size: 0.72rem;">TAXABLE TURNOVER</span>
        <h4 class="fw-bold text-success mb-0 mt-1">₹<?= money($totTaxable) ?></h4>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center" style="border-radius: 12px; border-left: 4px solid #ffc107 !important;">
        <span class="text-muted small uppercase fw-bold" style="font-size: 0.72rem;">TOTAL GST TAX</span>
        <h4 class="fw-bold text-warning mb-0 mt-1">₹<?= money($totTax) ?></h4>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center" style="border-radius: 12px; border-left: 4px solid #6f42c1 !important;">
        <span class="text-muted small uppercase fw-bold" style="font-size: 0.72rem;">GROSS INVOICED</span>
        <h4 class="fw-bold text-purple mb-0 mt-1">₹<?= money($totGrand) ?></h4>
      </div>
    </div>
  </div>

  <!-- Filter Card -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
    <div class="card-body p-3">
      <form method="get" class="row g-2 align-items-center">
        <div class="col-md-4">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="Search invoice, client, or GSTIN..." value="<?= e($search) ?>">
          </div>
        </div>
        <div class="col-md-2">
          <select name="fy" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Financial Years</option>
            <?php foreach ($fyList as $y): ?>
              <option value="<?= e($y) ?>" <?= $fyFilter === $y ? 'selected' : '' ?>>FY <?= e($y) ?> (20<?= substr($y, 0, 2) ?>-<?= substr($y, 2, 2) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Months</option>
            <?php 
            $months = [
                '04' => 'April', '05' => 'May', '06' => 'June', '07' => 'July',
                '08' => 'August', '09' => 'September', '10' => 'October', '11' => 'November',
                '12' => 'December', '01' => 'January', '02' => 'February', '03' => 'March'
            ];
            foreach ($months as $num => $mName): ?>
              <option value="<?= $num ?>" <?= $monthFilter === $num ? 'selected' : '' ?>><?= $mName ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled Only</option>
          </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
          <button class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel me-1"></i> Filter</button>
          <a href="gst_registry.php" class="btn btn-sm btn-light border" title="Reset"><i class="bi bi-x-circle"></i></a>
        </div>
      </form>
    </div>
  </div>

  <!-- Audit & CA Compliance Info Banner -->
  <div class="alert alert-info d-flex align-items-center mb-4 p-3 border-info-subtle shadow-sm rounded-3">
    <i class="bi bi-info-circle-fill fs-4 text-primary me-3 flex-shrink-0"></i>
    <div class="small">
      <strong>Zero Serial Gap Guarantee:</strong> This table maintains unbroken serial numbers (1, 2, 3...) for your CA. Invoices that were cancelled remain recorded in the series with an explicit <code>CANCELLED</code> tag so your CA and tax auditors have full visibility without any missing invoice query.
    </div>
  </div>

  <!-- Registry Table -->
  <div class="card border-0 shadow-sm" style="border-radius: 14px; overflow: hidden;">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="bg-light text-muted small uppercase">
            <tr>
              <th class="ps-3 text-center" style="width: 70px;">Sl #</th>
              <th>Invoice Number</th>
              <th>Date</th>
              <th>Customer Name</th>
              <th>GSTIN</th>
              <th class="text-end">Taxable Value</th>
              <th class="text-end">CGST</th>
              <th class="text-end">SGST</th>
              <th class="text-end">Tax Total</th>
              <th class="text-end">Grand Total</th>
              <th class="text-center">Status</th>
              <th class="text-end pe-3">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($records)): ?>
              <?php foreach ($records as $r): 
                $isCanc = ($r['status'] === 'cancelled');
              ?>
                <tr class="<?= $isCanc ? 'table-light' : '' ?>" style="<?= $isCanc ? 'background-color: #fff5f5;' : '' ?>">
                  <td class="ps-3 text-center fw-bold text-muted">
                    #<?= (int)$r['serial_no'] ?>
                  </td>
                  <td>
                    <a href="view.php?id=<?= $r['invoice_id'] ?>" class="fw-bold <?= $isCanc ? 'text-decoration-line-through text-danger' : 'text-primary text-decoration-none' ?>">
                      <?= e($r['invoice_no']) ?>
                    </a>
                  </td>
                  <td class="small text-muted"><?= date('d M Y', strtotime($r['invoice_date'])) ?></td>
                  <td>
                    <span class="fw-semibold text-dark"><?= e($r['customer_name'] ?: 'N/A') ?></span>
                  </td>
                  <td>
                    <?php if (!empty($r['customer_gstin'])): ?>
                      <span class="badge bg-light text-dark border font-monospace"><?= e($r['customer_gstin']) ?></span>
                    <?php else: ?>
                      <span class="text-muted small">Unregistered</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end fw-semibold">₹<?= money($r['taxable_value']) ?></td>
                  <td class="text-end text-muted small">₹<?= money($r['cgst_amount']) ?></td>
                  <td class="text-end text-muted small">₹<?= money($r['sgst_amount']) ?></td>
                  <td class="text-end text-warning fw-semibold">₹<?= money($r['tax_total']) ?></td>
                  <td class="text-end fw-bold text-dark">₹<?= money($r['total_amount']) ?></td>
                  <td class="text-center">
                    <?php if ($isCanc): ?>
                      <span class="badge bg-danger" title="<?= e($r['cancellation_reason'] ?: 'Cancelled') ?>">
                        <i class="bi bi-x-circle me-1"></i>CANCELLED
                      </span>
                    <?php else: ?>
                      <span class="badge bg-success">
                        <i class="bi bi-check-circle me-1"></i>ACTIVE
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end pe-3">
                    <a href="view.php?id=<?= $r['invoice_id'] ?>" class="btn btn-sm btn-outline-primary" title="View Invoice">
                      <i class="bi bi-eye"></i>
                    </a>
                    <a href="download.php?id=<?= $r['invoice_id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Download PDF">
                      <i class="bi bi-download"></i>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="12" class="text-center py-5 text-muted">
                  <i class="bi bi-file-earmark-spreadsheet fs-1 d-block mb-2 text-muted"></i>
                  No GST invoices recorded yet in this series.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>
