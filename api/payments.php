<?php
// Crm and backend/api/payments.php — RESTful API for Client Payments and Receipts
require_once __DIR__ . '/mobile_helper.php';
require_once __DIR__ . '/../includes/ledger_helper.php';
require_once __DIR__ . '/../includes/audit_helper.php';

$auth = authenticate_mobile();
require_role_api(['admin', 'super admin', 'manager', 'finance manager'], $auth);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = get_json_input();
$id = (int)($_GET['id'] ?? ($input['id'] ?? 0));

// ====================================================
// 1. GET /api/payments.php (LIST or DETAIL)
// ====================================================
if ($method === 'GET') {
    // 1A. PAYMENT DETAIL
    if ($id > 0) {
        $stmt = $pdo->prepare("
            SELECT p.*, c.name as customer_name, c.phone as customer_phone, c.email as customer_email
            FROM payments p
            JOIN customers c ON c.id = p.customer_id
            WHERE p.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $pay = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pay) json_error('Payment record not found', 404);

        // Fetch allocations
        $allocStmt = $pdo->prepare("
            SELECT pa.*, i.invoice_no, i.total_amount as invoice_total
            FROM payment_allocations pa
            JOIN invoices i ON i.id = pa.invoice_id
            WHERE pa.payment_id = ?
        ");
        $allocStmt->execute([$id]);
        $pay['allocations'] = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

        json_success(['payment' => $pay], 'Payment details loaded');
    }

    // 1B. LIST PAYMENTS
    $customerFilter = (int)($_GET['customer_id'] ?? 0);
    $search         = trim($_GET['q'] ?? '');
    $month          = (int)($_GET['month'] ?? 0);
    $year           = (int)($_GET['year'] ?? 0);
    $pagination     = get_pagination_params(20, 100);

    $where = ["1=1"];
    $params = [];

    if ($customerFilter > 0) {
        $where[] = "p.customer_id = ?";
        $params[] = $customerFilter;
    }
    if ($month > 0 && $year > 0) {
        $where[] = "MONTH(p.payment_date) = ? AND YEAR(p.payment_date) = ?";
        $params[] = $month;
        $params[] = $year;
    }
    if (!empty($search)) {
        $where[] = "(p.payment_no LIKE ? OR p.transaction_id LIKE ? OR c.name LIKE ?)";
        $term = "%" . sanitize_like_term($search) . "%";
        $params[] = $term; $params[] = $term; $params[] = $term;
    }

    $whereSql = implode(" AND ", $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p JOIN customers c ON c.id = p.customer_id WHERE $whereSql");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT p.*, c.name AS customer_name
        FROM payments p
        JOIN customers c ON c.id = p.customer_id
        WHERE $whereSql
        ORDER BY p.payment_date DESC, p.id DESC
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

    paginate_response($rows, $totalRecords, $pagination['page'], $pagination['limit'], 'Payments loaded', ['payments' => $rows]);
}

// ====================================================
// 2. POST /api/payments.php (RECORD NEW CLIENT PAYMENT)
// ====================================================
if ($method === 'POST') {
    $customerId     = (int)($input['customer_id'] ?? 0);
    $amount         = (float)($input['amount'] ?? 0);
    $paymentDate    = !empty($input['payment_date']) ? $input['payment_date'] : date('Y-m-d');
    $paymentMethod  = trim($input['payment_method'] ?? 'Bank Transfer');
    $transactionId  = trim($input['transaction_id'] ?? '');
    $reference      = trim($input['reference'] ?? '');
    $notes          = trim($input['notes'] ?? '');
    $allocations    = $input['allocations'] ?? [];

    if ($customerId <= 0 || $amount <= 0) {
        json_error('Customer and positive Payment Amount are required', 400, [
            'customer_id' => 'Customer required',
            'amount'      => 'Amount must be greater than 0'
        ]);
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

        $pRow = $pdo->query("SELECT payment_no FROM payments WHERE id = $payId")->fetch();
        $paymentNo = $pRow['payment_no'] ?? ('PAY-' . $payId);

        log_audit('Payment', 'Recorded Client Payment', $payId, ['amount' => $amount, 'payment_no' => $paymentNo]);

        try {
            require_once __DIR__ . '/../app/Realtime/RealtimeService.php';
            \App\Realtime\RealtimeService::getInstance($pdo)->emit(
                'crm.payment_received',
                'payment',
                $payId,
                'create',
                [
                    'id'          => $payId,
                    'customer_id' => $customerId,
                    'payment_no'  => $paymentNo,
                    'amount'      => $amount,
                    'date'        => $paymentDate
                ],
                ['admin', 'manager', 'finance manager', 'staff'],
                null,
                $auth['user_id']
            );
        } catch (\Throwable $e) {}

        json_success([
            'id'         => $payId,
            'payment_no' => $paymentNo,
            'amount'     => $amount
        ], 'Payment recorded successfully and client ledger credited', 201);
    } catch (\Throwable $e) {
        if ($e instanceof \PDOException) {
            handle_db_exception($e, 'payment');
        }
        api_log_error('Failed to record payment: ' . $e->getMessage());
        json_error('Failed to record payment. Please verify information and try again.', 500, [], ['code' => 'PAYMENT_RECORD_FAILED']);
    }
}

// ====================================================
// 3. PUT /api/payments.php?id={id} (UPDATE PAYMENT)
// ====================================================
if ($method === 'PUT') {
    require_role_api(['admin', 'super admin', 'manager', 'finance manager'], $auth);

    if ($id <= 0) {
        json_error('Valid Payment ID is required for update', 400);
    }

    $pStmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? LIMIT 1");
    $pStmt->execute([$id]);
    $existing = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        json_error('Payment not found', 404);
    }

    $amount        = isset($input['amount']) ? (float)$input['amount'] : (float)$existing['amount'];
    $paymentDate   = !empty($input['payment_date']) ? $input['payment_date'] : $existing['payment_date'];
    $paymentMethod = trim($input['payment_method'] ?? $existing['payment_method']);
    $transactionId = trim($input['transaction_id'] ?? $existing['transaction_id']);
    $reference     = trim($input['reference'] ?? $existing['reference']);
    $notes         = trim($input['notes'] ?? $existing['notes']);
    $status        = trim($input['status'] ?? ($existing['status'] ?? 'Completed'));

    if ($amount <= 0) {
        json_error('Payment amount must be greater than 0', 400);
    }

    try {
        update_client_payment($id, $amount, $paymentDate, $paymentMethod, $transactionId, $reference, $notes, $status);

        log_audit('Payment', 'Updated Client Payment', $id, ['amount' => $amount, 'payment_no' => $existing['payment_no']]);

        json_success([
            'id'         => $id,
            'payment_no' => $existing['payment_no'],
            'amount'     => $amount
        ], 'Payment updated successfully and financial metrics recalculated');
    } catch (\Throwable $e) {
        if ($e instanceof \PDOException) {
            handle_db_exception($e, 'payment');
        }
        api_log_error('Failed to update payment: ' . $e->getMessage());
        json_error('Failed to update payment. Please try again later.', 500, [], ['code' => 'PAYMENT_UPDATE_FAILED']);
    }
}

// ====================================================
// 4. DELETE /api/payments.php?id={id} (DELETE PAYMENT)
// ====================================================
if ($method === 'DELETE') {
    require_role_api(['admin', 'super admin'], $auth);

    if ($id <= 0) {
        json_error('Valid Payment ID is required for deletion', 400);
    }

    $pStmt = $pdo->prepare("SELECT id, payment_no FROM payments WHERE id = ? LIMIT 1");
    $pStmt->execute([$id]);
    $existing = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        json_error('Payment not found', 404);
    }

    try {
        delete_client_payment($id);

        log_audit('Payment', 'Deleted Client Payment', $id, ['payment_no' => $existing['payment_no']]);

        json_success([
            'id' => $id
        ], 'Payment deleted successfully. Invoice balances and ledger reconciled.');
    } catch (\Throwable $e) {
        if ($e instanceof \PDOException) {
            handle_db_exception($e, 'payment');
        }
        api_log_error('Failed to delete payment: ' . $e->getMessage());
        json_error('Failed to delete payment. Please try again later.', 500, [], ['code' => 'PAYMENT_DELETE_FAILED']);
    }
}

json_error('Method Not Allowed', 405);
