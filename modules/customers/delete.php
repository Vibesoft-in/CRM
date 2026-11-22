<?php
require_once '../../includes/functions.php';
require_admin_panel();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash("Invalid request method.", "danger");
    header("Location: list.php");
    exit;
}

if (!verify_csrf($_POST['csrf'] ?? '')) {
    set_flash("Invalid security token.", "danger");
    header("Location: list.php");
    exit;
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash("Invalid Client ID.", "danger");
    header("Location: list.php");
    exit;
}

$stmt = $pdo->prepare("SELECT id, name FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
    set_flash("Client not found.", "warning");
    header("Location: list.php");
    exit;
}

$stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
$stmt->execute([$id]);

set_flash("Client '" . e($customer['name']) . "' deleted successfully.", "success");
header("Location: list.php");
exit;
