<?php
// employee/profile.php — Comprehensive Employee Profile, Documents & Security Management
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/profile_handler.php';

$empId = (int)($_SESSION['employee_id'] ?? 0);
if ($empId <= 0) {
    abort_forbidden("No verified employee profile linked to your account.");
}
\App\Security\SecurityHeaders::applyNoCache();
$csrf = csrf_token();
$msg = '';
$err = '';

// Handle Profile & Documents Update
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['form_action'] ?? '') === 'update_profile') {
    $res = handle_employee_profile_update($pdo, $empId, (int)($_SESSION['user_id'] ?? 0));
    if ($res['success']) {
        $msg = $res['message'];
        // Refresh curEmp
        $eStmt = $pdo->prepare("SELECT e.*, d.name as dept_title, des.name as desig_title 
                                FROM employees e 
                                LEFT JOIN departments d ON d.id = e.department_id 
                                LEFT JOIN designations des ON des.id = e.designation_id 
                                WHERE e.id = ?");
        $eStmt->execute([$empId]);
        $curEmp = $eStmt->fetch();
    } else {
        $err = $res['error'];
    }
}

// Handle Password Change POST
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['form_action'] ?? '') === 'change_password') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = "Invalid security token.";
    } else {
        $oldPass  = $_POST['old_password'] ?? '';
        $newPass  = $_POST['new_password'] ?? '';
        $confPass = $_POST['confirm_password'] ?? '';

        $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $uStmt->execute([$_SESSION['user_id']]);
        $usr = $uStmt->fetch();

        if (!$usr || !password_verify($oldPass, $usr['password_hash'])) {
            $err = "Incorrect current password.";
        } elseif (strlen($newPass) < 6) {
            $err = "New password must be at least 6 characters long.";
        } elseif ($newPass !== $confPass) {
            $err = "New password and confirmation do not match.";
        } else {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $usr['id']]);

            // Revoke all other active sessions upon password change
            $currHash = hash('sha256', session_id());
            $pdo->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND session_id_hash != ?")
                ->execute([$usr['id'], $currHash]);

            \App\Security\AuditLogger::log('PASSWORD_CHANGE', 'auth', 'user', $usr['id']);
            create_action_notification($pdo, (int)$usr['id'], 'Password Changed', 'Your account password was changed successfully.', '/employee/profile.php', 'password_changed');
            $msg = "Password updated successfully! Other active sessions have been signed out.";
        }
    }
}

// Auto-sync employee_documents with employees table if missing
if (empty($curEmp['resume_file'])) {
    $sR = $pdo->prepare("SELECT file_path FROM employee_documents WHERE employee_id = ? AND doc_type = 'Resume' ORDER BY id DESC LIMIT 1");
    $sR->execute([$empId]);
    if ($fR = $sR->fetchColumn()) {
        $curEmp['resume_file'] = $fR;
        $pdo->prepare("UPDATE employees SET resume_file = ? WHERE id = ?")->execute([$fR, $empId]);
    }
}
if (empty($curEmp['aadhaar_file'])) {
    $sA = $pdo->prepare("SELECT file_path FROM employee_documents WHERE employee_id = ? AND doc_type = 'ID Proof' ORDER BY id DESC LIMIT 1");
    $sA->execute([$empId]);
    if ($fA = $sA->fetchColumn()) {
        $curEmp['aadhaar_file'] = $fA;
        $pdo->prepare("UPDATE employees SET aadhaar_file = ? WHERE id = ?")->execute([$fA, $empId]);
    }
}
if (empty($curEmp['offer_letter_file'])) {
    $sO = $pdo->prepare("SELECT file_path FROM employee_documents WHERE employee_id = ? AND doc_type = 'Offer Letter' ORDER BY id DESC LIMIT 1");
    $sO->execute([$empId]);
    if ($fO = $sO->fetchColumn()) {
        $curEmp['offer_letter_file'] = $fO;
        $pdo->prepare("UPDATE employees SET offer_letter_file = ?, offer_letter_status = 'Sent' WHERE id = ?")->execute([$fO, $empId]);
    }
}

$profileScore = calculate_profile_completion($curEmp);
$empPhotoUrl = (!empty($curEmp['profile_photo']) && file_exists(__DIR__ . '/../' . $curEmp['profile_photo'])) 
    ? BASE_URL . e($curEmp['profile_photo']) 
    : "https://ui-avatars.com/api/?name=" . urlencode($curEmp['full_name'] ?? 'User') . "&background=0d6efd&color=fff&size=160";

$empFirstName = !empty($curEmp['first_name']) ? $curEmp['first_name'] : (explode(' ', trim($curEmp['full_name'] ?? ''))[0] ?? '');
$empLastName  = !empty($curEmp['last_name']) ? $curEmp['last_name'] : trim(substr(trim($curEmp['full_name'] ?? ''), strlen($empFirstName)));
$circLen = 326.73; // 2 * PI * 52
$ringOffset = round($circLen * (1 - ($profileScore['percentage'] / 100)), 2);
$ringColor = $profileScore['percentage'] >= 100 ? '#10B981' : ($profileScore['percentage'] >= 60 ? '#2563EB' : '#F59E0B');
$settingsStmt = $pdo->query("SELECT * FROM settings ORDER BY id ASC LIMIT 1");
$companySettings = $settingsStmt ? $settingsStmt->fetch(PDO::FETCH_ASSOC) : [];
$companyName = !empty($companySettings['company_name']) ? $companySettings['company_name'] : 'VibeSoft';
$companyWebsite = !empty($companySettings['website']) ? $companySettings['website'] : 'vibesoft.in';
$cleanWebsite = preg_replace('#^https?://#i', '', rtrim($companyWebsite, '/'));
?>

<!-- Cropper.js for Interactive Profile Photo Cropping -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.1/dist/cropper.min.css">
<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.1/dist/cropper.min.js"></script>

<style>
/* Cropper Circular Crop View & Live Preview */
.cropper-view-box,
.cropper-face {
  border-radius: 50%;
}
.cropper-view-box {
  outline: 2px solid #2563eb !important;
  outline-color: rgba(37, 99, 235, 0.85) !important;
}
.crop-preview-box {
  overflow: hidden;
}

/* ID Card Design inspired by Reference Badge */
.id-card-wrapper {
  max-width: 325px;
  margin: 0 auto;
  background: #fdf2ec; /* Soft peach/cream outer card */
  border-radius: 24px;
  padding: 14px;
  box-shadow: 0 10px 28px rgba(10, 46, 92, 0.12);
  position: relative;
}

.id-card-top {
  position: relative;
  padding: 6px 4px 10px 4px;
  text-align: center;
}

.id-card-logo-badge {
  position: absolute;
  top: 4px;
  left: 6px;
  display: flex;
  align-items: center;
}

