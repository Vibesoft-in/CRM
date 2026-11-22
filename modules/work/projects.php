<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$err = '';

// Handle Add Project POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_project') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $customer_id      = (int)$_POST['customer_id'];
        $project_name     = trim($_POST['project_name']);
        $project_type     = $_POST['project_type'] ?? 'Fixed';
        $start_date       = $_POST['start_date'] ?: date('Y-m-d');
        $end_date         = $_POST['end_date'] ?: null;
        $agreed_amount    = (float)($_POST['agreed_amount'] ?? 0);
        $advance_required = (float)($_POST['advance_required'] ?? 0);
        $status           = $_POST['status'] ?? 'Active';
        $description      = trim($_POST['description'] ?? '');
        
        if ($customer_id <= 0 || empty($project_name)) {
            $err = 'Client and Project Name are required.';
        } else {
            $ins = $pdo->prepare("INSERT INTO projects (customer_id, project_name, project_type, start_date, end_date, agreed_amount, advance_required, status, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $ins->execute([$customer_id, $project_name, $project_type, $start_date, $end_date, $agreed_amount, $advance_required, $status, $description]);
            
            set_flash('Project created successfully!', 'success');
            header('Location: projects.php');
            exit;
        }
    }
}

// Handle Edit Project POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_project') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid security token';
    } else {
        $project_id       = (int)$_POST['project_id'];
        $customer_id      = (int)$_POST['customer_id'];
        $project_name     = trim($_POST['project_name']);
        $project_type     = $_POST['project_type'] ?? 'Fixed';
        $start_date       = $_POST['start_date'] ?: date('Y-m-d');
        $end_date         = $_POST['end_date'] ?: null;
        $agreed_amount    = (float)($_POST['agreed_amount'] ?? 0);
        $advance_required = (float)($_POST['advance_required'] ?? 0);
        $status           = $_POST['status'] ?? 'Active';
        $description      = trim($_POST['description'] ?? '');

        if ($project_id <= 0 || $customer_id <= 0 || empty($project_name)) {
            $err = 'Project ID, Client and Project Name are required.';
        } else {
            $upd = $pdo->prepare("UPDATE projects SET customer_id = ?, project_name = ?, project_type = ?, start_date = ?, end_date = ?, agreed_amount = ?, advance_required = ?, status = ?, description = ? WHERE id = ?");
            $upd->execute([$customer_id, $project_name, $project_type, $start_date, $end_date, $agreed_amount, $advance_required, $status, $description, $project_id]);

            set_flash('Project updated successfully!', 'success');
            header('Location: projects.php');
            exit;
        }
    }
}

// Handle Delete Project POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_project') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $project_id = (int)$_POST['project_id'];
        $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$project_id]);
        set_flash('Project deleted successfully.', 'info');
    }
    header('Location: projects.php');
    exit;
}

