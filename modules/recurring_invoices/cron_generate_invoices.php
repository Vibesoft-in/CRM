<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/ledger_helper.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $authKey = $_GET['key'] ?? '';
    $validKey = defined('CRON_KEY') ? CRON_KEY : 'vibesoft_cron_secret_2026';
    if ($authKey !== $validKey && !is_admin()) {
        http_response_code(403);
        die("Unauthorized cron execution.");
    }
}

$today = date('Y-m-d');

// 1. Process recurring_invoices rules
$recurring = $pdo->query("SELECT * FROM recurring_invoices WHERE status='active'")->fetchAll();

foreach ($recurring as $r) {
    if ($today >= $r['next_invoice_date']) {
        $invGen = generate_next_invoice_number('gst', $today);
        $invoice_no = $invGen['invoice_no'];
        $invSerial  = $invGen['serial_no'];

        $periodStart = date('d M Y', strtotime('-1 month', strtotime($today)));
        $periodEnd   = date('d M Y', strtotime($today));
        $desc = "Recurring Monthly Retainer ($periodStart - $periodEnd)";

        $stmt = $pdo->prepare("
            INSERT INTO invoices (invoice_no, invoice_type, serial_no, invoice_date, payment_mode, customer_id, notes, taxable_value, tax_total, total_amount, status, due_date, amount_paid, amount_due, created_by, created_at)
            VALUES (?, 'gst', ?, ?, 'Pending', ?, ?, ?, 0, ?, 'sent', ?, 0, ?, 1, NOW())
        ");
        $dueDate = date('Y-m-d', strtotime('+10 days', strtotime($today)));
        $stmt->execute([
            $invoice_no,
            $invSerial,
            $today,
            $r['customer_id'],
            $desc,
            $r['amount'],
            $r['amount'],
            $dueDate,
            $r['amount']
        ]);
        $invId = $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, qty, rate, line_total, tax_rate) VALUES (?, ?, 1, ?, ?, 0)")->execute([$invId, $desc, $r['amount'], $r['amount']]);
        post_invoice_to_ledger($invId);
        sync_invoice_to_subregistry($invId);

        // Calculate next date
        $next_date = date('Y-m-d', strtotime("+1 {$r['frequency']}", strtotime($r['next_invoice_date'])));
        $update = $pdo->prepare("UPDATE recurring_invoices SET next_invoice_date=? WHERE id=?");
        $update->execute([$next_date, $r['id']]);
    }
}
