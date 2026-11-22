<?php
require_once '../../includes/functions.php';
require_once '../../includes/ledger_helper.php';
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

$invoice_id = intval($_POST['invoice_id'] ?? 0);
$action = trim($_POST['action'] ?? 'cancel');
$reason = trim($_POST['reason'] ?? '');
$returnTo = trim($_POST['return_to'] ?? 'list');

if ($invoice_id <= 0) {
    set_flash("Invalid invoice ID.", "danger");
    header("Location: list.php");
    exit;
}

$stmt = $pdo->prepare("SELECT id, invoice_no, status, customer_id FROM invoices WHERE id = ?");
$stmt->execute([$invoice_id]);
$inv = $stmt->fetch();

if (!$inv) {
    set_flash("Invoice not found.", "danger");
    header("Location: list.php");
    exit;
}

if ($action === 'restore') {
    if (restore_invoice($invoice_id)) {
        set_flash("Invoice " . e($inv['invoice_no']) . " has been restored to active status.", "success");
    } else {
        set_flash("Failed to restore invoice.", "danger");
    }
} else {
    // Cancel invoice
    if (cancel_invoice($invoice_id, $reason, $_SESSION['user_id'] ?? null)) {
        set_flash("Invoice " . e($inv['invoice_no']) . " has been marked as CANCELLED. Serial number remains preserved for CA compliance.", "success");
    } else {
        set_flash("Failed to cancel invoice.", "danger");
    }
}

if ($returnTo === 'view') {
    header("Location: view.php?id=" . $invoice_id);
} else {
    header("Location: list.php");
}
exit;