.id-card-company-name {
  font-size: 0.88rem;
  font-weight: 800;
  color: #0a2e5c;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  margin: 0;
  padding-left: 30px;
}

.id-card-avatar-wrap {
  position: relative;
  width: 120px;
  height: 120px;
  margin: 8px auto -36px auto;
  z-index: 5;
}

.id-card-dotted-shape {
  position: absolute;
  top: -8px;
  left: -12px;
  width: 140px;
  height: 115px;
  background-image: radial-gradient(#0a2e5c 1.5px, transparent 1.5px);
  background-size: 7px 7px;
  opacity: 0.28;
  border-radius: 45% 55% 42% 58% / 58% 42% 58% 42%;
  pointer-events: none;
  z-index: 1;
}

.id-card-avatar {
  position: relative;
  z-index: 2;
  width: 104px;
  height: 104px;
  border-radius: 50%;
  border: 4px solid #ffffff;
  box-shadow: 0 6px 16px rgba(10, 46, 92, 0.22);
  object-fit: cover;
  background: #ffffff;
}

.id-card-body {
  background: #0a2e5c; /* Deep royal navy */
  border-radius: 20px 20px 18px 18px;
  padding: 44px 16px 16px 16px;
  color: #ffffff;
  position: relative;
  overflow: hidden;
}

.id-card-emp-name {
  font-size: 0.96rem;
  font-weight: 700;
  letter-spacing: 0.8px;
  color: #ffffff;
  text-transform: uppercase;
  text-align: center;
  margin-bottom: 2px;
}

.id-card-emp-desig {
  font-size: 0.74rem;
  color: rgba(255, 255, 255, 0.85);
  font-weight: 400;
  text-align: center;
  margin-bottom: 16px;
}

.id-card-crosses {
  position: absolute;
  color: rgba(255, 255, 255, 0.35);
  font-size: 0.62rem;
  line-height: 1.35;
  font-family: monospace;
  font-weight: bold;
  user-select: none;
}
.id-card-crosses.left {
  left: 10px;
  top: 54%;
}
.id-card-crosses.right {
  right: 10px;
  top: 42%;
}

.id-card-info-list {
  padding: 0 8px 0 14px;
  margin-bottom: 12px;
}

.id-card-info-item {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
  font-size: 0.73rem;
  color: rgba(255, 255, 255, 0.94);
  line-height: 1.3;
}

.id-card-info-item i {
  font-size: 0.82rem;
  color: #ffffff;
  width: 16px;
  flex-shrink: 0;
  text-align: center;
}

.id-card-info-item span {
  word-break: break-word;
}

.id-card-bottom-accent {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  padding: 0 4px 2px 6px;
}

.id-card-corner-line {
  width: 50%;
  height: 20px;
  border-left: 2px solid rgba(255, 255, 255, 0.75);
  border-bottom: 2px solid rgba(255, 255, 255, 0.75);
  border-bottom-left-radius: 12px;
}

.id-card-socials {
  display: flex;
  align-items: center;
  gap: 8px;
  color: rgba(255, 255, 255, 0.85);
  font-size: 0.75rem;
}

/* Horizontal Profile Tabs Navigation */
.profile-horizontal-nav {
  overflow-x: auto;
  flex-wrap: nowrap;
  scrollbar-width: none;
  -ms-overflow-style: none;
  border-bottom: 2px solid #edf2f7;
  gap: 4px;
}
.profile-horizontal-nav::-webkit-scrollbar {
  display: none;
  width: 0;
  height: 0;
}
.profile-horizontal-nav .nav-link {
  color: #64748b;
  font-size: 0.85rem;
  font-weight: 600;
  padding: 12px 18px;
  border: none;
  border-bottom: 3px solid transparent;
  border-radius: 8px 8px 0 0;
  white-space: nowrap;
  transition: all 0.2s ease;
  background: transparent;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.profile-horizontal-nav .nav-link:hover {
  color: #0f172a;
  background: #f8fafc;
}
.profile-horizontal-nav .nav-link.active {
  color: #2563eb !important;
  background: #eff6ff !important;
  border-bottom: 3px solid #2563eb !important;
}

@media (max-width: 575.98px) {
  .profile-horizontal-nav .nav-link {
    font-size: 0.78rem;
    padding: 10px 12px;
    gap: 4px;
  }
}
</style>

<div class="container-fluid px-0">

  <?php if ($msg): ?>
  <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius:12px;">
    <i class="bi bi-check-circle-fill me-2"></i><?= e($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <?php if ($err): ?>
  <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius:12px;">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($err) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Left Column: Employee ID Card Only -->
    <div class="col-lg-4">
      
      <!-- Employee ID Card Badge (Dual-tone UI matching reference image) -->
      <div class="id-card-wrapper mb-4">
        <!-- Top Bar: Logo & Company Name + Very Small Eye Icon -->
        <div class="id-card-top">
          <div class="id-card-logo-badge">
            <?php $logoUrl = get_company_logo(); ?>
            <?php if (!empty($logoUrl)): ?>
              <img src="<?= $logoUrl ?>" alt="Logo" style="height: 18px; max-width: 55px; object-fit: contain;">
            <?php else: ?>
              <span style="font-size:0.75rem; font-weight:800; color:#0a2e5c;">LOGO</span>
            <?php endif; ?>
          </div>
          <div class="id-card-company-name"><?= strtoupper(e($companyName)) ?></div>
          <!-- Very small eye icon to trigger ID card modal popup -->
          <button type="button" class="btn btn-link p-0 text-dark border-0" 
                  data-bs-toggle="modal" data-bs-target="#idCardModal" 
                  title="View ID Card Popup" 
                  style="position: absolute; top: 4px; right: 6px; font-size: 0.8rem; color: #0a2e5c !important; text-decoration: none; opacity: 0.75; cursor: pointer;">
            <i class="bi bi-eye"></i>
          </button>
        </div>

        <!-- Center Avatar with Dotted Accent Shape -->
        <div class="id-card-avatar-wrap">
          <div class="id-card-dotted-shape"></div>
          <img id="profileCardAvatar" src="<?= $empPhotoUrl ?>" class="id-card-avatar" alt="<?= e($curEmp['full_name']) ?>">
        </div>

        <!-- Deep Navy Blue Card Body -->
        <div class="id-card-body">
          <!-- Decorative vertical cross markers -->
          <div class="id-card-crosses left">×<br>×<br>×<br>×</div>
          <div class="id-card-crosses right">×<br>×<br>×<br>×</div>

          <!-- Employee Name & Designation -->
          <div class="id-card-emp-name"><?= strtoupper(e($curEmp['full_name'])) ?></div>
          <div class="id-card-emp-desig"><?= e($curEmp['designation'] ?? 'Developer') ?></div>

          <!-- Contact Info List -->
          <div class="id-card-info-list">
            <?php if (!empty($curEmp['phone'])): ?>
            <div class="id-card-info-item">
              <i class="bi bi-telephone-fill"></i>
              <span>+91 <?= e($curEmp['phone']) ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($curEmp['email'])): ?>
            <div class="id-card-info-item">
              <i class="bi bi-envelope-fill"></i>
              <span><?= e(strtolower($curEmp['email'])) ?></span>
            </div>
            <?php endif; ?>

            <div class="id-card-info-item">
              <i class="bi bi-globe2"></i>
              <span><?= e($cleanWebsite) ?></span>
            </div>

            <?php 
            $addressStr = !empty($curEmp['address']) ? $curEmp['address'] : trim(($curEmp['city'] ?? '') . ', ' . ($curEmp['state'] ?? ''));
            if (!empty($curEmp['pincode']) && strpos($addressStr, $curEmp['pincode']) === false) {
                $addressStr .= ' ' . $curEmp['pincode'];
            }
            ?>
            <?php if (!empty($addressStr)): ?>
            <div class="id-card-info-item">
              <i class="bi bi-geo-alt-fill"></i>
              <span><?= e($addressStr) ?></span>
            </div>
            <?php endif; ?>
          </div>

          <!-- Bottom Stylized Corner Accent & Social Icons -->
          <div class="id-card-bottom-accent">
            <div class="id-card-corner-line"></div>
            <div class="id-card-socials">
              <i class="bi bi-instagram"></i>
              <i class="bi bi-facebook"></i>
              <i class="bi bi-google"></i>
            </div>
          </div>
        </div>
      </div>

    </div>

    <!-- Right Column: Horizontal Tabs Navigation & Content -->
    <div class="col-lg-8">
      
      <div class="card border-0 shadow-sm" style="border-radius:16px; overflow:hidden;">
        <!-- Horizontal Tabs Header -->
        <div class="card-header bg-white border-bottom px-3 pt-3 pb-0">
          <ul class="nav nav-tabs profile-horizontal-nav border-0" id="profileTabList" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" id="tab-personal-btn" data-bs-toggle="tab" data-bs-target="#tab-personal" type="button" role="tab" aria-controls="tab-personal" aria-selected="true">
                <i class="bi bi-person text-info"></i> Personal & Contact
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="tab-bank-btn" data-bs-toggle="tab" data-bs-target="#tab-bank" type="button" role="tab" aria-controls="tab-bank" aria-selected="false">
                <i class="bi bi-bank text-warning"></i> Bank & Identity
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="tab-docs-btn" data-bs-toggle="tab" data-bs-target="#tab-docs" type="button" role="tab" aria-controls="tab-docs" aria-selected="false">
                <i class="bi bi-file-earmark-arrow-up text-success"></i> Resume & Govtment ID
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="tab-password-btn" data-bs-toggle="tab" data-bs-target="#tab-password" type="button" role="tab" aria-controls="tab-password" aria-selected="false">
                <i class="bi bi-shield-lock text-danger"></i> Change Password
              </button>
            </li>
          </ul>
        </div>

        <!-- Tab Content Area -->
        <div class="card-body p-4">
          <div class="tab-content" id="profileTabContent">

                <!-- TAB 1: Personal & Contact Info (Default Active) -->
                <div class="tab-pane fade show active" id="tab-personal" role="tabpanel" aria-labelledby="tab-personal-btn">

                  <form method="post" enctype="multipart/form-data" class="profile-update-form" id="personalProfileForm">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="form_action" value="update_profile">
                    <!-- Base64 Cropped Photo Payload -->
                    <input type="hidden" name="cropped_profile_photo" id="croppedProfilePhotoData" value="">
                    <!-- Preserve existing bank & doc values -->
                    <input type="hidden" name="bank_name" value="<?= e($curEmp['bank_name'] ?? '') ?>">
                    <input type="hidden" name="bank_acc_no" value="<?= e($curEmp['bank_acc_no'] ?? '') ?>">
                    <input type="hidden" name="bank_ifsc" value="<?= e($curEmp['bank_ifsc'] ?? '') ?>">
                    <input type="hidden" name="pan" value="<?= e($curEmp['pan'] ?? '') ?>">
                    <input type="hidden" name="aadhaar_ref" value="<?= e($curEmp['aadhaar_ref'] ?? '') ?>">

                    <!-- Profile Photo input with Live Cropper -->
                    <div class="p-3 bg-light rounded-3 border mb-3">
                      <div class="row align-items-center g-3">
                        <div class="col-auto">
                          <img id="profilePhotoPreview2" src="<?= $empPhotoUrl ?>" class="rounded-circle border shadow-sm" width="65" height="65" style="object-fit:cover;">
                        </div>
                        <div class="col">
                          <label class="form-label small fw-semibold mb-1">Profile Photo</label>
                          <div class="d-flex align-items-center gap-2">
                            <input type="file" name="profile_photo" id="profilePhotoInput" class="form-control form-control-sm profile-photo-input" accept="image/jpeg,image/png,image/webp">
                            <button type="button" class="btn btn-sm btn-outline-primary px-3 text-nowrap d-none" id="btnReCrop" title="Crop Selected Photo">
                              <i class="bi bi-crop me-1"></i> Crop
                            </button>
                          </div>
                          <small class="text-muted" style="font-size:0.72rem;">JPG, PNG, or WEBP (Max 5MB) · Image select karte hi interactive crop popup open hoga</small>
                        </div>
                      </div>
                    </div>

                    <div class="row g-3 mb-4">
                      <div class="col-md-6">
                        <label class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" class="form-control" value="<?= e($empFirstName) ?>" placeholder="Enter first name" required>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small fw-semibold">Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="<?= e($empLastName) ?>" placeholder="Enter last name">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small fw-semibold">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control lowercase-email" style="text-transform: lowercase;" value="<?= e(strtolower($curEmp['email'] ?? '')) ?>" placeholder="name@vibesoft.in" required>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small fw-semibold">Mobile Phone <span class="text-danger">*</span></label>
                        <input type="text" name="phone" class="form-control" value="<?= e($curEmp['phone'] ?? '') ?>" placeholder="10-digit mobile number" required>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small fw-semibold">WhatsApp Number</label>
                        <input type="text" name="whatsapp" class="form-control" value="<?= e($curEmp['whatsapp'] ?? '') ?>" placeholder="10-digit WhatsApp number">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small fw-semibold">Alternate Phone</label>
                        <input type="text" name="alternate_phone" class="form-control" value="<?= e($curEmp['alternate_phone'] ?? '') ?>" placeholder="Optional alternate phone number">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small fw-semibold">Emergency Contact Person</label>
                        <input type="text" name="emergency_contact" class="form-control" value="<?= e($curEmp['emergency_contact'] ?? '') ?>" placeholder="Relation / Name (e.g. Father, Spouse)">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small fw-semibold">Emergency Contact Phone</label>
                        <input type="text" name="emergency_contact_phone" class="form-control" value="<?= e($curEmp['emergency_contact_phone'] ?? '') ?>" placeholder="Emergency contact phone number">
                      </div>
                      <div class="col-12">
                        <label class="form-label small fw-semibold">Residential Address</label>
                        <textarea name="address" class="form-control" rows="2" placeholder="House / Flat No., Street, Area / Landmark"><?= e($curEmp['address'] ?? '') ?></textarea>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small fw-semibold">City</label>
                        <input type="text" name="city" class="form-control" value="<?= e($curEmp['city'] ?? '') ?>" placeholder="City">
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small fw-semibold">State</label>
                        <input type="text" name="state" class="form-control" value="<?= e($curEmp['state'] ?? '') ?>" placeholder="State">
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small fw-semibold">Pincode</label>
                        <input type="text" name="pincode" class="form-control" value="<?= e($curEmp['pincode'] ?? '') ?>" placeholder="6-digit PIN code">
                      </div>
                    </div>

                    <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold shadow-sm" style="border-radius:10px;">
                      <i class="bi bi-save me-1"></i> Save Personal & Contact Info
                    </button>
                  </form>
                </div>

                <!-- TAB 2: Bank & Identity Details -->
                <div class="tab-pane fade" id="tab-bank" role="tabpanel" aria-labelledby="tab-bank-btn">

                  <form method="post" enctype="multipart/form-data" class="profile-update-form">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="form_action" value="update_profile">
                    <!-- Preserve personal info values -->
                    <input type="hidden" name="first_name" value="<?= e($empFirstName) ?>">
                    <input type="hidden" name="last_name" value="<?= e($empLastName) ?>">
                    <input type="hidden" name="email" value="<?= e(strtolower($curEmp['email'] ?? '')) ?>">
                    <input type="hidden" name="phone" value="<?= e($curEmp['phone'] ?? '') ?>">
                    <input type="hidden" name="whatsapp" value="<?= e($curEmp['whatsapp'] ?? '') ?>">
                    <input type="hidden" name="alternate_phone" value="<?= e($curEmp['alternate_phone'] ?? '') ?>">
                    <input type="hidden" name="emergency_contact" value="<?= e($curEmp['emergency_contact'] ?? '') ?>">
                    <input type="hidden" name="emergency_contact_phone" value="<?= e($curEmp['emergency_contact_phone'] ?? '') ?>">
                    <input type="hidden" name="address" value="<?= e($curEmp['address'] ?? '') ?>">
                    <input type="hidden" name="city" value="<?= e($curEmp['city'] ?? '') ?>">
                    <input type="hidden" name="state" value="<?= e($curEmp['state'] ?? '') ?>">
                    <input type="hidden" name="pincode" value="<?= e($curEmp['pincode'] ?? '') ?>">

                    <div class="row g-3 mb-4">
                      <div class="col-md-6">
                        <label class="form-label small fw-semibold">Bank Name</label>
                        <input type="text" name="bank_name" class="form-control" value="<?= e($curEmp['bank_name'] ?? '') ?>" placeholder="e.g. State Bank of India, HDFC">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small fw-semibold">Bank Account Number</label>
                        <input type="text" name="bank_acc_no" class="form-control font-monospace" value="<?= e($curEmp['bank_acc_no'] ?? '') ?>" placeholder="Account number">
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small fw-semibold">IFSC Code</label>
                        <input type="text" name="bank_ifsc" class="form-control text-uppercase font-monospace" value="<?= e($curEmp['bank_ifsc'] ?? '') ?>" placeholder="SBIN0001234">
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small fw-semibold">PAN Card Number</label>
                        <input type="text" name="pan" class="form-control text-uppercase font-monospace" value="<?= e($curEmp['pan'] ?? '') ?>" placeholder="ABCDE1234F">
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small fw-semibold">Aadhaar Number</label>
                        <input type="text" name="aadhaar_ref" class="form-control font-monospace" value="<?= e($curEmp['aadhaar_ref'] ?? '') ?>" placeholder="12-digit Aadhaar Number">
                      </div>
                    </div>

                    <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold shadow-sm" style="border-radius:10px;">
                      <i class="bi bi-save me-1"></i> Save Bank & Identity Details
                    </button>
                  </form>
                </div>

                <!-- TAB 3: Upload Resume & Govtment ID -->
                <div class="tab-pane fade" id="tab-docs" role="tabpanel" aria-labelledby="tab-docs-btn">

                  <form method="post" enctype="multipart/form-data" class="profile-update-form">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="form_action" value="update_profile">
                    <!-- Preserve personal & bank info -->
                    <input type="hidden" name="first_name" value="<?= e($empFirstName) ?>">
                    <input type="hidden" name="last_name" value="<?= e($empLastName) ?>">
                    <input type="hidden" name="email" value="<?= e(strtolower($curEmp['email'] ?? '')) ?>">
                    <input type="hidden" name="phone" value="<?= e($curEmp['phone'] ?? '') ?>">
                    <input type="hidden" name="bank_name" value="<?= e($curEmp['bank_name'] ?? '') ?>">
                    <input type="hidden" name="bank_acc_no" value="<?= e($curEmp['bank_acc_no'] ?? '') ?>">
                    <input type="hidden" name="bank_ifsc" value="<?= e($curEmp['bank_ifsc'] ?? '') ?>">
                    <input type="hidden" name="pan" value="<?= e($curEmp['pan'] ?? '') ?>">
                    <input type="hidden" name="aadhaar_ref" value="<?= e($curEmp['aadhaar_ref'] ?? '') ?>">

                    <div class="row g-3 mb-4">
                      <?php if (!empty($curEmp['offer_letter_file'])): 
                        $olCleanUrl = BASE_URL . str_replace(' ', '%20', ltrim($curEmp['offer_letter_file'], '/'));
                        $olDownloadUrl = BASE_URL . 'download.php?file=' . urlencode($curEmp['offer_letter_file']) . '&name=Official_Offer_Letter.pdf';
                      ?>
                        <div class="col-12">
                          <div class="p-3 rounded-3 border border-primary-subtle bg-primary-subtle d-flex flex-wrap align-items-center justify-content-between gap-2 shadow-sm">
                            <div class="d-flex align-items-center gap-3">
                              <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-white text-primary border shadow-sm" style="width: 44px; height: 44px;">
                                <i class="bi bi-file-earmark-check-fill fs-5"></i>
                              </span>
                              <div>
                                <div class="d-flex align-items-center gap-2">
                                  <h6 class="fw-bold text-dark mb-0">Official Offer Letter</h6>
                                  <span class="badge bg-success text-white px-2 py-0" style="font-size:0.7rem;">Verified on Record</span>
                                </div>
                                <div class="text-muted small" style="font-size:0.75rem;">Issued by VibeSoft Management • Official appointment letter</div>
                              </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                              <button type="button" class="btn btn-primary btn-sm px-3 fw-semibold shadow-sm"
                                      onclick="openEmpDocPreview('<?= $olCleanUrl ?>', 'Official Offer Letter', 'pdf', '<?= $olDownloadUrl ?>')">
                                <i class="bi bi-eye me-1"></i> View Offer Letter
                              </button>
                              <a href="<?= $olDownloadUrl ?>" class="btn btn-outline-primary btn-sm px-2 fw-semibold" title="Download to device">
                                <i class="bi bi-download"></i>
                              </a>
                            </div>
                          </div>
                        </div>
                      <?php endif; ?>

                      <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3 border h-100">
                          <label class="form-label small fw-semibold">Upload Resume / CV</label>
                          <input type="file" name="resume_file" class="form-control form-control-sm" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                          <small class="text-muted d-block mt-1" style="font-size:0.72rem;">PDF, DOC, DOCX formats (Max 10MB)</small>
                          <?php if (!empty($curEmp['resume_file'])): 
                            $rCleanUrl = BASE_URL . str_replace(' ', '%20', ltrim($curEmp['resume_file'], '/'));
                            $rExt = strtolower(pathinfo($curEmp['resume_file'], PATHINFO_EXTENSION));
                            $rDownloadUrl = BASE_URL . 'download.php?file=' . urlencode($curEmp['resume_file']) . '&name=' . urlencode('Resume_' . ($curEmp['first_name'] ?? 'Emp') . '.' . $rExt);
                          ?>
                            <div class="mt-2 small text-success d-flex align-items-center flex-wrap gap-2">
                              <span><i class="bi bi-check-circle-fill me-1"></i>Resume on record:</span>
                              <button type="button" class="btn btn-link btn-sm p-0 fw-semibold text-primary text-decoration-none"
                                      onclick="openEmpDocPreview('<?= $rCleanUrl ?>', 'Resume / CV', '<?= $rExt ?>', '<?= $rDownloadUrl ?>')">
                                <i class="bi bi-eye me-1"></i>View Current
                              </button>
                              <span class="text-muted">•</span>
                              <a href="<?= $rDownloadUrl ?>" class="btn btn-link btn-sm p-0 text-secondary text-decoration-none" title="Download file">
                                <i class="bi bi-download me-1"></i>Download
                              </a>
                            </div>
                          <?php endif; ?>
                        </div>
                      </div>

                      <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3 border h-100">
                          <label class="form-label small fw-semibold">Upload Govtment ID (Aadhaar Card / PAN Card)</label>
                          <input type="file" name="aadhaar_file" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                          <small class="text-muted d-block mt-1" style="font-size:0.72rem;">JPG, PNG, PDF, DOC, JPEG (Max 10MB) · Aadhaar ya PAN Card</small>
                          <?php if (!empty($curEmp['aadhaar_file'])): 
                            $aCleanUrl = BASE_URL . str_replace(' ', '%20', ltrim($curEmp['aadhaar_file'], '/'));
                            $aExt = strtolower(pathinfo($curEmp['aadhaar_file'], PATHINFO_EXTENSION));
                            $aDownloadUrl = BASE_URL . 'download.php?file=' . urlencode($curEmp['aadhaar_file']) . '&name=' . urlencode('GovtID_' . ($curEmp['first_name'] ?? 'Emp') . '.' . $aExt);
                          ?>
                            <div class="mt-2 small text-success d-flex align-items-center flex-wrap gap-2">
                              <span><i class="bi bi-check-circle-fill me-1"></i>Govtment ID on record:</span>
                              <button type="button" class="btn btn-link btn-sm p-0 fw-semibold text-primary text-decoration-none"
                                      onclick="openEmpDocPreview('<?= $aCleanUrl ?>', 'Govtment ID (Aadhaar / PAN)', '<?= $aExt ?>', '<?= $aDownloadUrl ?>')">
                                <i class="bi bi-eye me-1"></i>View Current
                              </button>
                              <span class="text-muted">•</span>
                              <a href="<?= $aDownloadUrl ?>" class="btn btn-link btn-sm p-0 text-secondary text-decoration-none" title="Download file">
                                <i class="bi bi-download me-1"></i>Download
                              </a>
                            </div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>

                    <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold shadow-sm" style="border-radius:10px;">
                      <i class="bi bi-cloud-upload me-1"></i> Upload Selected Documents
                    </button>
                  </form>
                </div>

                <!-- TAB 5: Change Account Password -->
                <div class="tab-pane fade" id="tab-password" role="tabpanel" aria-labelledby="tab-password-btn">

                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="form_action" value="change_password">

                    <div class="mb-3">
                      <label class="form-label small fw-semibold">Current Password <span class="text-danger">*</span></label>
                      <input type="password" name="old_password" class="form-control" placeholder="Enter current password" required>
                    </div>

                    <div class="row g-3 mb-3">
                      <div class="col-md-6">
                        <label class="form-label small fw-semibold">New Password <span class="text-danger">*</span></label>
                        <input type="password" name="new_password" class="form-control" placeholder="Min 6 characters" required>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small fw-semibold">Confirm New Password <span class="text-danger">*</span></label>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter new password" required>
                      </div>
                    </div>

                    <button type="submit" class="btn btn-outline-danger fw-semibold btn-sm px-4">
                      <i class="bi bi-key me-1"></i> Update Account Password
                    </button>
                  </form>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

<!-- ID Card Preview Modal Popup -->
<div class="modal fade" id="idCardModal" tabindex="-1" aria-labelledby="idCardModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 360px;">
    <div class="modal-content border-0 bg-transparent shadow-none">
      <div class="modal-header border-0 pb-1 justify-content-end">
        <button type="button" class="btn-close btn-close-white shadow-sm bg-white rounded-circle p-2" data-bs-dismiss="modal" aria-label="Close" style="opacity: 0.9;"></button>
      </div>
      <div class="modal-body p-0 d-flex justify-content-center">
        <!-- Enlarged ID Card Modal Content -->
        <div class="id-card-wrapper shadow-lg" style="width: 100%; max-width: 335px;">
          <!-- Top Bar: Logo & Company Name -->
          <div class="id-card-top">
            <div class="id-card-logo-badge">
              <?php if (!empty($logoUrl)): ?>
                <img src="<?= $logoUrl ?>" alt="Logo" style="height: 18px; max-width: 55px; object-fit: contain;">
              <?php else: ?>
                <span style="font-size:0.75rem; font-weight:800; color:#0a2e5c;">LOGO</span>
              <?php endif; ?>
            </div>
            <div class="id-card-company-name" style="padding-left: 0;"><?= strtoupper(e($companyName)) ?></div>
          </div>

          <!-- Center Avatar with Dotted Accent Shape -->
          <div class="id-card-avatar-wrap">
            <div class="id-card-dotted-shape"></div>
            <img id="profileCardAvatarModal" src="<?= $empPhotoUrl ?>" class="id-card-avatar" alt="<?= e($curEmp['full_name']) ?>">
          </div>

          <!-- Deep Navy Blue Card Body -->
          <div class="id-card-body">
            <!-- Decorative vertical cross markers -->
            <div class="id-card-crosses left">×<br>×<br>×<br>×</div>
            <div class="id-card-crosses right">×<br>×<br>×<br>×</div>

            <!-- Employee Name & Designation -->
            <div class="id-card-emp-name"><?= strtoupper(e($curEmp['full_name'])) ?></div>
            <div class="id-card-emp-desig"><?= e($curEmp['designation'] ?? 'Developer') ?></div>

            <!-- Contact Info List -->
            <div class="id-card-info-list">
              <?php if (!empty($curEmp['phone'])): ?>
              <div class="id-card-info-item">
                <i class="bi bi-telephone-fill"></i>
                <span>+91 <?= e($curEmp['phone']) ?></span>
              </div>
              <?php endif; ?>

              <?php if (!empty($curEmp['email'])): ?>
              <div class="id-card-info-item">
                <i class="bi bi-envelope-fill"></i>
                <span><?= e(strtolower($curEmp['email'])) ?></span>
              </div>
              <?php endif; ?>

              <div class="id-card-info-item">
                <i class="bi bi-globe2"></i>
                <span><?= e($cleanWebsite) ?></span>
              </div>

              <?php if (!empty($addressStr)): ?>
              <div class="id-card-info-item">
                <i class="bi bi-geo-alt-fill"></i>
                <span><?= e($addressStr) ?></span>
              </div>
              <?php endif; ?>
            </div>

            <!-- Bottom Stylized Corner Accent & Social Icons -->
            <div class="id-card-bottom-accent">
              <div class="id-card-corner-line"></div>
              <div class="id-card-socials">
                <i class="bi bi-instagram"></i>
                <i class="bi bi-facebook"></i>
                <i class="bi bi-google"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Profile Photo Crop Modal -->
<div class="modal fade" id="photoCropModal" tabindex="-1" aria-labelledby="photoCropModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px; overflow:hidden;">
      <div class="modal-header bg-light border-bottom py-3 px-4">
        <h5 class="modal-title fw-bold text-dark fs-6 mb-0" id="photoCropModalLabel">
          <i class="bi bi-crop text-primary me-2"></i>Crop & Set Profile Photo
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <div class="row g-4 align-items-center">
          <!-- Main Cropper Viewport -->
          <div class="col-md-8">
            <div class="border rounded bg-dark d-flex align-items-center justify-content-center overflow-hidden" style="min-height: 340px; max-height: 400px; background: #0f172a;">
              <img id="cropImageTarget" src="" alt="Crop Preview" style="max-width: 100%; display: block;">
            </div>
            <!-- Toolbar Controls -->
            <div class="d-flex flex-wrap align-items-center justify-content-center gap-2 mt-3">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCropRotateLeft" title="Rotate Left (90°)">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Rotate Left
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCropRotateRight" title="Rotate Right (90°)">
                <i class="bi bi-arrow-clockwise me-1"></i> Rotate Right
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCropZoomIn" title="Zoom In">
                <i class="bi bi-zoom-in me-1"></i> Zoom In
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCropZoomOut" title="Zoom Out">
                <i class="bi bi-zoom-out me-1"></i> Zoom Out
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCropReset" title="Reset Crop">
                <i class="bi bi-arrow-repeat me-1"></i> Reset
              </button>
            </div>
          </div>
          <!-- Side Preview Column -->
          <div class="col-md-4 text-center border-start ps-md-4">
            <div class="small fw-semibold text-muted mb-2">Live Avatar Preview</div>
            <div class="crop-preview-box rounded-circle shadow-sm overflow-hidden mx-auto mb-3" style="width: 120px; height: 120px; border: 3px solid #2563eb; background: #f8fafc;"></div>
            <p class="text-muted small mb-0" style="font-size: 0.76rem; line-height: 1.4;">
              Image ko drag ya zoom karke circle me fit karein. <strong>Crop & Set Photo</strong> click karne par ye aapki profile pic ban jayegi.
            </p>
          </div>
        </div>
      </div>
      <div class="modal-footer bg-light border-top py-2 px-4 justify-content-between">
        <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm px-4 fw-semibold shadow-sm" id="btnApplyCrop">
          <i class="bi bi-check-lg me-1"></i> Crop & Set Photo
        </button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  let cropperInstance = null;
  const photoInput = document.getElementById('profilePhotoInput');
  const cropModalEl = document.getElementById('photoCropModal');
  const cropModal = cropModalEl ? new bootstrap.Modal(cropModalEl) : null;
  const imageTarget = document.getElementById('cropImageTarget');
  const btnApplyCrop = document.getElementById('btnApplyCrop');
  const btnReCrop = document.getElementById('btnReCrop');

  if (photoInput) {
    photoInput.addEventListener('change', function() {
      const file = this.files && this.files[0];
      if (file) {
        if (!file.type.match(/^image\//)) {
          alert('Please select a valid image file (JPG, PNG, or WEBP).');
          return;
        }
        const reader = new FileReader();
        reader.onload = function(evt) {
          if (imageTarget) {
            imageTarget.src = evt.target.result;
            if (cropModal) cropModal.show();
          }
        };
        reader.readAsDataURL(file);
      }
    });
  }

  if (cropModalEl) {
    cropModalEl.addEventListener('shown.bs.modal', function() {
      if (cropperInstance) {
        cropperInstance.destroy();
      }
      if (imageTarget) {
        cropperInstance = new Cropper(imageTarget, {
          aspectRatio: 1,
          viewMode: 1,
          dragMode: 'move',
          autoCropArea: 0.9,
          restore: false,
          guides: true,
          center: true,
          highlight: false,
          cropBoxMovable: true,
          cropBoxResizable: true,
          toggleDragModeOnDblclick: false,
          preview: '.crop-preview-box'
        });
      }
    });

    cropModalEl.addEventListener('hidden.bs.modal', function() {
      if (cropperInstance) {
        cropperInstance.destroy();
        cropperInstance = null;
      }
    });
  }

  // Toolbar controls
  document.getElementById('btnCropRotateLeft')?.addEventListener('click', () => cropperInstance?.rotate(-90));
  document.getElementById('btnCropRotateRight')?.addEventListener('click', () => cropperInstance?.rotate(90));
  document.getElementById('btnCropZoomIn')?.addEventListener('click', () => cropperInstance?.zoom(0.1));
  document.getElementById('btnCropZoomOut')?.addEventListener('click', () => cropperInstance?.zoom(-0.1));
  document.getElementById('btnCropReset')?.addEventListener('click', () => cropperInstance?.reset());

  // Re-crop button handler
  if (btnReCrop) {
    btnReCrop.addEventListener('click', function() {
      if (imageTarget && imageTarget.src && cropModal) {
        cropModal.show();
      }
    });
  }

  // Apply Crop handler
  if (btnApplyCrop) {
    btnApplyCrop.addEventListener('click', function() {
      if (!cropperInstance) return;
      const canvas = cropperInstance.getCroppedCanvas({
        width: 400,
        height: 400,
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'high'
      });
      if (!canvas) return;

      const croppedDataUrl = canvas.toDataURL('image/jpeg', 0.92);

      // Store in hidden input for seamless backend processing
      const hiddenCropInput = document.getElementById('croppedProfilePhotoData');
      if (hiddenCropInput) hiddenCropInput.value = croppedDataUrl;

      // Update previews in real time
      const p2 = document.getElementById('profilePhotoPreview2');
      const pCard = document.getElementById('profileCardAvatar');
      const pModal = document.getElementById('profileCardAvatarModal');
      if (p2) p2.src = croppedDataUrl;
      if (pCard) pCard.src = croppedDataUrl;
      if (pModal) pModal.src = croppedDataUrl;

      // Show re-crop button
      if (btnReCrop) btnReCrop.classList.remove('d-none');

      // Create File from blob and set to input.files using DataTransfer
      canvas.toBlob(function(blob) {
        if (blob && photoInput) {
          try {
            const file = new File([blob], 'profile_cropped.jpg', { type: 'image/jpeg' });
            const dt = new DataTransfer();
            dt.items.add(file);
            photoInput.files = dt.files;
          } catch (err) {}
        }
      }, 'image/jpeg', 0.92);

      if (cropModal) cropModal.hide();
    });
  }

  // Handle auto-activating tab from URL parameter (?tab=docs, ?tab=bank, ?tab=password, etc.) or URL hash
  const urlParams = new URLSearchParams(window.location.search);
  const activeTabParam = urlParams.get('tab') || window.location.hash.replace('#', '');
  if (activeTabParam) {
    let targetTabBtn = null;
    if (activeTabParam === 'docs' || activeTabParam === 'tab-docs') targetTabBtn = document.getElementById('tab-docs-btn');
    else if (activeTabParam === 'bank' || activeTabParam === 'tab-bank') targetTabBtn = document.getElementById('tab-bank-btn');
    else if (activeTabParam === 'password' || activeTabParam === 'tab-password' || activeTabParam === 'security') targetTabBtn = document.getElementById('tab-password-btn');
    else if (activeTabParam === 'personal' || activeTabParam === 'tab-personal') targetTabBtn = document.getElementById('tab-personal-btn');

    if (targetTabBtn && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
      const tab = new bootstrap.Tab(targetTabBtn);
      tab.show();
    } else if (targetTabBtn) {
      targetTabBtn.click();
    }
  }

  // On mobile, ensure clicked tab scrolls into center view in the horizontal nav
  document.querySelectorAll('#profileTabList button[data-bs-toggle="tab"]').forEach(btn => {
    btn.addEventListener('shown.bs.tab', function() {
      this.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
    });
  });

  // Strict email lowercase enforcement in inputs
  document.querySelectorAll('.lowercase-email').forEach(input => {
    input.addEventListener('input', function() {
      this.value = this.value.toLowerCase();
    });
  });
});

// Global Universal Document Preview Modal Handler for Employee
function openEmpDocPreview(url, title, ext, downloadUrl) {
  if (!url) return;
  ext = (ext || '').toLowerCase().trim();
  downloadUrl = downloadUrl || url;
  title = title || 'Document';

  // Format categorization
  const imgExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'bmp', 'ico'];
  const officeWord = ['doc', 'docx'];
  const officeExcel = ['xls', 'xlsx'];
  const officePpt = ['ppt', 'pptx'];
  const textExts = ['txt', 'log', 'json', 'xml', 'sql', 'md', 'html', 'js', 'css'];

  // Choose header icon
  let iconClass = 'bi-file-earmark-text text-secondary';
  if (imgExts.includes(ext)) iconClass = 'bi-file-earmark-image text-success';
  else if (ext === 'pdf') iconClass = 'bi-file-earmark-pdf text-danger';
  else if (officeWord.includes(ext)) iconClass = 'bi-file-earmark-word text-primary';
  else if (officeExcel.includes(ext)) iconClass = 'bi-file-earmark-excel text-success';
  else if (officePpt.includes(ext)) iconClass = 'bi-file-earmark-ppt text-warning';
  else if (textExts.includes(ext) || ext === 'csv') iconClass = 'bi-file-earmark-code text-info';

  const titleEl = document.getElementById('empDocModalTitle');
  if (titleEl) {
    titleEl.innerHTML = `<i class="bi ${iconClass} me-2"></i> ${title} <span class="badge bg-light text-muted border ms-2 uppercase font-monospace">.${ext.toUpperCase()}</span>`;
  }
  const dlBtn = document.getElementById('empDocDownloadBtn');
  if (dlBtn) {
    dlBtn.setAttribute('href', downloadUrl);
    dlBtn.setAttribute('download', (title ? title.replace(/[^a-zA-Z0-9_-]/g, '_') : 'document') + '.' + ext);
  }

  const container = document.getElementById('empDocPreviewContainer');
  if (container) {
    container.innerHTML = '<div class="py-5 text-center"><div class="spinner-border text-primary" role="status"></div><div class="mt-2 text-muted small">Loading document preview...</div></div>';

    if (imgExts.includes(ext)) {
      // 1. High-Res Image Preview with Zoom Controls
      container.innerHTML = `
        <div class="d-flex justify-content-end gap-2 mb-2 p-2 bg-white rounded border">
          <button class="btn btn-sm btn-outline-secondary" onclick="zoomEmpImg(0.2)"><i class="bi bi-zoom-in"></i></button>
          <button class="btn btn-sm btn-outline-secondary" onclick="zoomEmpImg(-0.2)"><i class="bi bi-zoom-out"></i></button>
          <button class="btn btn-sm btn-outline-secondary" onclick="resetEmpImg()"><i class="bi bi-arrows-fullscreen"></i> Reset</button>
        </div>
        <div style="overflow: auto; max-height: 70vh; background: #0f172a;" class="rounded p-3 text-center">
          <img id="empDocPreviewImg" src="${url}" class="img-fluid rounded shadow-sm" style="max-height: 65vh; object-fit: contain; transition: transform 0.2s ease;" alt="${title}">
        </div>`;
    } else if (ext === 'pdf') {
      // 2. PDF Viewer
      container.innerHTML = `
        <div class="h-100">
          <iframe src="${url}#toolbar=1" style="width: 100%; height: 75vh; border: none; border-radius: 8px;"></iframe>
        </div>`;
    } else if (textExts.includes(ext)) {
      // 3. Text / Code Viewer
      fetch(url)
        .then(res => res.text())
        .then(text => {
          container.innerHTML = `
            <div class="text-start bg-dark text-light p-3 rounded" style="max-height: 72vh; overflow: auto; font-family: monospace; font-size: 13px; line-height: 1.6;">
              <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom border-secondary">
                <span class="text-secondary small font-monospace">${title} (${ext.toUpperCase()})</span>
                <button class="btn btn-sm btn-outline-light py-0 px-2" onclick="navigator.clipboard.writeText(document.getElementById('empTextPreviewBox').innerText); alert('Text copied to clipboard!');"><i class="bi bi-clipboard me-1"></i> Copy</button>
              </div>
              <pre id="empTextPreviewBox" style="margin: 0; white-space: pre-wrap; word-break: break-all;">${text.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</pre>
            </div>`;
        })
        .catch(() => {
          renderEmpOfficeCard(container, title, ext, url, downloadUrl);
        });
    } else if (ext === 'csv') {
      // 4. CSV Interactive Table
      fetch(url)
        .then(res => res.text())
        .then(csvText => {
          const lines = csvText.trim().split('\n').filter(l => l.trim().length > 0);
          if (lines.length === 0) {
            container.innerHTML = '<div class="alert alert-info my-4">CSV file is empty.</div>';
            return;
          }
          let html = '<div class="table-responsive bg-white rounded border" style="max-height: 72vh;"><table class="table table-sm table-striped table-hover mb-0 text-start small">';
          lines.slice(0, 50).forEach((line, idx) => {
            const cols = line.split(',');
            if (idx === 0) {
              html += '<thead class="table-dark sticky-top"><tr><th style="width:40px;">#</th>' + cols.map(c => `<th>${c.replace(/["\r]/g, '')}</th>`).join('') + '</tr></thead><tbody>';
            } else {
              html += `<tr><td class="text-muted">${idx}</td>` + cols.map(c => `<td>${c.replace(/["\r]/g, '')}</td>`).join('') + '</tr>';
            }
          });
          html += '</tbody></table></div>';
          if (lines.length > 50) {
            html += `<div class="p-2 text-muted small bg-light border-top">Showing first 50 of ${lines.length} rows. Download full CSV to view all rows.</div>`;
          }
          container.innerHTML = html;
        })
        .catch(() => {
          renderEmpOfficeCard(container, title, ext, url, downloadUrl);
        });
    } else if (officeWord.includes(ext) || officeExcel.includes(ext) || officePpt.includes(ext)) {
      // 5. Office Documents (Word / Excel / PPT)
      renderEmpOfficeCard(container, title, ext, url, downloadUrl);
    } else {
      // 6. Generic Formats
      renderEmpOfficeCard(container, title, ext, url, downloadUrl);
    }
  }

  const modalEl = document.getElementById('empDocPreviewModal');
  if (modalEl) {
    if (window.bootstrap && bootstrap.Modal) {
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    } else if (window.jQuery && $(modalEl).modal) {
      $(modalEl).modal('show');
    }
  }
}

