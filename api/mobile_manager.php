<?php
// Crm and backend/api/mobile_manager.php — Manager & Administrative Action Endpoints for Mobile
require_once __DIR__ . '/mobile_helper.php';
require_once __DIR__ . '/../includes/audit_helper.php';
require_once __DIR__ . '/../includes/vault_helper.php';

$auth = authenticate_mobile();
require_role_api(['admin', 'super admin', 'manager', 'hr manager'], $auth);

$action = $_GET['action'] ?? ($_POST['action'] ?? 'pending_leaves');
$input = get_json_input();

// ----------------------------------------------------
// 1. PENDING LEAVE REQUESTS
// ----------------------------------------------------
if ($action === 'pending_leaves') {
    $stmt = $pdo->query("
        SELECT lr.*, lt.name as leave_type_name, e.full_name as employee_name, e.employee_code, e.department
        FROM leave_requests lr
        LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
        LEFT JOIN employees e ON e.id = lr.employee_id
        WHERE lr.status = 'pending'
        ORDER BY lr.created_at ASC
    ");
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_success(['pending_leaves' => $requests], 'Pending leaves loaded');
}

// ----------------------------------------------------
// 2. REVIEW LEAVE (APPROVE / REJECT)
// ----------------------------------------------------
if ($action === 'review_leave') {
    $requestId = (int)($input['request_id'] ?? 0);
    $status    = strtolower(trim($input['status'] ?? '')); // 'approved' or 'rejected'
    $comment   = trim($input['review_comment'] ?? '');

    if ($requestId <= 0 || !in_array($status, ['approved', 'rejected'])) {
        json_error('Valid Request ID and status (approved/rejected) are required', 400);
    }

    $rqStmt = $pdo->prepare("SELECT * FROM leave_requests WHERE id = ?");
    $rqStmt->execute([$requestId]);
    $req = $rqStmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        json_error('Leave request not found', 404);
    }

    $upd = $pdo->prepare("UPDATE leave_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_comment = ? WHERE id = ?");
    $upd->execute([$status, $auth['user_id'], $comment, $requestId]);

    // If approved, synchronize attendance table for date range (Excluding Sundays)
    if ($status === 'approved') {
        try {
            $start = new DateTime($req['start_date']);
            $end   = new DateTime($req['end_date']);
            $interval = new DateInterval('P1D');
            $period   = new DatePeriod($start, $interval, (clone $end)->modify('+1 day'));

            foreach ($period as $dt) {
                if ((int)$dt->format('w') === 0) continue; // Skip Sunday
                $dStr = $dt->format('Y-m-d');

                $attSql = "INSERT INTO attendance 
                             (employee_id, attendance_date, status, leave_type, notes, marked_by)
                           VALUES (?, ?, 'on_leave', 'Approved Leave', ?, ?)
                           ON DUPLICATE KEY UPDATE 
                             status = 'on_leave', leave_type = 'Approved Leave', notes = VALUES(notes)";
                $pdo->prepare($attSql)->execute([$req['employee_id'], $dStr, "Approved Leave: " . $req['reason'], $auth['user_id']]);
            }
        } catch (\Throwable $e) {}
    }

    log_audit('leave', $status, $requestId, [
        'employee_id' => $req['employee_id'],
        'days'        => $req['total_days'],
        'comment'     => $comment
    ]);

    json_success(['status' => $status], 'Leave request has been ' . ucfirst($status) . ' successfully');
}

// ----------------------------------------------------
// 3. TEAM WORK REPORTS
// ----------------------------------------------------
if ($action === 'team_reports') {
    $targetDate = trim($_GET['date'] ?? date('Y-m-d'));
    $stmt = $pdo->prepare("
        SELECT wr.*, e.full_name as employee_name, e.employee_code, e.department
        FROM work_reports wr
        JOIN employees e ON e.id = wr.employee_id
        WHERE wr.report_date = ?
        ORDER BY wr.submitted_at DESC
    ");
    $stmt->execute([$targetDate]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_success(['date' => $targetDate, 'reports' => $reports], 'Team work reports loaded');
}

// ----------------------------------------------------
// 4. REVIEW WORK REPORT
// ----------------------------------------------------
if ($action === 'review_work_report') {
    $reportId   = (int)($input['report_id'] ?? 0);
    $rating     = min(5, max(1, (int)($input['rating'] ?? 5)));
    $reviewNote = trim($input['review_note'] ?? '');

    if ($reportId <= 0) {
        json_error('Report ID is required', 400);
    }

    // Update work_reports table
    $upd1 = $pdo->prepare("UPDATE work_reports SET status = 'approved', review_comment = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
    $upd1->execute([$reviewNote, $auth['user_id'], $reportId]);

    // Also update daily_work_reports if matching record exists
    try {
        $upd2 = $pdo->prepare("UPDATE daily_work_reports SET rating = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $upd2->execute([$rating, $reviewNote, $auth['user_id'], $reportId]);
    } catch (\Throwable $e) {}

    json_success([], 'Work report reviewed successfully');
}

// ----------------------------------------------------
// 5. TODAY'S LIVE TEAM ATTENDANCE STATUS
// ----------------------------------------------------
if ($action === 'team_today_attendance') {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT e.id as employee_id, e.employee_code, e.full_name, e.department, e.designation,
               a.status as punch_status, a.check_in, a.check_out, a.late_minutes, a.total_hours
        FROM employees e
        LEFT JOIN attendance a ON a.employee_id = e.id AND a.attendance_date = ?
        WHERE e.status = 'active'
        ORDER BY e.full_name ASC
    ");
    $stmt->execute([$today]);
    $teamAttendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $presentCount = 0;
    $lateCount = 0;
    $absentCount = 0;
    $onLeaveCount = 0;

    foreach ($teamAttendance as &$t) {
        $st = strtolower($t['punch_status'] ?? 'absent');
        if ($st === 'present') $presentCount++;
        elseif ($st === 'late') { $lateCount++; $presentCount++; }
        elseif ($st === 'on_leave') $onLeaveCount++;
        else $absentCount++;
    }

    json_success([
        'date'            => $today,
        'present_count'   => $presentCount,
        'late_count'      => $lateCount,
        'absent_count'    => $absentCount,
        'on_leave_count'  => $onLeaveCount,
        'team_attendance' => $teamAttendance
    ], 'Team live attendance loaded');
}

// ----------------------------------------------------
// 6. VAULT REVEAL (DECRYPT CLIENT PASSWORD)
// ----------------------------------------------------
if ($action === 'vault_reveal') {
    require_role_api(['admin', 'super admin'], $auth);

    $credId = (int)($input['credential_id'] ?? 0);
    if ($credId <= 0) json_error('Credential ID is required', 400);

    $stmt = $pdo->prepare("SELECT c.*, cust.name as client_name FROM credentials c JOIN customers cust ON cust.id = c.customer_id WHERE c.id = ?");
    $stmt->execute([$credId]);
    $cred = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cred) json_error('Credential not found', 404);

    $decrypted = decrypt_credential($cred['encrypted_password']);

    log_audit('Credential Vault', 'Revealed Password via API', $credId, [
        'client'   => $cred['client_name'],
        'platform' => $cred['platform'],
        'account'  => $cred['account_name']
    ]);

    json_success([
        'password'   => $decrypted,
        'username'   => $cred['username_email'],
        'login_url'  => $cred['login_url'],
        'two_factor' => $cred['two_factor_info']
    ], 'Credential revealed securely');
}

json_error('Unknown manager action', 400);
