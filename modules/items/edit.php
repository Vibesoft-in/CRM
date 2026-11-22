<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$id = intval($_GET['id'] ?? 0);
$err = '';

// Fetch item
$stmt = $pdo->prepare("SELECT * FROM items WHERE id=?");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
  die("Item not found.");
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'Invalid request';
  } else {
    $name  = trim($_POST['name']);
    $hsn   = trim($_POST['hsn']);
    $price = floatval($_POST['price']);
    $tax   = floatval($_POST['tax']);

    $stmt = $pdo->prepare("UPDATE items 
                           SET name=?, hsn_sac=?, default_price=?, default_tax_rate=? 
                           WHERE id=?");
    $stmt->execute([$name, $hsn, $price, $tax, $id]);

    header('Location: list.php'); // go back to item list
    exit;
  }
}
?>
<div class="container my-4">
  <div class="card p-3 shadow-sm">
    <h4>Edit Item</h4>
    <?php if ($err): ?>
      <div class="alert alert-danger"><?= e($err) ?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div class="row g-2">
        <div class="col-md-6">
          <label>Name</label>
          <input name="name" class="form-control" value="<?= e($item['name']) ?>" required>
        </div>
        <div class="col-md-3">
          <label>HSN/SAC</label>
          <input name="hsn" class="form-control" value="<?= e($item['hsn_sac']) ?>">
        </div>
        <div class="col-md-3">
          <label>Price</label>
          <input name="price" class="form-control" type="number" step="0.01" value="<?= e($item['default_price']) ?>">
        </div>
        <div class="col-md-3">
          <label>Tax %</label>
          <input name="tax" class="form-control" type="number" step="0.01" value="<?= e($item['default_tax_rate']) ?>">
        </div>
      </div>
      <div class="mt-3">
        <button class="btn btn-success">Update</button>
        <a href="list.php" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>
