<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/layout.php';

require_login();

if (!is_admin()) {
    set_flash("Access Denied: Admin permissions required to manage users.", "danger");
    header("Location: " . BASE_URL . "index.php");
    exit;
}

$stmt = $pdo->query("SELECT id, name, email, role, api_key, created_at FROM users ORDER BY id DESC");
$users = $stmt->fetchAll();
$flash = get_flash();
?>

<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-people-fill text-primary me-2"></i>User Management & Roles</h4>
      <p class="text-muted small mb-0">Manage system access accounts, roles, and authorization keys.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="../settings/roles.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-shield-check me-1"></i> Role Permissions</a>
      <a href="register_user.php" class="btn btn-primary btn-sm shadow-sm fw-semibold"><i class="bi bi-person-plus me-1"></i> Create New User</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm" style="border-radius: 14px;">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="bg-light text-muted small uppercase">
            <tr>
              <th class="ps-3">ID</th>
              <th>Name</th>
              <th>Email</th>
              <th>Role</th>
              <th>API Key</th>
              <th>Created At</th>
              <th class="text-end pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr>
                <td class="ps-3 fw-bold">#<?= e($u['id']) ?></td>
                <td>
                  <span class="fw-bold text-dark d-block"><?= e($u['name']) ?></span>
                </td>
                <td><code><?= e($u['email']) ?></code></td>
                <td>
                  <span class="badge bg-<?= strtolower($u['role'])==='admin'||strtolower($u['role'])==='super admin'?'primary':'secondary' ?>">
                    <?= ucfirst(e($u['role'])) ?>
                  </span>
                </td>
                <td><small class="text-muted font-monospace"><?= e($u['api_key'] ?: '—') ?></small></td>
                <td class="small text-muted"><?= e($u['created_at']) ?></td>
                <td class="text-end pe-3">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <li>
                        <a href="edit_user.php?id=<?= e($u['id']) ?>" class="dropdown-item">
                          <i class="bi bi-pencil text-secondary me-2"></i> Edit User
                        </a>
                      </li>
                      <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                          <form method="post" action="delete_user.php" onsubmit="return confirm('Are you sure you want to delete user <?= e($u['name']) ?>?');" class="m-0">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="id" value="<?= e($u['id']) ?>">
                            <button type="submit" class="dropdown-item text-danger">
                              <i class="bi bi-trash me-2"></i> Delete User
                            </button>
                          </form>
                        </li>
                      <?php endif; ?>
                    </ul>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
