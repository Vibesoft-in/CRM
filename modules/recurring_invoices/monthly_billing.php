<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/ledger_helper.php';
require_login();

// Selected month (format YYYY-MM)
$selectedMonth = $_GET['month'] ?? date('Y-m');
$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status_filter'] ?? '');

$year = (int)date('Y', strtotime($selectedMonth . '-01'));
$monthNum = (int)date('m', strtotime($selectedMonth . '-01'));

// Handle Bulk & Single Invoice Generation POST
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $action = $_POST['action'];

        if ($action === 'generate_single_monthly') {
            $customer_id  = (int)$_POST['customer_id'];
            $billing_type = $_POST['billing_type'] ?? 'full'; // 'full' or 'prorata'
            $inv_date     = $_POST['invoice_date'] ?: date('Y-m-d');
            $due_date     = $_POST['due_date'] ?: date('Y-m-d', strtotime('+10 days'));
            $custom_amt   = (float)($_POST['custom_amount'] ?? 0);
            $service_note = trim($_POST['service_note'] ?? '');

            $cStmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $cStmt->execute([$customer_id]);
            $client = $cStmt->fetch();

            if ($client && $custom_amt > 0) {
                // Generate Invoice No
                $invGen = generate_next_invoice_number('gst', $inv_date);
                $invNo  = $invGen['invoice_no'];
                $serial = $invGen['serial_no'];

                $desc = !empty($service_note) ? $service_note : ($client['monthly_service_name'] ?: 'Monthly Retainer Service');

                $ins = $pdo->prepare("INSERT INTO invoices (invoice_no, invoice_type, serial_no, invoice_date, payment_mode, customer_id, place_of_supply, notes, taxable_value, tax_total, total_amount, status, due_date, amount_paid, amount_due, created_by, created_at) VALUES (?, 'gst', ?, ?, 'Pending', ?, '', ?, ?, 0, ?, 'sent', ?, 0, ?, ?, NOW())");
                $ins->execute([
                    $invNo,
                    $serial,
                    $inv_date,
                    $customer_id,
                    $desc,
                    $custom_amt,
                    $custom_amt,
                    $due_date,
                    $custom_amt,
                    $_SESSION['user_id'] ?? 1
                ]);
                $invId = $pdo->lastInsertId();

                // Insert Item
                $insItem = $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, qty, rate, line_total, tax_rate) VALUES (?, ?, 1, ?, ?, 0)");
                $insItem->execute([$invId, $desc, $custom_amt, $custom_amt]);

                post_invoice_to_ledger($invId);
                sync_invoice_to_subregistry($invId);

                set_flash("Monthly invoice $invNo generated successfully for " . e($client['name']), 'success');
                header("Location: monthly_billing.php?month=$selectedMonth");
                exit;
            }
        } elseif ($action === 'bulk_generate_due') {
            // Bulk generate for all active monthly clients where period has completed (cycleEnd <= today)
            $monthlyClients = $pdo->query("SELECT * FROM customers WHERE client_type = 'monthly' AND status = 'active' AND auto_invoice = 1")->fetchAll();
            $generatedCount = 0;

            foreach ($monthlyClients as $client) {
                $bDay = (int)($client['billing_day'] ?: 1);
                $cycleEndStr = sprintf('%04d-%02d-%02d', $year, $monthNum, min($bDay, 28));
                $cycleStartStr = date('Y-m-d', strtotime('-1 month', strtotime($cycleEndStr)));

                // Check if invoice already exists
                $checkInv = $pdo->prepare("SELECT id FROM invoices WHERE customer_id = ? AND (invoice_date BETWEEN ? AND ? OR notes LIKE ?) AND status != 'cancelled'");
                $checkInv->execute([$client['id'], date('Y-m-d', strtotime('-5 days', strtotime($cycleEndStr))), date('Y-m-d', strtotime('+5 days', strtotime($cycleEndStr))), "%" . date('d M', strtotime($cycleStartStr)) . "%"]);

                if (!$checkInv->fetch() && (float)$client['monthly_amount'] > 0) {
                    $invGen = generate_next_invoice_number('gst', $cycleEndStr);
                    $invNo  = $invGen['invoice_no'];
                    $serial = $invGen['serial_no'];

                    $desc = ($client['monthly_service_name'] ?: 'Monthly Retainer Service') . " (" . date('d M Y', strtotime($cycleStartStr)) . " - " . date('d M Y', strtotime($cycleEndStr)) . ")";
                    $amt = (float)$client['monthly_amount'];
                    $dueDate = date('Y-m-d', strtotime("+10 days", strtotime($cycleEndStr)));

                    $ins = $pdo->prepare("INSERT INTO invoices (invoice_no, invoice_type, serial_no, invoice_date, payment_mode, customer_id, place_of_supply, notes, taxable_value, tax_total, total_amount, status, due_date, amount_paid, amount_due, created_by, created_at) VALUES (?, 'gst', ?, ?, 'Pending', ?, '', ?, ?, 0, ?, 'sent', ?, 0, ?, ?, NOW())");
                    $ins->execute([$invNo, $serial, $cycleEndStr, $client['id'], $desc, $amt, $amt, $dueDate, $amt, $_SESSION['user_id'] ?? 1]);
                    $invId = $pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, qty, rate, line_total, tax_rate) VALUES (?, ?, 1, ?, ?, 0)")->execute([$invId, $desc, $amt, $amt]);
                    post_invoice_to_ledger($invId);
                    sync_invoice_to_subregistry($invId);

                    $generatedCount++;
                }
            }
            set_flash("Bulk Invoicing completed! $generatedCount invoice(s) generated.", 'success');
            header("Location: monthly_billing.php?month=$selectedMonth");
            exit;
        }
    }
}