let empImgZoom = 1;
function zoomEmpImg(delta) {
  const img = document.getElementById('empDocPreviewImg');
  if (img) {
    empImgZoom = Math.max(0.4, Math.min(3.5, empImgZoom + delta));
    img.style.transform = `scale(${empImgZoom})`;
  }
}
function resetEmpImg() {
  const img = document.getElementById('empDocPreviewImg');
  if (img) {
    empImgZoom = 1;
    img.style.transform = 'scale(1)';
  }
}

function renderEmpOfficeCard(container, title, ext, url, downloadUrl) {
  const isOnline = window.location.protocol.startsWith('http') && !['localhost', '127.0.0.1'].includes(window.location.hostname);
  const fullUrl = url.startsWith('http') ? url : (window.location.origin + url);
  const gDocsUrl = `https://docs.google.com/viewer?url=${encodeURIComponent(fullUrl)}&embedded=true`;
  const mOfficeUrl = `https://view.officeapps.live.com/op/embed.aspx?src=${encodeURIComponent(fullUrl)}`;

  let icon = 'bi-file-earmark-word text-primary';
  let badgeText = 'Word Document';
  if (['xls', 'xlsx'].includes(ext)) {
    icon = 'bi-file-earmark-excel text-success';
    badgeText = 'Excel Spreadsheet';
  } else if (['ppt', 'pptx'].includes(ext)) {
    icon = 'bi-file-earmark-ppt text-warning';
    badgeText = 'PowerPoint Presentation';
  }

  if (isOnline) {
    container.innerHTML = `
      <div class="h-100">
        <iframe src="${gDocsUrl}" style="width: 100%; height: 75vh; border: none; border-radius: 8px;" onerror="this.src='${mOfficeUrl}'"></iframe>
      </div>`;
  } else {
    container.innerHTML = `
      <div class="p-5 text-center bg-white rounded-3 border my-3">
        <i class="bi ${icon} display-3 d-block mb-3"></i>
        <h4 class="fw-bold text-dark mb-1">${title}</h4>
        <div class="mb-3">
          <span class="badge bg-primary bg-opacity-10 text-primary border px-3 py-2 fs-6">${badgeText} (.${ext.toUpperCase()})</span>
        </div>
        <p class="text-muted mx-auto" style="max-width: 500px;">
          This official <strong>.${ext.toUpperCase()}</strong> record is saved in your employee file. You can view it directly in Office / Native viewer or download it to your system.
        </p>
        <div class="d-flex justify-content-center gap-3 mt-4">
          <a href="${downloadUrl}" class="btn btn-primary px-4 py-2 fw-semibold shadow-sm">
            <i class="bi bi-download me-2"></i> Download File (.${ext.toUpperCase()})
          </a>
          <a href="${url}" target="_blank" class="btn btn-outline-secondary px-4 py-2 fw-semibold">
            <i class="bi bi-box-arrow-up-right me-2"></i> Open in New Window
          </a>
        </div>
      </div>`;
  }
}
</script>

<!-- Modal: Employee Document Preview -->
<div class="modal fade" id="empDocPreviewModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <div class="modal-header border-bottom-0 pb-0">
        <h5 class="modal-title fw-bold" id="empDocModalTitle"><i class="bi bi-file-earmark-text text-primary me-2"></i>Document Preview</h5>
        <div class="d-flex gap-2 align-items-center">
          <a href="#" id="empDocDownloadBtn" download target="_blank" class="btn btn-sm btn-primary"><i class="bi bi-download me-1"></i> Download File</a>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body p-0 text-center bg-light" style="min-height: 500px; max-height: 80vh; overflow-y: auto;">
        <div id="empDocPreviewContainer" class="p-3"></div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
