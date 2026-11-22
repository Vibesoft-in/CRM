<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/layout.php';

require_login();

if (!is_admin()) {
    set_flash("Access Denied: Admin permissions required.", "danger");
    header("Location: users.php");
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $errors[] = "Invalid security token.";
    } else {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'staff';

        if (!$name || !$email || !$password) {
            $errors[] = "All required fields must be filled out.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email address format.";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "A user account with this email already exists.";
            }
        }

        if (empty($errors)) {
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $api_key = bin2hex(random_bytes(16));

            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, api_key, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$name, $email, $password_hash, strtolower($role), $api_key]);

            set_flash("User '$name' registered successfully!", "success");
            header("Location: users.php");
            exit;
        }
    }
}
?>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-person-plus-fill text-primary me-2"></i>Register New User</h4>
      <p class="text-muted small mb-0">Create a new user account with role-based access.</p>
    </div>
    <a href="users.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Users</a>
  </div>

  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm" style="border-radius: 14px;">
        <div class="card-body p-4">
          <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
              <ul class="mb-0 ps-3">
                <?php foreach ($errors as $err): ?>
                  <li><?= e($err) ?></li>
                <?php endforeach; ?>
              </ul>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
          <?php endif; ?>

          <form method="post" class="needs-validation">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

            <div class="mb-3">
              <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" placeholder="e.g. Rahul Sharma" value="<?= e($_POST['name'] ?? '') ?>" required>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" placeholder="rahul@company.com" value="<?= e($_POST['email'] ?? '') ?>" required>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Password <span class="text-danger">*</span></label>
              <input type="password" name="password" class="form-control" placeholder="Enter secure password" required>
            </div>

            <div class="mb-4">
              <label class="form-label fw-medium">User Role <span class="text-danger">*</span></label>
              <select name="role" class="form-select" required>
                <option value="staff" <?= (($_POST['role'] ?? '') === 'staff') ? 'selected' : '' ?>>Staff</option>
                <option value="admin" <?= (($_POST['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Admin</option>
                <option value="finance_manager" <?= (($_POST['role'] ?? '') === 'finance_manager') ? 'selected' : '' ?>>Finance Manager</option>
                <option value="hr_manager" <?= (($_POST['role'] ?? '') === 'hr_manager') ? 'selected' : '' ?>>HR Manager</option>
              </select>
            </div>

            <div class="d-grid gap-2">
              <button type="submit" class="btn btn-primary shadow-sm fw-semibold"><i class="bi bi-check-circle me-1"></i> Register Account</button>
              <a href="users.php" class="btn btn-light border">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
