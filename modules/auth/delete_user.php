<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';

require_login();

if (!is_admin()) {
    set_flash("Access Denied: Admin permissions required.", "danger");
    header("Location: users.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash("Invalid request method.", "danger");
    header("Location: users.php");
    exit;
}

if (!verify_csrf($_POST['csrf'] ?? '')) {
    set_flash("Invalid security token.", "danger");
    header("Location: users.php");
    exit;
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash("Invalid User ID.", "danger");
    header("Location: users.php");
    exit;
}

if ($id === (int)$_SESSION['user_id']) {
    set_flash("You cannot delete your own account!", "warning");
    header("Location: users.php");
    exit;
}

$stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
$stmt->execute([$id]);

set_flash("User deleted successfully.", "success");
header("Location: users.php");
exit;
