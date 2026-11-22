<?php
require_once '../../includes/functions.php';
require_login();

// Database connection
global $pdo;

$id = $_GET['id'] ?? null;

if (!$id) {
    die("Invalid ID");
}

// Fetch existing recurring invoice
$stmt = $pdo->prepare("SELECT * FROM recurring_invoices WHERE id=?");
$stmt->execute([$id]);
$recurring = $stmt->fetch();

if (!$recurring) {
    die("Invalid ID");
}

// If form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = $_POST['amount'];
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : NULL;
    $frequency = $_POST['frequency'];
    $status = $_POST['status'];

    $update = $pdo->prepare("
        UPDATE recurring_invoices
        SET amount=?, end_date=?, frequency=?, status=?
        WHERE id=?
    ");
    $update->execute([$amount, $end_date, $frequency, $status, $id]);

    // Redirect before any HTML output
    header('Location: index.php?updated=1');
    exit;
}

// Include layout AFTER header logic
require_once '../../includes/layout.php';
?>

<div class="container my-4">
    <h4>Edit Recurring Invoice</h4>
    <form method="POST">
        <div class="mb-3">
            <label>Amount</label>
            <input type="number" name="amount" value="<?= htmlspecialchars($recurring['amount']) ?>" step="0.01" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>End Date</label>
            <input type="date" name="end_date" value="<?= htmlspecialchars($recurring['end_date']) ?>" class="form-control">
        </div>
        <div class="mb-3">
            <label>Frequency</label>
            <select name="frequency" class="form-control" required>
                <option value="daily" <?= $recurring['frequency']=='daily'?'selected':'' ?>>Daily</option>
                <option value="weekly" <?= $recurring['frequency']=='weekly'?'selected':'' ?>>Weekly</option>
                <option value="monthly" <?= $recurring['frequency']=='monthly'?'selected':'' ?>>Monthly</option>
                <option value="quarterly" <?= $recurring['frequency']=='quarterly'?'selected':'' ?>>Quarterly</option>
                <option value="yearly" <?= $recurring['frequency']=='yearly'?'selected':'' ?>>Yearly</option>
            </select>
        </div>
        <div class="mb-3">
            <label>Status</label>
            <select name="status" class="form-control" required>
                <option value="active" <?= $recurring['status']=='active'?'selected':'' ?>>Active</option>
                <option value="paused" <?= $recurring['status']=='paused'?'selected':'' ?>>Paused</option>
                <option value="cancelled" <?= $recurring['status']=='cancelled'?'selected':'' ?>>Cancelled</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Update</button>
    </form>
</div>
