<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/vault_helper.php';
require_login();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) die("Invalid Client ID");

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) die("Client not found");

// Financial stats
$stmt = $pdo->prepare("SELECT SUM(total_amount) FROM invoices WHERE customer_id = ? AND status != 'cancelled'");
$stmt->execute([$id]);
$billed = (float)($stmt->fetchColumn() ?: 0);

$stmt = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE customer_id = ?");
$stmt->execute([$id]);
$received = (float)($stmt->fetchColumn() ?: 0);
$pending = max(0, $billed - $received);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE customer_id = ?");
$stmt->execute([$id]);
$invCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE customer_id = ?");
$stmt->execute([$id]);
$projCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT payment_date, amount FROM payments WHERE customer_id = ? ORDER BY payment_date DESC LIMIT 1");
$stmt->execute([$id]);
$lastPay = $stmt->fetch();

// Sub-data for tabs
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE customer_id = ? ORDER BY invoice_date DESC, id DESC");
$stmt->execute([$id]);
$invoices = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM payments WHERE customer_id = ? ORDER BY payment_date DESC, id DESC");
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM projects WHERE customer_id = ? ORDER BY start_date DESC, id DESC");
$stmt->execute([$id]);
$projects = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM extra_work WHERE customer_id = ? ORDER BY work_date DESC, id DESC");
$stmt->execute([$id]);
$extraWorks = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM credentials WHERE customer_id = ? ORDER BY platform ASC");
$stmt->execute([$id]);
$credentials = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM client_ledger WHERE customer_id = ? ORDER BY entry_date ASC, id ASC");
$stmt->execute([$id]);
$ledger = $stmt->fetchAll();

$err = '';

// Handle Overview Notes POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_overview_notes') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $overview_notes = trim($_POST['overview_notes'] ?? '');
        $upd = $pdo->prepare("UPDATE customers SET overview_notes = ? WHERE id = ?");
        $upd->execute([$overview_notes, $id]);
        set_flash('Client overview notes updated!', 'success');
        header("Location: view.php?id=$id&tab=" . ($_GET['tab'] ?? 'overview'));
        exit;
    }
}

// Handle Quotation Upload POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_quotation') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $title          = trim($_POST['title'] ?? '');
        $quotation_no   = trim($_POST['quotation_no'] ?? '');
        $amount         = (float)($_POST['amount'] ?? 0);
        $quotation_type = $_POST['quotation_type'] ?? 'sent';
        $status         = $_POST['status'] ?? 'approved';
        $quotation_date = $_POST['quotation_date'] ?: date('Y-m-d');
        $scope_of_work  = trim($_POST['scope_of_work'] ?? '');

        if (empty($title)) {
            $err = 'Quotation Title is required.';
        } else {
            $filePath = null;
            if (!empty($_FILES['quotation_file']['name'])) {
                $qUploadDir = '../../assets/uploads/quotations/';
                if (!is_dir($qUploadDir)) {
                    @mkdir($qUploadDir, 0777, true);
                }
                $ext = strtolower(pathinfo($_FILES['quotation_file']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'])) {
                    $err = 'Only PDF, DOC, DOCX, JPG, and PNG files are allowed.';
                } else {
                    $fileName = 'QUOT_' . $id . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['quotation_file']['tmp_name'], $qUploadDir . $fileName)) {
                        $filePath = 'assets/uploads/quotations/' . $fileName;
                    }
                }
            }

            if (empty($err)) {
                $insQ = $pdo->prepare("INSERT INTO client_quotations (
                    customer_id, quotation_no, title, amount, scope_of_work, quotation_type, status, file_path, quotation_date, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $insQ->execute([$id, $quotation_no, $title, $amount, $scope_of_work, $quotation_type, $status, $filePath, $quotation_date, $_SESSION['user_id'] ?? 1]);

                set_flash('Client Quotation uploaded successfully!', 'success');
                header("Location: view.php?id=$id&tab=quotations");
                exit;
            }
        }
    }
}

// Handle Delete Quotation POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_quotation') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $q_id = intval($_POST['quotation_id']);
        $qStmt = $pdo->prepare("SELECT file_path FROM client_quotations WHERE id = ? AND customer_id = ?");
        $qStmt->execute([$q_id, $id]);
        $qRec = $qStmt->fetch();
        if ($qRec) {
            if (!empty($qRec['file_path']) && file_exists('../../' . $qRec['file_path'])) {
                @unlink('../../' . $qRec['file_path']);
            }
            $pdo->prepare("DELETE FROM client_quotations WHERE id = ?")->execute([$q_id]);
            set_flash('Quotation deleted.', 'info');
        }
    }
    header("Location: view.php?id=$id&tab=quotations");
    exit;
}

// Handle Update Quotation Status POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_quotation_status') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $q_id   = intval($_POST['quotation_id']);
        $status = $_POST['status'];
        $updQ = $pdo->prepare("UPDATE client_quotations SET status = ? WHERE id = ? AND customer_id = ?");
        $updQ->execute([$status, $q_id, $id]);
        set_flash('Quotation status updated!', 'success');
    }
    header("Location: view.php?id=$id&tab=quotations");
    exit;
}

// Handle Edit Project POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_project') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $project_id       = (int)$_POST['project_id'];
        $project_name     = trim($_POST['project_name']);
        $project_type     = $_POST['project_type'] ?? 'Fixed';
        $start_date       = $_POST['start_date'] ?: date('Y-m-d');
        $end_date         = $_POST['end_date'] ?: null;
        $agreed_amount    = (float)($_POST['agreed_amount'] ?? 0);
        $advance_required = (float)($_POST['advance_required'] ?? 0);
        $status           = $_POST['status'] ?? 'Active';
        $description      = trim($_POST['description'] ?? '');

        if ($project_id > 0 && !empty($project_name)) {
            $upd = $pdo->prepare("UPDATE projects SET project_name = ?, project_type = ?, start_date = ?, end_date = ?, agreed_amount = ?, advance_required = ?, status = ?, description = ? WHERE id = ? AND customer_id = ?");
            $upd->execute([$project_name, $project_type, $start_date, $end_date, $agreed_amount, $advance_required, $status, $description, $project_id, $id]);
            set_flash('Project updated successfully!', 'success');
        }
    }
    header("Location: view.php?id=$id&tab=projects");
    exit;
}

