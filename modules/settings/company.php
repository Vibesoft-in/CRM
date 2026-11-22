<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

// fetch current settings
$row = $pdo->query("SELECT * FROM settings ORDER BY id ASC LIMIT 1")->fetch();
if (!$row) {
    $pdo->exec("INSERT INTO settings (company_name) VALUES ('New Company')");
    $row = $pdo->query("SELECT * FROM settings ORDER BY id ASC LIMIT 1")->fetch();
}

$err = ''; 
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = "Invalid request";
    } else {
        $company     = trim($_POST['company_name'] ?? '');
        $gstin       = trim($_POST['gstin'] ?? '');
        $pan         = trim($_POST['pan'] ?? '');
        $addr        = trim($_POST['address'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $website     = trim($_POST['website'] ?? '');
        $bank_name   = trim($_POST['bank_name'] ?? '');
        $acc_holder  = trim($_POST['account_holder'] ?? '');
        $acc_number  = trim($_POST['account_number'] ?? '');
        $ifsc        = trim($_POST['ifsc_code'] ?? '');
        $upi         = trim($_POST['upi_id'] ?? '');
        $branch_name = trim($_POST['branch_name'] ?? '');

        $logoPath = $row['logo'] ?? '';
        if (!empty($_FILES['logo']['name'])) {
            $ext   = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $fname = 'logo_' . time() . '.' . $ext;
            $dest  = __DIR__ . '/../../assets/images/' . $fname;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                $logoPath = 'assets/images/' . $fname;
            }
        }

        $sigPath = $row['signature'] ?? '';
        if (!empty($_FILES['signature']['name'])) {
            $ext   = strtolower(pathinfo($_FILES['signature']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'])) {
                $fname = 'signature_' . time() . '.' . $ext;
                $dest  = __DIR__ . '/../../assets/images/' . $fname;
                if (move_uploaded_file($_FILES['signature']['tmp_name'], $dest)) {
                    $sigPath = 'assets/images/' . $fname;
                }
            }
        }
        if (!empty($_POST['remove_signature'])) {
            $sigPath = '';
        }

        $admin_2fa_email   = trim($_POST['admin_2fa_email'] ?? 'rishabhtyagi162@gmail.com');
        $admin_2fa_enabled = isset($_POST['admin_2fa_enabled']) ? 1 : 0;

        if (!empty($admin_2fa_email) && !filter_var($admin_2fa_email, FILTER_VALIDATE_EMAIL)) {
            $err = "Invalid Admin 2FA Security Email address.";
        } else {
            $stmt = $pdo->prepare("UPDATE settings
                SET company_name=?, gstin=?, pan=?, address=?, phone=?, email=?, website=?,
                    bank_name=?, account_holder=?, account_number=?, ifsc_code=?, upi_id=?, branch_name=?, logo=?, signature=?,
                    admin_2fa_email=?, admin_2fa_enabled=?
                WHERE id=?");
            $stmt->execute([
                $company, $gstin, $pan, $addr, $phone, $email, $website,
                $bank_name, $acc_holder, $acc_number, $ifsc, $upi, $branch_name,
                $logoPath, $sigPath, $admin_2fa_email, $admin_2fa_enabled, $row['id']
            ]);

            set_flash("Company & Security settings updated successfully!", "success");
            $row = $pdo->query("SELECT * FROM settings ORDER BY id ASC LIMIT 1")->fetch();
        }
    }
}


?>
<div class="container my-4">
  <div class="card p-4 shadow-sm">
    <h4>Company Settings</h4>
    <?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label fw-medium">Company Name <span class="text-danger">*</span></label>
          <input name="company_name" class="form-control" placeholder="e.g. VibeSoft Solutions Pvt. Ltd." value="<?= e($row['company_name']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-medium">GSTIN</label>
          <input name="gstin" class="form-control" placeholder="e.g. 07AAAAA0000A1Z5" value="<?= e($row['gstin']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-medium">PAN</label>
          <input name="pan" class="form-control" placeholder="e.g. AAAAA0000A" value="<?= e($row['pan']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-medium">Phone</label>
          <input name="phone" class="form-control" placeholder="e.g. +91 98765 43210" value="<?= e($row['phone']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-medium">Email</label>
          <input name="email" type="email" class="form-control" placeholder="e.g. contact@vibesoft.in" value="<?= e($row['email']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-medium">Website</label>
          <input name="website" type="text" class="form-control" placeholder="e.g. www.vibesoft.in" value="<?= e($row['website']) ?>">
        </div>
        <div class="col-md-12">
          <label class="form-label fw-medium">Address</label>
          <textarea name="address" class="form-control" rows="2" placeholder="Registered office or corporate address..."><?= e($row['address']) ?></textarea>
        </div>
        
        <div class="col-md-6">
          <label class="form-label fw-medium">Bank Name</label>
          <input name="bank_name" class="form-control" placeholder="e.g. HDFC Bank, ICICI Bank" value="<?= e($row['bank_name']) ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label fw-medium">Account Holder Name</label>
          <input name="account_holder" class="form-control" placeholder="e.g. VibeSoft Solutions Pvt. Ltd." value="<?= e($row['account_holder']) ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label fw-medium">Account Number</label>
          <input name="account_number" class="form-control" placeholder="e.g. 50200012345678" value="<?= e($row['account_number']) ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label fw-medium">IFSC Code</label>
          <input name="ifsc_code" class="form-control" placeholder="e.g. HDFC0001234" value="<?= e($row['ifsc_code']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-medium">Branch Name</label>
          <input type="text" name="branch_name" class="form-control" placeholder="e.g. Connaught Place, New Delhi" value="<?= e($row['branch_name'] ?? '') ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label fw-medium">UPI ID</label>
          <input name="upi_id" class="form-control" placeholder="e.g. vibesoft@hdfcbank" value="<?= e($row['upi_id']) ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label fw-medium">Company Logo</label>
          <input type="file" name="logo" class="form-control" accept="image/*">
          <?php if (!empty($row['logo'])): ?>
            <div class="mt-2 p-2 bg-light rounded d-inline-block border">
              <img src="<?= BASE_URL . e($row['logo']) ?>" height="50" style="object-fit: contain;" alt="Logo">
            </div>
          <?php endif; ?>
        </div>

        <div class="col-md-6">
          <label class="form-label fw-medium">Authorized Signatory Signature</label>
          <input type="file" name="signature" class="form-control" accept="image/png,image/jpeg,image/svg+xml">
          <small class="text-muted d-block mt-1">Upload official signature image (transparent PNG recommended). Used on Invoices, Salary Slips & Official Letters.</small>
          <?php if (!empty($row['signature'])): ?>
            <div class="mt-2 p-2 bg-light rounded d-flex align-items-center gap-3 border" style="width:fit-content;">
              <img src="<?= BASE_URL . e($row['signature']) ?>" height="45" style="object-fit: contain; max-width: 160px; background:#fff; padding:2px;" alt="Signature">
              <div class="form-check m-0">
                <input class="form-check-input" type="checkbox" name="remove_signature" value="1" id="removeSig">
                <label class="form-check-label small text-danger" for="removeSig">Remove Signature</label>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Admin 2FA Security Section -->
      <div class="mt-4 pt-3 border-top">
        <div class="d-flex align-items-center gap-2 mb-3">
          <span class="badge bg-primary p-2 rounded-3"><i class="bi bi-shield-lock-fill fs-6"></i></span>
          <div>
            <h5 class="mb-0 fw-bold">Admin Panel Security & Two-Factor Authentication (2FA)</h5>
            <small class="text-muted">Ensure nobody can accidentally or unauthorizedly access the Admin Panel without verifying via OTP</small>
          </div>
        </div>

        <div class="card bg-light border-0 p-3 rounded-3 mb-3">
          <div class="row g-3">
            <div class="col-md-7">
              <label class="form-label fw-semibold">Admin Login Verification Email (OTP Recipient)</label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-envelope-at-fill text-primary"></i></span>
                <input name="admin_2fa_email" type="email" class="form-control" value="<?= e($row['admin_2fa_email'] ?? 'rishabhtyagi162@gmail.com') ?>" placeholder="rishabhtyagi162@gmail.com" required>
              </div>
              <small class="text-muted d-block mt-1">
                Whenever an administrator logs into the Admin Panel, a secure 6-digit OTP will be sent directly to this email address.
              </small>
            </div>

            <div class="col-md-5 d-flex align-items-center">
              <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" role="switch" id="admin_2fa_enabled" name="admin_2fa_enabled" value="1" <?= (!isset($row['admin_2fa_enabled']) || !empty($row['admin_2fa_enabled'])) ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="admin_2fa_enabled">
                  Enforce Two-Step OTP for Admin Logins
                </label>
                <div class="text-muted small">When turned on, login requires password + OTP code verification.</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-4">
        <button class="btn btn-primary px-4 py-2 fw-semibold shadow-sm"><i class="bi bi-check2-circle me-1"></i> Save Settings</button>
      </div>
    </form>
  </div>
</div>
<?php include '../../includes/footer.php'; ?>
