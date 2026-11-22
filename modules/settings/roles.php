<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();
if (!is_admin()) {
    set_flash("Access Denied: Only administrators can manage roles.", "danger");
    header("Location: " . BASE_URL . "index.php");
    exit;
}

$roles = $pdo->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();
?>

<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-person-gear text-primary me-2"></i>User Roles & Permissions</h4>
      <p class="text-muted small mb-0">Role-based access control (RBAC) definitions and module access security.</p>
    </div>
  </div>

  <div class="row g-4">
    <?php foreach ($roles as $r): ?>
      <div class="col-md-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100" style="border-radius: 14px;">
          <div class="card-body p-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h5 class="fw-bold text-dark mb-0"><i class="bi bi-shield-check text-primary me-2"></i><?= e($r['role_name']) ?></h5>
              <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Role</span>
            </div>
            <p class="text-muted small mb-3"><?= e($r['description'] ?: 'System Role') ?></p>
            <div class="p-3 bg-light rounded-3">
              <small class="text-muted d-block uppercase mb-1" style="font-size: 0.7rem;">SCOPED PERMISSIONS</small>
              <div class="d-flex flex-wrap gap-1">
                <span class="badge bg-light text-dark border">view_clients</span>
                <span class="badge bg-light text-dark border">view_invoices</span>
                <span class="badge bg-light text-dark border">view_expenses</span>
                <?php if (in_array($r['role_name'], ['Super Admin', 'Admin', 'Finance Manager'])): ?>
                  <span class="badge bg-success">reveal_credentials</span>
                  <span class="badge bg-primary">manage_payroll</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>