// Handle Delete Project POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_project') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $project_id = (int)$_POST['project_id'];
        $pdo->prepare("DELETE FROM projects WHERE id = ? AND customer_id = ?")->execute([$project_id, $id]);
        set_flash('Project deleted successfully.', 'info');
    }
    header("Location: view.php?id=$id&tab=projects");
    exit;
}

// Handle Quick Update Invoice (Amount & Status) POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_update_invoice') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $inv_id       = (int)$_POST['invoice_id'];
        $total_amount = (float)($_POST['total_amount'] ?? 0);
        $new_status   = trim($_POST['status'] ?? 'sent');
        $due_date     = $_POST['due_date'] ?: null;

        $stmtInv = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND customer_id = ?");
        $stmtInv->execute([$inv_id, $id]);
        $inv = $stmtInv->fetch();

        if ($inv && $total_amount >= 0) {
            $paidAmt = (float)$inv['amount_paid'];
            
            if ($new_status === 'paid') {
                $paidAmt = $total_amount;
                $dueAmt  = 0;
            } else {
                $dueAmt = max(0, $total_amount - $paidAmt);
                if ($paidAmt >= $total_amount && $total_amount > 0) {
                    $new_status = 'paid';
                } elseif ($paidAmt > 0) {
                    $new_status = 'partially_paid';
                }
            }

            $upd = $pdo->prepare("UPDATE invoices SET total_amount = ?, amount_paid = ?, amount_due = ?, status = ?, due_date = ? WHERE id = ? AND customer_id = ?");
            $upd->execute([$total_amount, $paidAmt, $dueAmt, $new_status, $due_date, $inv_id, $id]);

            // Update client ledger debit if invoice total changed
            $updLedger = $pdo->prepare("UPDATE client_ledger SET debit = ? WHERE reference_type = 'invoice' AND reference_id = ? AND customer_id = ?");
            $updLedger->execute([$total_amount, $inv_id, $id]);

            recalculate_customer_ledger($id);
            set_flash('Invoice ' . $inv['invoice_no'] . ' updated successfully!', 'success');
        }
    }
    header("Location: view.php?id=$id&tab=invoices");
    exit;
}

// Handle Edit Payment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_payment') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $payment_id     = (int)$_POST['payment_id'];
        $amount         = (float)($_POST['amount'] ?? 0);
        $payment_date   = $_POST['payment_date'] ?: date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'Bank Transfer';
        $transaction_id = trim($_POST['transaction_id'] ?? '');
        $reference      = trim($_POST['reference'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');
        $status         = $_POST['status'] ?? 'Completed';

        if ($payment_id > 0 && $amount > 0) {
            update_client_payment($payment_id, $amount, $payment_date, $payment_method, $transaction_id, $reference, $notes, $status);
            set_flash('Payment details updated successfully and financial metrics recalculated!', 'success');
        }
    }
    header("Location: view.php?id=$id&tab=payments");
    exit;
}

// Handle Delete Payment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_payment') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $payment_id = (int)$_POST['payment_id'];
        if ($payment_id > 0) {
            delete_client_payment($payment_id);
            set_flash('Payment deleted successfully. Invoice amounts due and ledger balances restored.', 'info');
        }
    }
    header("Location: view.php?id=$id&tab=payments");
    exit;
}

$stmtQ = $pdo->prepare("SELECT * FROM client_quotations WHERE customer_id = ? ORDER BY quotation_date DESC, id DESC");
$stmtQ->execute([$id]);
$quotations = $stmtQ->fetchAll();

$activeTab = $_GET['tab'] ?? 'overview';
?>


