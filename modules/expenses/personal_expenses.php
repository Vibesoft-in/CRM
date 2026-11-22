<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$err = '';
// Handle Add Personal Expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_expense') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = "Invalid security token.";
    } else {
        $date   = $_POST['expense_date'] ?: date('Y-m-d');
        $cat    = trim($_POST['category'] ?? 'Miscellaneous');
        $title  = trim($_POST['title'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $mode   = trim($_POST['payment_mode'] ?? 'Cash');

        if (!$title || $amount <= 0) {
            $err = "Expense title and a valid positive amount are required.";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO personal_expenses 
                (expense_date, category, title, description, amount, payment_mode, status, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'Paid', ?, NOW())
            ");
            $stmt->execute([$date, $cat, $title, $desc, $amount, $mode, $_SESSION['user_id']]);

            set_flash("Personal expense recorded successfully!", "success");
            header("Location: personal_expenses.php");
            exit;
        }
    }
}

// Handle Edit Personal Expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_expense') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = "Invalid security token.";
    } else {
        $id     = (int)($_POST['expense_id'] ?? 0);
        $date   = $_POST['expense_date'] ?: date('Y-m-d');
        $cat    = trim($_POST['category'] ?? 'Miscellaneous');
        $title  = trim($_POST['title'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $mode   = trim($_POST['payment_mode'] ?? 'Cash');

        if ($id <= 0 || !$title || $amount <= 0) {
            $err = "Valid expense ID, title, and amount are required.";
        } else {
            $stmt = $pdo->prepare("
                UPDATE personal_expenses 
                SET expense_date = ?, category = ?, title = ?, description = ?, amount = ?, payment_mode = ? 
                WHERE id = ?
            ");
            $stmt->execute([$date, $cat, $title, $desc, $amount, $mode, $id]);

            set_flash("Personal expense updated successfully!", "success");
            header("Location: personal_expenses.php");
            exit;
        }
    }
}

// Handle Delete Personal Expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_expense') {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $id = (int)($_POST['expense_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM personal_expenses WHERE id = ?");
            $stmt->execute([$id]);
            set_flash("Personal expense deleted.", "success");
        }
    }
    header("Location: personal_expenses.php");
    exit;
}

$flash = get_flash();

// Filter handling
$where = ["1=1"];
$params = [];

$search = trim($_GET['q'] ?? '');
$catFilter = trim($_GET['category'] ?? '');
$monthFilter = trim($_GET['month'] ?? '');
$yearFilter = trim($_GET['year'] ?? '');

if ($search !== '') {
    $where[] = "(title LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($catFilter !== '') {
    $where[] = "category = ?";
    $params[] = $catFilter;
}

if ($monthFilter !== '' && $yearFilter !== '') {
    $where[] = "MONTH(expense_date) = ? AND YEAR(expense_date) = ?";
    $params[] = (int)$monthFilter;
    $params[] = (int)$yearFilter;
} elseif ($yearFilter !== '') {
    $where[] = "YEAR(expense_date) = ?";
    $params[] = (int)$yearFilter;
}

$whereSql = implode(" AND ", $where);
$stmt = $pdo->prepare("SELECT * FROM personal_expenses WHERE $whereSql ORDER BY expense_date DESC, id DESC");
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// Personal Metrics (strictly from personal_expenses table ONLY)
$today = (float)($pdo->query("SELECT SUM(amount) FROM personal_expenses WHERE DATE(expense_date) = CURDATE()")->fetchColumn() ?: 0);
$thisMonth = (float)($pdo->query("SELECT SUM(amount) FROM personal_expenses WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())")->fetchColumn() ?: 0);
$thisYear = (float)($pdo->query("SELECT SUM(amount) FROM personal_expenses WHERE YEAR(expense_date) = YEAR(CURDATE())")->fetchColumn() ?: 0);
$allTime = (float)($pdo->query("SELECT SUM(amount) FROM personal_expenses")->fetchColumn() ?: 0);

$categories = [
    'Food & Dining', 'Groceries', 'Shopping', 'Home & Rent', 'Travel & Fuel',
    'Bills & Utilities', 'Medical & Healthcare', 'Investment & Savings',
    'Personal Care', 'Entertainment', 'Family & Friends', 'Miscellaneous'
];
?>

