<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/ledger_helper.php';
require_login();

$err = '';

// Handle Status Change POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_extra_status') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $ew_id = (int)$_POST['extra_work_id'];
        $new_status = $_POST['new_status'] ?? '';
        if (in_array($new_status, ['requested', 'approved', 'in_progress', 'completed', 'billed'])) {
            $upd = $pdo->prepare("UPDATE extra_work SET status = ? WHERE id = ?");
            $upd->execute([$new_status, $ew_id]);
            set_flash('Extra Work status updated to ' . ucfirst($new_status), 'success');
        }
    }
    header('Location: extra_work.php');
    exit;
}

// Handle Add Extra Work POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'add_extra_work')) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $customer_id    = (int)$_POST['customer_id'];
        $title          = trim($_POST['title']);
        $description    = trim($_POST['description'] ?? '');
        $work_date      = $_POST['work_date'] ?: date('Y-m-d');
        $end_date       = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $total_amount   = (float)($_POST['total_amount'] ?? 0);
        $status         = $_POST['status'] ?? 'completed';
        $billing_method = $_POST['billing_method'] ?? 'separate_invoice';
        
        if ($customer_id <= 0 || empty($title) || $total_amount <= 0) {
            $err = 'Client, Service Name, and Price/Amount are required.';
        } else {
            $pdo->beginTransaction();
            try {
                // 1. Insert into extra_work table
                $stmt = $pdo->prepare("INSERT INTO extra_work (customer_id, title, description, work_date, end_date, qty, rate, total_amount, status, billing_method, created_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, NOW())");
                $stmt->execute([$customer_id, $title, $description, $work_date, $end_date, $total_amount, $total_amount, $status, $billing_method]);
                $extra_work_id = $pdo->lastInsertId();

                // 2. Handle Automated Invoicing Action
                if ($billing_method === 'separate_invoice') {
                    $invGen = generate_next_invoice_number('gst', $work_date);
                    $invoice_no = $invGen['invoice_no'];
                    $invSerial  = $invGen['serial_no'];

                    $dueDate = date('Y-m-d', strtotime($work_date . ' + 10 days'));

                    // Create Separate Invoice
                    $insInv = $pdo->prepare("INSERT INTO invoices (invoice_no, invoice_type, serial_no, customer_id, invoice_date, due_date, taxable_value, total_amount, amount_due, status, created_by, created_at) VALUES (?, 'gst', ?, ?, ?, ?, ?, ?, ?, 'sent', ?, NOW())");
                    $insInv->execute([$invoice_no, $invSerial, $customer_id, $work_date, $dueDate, $total_amount, $total_amount, $total_amount, $_SESSION['user_id'] ?? 1]);
                    $invoice_id = $pdo->lastInsertId();

                    // Line Item
                    $insItem = $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, qty, rate, line_total) VALUES (?, ?, 1, ?, ?)");
                    $insItem->execute([$invoice_id, "Extra Work: " . $title, $total_amount, $total_amount]);

                    // Post to Ledger
                    post_invoice_to_ledger($invoice_id);
                    sync_invoice_to_subregistry($invoice_id);

                    // Update Extra Work status to billed
                    $pdo->prepare("UPDATE extra_work SET status = 'billed', invoice_id = ? WHERE id = ?")->execute([$invoice_id, $extra_work_id]);

                    $pdo->commit();
                    set_flash('Extra Work saved & Separate Invoice <a href="../invoices/view.php?id=' . $invoice_id . '" class="alert-link">' . $invoice_no . '</a> generated successfully!', 'success');

                } elseif ($billing_method === 'current_monthly') {
                    $m = (int)date('n', strtotime($work_date));
                    $y = (int)date('Y', strtotime($work_date));

                    $chk = $pdo->prepare("SELECT i.id, i.taxable_value, i.total_amount, i.amount_due FROM invoices i WHERE i.customer_id = ? AND MONTH(i.invoice_date) = ? AND YEAR(i.invoice_date) = ? AND i.status != 'cancelled' ORDER BY i.id DESC LIMIT 1");
                    $chk->execute([$customer_id, $m, $y]);
                    $currInv = $chk->fetch();

                    if ($currInv) {
                        $invoice_id = $currInv['id'];
                        
                        // Add line item to existing monthly invoice
                        $insItem = $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, qty, rate, line_total) VALUES (?, ?, 1, ?, ?)");
                        $insItem->execute([$invoice_id, "Extra Work: " . $title, $total_amount, $total_amount]);

                        // Recalculate totals
                        $newTaxable = (float)$currInv['taxable_value'] + $total_amount;
                        $newTotal   = (float)$currInv['total_amount'] + $total_amount;
                        $newDue     = (float)$currInv['amount_due'] + $total_amount;

                        $pdo->prepare("UPDATE invoices SET taxable_value = ?, total_amount = ?, amount_due = ? WHERE id = ?")
                            ->execute([$newTaxable, $newTotal, $newDue, $invoice_id]);

                        // Re-post to Ledger
                        post_invoice_to_ledger($invoice_id);

                        // Update Extra Work
                        $pdo->prepare("UPDATE extra_work SET status = 'billed', invoice_id = ? WHERE id = ?")->execute([$invoice_id, $extra_work_id]);

                        $pdo->commit();
                        set_flash('Extra Work saved & added to current monthly Invoice <a href="../invoices/view.php?id=' . $invoice_id . '" class="alert-link">#' . $invoice_id . '</a>! Invoice total updated.', 'success');
                    } else {
                        // Create new monthly invoice with extra work included
                        $custStmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
                        $custStmt->execute([$customer_id]);
                        $client = $custStmt->fetch();

                        $invGen = generate_next_invoice_number('gst', $work_date);
                        $invoice_no = $invGen['invoice_no'];
                        $invSerial  = $invGen['serial_no'];

                        $monthlyAmount = (float)($client['monthly_amount'] ?? 0);
                        $totalAmount   = $monthlyAmount + $total_amount;

                        $dueDays = (int)($client['due_days'] ?: 10);
                        $dueDate = date('Y-m-d', strtotime($work_date . " + {$dueDays} days"));

                        $insInv = $pdo->prepare("INSERT INTO invoices (invoice_no, invoice_type, serial_no, customer_id, invoice_date, due_date, taxable_value, total_amount, amount_due, status, created_by, created_at) VALUES (?, 'gst', ?, ?, ?, ?, ?, ?, ?, 'sent', ?, NOW())");
                        $insInv->execute([$invoice_no, $invSerial, $customer_id, $work_date, $dueDate, $totalAmount, $totalAmount, $totalAmount, $_SESSION['user_id'] ?? 1]);
                        $invoice_id = $pdo->lastInsertId();

                        $insItem = $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, qty, rate, line_total) VALUES (?, ?, 1, ?, ?)");

                        if ($monthlyAmount > 0) {
                            $monthName = date('F', strtotime($work_date));
                            $sName = $client['monthly_service_name'] ?: 'Monthly Service Package';
                            $insItem->execute([$invoice_id, "{$sName} - {$monthName} {$y}", $monthlyAmount, $monthlyAmount]);
                        }

                        $insItem->execute([$invoice_id, "Extra Work: " . $title, $total_amount, $total_amount]);

                        post_invoice_to_ledger($invoice_id);
                        sync_invoice_to_subregistry($invoice_id);

                        $pdo->prepare("UPDATE extra_work SET status = 'billed', invoice_id = ? WHERE id = ?")->execute([$invoice_id, $extra_work_id]);

                        $pdo->commit();
                        set_flash('Extra Work saved & new monthly Invoice <a href="../invoices/view.php?id=' . $invoice_id . '" class="alert-link">' . $invoice_no . '</a> generated with extra work included!', 'success');
                    }
                } else {
                    $pdo->commit();
                    set_flash('Extra Work logged successfully!', 'success');
                }

                header('Location: extra_work.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $err = 'Error saving extra work: ' . $e->getMessage();
            }
        }
    }
}