<div class="container-fluid">
  <?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm mb-4" role="alert" style="border-radius: 12px;">
      <i class="bi bi-check-circle-fill me-2 text-success"></i><?= $flash['message'] ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($client['status'] !== 'active' || (int)($client['auto_invoice'] ?? 1) === 0): ?>

    <div class="alert alert-warning border-warning d-flex align-items-center shadow-sm mb-4" style="border-radius: 12px; background-color: #fff9e6;">
      <i class="bi bi-pause-circle-fill fs-3 text-warning me-3"></i>
      <div>
        <h6 class="fw-bold mb-1 text-dark"><i class="bi bi-dash-circle me-1 text-warning"></i>Monthly Billing & Invoicing Stopped</h6>
        <span class="small text-dark">
          Client status is set to <strong><?= ucfirst(e($client['status'])) ?></strong> (Auto-Invoice: <strong><?= $client['auto_invoice'] ? 'Enabled' : 'Disabled' ?></strong>).
          Automatic monthly invoice generation for this client is currently <strong>STOPPED</strong>.
        </span>
      </div>
    </div>
  <?php endif; ?>

  <!-- Profile Header Card -->

  <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px; background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);">
    <div class="card-body p-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center">
          <?= get_client_logo_html($client, 64) ?>

          <div>
            <div class="d-flex align-items-center gap-2">
              <h3 class="fw-bold text-dark mb-0"><?= e($client['name']) ?></h3>
              <span class="badge bg-<?= $client['client_type']=='monthly'?'primary':'info' ?>"><?= ucfirst(e($client['client_type'])) ?></span>
              <span class="badge bg-<?= $client['status']=='active'?'success':'secondary' ?>"><?= ucfirst(e($client['status'])) ?></span>
            </div>
            <p class="text-muted mb-1 mt-1">
              <i class="bi bi-person me-1"></i><?= e($client['contact_person'] ?: 'No primary contact') ?> &bull; 
              <i class="bi bi-telephone me-1"></i><?= e($client['phone'] ?: 'N/A') ?> &bull; 
              <i class="bi bi-envelope me-1"></i><?= e($client['email'] ?: 'N/A') ?>
            </p>
            <?php
              $onDate = !empty($client['onboard_date']) ? $client['onboard_date'] : '2026-01-01';
              $onDt = new DateTime($onDate);
              $nowDt = new DateTime();
              $diff = $onDt->diff($nowDt);
              $totalDays = $diff->days;
              $durParts = [];
              if ($diff->y > 0) $durParts[] = $diff->y . ' yr' . ($diff->y > 1 ? 's' : '');
              if ($diff->m > 0) $durParts[] = $diff->m . ' mo' . ($diff->m > 1 ? 's' : '');
              $durParts[] = $diff->d . ' day' . ($diff->d > 1 ? 's' : '');
              $durText = implode(' ', $durParts);
            ?>
            <small class="text-secondary d-block">
              <i class="bi bi-calendar-check text-primary me-1"></i>Onboarded: <strong><?= date('d M Y', strtotime($onDate)) ?></strong> &bull; 
              Connected for <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><i class="bi bi-clock-history me-1"></i><?= $durText ?> (<?= $totalDays ?> days)</span>
            </small>
          </div>
        </div>

        <!-- Quick Financial Metrics Header -->
        <div class="d-flex gap-3">
          <div class="bg-white p-3 rounded-3 shadow-sm text-center border" style="min-width: 130px;">
            <small class="text-muted d-block uppercase" style="font-size: 0.7rem;">TOTAL BILLED</small>
            <span class="fw-bold fs-5 text-dark">₹<?= money($billed) ?></span>
          </div>
          <div class="bg-white p-3 rounded-3 shadow-sm text-center border" style="min-width: 130px;">
            <small class="text-muted d-block uppercase" style="font-size: 0.7rem;">RECEIVED</small>
            <span class="fw-bold fs-5 text-success">₹<?= money($received) ?></span>
          </div>
          <div class="bg-white p-3 rounded-3 shadow-sm text-center border" style="min-width: 130px;">
            <small class="text-muted d-block uppercase" style="font-size: 0.7rem;">PENDING</small>
            <span class="fw-bold fs-5 text-<?= $pending>0?'danger':'muted' ?>">₹<?= money($pending) ?></span>
          </div>
        </div>
      </div>

      <!-- Quick Action Bar -->
      <div class="d-flex flex-wrap gap-2 mt-4 pt-3 border-top">
        <a href="../invoices/create.php?customer_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle me-1"></i> New Invoice</a>
        <a href="../invoices/record_payment.php?customer_id=<?= $id ?>" class="btn btn-sm btn-success"><i class="bi bi-cash-coin me-1"></i> Record Payment</a>
        <a href="../work/extra_work.php?customer_id=<?= $id ?>&action=new" class="btn btn-sm btn-warning"><i class="bi bi-plus-square me-1"></i> Add Extra Work</a>
        <a href="../credentials/index.php?customer_id=<?= $id ?>&action=add" class="btn btn-sm btn-info text-white"><i class="bi bi-key me-1"></i> Add Credential</a>
        <a href="ledger.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-journal-text me-1"></i> View Full Ledger</a>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-pencil me-1"></i> Edit Client</a>
      </div>
    </div>
  </div>

  <!-- Nav Tabs -->
  <ul class="nav nav-pills mb-4 bg-white p-2 rounded-3 shadow-sm border" id="clientTabs">
    <li class="nav-item"><a class="nav-link <?= $activeTab==='overview'?'active':'' ?>" href="?id=<?= $id ?>&tab=overview"><i class="bi bi-speedometer2 me-1"></i> Overview</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='quotations'?'active':'' ?>" href="?id=<?= $id ?>&tab=quotations"><i class="bi bi-file-earmark-text me-1"></i> Quotations (<?= count($quotations) ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='invoices'?'active':'' ?>" href="?id=<?= $id ?>&tab=invoices"><i class="bi bi-receipt me-1"></i> Invoices (<?= count($invoices) ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='payments'?'active':'' ?>" href="?id=<?= $id ?>&tab=payments"><i class="bi bi-cash-stack me-1"></i> Payments (<?= count($payments) ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='projects'?'active':'' ?>" href="?id=<?= $id ?>&tab=projects"><i class="bi bi-kanban me-1"></i> Projects (<?= count($projects) ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='extrawork'?'active':'' ?>" href="?id=<?= $id ?>&tab=extrawork"><i class="bi bi-plus-circle me-1"></i> Extra Work (<?= count($extraWorks) ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='credentials'?'active':'' ?>" href="?id=<?= $id ?>&tab=credentials"><i class="bi bi-shield-lock me-1"></i> Credentials (<?= count($credentials) ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='ledger'?'active':'' ?>" href="?id=<?= $id ?>&tab=ledger"><i class="bi bi-journal-text me-1"></i> Ledger</a></li>
  </ul>

  <!-- Tab Content -->
  <?php if ($activeTab === 'overview'): ?>
    <!-- Overview Notes / Summary Card -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; background: linear-gradient(135deg, #f0f7ff 0%, #ffffff 100%); border-left: 5px solid #0d6efd !important;">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="fw-bold text-primary mb-0"><i class="bi bi-card-text me-2"></i>Client Overview & Scope Summary Notes</h6>
          <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editOverviewNotesModal">
            <i class="bi bi-pencil me-1"></i> Edit Overview Notes
          </button>
        </div>
        <?php if (!empty($client['overview_notes'])): ?>
          <div class="p-3 bg-white rounded-3 border text-dark" style="font-size: 0.95rem; line-height: 1.6; white-space: pre-wrap;"><?= e($client['overview_notes']) ?></div>
        <?php else: ?>
          <p class="text-muted mb-0 small"><i class="bi bi-info-circle me-1"></i> No overview notes recorded yet. Click "Edit Overview Notes" to add general project scope, client preferences, or agreed terms summary.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
          <div class="card-header bg-white py-3"><h6 class="fw-bold mb-0 text-dark">Contact & Company Info</h6></div>
          <div class="card-body">
            <table class="table table-borderless align-middle mb-0">
              <tr><td class="text-muted" style="width: 140px;">GSTIN:</td><td class="fw-semibold"><?= e($client['gstin'] ?: 'Not Provided') ?></td></tr>
              <tr><td class="text-muted">PAN:</td><td class="fw-semibold"><?= e($client['pan'] ?: 'Not Provided') ?></td></tr>
              <tr><td class="text-muted">Website:</td><td><a href="<?= e($client['website']) ?>" target="_blank"><?= e($client['website'] ?: 'N/A') ?></a></td></tr>
              <tr><td class="text-muted">Billing Address:</td><td><?= nl2br(e($client['billing_address'] ?: 'N/A')) ?></td></tr>
              <tr><td class="text-muted">City / State:</td><td><?= e(($client['city']??'').', '.($client['state']??'')) ?></td></tr>
              <tr><td class="text-muted">Onboard Date:</td><td><?= e($client['onboard_date'] ?: 'N/A') ?></td></tr>
            </table>
          </div>
        </div>
      </div>


      <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
          <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-kanban me-2"></i>Active Service Packages & Projects</h6>
            <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i> Manage Services</a>
          </div>
          <div class="card-body">
            <?php if (!empty($clientServices)): ?>
              <div class="list-group list-group-flush mb-3">
                <?php foreach ($clientServices as $cs): ?>
                  <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center bg-transparent border-bottom">
                    <div>
                      <h6 class="fw-bold text-dark mb-0"><i class="bi bi-box-seam text-primary me-2"></i><?= e($cs['service_name']) ?></h6>
                      <small class="text-muted">
                        Type: <span class="badge bg-<?= $cs['billing_type']==='monthly'?'primary':'info' ?>-subtle text-<?= $cs['billing_type']==='monthly'?'primary':'info' ?> border border-<?= $cs['billing_type']==='monthly'?'primary':'info' ?>-subtle"><?= $cs['billing_type']==='monthly'?'Monthly Retainer':'One-Time Project' ?></span>
                      </small>
                    </div>
                    <div class="text-end">
                      <span class="fw-bold text-dark fs-6">₹<?= money($cs['amount']) ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="p-2 bg-light rounded d-flex justify-content-between align-items-center">
                <small class="text-muted"><i class="bi bi-calendar-check me-1"></i> Billing Day: <strong><?= e($client['billing_day'] ?? 1) ?>th of month</strong></small>
                <span class="small fw-semibold text-primary">Total Monthly: ₹<?= money($client['monthly_amount']) ?>/mo</span>
              </div>
            <?php elseif ($client['client_type'] === 'monthly'): ?>
              <div class="p-3 bg-light rounded mb-3">
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <h6 class="fw-bold text-dark mb-1"><?= e($client['monthly_service_name'] ?: 'Monthly Service') ?></h6>
                    <small class="text-muted">Billing Day: <?= e($client['billing_day'] ?? 1) ?>th of every month</small>
                  </div>
                  <span class="fs-4 fw-bold text-primary">₹<?= money($client['monthly_amount']) ?></span>
                </div>
              </div>
              <p class="small text-muted mb-0"><i class="bi bi-info-circle me-1"></i> Automatic monthly invoices are generated based on configured billing cycle.</p>
            <?php else: ?>
              <p class="text-muted mb-0">This client is set up as a <strong><?= ucfirst(e($client['client_type'])) ?></strong> client.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

  <?php elseif ($activeTab === 'invoices'): ?>
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
      <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>Invoice #</th><th>Date</th><th>Total Amount</th><th>Paid</th><th>Due</th><th>Status</th><th class="text-end pe-3">Action</th></tr>
          </thead>
          <tbody>
            <?php foreach ($invoices as $inv): 
              $isCanc = ($inv['status'] === 'cancelled');
              $invType = $inv['invoice_type'] ?? 'gst';
            ?>
              <tr class="<?= $isCanc ? 'table-light' : '' ?>" style="<?= $isCanc ? 'background-color: #fff5f5;' : '' ?>">
                <td>
                  <a href="../invoices/view.php?id=<?= $inv['id'] ?>" class="fw-bold <?= $isCanc ? 'text-decoration-line-through text-danger' : 'text-primary text-decoration-none' ?>">
                    <?= e($inv['invoice_no']) ?>
                  </a>
                  <div>
                    <?php if ($invType === 'personal'): ?>
                      <span class="badge bg-warning-subtle text-dark border" style="font-size: 0.65rem;">Personal Bill</span>
                    <?php else: ?>
                      <span class="badge bg-primary-subtle text-primary border" style="font-size: 0.65rem;">Company GST</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td><?= date('d M Y', strtotime($inv['invoice_date'] ?: $inv['created_at'])) ?></td>
                <td class="fw-semibold">₹<?= money($inv['total_amount']) ?></td>
                <td class="text-success fw-medium">₹<?= money($inv['amount_paid']) ?></td>
                <td class="text-danger fw-semibold">₹<?= money($isCanc ? 0 : $inv['amount_due']) ?></td>
                <td>
                  <?php if ($isCanc): ?>
                    <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>CANCELLED</span>
                  <?php else: ?>
                    <span class="badge bg-<?= $inv['status']=='paid'?'success':($inv['status']=='partially_paid'?'warning':'danger') ?>"><?= strtoupper(e($inv['status'])) ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-end pe-3">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <li>
                        <a href="../invoices/view.php?id=<?= $inv['id'] ?>" class="dropdown-item">
                          <i class="bi bi-eye text-primary me-2"></i> View Invoice
                        </a>
                      </li>
                      <li>
                        <a href="../invoices/download.php?id=<?= $inv['id'] ?>" target="_blank" class="dropdown-item">
                          <i class="bi bi-download text-success me-2"></i> Download PDF
                        </a>
                      </li>
                      <?php if (!$isCanc): ?>
                        <li>
                          <button type="button" class="dropdown-item edit-invoice-btn"
                                  data-invoice='<?= json_encode($inv, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                            <i class="bi bi-pencil text-secondary me-2"></i> Quick Edit
                          </button>
                        </li>
                        <li>
                          <a href="../invoices/edit.php?id=<?= $inv['id'] ?>" class="dropdown-item">
                            <i class="bi bi-sliders text-info me-2"></i> Full Line Items Edit
                          </a>
                        </li>
                      <?php else: ?>
                        <li><span class="dropdown-item text-muted disabled"><i class="bi bi-slash-circle me-2"></i> Edit Disabled (Cancelled)</span></li>
                      <?php endif; ?>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <form method="post" action="../invoices/delete.php" onsubmit="return confirm('⚠️ Delete invoice <?= e($inv['invoice_no']) ?> permanently?');" class="m-0">
                          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                          <input type="hidden" name="id" value="<?= $inv['id'] ?>">
                          <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-trash me-2"></i> Delete Invoice
                          </button>
                        </form>
                      </li>
                    </ul>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$invoices): ?><tr><td colspan="7" class="text-center py-4 text-muted">No invoices generated yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($activeTab === 'payments'): ?>
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
      <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>Payment No</th><th>Date</th><th>Method</th><th>Transaction Ref</th><th>Amount</th><th class="text-end pe-3">Action</th></tr>
          </thead>
          <tbody>
            <?php foreach ($payments as $pay): ?>
              <tr>
                <td class="fw-bold text-primary"><?= e($pay['payment_no']) ?></td>
                <td><?= date('d M Y', strtotime($pay['payment_date'])) ?></td>
                <td><span class="badge bg-info text-dark"><?= e($pay['payment_method'] ?: $pay['payment_mode']) ?></span></td>
                <td><?= e($pay['transaction_id'] ?: ($pay['reference'] ?: '—')) ?></td>
                <td class="fw-bold text-success">₹<?= money($pay['amount']) ?></td>
                <td class="text-end pe-3">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <li>
                        <button type="button" class="dropdown-item edit-payment-btn"
                                data-payment='<?= json_encode($pay, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                          <i class="bi bi-pencil text-secondary me-2"></i> Edit Payment
                        </button>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <form method="post" onsubmit="return confirm('⚠️ Delete payment <?= e($pay['payment_no']) ?> of ₹<?= money($pay['amount']) ?> permanently? Invoice due amounts and ledger will be restored!');" class="m-0">
                          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                          <input type="hidden" name="action" value="delete_payment">
                          <input type="hidden" name="payment_id" value="<?= $pay['id'] ?>">
                          <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-trash me-2"></i> Delete Payment
                          </button>
                        </form>
                      </li>
                    </ul>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$payments): ?><tr><td colspan="6" class="text-center py-4 text-muted">No payments recorded yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($activeTab === 'ledger'): ?>
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">Running Financial Ledger</h6>
        <a href="ledger.php?id=<?= $id ?>" class="btn btn-sm btn-primary">Open Printable Ledger</a>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>Date</th><th>Description</th><th>Type</th><th class="text-end">Debit (Billed)</th><th class="text-end">Credit (Paid)</th><th class="text-end">Balance</th></tr>
          </thead>
          <tbody>
            <?php foreach ($ledger as $l): ?>
              <tr>
                <td><?= date('d M Y', strtotime($l['entry_date'])) ?></td>
                <td class="fw-medium"><?= e($l['description']) ?></td>
                <td><span class="badge bg-secondary"><?= strtoupper(e($l['reference_type'])) ?></span></td>
                <td class="text-end text-danger"><?= $l['debit']>0?'₹'.money($l['debit']):'—' ?></td>
                <td class="text-end text-success"><?= $l['credit']>0?'₹'.money($l['credit']):'—' ?></td>
                <td class="text-end fw-bold">₹<?= money($l['balance']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$ledger): ?><tr><td colspan="6" class="text-center py-4 text-muted">No ledger entries logged.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php elseif ($activeTab === 'projects'): ?>
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-kanban me-2 text-primary"></i>Client Projects</h6>
        <a href="../work/projects.php?customer_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle me-1"></i> New Project</a>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Project Name</th>
              <th>Type</th>
              <th>Start Date</th>
              <th class="text-end">Agreed Amount</th>
              <th class="text-end">Advance Required</th>
              <th>Status</th>
              <th class="text-end pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($projects as $p): ?>
              <tr>
                <td class="ps-3 fw-bold text-dark"><?= e($p['project_name']) ?></td>
                <td><span class="badge bg-light text-dark border"><?= e($p['project_type']) ?></span></td>
                <td><?= date('d M Y', strtotime($p['start_date'])) ?></td>
                <td class="text-end fw-bold text-dark">₹<?= money($p['agreed_amount']) ?></td>
                <td class="text-end text-warning fw-semibold">₹<?= money($p['advance_required']) ?></td>
                <td><span class="badge bg-<?= $p['status']=='Completed'?'success':($p['status']=='Active'?'primary':'warning') ?>"><?= e($p['status']) ?></span></td>
                <td class="text-end pe-3">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <li>
                        <button type="button" class="dropdown-item edit-project-btn"
                                data-project='<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                          <i class="bi bi-pencil text-secondary me-2"></i> Edit Project
                        </button>
                      </li>
                      <li>
                        <a href="../invoices/create.php?customer_id=<?= $id ?>&project_id=<?= $p['id'] ?>" class="dropdown-item">
                          <i class="bi bi-receipt text-primary me-2"></i> Create Invoice
                        </a>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <form method="post" onsubmit="return confirm('⚠️ Delete project \'<?= e($p['project_name']) ?>\' permanently?');" class="m-0">
                          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                          <input type="hidden" name="action" value="delete_project">
                          <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                          <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-trash me-2"></i> Delete Project
                          </button>
                        </form>
                      </li>
                    </ul>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$projects): ?>
              <tr><td colspan="7" class="text-center py-4 text-muted">No projects logged for this client. Click "New Project" to add one.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($activeTab === 'extrawork'): ?>
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-plus-square me-2 text-primary"></i>Extra Work & Service History</h6>
        <a href="../work/extra_work.php?customer_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle me-1"></i> Log Extra Work</a>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Work Period</th>
              <th>Service Title</th>
              <th>Billing Option</th>
              <th class="text-end">Amount (₹)</th>
              <th>Status</th>
              <th class="text-end pe-3">Invoice Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($extraWorks as $ew): ?>
              <tr>
                <td class="ps-3 small">
                  <span class="fw-bold text-dark d-block"><?= date('d M Y', strtotime($ew['work_date'])) ?></span>
                  <?php if (!empty($ew['end_date'])): ?>
                    <small class="text-muted">to <?= date('d M Y', strtotime($ew['end_date'])) ?></small>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="fw-bold text-dark d-block"><?= e($ew['title']) ?></span>
                  <?php if (!empty($ew['description'])): ?>
                    <small class="text-muted"><?= e($ew['description']) ?></small>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($ew['billing_method'] === 'separate_invoice'): ?>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Separate Invoice</span>
                  <?php else: ?>
                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Included in Monthly</span>
                  <?php endif; ?>
                </td>
                <td class="text-end fw-bold text-success">₹<?= money($ew['total_amount']) ?></td>
                <td>
                  <span class="badge bg-<?= $ew['status']=='completed'?'success':($ew['status']=='billed'?'info':'warning') ?>"><?= ucfirst(e($ew['status'])) ?></span>
                </td>
                <td class="text-end pe-3">
                  <?php if (!empty($ew['invoice_id'])): ?>
                    <a href="../invoices/view.php?id=<?= $ew['invoice_id'] ?>" class="btn btn-sm btn-light border fw-semibold text-primary">
                      <i class="bi bi-file-earmark-text me-1"></i> View Invoice #<?= $ew['invoice_id'] ?>
                    </a>
                  <?php else: ?>
                    <a href="../invoices/create.php?customer_id=<?= $id ?>&extra_work_id=<?= $ew['id'] ?>" class="btn btn-sm btn-outline-success">
                      <i class="bi bi-receipt me-1"></i> Create Invoice
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$extraWorks): ?>
              <tr><td colspan="6" class="text-center py-4 text-muted">No extra work tasks logged for this client.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($activeTab === 'credentials'): ?>
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-shield-lock me-2 text-primary"></i>Client Vault Credentials</h6>
        <a href="../credentials/index.php?customer_id=<?= $id ?>&action=add" class="btn btn-sm btn-primary"><i class="bi bi-key me-1"></i> Add Credential</a>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Platform</th>
              <th>Account Name</th>
              <th>Username / Email</th>
              <th>Login URL</th>
              <th class="text-end pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($credentials as $cred): ?>
              <tr>
                <td class="ps-3 fw-bold text-primary"><?= e($cred['platform']) ?></td>
                <td class="fw-medium text-dark"><?= e($cred['account_name']) ?></td>
                <td><code><?= e($cred['username_email']) ?></code></td>
                <td><a href="<?= e($cred['login_url']) ?>" target="_blank" class="small text-muted"><?= e($cred['login_url']) ?></a></td>
                <td class="text-end pe-3">
                  <a href="../credentials/index.php?customer_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye me-1"></i> Open Vault</a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$credentials): ?>
              <tr><td colspan="5" class="text-center py-4 text-muted">No credentials stored for this client.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($activeTab === 'quotations'): ?>
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
      <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Client Quotations & Agreed Work Scope</h6>
          <small class="text-muted">Upload and track sent quotations, final approved quotations, and scope of work agreements.</small>
        </div>
        <button class="btn btn-sm btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#uploadQuotationModal">
          <i class="bi bi-cloud-upload me-1"></i> Upload Client Quotation
        </button>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light small">
            <tr>
              <th class="ps-3">Quotation Title / Ref</th>
              <th>Type</th>
              <th>Quoted Amount</th>
              <th>Quotation Date</th>
              <th>Scope of Work & Notes</th>
              <th>Status</th>
              <th class="text-end pe-3">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($quotations): ?>
              <?php foreach ($quotations as $q): 
                $fileUrl = !empty($q['file_path']) ? BASE_URL . e($q['file_path']) : null;
                $ext = !empty($q['file_path']) ? strtolower(pathinfo($q['file_path'], PATHINFO_EXTENSION)) : '';
              ?>
                <tr>
                  <td class="ps-3">
                    <span class="fw-bold text-dark d-block"><?= e($q['title']) ?></span>
                    <?php if (!empty($q['quotation_no'])): ?>
                      <small class="text-muted font-monospace"><i class="bi bi-hash"></i><?= e($q['quotation_no']) ?></small>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($q['quotation_type'] === 'final'): ?>
                      <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="bi bi-check-circle me-1"></i>Final Approved</span>
                    <?php elseif ($q['quotation_type'] === 'sent'): ?>
                      <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><i class="bi bi-send me-1"></i>Sent to Client</span>
                    <?php elseif ($q['quotation_type'] === 'revised'): ?>
                      <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1"><i class="bi bi-arrow-repeat me-1"></i>Revised</span>
                    <?php else: ?>
                      <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1">Draft</span>
                    <?php endif; ?>
                  </td>
                  <td class="fw-bold text-dark fs-6">₹<?= money($q['amount']) ?></td>
                  <td class="small text-muted"><?= !empty($q['quotation_date']) ? date('d M Y', strtotime($q['quotation_date'])) : date('d M Y', strtotime($q['created_at'])) ?></td>
                  <td>
                    <?php if (!empty($q['scope_of_work'])): ?>
                      <span class="small text-dark text-break" style="max-width: 250px; display: inline-block; white-space: pre-wrap;"><?= e($q['scope_of_work']) ?></span>
                    <?php else: ?>
                      <span class="text-muted small">No scope details noted.</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="update_quotation_status">
                      <input type="hidden" name="quotation_id" value="<?= $q['id'] ?>">
                      <select name="status" class="form-select form-select-sm border-0 bg-<?= $q['status']=='approved'?'success-subtle text-success':($q['status']=='pending'?'warning-subtle text-warning':'danger-subtle text-danger') ?> fw-semibold py-0 ps-2 pe-4" onchange="this.form.submit()" style="width: auto; height: 26px;">
                        <option value="approved" <?= $q['status']=='approved'?'selected':'' ?>>Approved</option>
                        <option value="pending" <?= $q['status']=='pending'?'selected':'' ?>>Pending</option>
                        <option value="rejected" <?= $q['status']=='rejected'?'selected':'' ?>>Rejected</option>
                        <option value="superseded" <?= $q['status']=='superseded'?'selected':'' ?>>Superseded</option>
                      </select>
                    </form>
                  </td>
                  <td class="text-end pe-3">
                    <div class="dropdown">
                      <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-three-dots-vertical"></i>
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <?php if ($fileUrl): ?>
                          <li>
                            <a href="<?= $fileUrl ?>" target="_blank" class="dropdown-item">
                              <i class="bi bi-eye text-primary me-2"></i> View File
                            </a>
                          </li>
                          <li>
                            <a href="<?= $fileUrl ?>" download class="dropdown-item">
                              <i class="bi bi-download text-success me-2"></i> Download File
                            </a>
                          </li>
                          <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        <li>
                          <form method="post" onsubmit="return confirm('Delete this quotation record?');" class="m-0">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="delete_quotation">
                            <input type="hidden" name="quotation_id" value="<?= $q['id'] ?>">
                            <button type="submit" class="dropdown-item text-danger">
                              <i class="bi bi-trash me-2"></i> Delete Quotation
                            </button>
                          </form>
                        </li>
                      </ul>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" class="text-center py-5 text-muted">
                  <i class="bi bi-file-earmark-x fs-2 d-block mb-2 text-muted"></i>
                  No quotations uploaded for this client yet. Click <strong>"Upload Client Quotation"</strong> above to upload the quotation sent or finalized with the client.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php endif; ?>