// Fetch active monthly clients
$whereClients = ["c.client_type = 'monthly'", "c.status = 'active'"];
$paramsClients = [];

if (!empty($search)) {
    $whereClients[] = "(c.name LIKE ? OR c.monthly_service_name LIKE ?)";
    $term = "%$search%";
    $paramsClients = [$term, $term];
}

$whereSql = implode(" AND ", $whereClients);
$stmtClients = $pdo->prepare("SELECT c.* FROM customers c WHERE $whereSql ORDER BY c.billing_day ASC, c.name ASC");
$stmtClients->execute($paramsClients);
$clients = $stmtClients->fetchAll();

// Calculate Monthly Statistics & Pro-Rata Earnings
$totalMonthlyStream = 0;
$totalInvoicedMonth = 0;
$totalPaidMonth     = 0;
$totalPendingMonth  = 0;
$totalProrataEarned = 0;

$today = date('Y-m-d');
$todayTs = strtotime($today);

$matrix = [];
foreach ($clients as $c) {
    $monthlyAmt = (float)($c['monthly_amount'] ?? 0);
    $totalMonthlyStream += $monthlyAmt;

    $bDay = (int)($c['billing_day'] ?: 1);
    
    // Cycle dates for selected month
    $cycleEndStr   = sprintf('%04d-%02d-%02d', $year, $monthNum, min($bDay, 28));
    $cycleEndTs    = strtotime($cycleEndStr);
    $cycleStartStr = date('Y-m-d', strtotime('-1 month', $cycleEndTs));

    // Check existing invoice for this period
    $invStmt = $pdo->prepare("
        SELECT * FROM invoices 
        WHERE customer_id = ? 
          AND status != 'cancelled'
          AND (invoice_date BETWEEN ? AND ? OR notes LIKE ?)
        ORDER BY invoice_date DESC LIMIT 1
    ");
    $invStmt->execute([
        $c['id'],
        date('Y-m-d', strtotime('-5 days', $cycleEndTs)),
        date('Y-m-d', strtotime('+5 days', $cycleEndTs)),
        "%" . date('d M', strtotime($cycleStartStr)) . "%"
    ]);
    $invoice = $invStmt->fetch();

    // PRO-RATA CALCULATION: From latest invoice date (or cycle start) to today
    $latestInvDate = !empty($invoice['invoice_date']) ? $invoice['invoice_date'] : $cycleEndStr;
    $latestInvTs   = strtotime($latestInvDate);

    // Active cycle from latest invoice date to 1 month later
    $nextInvDate = date('Y-m-d', strtotime('+1 month', $latestInvTs));
    $nextInvTs   = strtotime($nextInvDate);

    $daysInOngoingCycle = max(1, (int)(($nextInvTs - $latestInvTs) / 86400));

    if ($todayTs < $latestInvTs) {
        $daysElapsedTillToday = 0;
    } elseif ($todayTs >= $nextInvTs) {
        $daysElapsedTillToday = $daysInOngoingCycle;
    } else {
        $daysElapsedTillToday = max(0, (int)(($todayTs - $latestInvTs) / 86400));
    }

    $prorataRatio  = min(1.0, $daysElapsedTillToday / $daysInOngoingCycle);
    $prorataEarned = round($monthlyAmt * $prorataRatio, 2);
    $totalProrataEarned += $prorataEarned;

    $invoicedAmt = 0;
    $paidAmt     = 0;
    $dueAmt      = 0;
    $statusText  = 'DUE NOW';
    $statusClass = 'bg-primary text-white';

    if ($invoice) {
        $invoicedAmt = (float)$invoice['total_amount'];
        $paidAmt     = (float)$invoice['amount_paid'];
        $dueAmt      = (float)$invoice['amount_due'];

        $totalInvoicedMonth += $invoicedAmt;
        $totalPaidMonth     += $paidAmt;
        $totalPendingMonth  += $dueAmt;

        if ($invoice['status'] === 'paid') {
            $statusText  = 'PAID (FULL)';
            $statusClass = 'bg-success text-white';
        } elseif ($invoice['status'] === 'partially_paid') {
            $statusText  = 'PARTIALLY PAID';
            $statusClass = 'bg-warning text-dark';
        } else {
            $statusText  = 'INVOICED (PENDING)';
            $statusClass = 'bg-danger text-white';
        }
    } else {
        if ($todayTs < $cycleEndTs) {
            $statusText  = 'DUE ON ' . date('d M', $cycleEndTs);
            $statusClass = 'bg-light text-dark border';
        }
    }

    // Filter by status if set
    if ($statusFilter !== '') {
        if ($statusFilter === 'paid' && strpos($statusText, 'PAID') === false) continue;
        if ($statusFilter === 'pending' && strpos($statusText, 'PENDING') === false) continue;
        if ($statusFilter === 'due' && strpos($statusText, 'DUE') === false) continue;
    }

    $matrix[] = [
        'client'                 => $c,
        'cycle_start'            => $cycleStartStr,
        'cycle_end'              => $cycleEndStr,
        'latest_inv_date'        => $latestInvDate,
        'days_elapsed_today'     => $daysElapsedTillToday,
        'days_in_cycle'          => $daysInOngoingCycle,
        'monthly_amount'         => $monthlyAmt,
        'prorata_earned'         => $prorataEarned,
        'invoice'                => $invoice,
        'invoiced_amount'        => $invoicedAmt,
        'paid_amount'            => $paidAmt,
        'due_amount'             => $dueAmt,
        'status_text'            => $statusText,
        'status_class'           => $statusClass
    ];
}
?>

<div class="container-fluid py-2">
  <!-- Page Header -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px; background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);">
    <div class="card-body p-4">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
          <h4 class="fw-bold text-dark mb-1">
            <i class="bi bi-calendar2-check text-primary me-2"></i>Monthly Retainer Billing Manager
          </h4>
          <p class="text-muted small mb-0">
            Track client billing dates, pro-rata earnings from invoice date to today, and generate monthly invoices.
          </p>
        </div>
        <div class="d-flex gap-2">
          <form method="post" onsubmit="return confirm('Generate End-of-Month invoices for all due clients?');" class="d-inline">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="bulk_generate_due">
            <button type="submit" class="btn btn-success fw-semibold px-3 py-2 shadow-sm rounded-pill">
              <i class="bi bi-lightning-charge-fill me-1"></i> Auto-Generate Due Invoices
            </button>
          </form>
          <a href="../invoices/create.php" class="btn btn-outline-primary fw-semibold px-3 py-2 rounded-pill">
            <i class="bi bi-plus-circle me-1"></i> Manual Invoice
          </a>
        </div>
      </div>
    </div>
  </div>

  <?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm mb-4" role="alert" style="border-radius: 12px;">
      <i class="bi bi-check-circle-fill me-2 text-success"></i><?= $flash['message'] ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Summary Cards -->
  <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4">
    <div class="col">
      <div class="card border-0 shadow-sm p-3 rounded-4 bg-white border-start border-4 border-primary h-100">
        <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">Total Invoiced (Month)</small>
        <h3 class="fw-bold text-primary mb-0 mt-1">₹<?= money($totalInvoicedMonth) ?></h3>
        <small class="text-muted mt-1 d-block"><i class="bi bi-file-earmark-text text-primary me-1"></i>Total Amount Invoiced</small>
      </div>
    </div>
    <div class="col">
      <div class="card border-0 shadow-sm p-3 rounded-4 bg-white border-start border-4 border-success h-100">
        <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">Payment Received</small>
        <h3 class="fw-bold text-success mb-0 mt-1">₹<?= money($totalPaidMonth) ?></h3>
        <small class="text-muted mt-1 d-block"><i class="bi bi-check-circle-fill text-success me-1"></i>Paid Amount Collected</small>
      </div>
    </div>
    <div class="col">
      <div class="card border-0 shadow-sm p-3 rounded-4 bg-white border-start border-4 border-warning h-100">
        <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">Pending Receivables</small>
        <h3 class="fw-bold text-warning mb-0 mt-1">₹<?= money($totalPendingMonth) ?></h3>
        <small class="text-muted mt-1 d-block"><i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>Unpaid Invoice Balance</small>
      </div>
    </div>
    <div class="col">
      <div class="card border-0 shadow-sm p-3 rounded-4 bg-white border-start border-4 border-secondary h-100">
        <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">Active Retainer Stream</small>
        <h3 class="fw-bold text-dark mb-0 mt-1">₹<?= money($totalMonthlyStream) ?><span class="fs-6 text-muted font-normal">/mo</span></h3>
        <small class="text-muted mt-1 d-block"><i class="bi bi-people-fill text-secondary me-1"></i><?= count($clients) ?> Active Clients</small>
      </div>
    </div>
    <div class="col">
      <div class="card border-0 shadow-sm p-3 rounded-4 bg-white border-start border-4 border-info h-100">
        <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">Pro-Rata Earned</small>
        <h3 class="fw-bold text-info mb-0 mt-1">₹<?= money($totalProrataEarned) ?></h3>
        <small class="text-muted mt-1 d-block"><i class="bi bi-clock-history text-info me-1"></i>From Inv. Date to Today</small>
      </div>
    </div>
  </div>


  <!-- Filters & Controls Card -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px;">
    <div class="card-body py-3">
      <form method="get" class="row g-3 align-items-center">
        <div class="col-md-3">
          <label class="form-label small fw-semibold text-muted mb-1">Billing Month</label>
          <input type="month" name="month" class="form-control rounded-3" value="<?= e($selectedMonth) ?>" onchange="this.form.submit()">
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold text-muted mb-1">Search Client / Service</label>
          <div class="input-group">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" name="q" class="form-control border-start-0" placeholder="Search client name or package..." value="<?= e($search) ?>">
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold text-muted mb-1">Status Filter</label>
          <select name="status_filter" class="form-select rounded-3" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid (Full)</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Invoiced (Pending)</option>
            <option value="due" <?= $statusFilter === 'due' ? 'selected' : '' ?>>Due for Generation</option>
          </select>
        </div>
        <div class="col-md-2 d-flex align-items-end gap-1 mt-auto">
          <button type="submit" class="btn btn-primary w-100 rounded-3"><i class="bi bi-funnel me-1"></i> Filter</button>
          <?php if ($search || $statusFilter || $selectedMonth !== date('Y-m')): ?>
            <a href="monthly_billing.php" class="btn btn-light border rounded-3" title="Reset Filters"><i class="bi bi-x-circle"></i></a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Retainer Invoicing Matrix Table -->
  <div class="card border-0 shadow-sm" style="border-radius: 16px; overflow: hidden;">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
      <h6 class="fw-bold mb-0 text-dark">
        <i class="bi bi-journal-text text-primary me-2"></i>Monthly Retainer Invoicing Matrix (<?= date('F Y', strtotime($selectedMonth . '-01')) ?>)
      </h6>
      <span class="badge bg-light text-muted border font-monospace"><i class="bi bi-info-circle me-1 text-primary"></i>Pro-Rata = Earned from Invoice Date to Today</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light small text-uppercase">
          <tr>
            <th class="ps-3 py-3">Client & Service Package</th>
            <th class="py-3">Invoice Date</th>
            <th class="text-end py-3">Full Month Rate</th>
            <th class="text-end py-3">Earned Till Today (Pro-Rata)</th>
            <th class="text-end py-3">Invoiced</th>
            <th class="text-end py-3">Paid (Received)</th>
            <th class="text-end py-3">Pending Due</th>
            <th class="text-center py-3">Billing Status</th>
            <th class="text-end pe-3 py-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($matrix): ?>
            <?php foreach ($matrix as $row): 
              $c   = $row['client'];
              $inv = $row['invoice'];
            ?>
              <tr>
                <td class="ps-3">
                  <a href="../customers/view.php?id=<?= $c['id'] ?>&tab=invoices" class="fw-bold text-dark text-decoration-none d-block fs-6">
                    <?= e($c['name']) ?>
                  </a>
                  <small class="text-muted"><i class="bi bi-box-seam me-1 text-primary"></i><?= e($c['monthly_service_name'] ?: 'Monthly Service Package') ?></small>
                </td>
                <td>
                  <span class="fw-bold text-primary d-block" style="font-size: 0.9rem;">
                    <?= date('d M Y', strtotime($row['latest_inv_date'])) ?>
                  </span>
                  <small class="text-muted"><i class="bi bi-calendar-event me-1"></i>Billing Day: <strong><?= e($c['billing_day'] ?: 1) ?>th</strong></small>
                </td>
                <td class="text-end fw-bold text-dark fs-6">₹<?= money($row['monthly_amount']) ?></td>
                <td class="text-end">
                  <span class="fw-bold text-info fs-6 d-block">₹<?= money($row['prorata_earned']) ?></span>
                  <small class="text-muted"><?= $row['days_elapsed_today'] ?> days elapsed (from <?= date('d M', strtotime($row['latest_inv_date'])) ?>)</small>
                </td>
                <td class="text-end fw-semibold">
                  <?= $row['invoiced_amount'] > 0 ? '₹' . money($row['invoiced_amount']) : '<span class="text-muted">—</span>' ?>
                </td>
                <td class="text-end text-success fw-bold">
                  <?= $row['paid_amount'] > 0 ? '₹' . money($row['paid_amount']) : '<span class="text-muted">—</span>' ?>
                </td>
                <td class="text-end text-danger fw-bold">
                  <?= $row['due_amount'] > 0 ? '₹' . money($row['due_amount']) : '<span class="text-muted">₹0.00</span>' ?>
                </td>
                <td class="text-center">
                  <span class="badge <?= $row['status_class'] ?> px-3 py-2 rounded-pill fw-semibold">
                    <?= $row['status_text'] ?>
                  </span>
                </td>
                <td class="text-end pe-3">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                      <i class="bi bi-three-dots-vertical"></i> Actions
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <?php if ($inv): ?>
                        <li>
                          <a href="../invoices/view.php?id=<?= $inv['id'] ?>" class="dropdown-item">
                            <i class="bi bi-eye text-primary me-2"></i> View Invoice
                          </a>
                        </li>
                        <?php if ($row['due_amount'] > 0): ?>
                          <li>
                            <a href="../invoices/record_payment.php?customer_id=<?= $c['id'] ?>&invoice_id=<?= $inv['id'] ?>" class="dropdown-item">
                              <i class="bi bi-cash-coin text-success me-2"></i> Record Payment
                            </a>
                          </li>
                        <?php endif; ?>
                        <li>
                          <a href="../customers/view.php?id=<?= $c['id'] ?>&tab=invoices" class="dropdown-item">
                            <i class="bi bi-person text-secondary me-2"></i> View Client Profile
                          </a>
                        </li>
                      <?php else: ?>
                        <li>
                          <button type="button" class="dropdown-item text-primary fw-semibold gen-invoice-modal-btn"
                                  data-client='<?= json_encode([
                                    'id' => $c['id'],
                                    'name' => $c['name'],
                                    'monthly_amount' => $row['monthly_amount'],
                                    'prorata_earned' => $row['prorata_earned'],
                                    'service_name' => $c['monthly_service_name'],
                                    'cycle_start' => date('d M Y', strtotime($row['cycle_start'])),
                                    'cycle_end' => date('d M Y', strtotime($row['cycle_end'])),
                                    'inv_date' => $row['latest_inv_date']
                                  ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                            <i class="bi bi-file-earmark-plus text-primary me-2"></i> Generate Invoice
                          </button>
                        </li>
                        <li>
                          <a href="../customers/view.php?id=<?= $c['id'] ?>" class="dropdown-item">
                            <i class="bi bi-person text-secondary me-2"></i> View Client Profile
                          </a>
                        </li>
                      <?php endif; ?>
                    </ul>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="9" class="text-center py-5 text-muted">No monthly retainer clients match the criteria for this period.</td>
            </tr>
          <?php endif; ?>
        </tbody>
        <?php if ($matrix): ?>
        <tfoot class="table-light border-top border-2">
          <tr class="fw-bold">
            <td colspan="2" class="ps-3 py-3 text-dark">
              <i class="bi bi-calculator-fill text-primary me-2"></i>Total (<?= count($matrix) ?> Clients)
            </td>
            <td class="text-end py-3 text-dark">₹<?= money($totalMonthlyStream) ?></td>
            <td class="text-end py-3 text-info">₹<?= money($totalProrataEarned) ?></td>
            <td class="text-end py-3 text-primary">₹<?= money($totalInvoicedMonth) ?></td>
            <td class="text-end py-3 text-success">₹<?= money($totalPaidMonth) ?></td>
            <td class="text-end py-3 text-danger">₹<?= money($totalPendingMonth) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<!-- Single Client Monthly Invoice Generation Modal -->
