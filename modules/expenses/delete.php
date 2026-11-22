<?php
require_once '../../includes/functions.php';
require_admin_panel();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash("Invalid request method.", "danger");
    header("Location: expenses.php");
    exit;
}

if (!verify_csrf($_POST['csrf'] ?? '')) {
    set_flash("Invalid security token.", "danger");
    header("Location: expenses.php");
    exit;
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash("Invalid Expense ID.", "danger");
    header("Location: expenses.php");
    exit;
}

$stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
$stmt->execute([$id]);

set_flash("Expense deleted successfully.", "success");
header("Location: expenses.php");
exit;