</div>

<!-- Modal: Edit Overview Notes -->
<div class="modal fade" id="editOverviewNotesModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="update_overview_notes">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-card-text text-primary me-2"></i>Edit Client Overview & Scope Notes</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-medium">Overview Message / Agreed Work Scope</label>
            <textarea name="overview_notes" class="form-control" rows="8" placeholder="Write a summary of what work needs to be done for this client, agreed deliverables, package scope, special requests, and overview message..."><?= e($client['overview_notes'] ?? '') ?></textarea>
            <small class="text-muted mt-1 d-block"><i class="bi bi-info-circle me-1"></i> This overview message will appear prominently on the client's overview dashboard for quick reference.</small>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-check-circle me-1"></i> Save Overview Notes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Upload Quotation -->
<div class="modal fade" id="uploadQuotationModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="upload_quotation">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-cloud-upload text-primary me-2"></i>Upload Client Quotation</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-medium">Quotation Title / Subject <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" placeholder="e.g. Website Development & SEO Final Quotation" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Quotation Ref No</label>
              <input type="text" name="quotation_no" class="form-control" placeholder="e.g. QUOT-2026-001">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Quotation Type <span class="text-danger">*</span></label>
              <select name="quotation_type" class="form-select">
                <option value="final" selected>✅ Final Approved Quotation (Client Agreed)</option>
                <option value="sent">📤 Sent to Client (Initial Quote)</option>
                <option value="revised">🔄 Revised Quotation</option>
                <option value="draft">📝 Draft Quotation</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Quotation Amount (₹)</label>
              <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Quotation Date</label>
              <input type="date" name="quotation_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-8">
              <label class="form-label fw-medium">Upload File (PDF / DOC / Image)</label>
              <input type="file" name="quotation_file" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
              <small class="text-muted">Attach copy of sent or signed/finalized quotation.</small>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Status</label>
              <select name="status" class="form-select">
                <option value="approved" selected>Approved / Final</option>
                <option value="pending">Pending Review</option>
                <option value="rejected">Rejected</option>
                <option value="superseded">Superseded</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-medium">Scope of Work & Notes Summary</label>
              <textarea name="scope_of_work" class="form-control" rows="3" placeholder="Detail what work is included, agreed deliverables, payment terms, or quotation summary..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-check-circle me-1"></i> Upload Quotation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Project Modal -->
