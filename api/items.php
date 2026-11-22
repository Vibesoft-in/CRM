<?php
// Crm and backend/api/items.php — RESTful API for Catalog Items and Services
require_once __DIR__ . '/mobile_helper.php';
require_once __DIR__ . '/../includes/audit_helper.php';

$auth = authenticate_mobile();
require_role_api(['admin', 'super admin', 'manager', 'sales manager', 'finance manager', 'staff'], $auth);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = get_json_input();
$id = (int)($_GET['id'] ?? ($input['id'] ?? 0));

// ====================================================
// 1. GET /api/items.php (LIST or DETAIL)
// ====================================================
if ($method === 'GET') {
    // 1A. ITEM DETAIL
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            json_error('Item not found', 404, ['code' => 'ITEM_NOT_FOUND']);
        }

        json_success(['item' => $item], 'Item details loaded');
    }

    // 1B. LIST ALL ITEMS (WITH SEARCH, SORT, PAGINATION)
    $search     = trim($_GET['q'] ?? '');
    $sortCol    = trim($_GET['sort'] ?? 'name');
    $sortOrder  = strtolower(trim($_GET['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $pagination = get_pagination_params(50, 200);

    $allowedSort = ['id', 'name', 'hsn_sac', 'default_price', 'default_tax_rate', 'created_at'];
    if (!in_array($sortCol, $allowedSort)) {
        $sortCol = 'name';
    }

    $where = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $where[] = "(name LIKE ? OR hsn_sac LIKE ?)";
        $term = "%" . sanitize_like_term($search) . "%";
        $params[] = $term;
        $params[] = $term;
    }

    $whereSql = implode(" AND ", $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE $whereSql");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, name, hsn_sac, default_price, default_tax_rate, created_at 
        FROM items 
        WHERE $whereSql 
        ORDER BY {$sortCol} {$sortOrder} 
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

    paginate_response($rows, $totalRecords, $pagination['page'], $pagination['limit'], 'Items loaded', ['items' => $rows]);
}

// ====================================================
// 2. POST /api/items.php (CREATE ITEM)
// ====================================================
if ($method === 'POST') {
    require_role_api(['admin', 'super admin', 'manager', 'sales manager', 'finance manager'], $auth);

    validate_or_fail($input, [
        'name' => 'required|min:2|max:150'
    ]);

    $name    = trim($input['name'] ?? '');
    $hsn     = trim($input['hsn_sac'] ?? ($input['hsn'] ?? ''));
    $price   = (float)($input['default_price'] ?? ($input['price'] ?? 0));
    $taxRate = (float)($input['default_tax_rate'] ?? ($input['tax'] ?? 18));

    try {
        $stmt = $pdo->prepare("
            INSERT INTO items (name, hsn_sac, default_price, default_tax_rate, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$name, $hsn, $price, $taxRate]);
        $newId = (int)$pdo->lastInsertId();
    } catch (\PDOException $e) {
        handle_db_exception($e, 'item');
    }

    log_audit('Item', 'Created Catalog Item', $newId, ['name' => $name, 'price' => $price]);

    json_success([
        'id'               => $newId,
        'name'             => $name,
        'hsn_sac'          => $hsn,
        'default_price'    => $price,
        'default_tax_rate' => $taxRate
    ], 'Item created successfully', 201);
}

// ====================================================
// 3. PUT /api/items.php?id={id} (UPDATE ITEM)
// ====================================================
if ($method === 'PUT') {
    require_role_api(['admin', 'super admin', 'manager', 'sales manager', 'finance manager'], $auth);

    if ($id <= 0) {
        json_error('Valid Item ID is required for update', 400);
    }

    $iStmt = $pdo->prepare("SELECT * FROM items WHERE id = ? LIMIT 1");
    $iStmt->execute([$id]);
    $existing = $iStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        json_error('Item not found', 404);
    }

    $name    = trim($input['name'] ?? $existing['name']);
    $hsn     = trim($input['hsn_sac'] ?? ($input['hsn'] ?? $existing['hsn_sac']));
    $price   = isset($input['default_price']) ? (float)$input['default_price'] : (isset($input['price']) ? (float)$input['price'] : (float)$existing['default_price']);
    $taxRate = isset($input['default_tax_rate']) ? (float)$input['default_tax_rate'] : (isset($input['tax']) ? (float)$input['tax'] : (float)$existing['default_tax_rate']);

    if (empty($name)) {
        json_error('Item name cannot be empty', 400);
    }

    $upd = $pdo->prepare("
        UPDATE items 
        SET name = ?, hsn_sac = ?, default_price = ?, default_tax_rate = ? 
        WHERE id = ?
    ");
    $upd->execute([$name, $hsn, $price, $taxRate, $id]);

    log_audit('Item', 'Updated Catalog Item', $id, ['name' => $name]);

    json_success([
        'id'               => $id,
        'name'             => $name,
        'hsn_sac'          => $hsn,
        'default_price'    => $price,
        'default_tax_rate' => $taxRate
    ], 'Item updated successfully');
}

// ====================================================
// 4. DELETE /api/items.php?id={id} (DELETE ITEM)
// ====================================================
if ($method === 'DELETE') {
    require_role_api(['admin', 'super admin'], $auth);

    if ($id <= 0) {
        json_error('Valid Item ID is required for deletion', 400);
    }

    $iStmt = $pdo->prepare("SELECT id, name FROM items WHERE id = ? LIMIT 1");
    $iStmt->execute([$id]);
    $existing = $iStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        json_error('Item not found', 404);
    }

    $pdo->prepare("DELETE FROM items WHERE id = ?")->execute([$id]);

    log_audit('Item', 'Deleted Catalog Item', $id, ['name' => $existing['name']]);

    json_success(['id' => $id], 'Item deleted successfully');
}

json_error('Method Not Allowed', 405);
