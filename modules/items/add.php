<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) $err='Invalid';
  else {
    $name=trim($_POST['name']); $hsn=trim($_POST['hsn']); $price=floatval($_POST['price']); $tax=floatval($_POST['tax']);
    $stmt=$pdo->prepare("INSERT INTO items (name,hsn_sac,default_price,default_tax_rate,created_at) VALUES (?,?,?,?,NOW())");
    $stmt->execute([$name,$hsn,$price,$tax]);
    header('Location:list.php'); exit;
  }
}

?>
<div class="container my-4">
  <div class="card p-3"><h4>Add Item</h4>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div class="row g-2">
        <div class="col-md-6"><label>Name</label><input name="name" class="form-control" required></div>
        <div class="col-md-3"><label>HSN/SAC</label><input name="hsn" class="form-control"></div>
        <div class="col-md-3"><label>Price</label><input name="price" class="form-control" type="number" step="0.01"></div>
        <div class="col-md-3"><label>Tax %</label><input name="tax" class="form-control" type="number" step="0.01" value="18"></div>
      </div>
      <div class="mt-3"><button class="btn btn-success">Save</button></div>
    </form>
  </div>
</div>
<?php include '../../includes/footer.php'; ?>