<div class="modal fade" id="editProjectModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit_project">
        <input type="hidden" name="project_id" id="edit_project_id">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Project Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-medium">Project Name <span class="text-danger">*</span></label>
              <input type="text" name="project_name" id="edit_project_name" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Project Type</label>
              <select name="project_type" id="edit_project_type" class="form-select">
                <option value="Fixed">Fixed Price</option>
                <option value="One-Time">One-Time Project</option>
                <option value="Retainer">Monthly Retainer</option>
                <option value="Hourly">Hourly Rate</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Agreed Amount (₹) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="agreed_amount" id="edit_agreed_amount" class="form-control fw-bold text-success" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Advance Required (₹)</label>
              <input type="number" step="0.01" name="advance_required" id="edit_advance_required" class="form-control fw-bold text-warning">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Status</label>
              <select name="status" id="edit_project_status" class="form-select">
                <option value="Active">Active</option>
                <option value="Planning">Planning</option>
                <option value="On Hold">On Hold</option>
                <option value="Completed">Completed</option>
                <option value="Cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Start Date</label>
              <input type="date" name="start_date" id="edit_start_date" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">End Date</label>
              <input type="date" name="end_date" id="edit_end_date" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-medium">Project Description & Deliverables</label>
              <textarea name="description" id="edit_project_description" class="form-control" rows="3"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-check-circle me-1"></i> Update Project</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Invoice Modal -->
