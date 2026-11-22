<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];

if ($typeFilter) {
    $where[] = "client_type = ?";
    $params[] = $typeFilter;
}
if ($statusFilter) {
    $where[] = "status = ?";
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ? OR contact_person LIKE ? OR gstin LIKE ?)";
    $term = "%$search%";
    $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
}

$whereSql = implode(" AND ", $where);
$stmt = $pdo->prepare("
    SELECT c.*,
      COALESCE((SELECT SUM(total_amount) FROM invoices WHERE customer_id = c.id AND status != 'cancelled'), 0) AS total_billed,
      COALESCE((SELECT SUM(amount) FROM payments WHERE customer_id = c.id), 0) AS total_received
    FROM customers c
    WHERE $whereSql
    ORDER BY c.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>

<div class="container-fluid">
  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-people-fill text-primary me-2"></i>Client Directory</h4>
      <p class="text-muted small mb-0">Manage monthly clients, retainers, project billing, and financial ledgers.</p>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-primary shadow-sm fw-semibold" href="add.php">
        <i class="bi bi-person-plus me-1"></i> Add Client
      </a>
      <a class="btn btn-outline-secondary shadow-sm" href="monthly_clients.php">
        <i class="bi bi-arrow-repeat me-1"></i> Monthly Automation
      </a>
    </div>
  </div>

  <?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Filters & Tabs Card -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
    <div class="card-body p-3">
      <form method="get" class="row g-2 align-items-center">
        <div class="col-md-4">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="Search client name, email, phone..." value="<?= e($search) ?>">
          </div>
        </div>
        <div class="col-md-3">
          <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Client Types</option>
            <option value="monthly" <?= $typeFilter==='monthly'?'selected':'' ?>>Monthly Clients</option>
            <option value="one_time" <?= $typeFilter==='one_time'?'selected':'' ?>>One-Time Clients</option>
            <option value="project" <?= $typeFilter==='project'?'selected':'' ?>>Project-Based</option>
            <option value="retainer" <?= $typeFilter==='retainer'?'selected':'' ?>>Retainer</option>
          </select>
        </div>
        <div class="col-md-3">
          <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
            <option value="lead" <?= $statusFilter==='lead'?'selected':'' ?>>Lead</option>
            <option value="inactive" <?= $statusFilter==='inactive'?'selected':'' ?>>Inactive</option>
            <option value="completed" <?= $statusFilter==='completed'?'selected':'' ?>>Completed</option>
            <option value="archived" <?= $statusFilter==='archived'?'selected':'' ?>>Archived</option>
          </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
          <button class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel me-1"></i> Filter</button>
          <a href="list.php" class="btn btn-sm btn-light border"><i class="bi bi-x-circle"></i></a>
        </div>
      </form>
    </div>
  </div>

  <!-- Client Table -->
  <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light text-muted small uppercase">
          <tr>
            <th class="ps-3">Client</th>
            <th>Type / Status</th>
            <th>Contact</th>
            <th>Monthly Package</th>
            <th class="text-end">Total Billed</th>
            <th class="text-end">Received</th>
            <th class="text-end">Pending</th>
            <th class="text-center pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($rows): ?>
          <?php foreach($rows as $r): 
            $billed = (float)$r['total_billed'];
            $received = (float)$r['total_received'];
            $pending = max(0, $billed - $received);
          ?>
            <tr>
              <td class="ps-3">
                <div class="d-flex align-items-center">
                  <?= get_client_logo_html($r, 40) ?>

                  <div>
                    <a href="view.php?id=<?= $r['id'] ?>" class="fw-bold text-dark text-decoration-none d-block">
                      <?= e($r['name']) ?>
                    </a>
                    <small class="text-muted"><?= e($r['contact_person'] ?: 'No primary contact') ?></small>
                  </div>
                </div>
              </td>
              <td>
                <span class="badge bg-<?= 
                  $r['client_type']=='monthly'?'primary':
                  ($r['client_type']=='retainer'?'info':'secondary')
                ?> me-1">
                  <?= ucfirst(e($r['client_type'])) ?>
                </span>
                <span class="badge bg-<?= 
                  $r['status']=='active'?'success':
                  ($r['status']=='lead'?'warning':'danger')
                ?>">
                  <?= ucfirst(e($r['status'])) ?>
                </span>
              </td>
              <td>
                <small class="d-block"><i class="bi bi-telephone text-muted me-1"></i><?= e($r['phone'] ?: 'N/A') ?></small>
                <small class="d-block text-muted"><i class="bi bi-envelope me-1"></i><?= e($r['email'] ?: 'N/A') ?></small>
              </td>
              <td>
                <?php if ($r['client_type'] === 'monthly' && $r['monthly_amount'] > 0): ?>
                  <span class="fw-semibold text-dark">₹<?= money($r['monthly_amount']) ?></span>/mo
                  <small class="d-block text-muted"><?= e($r['monthly_service_name'] ?: 'Service') ?></small>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="text-end fw-semibold">₹<?= money($billed) ?></td>
              <td class="text-end text-success fw-semibold">₹<?= money($received) ?></td>
              <td class="text-end text-<?= $pending > 0 ? 'danger':'muted' ?> fw-bold">
                ₹<?= money($pending) ?>
              </td>
              <td class="text-center pe-3">
                <div class="dropdown">
                  <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown">
                    Actions
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><a class="dropdown-item" href="view.php?id=<?= $r['id'] ?>"><i class="bi bi-eye text-primary me-2"></i> View Profile</a></li>
                    <li><a class="dropdown-item" href="ledger.php?id=<?= $r['id'] ?>"><i class="bi bi-journal-text text-info me-2"></i> Financial Ledger</a></li>
                    <li><a class="dropdown-item" href="../invoices/create.php?customer_id=<?= $r['id'] ?>"><i class="bi bi-receipt text-success me-2"></i> Create Invoice</a></li>
                    <li><a class="dropdown-item" href="../credentials/index.php?customer_id=<?= $r['id'] ?>"><i class="bi bi-key text-warning me-2"></i> View Vault</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="edit.php?id=<?= $r['id'] ?>"><i class="bi bi-pencil me-2"></i> Edit Client</a></li>
                    <li>
                      <form method="post" action="delete.php" onsubmit="return confirm('Delete client <?= e($r['name']) ?> and all associated records?');" class="dropdown-item p-0">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <button type="submit" class="btn btn-link text-danger dropdown-item w-100 text-start py-1 px-3">
                          <i class="bi bi-trash me-2"></i> Delete Client
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
            <td colspan="8" class="text-center py-5 text-muted">
              <i class="bi bi-people fs-1 d-block mb-2 text-muted"></i>
              No clients found matching your filters.
            </td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php
    // Calculate Monthly vs One-Time totals for bottom summary
    $mStream = (float)$pdo->query("SELECT COALESCE(SUM(monthly_amount), 0) FROM customers WHERE client_type = 'monthly' AND status = 'active'")->fetchColumn();
    $mCount = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE client_type = 'monthly' AND status = 'active'")->fetchColumn();
    $mInv = $pdo->query("
        SELECT 
            COALESCE(SUM(i.total_amount), 0) AS total_billed,
            COALESCE(SUM(i.amount_paid), 0) AS total_received
        FROM invoices i
        INNER JOIN customers c ON c.id = i.customer_id
        WHERE c.client_type = 'monthly' AND c.status = 'active' AND i.status != 'cancelled'
    ")->fetch();
    $monthlySummary = [
        'client_count'   => $mCount,
        'stream_total'   => $mStream,
        'total_billed'   => (float)($mInv['total_billed'] ?? 0),
        'total_received' => (float)($mInv['total_received'] ?? 0),
    ];

    $otCount = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE client_type IN ('one_time', 'project')")->fetchColumn();
    $otInv = $pdo->query("
        SELECT 
            COALESCE(SUM(i.total_amount), 0) AS total_billed,
            COALESCE(SUM(i.amount_paid), 0) AS total_received
        FROM invoices i
        INNER JOIN customers c ON c.id = i.customer_id
        WHERE c.client_type IN ('one_time', 'project') AND i.status != 'cancelled'
    ")->fetch();
    $oneTimeSummary = [
        'client_count'   => $otCount,
        'total_billed'   => (float)($otInv['total_billed'] ?? 0),
        'total_received' => (float)($otInv['total_received'] ?? 0),
    ];
  ?>

  <!-- Bottom Financial Summary Card for Monthly & One-Time Clients -->
  <div class="card border-0 shadow-sm mt-4 mb-4" style="border-radius: 16px;">
    <div class="card-header bg-white py-3 border-bottom">
      <h6 class="fw-bold mb-0 text-dark">
        <i class="bi bi-calculator-fill text-primary me-2"></i>Client Revenue Overview Summary (Monthly vs One-Time)
      </h6>
    </div>
    <div class="card-body p-4 bg-light">
      <div class="row g-4">
        <!-- Monthly Clients Box -->
        <div class="col-md-6">
          <div class="p-3 bg-white rounded-3 border shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="badge bg-primary px-2 py-1"><i class="bi bi-arrow-repeat me-1"></i>Monthly Retainer Clients</span>
              <span class="fw-bold text-dark"><?= (int)$monthlySummary['client_count'] ?> Active Clients</span>
            </div>
            <div class="d-flex justify-content-between align-items-baseline mt-2">
              <small class="text-muted">Monthly Retainer Stream:</small>
              <span class="fw-bold text-primary fs-5">₹<?= money($monthlySummary['stream_total']) ?><small class="fs-6 font-normal">/mo</small></span>
            </div>
            <div class="d-flex justify-content-between align-items-baseline mt-1">
              <small class="text-muted">Total Revenue Earned (Collected):</small>
              <span class="fw-bold text-success">₹<?= money($monthlySummary['total_received']) ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-baseline mt-1">
              <small class="text-muted">Total Invoiced (Billed):</small>
              <span class="fw-semibold text-dark">₹<?= money($monthlySummary['total_billed']) ?></span>
            </div>
          </div>
        </div>

        <!-- One-Time Clients Box -->
        <div class="col-md-6">
          <div class="p-3 bg-white rounded-3 border shadow-sm h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="badge bg-info text-dark px-2 py-1"><i class="bi bi-folder-check me-1"></i>One-Time & Project Clients</span>
              <span class="fw-bold text-dark"><?= (int)$oneTimeSummary['client_count'] ?> Project Clients</span>
            </div>
            <div class="d-flex justify-content-between align-items-baseline mt-2">
              <small class="text-muted">Total Revenue Earned (Collected):</small>
              <span class="fw-bold text-success fs-5">₹<?= money($oneTimeSummary['total_received']) ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-baseline mt-1">
              <small class="text-muted">Total Invoiced (Billed):</small>
              <span class="fw-bold text-dark">₹<?= money($oneTimeSummary['total_billed']) ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-baseline mt-1">
              <small class="text-muted">Pending Balance:</small>
              <span class="fw-semibold text-danger">₹<?= money(max(0, $oneTimeSummary['total_billed'] - $oneTimeSummary['total_received'])) ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>