<div class="container-fluid">
  <!-- Page Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1">
        <i class="bi bi-wallet text-warning me-2"></i>Personal Expenses
      </h4>
      <p class="text-muted small mb-0">
        Private personal expenses log. Completely separate from CRM business accounting and tax reports.
      </p>
    </div>
  </div>

  <!-- Navigation Tabs: Company Expenses vs Personal Expenses -->
  <ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item">
      <a class="nav-link bg-white text-dark border fw-medium" href="expenses.php">
        <i class="bi bi-building me-1 text-primary"></i> Company / Business Expenses
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link active fw-bold bg-warning text-dark border-warning shadow-sm" href="personal_expenses.php">
        <i class="bi bi-person-fill me-1"></i> Personal Expenses (Private)
      </a>
    </li>
  </ul>

  <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="bi bi-exclamation-triangle me-2"></i><?= e($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Privacy & Isolation Notice Banner -->
  <div class="alert alert-warning d-flex align-items-center mb-4 p-3 border-warning-subtle shadow-sm rounded-3">
    <i class="bi bi-shield-lock-fill fs-3 text-warning-emphasis me-3 flex-shrink-0"></i>
    <div class="small">
      <strong>100% Isolated & Private:</strong> These personal expenses belong strictly to your personal ledger. They are <strong>never</strong> added to company operational expenses, profit & loss, company tax calculations, or CA financial reports.
    </div>
  </div>

  <!-- Metric Summaries -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center" style="border-radius: 12px; border-left: 4px solid #0d6efd !important;">
        <span class="text-muted small uppercase fw-bold" style="font-size: 0.72rem;">TODAY (PERSONAL)</span>
        <h4 class="fw-bold text-dark mb-0 mt-1">₹<?= money($today) ?></h4>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center" style="border-radius: 12px; border-left: 4px solid #198754 !important;">
        <span class="text-muted small uppercase fw-bold" style="font-size: 0.72rem;">THIS MONTH</span>
        <h4 class="fw-bold text-success mb-0 mt-1">₹<?= money($thisMonth) ?></h4>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center" style="border-radius: 12px; border-left: 4px solid #ffc107 !important;">
        <span class="text-muted small uppercase fw-bold" style="font-size: 0.72rem;">THIS YEAR</span>
        <h4 class="fw-bold text-warning mb-0 mt-1">₹<?= money($thisYear) ?></h4>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center" style="border-radius: 12px; border-left: 4px solid #dc3545 !important;">
        <span class="text-muted small uppercase fw-bold" style="font-size: 0.72rem;">ALL TIME TOTAL</span>
        <h4 class="fw-bold text-danger mb-0 mt-1">₹<?= money($allTime) ?></h4>
      </div>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <!-- Quick Add Form -->
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm h-100" style="border-radius: 14px;">
        <div class="card-header bg-white py-3 border-bottom-0">
          <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-plus-circle me-2 text-warning"></i>Record Personal Expense</h6>
        </div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="add_expense">

            <div class="mb-3">
              <label class="form-label fw-medium small">Date <span class="text-danger">*</span></label>
              <input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium small">Expense Title / Item <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" placeholder="e.g. Weekly Groceries, Fuel, Electricity" required>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium small">Category <span class="text-danger">*</span></label>
              <select name="category" class="form-select" required>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= e($c) ?>"><?= e($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium small">Amount (₹) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required min="1">
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium small">Payment Mode</label>
              <select name="payment_mode" class="form-select">
                <option value="UPI" selected>UPI / PhonePe / GPay</option>
                <option value="Cash">Cash</option>
                <option value="Debit Card">Personal Debit Card</option>
                <option value="Credit Card">Personal Credit Card</option>
                <option value="Netbanking">Netbanking</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium small">Notes / Remarks</label>
              <textarea name="description" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
            </div>

            <button type="submit" class="btn btn-warning w-100 fw-bold shadow-sm">
              <i class="bi bi-check2-circle me-1"></i> Save Personal Expense
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- Expenses Table & Filter -->
    <div class="col-lg-8">
      <div class="card border-0 shadow-sm" style="border-radius: 14px;">
        <!-- Filters Header -->
        <div class="card-header bg-white py-3 border-bottom-0">
          <form method="get" class="row g-2 align-items-center">
            <div class="col-md-5">
              <div class="input-group input-group-sm">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="q" class="form-control bg-light border-start-0 ps-0" placeholder="Search title or notes..." value="<?= e($search) ?>">
              </div>
            </div>
            <div class="col-md-4">
              <select name="category" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= e($c) ?>" <?= $catFilter === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3 d-flex gap-1">
              <button class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel me-1"></i> Filter</button>
              <a href="personal_expenses.php" class="btn btn-sm btn-light border" title="Reset"><i class="bi bi-x-circle"></i></a>
            </div>
          </form>
        </div>

        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="bg-light text-muted small uppercase">
                <tr>
                  <th class="ps-3">Date</th>
                  <th>Title / Purpose</th>
                  <th>Category</th>
                  <th>Mode</th>
                  <th class="text-end">Amount</th>
                  <th class="text-end pe-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($expenses)): ?>
                  <?php foreach ($expenses as $ex): ?>
                    <tr>
                      <td class="ps-3 small text-muted"><?= date('d M Y', strtotime($ex['expense_date'])) ?></td>
                      <td>
                        <span class="fw-semibold text-dark d-block"><?= e($ex['title']) ?></span>
                        <?php if (!empty($ex['description'])): ?>
                          <small class="text-muted"><?= e($ex['description']) ?></small>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="badge bg-light text-secondary border font-monospace"><?= e($ex['category']) ?></span>
                      </td>
                      <td class="small text-muted"><?= e($ex['payment_mode']) ?></td>
                      <td class="text-end fw-bold text-dark">₹<?= money($ex['amount']) ?></td>
                      <td class="text-end pe-3">
                        <button type="button" class="btn btn-sm btn-light border me-1" 
                                onclick="openEditModal(<?= htmlspecialchars(json_encode($ex), ENT_QUOTES, 'UTF-8') ?>)" title="Edit">
                          <i class="bi bi-pencil"></i>
                        </button>
                        <form method="post" onsubmit="return confirm('Delete this personal expense entry?');" class="d-inline m-0">
                          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                          <input type="hidden" name="action" value="delete_expense">
                          <input type="hidden" name="expense_id" value="<?= $ex['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-light border text-danger" title="Delete">
                            <i class="bi bi-trash"></i>
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="6" class="text-center py-5 text-muted">
                      <i class="bi bi-wallet2 fs-1 d-block mb-2 text-muted"></i>
                      No personal expenses recorded yet.
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Edit Expense Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-white border-bottom">
        <h5 class="modal-title fw-bold" id="editModalLabel"><i class="bi bi-pencil-square text-primary me-2"></i> Edit Personal Expense</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit_expense">
        <input type="hidden" name="expense_id" id="editExpenseId" value="">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label fw-medium small">Date <span class="text-danger">*</span></label>
            <input type="date" name="expense_date" id="editDate" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium small">Title <span class="text-danger">*</span></label>
            <input type="text" name="title" id="editTitle" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium small">Category <span class="text-danger">*</span></label>
            <select name="category" id="editCategory" class="form-select" required>
              <?php foreach ($categories as $c): ?>
                <option value="<?= e($c) ?>"><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium small">Amount (₹) <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text">₹</span>
              <input type="number" step="0.01" name="amount" id="editAmount" class="form-control" required min="1">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium small">Payment Mode</label>
            <select name="payment_mode" id="editPaymentMode" class="form-select">
              <option value="UPI">UPI / PhonePe / GPay</option>
              <option value="Cash">Cash</option>
              <option value="Debit Card">Personal Debit Card</option>
              <option value="Credit Card">Personal Credit Card</option>
              <option value="Netbanking">Netbanking</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-medium small">Notes</label>
            <textarea name="description" id="editDescription" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning fw-bold">Update Expense</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openEditModal(ex) {
  document.getElementById('editExpenseId').value = ex.id;
  document.getElementById('editDate').value = ex.expense_date;
  document.getElementById('editTitle').value = ex.title;
  document.getElementById('editCategory').value = ex.category;
  document.getElementById('editAmount').value = ex.amount;
  document.getElementById('editPaymentMode').value = ex.payment_mode || 'Cash';
  document.getElementById('editDescription').value = ex.description || '';

  var myModal = new bootstrap.Modal(document.getElementById('editModal'));
  myModal.show();
}
</script>

<?php include '../../includes/footer.php'; ?>
