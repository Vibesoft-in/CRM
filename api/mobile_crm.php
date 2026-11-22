<?php
// Crm and backend/api/mobile_crm.php — REST API for CRM Clients, Invoices, Ledger, Projects, and Expenses
require_once __DIR__ . '/mobile_helper.php';
require_once __DIR__ . '/../includes/ledger_helper.php';

$auth = authenticate_mobile();
$action = $_GET['action'] ?? ($_POST['action'] ?? 'clients');
$input = get_json_input();

// ----------------------------------------------------
// 1. CLIENTS LIST
// ----------------------------------------------------
if ($action === 'clients') {
    require_role_api(['admin', 'super admin', 'manager', 'sales manager', 'finance manager', 'staff'], $auth);

    $typeFilter   = trim($_GET['type'] ?? '');
    $statusFilter = trim($_GET['status'] ?? '');
    $search       = trim($_GET['q'] ?? '');
    $pagination   = get_pagination_params(20, 100);

    $where = ["1=1"];
    $params = [];

    if (!empty($typeFilter)) {
        $where[] = "c.client_type = ?";
        $params[] = $typeFilter;
    }
    if (!empty($statusFilter)) {
        $where[] = "c.status = ?";
        $params[] = $statusFilter;
    }
    if (!empty($search)) {
        $where[] = "(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.contact_person LIKE ? OR c.gstin LIKE ?)";
        $term = "%$search%";
        $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
    }

    $whereSql = implode(" AND ", $where);

    // Total Count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $whereSql");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT c.*,
          COALESCE((SELECT SUM(total_amount) FROM invoices WHERE customer_id = c.id AND status != 'cancelled'), 0) AS total_billed,
          COALESCE((SELECT SUM(amount) FROM payments WHERE customer_id = c.id), 0) AS total_received
        FROM customers c
        WHERE $whereSql
        ORDER BY c.id DESC
        LIMIT ? OFFSET ?
    ");

    $bindIdx = 1;
    foreach ($params as $p) {
        $stmt->bindValue($bindIdx++, $p);
    }
    $stmt->bindValue($bindIdx++, $pagination['limit'], PDO::PARAM_INT);
    $stmt->bindValue($bindIdx++, $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $clients = [];
    foreach ($rows as $r) {
        $billed = (float)$r['total_billed'];
        $received = (float)$r['total_received'];
        $balance = max(0, $billed - $received);

        $clients[] = [
            'id'                  => (int)$r['id'],
            'name'                => $r['name'],
            'client_type'         => $r['client_type'] ?? 'monthly',
            'status'              => $r['status'] ?? 'active',
            'contact_person'      => $r['contact_person'] ?? '',
            'phone'               => $r['phone'] ?? '',
            'email'               => $r['email'] ?? '',
            'city'                => $r['city'] ?? '',
            'state'               => $r['state'] ?? '',
            'monthly_service'     => $r['monthly_service_name'] ?? '',
            'monthly_amount'      => (float)($r['monthly_amount'] ?? 0),
            'total_billed'        => $billed,
            'total_received'      => $received,
            'outstanding_balance' => $balance
        ];
    }

    json_success([
        'total'   => $totalRecords,
        'page'    => $pagination['page'],
        'limit'   => $pagination['limit'],
        'clients' => $clients
    ], 'Clients loaded');
}

// ----------------------------------------------------
// 2. CLIENT DETAIL
// ----------------------------------------------------
if ($action === 'client_detail') {
    require_role_api(['admin', 'super admin', 'manager', 'sales manager', 'finance manager', 'staff'], $auth);

    $clientId = (int)($_GET['id'] ?? 0);
    if ($clientId <= 0) json_error('Client ID is required', 400);

    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) json_error('Client not found', 404);

    // Fetch recent invoices
    $invStmt = $pdo->prepare("SELECT id, invoice_no, invoice_date, due_date, total_amount, amount_paid, amount_due, status FROM invoices WHERE customer_id = ? ORDER BY id DESC LIMIT 10");
    $invStmt->execute([$clientId]);
    $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch recent payments
    $payStmt = $pdo->prepare("SELECT id, payment_no, payment_date, amount, payment_method, transaction_id FROM payments WHERE customer_id = ? ORDER BY id DESC LIMIT 10");
    $payStmt->execute([$clientId]);
    $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate ledger balance
    $balStmt = $pdo->prepare("SELECT balance FROM client_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1");
    $balStmt->execute([$clientId]);
    $currentBalance = (float)($balStmt->fetchColumn() ?: 0);

    json_success([
        'client'          => $client,
        'current_balance' => $currentBalance,
        'recent_invoices' => $invoices,
        'recent_payments' => $payments
    ], 'Client details loaded');
}

