<?php
require_once __DIR__ . '/../includes/functions.php';

echo "Starting complete backfill and synchronization of invoice subregistries...\n";

// Clear existing entries in subregistries to establish clean, continuous sequential numbering
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE gst_invoices");
$pdo->exec("TRUNCATE TABLE personal_invoices");

// 1. Process all GST invoices in strict chronological order
$stmt = $pdo->query("SELECT * FROM invoices WHERE (invoice_type = 'gst' OR invoice_type IS NULL OR invoice_type = '') ORDER BY invoice_date ASC, id ASC");
$gstInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$serial = 1;
foreach ($gstInvoices as $inv) {
    $fy = get_financial_year($inv['invoice_date']);
    $m = date('m', strtotime($inv['invoice_date'] ?: 'now'));
    $status = ($inv['status'] === 'cancelled') ? 'cancelled' : 'active';
    $reason = $inv['cancellation_reason'] ?? null;
    $taxTotal = (float)($inv['tax_total'] ?? 0);
    $taxable = (float)($inv['taxable_value'] ?? 0);
    $cgst = round($taxTotal / 2, 2);
    $sgst = round($taxTotal / 2, 2);
    $igst = 0.00;

    $pdo->prepare("INSERT INTO gst_invoices (serial_no, invoice_no, invoice_id, customer_id, invoice_date, taxable_value, cgst_amount, sgst_amount, igst_amount, tax_total, total_amount, financial_year, month_str, status, cancellation_reason, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$serial, $inv['invoice_no'], $inv['id'], $inv['customer_id'], $inv['invoice_date'], $taxable, $cgst, $sgst, $igst, $taxTotal, $inv['total_amount'], $fy, $m, $status, $reason, $inv['notes']]);

    $pdo->prepare("UPDATE invoices SET serial_no = ?, invoice_type = 'gst' WHERE id = ?")->execute([$serial, $inv['id']]);
    $serial++;
}

$lastGstSerial = $serial - 1;
$pdo->prepare("UPDATE gst_invoice_counter SET last_serial = ? WHERE id = 1")->execute([$lastGstSerial]);
echo "✔ Synced $lastGstSerial GST invoices into `gst_invoices` (Counter set to $lastGstSerial).\n";

// 2. Process Personal invoices
$stmt = $pdo->query("SELECT * FROM invoices WHERE invoice_type = 'personal' ORDER BY invoice_date ASC, id ASC");
$persInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pSerial = 1;
foreach ($persInvoices as $inv) {
    $fy = get_financial_year($inv['invoice_date']);
    $m = date('m', strtotime($inv['invoice_date'] ?: 'now'));
    $status = ($inv['status'] === 'cancelled') ? 'cancelled' : 'active';
    $reason = $inv['cancellation_reason'] ?? null;

    $pdo->prepare("INSERT INTO personal_invoices (serial_no, invoice_no, invoice_id, customer_id, invoice_date, total_amount, payment_mode, financial_year, month_str, status, cancellation_reason, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$pSerial, $inv['invoice_no'], $inv['id'], $inv['customer_id'], $inv['invoice_date'], $inv['total_amount'], $inv['payment_mode'] ?: 'Personal Account', $fy, $m, $status, $reason, $inv['notes']]);

    $pdo->prepare("UPDATE invoices SET serial_no = ?, invoice_type = 'personal' WHERE id = ?")->execute([$pSerial, $inv['id']]);
    $pSerial++;
}

$lastPersSerial = $pSerial - 1;
$pdo->prepare("UPDATE personal_invoice_counter SET last_serial = ? WHERE id = 1")->execute([$lastPersSerial]);
echo "✔ Synced $lastPersSerial Personal invoices into `personal_invoices` (Counter set to $lastPersSerial).\n";

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
echo "Subregistry backfill completed successfully!\n";
