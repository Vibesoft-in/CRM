<?php
// modules/team/departments.php — Departments & Designations Management
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$csrf = csrf_token();
$msg  = '';
$err  = '';

// Handle Add Department POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_department') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = "Invalid security token.";
    } else {
        $name = trim($_POST['name'] ?? '');
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $desc = trim($_POST['description'] ?? '');

        if (empty($name)) {
            $err = "Department name is required.";
        } else {
            $ins = $pdo->prepare("INSERT INTO departments (name, slug, description) VALUES (?, ?, ?)");
            try {
                $ins->execute([$name, $slug, $desc]);
                set_flash("Department '$name' added successfully!", "success");
                header("Location: departments.php");
                exit;
            } catch (Exception $e) {
                $err = "Department already exists or invalid data.";
            }
        }
    }
}

// Handle Add Designation POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_designation') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = "Invalid security token.";
    } else {
        $name   = trim($_POST['name'] ?? '');
        $deptId = intval($_POST['department_id'] ?? 1);
        $desc   = trim($_POST['description'] ?? '');

        if (empty($name)) {
            $err = "Designation name is required.";
        } else {
            $ins = $pdo->prepare("INSERT INTO designations (name, department_id, description) VALUES (?, ?, ?)");
            try {
                $ins->execute([$name, $deptId, $desc]);
                set_flash("Designation '$name' created successfully!", "success");
                header("Location: departments.php");
                exit;
            } catch (Exception $e) {
                $err = "Error creating designation.";
            }
        }
    }
}

// Fetch all departments with count of employees
$depts = $pdo->query("
    SELECT d.*, COUNT(e.id) as emp_count 
    FROM departments d 
    LEFT JOIN employees e ON (e.department_id = d.id OR e.department = d.name) 
    GROUP BY d.id 
    ORDER BY d.sort_order ASC, d.name ASC
")->fetchAll();

// Fetch all designations
$desigs = $pdo->query("
    SELECT des.*, d.name as dept_name 
    FROM designations des 
    LEFT JOIN departments d ON d.id = des.department_id 
    ORDER BY d.name ASC, des.name ASC
")->fetchAll();
?>

<div class="container-fluid py-4">

  <!-- Top bar -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h3 class="fw-bold mb-0"><i class="bi bi-diagram-3 text-primary me-2"></i>Departments & Roles</h3>
      <p class="text-muted mb-0 small">Organize workshop departments, design units, and role designations</p>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#addDeptModal">
        <i class="bi bi-plus-circle me-1"></i> Add Department
      </button>
      <button class="btn btn-outline-primary btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#addDesigModal">
        <i class="bi bi-person-plus me-1"></i> Add Designation
      </button>
    </div>
  </div>

  <?php $flash = get_flash(); if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius:12px;">
    <i class="bi bi-check-circle-fill me-2"></i><?= e($flash['message']) ?>
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
    <!-- Departments Column -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100" style="border-radius:16px;">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
          <h5 class="fw-bold text-dark mb-0"><i class="bi bi-building me-2 text-primary"></i>Departments</h5>
          <span class="badge bg-light text-muted border"><?= count($depts) ?> Departments</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
              <thead class="table-light">
                <tr>
                  <th class="ps-4">Department</th>
                  <th>Description</th>
                  <th>Employees</th>
                  <th class="pe-4 text-end">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($depts as $d): ?>
                <tr>
                  <td class="ps-4 fw-bold text-dark"><?= e($d['name']) ?></td>
                  <td class="text-muted small" style="max-width:200px;"><?= e($d['description'] ?? '—') ?></td>
                  <td>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                      <?= $d['emp_count'] ?> Members
                    </span>
                  </td>
                  <td class="pe-4 text-end">
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                      <?= ucfirst($d['status']) ?>
                    </span>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Designations Column -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100" style="border-radius:16px;">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
          <h5 class="fw-bold text-dark mb-0"><i class="bi bi-person-badge me-2 text-success"></i>Designations & Titles</h5>
          <span class="badge bg-light text-muted border"><?= count($desigs) ?> Designations</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
              <thead class="table-light">
                <tr>
                  <th class="ps-4">Designation</th>
                  <th>Department</th>
                  <th class="pe-4 text-end">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($desigs as $des): ?>
                <tr>
                  <td class="ps-4 fw-bold text-dark"><?= e($des['name']) ?></td>
                  <td class="text-primary small fw-semibold"><?= e($des['dept_name'] ?? 'General') ?></td>
                  <td class="pe-4 text-end">
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                      <?= ucfirst($des['status']) ?>
                    </span>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- ADD DEPARTMENT MODAL -->
<div class="modal fade" id="addDeptModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-building text-primary me-2"></i>Create Department</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add_department">

          <div class="mb-3">
            <label class="form-label small fw-semibold">Department Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" placeholder="e.g. LED Signage Assembly, CNC & Wooden Art" required>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold">Description</label>
            <textarea name="description" class="form-control" rows="3" placeholder="Operations, scope, workshop location..."></textarea>
          </div>

          <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">Create Department</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ADD DESIGNATION MODAL -->
<div class="modal fade" id="addDesigModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-person-plus text-success me-2"></i>Create Designation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add_designation">

          <div class="mb-3">
            <label class="form-label small fw-semibold">Department <span class="text-danger">*</span></label>
            <select name="department_id" class="form-select" required>
              <?php foreach ($depts as $d): ?>
                <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold">Designation Title <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" placeholder="e.g. 3D LED Board Specialist, CNC Router Master" required>
          </div>

          <button type="submit" class="btn btn-success w-100 py-2 fw-semibold">Create Designation</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
