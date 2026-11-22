<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/ledger_helper.php';
require_login();

/**
 * Generate monthly invoice for a client with duplicate protection
 */
function generate_monthly_invoice_for_client($customer_id, $month, $year) {
    global $pdo;
    
    // Check if client exists, is ACTIVE, and has auto_invoice enabled
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $client = $stmt->fetch();
    
    // Stop monthly invoicing if client doesn't exist, amount is 0, status is NOT active (e.g. completed, inactive), or auto_invoice disabled
    if (!$client || $client['monthly_amount'] <= 0 || $client['status'] !== 'active' || (int)($client['auto_invoice'] ?? 1) === 0) {
        return false;
    }

    
    // Check unique billing cycle constraint
    $chk = $pdo->prepare("SELECT invoice_id FROM billing_cycles WHERE customer_id = ? AND billing_month = ? AND billing_year = ?");
    $chk->execute([$customer_id, $month, $year]);
    $cycle = $chk->fetch();
    if ($cycle) {
        return $cycle['invoice_id']; // Already generated
    }
    
    $pdo->beginTransaction();
    try {
        $monthName   = date('F', mktime(0, 0, 0, $month, 1));
        $invoiceDate = date('Y-m-d', strtotime("$year-$month-01"));
        $dueDays     = (int)($client['due_days'] ?: 10);
        $dueDate     = date('Y-m-d', strtotime("$invoiceDate + $dueDays days"));

        $invGen = generate_next_invoice_number('gst', $invoiceDate);
        $invoice_no = $invGen['invoice_no'];
        $invSerial  = $invGen['serial_no'];

        // Fetch active monthly services for this customer
        $stmtCS = $pdo->prepare("SELECT * FROM customer_services WHERE customer_id = ? AND billing_type = 'monthly' AND status = 'active'");
        $stmtCS->execute([$customer_id]);
        $monthlyServices = $stmtCS->fetchAll();

        // Calculate Monthly Retainer Subtotal
        $monthlySubtotal = 0;
        if (!empty($monthlyServices)) {
            foreach ($monthlyServices as $ms) {
                $monthlySubtotal += (float)$ms['amount'];
            }
        } else {
            $monthlySubtotal = (float)$client['monthly_amount'];
        }

        // Check approved extra work to attach
        $extraStmt = $pdo->prepare("SELECT * FROM extra_work WHERE customer_id = ? AND status = 'completed' AND (invoice_id IS NULL OR invoice_id = 0)");
        $extraStmt->execute([$customer_id]);
        $extras = $extraStmt->fetchAll();

        $subtotal = $monthlySubtotal;
        foreach ($extras as $ex) {
            $subtotal += (float)$ex['total_amount'];
        }

        $totalAmount = $subtotal;
        $userId = $_SESSION['user_id'] ?? null;

        // Insert Invoice
        $insInv = $pdo->prepare("INSERT INTO invoices (invoice_no, invoice_type, serial_no, customer_id, invoice_date, due_date, taxable_value, total_amount, amount_due, status, created_by, created_at) VALUES (?, 'gst', ?, ?, ?, ?, ?, ?, ?, 'sent', ?, NOW())");
        $insInv->execute([$invoice_no, $invSerial, $customer_id, $invoiceDate, $dueDate, $subtotal, $totalAmount, $totalAmount, $userId]);
        $invoice_id = $pdo->lastInsertId();

        $insItem = $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, qty, rate, line_total) VALUES (?, ?, 1, ?, ?)");

        // Insert Monthly Services as Line Items
        if (!empty($monthlyServices)) {
            foreach ($monthlyServices as $ms) {
                $itemDesc = $ms['service_name'] . " - $monthName $year";
                $rate = (float)$ms['amount'];
                $insItem->execute([$invoice_id, $itemDesc, $rate, $rate]);
            }
        } else {
            $serviceName = $client['monthly_service_name'] ?: 'Monthly Service Package';
            $itemDesc    = "$serviceName - $monthName $year";
            $rate        = (float)$client['monthly_amount'];
            $insItem->execute([$invoice_id, $itemDesc, $rate, $rate]);
        }

        // Attach Extra Work items
        foreach ($extras as $ex) {
            $insItem->execute([$invoice_id, 'Extra Work: ' . $ex['title'], $ex['qty'] ?? 1, $ex['rate'] ?? $ex['total_amount'], $ex['total_amount']]);
            $pdo->prepare("UPDATE extra_work SET status = 'billed', invoice_id = ? WHERE id = ?")->execute([$invoice_id, $ex['id']]);
        }

        // Record Billing Cycle
        $pdo->prepare("INSERT INTO billing_cycles (customer_id, billing_month, billing_year, invoice_id, status) VALUES (?, ?, ?, ?, 'generated')")
            ->execute([$customer_id, $month, $year, $invoice_id]);

        // Post Invoice to Ledger
        post_invoice_to_ledger($invoice_id);
        sync_invoice_to_subregistry($invoice_id);

        $pdo->commit();
        return $invoice_id;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Handle single client invoice generation via GET
if (isset($_GET['action']) && $_GET['action'] === 'single_gen' && isset($_GET['client_id'])) {
    $cid   = (int)$_GET['client_id'];
    $month = (int)($_GET['month'] ?? date('n'));
    $year  = (int)($_GET['year']  ?? date('Y'));
    try {
        $res = generate_monthly_invoice_for_client($cid, $month, $year);
        if ($res) {
            set_flash('Invoice generated successfully! <a href="../invoices/view.php?id=' . $res . '" class="alert-link">View Invoice</a>', 'success');
        } else {
            set_flash('Invoice generation stopped: Invoice already exists, client status is not active (Completed/Inactive), or auto-invoice is disabled.', 'warning');

        }
    } catch (Exception $e) {
        set_flash('Error generating invoice: ' . $e->getMessage(), 'danger');
    }
    header('Location: monthly_clients.php?month=' . $month . '&year=' . $year);
    exit;
}

// Handle bulk generation trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_all') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $month = (int)($_POST['month'] ?? date('n'));
        $year  = (int)($_POST['year']  ?? date('Y'));

        $monthlyClients = $pdo->query("SELECT id FROM customers WHERE client_type = 'monthly' AND status = 'active' AND auto_invoice = 1")->fetchAll(PDO::FETCH_COLUMN);

        $count = 0;
        foreach ($monthlyClients as $cid) {
            try {
                $res = generate_monthly_invoice_for_client($cid, $month, $year);
                if ($res) $count++;
            } catch (Exception $e) { /* skip individual failures */ }
        }

        set_flash("Monthly billing completed! {$count} invoice(s) processed for " . date('F Y', mktime(0,0,0,$month,1)), 'success');
        header('Location: monthly_clients.php?month=' . $month . '&year=' . $year);
        exit;
    }
}

