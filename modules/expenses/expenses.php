<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = "Invalid security token";
    } else {
        $date     = $_POST['expense_date'] ?: date('Y-m-d');
        $cat      = trim($_POST['category'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $vendor   = trim($_POST['vendor'] ?? '');
        $amount   = (float)($_POST['amount'] ?? 0);
        $tax_amt  = (float)($_POST['tax_amount'] ?? 0);
        $mode     = trim($_POST['payment_mode'] ?? 'Cash');

        if (!$cat || $amount <= 0) {
            $err = "Category and valid positive Amount are required";
        } else {
            $stmt = $pdo->prepare("INSERT INTO expenses (expense_date, category, description, vendor, amount, tax_amount, payment_mode, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'Paid', ?, NOW())");
            $stmt->execute([$date, $cat, $desc, $vendor, $amount, $tax_amt, $mode, $_SESSION['user_id']]);

            set_flash("Expense recorded successfully!", "success");
            header("Location: expenses.php");
            exit;
        }
    }
}

$flash = get_flash();

// Filtering
$where = "1=1"; 
$params = [];

if (!empty($_GET['month']) && !empty($_GET['year'])) {
    $where .= " AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?";
    $params[] = (int)$_GET['month'];
    $params[] = (int)$_GET['year'];
}
if (!empty($_GET['from']) && !empty($_GET['to'])) {
    $where .= " AND expense_date BETWEEN ? AND ?";
    $params[] = $_GET['from'];
    $params[] = $_GET['to'];
}

$stmt = $pdo->prepare("SELECT * FROM expenses WHERE $where ORDER BY expense_date DESC, id DESC");
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// Financial Summaries
$today = (float)($pdo->query("SELECT SUM(amount) FROM expenses WHERE DATE(expense_date) = CURDATE()")->fetchColumn() ?: 0);
$month = (float)($pdo->query("SELECT SUM(amount) FROM expenses WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())")->fetchColumn() ?: 0);
$year  = (float)($pdo->query("SELECT SUM(amount) FROM expenses WHERE YEAR(expense_date) = YEAR(CURDATE())")->fetchColumn() ?: 0);
$total = (float)($pdo->query("SELECT SUM(amount) FROM expenses")->fetchColumn() ?: 0);
?>

<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-wallet2 text-primary me-2"></i>Company Expense Management</h4>
      <p class="text-muted small mb-0">Track operating overheads, vendor expenses, rent, and company utility spending.</p>
    </div>
  </div>

  <!-- Navigation Tabs: Company vs Personal Expenses -->
  <ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item">
      <a class="nav-link active fw-bold bg-primary shadow-sm" href="expenses.php">
        <i class="bi bi-building me-1"></i> Company / Business Expenses
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link bg-white text-dark border fw-medium" href="personal_expenses.php">
        <i class="bi bi-person-fill me-1 text-warning"></i> Personal Expenses (Private)
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

  <!-- Summary Metric Cards -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center" style="border-radius: 12px; border-left: 4px solid #0d6efd !important;">
        <span class="text-muted small uppercase fw-bold" style="font-size: 0.75rem;">TODAY</span>
        <h4 class="fw-bold text-dark mb-0 mt-1">₹<?= money($today) ?></h4>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center" style="border-radius: 12px; border-left: 4px solid #198754 !important;">
        <span class="text-muted small uppercase fw-bold" style="font-size: 0.75rem;">THIS MONTH</span>
        <h4 class="fw-bold text-success mb-0 mt-1">₹<?= money($month) ?></h4>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center" style="border-radius: 12px; border-left: 4px solid #ffc107 !important;">
        <span class="text-muted small uppercase fw-bold" style="font-size: 0.75rem;">THIS YEAR</span>
        <h4 class="fw-bold text-warning mb-0 mt-1">₹<?= money($year) ?></h4>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm p-3 text-center" style="border-radius: 12px; border-left: 4px solid #dc3545 !important;">
        <span class="text-muted small uppercase fw-bold" style="font-size: 0.75rem;">ALL TIME</span>
        <h4 class="fw-bold text-danger mb-0 mt-1">₹<?= money($total) ?></h4>
      </div>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <!-- Quick Add Form -->
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm h-100" style="border-radius: 14px;">
        <div class="card-header bg-white py-3 border-bottom-0">
          <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-plus-circle me-2 text-primary"></i>Record New Expense</h6>
        </div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

            <div class="mb-3">
              <label class="form-label fw-medium">Expense Date</label>
              <input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Category <span class="text-danger">*</span></label>
              <select name="category" class="form-select" required>
                <option value="">-- Select Category --</option>
                <option value="Office Rent">Office Rent</option>
                <option value="Electricity / Utility">Electricity / Utility</option>
                <option value="Internet / Telecom">Internet / Telecom</option>
                <option value="Software / Hosting">Software / Hosting</option>
                <option value="Marketing / Ads">Marketing / Ads</option>
                <option value="Travel / Conveyance">Travel / Conveyance</option>
                <option value="Food / Refreshments">Food / Refreshments</option>
                <option value="Office Supplies">Office Supplies</option>
                <option value="Salary">Salary</option>
                <option value="Other">Other</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Vendor / Payee</label>
              <input type="text" name="vendor" class="form-control" placeholder="e.g. AWS, Landlord, BSNL">
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Amount (₹) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Payment Mode</label>
              <select name="payment_mode" class="form-select">
                <option value="Bank Transfer">Bank Transfer</option>
                <option value="UPI">UPI</option>
                <option value="Cash">Cash</option>
                <option value="Credit Card">Credit Card</option>
                <option value="Cheque">Cheque</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Description / Remarks</label>
              <textarea name="description" class="form-control" rows="2" placeholder="Brief note..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary w-100 fw-semibold shadow-sm">
              <i class="bi bi-check-circle me-1"></i> Record Expense
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- Expense List & Filters -->
    <div class="col-lg-8">
      <div class="card border-0 shadow-sm h-100" style="border-radius: 14px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-bottom-0 d-flex flex-wrap align-items-center justify-content-between gap-2">
          <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-list-ul me-2 text-primary"></i>Expense Log</h6>
          
          <form method="get" class="d-flex gap-2 align-items-center">
            <input type="date" name="from" class="form-control form-control-sm" value="<?= e($_GET['from']??'') ?>">
            <span class="small text-muted">to</span>
            <input type="date" name="to" class="form-control form-control-sm" value="<?= e($_GET['to']??'') ?>">
            <button class="btn btn-sm btn-primary"><i class="bi bi-filter"></i></button>
            <a href="expenses.php" class="btn btn-sm btn-light border"><i class="bi bi-x-circle"></i></a>
          </form>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="bg-light text-muted small uppercase">
                <tr>
                  <th class="ps-3">Date</th>
                  <th>Category</th>
                  <th>Vendor / Details</th>
                  <th class="text-end">Amount</th>
                  <th>Payment</th>
                  <th class="text-end pe-3">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($expenses): ?>
                  <?php foreach ($expenses as $ex): ?>
                    <tr>
                      <td class="ps-3 small fw-medium"><?= e($ex['expense_date']) ?></td>
                      <td>
                        <span class="badge bg-secondary">
                          <?= e($ex['category']) ?>
                        </span>
                      </td>
                      <td>
                        <span class="fw-semibold text-dark d-block"><?= e($ex['vendor'] ?: ($ex['description'] ?: 'N/A')) ?></span>
                        <?php if ($ex['vendor'] && $ex['description']): ?>
                          <small class="text-muted"><?= e($ex['description']) ?></small>
                        <?php endif; ?>
                      </td>
                      <td class="text-end fw-bold text-danger">₹<?= money($ex['amount']) ?></td>
                      <td><span class="badge bg-info-subtle text-info border border-info-subtle small"><?= e($ex['payment_mode']) ?></span></td>
                      <td class="text-end pe-3">
                        <div class="dropdown">
                          <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-three-dots-vertical"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li>
                              <a href="edit.php?id=<?= $ex['id'] ?>" class="dropdown-item">
                                <i class="bi bi-pencil text-primary me-2"></i> Edit Expense
                              </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                              <form method="post" action="delete.php" onsubmit="return confirm('Delete this expense entry?');" class="m-0">
                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                                <button type="submit" class="dropdown-item text-danger">
                                  <i class="bi bi-trash me-2"></i> Delete Expense
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
                    <td colspan="6" class="text-center py-4 text-muted">No expenses recorded for this period.</td>
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

<?php include '../../includes/footer.php'; ?>
