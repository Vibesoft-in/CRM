<?php
// Crm and backend/api/invoices.php — Full RESTful API for Invoice Management
require_once __DIR__ . '/mobile_helper.php';
require_once __DIR__ . '/../includes/ledger_helper.php';
require_once __DIR__ . '/../includes/audit_helper.php';

$auth = authenticate_mobile();
require_role_api(['admin', 'super admin', 'manager', 'finance manager', 'staff'], $auth);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = get_json_input();
$action = $_GET['action'] ?? ($input['action'] ?? '');
$id = (int)($_GET['id'] ?? ($input['id'] ?? 0));

// ====================================================
// 1. GET /api/invoices.php (LIST or DETAIL)
// ====================================================
if ($method === 'GET') {
    // 1A. INVOICE DETAIL
    if ($id > 0) {
        $stmt = $pdo->prepare("
            SELECT i.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone, 
                   c.gstin as customer_gstin, c.billing_address, c.shipping_address, c.state_code
            FROM invoices i 
            LEFT JOIN customers c ON c.id = i.customer_id 
            WHERE i.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inv) {
            json_error('Invoice not found', 404, ['code' => 'INVOICE_NOT_FOUND']);
        }

        // Fetch line items
        $itemsStmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
        $itemsStmt->execute([$id]);
        $inv['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch payment allocations
        $allocStmt = $pdo->prepare("
            SELECT pa.*, p.payment_no, p.payment_date, p.payment_method, p.transaction_id
            FROM payment_allocations pa
            JOIN payments p ON p.id = pa.payment_id
            WHERE pa.invoice_id = ?
            ORDER BY pa.id DESC
        ");
        $allocStmt->execute([$id]);
        $inv['payments'] = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

        json_success(['invoice' => $inv], 'Invoice details loaded');
    }

    // 1B. INVOICE LIST (WITH SEARCH, STATUS FILTER, CUSTOMER FILTER, PAGINATION)
    $statusFilter = trim($_GET['status'] ?? '');
    $customerFilter = (int)($_GET['customer_id'] ?? 0);
    $search       = trim($_GET['q'] ?? '');
    $sortCol      = trim($_GET['sort'] ?? 'id');
    $sortOrder    = strtolower(trim($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $pagination   = get_pagination_params(20, 100);

    $allowedSort = ['id', 'invoice_no', 'invoice_date', 'due_date', 'total_amount', 'status'];
    if (!in_array($sortCol, $allowedSort)) {
        $sortCol = 'id';
    }

    $where = ["1=1"];
    $params = [];

    if (!empty($statusFilter)) {
        $where[] = "i.status = ?";
        $params[] = $statusFilter;
    }
    if ($customerFilter > 0) {
        $where[] = "i.customer_id = ?";
        $params[] = $customerFilter;
    }
    if (!empty($search)) {
        $where[] = "(i.invoice_no LIKE ? OR c.name LIKE ?)";
        $term = "%" . sanitize_like_term($search) . "%";
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
        ORDER BY i.{$sortCol} {$sortOrder}
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

    paginate_response($invoices, $totalRecords, $pagination['page'], $pagination['limit'], 'Invoices loaded', ['invoices' => $invoices]);
}

// ====================================================
// 2. POST /api/invoices.php (CREATE or QUICK STATUS CHANGE)
// ====================================================
if ($method === 'POST') {
    // 2A-0. DYNAMIC CALCULATION ENDPOINT (Without saving)
    if ($action === 'calculate') {
        $items = $input['items'] ?? [];
        $discount = (float)($input['discount'] ?? 0);
        $calcResult = calculate_invoice_totals($items, $discount);
        json_success($calcResult, 'Invoice calculated successfully');
    }

    // 2A. QUICK STATUS CHANGE (e.g. Mark Paid, Sent, Cancelled)
    if ($action === 'change_status') {
        require_role_api(['admin', 'super admin', 'manager', 'finance manager'], $auth);
        $newStatus = strtolower(trim($input['status'] ?? ''));

        if ($id <= 0 || !in_array($newStatus, ['draft', 'sent', 'viewed', 'partially_paid', 'paid', 'overdue', 'cancelled'])) {
            json_error('Valid invoice ID and status are required', 400);
        }

        $invStmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
        $invStmt->execute([$id]);
        $inv = $invStmt->fetch(PDO::FETCH_ASSOC);

        if (!$inv) json_error('Invoice not found', 404);

        if ($newStatus === 'paid' && $inv['status'] !== 'paid') {
            $dueAmt = (float)$inv['amount_due'];
            if ($dueAmt > 0) {
                record_client_payment(
                    $inv['customer_id'],
                    $dueAmt,
                    date('Y-m-d'),
                    'Bank Transfer',
                    'TXN-API-QUICK',
                    'API Status Mark Paid',
                    'Status updated via Mobile API',
                    [['invoice_id' => $id, 'amount' => $dueAmt]]
                );
            } else {
                $pdo->prepare("UPDATE invoices SET status = 'paid', amount_due = 0, amount_paid = total_amount WHERE id = ?")->execute([$id]);
            }
        } else {
            $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
        }

        log_audit('Invoice', 'Status Changed to ' . $newStatus, $id, ['invoice_no' => $inv['invoice_no']]);
        json_success(['id' => $id, 'status' => $newStatus], 'Invoice status updated successfully');
    }

    // 2B. CREATE NEW INVOICE WITH ITEMS & LEDGER DEBIT
    require_role_api(['admin', 'super admin', 'manager', 'finance manager'], $auth);

    $customerId = (int)($input['customer_id'] ?? 0);
    $items = $input['items'] ?? [];

    if ($customerId <= 0 || empty($items) || !is_array($items)) {
        json_error('Customer ID and at least one item are required', 400, [
            'customer_id' => 'Valid customer required',
            'items' => 'Item list required'
        ]);
    }

    $cStmt = $pdo->prepare("SELECT id, name FROM customers WHERE id = ? LIMIT 1");
    $cStmt->execute([$customerId]);
    $client = $cStmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) json_error('Customer not found', 404);

    try {
        $pdo->beginTransaction();

        // 1. Generate sequential invoice number from invoice_counter
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

        // Update totals on invoice record
        $updInv = $pdo->prepare("UPDATE invoices SET taxable_value = ?, tax_total = ?, total_amount = ?, amount_due = ? WHERE id = ?");
        $updInv->execute([$taxableValue, $taxTotal, $totalAmount, $totalAmount, $invoiceId]);

        // Post debit to client ledger
        post_invoice_to_ledger($invoiceId);

        $pdo->commit();

        log_audit('Invoice', 'Created Invoice', $invoiceId, ['invoice_no' => $invoiceNo, 'total' => $totalAmount]);

        try {
            require_once __DIR__ . '/../app/Realtime/RealtimeService.php';
            \App\Realtime\RealtimeService::getInstance($pdo)->emit(
                'crm.invoice_created',
                'invoice',
                $invoiceId,
                'create',
                [
                    'id'           => $invoiceId,
                    'customer_id'  => $customerId,
                    'invoice_no'   => $invoiceNo,
                    'total_amount' => $totalAmount
                ],
                ['admin', 'manager', 'finance manager', 'staff'],
                null,
                $auth['user_id']
            );
        } catch (\Throwable $e) {}

        json_success([
            'id'           => $invoiceId,
            'invoice_no'   => $invoiceNo,
            'total_amount' => $totalAmount
        ], 'Invoice created successfully and posted to ledger', 201);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        if ($e instanceof \PDOException) {
            handle_db_exception($e, 'invoice');
        }
        api_log_error('Failed to create invoice: ' . $e->getMessage());
        json_error('Failed to create invoice. Please verify input and try again.', 500, [], ['code' => 'INVOICE_CREATE_FAILED']);
    }
}

// ====================================================
// 3. PUT /api/invoices.php?id={id} (UPDATE INVOICE)
// ====================================================
if ($method === 'PUT') {
    require_role_api(['admin', 'super admin', 'manager', 'finance manager'], $auth);

    if ($id <= 0) {
        json_error('Valid Invoice ID is required for update', 400);
    }

    $invStmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
    $invStmt->execute([$id]);
    $inv = $invStmt->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        json_error('Invoice not found', 404);
    }

    try {
        $pdo->beginTransaction();

        $dueDate       = !empty($input['due_date']) ? $input['due_date'] : $inv['due_date'];
        $placeOfSupply = trim($input['place_of_supply'] ?? $inv['place_of_supply']);
        $notes         = trim($input['notes'] ?? $inv['notes']);
        $discount      = isset($input['discount']) ? (float)$input['discount'] : (float)$inv['discount'];
        $newStatus     = trim($input['status'] ?? $inv['status']);

        // Check if items are being updated
        if (!empty($input['items']) && is_array($input['items'])) {
            // Delete old items
            $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);

            $taxableValue = 0;
            $taxTotal = 0;

            foreach ($input['items'] as $it) {
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
                $insItem->execute([$id, $name, $hsn, $qty, $rate, $taxRate, $isInc ? 1 : 0, $lineTax, $lineAmount, $lineDisc, $handler]);

                $taxableValue += $lineAmount;
                $taxTotal += $lineTax;
            }

            $totalAmount = max(0, round(($taxableValue + $taxTotal) - $discount, 2));
            $paidAmt = (float)$inv['amount_paid'];
            $dueAmt  = max(0, $totalAmount - $paidAmt);

            if ($paidAmt >= $totalAmount && $totalAmount > 0) {
                $newStatus = 'paid';
            } elseif ($paidAmt > 0) {
                $newStatus = 'partially_paid';
            }

            $updInv = $pdo->prepare("
                UPDATE invoices 
                SET due_date = ?, place_of_supply = ?, notes = ?, discount = ?, taxable_value = ?, tax_total = ?, total_amount = ?, amount_due = ?, status = ?
                WHERE id = ?
            ");
            $updInv->execute([$dueDate, $placeOfSupply, $notes, $discount, $taxableValue, $taxTotal, $totalAmount, $dueAmt, $newStatus, $id]);

            post_invoice_to_ledger($id);
        } else {
            // Header only update
            $updInv = $pdo->prepare("
                UPDATE invoices 
                SET due_date = ?, place_of_supply = ?, notes = ?, discount = ?, status = ?
                WHERE id = ?
            ");
            $updInv->execute([$dueDate, $placeOfSupply, $notes, $discount, $newStatus, $id]);
        }

        $pdo->commit();

        log_audit('Invoice', 'Updated Invoice', $id, ['invoice_no' => $inv['invoice_no']]);

        json_success(['id' => $id, 'invoice_no' => $inv['invoice_no']], 'Invoice updated successfully');
    } catch (\Throwable $e) {
        $pdo->rollBack();
        if ($e instanceof \PDOException) {
            handle_db_exception($e, 'invoice');
        }
        api_log_error('Failed to update invoice: ' . $e->getMessage());
        json_error('Failed to update invoice. Please verify input and try again.', 500, [], ['code' => 'INVOICE_UPDATE_FAILED']);
    }
}

// ====================================================
// 4. DELETE /api/invoices.php (DELETE INVOICE)
// ====================================================
if ($method === 'DELETE') {
    require_role_api(['admin', 'super admin'], $auth);

    if ($id <= 0) json_error('Valid Invoice ID is required for deletion', 400, ['code' => 'INVALID_ID']);

    $invStmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
    $invStmt->execute([$id]);
    $inv = $invStmt->fetch(PDO::FETCH_ASSOC);

    if (!$inv) json_error('Invoice not found', 404, ['code' => 'INVOICE_NOT_FOUND']);

    try {
        $pdo->beginTransaction();

        // 1. Delete associated payments/allocations or reject if payments exist
        $hasPay = (int)($pdo->query("SELECT COUNT(*) FROM payment_allocations WHERE invoice_id = $id")->fetchColumn() ?: 0);
        if ($hasPay > 0) {
            $pdo->rollBack();
            json_error('Cannot delete invoice with recorded payments. Please cancel it instead.', 400, ['code' => 'HAS_PAYMENTS']);
        }

        // 2. Remove ledger entry
        $pdo->prepare("DELETE FROM client_ledger WHERE reference_type = 'invoice' AND reference_id = ?")->execute([$id]);
        recalculate_customer_ledger($inv['customer_id']);

        // 3. Delete invoice and items (cascading)
        $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$id]);

        $pdo->commit();

        log_audit('Invoice', 'Deleted Invoice', $id, ['invoice_no' => $inv['invoice_no']]);
        json_success(['id' => $id], 'Invoice deleted and ledger reconciled successfully');
    } catch (\Throwable $e) {
        $pdo->rollBack();
        if ($e instanceof \PDOException) {
            handle_db_exception($e, 'invoice');
        }
        api_log_error('Failed to delete invoice: ' . $e->getMessage());
        json_error('Failed to delete invoice. Please try again later.', 500, [], ['code' => 'INVOICE_DELETE_FAILED']);
    }
}

json_error('Method Not Allowed', 405);
