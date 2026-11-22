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

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash("Invalid invoice ID.", "danger");
    header("Location: list.php");
    exit;
}

$stmt = $pdo->prepare("SELECT id, invoice_no, customer_id FROM invoices WHERE id = ?");
$stmt->execute([$id]);
$inv = $stmt->fetch();

if (!$inv) {
    set_flash("Invoice not found.", "warning");
    header("Location: list.php");
    exit;
}

try {
    $pdo->beginTransaction();

    $customerId = $inv['customer_id'];

    // Delete invoice items
    $stmt = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
    $stmt->execute([$id]);

    // Delete payment allocations
    $stmt = $pdo->prepare("DELETE FROM payment_allocations WHERE invoice_id = ?");
    $stmt->execute([$id]);

    // Delete client ledger entry
    $stmt = $pdo->prepare("DELETE FROM client_ledger WHERE reference_type = 'invoice' AND reference_id = ?");
    $stmt->execute([$id]);

    // Delete invoice header
    $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
    $stmt->execute([$id]);

    recalculate_customer_ledger($customerId);

    $pdo->commit();

    set_flash("Invoice " . e($inv['invoice_no']) . " deleted successfully.", "success");
    header("Location: list.php");
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    set_flash("Error deleting invoice: " . $e->getMessage(), "danger");
    header("Location: list.php");
    exit;
}