<div class="modal fade" id="generateMonthlyInvoiceModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="generate_single_monthly">
        <input type="hidden" name="customer_id" id="modal_customer_id">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-file-earmark-plus text-primary me-2"></i>Generate Monthly Invoice</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="p-3 bg-light rounded-3 mb-3 border">
            <h6 class="fw-bold text-dark mb-1" id="modal_client_name">—</h6>
            <small class="text-muted d-block" id="modal_cycle_dates">—</small>
          </div>

          <div class="mb-3">
            <label class="form-label fw-medium">Invoice Billing Option <span class="text-danger">*</span></label>
            <div class="form-check p-3 border rounded mb-2 bg-white">
              <input class="form-check-input" type="radio" name="billing_option" id="optFullMonth" value="full" checked onchange="toggleInvoiceOption()">
              <label class="form-check-label fw-bold text-dark" for="optFullMonth">
                Full Month Completed Invoice — ₹<span id="label_full_amount">0.00</span>
              </label>
              <small class="text-muted d-block">Bill complete agreed monthly retainer rate for the full 1-month service period.</small>
            </div>
            <div class="form-check p-3 border rounded bg-white">
              <input class="form-check-input" type="radio" name="billing_option" id="optProrata" value="prorata" onchange="toggleInvoiceOption()">
              <label class="form-check-label fw-bold text-info" for="optProrata">
                Pro-Rata Earned Till Today — ₹<span id="label_prorata_amount">0.00</span>
              </label>
              <small class="text-muted d-block">Bill calculated pro-rata amount earned based on exact days elapsed from invoice date to today.</small>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-medium">Invoice Amount (₹) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="custom_amount" id="modal_custom_amount" class="form-control form-control-lg fw-bold text-primary" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Invoice Generation Date</label>
              <input type="date" name="invoice_date" id="modal_invoice_date" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Payment Due Date</label>
              <input type="date" name="due_date" id="modal_due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+10 days')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Invoice Description / Service Item</label>
              <input type="text" name="service_note" id="modal_service_note" class="form-control" placeholder="Service description...">
            </div>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-check-circle me-1"></i> Generate & Post Invoice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
