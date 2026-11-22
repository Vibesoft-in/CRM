<?php
// Crm and backend/api/mobile_holidays.php
require_once __DIR__ . '/mobile_helper.php';

$auth = authenticate_mobile();
$action = $_GET['action'] ?? ($_POST['action'] ?? ($input['action'] ?? 'list'));
$input = get_json_input();
$year = (int)($input['year'] ?? ($_GET['year'] ?? date('Y')));
$userRole = strtolower(trim($auth['user']['role'] ?? ''));
$isAdmin = in_array($userRole, ['admin', 'super admin', 'director', 'manager']);

// ----------------------------------------------------
// ADMIN ACTIONS: ADD / EDIT / DELETE HOLIDAY
// ----------------------------------------------------
if ($action === 'add') {
    if (!$isAdmin) {
        json_error('Admin or manager permission required to add holidays', 403);
    }
    $hDate = trim($input['date'] ?? '');
    $hName = trim($input['name'] ?? ($input['title'] ?? ''));
    $hType = trim($input['type'] ?? 'festival');
    $hDesc = trim($input['description'] ?? '');

    if (empty($hDate) || empty($hName)) {
        json_error('Holiday date and name are required', 400);
    }

    $stmt = $pdo->prepare("INSERT INTO holidays (name, holiday_type, holiday_date, description, status, created_at) VALUES (?, ?, ?, ?, 'active', NOW())");
    $stmt->execute([$hName, $hType, $hDate, $hDesc]);
    $newId = $pdo->lastInsertId();

    try {
        $stmtSync = $pdo->prepare("INSERT INTO attendance_holidays (holiday_date, holiday_name, holiday_type, created_at) VALUES (?, ?, ?, NOW())");
        $stmtSync->execute([$hDate, $hName, $hType]);
    } catch (\Throwable $e) {}

    json_success(['id' => (int)$newId], 'Holiday created successfully');
}

if ($action === 'edit' || $action === 'update') {
    if (!$isAdmin) {
        json_error('Admin or manager permission required to edit holidays', 403);
    }
    $hId = (int)($input['id'] ?? ($_GET['id'] ?? 0));
    $hDate = trim($input['date'] ?? '');
    $hName = trim($input['name'] ?? ($input['title'] ?? ''));
    $hType = trim($input['type'] ?? 'festival');
    $hDesc = trim($input['description'] ?? '');

    if ($hId <= 0 || empty($hDate) || empty($hName)) {
        json_error('Holiday ID, date, and name are required', 400);
    }

    $existing = $pdo->prepare("SELECT holiday_date FROM holidays WHERE id = ?");
    $existing->execute([$hId]);
    $oldDate = $existing->fetchColumn();

    $stmt = $pdo->prepare("UPDATE holidays SET name = ?, holiday_type = ?, holiday_date = ?, description = ? WHERE id = ?");
    $stmt->execute([$hName, $hType, $hDate, $hDesc, $hId]);

    try {
        if ($oldDate) {
            $pdo->prepare("DELETE FROM attendance_holidays WHERE holiday_date = ?")->execute([$oldDate]);
        }
        $stmtSync = $pdo->prepare("INSERT INTO attendance_holidays (holiday_date, holiday_name, holiday_type, created_at) VALUES (?, ?, ?, NOW())");
        $stmtSync->execute([$hDate, $hName, $hType]);
    } catch (\Throwable $e) {}

    json_success(['id' => $hId], 'Holiday updated successfully');
}

if ($action === 'delete') {
    if (!$isAdmin) {
        json_error('Admin or manager permission required to delete holidays', 403);
    }
    $hId = (int)($input['id'] ?? ($_GET['id'] ?? 0));
    if ($hId <= 0) {
        json_error('Holiday ID is required', 400);
    }

    $hRow = $pdo->query("SELECT holiday_date FROM holidays WHERE id = " . (int)$hId)->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("DELETE FROM holidays WHERE id = ?")->execute([$hId]);
    if ($hRow) {
        $pdo->prepare("DELETE FROM attendance_holidays WHERE holiday_date = ?")->execute([$hRow['holiday_date']]);
    }

    json_success(['deleted_id' => $hId], 'Holiday deleted successfully');
}

// ----------------------------------------------------
// ACTION: LIST HOLIDAYS
// ----------------------------------------------------
$holidaysMap = get_active_holidays_map($pdo, (string)$year);
$holidays = [];
foreach ($holidaysMap as $dt => $item) {
    $holidays[] = [
        'id'          => (int)($item['id'] ?? 0),
        'date'        => $dt,
        'title'       => $item['name'] ?? $item['holiday_name'],
        'type'        => $item['holiday_type'] ?? 'festival',
        'description' => $item['description'] ?? '',
        'day_name'    => date('l', strtotime($dt)),
        'is_sunday'   => (date('w', strtotime($dt)) == 0)
    ];
}

json_success([
    'year'      => $year,
    'holidays'  => $holidays,
    'total'     => count($holidays),
    'is_admin'  => $isAdmin
], 'Holidays loaded');
