<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();

$err = '';
// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'Invalid request';
  } else {
    $name  = trim($_POST['name']);
    $hsn   = trim($_POST['hsn']);
    $price = floatval($_POST['price']);
    $tax   = floatval($_POST['tax']);

    $stmt = $pdo->prepare("INSERT INTO items (name, hsn_sac, default_price, default_tax_rate, created_at) 
                           VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$name, $hsn, $price, $tax]);

    header('Location: list.php'); 
    exit;
  }
}

// Fetch all items
$items = $pdo->query("SELECT * FROM items ORDER BY id DESC")->fetchAll();
?>

<div class="container my-4">
  <div class="card p-3 shadow-sm mb-4">
    <h4>Add Item</h4>
    <?php if ($err): ?>
      <div class="alert alert-danger"><?= e($err) ?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div class="row g-2">
        <div class="col-md-6">
          <label>Name</label>
          <input name="name" class="form-control" required>
        </div>
        <div class="col-md-3">
          <label>HSN/SAC</label>
          <input name="hsn" class="form-control">
        </div>
        <div class="col-md-3">
          <label>Price</label>
          <input name="price" class="form-control" type="number" step="0.01">
        </div>
        <div class="col-md-3">
          <label>Tax %</label>
          <input name="tax" class="form-control" type="number" step="0.01" value="18">
        </div>
      </div>
      <div class="mt-3">
        <button class="btn btn-success">Save</button>
      </div>
    </form>
  </div>

  <!-- Item List -->
  <div class="card p-3 shadow-sm">
    <h4>All Items</h4>
    <div class="table-responsive">
      <table class="table table-bordered table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Name</th>
            <th>HSN/SAC</th>
            <th>Price</th>
            <th>Tax %</th>
            <th>Created At</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($items): ?>
            <?php foreach($items as $it): ?>
              <tr>
                <td><?= e($it['id']) ?></td>
                <td><?= e($it['name']) ?></td>
                <td><?= e($it['hsn_sac']) ?></td>
                <td>₹ <?= money($it['default_price']) ?></td>
                <td><?= e($it['default_tax_rate']) ?>%</td>
                <td><?= e($it['created_at']) ?></td>
                <td>
                  <a href="edit.php?id=<?= e($it['id']) ?>" class="btn btn-sm btn-outline-primary">
                    Edit
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="7" class="text-center text-muted">No items found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>
