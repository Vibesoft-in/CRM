<?php
// modules/team/password_logs_api.php — Returns password change logs and history for employees
require_once __DIR__ . '/../../includes/functions.php';
require_login();

header('Content-Type: application/json');

if (!is_admin() && !is_manager()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

$employeeId = (int)($_GET['employee_id'] ?? $_POST['employee_id'] ?? 0);
if ($employeeId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid employee ID']);
    exit;
}

$logs = get_employee_password_logs($pdo, $employeeId, 50);

$formattedLogs = array_map(function($row) {
    return [
        'id'              => $row['id'],
        'password_plain'  => $row['password_plain'],
        'changed_by_name' => $row['changed_by_name'] ?: 'Admin',
        'device_name'     => $row['device_name'] ?: 'Unknown Device',
        'ip_address'      => $row['ip_address'] ?: '—',
        'change_type'     => $row['change_type'],
        'notes'           => $row['notes'] ?: '—',
        'date_formatted'  => date('d M Y, h:i A', strtotime($row['created_at']))
    ];
}, $logs);

echo json_encode([
    'success' => true,
    'count'   => count($formattedLogs),
    'logs'    => $formattedLogs
]);