// Fetch projects with financial profitability metrics
$stmt = $pdo->query("
    SELECT p.*, c.name AS client_name,
      COALESCE((SELECT SUM(total_amount) FROM extra_work WHERE project_id = p.id), 0) AS extra_work_rev,
      COALESCE((SELECT SUM(amount) FROM expenses WHERE project_id = p.id), 0) AS project_expenses
    FROM projects p
    JOIN customers c ON c.id = p.customer_id
    ORDER BY p.start_date DESC, p.id DESC
");
$projects = $stmt->fetchAll();

$customers = $pdo->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name ASC")->fetchAll();
?>

<div class="container-fluid">
  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-kanban text-primary me-2"></i>Project & Work Management</h4>
      <p class="text-muted small mb-0">Track fixed/one-time projects, deliverables, team allocations, and net project profitability.</p>
    </div>
    <button class="btn btn-primary shadow-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#addProjectModal">
      <i class="bi bi-plus-circle me-1"></i> Create Project
    </button>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
      <i class="bi bi-exclamation-triangle me-2"></i><?= e($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
      <?= $flash['message'] ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Table -->
  <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light text-muted small uppercase">
          <tr>
            <th class="ps-3">Project / Client</th>
            <th>Type</th>
            <th>Status</th>
            <th>Agreed Value</th>
            <th>Extra Work</th>
            <th>Project Expenses</th>
            <th class="text-end">Net Profit</th>
            <th class="pe-3 text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($projects): ?>
            <?php foreach ($projects as $p): 
              $rev = (float)$p['agreed_amount'];
              $extra = (float)$p['extra_work_rev'];
              $exp = (float)$p['project_expenses'];
              $profit = ($rev + $extra) - $exp;
            ?>
              <tr>
                <td class="ps-3">
                  <span class="fw-bold text-dark d-block"><?= e($p['project_name']) ?></span>
                  <small class="text-muted"><i class="bi bi-building me-1"></i><?= e($p['client_name']) ?></small>
                </td>
                <td><span class="badge bg-light text-dark border"><?= e($p['project_type']) ?></span></td>
                <td>
                  <span class="badge bg-<?= 
                    $p['status']=='Active'?'primary':
                    ($p['status']=='Completed'?'success':'warning')
                  ?>">
                    <?= e($p['status']) ?>
                  </span>
                </td>
                <td class="fw-semibold text-dark">₹<?= money($rev) ?></td>
                <td class="text-info fw-semibold">₹<?= money($extra) ?></td>
                <td class="text-danger fw-semibold">₹<?= money($exp) ?></td>
                <td class="text-end fw-bold text-<?= $profit>=0?'success':'danger' ?>">
                  ₹<?= money($profit) ?>
                </td>
                <td class="pe-3 text-end">
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
                        <a href="../invoices/create.php?customer_id=<?= $p['customer_id'] ?>&project_id=<?= $p['id'] ?>" class="dropdown-item">
                          <i class="bi bi-receipt text-primary me-2"></i> Create Invoice
                        </a>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <form method="post" onsubmit="return confirm('Delete project <?= e($p['project_name']) ?>?');" class="m-0">
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
          <?php else: ?>
            <tr>
              <td colspan="8" class="text-center py-5 text-muted">No active projects found. Click "Create Project" to add one.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: Add Project -->
<div class="modal fade" id="addProjectModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_project">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-kanban text-primary me-2"></i>Create Project</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-medium">Select Client <span class="text-danger">*</span></label>
              <select name="customer_id" class="form-select" required>
                <option value="">-- Select Client --</option>
                <?php foreach ($customers as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Project Name <span class="text-danger">*</span></label>
              <input type="text" name="project_name" class="form-control" placeholder="e.g. E-Commerce Portal Redesign" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Project Type</label>
              <select name="project_type" class="form-select">
                <option value="Fixed" selected>Fixed Price</option>
                <option value="One-Time">One-Time</option>
                <option value="Monthly">Monthly</option>
                <option value="Retainer">Retainer</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Agreed Amount (₹)</label>
              <input type="number" step="0.01" name="agreed_amount" class="form-control" placeholder="80000.00">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Advance Required (₹)</label>
              <input type="number" step="0.01" name="advance_required" class="form-control" placeholder="30000.00">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Start Date</label>
              <input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">End Date</label>
              <input type="date" name="end_date" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Status</label>
              <select name="status" class="form-select">
                <option value="Planning">Planning</option>
                <option value="Active" selected>Active</option>
                <option value="On Hold">On Hold</option>
                <option value="Completed">Completed</option>
              </select>
            </div>
            <div class="col-md-12">
              <label class="form-label fw-medium">Project Description</label>
              <textarea name="description" class="form-control" rows="3"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-check-circle me-1"></i> Save Project</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Edit Project -->
<div class="modal fade" id="editProjectModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit_project">
        <input type="hidden" name="project_id" id="edit_project_id">
        <div class="modal-header border-bottom-0 pb-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Project</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-medium">Select Client <span class="text-danger">*</span></label>
              <select name="customer_id" id="edit_customer_id" class="form-select" required>
                <option value="">-- Select Client --</option>
                <?php foreach ($customers as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Project Name <span class="text-danger">*</span></label>
              <input type="text" name="project_name" id="edit_project_name" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Project Type</label>
              <select name="project_type" id="edit_project_type" class="form-select">
                <option value="Fixed">Fixed Price</option>
                <option value="One-Time">One-Time</option>
                <option value="Monthly">Monthly</option>
                <option value="Retainer">Retainer</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Agreed Amount (₹)</label>
              <input type="number" step="0.01" name="agreed_amount" id="edit_agreed_amount" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Advance Required (₹)</label>
              <input type="number" step="0.01" name="advance_required" id="edit_advance_required" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Start Date</label>
              <input type="date" name="start_date" id="edit_start_date" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">End Date</label>
              <input type="date" name="end_date" id="edit_end_date" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Status</label>
              <select name="status" id="edit_status" class="form-select">
                <option value="Planning">Planning</option>
                <option value="Active">Active</option>
                <option value="On Hold">On Hold</option>
                <option value="Completed">Completed</option>
              </select>
            </div>
            <div class="col-md-12">
              <label class="form-label fw-medium">Project Description</label>
              <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
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

<script>
document.querySelectorAll('.edit-project-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const p = JSON.parse(this.getAttribute('data-project'));
    document.getElementById('edit_project_id').value = p.id;
    document.getElementById('edit_customer_id').value = p.customer_id;
    document.getElementById('edit_project_name').value = p.project_name || '';
    document.getElementById('edit_project_type').value = p.project_type || 'Fixed';
    document.getElementById('edit_agreed_amount').value = p.agreed_amount || 0;
    document.getElementById('edit_advance_required').value = p.advance_required || 0;
    document.getElementById('edit_start_date').value = p.start_date || '';
    document.getElementById('edit_end_date').value = p.end_date || '';
    document.getElementById('edit_status').value = p.status || 'Active';
    document.getElementById('edit_description').value = p.description || '';
    
    const editModal = new bootstrap.Modal(document.getElementById('editProjectModal'));
    editModal.show();
  });
});
</script>

<?php include '../../includes/footer.php'; ?>
