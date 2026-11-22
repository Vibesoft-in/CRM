<?php
// Crm and backend/api/expenses.php — RESTful API for Expense Management
require_once __DIR__ . '/mobile_helper.php';
require_once __DIR__ . '/../includes/audit_helper.php';

$auth = authenticate_mobile();
require_role_api(['admin', 'super admin', 'manager', 'finance manager'], $auth);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = get_json_input();
$id = (int)($_GET['id'] ?? ($input['id'] ?? 0));

// ====================================================
// 1. GET /api/expenses.php (LIST, DETAIL & SUMMARIES)
// ====================================================
if ($method === 'GET') {
    // 1A. EXPENSE DETAIL
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $exp = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$exp) json_error('Expense not found', 404);

        json_success(['expense' => $exp], 'Expense detail loaded');
    }

    // 1B. LIST EXPENSES WITH FILTERS & SUMMARY
    $month      = (int)($_GET['month'] ?? 0);
    $year       = (int)($_GET['year'] ?? 0);
    $from       = trim($_GET['from'] ?? '');
    $to         = trim($_GET['to'] ?? '');
    $category   = trim($_GET['category'] ?? '');
    $search     = trim($_GET['q'] ?? '');
    $pagination = get_pagination_params(20, 100);

    $where = ["1=1"];
    $params = [];

    if ($month > 0 && $year > 0) {
        $where[] = "MONTH(expense_date) = ? AND YEAR(expense_date) = ?";
        $params[] = $month;
        $params[] = $year;
    }
    if (!empty($from) && !empty($to)) {
        $where[] = "expense_date BETWEEN ? AND ?";
        $params[] = $from;
        $params[] = $to;
    }
    if (!empty($category)) {
        $where[] = "category = ?";
        $params[] = $category;
    }
    if (!empty($search)) {
        $where[] = "(description LIKE ? OR vendor LIKE ? OR category LIKE ?)";
        $term = "%" . sanitize_like_term($search) . "%";
        $params[] = $term; $params[] = $term; $params[] = $term;
    }

    $whereSql = implode(" AND ", $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE $whereSql");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT * FROM expenses 
        WHERE $whereSql 
        ORDER BY expense_date DESC, id DESC 
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

    // Calculate Summary Stats
    $todayTotal = (float)($pdo->query("SELECT SUM(amount) FROM expenses WHERE DATE(expense_date) = CURDATE()")->fetchColumn() ?: 0);
    $monthTotal = (float)($pdo->query("SELECT SUM(amount) FROM expenses WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())")->fetchColumn() ?: 0);
    $yearTotal  = (float)($pdo->query("SELECT SUM(amount) FROM expenses WHERE YEAR(expense_date) = YEAR(CURDATE())")->fetchColumn() ?: 0);

    paginate_response($rows, $totalRecords, $pagination['page'], $pagination['limit'], 'Expenses loaded', [
        'expenses'  => $rows,
        'summaries' => [
            'today' => $todayTotal,
            'month' => $monthTotal,
            'year'  => $yearTotal
        ]
    ]);
}

// ====================================================
// 2. POST /api/expenses.php (RECORD EXPENSE)
// ====================================================
if ($method === 'POST') {
    validate_or_fail($input, [
        'category' => 'required',
        'amount'   => 'required|numeric|min:0.01'
    ]);

    $date     = !empty($input['expense_date']) ? $input['expense_date'] : date('Y-m-d');
    $cat      = trim($input['category'] ?? '');
    $desc     = trim($input['description'] ?? '');
    $vendor   = trim($input['vendor'] ?? '');
    $amount   = (float)($input['amount'] ?? 0);
    $taxAmt   = (float)($input['tax_amount'] ?? 0);
    $mode     = trim($input['payment_mode'] ?? 'Cash');

    try {
        $stmt = $pdo->prepare("
            INSERT INTO expenses (expense_date, category, description, vendor, amount, tax_amount, payment_mode, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Paid', ?, NOW())
        ");
        $stmt->execute([$date, $cat, $desc, $vendor, $amount, $taxAmt, $mode, $auth['user_id']]);
        $newId = (int)$pdo->lastInsertId();
    } catch (\PDOException $e) {
        handle_db_exception($e, 'expense');
    }

    log_audit('Expense', 'Recorded Expense', $newId, ['category' => $cat, 'amount' => $amount]);

    json_success(['id' => $newId, 'amount' => $amount], 'Expense recorded successfully', 201);
}

// ====================================================
// 3. PUT /api/expenses.php?id={id} (UPDATE EXPENSE)
// ====================================================
if ($method === 'PUT') {
    if ($id <= 0) json_error('Valid Expense ID is required', 400);

    $eStmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? LIMIT 1");
    $eStmt->execute([$id]);
    $existing = $eStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) json_error('Expense not found', 404);

    $date   = $input['expense_date'] ?? $existing['expense_date'];
    $cat    = trim($input['category'] ?? $existing['category']);
    $desc   = trim($input['description'] ?? $existing['description']);
    $vendor = trim($input['vendor'] ?? $existing['vendor']);
    $amount = isset($input['amount']) ? (float)$input['amount'] : (float)$existing['amount'];
    $taxAmt = isset($input['tax_amount']) ? (float)$input['tax_amount'] : (float)$existing['tax_amount'];
    $mode   = trim($input['payment_mode'] ?? $existing['payment_mode']);

    $upd = $pdo->prepare("UPDATE expenses SET expense_date = ?, category = ?, description = ?, vendor = ?, amount = ?, tax_amount = ?, payment_mode = ? WHERE id = ?");
    $upd->execute([$date, $cat, $desc, $vendor, $amount, $taxAmt, $mode, $id]);

    log_audit('Expense', 'Updated Expense', $id, ['amount' => $amount]);

    json_success(['id' => $id], 'Expense updated successfully');
}

// ====================================================
// 4. DELETE /api/expenses.php?id={id} (DELETE EXPENSE)
// ====================================================
if ($method === 'DELETE') {
    require_role_api(['admin', 'super admin'], $auth);
    if ($id <= 0) json_error('Valid Expense ID is required', 400);

    $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);
    log_audit('Expense', 'Deleted Expense', $id);

    json_success(['id' => $id], 'Expense deleted successfully');
}

json_error('Method Not Allowed', 405);