let clientData = {};

document.querySelectorAll('.gen-invoice-modal-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    clientData = JSON.parse(this.getAttribute('data-client'));
    document.getElementById('modal_customer_id').value = clientData.id;
    document.getElementById('modal_client_name').textContent = clientData.name;
    document.getElementById('modal_cycle_dates').textContent = 'Completed Period: ' + clientData.cycle_start + ' to ' + clientData.cycle_end;
    
    document.getElementById('label_full_amount').textContent = parseFloat(clientData.monthly_amount).toFixed(2);
    document.getElementById('label_prorata_amount').textContent = parseFloat(clientData.prorata_earned).toFixed(2);

    document.getElementById('optFullMonth').checked = true;
    document.getElementById('modal_custom_amount').value = parseFloat(clientData.monthly_amount).toFixed(2);
    document.getElementById('modal_invoice_date').value = clientData.inv_date || '<?= date('Y-m-d') ?>';
    document.getElementById('modal_service_note').value = (clientData.service_name || 'Monthly Retainer Service') + ' (' + clientData.cycle_start + ' - ' + clientData.cycle_end + ')';

    const modal = new bootstrap.Modal(document.getElementById('generateMonthlyInvoiceModal'));
    modal.show();
  });
});

function toggleInvoiceOption() {
  const isProrata = document.getElementById('optProrata').checked;
  if (isProrata) {
    document.getElementById('modal_custom_amount').value = parseFloat(clientData.prorata_earned).toFixed(2);
  } else {
    document.getElementById('modal_custom_amount').value = parseFloat(clientData.monthly_amount).toFixed(2);
  }
}
</script>

<?php include '../../includes/footer.php'; ?>
