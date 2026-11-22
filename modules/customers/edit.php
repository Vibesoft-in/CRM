<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) die("Invalid Client ID");

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();
if (!$customer) die("Client not found");

$stmtServices = $pdo->prepare("SELECT * FROM customer_services WHERE customer_id = ? ORDER BY id ASC");
$stmtServices->execute([$id]);
$existingServices = $stmtServices->fetchAll();

if (empty($existingServices) && !empty($customer['monthly_service_name'])) {
    $existingServices = [
        [
            'id' => 0,
            'service_name' => $customer['monthly_service_name'],
            'billing_type' => 'monthly',
            'amount' => $customer['monthly_amount']
        ]
    ];
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $name                 = trim($_POST['name']);
        $contact_person       = trim($_POST['contact_person']);
        $client_type          = $_POST['client_type'] ?? 'monthly';
        $status               = $_POST['status'] ?? 'active';
        $gstin                = trim($_POST['gstin']);
        $pan                  = trim($_POST['pan']);
        $phone                = trim($_POST['phone']);
        $alternate_phone      = trim($_POST['alternate_phone']);
        $whatsapp             = trim($_POST['whatsapp']);
        $email                = trim($_POST['email']);
        $website              = trim($_POST['website']);
        $billing_address      = trim($_POST['billing_address']);
        $shipping_address     = trim($_POST['shipping_address']);
        $city                 = trim($_POST['city']);
        $state                = trim($_POST['state']);
        $state_code           = trim($_POST['state_code']);
        $pincode              = trim($_POST['pincode']);
        $country              = trim($_POST['country'] ?: 'India');
        $onboard_date         = $_POST['onboard_date'] ?: date('Y-m-d');
        $notes                = trim($_POST['notes']);
        $overview_notes       = trim($_POST['overview_notes'] ?? '');
        
        $billing_start_date   = $_POST['billing_start_date'] ?: null;
        $billing_day          = (int)($_POST['billing_day'] ?? 1);
        $due_days             = (int)($_POST['due_days'] ?? 10);
        $auto_invoice         = isset($_POST['auto_invoice']) ? 1 : 0;
        
        // If status is completed, inactive, or archived, stop auto-invoicing and mark billing stopped
        if (in_array($status, ['completed', 'inactive', 'archived'])) {
            $auto_invoice = 0;
        }

        $state_code           = normalize_gst_state_code($state_code ?: $state);
        if (empty($state) && !empty($state_code)) {
            $state = get_gst_state_name($state_code);
        }

        if (empty($name)) {
            $err = 'Company/Client Name is required.';
        } elseif (empty($billing_address)) {
            $err = 'Billing Address is required.';
        } elseif (empty($city)) {
            $err = 'City is required.';
        } elseif (empty($state)) {
            $err = 'State is required. Please select a valid State / UT.';
        } elseif (empty($pincode)) {
            $err = 'PIN Code is required.';
        } else {
            $upd = $pdo->prepare("UPDATE customers SET
                name = ?, contact_person = ?, client_type = ?, status = ?, gstin = ?, pan = ?, phone = ?, alternate_phone = ?, whatsapp = ?, email = ?, website = ?,
                billing_address = ?, shipping_address = ?, city = ?, state = ?, state_code = ?, pincode = ?, country = ?, onboard_date = ?, notes = ?, overview_notes = ?,
                billing_start_date = ?, billing_day = ?, due_days = ?, auto_invoice = ?
                WHERE id = ?");
            
            $upd->execute([
                $name, $contact_person, $client_type, $status, $gstin, $pan, $phone, $alternate_phone, $whatsapp, $email, $website,
                $billing_address, $shipping_address, $city, $state, $state_code, $pincode, $country, $onboard_date, $notes, $overview_notes,
                $billing_start_date, $billing_day, $due_days, $auto_invoice,
                $id
            ]);

            // Handle Client Logo Upload
            if (!empty($_FILES['client_logo']['name']) && $_FILES['client_logo']['error'] === UPLOAD_ERR_OK) {
                $logoUploadDir = __DIR__ . '/../../uploads/client_Logo/';
                if (!is_dir($logoUploadDir)) {
                    @mkdir($logoUploadDir, 0777, true);
                }
                $lExt = strtolower(pathinfo($_FILES['client_logo']['name'], PATHINFO_EXTENSION));
                if (in_array($lExt, ['jpg', 'jpeg', 'png', 'webp', 'svg', 'avif'])) {
                    $lFileName = 'logo_' . time() . '_' . rand(1000, 9999) . '.' . $lExt;
                    if (move_uploaded_file($_FILES['client_logo']['tmp_name'], $logoUploadDir . $lFileName)) {
                        $clientLogoPath = 'uploads/client_Logo/' . $lFileName;
                        $pdo->prepare("UPDATE customers SET client_logo = ? WHERE id = ?")->execute([$clientLogoPath, $id]);
                    }
                }
            }

            // If status is completed/inactive/archived, update customer services status accordingly
            if (in_array($status, ['completed', 'inactive', 'archived'])) {
                $servStatus = ($status === 'completed') ? 'completed' : 'cancelled';
                $pdo->prepare("UPDATE customer_services SET status = ? WHERE customer_id = ?")->execute([$servStatus, $id]);
            }




            // Sync Service Packages & Projects
            $submitted_ids     = $_POST['service_id'] ?? [];
            $service_names     = $_POST['service_name'] ?? [];
            $service_types     = $_POST['service_type'] ?? [];
            $service_amounts   = $_POST['service_amount'] ?? [];

            $keep_service_ids = [];
            $monthly_names = [];
            $total_monthly_amount = 0;

            if (is_array($service_names)) {
                for ($i = 0; $i < count($service_names); $i++) {
                    $serv_id = (int)($submitted_ids[$i] ?? 0);
                    $sName   = trim($service_names[$i] ?? '');
                    if (empty($sName)) continue;
                    $sType   = $service_types[$i] ?? 'monthly';
                    $sAmt    = (float)($service_amounts[$i] ?? 0);
                    $pType   = ($sType === 'one_time') ? 'One-Time' : 'Monthly';

                    if ($serv_id > 0) {
                        $updServ = $pdo->prepare("UPDATE customer_services SET service_name = ?, billing_type = ?, amount = ? WHERE id = ? AND customer_id = ?");
                        $updServ->execute([$sName, $sType, $sAmt, $serv_id, $id]);
                        $keep_service_ids[] = $serv_id;

                        $chkProj = $pdo->prepare("SELECT project_id FROM customer_services WHERE id = ?");
                        $chkProj->execute([$serv_id]);
                        $proj_id = (int)$chkProj->fetchColumn();

                        if ($proj_id > 0) {
                            $updP = $pdo->prepare("UPDATE projects SET project_name = ?, project_type = ?, agreed_amount = ? WHERE id = ? AND customer_id = ?");
                            $updP->execute([$sName, $pType, $sAmt, $proj_id, $id]);
                        } else {
                            $insP = $pdo->prepare("INSERT INTO projects (customer_id, project_name, project_type, start_date, agreed_amount, status, description, created_at) VALUES (?, ?, ?, ?, ?, 'Active', ?, NOW())");
                            $insP->execute([$id, $sName, $pType, $onboard_date, $sAmt, "Auto-created service package"]);
                            $new_p_id = $pdo->lastInsertId();
                            $pdo->prepare("UPDATE customer_services SET project_id = ? WHERE id = ?")->execute([$new_p_id, $serv_id]);
                        }
                    } else {
                        $insP = $pdo->prepare("INSERT INTO projects (customer_id, project_name, project_type, start_date, agreed_amount, status, description, created_at) VALUES (?, ?, ?, ?, ?, 'Active', ?, NOW())");
                        $insP->execute([$id, $sName, $pType, $onboard_date, $sAmt, "Auto-created service package"]);
                        $new_p_id = $pdo->lastInsertId();

                        $insS = $pdo->prepare("INSERT INTO customer_services (customer_id, project_id, service_name, billing_type, amount, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
                        $insS->execute([$id, $new_p_id, $sName, $sType, $sAmt]);
                        $keep_service_ids[] = $pdo->lastInsertId();
                    }

                    if ($sType === 'monthly') {
                        $monthly_names[] = $sName;
                        $total_monthly_amount += $sAmt;
                    }
                }
            }

            if (!empty($keep_service_ids)) {
                $inClause = implode(',', array_map('intval', $keep_service_ids));
                $pdo->exec("DELETE FROM customer_services WHERE customer_id = {$id} AND id NOT IN ({$inClause})");
            } else {
                $pdo->exec("DELETE FROM customer_services WHERE customer_id = {$id}");
            }

            $combined_name = implode(', ', $monthly_names);
            $updCustSum = $pdo->prepare("UPDATE customers SET monthly_service_name = ?, monthly_amount = ? WHERE id = ?");
            $updCustSum->execute([$combined_name, $total_monthly_amount, $id]);
            
            set_flash('Client details & logo updated successfully!', 'success');

            header('Location: ' . BASE_URL . 'modules/customers/view.php?id=' . $id);
            exit;
        }
    }
}
?>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Client: <?= e($customer['name']) ?></h4>
      <p class="text-muted small mb-0">Update contact information, tax IDs, and service package configurations.</p>
    </div>
    <div>
      <a href="view.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm me-2"><i class="bi bi-eye me-1"></i> View Profile</a>
      <a href="list.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Clients</a>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="bi bi-exclamation-triangle me-2"></i><?= e($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="row g-4">
      <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
          <div class="card-header bg-white py-3 border-bottom-0">
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-building me-2 text-primary"></i>Company & Basic Info</h6>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label fw-medium">Company Name / Title <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" value="<?= e($customer['name']) ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-medium">Contact Person</label>
                <input type="text" name="contact_person" class="form-control" value="<?= e($customer['contact_person'] ?? '') ?>">
              </div>
              <div class="col-12">
                <div class="p-3 bg-light rounded-3 border d-flex align-items-center gap-3">
                  <div>
                    <?= get_client_logo_html($customer, 48) ?>
                  </div>
                  <div class="flex-grow-1">
                    <label class="form-label fw-medium mb-1"><i class="bi bi-image text-primary me-1"></i>Change Client Logo Image</label>
                    <input type="file" name="client_logo" class="form-control" accept="image/*">
                    <small class="text-muted" style="font-size:0.75rem;">Upload new PNG, JPG, WEBP or SVG logo to update client avatar.</small>
                  </div>
                </div>
              </div>

              <div class="col-md-4">
                <label class="form-label fw-medium">Client Type</label>
                <select name="client_type" class="form-select">
                  <option value="monthly" <?= ($customer['client_type']??'monthly')==='monthly'?'selected':'' ?>>Monthly Client</option>
                  <option value="one_time" <?= ($customer['client_type']??'')==='one_time'?'selected':'' ?>>One-Time Client</option>
                  <option value="project" <?= ($customer['client_type']??'')==='project'?'selected':'' ?>>Project-Based</option>
                  <option value="retainer" <?= ($customer['client_type']??'')==='retainer'?'selected':'' ?>>Retainer</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-medium">Status</label>
                <select name="status" class="form-select">
                  <option value="active" <?= ($customer['status']??'active')==='active'?'selected':'' ?>>Active</option>
                  <option value="lead" <?= ($customer['status']??'')==='lead'?'selected':'' ?>>Lead</option>
                  <option value="inactive" <?= ($customer['status']??'')==='inactive'?'selected':'' ?>>Inactive</option>
                  <option value="completed" <?= ($customer['status']??'')==='completed'?'selected':'' ?>>Completed</option>
                  <option value="archived" <?= ($customer['status']??'')==='archived'?'selected':'' ?>>Archived</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-medium">Onboard Date</label>
                <input type="date" name="onboard_date" class="form-control" value="<?= e($customer['onboard_date'] ?? date('Y-m-d')) ?>">
              </div>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
          <div class="card-header bg-white py-3 border-bottom-0">
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-telephone-inbound me-2 text-primary"></i>Contact & Tax Info</h6>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label fw-medium">Email Address</label>
                <input type="email" name="email" class="form-control" value="<?= e($customer['email']) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label fw-medium">Primary Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= e($customer['phone']) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label fw-medium">WhatsApp Number</label>
                <input type="text" name="whatsapp" class="form-control" value="<?= e($customer['whatsapp'] ?? '') ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label fw-medium">Alternate Phone</label>
                <input type="text" name="alternate_phone" class="form-control" value="<?= e($customer['alternate_phone'] ?? '') ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label fw-medium">Website</label>
                <input type="url" name="website" class="form-control" value="<?= e($customer['website'] ?? '') ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label fw-medium">GSTIN</label>
                <input type="text" name="gstin" id="client_gstin" class="form-control text-uppercase" value="<?= e($customer['gstin']) ?>" maxlength="15">
              </div>
              <div class="col-md-2">
                <label class="form-label fw-medium">PAN</label>
                <input type="text" name="pan" id="client_pan" class="form-control text-uppercase" value="<?= e($customer['pan'] ?? '') ?>" maxlength="10">
              </div>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
          <div class="card-header bg-white py-3 border-bottom-0">
            <h6 class="fw-bold mb-0 text-dark">
              <i class="bi bi-geo-alt me-2 text-primary"></i>Addresses & Location
              <span class="badge bg-danger ms-1" style="font-size:0.65rem;">Required</span>
            </h6>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-medium">Billing Address <span class="text-danger">*</span></label>
                <textarea name="billing_address" class="form-control" rows="3" required><?= e($customer['billing_address']) ?></textarea>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-medium">Shipping Address <small class="text-muted">(Optional)</small></label>
                <textarea name="shipping_address" class="form-control" rows="3"><?= e($customer['shipping_address']) ?></textarea>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-medium">City <span class="text-danger">*</span></label>
                <input type="text" name="city" class="form-control" value="<?= e($customer['city'] ?? '') ?>" required>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-medium">State <span class="text-danger">*</span></label>
                <?php
                $currentCustStateCode = normalize_gst_state_code($customer['state_code'] ?: $customer['state']);
                ?>
                <select name="state" id="client_state" class="form-select" required>
                  <option value="">-- Select State / UT --</option>
                  <?php foreach (get_indian_gst_states() as $stCode => $stName): ?>
                    <option value="<?= e($stName) ?>" data-code="<?= $stCode ?>" <?= ($currentCustStateCode === $stCode || ($customer['state'] ?? '') === $stName) ? 'selected' : '' ?>>
                      <?= e($stName) ?> (<?= $stCode ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-medium">State Code</label>
                <input type="text" name="state_code" id="client_state_code" class="form-control bg-light" value="<?= e($customer['state_code']) ?>" readonly>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-medium">Pincode <span class="text-danger">*</span></label>
                <input type="text" name="pincode" class="form-control" value="<?= e($customer['pincode'] ?? '') ?>" maxlength="10" required>
              </div>
              <div class="col-12 mt-3">
                <label class="form-label fw-medium text-dark"><i class="bi bi-card-text text-primary me-1"></i> Client Overview Message & Scope Notes</label>
                <textarea name="overview_notes" class="form-control" rows="3" placeholder="Overview notes / summary of agreed work for quick reference on client profile..."><?= e($customer['overview_notes'] ?? '') ?></textarea>
              </div>
              <div class="col-12 mt-2">
                <label class="form-label fw-medium">Internal Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= e($customer['notes'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
        </div>
      </div>


      <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px;">
          <div class="card-header py-3 border-bottom d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #0d6efd15, #7c3aed15);">
            <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-kanban me-2"></i>Service Packages & Projects</h6>
            <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" id="addServiceRowBtn">
              <i class="bi bi-plus-circle me-1"></i> Add Service
            </button>
          </div>
          <div class="card-body">
            <p class="text-muted small mb-3"><i class="bi bi-info-circle text-primary me-1"></i>Service packages are synced automatically with client projects.</p>

            <div id="serviceRowsContainer">
              <?php foreach ($existingServices as $idx => $serv): ?>
                <div class="service-row card border-light bg-light p-3 mb-2" style="border-radius: 10px;">
                  <input type="hidden" name="service_id[]" value="<?= (int)($serv['id'] ?? 0) ?>">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small fw-bold text-primary"><i class="bi bi-box-seam me-1"></i>Package #<?= $idx + 1 ?></span>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 text-decoration-none remove-service-btn"><i class="bi bi-trash"></i> Remove</button>
                  </div>
                  <div class="row g-2">
                    <div class="col-12">
                      <label class="form-label small fw-medium text-dark mb-1">Service Package Name <span class="text-danger">*</span></label>
                      <input type="text" name="service_name[]" class="form-control form-control-sm" value="<?= e($serv['service_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-6">
                      <label class="form-label small fw-medium text-dark mb-1">Billing Type</label>
                      <select name="service_type[]" class="form-select form-select-sm">
                        <option value="monthly" <?= ($serv['billing_type']??'monthly')==='monthly'?'selected':'' ?>>Monthly Retainer</option>
                        <option value="one_time" <?= ($serv['billing_type']??'')==='one_time'?'selected':'' ?>>One-Time Project</option>
                      </select>
                    </div>
                    <div class="col-6">
                      <label class="form-label small fw-medium text-dark mb-1">Amount (₹)</label>
                      <input type="number" step="0.01" name="service_amount[]" class="form-control form-control-sm fw-semibold" value="<?= e($serv['amount'] ?? 0) ?>" required>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <hr class="my-3">

            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="form-label fw-medium">Billing Day</label>
                <select name="billing_day" class="form-select">
                  <?php for($d=1; $d<=28; $d++): ?>
                    <option value="<?= $d ?>" <?= ($customer['billing_day']??1)==$d?'selected':'' ?>>Day <?= $d ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label fw-medium">Due Days</label>
                <input type="number" name="due_days" class="form-control" value="<?= e($customer['due_days'] ?? 10) ?>">
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-medium">Billing Start Date</label>
              <input type="date" name="billing_start_date" class="form-control" value="<?= e($customer['billing_start_date'] ?? '') ?>">
            </div>
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" name="auto_invoice" value="1" id="autoInvoice" <?= (!isset($customer['auto_invoice']) || $customer['auto_invoice']) ? 'checked' : '' ?>>
              <label class="form-check-label fw-medium" for="autoInvoice">Auto Generate Monthly Invoice</label>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
          <div class="card-header bg-white py-3 border-bottom-0">
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-sticky me-2 text-primary"></i>Internal Notes</h6>
          </div>
          <div class="card-body">
            <textarea name="notes" class="form-control" rows="4"><?= e($customer['notes'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="d-grid gap-2">
          <button type="submit" class="btn btn-primary btn-lg shadow-sm fw-semibold">
            <i class="bi bi-check-circle me-1"></i> Save Changes
          </button>
          <a href="view.php?id=<?= $id ?>" class="btn btn-light border">Cancel</a>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
document.getElementById('addServiceRowBtn').addEventListener('click', function() {
  const container = document.getElementById('serviceRowsContainer');
  const div = document.createElement('div');
  div.className = 'service-row card border-light bg-light p-3 mb-2';
  div.style.borderRadius = '10px';
  div.innerHTML = `
    <input type="hidden" name="service_id[]" value="0">
    <div class="d-flex justify-content-between align-items-center mb-1">
      <span class="small fw-bold text-primary"><i class="bi bi-box-seam me-1"></i>New Service Package</span>
      <button type="button" class="btn btn-sm btn-link text-danger p-0 text-decoration-none remove-service-btn"><i class="bi bi-trash"></i> Remove</button>
    </div>
    <div class="row g-2">
      <div class="col-12">
        <label class="form-label small fw-medium text-dark mb-1">Service Package Name <span class="text-danger">*</span></label>
        <input type="text" name="service_name[]" class="form-control form-control-sm" placeholder="e.g. Website Development" required>
      </div>
      <div class="col-6">
        <label class="form-label small fw-medium text-dark mb-1">Billing Type</label>
        <select name="service_type[]" class="form-select form-select-sm">
          <option value="monthly">Monthly Retainer</option>
          <option value="one_time">One-Time Project</option>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label small fw-medium text-dark mb-1">Amount (₹)</label>
        <input type="number" step="0.01" name="service_amount[]" class="form-control form-control-sm fw-semibold" placeholder="25000.00" required>
      </div>
    </div>
  `;
  container.appendChild(div);
});

document.getElementById('serviceRowsContainer').addEventListener('click', function(e) {
  if (e.target && (e.target.classList.contains('remove-service-btn') || e.target.closest('.remove-service-btn'))) {
    const row = e.target.closest('.service-row');
    if (document.querySelectorAll('.service-row').length > 1) {
      row.remove();
    } else {
      alert('At least one service package is required.');
    }
  }
});

// Auto-sync State Code & Auto-detect State from GSTIN
(function(){
  const stateSelect = document.getElementById('client_state');
  const stateCodeInput = document.getElementById('client_state_code');
  const gstinInput = document.getElementById('client_gstin');

  if (stateSelect && stateCodeInput) {
    stateSelect.addEventListener('change', function() {
      const opt = this.options[this.selectedIndex];
      stateCodeInput.value = opt ? (opt.dataset.code || '') : '';
    });
    if (stateSelect.selectedIndex > 0) {
      const opt = stateSelect.options[stateSelect.selectedIndex];
      if (opt && opt.dataset.code) stateCodeInput.value = opt.dataset.code;
    }
  }

  if (gstinInput && stateSelect) {
    gstinInput.addEventListener('input', function() {
      const val = this.value.trim().toUpperCase();
      this.value = val;
      if (val.length >= 2) {
        const code = val.substring(0, 2);
        for (let i = 0; i < stateSelect.options.length; i++) {
          if (stateSelect.options[i].dataset.code === code) {
            stateSelect.selectedIndex = i;
            if (stateCodeInput) stateCodeInput.value = code;
            break;
          }
        }
      }
    });
  }
})();
</script>

<?php include '../../includes/footer.php'; ?>