// ----------------------------------------------------
// 3. INVOICES LIST
// ----------------------------------------------------
if ($action === 'invoices') {
    require_role_api(['admin', 'super admin', 'manager', 'finance manager', 'staff'], $auth);

    $statusFilter = trim($_GET['status'] ?? '');
    $search       = trim($_GET['q'] ?? '');
    $pagination   = get_pagination_params(20, 100);

    $where = ["1=1"];
    $params = [];

    if (!empty($statusFilter)) {
        $where[] = "i.status = ?";
        $params[] = $statusFilter;
    }
    if (!empty($search)) {
        $where[] = "(i.invoice_no LIKE ? OR c.name LIKE ?)";
        $term = "%$search%";
        $params[] = $term; $params[] = $term;
    }

    $whereSql = implode(" AND ", $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE $whereSql");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT i.*, c.name AS customer_name, c.phone AS customer_phone
        FROM invoices i
        LEFT JOIN customers c ON c.id = i.customer_id
        WHERE $whereSql
        ORDER BY i.id DESC
        LIMIT ? OFFSET ?
    ");

    $bindIdx = 1;
    foreach ($params as $p) {
        $stmt->bindValue($bindIdx++, $p);
    }
    $stmt->bindValue($bindIdx++, $pagination['limit'], PDO::PARAM_INT);
    $stmt->bindValue($bindIdx++, $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_success([
        'total'    => $totalRecords,
        'page'     => $pagination['page'],
        'limit'    => $pagination['limit'],
        'invoices' => $invoices
    ], 'Invoices loaded');
}

// ----------------------------------------------------
// 4. INVOICE DETAIL
// ----------------------------------------------------
if ($action === 'invoice_detail') {
    require_role_api(['admin', 'super admin', 'manager', 'finance manager', 'staff'], $auth);

    $invoiceId = (int)($_GET['id'] ?? 0);
    if ($invoiceId <= 0) json_error('Invoice ID is required', 400);

    $stmt = $pdo->prepare("SELECT i.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone, c.gstin as customer_gstin, c.billing_address FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE i.id = ?");
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inv) json_error('Invoice not found', 404);

    $itemStmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
    $itemStmt->execute([$invoiceId]);
    $inv['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    json_success(['invoice' => $inv], 'Invoice detail loaded');
}

// ----------------------------------------------------
// 4B. CALCULATE INVOICE PREVIEW (SERVER-AUTHORITATIVE)
// ----------------------------------------------------
if ($action === 'calculate_invoice') {
    require_role_api(['admin', 'super admin', 'manager', 'finance manager', 'staff'], $auth);
    $items = $input['items'] ?? [];
    $discount = (float)($input['discount'] ?? 0);
    $calc = calculate_invoice_totals($items, $discount);
    json_success($calc, 'Invoice calculation computed');
}

// ----------------------------------------------------
// 4C. CREATE INVOICE (MOBILE FLOW)
// ----------------------------------------------------
if ($action === 'create_invoice') {
    require_role_api(['admin', 'super admin', 'manager', 'finance manager'], $auth);

    $customerId = (int)($input['customer_id'] ?? 0);
    $items = $input['items'] ?? [];

    if ($customerId <= 0 || empty($items) || !is_array($items)) {
        json_error('Customer ID and at least one item are required', 400);
    }

    $cStmt = $pdo->prepare("SELECT id, name FROM customers WHERE id = ? LIMIT 1");
    $cStmt->execute([$customerId]);
    $client = $cStmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) json_error('Customer not found', 404);

    try {
        $pdo->beginTransaction();

        $ctrRow = $pdo->query("SELECT last_number FROM invoice_counter WHERE id = 1 FOR UPDATE")->fetch();
        $newNum = ($ctrRow ? (int)$ctrRow['last_number'] : 10000) + 1;
        $pdo->exec("UPDATE invoice_counter SET last_number = {$newNum} WHERE id = 1");
        $invoiceNo = 'INV-' . $newNum;

        $invoiceDate   = !empty($input['invoice_date']) ? $input['invoice_date'] : date('Y-m-d');
        $dueDate       = !empty($input['due_date']) ? $input['due_date'] : date('Y-m-d', strtotime($invoiceDate . ' + 10 days'));
        $placeOfSupply = trim($input['place_of_supply'] ?? 'Uttarakhand');
        $notes         = trim($input['notes'] ?? '');
        $discount      = (float)($input['discount'] ?? 0);

        $insStmt = $pdo->prepare("
            INSERT INTO invoices (invoice_no, customer_id, invoice_date, due_date, place_of_supply, notes, discount, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'sent', ?, NOW())
        ");
        $insStmt->execute([$invoiceNo, $customerId, $invoiceDate, $dueDate, $placeOfSupply, $notes, $discount, $auth['user_id']]);
        $invoiceId = (int)$pdo->lastInsertId();

        $taxableValue = 0;
        $taxTotal = 0;

        foreach ($items as $it) {
            $name     = trim($it['name'] ?? 'Service / Item');
            $hsn      = trim($it['hsn_sac'] ?? ($it['hsn'] ?? ''));
            $qty      = max(1, (int)($it['qty'] ?? 1));
            $rate     = (float)($it['rate'] ?? 0);
            $taxRate  = (float)($it['tax_rate'] ?? 0);
            $lineDisc = (float)($it['discount'] ?? 0);
            $isInc    = !empty($it['is_tax_inclusive']) || !empty($it['tax_included']);
            $handler  = trim($it['handler_name'] ?? '');

            $lineCalc   = calculate_invoice_line_item($qty, $rate, $taxRate, $lineDisc, $isInc);
            $lineAmount = $lineCalc['taxable_amount'];
            $lineTax    = $lineCalc['tax_amount'];

            $insItem = $pdo->prepare("
                INSERT INTO invoice_items (invoice_id, name, hsn_sac, qty, rate, tax_rate, is_tax_inclusive, line_tax, line_total, discount, handler_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insItem->execute([$invoiceId, $name, $hsn, $qty, $rate, $taxRate, $isInc ? 1 : 0, $lineTax, $lineAmount, $lineDisc, $handler]);

            $taxableValue += $lineAmount;
            $taxTotal += $lineTax;
        }

        $totalAmount = max(0, round(($taxableValue + $taxTotal) - $discount, 2));

        $updInv = $pdo->prepare("UPDATE invoices SET taxable_value = ?, tax_total = ?, total_amount = ?, amount_due = ? WHERE id = ?");
        $updInv->execute([$taxableValue, $taxTotal, $totalAmount, $totalAmount, $invoiceId]);

        post_invoice_to_ledger($invoiceId);

        $pdo->commit();

        json_success([
            'id'           => $invoiceId,
            'invoice_no'   => $invoiceNo,
            'total_amount' => $totalAmount
        ], 'Invoice created successfully');
    } catch (\Throwable $e) {
        $pdo->rollBack();
        json_error('Failed to create invoice: ' . $e->getMessage(), 500);
    }
}

// ----------------------------------------------------
// 5. RECORD CLIENT PAYMENT (REUSES LEDGER ENGINE)
// ----------------------------------------------------
if ($action === 'record_payment') {
    require_role_api(['admin', 'super admin', 'manager', 'finance manager'], $auth);

    $customerId     = (int)($input['customer_id'] ?? 0);
    $amount         = (float)($input['amount'] ?? 0);
    $paymentDate    = !empty($input['payment_date']) ? $input['payment_date'] : date('Y-m-d');
    $paymentMethod  = $input['payment_method'] ?? 'Bank Transfer';
    $transactionId  = trim($input['transaction_id'] ?? '');
    $reference      = trim($input['reference'] ?? '');
    $notes          = trim($input['notes'] ?? '');
    $allocations    = $input['allocations'] ?? [];

    if ($customerId <= 0 || $amount <= 0) {
        json_error('Valid Client and positive Payment Amount are required', 400);
    }

    try {
        $payId = record_client_payment(
            $customerId,
            $amount,
            $paymentDate,
            $paymentMethod,
            $transactionId,
            $reference,
            $notes,
            $allocations
        );

        try {
            require_once __DIR__ . '/../app/Realtime/RealtimeService.php';
            \App\Realtime\RealtimeService::getInstance($pdo)->emit(
                'payment.recorded',
                'payment',
                (int)$payId,
                'create',
                [
                    'payment_id'   => (int)$payId,
                    'customer_id'  => (int)$customerId,
                    'amount'       => (float)$amount,
                    'payment_date' => $paymentDate,
                    'payment_mode' => $paymentMethod
                ],
                ['admin', 'manager', 'finance manager'],
                null,
                (int)$auth['user_id']
            );
        } catch (\Throwable $e) {}

        json_success([
            'payment_id' => $payId
        ], 'Payment recorded successfully and client ledger updated');
    } catch (\Throwable $e) {
        json_error('Failed to record payment: ' . $e->getMessage(), 500);
    }
}

// ----------------------------------------------------
// 6. CLIENT STATEMENT OF ACCOUNT / LEDGER
// ----------------------------------------------------
if ($action === 'client_ledger') {
    require_role_api(['admin', 'super admin', 'manager', 'finance manager'], $auth);

    $customerId = (int)($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) json_error('Customer ID is required', 400);

    $stmt = $pdo->prepare("SELECT * FROM client_ledger WHERE customer_id = ? ORDER BY entry_date ASC, id ASC");
    $stmt->execute([$customerId]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_success(['ledger' => $entries], 'Client ledger loaded');
}

// ----------------------------------------------------
// 7. PROJECTS LIST
// ----------------------------------------------------
if ($action === 'projects') {
    require_role_api(['admin', 'super admin', 'manager', 'sales manager', 'staff'], $auth);

    $stmt = $pdo->query("
        SELECT p.*, c.name as customer_name 
        FROM projects p 
        LEFT JOIN customers c ON c.id = p.customer_id 
        ORDER BY p.id DESC
    ");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_success(['projects' => $projects], 'Projects loaded');
}

// ----------------------------------------------------
// 8. EXPENSES LIST & CREATE
// ----------------------------------------------------
if ($action === 'expenses') {
    require_role_api(['admin', 'super admin', 'manager', 'finance manager'], $auth);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $date     = $input['expense_date'] ?? date('Y-m-d');
        $cat      = trim($input['category'] ?? '');
        $desc     = trim($input['description'] ?? '');
        $vendor   = trim($input['vendor'] ?? '');
        $amount   = (float)($input['amount'] ?? 0);
        $taxAmt   = (float)($input['tax_amount'] ?? 0);
        $mode     = trim($input['payment_mode'] ?? 'Cash');

        if (empty($cat) || $amount <= 0) {
            json_error('Category and valid positive Amount are required', 400);
        }

        $ins = $pdo->prepare("INSERT INTO expenses (expense_date, category, description, vendor, amount, tax_amount, payment_mode, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'Paid', ?, NOW())");
        $ins->execute([$date, $cat, $desc, $vendor, $amount, $taxAmt, $mode, $auth['user_id']]);

        json_success(['expense_id' => (int)$pdo->lastInsertId()], 'Expense recorded successfully');
    } else {
        $month = (int)($_GET['month'] ?? date('m'));
        $year  = (int)($_GET['year'] ?? date('Y'));

        $stmt = $pdo->prepare("SELECT * FROM expenses WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ? ORDER BY expense_date DESC, id DESC");
        $stmt->execute([$month, $year]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalAmt = (float)($pdo->prepare("SELECT SUM(amount) FROM expenses WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ?")->execute([$month, $year]) ? $stmt->fetchColumn() : 0);

        json_success(['expenses' => $rows, 'month' => $month, 'year' => $year], 'Expenses loaded');
    }
}

json_error('Unknown CRM action', 400);