<div class="modal fade" id="editInvoiceModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="quick_update_invoice">
        <input type="hidden" name="invoice_id" id="edit_invoice_id">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-receipt text-primary me-2"></i>Update Invoice Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-medium">Invoice Number</label>
            <input type="text" id="edit_invoice_no_display" class="form-control bg-light fw-bold" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Total Invoice Amount (₹) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" name="total_amount" id="edit_invoice_total_amount" class="form-control form-control-lg fw-bold text-primary" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Payment Status</label>
            <select name="status" id="edit_invoice_status" class="form-select">
              <option value="sent">SENT (Unpaid)</option>
              <option value="partially_paid">PARTIALLY PAID</option>
              <option value="paid">PAID (Full)</option>
              <option value="overdue">OVERDUE</option>
              <option value="cancelled">CANCELLED</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Due Date</label>
            <input type="date" name="due_date" id="edit_invoice_due_date" class="form-control">
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-check-circle me-1"></i> Update Invoice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Payment Modal -->
<div class="modal fade" id="editPaymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit_payment">
        <input type="hidden" name="payment_id" id="edit_payment_id">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-cash-coin text-success me-2"></i>Edit Payment Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-medium">Payment Number</label>
            <input type="text" id="edit_payment_no_display" class="form-control bg-light fw-bold" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Amount Received (₹) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" name="amount" id="edit_payment_amount" class="form-control form-control-lg fw-bold text-success" required>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-medium">Payment Date</label>
              <input type="date" name="payment_date" id="edit_payment_date" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Payment Method</label>
              <select name="payment_method" id="edit_payment_method" class="form-select">
                <option value="Bank Transfer">Bank Transfer</option>
                <option value="UPI">UPI</option>
                <option value="Cash">Cash</option>
                <option value="Card">Card</option>
                <option value="Cheque">Cheque</option>
                <option value="Other">Other</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Transaction ID / UTR</label>
            <input type="text" name="transaction_id" id="edit_payment_transaction_id" class="form-control" placeholder="e.g. UTR123456789">
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Payment Reference</label>
            <input type="text" name="reference" id="edit_payment_reference" class="form-control" placeholder="e.g. 50% Advance">
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium">Payment Notes</label>
            <textarea name="notes" id="edit_payment_notes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success fw-semibold"><i class="bi bi-check-circle me-1"></i> Update Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.edit-project-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const p = JSON.parse(this.getAttribute('data-project'));
    document.getElementById('edit_project_id').value = p.id;
    document.getElementById('edit_project_name').value = p.project_name || '';
    document.getElementById('edit_project_type').value = p.project_type || 'One-Time';
    document.getElementById('edit_agreed_amount').value = p.agreed_amount || 0;
    document.getElementById('edit_advance_required').value = p.advance_required || 0;
    document.getElementById('edit_project_status').value = p.status || 'Active';
    document.getElementById('edit_start_date').value = p.start_date || '';
    document.getElementById('edit_end_date').value = p.end_date || '';
    document.getElementById('edit_project_description').value = p.description || '';
    
    const modal = new bootstrap.Modal(document.getElementById('editProjectModal'));
    modal.show();
  });
});