// Fetch extra work entries
$where = ["1=1"];
$params = [];
if (!empty($_GET['customer_id'])) {
    $where[] = "e.customer_id = ?";
    $params[] = (int)$_GET['customer_id'];
}
if (!empty($_GET['status'])) {
    $where[] = "e.status = ?";
    $params[] = $_GET['status'];
}

$whereSql = implode(" AND ", $where);
$stmt = $pdo->prepare("
    SELECT e.*, c.name AS customer_name, c.client_logo, i.invoice_no AS linked_invoice_no
    FROM extra_work e
    LEFT JOIN customers c ON c.id = e.customer_id
    LEFT JOIN invoices i ON i.id = e.invoice_id
    WHERE $whereSql
    ORDER BY e.work_date DESC, e.id DESC

");
$stmt->execute($params);
$entries = $stmt->fetchAll();

$customers = $pdo->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name ASC")->fetchAll();
?>

<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-plus-square text-primary me-2"></i>Extra Work & Service Management</h4>
      <p class="text-muted small mb-0">Log extra services (e.g. App Dev), auto-add to current monthly bill or generate separate invoice.</p>
    </div>
    <button class="btn btn-primary shadow-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#addExtraWorkModal">
      <i class="bi bi-plus-circle me-1"></i> Log Extra Service / Work
    </button>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
      <i class="bi bi-exclamation-triangle me-2"></i><?= e($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
      <?= $flash['message'] ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Table -->
  <div class="card border-0 shadow-sm" style="border-radius: 14px; overflow: hidden;">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light text-muted small uppercase">
          <tr>
            <th class="ps-3">Work Period</th>
            <th>Client</th>
            <th>Service / Extra Task</th>
            <th>Billing Option</th>
            <th>Price (₹)</th>
            <th>Status</th>
            <th class="pe-3 text-end">Invoice Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($entries): ?>
            <?php foreach ($entries as $r): ?>
              <tr>
                <td class="ps-3 small">
                  <span class="fw-bold text-dark d-block"><?= date('d M Y', strtotime($r['work_date'])) ?></span>
                  <?php if (!empty($r['end_date'])): ?>
                    <small class="text-muted">to <?= date('d M Y', strtotime($r['end_date'])) ?></small>
                  <?php else: ?>
                    <small class="text-muted">Single/Ongoing</small>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="d-flex align-items-center">
                    <?= get_client_logo_html(['name' => $r['customer_name'], 'client_logo' => $r['client_logo']], 32) ?>
                    <a href="../customers/view.php?id=<?= $r['customer_id'] ?>" class="fw-bold text-dark text-decoration-none">
                      <?= e($r['customer_name']) ?>
                    </a>
                  </div>
                </td>

                <td>
                  <span class="fw-bold text-dark d-block"><?= e($r['title']) ?></span>
                  <?php if (!empty($r['description'])): ?>
                    <small class="text-muted"><?= e(substr($r['description'], 0, 50)) ?></small>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($r['billing_method'] === 'separate_invoice'): ?>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="bi bi-receipt me-1"></i> Separate Invoice</span>
                  <?php else: ?>
                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"><i class="bi bi-arrow-repeat me-1"></i> Included in Monthly</span>
                  <?php endif; ?>
                </td>
                <td class="fw-bold text-success fs-6">₹<?= money($r['total_amount']) ?></td>
                <td>
                  <div class="dropdown d-inline-block">
                    <button class="btn btn-sm btn-<?= 
                      $r['status']=='completed'?'success':
                      ($r['status']=='billed'?'info':
                      ($r['status']=='approved'?'primary':'warning'))
                    ?> dropdown-toggle py-1 px-2 fw-semibold" type="button" data-bs-toggle="dropdown" style="font-size:0.75rem;">
                      <?= ucfirst(e($r['status'])) ?>
                    </button>
                    <ul class="dropdown-menu shadow-sm border-0">
                      <li><h6 class="dropdown-header">Update Status</h6></li>
                      <?php foreach (['completed', 'in_progress', 'approved', 'requested'] as $st): ?>
                        <li>
                          <form method="post">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="change_extra_status">
                            <input type="hidden" name="extra_work_id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="new_status" value="<?= $st ?>">
                            <button type="submit" class="dropdown-item"><?= ucfirst($st) ?></button>
                          </form>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </td>
                <td class="pe-3 text-end">
                  <?php if (!empty($r['invoice_id'])): ?>
                    <a href="../invoices/view.php?id=<?= $r['invoice_id'] ?>" class="btn btn-sm btn-light border fw-semibold text-primary">
                      <i class="bi bi-file-earmark-text me-1"></i> View Invoice <?= e($r['linked_invoice_no'] ? '#'.$r['linked_invoice_no'] : '#'.$r['invoice_id']) ?>
                    </a>
                  <?php else: ?>
                    <a href="../invoices/create.php?customer_id=<?= $r['customer_id'] ?>&extra_work_id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-success">
                      <i class="bi bi-receipt me-1"></i> Generate Invoice
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="text-center py-5 text-muted">
                <i class="bi bi-plus-square fs-1 d-block mb-2 text-muted"></i>
                No extra work entries logged. Click "Log Extra Service" to add one.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: Simple Add Extra Work -->
<div class="modal fade" id="addExtraWorkModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_extra_work">
        
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-plus-square text-primary me-2"></i>Log Extra Work / Service</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        
        <div class="modal-body">
          <div class="row g-3">
            <!-- 1. Select Customer -->
            <div class="col-md-6">
              <label class="form-label fw-semibold">Select Customer / Client <span class="text-danger">*</span></label>
              <select name="customer_id" class="form-select form-select-lg" required>
                <option value="">-- Select Client --</option>
                <?php foreach ($customers as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- 2. Price / Amount -->
            <div class="col-md-6">
              <label class="form-label fw-semibold">Service Price / Amount (₹) <span class="text-danger">*</span></label>
              <div class="input-group input-group-lg">
                <span class="input-group-text fw-bold">₹</span>
                <input type="number" step="0.01" name="total_amount" class="form-control fw-bold text-success" placeholder="50000.00" required>
              </div>
            </div>

            <!-- 3. Service Name -->
            <div class="col-md-12">
              <label class="form-label fw-semibold">Extra Service Name / Work Title <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control form-control-lg" placeholder="e.g. Mobile App Development / Landing Page Design" required>
            </div>

            <!-- 4. Start & End Dates -->
            <div class="col-md-6">
              <label class="form-label fw-medium">Start Work Date <span class="text-danger">*</span></label>
              <input type="date" name="work_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">End Work Date <small class="text-muted">(Optional)</small></label>
              <input type="date" name="end_date" class="form-control">
            </div>

            <!-- 5. Status & Payment Option -->
            <div class="col-md-6">
              <label class="form-label fw-medium">Work Status</label>
              <select name="status" class="form-select">
                <option value="completed" selected>Completed</option>
                <option value="in_progress">In Progress</option>
                <option value="approved">Approved</option>
                <option value="requested">Requested</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-medium">Payment & Invoice Option <span class="text-danger">*</span></label>
              <select name="billing_method" class="form-select bg-light fw-bold text-primary">
                <option value="separate_invoice" selected>📄 Create Separate Invoice Now</option>
                <option value="current_monthly">🔄 Include in Current Month's Invoice (Combine)</option>
              </select>
            </div>

            <!-- 6. Optional Description -->
            <div class="col-md-12">
              <label class="form-label fw-medium">Service Notes / Details <small class="text-muted">(Optional)</small></label>
              <textarea name="description" class="form-control" rows="2" placeholder="e.g. SMM Client ordered extra Android App Development for 50k..."></textarea>
            </div>
          </div>
        </div>

        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-lg fw-semibold shadow-sm">
            <i class="bi bi-check-circle me-1"></i> Save & Generate Invoice
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>
