<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $name                 = trim($_POST['name'] ?? '');
        $contact_person       = trim($_POST['contact_person'] ?? '');
        $client_type          = $_POST['client_type'] ?? 'monthly';
        $status               = $_POST['status'] ?? 'active';
        $phone                = trim($_POST['phone'] ?? '');
        $whatsapp             = trim($_POST['whatsapp'] ?? '');
        $alternate_phone      = trim($_POST['alternate_phone'] ?? '');
        $email                = trim($_POST['email'] ?? '');
        $website              = trim($_POST['website'] ?? '');
        $gstin                = trim($_POST['gstin'] ?? '');
        $pan                  = trim($_POST['pan'] ?? '');
        $billing_address      = trim($_POST['billing_address'] ?? '');
        $shipping_address     = trim($_POST['shipping_address'] ?? '');
        $city                 = trim($_POST['city'] ?? '');
        $state                = trim($_POST['state'] ?? '');
        $state_code           = trim($_POST['state_code'] ?? '');
        $pincode              = trim($_POST['pincode'] ?? '');
        $country              = trim($_POST['country'] ?: 'India');
        $onboard_date         = $_POST['onboard_date'] ?: date('Y-m-d');
        $notes                = trim($_POST['notes'] ?? '');
        $overview_notes       = trim($_POST['overview_notes'] ?? '');
        $monthly_service_name = trim($_POST['monthly_service_name'] ?? '');
        $monthly_amount       = (float)($_POST['monthly_amount'] ?? 0);
        $billing_start_date   = $_POST['billing_start_date'] ?: null;
        $billing_day          = (int)($_POST['billing_day'] ?? 1);
        $due_days             = (int)($_POST['due_days'] ?? 10);
        $auto_invoice         = isset($_POST['auto_invoice']) ? 1 : 0;

        $state_code           = normalize_gst_state_code($state_code ?: $state);
        if (empty($state) && !empty($state_code)) {
            $state = get_gst_state_name($state_code);
        }

        if (empty($name)) {
            $err = 'Client / Company Name is required.';
        } elseif (empty($billing_address)) {
            $err = 'Billing Address is required.';
        } elseif (empty($city)) {
            $err = 'City is required.';
        } elseif (empty($state)) {
            $err = 'State is required. Please select a valid State / UT.';
        } elseif (empty($pincode)) {
            $err = 'PIN Code is required.';
        } else {
            // Handle Client Logo Upload
            $clientLogoPath = null;
            if (!empty($_FILES['client_logo']['name'])) {
                $logoUploadDir = '../../uploads/client_Logo/';
                if (!is_dir($logoUploadDir)) {
                    @mkdir($logoUploadDir, 0777, true);
                }
                $lExt = strtolower(pathinfo($_FILES['client_logo']['name'], PATHINFO_EXTENSION));
                if (in_array($lExt, ['jpg', 'jpeg', 'png', 'webp', 'svg', 'avif'])) {
                    $lFileName = 'logo_' . time() . '_' . rand(1000, 9999) . '.' . $lExt;
                    if (move_uploaded_file($_FILES['client_logo']['tmp_name'], $logoUploadDir . $lFileName)) {
                        $clientLogoPath = 'uploads/client_Logo/' . $lFileName;
                    }
                }
            }

            $stmt = $pdo->prepare("INSERT INTO customers (
                name, contact_person, client_type, status, gstin, pan, phone, alternate_phone, whatsapp, email, website,
                billing_address, shipping_address, city, state, state_code, pincode, country, onboard_date, notes, overview_notes,
                monthly_service_name, monthly_amount, billing_start_date, billing_day, due_days, auto_invoice, client_logo, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

            $stmt->execute([
                $name, $contact_person, $client_type, $status, $gstin, $pan, $phone, $alternate_phone, $whatsapp, $email, $website,
                $billing_address, $shipping_address, $city, $state, $state_code, $pincode, $country, $onboard_date, $notes, $overview_notes,
                $monthly_service_name, $monthly_amount, $billing_start_date, $billing_day, $due_days, $auto_invoice, $clientLogoPath
            ]);

            $new_id = $pdo->lastInsertId();


            // Handle Initial Quotation Upload if attached
            if (!empty($_FILES['quotation_file']['name'])) {
                $qUploadDir = '../../assets/uploads/quotations/';
                if (!is_dir($qUploadDir)) {
                    @mkdir($qUploadDir, 0777, true);
                }
                $qExt = strtolower(pathinfo($_FILES['quotation_file']['name'], PATHINFO_EXTENSION));
                if (in_array($qExt, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'])) {
                    $qFileName = 'QUOT_' . $new_id . '_' . time() . '.' . $qExt;
                    $qTargetPath = $qUploadDir . $qFileName;
                    $qDbPath = 'assets/uploads/quotations/' . $qFileName;

                    if (move_uploaded_file($_FILES['quotation_file']['tmp_name'], $qTargetPath)) {
                        $qTitle = trim($_POST['quotation_title'] ?? '') ?: 'Initial Client Quotation';
                        $qType = $_POST['quotation_type'] ?? 'sent';
                        $qAmt = (float)($_POST['quotation_amount'] ?? 0);
                        $qScope = trim($_POST['quotation_scope'] ?? '');

                        $insQ = $pdo->prepare("INSERT INTO client_quotations (
                            customer_id, title, amount, scope_of_work, quotation_type, status, file_path, quotation_date, created_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, 'approved', ?, NOW(), ?, NOW())");
                        $insQ->execute([$new_id, $qTitle, $qAmt, $qScope, $qType, $qDbPath, $_SESSION['user_id'] ?? 1]);
                    }
                }
            }


            // Process Multiple Service Packages & Auto-Create Projects
            $service_names   = $_POST['service_name'] ?? [];
            $service_types   = $_POST['service_type'] ?? [];
            $service_amounts = $_POST['service_amount'] ?? [];

            $monthly_names = [];
            $total_monthly_amount = 0;

            if (is_array($service_names)) {
                for ($i = 0; $i < count($service_names); $i++) {
                    $sName = trim($service_names[$i] ?? '');
                    if (empty($sName)) continue;
                    $sType = $service_types[$i] ?? 'monthly';
                    $sAmt  = (float)($service_amounts[$i] ?? 0);

                    // 1. Create Project in projects table automatically
                    $pType = ($sType === 'one_time') ? 'One-Time' : 'Monthly';
                    $insProj = $pdo->prepare("INSERT INTO projects (customer_id, project_name, project_type, start_date, agreed_amount, status, description, created_at) VALUES (?, ?, ?, ?, ?, 'Active', ?, NOW())");
                    $insProj->execute([$new_id, $sName, $pType, $onboard_date, $sAmt, "Auto-created service package during client onboarding"]);
                    $project_id = $pdo->lastInsertId();

                    // 2. Create Service entry in customer_services table
                    $insServ = $pdo->prepare("INSERT INTO customer_services (customer_id, project_id, service_name, billing_type, amount, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
                    $insServ->execute([$new_id, $project_id, $sName, $sType, $sAmt]);

                    if ($sType === 'monthly') {
                        $monthly_names[] = $sName;
                        $total_monthly_amount += $sAmt;
                    }
                }
            }

            // Legacy fallback if single service field was filled
            if (empty($monthly_names) && !empty($monthly_service_name)) {
                $monthly_names[] = $monthly_service_name;
                $total_monthly_amount = $monthly_amount;

                $insProj = $pdo->prepare("INSERT INTO projects (customer_id, project_name, project_type, start_date, agreed_amount, status, description, created_at) VALUES (?, ?, 'Monthly', ?, ?, 'Active', ?, NOW())");
                $insProj->execute([$new_id, $monthly_service_name, $onboard_date, $monthly_amount, "Auto-created service package during client onboarding"]);
                $project_id = $pdo->lastInsertId();

                $insServ = $pdo->prepare("INSERT INTO customer_services (customer_id, project_id, service_name, billing_type, amount, status, created_at) VALUES (?, ?, ?, 'monthly', ?, 'active', NOW())");
                $insServ->execute([$new_id, $project_id, $monthly_service_name, $monthly_amount]);
            }

            if (!empty($monthly_names)) {
                $combined_name = implode(', ', $monthly_names);
                $updCust = $pdo->prepare("UPDATE customers SET monthly_service_name = ?, monthly_amount = ? WHERE id = ?");
                $updCust->execute([$combined_name, $total_monthly_amount, $new_id]);
            }

            set_flash('Client added & logo uploaded successfully!', 'success');

            header('Location: ' . BASE_URL . 'modules/customers/list.php');
            exit;
        }
    }
}
?>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-person-plus text-primary me-2"></i>Add New Client</h4>
      <p class="text-muted small mb-0">Quick onboarding — fill client details and assign service packages to create projects.</p>
    </div>
    <a href="list.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <i class="bi bi-exclamation-triangle me-2"></i><?= e($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">


    <div class="row g-4">

      <!-- LEFT: Essential Info -->
      <div class="col-lg-7">

        <!-- Core Info -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px;">
          <div class="card-header bg-white py-3 border-bottom">
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-building me-2 text-primary"></i>Client Information <span class="badge bg-danger ms-1" style="font-size:0.65rem;">Required</span></h6>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label fw-medium">Client / Company Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control form-control-lg" placeholder="e.g. Acme Pvt Ltd" required autofocus>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-medium">Client Type</label>
                <select name="client_type" id="client_type" class="form-select form-select-lg">
                  <option value="monthly">Monthly Retainer</option>
                  <option value="one_time">One-Time</option>
                  <option value="project">Project-Based</option>
                  <option value="retainer">Retainer</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-medium"><i class="bi bi-image text-primary me-1"></i>Client Logo (Upload Image)</label>
                <input type="file" name="client_logo" class="form-control" accept="image/*">
                <small class="text-muted" style="font-size:0.75rem;">PNG, JPG, WEBP, SVG avatar logo.</small>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-medium">Contact Person</label>
                <input type="text" name="contact_person" class="form-control" placeholder="Primary contact name">
              </div>

              <div class="col-md-6">
                <label class="form-label fw-medium">Phone / WhatsApp</label>
                <input type="text" name="phone" class="form-control" placeholder="+91 9876543210" oninput="document.querySelector('[name=whatsapp]').value = this.value">
                <input type="hidden" name="whatsapp">
              </div>
              <div class="col-md-6">
                <label class="form-label fw-medium">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="billing@company.com">
              </div>
              <div class="col-md-3">
                <label class="form-label fw-medium">Status</label>
                <select name="status" class="form-select">
                  <option value="active">Active</option>
                  <option value="lead">Lead</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-medium">Onboard Date</label>
                <input type="date" name="onboard_date" class="form-control" value="<?= date('Y-m-d') ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- Address & Location (Required) -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px;">
          <div class="card-header bg-white py-3 border-bottom">
            <h6 class="fw-bold mb-0 text-dark">
              <i class="bi bi-geo-alt me-2 text-primary"></i>Billing Address & Location 
              <span class="badge bg-danger ms-1" style="font-size:0.65rem;">Required</span>
            </h6>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label fw-medium">Billing Address <span class="text-danger">*</span></label>
                <textarea name="billing_address" class="form-control" rows="2" placeholder="Full street address, building, premises..." required><?= e($_POST['billing_address'] ?? '') ?></textarea>
              </div>
              <div class="col-md-5">
                <label class="form-label fw-medium">City <span class="text-danger">*</span></label>
                <input type="text" name="city" class="form-control" placeholder="e.g. Dehradun" value="<?= e($_POST['city'] ?? '') ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-medium">State <span class="text-danger">*</span></label>
                <select name="state" id="client_state" class="form-select" required>
                  <option value="">-- Select State / UT --</option>
                  <?php foreach (get_indian_gst_states() as $stCode => $stName): ?>
                    <option value="<?= e($stName) ?>" data-code="<?= $stCode ?>" <?= (($_POST['state'] ?? '') === $stName || normalize_gst_state_code($_POST['state_code'] ?? '') === $stCode) ? 'selected' : '' ?>>
                      <?= e($stName) ?> (<?= $stCode ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-medium">PIN Code <span class="text-danger">*</span></label>
                <input type="text" name="pincode" class="form-control" placeholder="e.g. 248001" value="<?= e($_POST['pincode'] ?? '') ?>" maxlength="10" required>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-medium">State Code</label>
                <input type="text" name="state_code" id="client_state_code" class="form-control bg-light" placeholder="05" value="<?= e($_POST['state_code'] ?? '') ?>" readonly>
              </div>
              <div class="col-md-5">
                <label class="form-label fw-medium">GSTIN</label>
                <input type="text" name="gstin" id="client_gstin" class="form-control text-uppercase" placeholder="05AAAAA0000A1Z5" value="<?= e($_POST['gstin'] ?? '') ?>" maxlength="15">
                <small class="text-muted" style="font-size:0.75rem;">15-digit GSTIN (Leave empty if client has no GSTIN).</small>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-medium">PAN</label>
                <input type="text" name="pan" id="client_pan" class="form-control text-uppercase" placeholder="ABCDE1234F" value="<?= e($_POST['pan'] ?? '') ?>" maxlength="10">
              </div>
            </div>
          </div>
        </div>

        <!-- Additional Details (collapsible) -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px;">
          <div class="card-header bg-white py-3 border-bottom" style="cursor:pointer;" onclick="document.getElementById('advancedCollapse').classList.toggle('show')">
            <h6 class="fw-bold mb-0 text-dark d-flex align-items-center justify-content-between">
              <span><i class="bi bi-sliders me-2 text-secondary"></i>Additional Details <small class="text-muted fw-normal">(Shipping, Notes, Web)</small></span>
              <i class="bi bi-chevron-down text-muted"></i>
            </h6>
          </div>
          <div class="collapse" id="advancedCollapse">
            <div class="card-body">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label fw-medium">Shipping Address <small class="text-muted">(Optional)</small></label>
                  <textarea name="shipping_address" class="form-control" rows="2" placeholder="Leave blank if same as billing address..."><?= e($_POST['shipping_address'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label fw-medium">Alternate Phone</label>
                  <input type="text" name="alternate_phone" class="form-control" value="<?= e($_POST['alternate_phone'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label fw-medium">Website</label>
                  <input type="url" name="website" class="form-control" placeholder="https://..." value="<?= e($_POST['website'] ?? '') ?>">
                </div>
                <div class="col-12">
                  <label class="form-label fw-medium text-dark"><i class="bi bi-card-text text-primary me-1"></i> Client Overview Message & Scope Notes</label>
                  <textarea name="overview_notes" class="form-control" rows="3" placeholder="Overview notes / summary of agreed work for quick reference on client profile..."><?= e($_POST['overview_notes'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                  <label class="form-label fw-medium">Internal Private Notes</label>
                  <textarea name="notes" class="form-control" rows="2" placeholder="Private internal notes, payment terms, preferences..."><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Initial Quotation Upload Card -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px;">
          <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-file-earmark-text text-primary me-2"></i>Initial Client Quotation <small class="text-muted fw-normal">(Optional)</small></h6>
            <span class="badge bg-primary-subtle text-primary">PDF / Image</span>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label small fw-medium">Quotation Title</label>
                <input type="text" name="quotation_title" class="form-control form-control-sm" placeholder="e.g. Approved Website Development Quotation">
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-medium">Quotation Type</label>
                <select name="quotation_type" class="form-select form-select-sm">
                  <option value="final">Final Approved</option>
                  <option value="sent" selected>Sent to Client</option>
                  <option value="revised">Revised</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-medium">Quotation Amount (₹)</label>
                <input type="number" step="0.01" name="quotation_amount" class="form-control form-control-sm" placeholder="0.00">
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-medium">Upload Quotation File</label>
                <input type="file" name="quotation_file" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
              </div>
              <div class="col-12">
                <label class="form-label small fw-medium">Scope of Work / Key Terms Summary</label>
                <textarea name="quotation_scope" class="form-control form-control-sm" rows="2" placeholder="Short description of items / work scope included in this quotation..."></textarea>
              </div>
            </div>
          </div>
        </div>

      </div>


      <!-- RIGHT: Service Packages & Auto Project Creation -->
      <div class="col-lg-5">

        <!-- Service Packages Setup -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px;">
          <div class="card-header py-3 border-bottom d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #0d6efd15, #7c3aed15);">
            <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-kanban me-2"></i>Service Packages & Projects</h6>
            <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" id="addServiceRowBtn">
              <i class="bi bi-plus-circle me-1"></i> Add Service
            </button>
          </div>
          <div class="card-body">
            <p class="text-muted small mb-3"><i class="bi bi-info-circle text-primary me-1"></i>Each service package added here will automatically create a project for this client.</p>

            <div id="serviceRowsContainer">
              <!-- Default Service Row -->
              <div class="service-row card border-light bg-light p-3 mb-2" style="border-radius: 10px;">
                <div class="row g-2">
                  <div class="col-12">
                    <label class="form-label small fw-medium text-dark mb-1">Service Package Name <span class="text-danger">*</span></label>
                    <input type="text" name="service_name[]" class="form-control form-control-sm" placeholder="e.g. Monthly Meta Ads" required>
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
                    <input type="number" step="0.01" name="service_amount[]" class="form-control form-control-sm fw-semibold" placeholder="15000.00" required>
                  </div>
                </div>
              </div>
            </div>

            <hr class="my-3">

            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="form-label fw-medium">Billing Day of Month</label>
                <select name="billing_day" class="form-select">
                  <?php for($d=1; $d<=28; $d++): ?>
                    <option value="<?= $d ?>"><?= $d ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label fw-medium">Due Days After Invoice</label>
                <input type="number" name="due_days" class="form-control" value="10" min="0" max="60">
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-medium">Billing Start Date</label>
              <input type="date" name="billing_start_date" class="form-control" value="<?= date('Y-m-01') ?>">
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="auto_invoice" value="1" id="autoInvoice" checked>
              <label class="form-check-label fw-medium" for="autoInvoice">Auto Generate Monthly Invoice</label>
            </div>
          </div>
        </div>

        <!-- Save Actions -->
        <div class="card border-0 shadow-sm" style="border-radius: 14px;">
          <div class="card-body">
            <div class="d-grid gap-2">
              <button type="submit" class="btn btn-primary btn-lg fw-semibold shadow-sm">
                <i class="bi bi-check-circle me-1"></i> Save Client & Create Projects
              </button>
              <a href="list.php" class="btn btn-light border fw-medium">Cancel</a>
            </div>
            <p class="text-muted small text-center mt-3 mb-0">
              <i class="bi bi-info-circle me-1"></i>Projects will be created automatically in Project Management.
            </p>
          </div>
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
    <div class="d-flex justify-content-between align-items-center mb-1">
      <span class="small fw-bold text-primary"><i class="bi bi-box-seam me-1"></i>Additional Service Package</span>
      <button type="button" class="btn btn-sm btn-link text-danger p-0 text-decoration-none remove-service-btn"><i class="bi bi-trash"></i> Remove</button>
    </div>
    <div class="row g-2">
      <div class="col-12">
        <label class="form-label small fw-medium text-dark mb-1">Service Package Name <span class="text-danger">*</span></label>
        <input type="text" name="service_name[]" class="form-control form-control-sm" placeholder="e.g. Monthly Google Ads" required>
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
        <input type="number" step="0.01" name="service_amount[]" class="form-control form-control-sm fw-semibold" placeholder="20000.00" required>
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
      alert('At least one service package is required for onboarding a client.');
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
    // Set initial state code if pre-selected
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
