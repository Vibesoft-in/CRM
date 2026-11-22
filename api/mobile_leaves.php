<?php
// Crm and backend/api/mobile_leaves.php
require_once __DIR__ . '/mobile_helper.php';

$auth = authenticate_mobile();
$empId = $auth['employee_id'];
if (!$empId) {
    json_error('No linked employee profile found for this account', 400);
}

// Strict IDOR Check: Ensure employee only queries their own leaves unless Manager/Admin
$targetEmpId = (int)($_GET['employee_id'] ?? ($_POST['employee_id'] ?? $empId));
if ($targetEmpId !== $empId) {
    verify_employee_access($targetEmpId, $auth, 'leave records');
    $empId = $targetEmpId;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'overview');
$input = get_json_input();

// ----------------------------------------------------
// ACTION: CANCEL LEAVE REQUEST
// ----------------------------------------------------
if ($action === 'cancel') {
    $reqId = (int)($input['request_id'] ?? ($_GET['request_id'] ?? 0));
    if ($reqId <= 0) json_error('Valid leave request ID is required', 400);

    $req = verify_record_ownership('leave_requests', $reqId, 'employee_id', $auth, 'leave request');

    if (strtolower($req['status']) !== 'pending') {
        json_error('Only pending leave requests can be cancelled', 400);
    }

    $pdo->prepare("UPDATE leave_requests SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$reqId]);
    json_success(['id' => $reqId], 'Leave request cancelled successfully');
}

// ----------------------------------------------------
// ACTION: APPLY FOR LEAVE
// ----------------------------------------------------
if ($action === 'apply') {
    $leaveTypeId = (int)($input['leave_type_id'] ?? 1);
    $startDate = trim($input['start_date'] ?? '');
    $endDate = trim($input['end_date'] ?? '');
    $reason = trim($input['reason'] ?? '');

    // Normalize date format if mm/dd/yyyy
    $formatDate = function($d) {
        if (strpos($d, '/') !== false) {
            $p = explode('/', $d);
            if (count($p) === 3) return sprintf('%04d-%02d-%02d', (int)$p[2], (int)$p[0], (int)$p[1]);
        }
        return $d;
    };

    $startDate = $formatDate($startDate);
    $endDate = $formatDate($endDate);

    if (empty($startDate) || empty($endDate)) {
        json_error('Start date and End date are required', 400);
    }

    $startTs = strtotime($startDate);
    $endTs = strtotime($endDate);
    if ($endTs < $startTs) {
        json_error('End date cannot be before start date', 400);
    }

    $totalDays = max(1, (int)round(($endTs - $startTs) / 86400) + 1);

    $iStmt = $pdo->prepare("
        INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, total_days, reason, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
    ");
    $iStmt->execute([$empId, $leaveTypeId, $startDate, $endDate, $totalDays, $reason]);
    $reqId = $pdo->lastInsertId();

    try {
        require_once __DIR__ . '/../app/Realtime/RealtimeService.php';
        \App\Realtime\RealtimeService::getInstance($pdo)->emit(
            'leave.applied',
            'leave',
            (int)$reqId,
            'create',
            [
                'id'          => (int)$reqId,
                'employee_id' => (int)$empId,
                'status'      => 'pending',
                'start_date'  => $startDate,
                'end_date'    => $endDate,
                'total_days'  => $totalDays
            ],
            ['admin', 'manager', 'hr manager'],
            null,
            $auth['user_id']
        );
    } catch (\Throwable $e) {}

    create_action_notification(
        $pdo,
        (int)$auth['user_id'],
        'Leave Application Submitted',
        "Your {$totalDays}-day leave request from {$startDate} to {$endDate} was submitted successfully.",
        '/employee/leave.php',
        'leave_applied'
    );

    json_success([
        'request_id' => (int)$reqId
    ], 'Leave application submitted successfully');
}

// ----------------------------------------------------
// ACTION: OVERVIEW & BALANCES
// ----------------------------------------------------
$currentYear = (int)date('Y');
$ltRows = $pdo->query("SELECT * FROM leave_types WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

$leaveTypes = [];
$balances = [];
$colors = ['#0284C7', '#F59E0B', '#334155', '#10B981', '#8B5CF6'];
$colorIdx = 0;

foreach ($ltRows as $lt) {
    $quota = (int)$lt['days_per_year'];
    $uStmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_days), 0) 
        FROM leave_requests 
        WHERE employee_id = ? AND leave_type_id = ? AND status = 'approved' AND YEAR(start_date) = ?
    ");
    $uStmt->execute([$empId, $lt['id'], $currentYear]);
    $used = (int)$uStmt->fetchColumn();
    $available = max(0, $quota - $used);

    $leaveTypes[] = [
        'id'            => (int)$lt['id'],
        'name'          => $lt['name'],
        'days_per_year' => $quota,
        'used'          => $used,
        'available'     => $available
    ];

    $balances[] = [
        'name'    => $lt['name'],
        'current' => $available,
        'total'   => $quota,
        'color'   => $colors[$colorIdx % count($colors)]
    ];
    $colorIdx++;
}

// Calculate overall annual leave summary (4 allowed per year by company policy)
$annualSummary = get_employee_annual_leave_summary($pdo, $empId, $currentYear);

// Fetch employee's real leave requests
$rStmt = $pdo->prepare("
    SELECT lr.*, lt.name as leave_type_name, u.name as reviewer_name 
    FROM leave_requests lr
    LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
    LEFT JOIN users u ON lr.reviewed_by = u.id
    WHERE lr.employee_id = ? 
    ORDER BY lr.created_at DESC 
    LIMIT 30
");
$rStmt->execute([$empId]);
$rows = $rStmt->fetchAll(PDO::FETCH_ASSOC);

$requests = [];
foreach ($rows as $r) {
    $st = strtolower($r['status'] ?? 'pending');
    $startTs = strtotime($r['start_date']);
    $endTs = strtotime($r['end_date']);
    $dateRange = date('d M Y', $startTs) . ' - ' . date('d M Y', $endTs);

    $requests[] = [
        'id'             => (int)$r['id'],
        'leave_type'     => $r['leave_type_name'] ?? 'Leave',
        'date_range'     => $dateRange,
        'start_date'     => $r['start_date'],
        'end_date'       => $r['end_date'],
        'total_days'     => (int)$r['total_days'],
        'status'         => ucfirst($st),
        'reason'         => $r['reason'] ?? '',
        'applied_at'     => !empty($r['created_at']) ? date('d M Y, h:i A', strtotime($r['created_at'])) : null,
        'reviewer_name'  => $r['reviewer_name'] ?? null,
        'reviewed_at'    => !empty($r['reviewed_at']) ? date('d M Y, h:i A', strtotime($r['reviewed_at'])) : null,
        'review_comment' => $r['review_comment'] ?? null
    ];
}

json_success([
    'annual_summary'  => $annualSummary,
    'leave_types'     => $leaveTypes,
    'balances'        => $balances,
    'recent_requests' => $requests
], 'Leaves data loaded');
