<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

if (!isset($_GET['id'])) {
    set_flash("Expense ID missing.", "danger");
    header("Location: expenses.php");
    exit;
}
$id = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
$stmt->execute([$id]);
$expense = $stmt->fetch();

if (!$expense) {
    set_flash("Expense record not found.", "danger");
    header("Location: expenses.php");
    exit;
}

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
            $stmt = $pdo->prepare("UPDATE expenses SET expense_date=?, category=?, description=?, vendor=?, amount=?, tax_amount=?, payment_mode=? WHERE id=?");
            $stmt->execute([$date, $cat, $desc, $vendor, $amount, $tax_amt, $mode, $id]);

            set_flash("Expense updated successfully!", "success");
            header("Location: expenses.php");
            exit;
        }
    }
}
?>

<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Expense Record</h4>
      <p class="text-muted small mb-0">Modify expense category, vendor, amount, or payment details.</p>
    </div>
    <a href="expenses.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Expenses</a>
  </div>

  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm" style="border-radius: 14px;">
        <div class="card-body p-4">
          <?php if ($err): ?>
            <div class="alert alert-danger alert-dismissible fade show">
              <i class="bi bi-exclamation-triangle me-2"></i><?= e($err) ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
          <?php endif; ?>

          <form method="post" class="needs-validation">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

            <div class="mb-3">
              <label class="form-label fw-medium">Expense Date</label>
              <input type="date" name="expense_date" class="form-control" value="<?= e($expense['expense_date']) ?>" required>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Category <span class="text-danger">*</span></label>
              <select name="category" class="form-select" required>
                <?php 
                  $cats = ["Office Rent", "Electricity / Utility", "Internet / Telecom", "Software / Hosting", "Marketing / Ads", "Travel / Conveyance", "Food / Refreshments", "Office Supplies", "Salary", "Other"];
                  foreach ($cats as $c) {
                    $sel = ($expense['category'] === $c) ? "selected" : "";
                    echo "<option value='$c' $sel>$c</option>";
                  }
                ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Vendor / Payee</label>
              <input type="text" name="vendor" class="form-control" value="<?= e($expense['vendor']) ?>">
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Amount (₹) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="amount" class="form-control" value="<?= e($expense['amount']) ?>" required>
            </div>

            <div class="mb-3">
              <label class="form-label fw-medium">Payment Mode</label>
              <select name="payment_mode" class="form-select">
                <?php 
                  $modes = ["Bank Transfer", "UPI", "Cash", "Credit Card", "Cheque"];
                  foreach ($modes as $m) {
                    $sel = ($expense['payment_mode'] === $m) ? "selected" : "";
                    echo "<option value='$m' $sel>$m</option>";
                  }
                ?>
              </select>
            </div>

            <div class="mb-4">
              <label class="form-label fw-medium">Description / Remarks</label>
              <textarea name="description" class="form-control" rows="2"><?= e($expense['description']) ?></textarea>
            </div>

            <div class="d-grid gap-2">
              <button type="submit" class="btn btn-primary shadow-sm fw-semibold">
                <i class="bi bi-check-circle me-1"></i> Update Expense
              </button>
              <a href="expenses.php" class="btn btn-light border">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>