$selectedMonth = (int)($_GET['month'] ?? date('n'));
$selectedYear = (int)($_GET['year'] ?? date('Y'));

// Fetch all monthly clients with billing cycle status for selected month
$stmt = $pdo->prepare("
    SELECT c.*,
      bc.id AS cycle_id, bc.invoice_id, bc.status AS cycle_status,
      i.invoice_no, i.total_amount AS invoice_total, i.amount_paid, i.status AS inv_status,
      i.invoice_date, i.due_date
    FROM customers c
    LEFT JOIN billing_cycles bc ON bc.customer_id = c.id AND bc.billing_month = ? AND bc.billing_year = ?
    LEFT JOIN invoices i ON i.id = bc.invoice_id
    WHERE c.client_type = 'monthly' AND c.status = 'active'
    ORDER BY c.billing_day ASC, c.name ASC
");
$stmt->execute([$selectedMonth, $selectedYear]);
$monthlyClients = $stmt->fetchAll();
?>

<div class="container-fluid">
  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-arrow-repeat text-primary me-2"></i>Monthly Client Billing Automation</h4>
      <p class="text-muted small mb-0">Automated recurring billing cycles, duplicate prevention, and invoice generation.</p>
    </div>
    <div class="d-flex gap-2">
      <form method="post" onsubmit="return confirm('Generate monthly invoices for all active monthly clients for selected month?');">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="generate_all">
        <input type="hidden" name="month" value="<?= $selectedMonth ?>">
        <input type="hidden" name="year" value="<?= $selectedYear ?>">
        <button type="submit" class="btn btn-success shadow-sm fw-semibold">
          <i class="bi bi-play-circle me-1"></i> Generate All Invoices for <?= date("F Y", mktime(0,0,0,$selectedMonth,1)) ?>
        </button>
      </form>
    </div>
  </div>

  <?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Month Picker & Stats -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
    <div class="card-body p-3">
      <form method="get" class="row g-3 align-items-center">
        <div class="col-md-3">
          <label class="form-label small text-muted mb-1">Select Billing Month</label>
          <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php for($m=1; $m<=12; $m++): ?>
              <option value="<?= $m ?>" <?= $selectedMonth==$m?'selected':'' ?>>
                <?= date("F", mktime(0,0,0,$m,1)) ?>
              </option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small text-muted mb-1">Select Billing Year</label>
          <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php for($y=date("Y"); $y>=2024; $y--): ?>
              <option value="<?= $y ?>" <?= $selectedYear==$y?'selected':'' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-md-6 text-end">
          <span class="badge bg-light text-dark border p-2 me-2">
            Active Monthly Clients: <strong><?= count($monthlyClients) ?></strong>
          </span>
        </div>
      </form>
    </div>
  </div>

  <!-- Monthly Cycle Table -->
  <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light text-muted small uppercase">
          <tr>
            <th class="ps-3">Client</th>
            <th>Monthly Service</th>
            <th>Monthly Amount</th>
            <th>Billing Date</th>
            <th>Cycle Status</th>
            <th>Invoice</th>
            <th>Payment</th>
            <th class="text-center pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($monthlyClients): ?>
            <?php foreach ($monthlyClients as $r): ?>
              <tr>
                <td class="ps-3">
                  <div class="d-flex align-items-center">
                    <?= get_client_logo_html($r, 36) ?>
                    <div>
                      <a href="view.php?id=<?= $r['id'] ?>" class="fw-bold text-dark text-decoration-none d-block">
                        <?= e($r['name']) ?>
                      </a>
                      <small class="text-muted"><?= e($r['contact_person'] ?: 'No Contact') ?></small>
                    </div>
                  </div>
                </td>

                <td><?= e($r['monthly_service_name'] ?: 'Digital Marketing') ?></td>
                <td class="fw-semibold text-dark">₹<?= money($r['monthly_amount']) ?></td>
                <td>
                  <?php
                    $billingDay = (int)($r['billing_day'] ?: 1);
                    // Construct the billing date for the selected month/year
                    $billingDateStr = sprintf('%04d-%02d-%02d', $selectedYear, $selectedMonth, min($billingDay, cal_days_in_month(CAL_GREGORIAN, $selectedMonth, $selectedYear)));
                    $billingDate    = date('d M Y', strtotime($billingDateStr));
                    // Due date: from actual invoice if generated, otherwise calculate
                    if ($r['due_date']) {
                        $dueDate = date('d M Y', strtotime($r['due_date']));
                    } else {
                        $dueDays = (int)($r['due_days'] ?: 10);
                        $dueDate = date('d M Y', strtotime($billingDateStr . ' +' . $dueDays . ' days'));
                    }
                    // Highlight if billing date is today or in the past this month and not yet generated
                    $isOverdue = !$r['invoice_id'] && strtotime($billingDateStr) <= strtotime(date('Y-m-d'));
                  ?>
                  <div>
                    <span class="fw-semibold text-dark d-block">
                      <i class="bi bi-calendar-event me-1 text-primary"></i><?= $billingDate ?>
                    </span>
                    <small class="text-muted">Due: <?= $dueDate ?></small>
                    <?php if ($isOverdue): ?>
                      <br><span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size:0.7rem;">Overdue to generate</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <?php if ($r['invoice_id']): ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Generated</span>
                  <?php else: ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i> Pending</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($r['invoice_no']): ?>
                    <a href="../invoices/view.php?id=<?= $r['invoice_id'] ?>" class="fw-bold text-primary text-decoration-none">
                      <?= e($r['invoice_no']) ?>
                    </a>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($r['inv_status']): ?>
                    <?php
                      $stColor = match($r['inv_status']) {
                          'paid'           => 'success',
                          'partially_paid' => 'warning',
                          'overdue'        => 'danger',
                          default          => 'secondary'
                      };
                    ?>
                    <span class="badge bg-<?= $stColor ?>"><?= strtoupper(str_replace('_',' ',$r['inv_status'])) ?></span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-center pe-3">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                      <i class="bi bi-three-dots-vertical"></i> Actions
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <?php if ($r['invoice_id']): ?>
                        <li>
                          <a href="../invoices/view.php?id=<?= $r['invoice_id'] ?>" class="dropdown-item">
                            <i class="bi bi-eye text-primary me-2"></i> View Invoice
                          </a>
                        </li>
                        <li>
                          <a href="../invoices/download.php?id=<?= $r['invoice_id'] ?>" target="_blank" class="dropdown-item">
                            <i class="bi bi-download text-info me-2"></i> Download PDF
                          </a>
                        </li>
                      <?php else: ?>
                        <li>
                          <a href="?action=single_gen&client_id=<?= $r['id'] ?>&month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>" class="dropdown-item text-success fw-semibold">
                            <i class="bi bi-plus-circle text-success me-2"></i> Generate Invoice
                          </a>
                        </li>
                      <?php endif; ?>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <a href="view.php?id=<?= $r['id'] ?>" class="dropdown-item">
                          <i class="bi bi-person text-secondary me-2"></i> Client Profile
                        </a>
                      </li>
                    </ul>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="8" class="text-center py-5 text-muted">No active monthly clients found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php
    $mStream = (float)$pdo->query("
        SELECT COALESCE(SUM(monthly_amount), 0) 
        FROM customers 
        WHERE client_type = 'monthly' AND status = 'active'
    ")->fetchColumn();

    $mCount = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM customers 
        WHERE client_type = 'monthly' AND status = 'active'
    ")->fetchColumn();

    $mInv = $pdo->query("
        SELECT 
            COALESCE(SUM(i.total_amount), 0) AS billed_total,
            COALESCE(SUM(i.amount_paid), 0) AS received_total,
            COALESCE(SUM(i.amount_due), 0) AS pending_total
        FROM invoices i
        INNER JOIN customers c ON c.id = i.customer_id
        WHERE c.client_type = 'monthly' AND c.status = 'active' AND i.status != 'cancelled'
    ")->fetch();

    $mSummary = [
        'total_count'    => $mCount,
        'stream_total'   => $mStream,
        'billed_total'   => (float)($mInv['billed_total'] ?? 0),
        'received_total' => (float)($mInv['received_total'] ?? 0),
        'pending_total'  => (float)($mInv['pending_total'] ?? 0),
    ];
  ?>

  <!-- Bottom Financial Summary Footer for Monthly Clients -->
  <div class="card border-0 shadow-sm mt-4 mb-4" style="border-radius: 16px; background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #ffffff;">
    <div class="card-body p-4">
      <div class="row g-3 align-items-center text-center text-md-start">
        <div class="col-md-3 border-end border-secondary">
          <small class="text-white-50 text-uppercase fw-bold" style="font-size: 0.75rem; letter-spacing: 0.5px;">Active Monthly Clients</small>
          <h3 class="fw-bold text-white mb-0 mt-1"><?= (int)$mSummary['total_count'] ?> <span class="fs-6 font-normal text-white-50">Clients</span></h3>
        </div>
        <div class="col-md-3 border-end border-secondary">
          <small class="text-info text-uppercase fw-bold" style="font-size: 0.75rem; letter-spacing: 0.5px;">Monthly Retainer Stream</small>
          <h3 class="fw-bold text-info mb-0 mt-1">₹<?= money($mSummary['stream_total']) ?><span class="fs-6 text-white-50 font-normal">/mo</span></h3>
        </div>
        <div class="col-md-3 border-end border-secondary">
          <small class="text-success text-uppercase fw-bold" style="font-size: 0.75rem; letter-spacing: 0.5px;">Total Earned (Collected)</small>
          <h3 class="fw-bold text-success mb-0 mt-1">₹<?= money($mSummary['received_total']) ?></h3>
        </div>
        <div class="col-md-3">
          <small class="text-warning text-uppercase fw-bold" style="font-size: 0.75rem; letter-spacing: 0.5px;">Pending Receivables</small>
          <h3 class="fw-bold text-warning mb-0 mt-1">₹<?= money($mSummary['pending_total']) ?></h3>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>