document.querySelectorAll('.edit-invoice-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const inv = JSON.parse(this.getAttribute('data-invoice'));
    document.getElementById('edit_invoice_id').value = inv.id;
    document.getElementById('edit_invoice_no_display').value = inv.invoice_no || '';
    document.getElementById('edit_invoice_total_amount').value = inv.total_amount || 0;
    document.getElementById('edit_invoice_status').value = inv.status || 'sent';
    document.getElementById('edit_invoice_due_date').value = inv.due_date || '';
    
    const modal = new bootstrap.Modal(document.getElementById('editInvoiceModal'));
    modal.show();
  });
});

document.querySelectorAll('.edit-payment-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const pay = JSON.parse(this.getAttribute('data-payment'));
    document.getElementById('edit_payment_id').value = pay.id;
    document.getElementById('edit_payment_no_display').value = pay.payment_no || '';
    document.getElementById('edit_payment_amount').value = pay.amount || 0;
    document.getElementById('edit_payment_date').value = pay.payment_date || '';
    document.getElementById('edit_payment_method').value = pay.payment_method || pay.payment_mode || 'Bank Transfer';
    document.getElementById('edit_payment_transaction_id').value = pay.transaction_id || '';
    document.getElementById('edit_payment_reference').value = pay.reference || '';
    document.getElementById('edit_payment_notes').value = pay.notes || '';
    
    const modal = new bootstrap.Modal(document.getElementById('editPaymentModal'));
    modal.show();
  });
});
</script>

<?php include '../../includes/footer.php'; ?>

