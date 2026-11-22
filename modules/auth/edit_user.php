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

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash("Invalid User ID.", "danger");
    header("Location: users.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    set_flash("User not found.", "danger");
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

        if (!$name || !$email) {
            $errors[] = "Name and email are required fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $id]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "Email is already taken by another user.";
            }
        }

        if (empty($errors)) {
            if (!empty($password)) {
                $password_hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, password_hash = ?, role = ? WHERE id = ?");
                $stmt->execute([$name, $email, $password_hash, strtolower($role), $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
                $stmt->execute([$name, $email, strtolower($role), $id]);
            }

            set_flash("User '$name' updated successfully!", "success");
            header("Location: users.php");
            exit;
        }
    }
}
?>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-pencil-square text-primary me-2"></i>Edit User Account</h4>
      <p class="text-muted small mb-0">Modify user profile, credentials, and access roles.</p>
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
              <input type="text" name="name" class="form-control" value="<?= e($user['name']) ?>" required>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>" required>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Password <small class="text-muted">(Leave blank to keep current)</small></label>
              <input type="password" name="password" class="form-control" placeholder="••••••••">
            </div>

            <div class="mb-4">
              <label class="form-label fw-medium">User Role <span class="text-danger">*</span></label>
              <select name="role" class="form-select" required>
                <option value="staff" <?= (strtolower($user['role']) === 'staff') ? 'selected' : '' ?>>Staff</option>
                <option value="admin" <?= (strtolower($user['role']) === 'admin' || strtolower($user['role']) === 'super admin') ? 'selected' : '' ?>>Admin</option>
                <option value="finance_manager" <?= (strtolower($user['role']) === 'finance_manager') ? 'selected' : '' ?>>Finance Manager</option>
                <option value="hr_manager" <?= (strtolower($user['role']) === 'hr_manager') ? 'selected' : '' ?>>HR Manager</option>
              </select>
            </div>

            <div class="d-grid gap-2">
              <button type="submit" class="btn btn-primary shadow-sm fw-semibold"><i class="bi bi-check-circle me-1"></i> Update User</button>
              <a href="users.php" class="btn btn-light border">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
