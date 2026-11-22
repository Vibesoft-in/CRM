<?php
// modules/team/attendance_api.php — AJAX handler for attendance operations
ob_start();
require_once __DIR__ . '/../../includes/functions.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function json_exit(array $data, int $code = 200): void {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ─────────────────────────────────────────────
// GET SETTINGS
// ─────────────────────────────────────────────
function getSettings($pdo) {
    $s = $pdo->query("SELECT * FROM attendance_settings LIMIT 1")->fetch();
    if (!$s) {
        // defaults
        $s = ['expected_check_in' => '10:00:00', 'expected_check_out' => '19:00:00',
              'grace_minutes' => 15, 'late_deduction_per_hour' => 50,
              'half_day_threshold_hours' => 4, 'working_days_per_month' => 26,
              'overtime_after_hours' => 9];
    }
    return $s;
}

// ─────────────────────────────────────────────
// CALCULATE LATE MINUTES
// ─────────────────────────────────────────────
function calcLateMinutes($checkIn, $expectedIn, $graceMins) {
    if (!$checkIn) return 0;
    $expected = strtotime('2000-01-01 ' . substr($expectedIn, 0, 8)) + ($graceMins * 60);
    $actual   = strtotime('2000-01-01 ' . substr($checkIn, 0, 8));
    $diff = ($actual - $expected) / 60; // in minutes
    return max(0, (int)$diff);
}

// ─────────────────────────────────────────────
// CALCULATE TOTAL HOURS
// ─────────────────────────────────────────────
function calcTotalHours($checkIn, $checkOut) {
    if (!$checkIn || !$checkOut) return 0;
    $inTs  = strtotime('2000-01-01 ' . substr($checkIn, 0, 8));
    $outTs = strtotime('2000-01-01 ' . substr($checkOut, 0, 8));
    if ($outTs <= $inTs) return 0;
    $diff  = $outTs - $inTs;
    return max(0, round($diff / 3600, 2));
}

// ─────────────────────────────────────────────
// CALCULATE HAVERSINE DISTANCE IN METERS
// ─────────────────────────────────────────────
function calcDistanceMeters($lat1, $lon1, $lat2, $lon2) {
    if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) return 0;
    $earthRadius = 6371000; // meters
    $dLat = deg2rad((float)$lat2 - (float)$lat1);
    $dLon = deg2rad((float)$lon2 - (float)$lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad((float)$lat1)) * cos(deg2rad((float)$lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return (int)round($earthRadius * $c);
}

// ─────────────────────────────────────────────
// ACTION: auto_process_attendance
// ─────────────────────────────────────────────
if ($action === 'auto_process_attendance') {
    $targetDate = $_POST['date'] ?? $_GET['date'] ?? date('Y-m-d');
    $res = auto_process_daily_attendance($pdo, $targetDate);
    echo json_encode([
        'success' => true,
        'data' => $res,
        'msg' => "Daily attendance rules processed for {$targetDate} ({$res['absent_marked']} absent marked, {$res['half_day_marked']} unclosed records set to half day)."
    ]);
    exit;
}

// ─────────────────────────────────────────────
// ACTION: mark_attendance
// ─────────────────────────────────────────────
if ($action === 'mark_attendance') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        echo json_encode(['success' => false, 'msg' => 'Invalid CSRF token']);
        exit;
    }

    $employeeId = intval($_POST['employee_id'] ?? 0);
    $date       = $_POST['date'] ?? date('Y-m-d');
    $status     = $_POST['status'] ?? 'present';
    $checkIn    = !empty($_POST['check_in']) ? $_POST['check_in'] : null;
    $checkOut   = !empty($_POST['check_out']) ? $_POST['check_out'] : null;
    $leaveType  = trim($_POST['leave_type'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');

    if (!$employeeId) {
        echo json_encode(['success' => false, 'msg' => 'Invalid employee']);
        exit;
    }

    // STRICT IDOR DEFENSE: Employees can ONLY punch attendance for their own account!
    $currentEmpId = (int)($_SESSION['employee_id'] ?? 0);
    if (!is_admin()) {
        if ($currentEmpId <= 0 || $currentEmpId !== $employeeId) {
            \App\Security\AuditLogger::securityEvent('SECURITY_IDOR_ATTENDANCE_PUNCH_ATTEMPT', [
                'target_employee_id'  => $employeeId,
                'current_employee_id' => $currentEmpId,
                'user_id'             => $_SESSION['user_id'] ?? null
            ]);
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'code'    => 'FORBIDDEN',
                'msg'     => 'Access Denied: You can only punch attendance for your own verified account.'
            ]);
            exit;
        }
    }

    $settings = getSettings($pdo);

    // Geofencing Check for Self Attendance Punch
    $userLat = (isset($_POST['latitude']) && $_POST['latitude'] !== '') ? (float)$_POST['latitude'] : null;
    $userLng = (isset($_POST['longitude']) && $_POST['longitude'] !== '') ? (float)$_POST['longitude'] : null;
    $isSelfPunch = (isset($_POST['is_self']) && $_POST['is_self'] == '1') || !is_admin();

    // Check-in and Punch-out cutoffs for Employee self-punch
    if ($isSelfPunch && $date === date('Y-m-d')) {
        // Enforce Holiday / Non-working Day Check: Cannot punch in if today is a Holiday/Sunday unless admin scheduled it as a working day
        if (!$checkOut) {
            $dayCheck = is_date_holiday_or_sunday($date);
            if (!empty($dayCheck['is_non_working'])) {
                $hName = $dayCheck['holiday_name'] ?: ($dayCheck['reason'] ?: 'Holiday / Weekly Off');
                echo json_encode([
                    'success' => false,
                    'code'    => 'HOLIDAY_BLOCKED',
                    'msg'     => "Today is an official holiday / weekly off ({$hName}). Attendance cannot be marked for today."
                ]);
                exit;
            }
        }

        $nowH = (int)date('G');
        $nowM = (int)date('i');
        $nowMin = ($nowH * 60) + $nowM;

        // If punch-in after 5:00 PM (17:00 = 1020 mins)
        if (!$checkOut && $nowMin >= 1020) {
            echo json_encode([
                'success' => false,
                'code'    => 'CHECKIN_CLOSED',
                'msg'     => 'Check-in time closed at 5:00 PM. You have been marked absent for today. Please contact Admin for manual entry.'
            ]);
            exit;
        }

        // If punch-out after 8:00 PM (20:00 = 1200 mins)
        if ($checkOut && $nowMin >= 1200) {
            echo json_encode([
                'success' => false,
                'code'    => 'PUNCHOUT_CLOSED',
                'msg'     => 'Punch out time closed at 8:00 PM. Your attendance has been auto-marked as Half Day. Please contact Admin if you need adjustments.'
            ]);
            exit;
        }
    }

    $punchDistance = null;
    if ($isSelfPunch && !empty($settings['geofence_enabled'])) {
        if ($userLat === null || $userLng === null) {
            echo json_encode([
                'success' => false,
                'code' => 'LOCATION_REQUIRED',
                'msg' => 'Please turn ON your device location / GPS to mark attendance.'
            ]);
            exit;
        }

        $officeLat = (float)($settings['office_latitude'] ?? 29.22006923);
        $officeLng = (float)($settings['office_longitude'] ?? 78.96778362);
        $allowedRadius = (int)($settings['geofence_radius_meters'] ?? 200);

        $punchDistance = calcDistanceMeters($userLat, $userLng, $officeLat, $officeLng);

        if ($punchDistance > $allowedRadius) {
            echo json_encode([
                'success' => false,
                'code' => 'OUTSIDE_OFFICE',
                'distance' => $punchDistance,
                'allowed_radius' => $allowedRadius,
                'msg' => "Please go to office and mark attendance. You are currently {$punchDistance} meters away from office (Allowed radius: {$allowedRadius}m)."
            ]);
            exit;
        }
    }

    $zeroHours = isset($_POST['zero_hours']) ? (int)$_POST['zero_hours'] : 1;
    if (in_array($status, ['absent', 'on_leave'], true)) {
        if ($zeroHours) {
            $checkIn = null;
            $checkOut = null;
            $totalHours = 0.00;
            $lateMinutes = 0;
            $overtime = 0.00;
            try {
                $pdo->prepare("UPDATE lunch_sessions SET status = 'cancelled', duration_seconds = 0 WHERE employee_id = ? AND session_date = ?")->execute([$employeeId, $date]);
            } catch (\Throwable $t) {}
        } else {
            $totalHours = calcTotalHours($checkIn, $checkOut);
            $lateMinutes = 0;
            $overtime = max(0, $totalHours - $settings['overtime_after_hours']);
        }
    } else {
        $lateMinutes = ($checkIn && in_array($status, ['present', 'late'])) ? calcLateMinutes($checkIn, $settings['expected_check_in'], $settings['grace_minutes']) : 0;
        $totalHours  = calcTotalHours($checkIn, $checkOut);
        
        // Deduct lunch duration if checking out
        if ($checkOut && $totalHours > 0) {
            $activeLunch = get_active_lunch_session($pdo, $employeeId, $date);
            if ($activeLunch) {
                // Unresumed break: auto-mark as 1:30 hours (5400s) starting from break start_time
                $lunchStartTs = strtotime($date . ' ' . $activeLunch['start_time']);
                $autoEndTime = date('H:i:s', $lunchStartTs + 5400);
                $pdo->prepare("UPDATE lunch_sessions SET end_time = ?, duration_seconds = 5400, status = 'completed', updated_at = NOW() WHERE id = ?")
                    ->execute([$autoEndTime, $activeLunch['id']]);
            }
            $lunchDurationSec = get_today_lunch_duration_seconds($pdo, $employeeId, $date);
            if ($lunchDurationSec > 0) {
                $totalHours = max(0.00, round($totalHours - ($lunchDurationSec / 3600), 2));
            }
        }
        
        $overtime    = max(0, $totalHours - $settings['overtime_after_hours']);
    }

    // Late Work Compensation ("Time Manage"):
    // If someone came late, but stayed late and completed the expected shift duration:
    $expectedInSec  = strtotime('2000-01-01 ' . substr($settings['expected_check_in'], 0, 8));
    $expectedOutSec = strtotime('2000-01-01 ' . substr($settings['expected_check_out'], 0, 8));
    $requiredShiftHours = ($expectedOutSec > $expectedInSec) ? (($expectedOutSec - $expectedInSec) / 3600) : 9.0;

    $isCompensated = false;
    if ($lateMinutes > 0 && $checkOut && $totalHours >= $requiredShiftHours) {
        // Late arrival compensated by late departure!
        $isCompensated = true;
        if (!is_admin() || $status === 'late') {
            $status = 'present';
        }
        $compNote = "Late arrival compensated (Worked " . format_hours_hms($totalHours) . ")";
        if (!str_contains($notes, 'compensated')) {
            $notes = trim($notes . ($notes ? ' | ' : '') . $compNote);
        }
    } elseif ($status === 'present' && $lateMinutes > 0 && !$isCompensated) {
        // Only auto-flag as late for self-punch or uncompensated
        if ($isSelfPunch) {
            $status = 'late';
        }
    }

    // Duplicate submission protection for clock in
    if ($checkIn && empty($checkOut)) {
        $chkStmt = $pdo->prepare("SELECT check_in, check_out, status FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
        $chkStmt->execute([$employeeId, $date]);
        $already = $chkStmt->fetch(PDO::FETCH_ASSOC);
        if ($already && !empty($already['check_in'])) {
            $hoursSummary = calculate_working_hours_summary($pdo, $employeeId, $date);
            $daysInMonth = (int)date('t', strtotime($date));
            $mAttStmt = $pdo->prepare("
                SELECT SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as present_days
                FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
            ");
            $mAttStmt->execute([$employeeId, date('Y-m-01', strtotime($date)), date('Y-m-t', strtotime($date))]);
            $pDays = (int)$mAttStmt->fetchColumn();
            $payload = [
                'already_marked' => true,
                'check_in' => date('h:i A', strtotime($already['check_in'])),
                'check_out' => !empty($already['check_out']) ? date('h:i A', strtotime($already['check_out'])) : null,
                'check_in_raw' => $already['check_in'],
                'check_out_raw' => $already['check_out'],
                'status' => $already['status'],
                'hours_summary' => $hoursSummary,
                'present_days' => $pDays,
                'total_month_days' => $daysInMonth,
                'present_days_formatted' => "{$pDays} / {$daysInMonth}",
                'msg' => 'Attendance already punched for today at ' . date('h:i A', strtotime($already['check_in']))
            ];
            echo json_encode(array_merge(['success' => true, 'data' => $payload], $payload));
            exit;
        }
    }

    $sql = "INSERT INTO attendance 
              (employee_id, attendance_date, status, check_in, check_out, late_minutes, total_hours, overtime_hours, leave_type, notes, marked_by, punch_latitude, punch_longitude, punch_distance_meters)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              status=VALUES(status), check_in=VALUES(check_in), check_out=VALUES(check_out),
              late_minutes=VALUES(late_minutes), total_hours=VALUES(total_hours),
              overtime_hours=VALUES(overtime_hours), leave_type=VALUES(leave_type),
              notes=VALUES(notes), marked_by=VALUES(marked_by),
              punch_latitude=COALESCE(VALUES(punch_latitude), punch_latitude),
              punch_longitude=COALESCE(VALUES(punch_longitude), punch_longitude),
              punch_distance_meters=COALESCE(VALUES(punch_distance_meters), punch_distance_meters),
              updated_at=NOW()";

    $pdo->prepare($sql)->execute([
        $employeeId, $date, $status, $checkIn, $checkOut,
        $lateMinutes, $totalHours, $overtime, $leaveType, $notes,
        $_SESSION['user_id'] ?? 0,
        $userLat, $userLng, $punchDistance
    ]);

    $hoursSummary = calculate_working_hours_summary($pdo, $employeeId, $date);
    $monthStart = date('Y-m-01', strtotime($date));
    $monthEnd = date('Y-m-t', strtotime($date));
    $daysInMonth = (int)date('t', strtotime($date));
    $mAttStmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
            SUM(CASE WHEN status NOT IN ('absent', 'on_leave') THEN total_hours ELSE 0 END) as total_work_hours
        FROM attendance 
        WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
    ");
    $mAttStmt->execute([$employeeId, $monthStart, $monthEnd]);
    $mSum = $mAttStmt->fetch(PDO::FETCH_ASSOC);
    $presentDays = (int)($mSum['present_days'] ?? 0);

    $targetUserId = $_SESSION['user_id'] ?? get_user_id_by_employee_id($pdo, $employeeId);
    if ($targetUserId) {
        if ($checkOut) {
            create_action_notification($pdo, $targetUserId, 'Punch-Out Successful', "You clocked out successfully at " . date('h:i A', strtotime($checkOut)) . " (Worked: " . format_hours_hms($totalHours) . ").", '/employee/attendance.php', 'attendance_clock_out');
        } elseif ($checkIn) {
            create_action_notification($pdo, $targetUserId, 'Punch-In Successful', "You marked attendance successfully at " . date('h:i A', strtotime($checkIn)) . " (" . ucfirst($status) . ").", '/employee/attendance.php', 'attendance_clock_in');
        }
    }

    $payload = [
        'late_minutes' => $lateMinutes,
        'late_hms' => format_minutes_hms($lateMinutes),
        'total_hours' => $totalHours,
        'total_hms' => format_hours_hms($totalHours),
        'check_in' => $checkIn ? date('h:i A', strtotime($checkIn)) : null,
        'check_out' => $checkOut ? date('h:i A', strtotime($checkOut)) : null,
        'check_in_raw' => $checkIn,
        'check_out_raw' => $checkOut,
        'status' => $status,
        'compensated' => $isCompensated,
        'distance' => $punchDistance,
        'hours_summary' => $hoursSummary,
        'present_days' => $presentDays,
        'total_month_days' => $daysInMonth,
        'present_days_formatted' => "{$presentDays} / {$daysInMonth}",
        'msg' => $checkOut ? 'Checked out successfully!' : 'Attendance marked successfully!'
    ];
    echo json_encode(array_merge(['success' => true, 'data' => $payload], $payload));
    exit;
}

// ─────────────────────────────────────────────
// ACTION: bulk_absent (mark all unmarked as absent)
// ─────────────────────────────────────────────
if ($action === 'bulk_absent') {
    if (!is_admin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'FORBIDDEN', 'msg' => 'Admin authorization required.']);
        exit;
    }
    $date = $_POST['date'] ?? date('Y-m-d');
    $employees = $pdo->query("SELECT id FROM employees WHERE status='active'")->fetchAll();
    foreach ($employees as $emp) {
        $pdo->prepare("INSERT IGNORE INTO attendance (employee_id, attendance_date, status, check_in, check_out, total_hours) VALUES (?, ?, 'absent', NULL, NULL, 0.00)")
            ->execute([$emp['id'], $date]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ─────────────────────────────────────────────
// ACTION: save_daily_report
// ─────────────────────────────────────────────
if ($action === 'save_daily_report') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        echo json_encode(['success' => false, 'msg' => 'Invalid CSRF token']);
        exit;
    }

    $employeeId     = intval($_POST['employee_id'] ?? 0);
    $reportDate     = $_POST['report_date'] ?? date('Y-m-d');

    // Future date validation
    if (strtotime($reportDate) > strtotime(date('Y-m-d'))) {
        echo json_encode(['success' => false, 'msg' => 'You cannot submit a work report for a future date (' . date('d M Y', strtotime($reportDate)) . ').']);
        exit;
    }

    $morningTasks   = trim($_POST['morning_tasks'] ?? '');
    $eveningTasks   = trim($_POST['evening_tasks'] ?? '');
    $projectsWorked = trim($_POST['projects_worked'] ?? '');
    $clientsWorked  = trim($_POST['clients_worked'] ?? '');
    
    // Calculate duration automatically from attendance and lunch breaks
    $durCalc        = calculate_work_report_duration($pdo, $employeeId, $reportDate);
    $hoursWorked    = (float)$durCalc['hours'];
    
    $blockers       = trim($_POST['blockers'] ?? '');
    $planTomorrow   = trim($_POST['plan_for_tomorrow'] ?? '');
    $mood           = $_POST['mood'] ?? 'good';

    $sql = "INSERT INTO daily_work_reports
              (employee_id, report_date, morning_tasks, evening_tasks, projects_worked, clients_worked, hours_worked, blockers, plan_for_tomorrow, mood)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              morning_tasks=VALUES(morning_tasks), evening_tasks=VALUES(evening_tasks),
              projects_worked=VALUES(projects_worked), clients_worked=VALUES(clients_worked),
              hours_worked=VALUES(hours_worked), blockers=VALUES(blockers),
              plan_for_tomorrow=VALUES(plan_for_tomorrow), mood=VALUES(mood), updated_at=NOW()";

    $pdo->prepare($sql)->execute([
        $employeeId, $reportDate, $morningTasks, $eveningTasks,
        $projectsWorked, $clientsWorked, $hoursWorked,
        $blockers, $planTomorrow, $mood
    ]);

    // Parity: also sync to work_reports table
    try {
        $wrCategory = $projectsWorked ?: 'General Development';
        $wrDesc = $eveningTasks ?: ($morningTasks ?: 'Completed tasks');
        $wCheck = $pdo->prepare("SELECT id FROM work_reports WHERE employee_id = ? AND report_date = ? LIMIT 1");
        $wCheck->execute([$employeeId, $reportDate]);
        $existingWr = $wCheck->fetchColumn();
        if ($existingWr) {
            $pdo->prepare("UPDATE work_reports SET summary=?, tasks_completed=?, projects_worked=?, hours_worked=?, challenges=?, tomorrow_plan=?, status='submitted', updated_at=NOW() WHERE id=?")
                ->execute([$wrCategory, $wrDesc, $wrCategory, $hoursWorked, $blockers, $planTomorrow, $existingWr]);
        } else {
            $pdo->prepare("INSERT INTO work_reports (employee_id, report_date, summary, tasks_completed, projects_worked, hours_worked, challenges, tomorrow_plan, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'submitted', NOW(), NOW())")
                ->execute([$employeeId, $reportDate, $wrCategory, $wrDesc, $wrCategory, $hoursWorked, $blockers, $planTomorrow]);
        }
    } catch (\Throwable $e) {}

    echo json_encode(['success' => true, 'calculated_hours' => $hoursWorked, 'formatted_duration' => $durCalc['duration_formatted']]);
    exit;
}

// ─────────────────────────────────────────────
// ACTION: review_report
// ─────────────────────────────────────────────
if ($action === 'review_report') {
    if (!can_access_admin_panel()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'FORBIDDEN', 'msg' => 'Manager or Admin authorization required.']);
        exit;
    }
    $reportId   = intval($_POST['report_id'] ?? 0);
    $rating     = intval($_POST['rating'] ?? 0);
    $reviewNote = trim($_POST['review_note'] ?? '');

    $pdo->prepare("UPDATE daily_work_reports SET rating=?, review_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
        ->execute([$rating ?: null, $reviewNote, $_SESSION['user_id'] ?? 0, $reportId]);

    // Send notification to employee
    try {
        $dwrStmt = $pdo->prepare("SELECT employee_id, report_date FROM daily_work_reports WHERE id = ?");
        $dwrStmt->execute([$reportId]);
        $dwr = $dwrStmt->fetch(PDO::FETCH_ASSOC);
        if ($dwr) {
            $empUserId = get_user_id_by_employee_id($pdo, (int)$dwr['employee_id']);
            if ($empUserId) {
                $ratingText = $rating > 0 ? " ({$rating}★)" : "";
                $msg = "Admin has reviewed your daily work report for {$dwr['report_date']}" . $ratingText . (!empty($reviewNote) ? ": \"{$reviewNote}\"" : ".");
                create_action_notification(
                    $pdo,
                    (int)$empUserId,
                    'Daily Report Reviewed',
                    $msg,
                    '/employee/work-reports.php',
                    'work_report_reviewed',
                    ['report_id' => $reportId, 'rating' => $rating, 'review_note' => $reviewNote]
                );
            }
        }
    } catch (\Throwable $t) {}

    echo json_encode(['success' => true]);
    exit;
}

// ─────────────────────────────────────────────
// ACTION: update_settings
// ─────────────────────────────────────────────
if ($action === 'update_settings') {
    if (!is_admin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'FORBIDDEN', 'msg' => 'Admin authorization required.']);
        exit;
    }
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        echo json_encode(['success' => false, 'msg' => 'Invalid CSRF token']);
        exit;
    }

    $checkIn    = $_POST['expected_check_in'] ?? '10:00';
    $checkOut   = $_POST['expected_check_out'] ?? '19:00';
    $grace      = intval($_POST['grace_minutes'] ?? 15);
    $deduction  = (float)($_POST['late_deduction_per_hour'] ?? 50);
    $halfDay    = (float)($_POST['half_day_threshold_hours'] ?? 4);
    $workDays   = intval($_POST['working_days_per_month'] ?? 26);
    $overtime   = (float)($_POST['overtime_after_hours'] ?? 9);

    $geofenceEnabled = isset($_POST['geofence_enabled']) ? intval($_POST['geofence_enabled']) : 1;
    $officeLat       = !empty($_POST['office_latitude']) ? (float)$_POST['office_latitude'] : 29.22006923;
    $officeLng       = !empty($_POST['office_longitude']) ? (float)$_POST['office_longitude'] : 78.96778362;
    $geofenceRadius  = !empty($_POST['geofence_radius_meters']) ? (int)$_POST['geofence_radius_meters'] : 200;
    $officeAddress   = trim($_POST['office_address'] ?? 'Vibesoft, Chamunda Vihar Phase 1, Ramnagar Road, Kashipur, Uttarakhand 244713');
    $dailyTarget     = !empty($_POST['daily_target_hours']) ? (float)$_POST['daily_target_hours'] : 7.00;
    $annualLeaves    = isset($_POST['annual_allowed_leaves']) ? intval($_POST['annual_allowed_leaves']) : 4;

    $existing = $pdo->query("SELECT id FROM attendance_settings LIMIT 1")->fetch();
    if ($existing) {
        $pdo->prepare("UPDATE attendance_settings SET 
            expected_check_in=?, expected_check_out=?, grace_minutes=?, 
            late_deduction_per_hour=?, half_day_threshold_hours=?, working_days_per_month=?, overtime_after_hours=?,
            geofence_enabled=?, office_latitude=?, office_longitude=?, geofence_radius_meters=?, office_address=?,
            daily_target_hours=?, annual_allowed_leaves=?
            WHERE id=1")
            ->execute([
                $checkIn.':00', $checkOut.':00', $grace, $deduction, $halfDay, $workDays, $overtime,
                $geofenceEnabled, $officeLat, $officeLng, $geofenceRadius, $officeAddress,
                $dailyTarget, $annualLeaves
            ]);
    } else {
        $pdo->prepare("INSERT INTO attendance_settings 
            (expected_check_in, expected_check_out, grace_minutes, late_deduction_per_hour, half_day_threshold_hours, working_days_per_month, overtime_after_hours, geofence_enabled, office_latitude, office_longitude, geofence_radius_meters, office_address, daily_target_hours, annual_allowed_leaves) 
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $checkIn.':00', $checkOut.':00', $grace, $deduction, $halfDay, $workDays, $overtime,
                $geofenceEnabled, $officeLat, $officeLng, $geofenceRadius, $officeAddress,
                $dailyTarget, $annualLeaves
            ]);
    }

    // Check if auto recalculation requested
    $recalcTarget = $_POST['recalculate_scope'] ?? '';
    $recalculated = 0;
    if ($recalcTarget) {
        $sql = "SELECT id, employee_id, attendance_date, check_in, check_out, status FROM attendance WHERE check_in IS NOT NULL";
        $params = [];
        if ($recalcTarget !== 'all') {
            $sql .= " AND attendance_date = ?";
            $params[] = $recalcTarget;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            if (!in_array($r['status'], ['present', 'late'])) continue;
            $lateMins = calcLateMinutes($r['check_in'], $checkIn.':00', $grace);
            $newStatus = ($lateMins > 0) ? 'late' : 'present';
            $pdo->prepare("UPDATE attendance SET late_minutes=?, status=? WHERE id=?")
                ->execute([$lateMins, $newStatus, $r['id']]);
            $recalculated++;
        }
    }

    echo json_encode(['success' => true, 'recalculated' => $recalculated]);
    exit;
}

// ─────────────────────────────────────────────
// ACTION: recalculate_late
// ─────────────────────────────────────────────
if ($action === 'recalculate_late') {
    if (!is_admin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'FORBIDDEN', 'msg' => 'Admin authorization required.']);
        exit;
    }
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        echo json_encode(['success' => false, 'msg' => 'Invalid CSRF token']);
        exit;
    }

    $targetDate = $_POST['date'] ?? null;
    $settings   = getSettings($pdo);

    $sql = "SELECT id, employee_id, attendance_date, check_in, check_out, status FROM attendance WHERE check_in IS NOT NULL";
    $params = [];
    if (!empty($targetDate) && $targetDate !== 'all') {
        $sql .= " AND attendance_date = ?";
        $params[] = $targetDate;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $updated = 0;
    foreach ($rows as $r) {
        if (!in_array($r['status'], ['present', 'late'])) continue;

        $lateMins = calcLateMinutes($r['check_in'], $settings['expected_check_in'], $settings['grace_minutes']);
        $newStatus = ($lateMins > 0) ? 'late' : 'present';

        $totalHours = calcTotalHours($r['check_in'], $r['check_out']);
        $overtime   = max(0, $totalHours - $settings['overtime_after_hours']);

        $upd = $pdo->prepare("UPDATE attendance SET late_minutes=?, status=?, total_hours=?, overtime_hours=? WHERE id=?");
        $upd->execute([$lateMins, $newStatus, $totalHours, $overtime, $r['id']]);
        $updated++;
    }

    echo json_encode([
        'success' => true, 
        'updated' => $updated, 
        'msg' => "Updated $updated records using {$settings['grace_minutes']} min grace period!"
    ]);
    exit;
}

// ─────────────────────────────────────────────
// ACTION: get_employee_day (GET - for modal load)
// ─────────────────────────────────────────────
if ($action === 'get_employee_day') {
    $employeeId = intval($_GET['employee_id'] ?? 0);
    $date       = $_GET['date'] ?? date('Y-m-d');

    $att = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND attendance_date=?");
    $att->execute([$employeeId, $date]);
    $attRow = $att->fetch();

    $rep = $pdo->prepare("SELECT * FROM daily_work_reports WHERE employee_id=? AND report_date=?");
    $rep->execute([$employeeId, $date]);
    $repRow = $rep->fetch();

    echo json_encode(['success' => true, 'attendance' => $attRow, 'report' => $repRow]);
    exit;
}

// ─────────────────────────────────────────────
// ACTION: get_office_location (GET)
// ─────────────────────────────────────────────
if ($action === 'get_office_location') {
    $s = getSettings($pdo);
    echo json_encode([
        'success' => true,
        'geofence_enabled' => (bool)($s['geofence_enabled'] ?? 1),
        'office_latitude'  => (float)($s['office_latitude'] ?? 29.22006923),
        'office_longitude' => (float)($s['office_longitude'] ?? 78.96778362),
        'geofence_radius_meters' => (int)($s['geofence_radius_meters'] ?? 200),
        'office_address'   => $s['office_address'] ?? 'Vibesoft, Chamunda Vihar Phase 1, Ramnagar Road, Kashipur, Uttarakhand 244713'
    ]);
    exit;
}

// ─────────────────────────────────────────────
// ACTION: get_calculated_duration
// ─────────────────────────────────────────────
if ($action === 'get_calculated_duration') {
    $empId = intval($_GET['employee_id'] ?? ($_POST['employee_id'] ?? ($_SESSION['employee_id'] ?? 0)));
    $date  = $_GET['date'] ?? ($_POST['date'] ?? date('Y-m-d'));
    if (!$empId) {
        echo json_encode(['success' => false, 'msg' => 'Employee ID required']);
        exit;
    }
    $calc = calculate_work_report_duration($pdo, $empId, $date);
    echo json_encode(['success' => true, 'data' => $calc]);
    exit;
}

// ─────────────────────────────────────────────
// ACTION: lunch_status
// ─────────────────────────────────────────────
if ($action === 'lunch_status') {
    $empId = intval($_GET['employee_id'] ?? ($_SESSION['employee_id'] ?? 0));
    $date  = $_GET['date'] ?? date('Y-m-d');
    if (!$empId) {
        echo json_encode(['success' => false, 'msg' => 'Employee ID required']);
        exit;
    }
    $active = get_active_lunch_session($pdo, $empId, $date);
    $totalSec = get_today_lunch_duration_seconds($pdo, $empId, $date);
    $sessions = get_today_lunch_sessions($pdo, $empId, $date);

    echo json_encode([
        'success' => true,
        'is_on_lunch' => !empty($active),
        'active_session' => $active,
        'total_duration_seconds' => $totalSec,
        'total_duration_formatted' => format_minutes_hm((int)round($totalSec / 60)),
        'sessions' => $sessions
    ]);
    exit;
}

// ─────────────────────────────────────────────
// ACTION: lunch_start (Break Start)
// ─────────────────────────────────────────────
if ($action === 'lunch_start') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        echo json_encode(['success' => false, 'msg' => 'Invalid CSRF token']);
        exit;
    }
    $empId = intval($_POST['employee_id'] ?? ($_SESSION['employee_id'] ?? 0));
    $date  = $_POST['date'] ?? date('Y-m-d');
    $userLat = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $userLng = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;

    if (!$empId) {
        echo json_encode(['success' => false, 'msg' => 'Employee ID required']);
        exit;
    }

    $attStmt = $pdo->prepare("SELECT id, check_in, check_out FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
    $attStmt->execute([$empId, $date]);
    $todayAtt = $attStmt->fetch(PDO::FETCH_ASSOC);

    if (!$todayAtt || empty($todayAtt['check_in'])) {
        echo json_encode(['success' => false, 'msg' => 'You must clock in first before starting a break']);
        exit;
    }
    if (!empty($todayAtt['check_out'])) {
        echo json_encode(['success' => false, 'msg' => 'You have already clocked out for today']);
        exit;
    }

    $active = get_active_lunch_session($pdo, $empId, $date);
    if ($active) {
        echo json_encode(['success' => false, 'msg' => 'You are already on break', 'active_session' => $active]);
        exit;
    }

    $nowTime = date('H:i:s');
    $ins = $pdo->prepare("INSERT INTO lunch_sessions (attendance_id, employee_id, session_date, start_time, start_latitude, start_longitude, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())");
    $ins->execute([$todayAtt['id'], $empId, $date, $nowTime, $userLat, $userLng]);
    $sessionId = $pdo->lastInsertId();

    $breakUserId = $_SESSION['user_id'] ?? get_user_id_by_employee_id($pdo, $empId);
    if ($breakUserId) {
        create_action_notification($pdo, $breakUserId, 'Break Started', "Your break started at " . date('h:i A', strtotime($nowTime)) . ".", '/employee/attendance.php', 'attendance_break_start');
    }

    $hoursSummary = calculate_working_hours_summary($pdo, $empId, $date);

    $payload = [
        'msg' => 'Break started at ' . date('h:i A', strtotime($nowTime)),
        'session_id' => (int)$sessionId,
        'start_time' => $nowTime,
        'start_time_formatted' => date('h:i A', strtotime($nowTime)),
        'is_on_break' => true,
        'is_on_lunch' => true,
        'hours_summary' => $hoursSummary
    ];
    echo json_encode(array_merge(['success' => true, 'data' => $payload], $payload));
    exit;
}

// ─────────────────────────────────────────────
// ACTION: lunch_end (Break End)
// ─────────────────────────────────────────────
if ($action === 'lunch_end') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        echo json_encode(['success' => false, 'msg' => 'Invalid CSRF token']);
        exit;
    }
    $empId = intval($_POST['employee_id'] ?? ($_SESSION['employee_id'] ?? 0));
    $date  = $_POST['date'] ?? date('Y-m-d');
    $userLat = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $userLng = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;

    if (!$empId) {
        echo json_encode(['success' => false, 'msg' => 'Employee ID required']);
        exit;
    }

    $active = get_active_lunch_session($pdo, $empId, $date);
    if (!$active) {
        echo json_encode(['success' => false, 'msg' => 'No active break found to end']);
        exit;
    }

    $nowTime = date('H:i:s');
    $startTs = strtotime($date . ' ' . $active['start_time']);
    $endTs = strtotime($date . ' ' . $nowTime);
    $durSec = max(0, $endTs - $startTs);
    // Cap at 1:30 hours (5400 seconds) if unresumed
    if ($durSec > 5400) {
        $durSec = 5400;
        $nowTime = date('H:i:s', $startTs + 5400);
    }

    $upd = $pdo->prepare("UPDATE lunch_sessions SET end_time = ?, duration_seconds = ?, end_latitude = ?, end_longitude = ?, status = 'completed', updated_at = NOW() WHERE id = ?");
    $upd->execute([$nowTime, $durSec, $userLat, $userLng, $active['id']]);

    $totalSec = get_today_lunch_duration_seconds($pdo, $empId, $date);
    $hoursSummary = calculate_working_hours_summary($pdo, $empId, $date);

    $breakUserId = $_SESSION['user_id'] ?? get_user_id_by_employee_id($pdo, $empId);
    if ($breakUserId) {
        create_action_notification($pdo, $breakUserId, 'Break Ended', "Your break ended at " . date('h:i A', strtotime($nowTime)) . " (Duration: " . format_minutes_hm((int)round($durSec / 60)) . ").", '/employee/attendance.php', 'attendance_break_end');
    }

    $payload = [
        'msg' => 'Break ended. Duration: ' . format_minutes_hm((int)round($durSec / 60)),
        'session_duration_seconds' => $durSec,
        'total_lunch_seconds' => $totalSec,
        'total_lunch_formatted' => format_minutes_hm((int)round($totalSec / 60)),
        'total_break_seconds' => $totalSec,
        'total_break_formatted' => format_minutes_hm((int)round($totalSec / 60)),
        'is_on_break' => false,
        'is_on_lunch' => false,
        'hours_summary' => $hoursSummary
    ];
    echo json_encode(array_merge(['success' => true, 'data' => $payload], $payload));
    exit;
}

// ─────────────────────────────────────────────
// ACTION: get_dashboard_attendance_state
// ─────────────────────────────────────────────
if ($action === 'get_dashboard_attendance_state') {
    $empId = intval($_GET['employee_id'] ?? ($_POST['employee_id'] ?? ($_SESSION['employee_id'] ?? 0)));
    $date  = $_GET['date'] ?? ($_POST['date'] ?? date('Y-m-d'));

    if (!$empId) {
        echo json_encode(['success' => false, 'msg' => 'Employee ID required']);
        exit;
    }

    $attStmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
    $attStmt->execute([$empId, $date]);
    $todayAtt = $attStmt->fetch(PDO::FETCH_ASSOC);

    $hoursSummary = calculate_working_hours_summary($pdo, $empId, $date);
    $activeLunch  = get_active_lunch_session($pdo, $empId, $date);
    $isOnLunch    = !empty($activeLunch);
    $totalLunchSec= get_today_lunch_duration_seconds($pdo, $empId, $date);

    $monthStart = date('Y-m-01', strtotime($date));
    $monthEnd   = date('Y-m-t', strtotime($date));
    $daysInMonth= (int)date('t', strtotime($date));

    $mAttStmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
            SUM(CASE WHEN status NOT IN ('absent', 'on_leave') THEN total_hours ELSE 0 END) as total_work_hours
        FROM attendance 
        WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
    ");
    $mAttStmt->execute([$empId, $monthStart, $monthEnd]);
    $mSum = $mAttStmt->fetch(PDO::FETCH_ASSOC);
    $presentDays = (int)($mSum['present_days'] ?? 0);

    $dayCheck = is_date_holiday_or_sunday($date);
    $isNonWorking = !empty($dayCheck['is_non_working']);
    $isWorkingOverride = !empty($dayCheck['is_working_sunday']) || ($dayCheck['reason'] && stripos($dayCheck['reason'], 'working') !== false);
    $holidayName = $dayCheck['holiday_name'] ?: ($dayCheck['reason'] ?: 'Holiday / Weekly Off');

    $payload = [
        'attendance'            => $todayAtt,
        'check_in'              => !empty($todayAtt['check_in']) ? date('h:i A', strtotime($todayAtt['check_in'])) : null,
        'check_out'             => !empty($todayAtt['check_out']) ? date('h:i A', strtotime($todayAtt['check_out'])) : null,
        'check_in_raw'          => $todayAtt['check_in'] ?? null,
        'check_out_raw'         => $todayAtt['check_out'] ?? null,
        'status'                => $todayAtt['status'] ?? null,
        'late_minutes'          => (int)($todayAtt['late_minutes'] ?? 0),
        'late_hms'              => format_minutes_hms((int)($todayAtt['late_minutes'] ?? 0)),
        'is_on_break'           => $isOnLunch,
        'is_on_lunch'           => $isOnLunch,
        'break_seconds'         => $totalLunchSec,
        'total_lunch_seconds'   => $totalLunchSec,
        'break_formatted'       => format_minutes_hm((int)round($totalLunchSec / 60)),
        'total_lunch_formatted' => format_minutes_hm((int)round($totalLunchSec / 60)),
        'break_start'           => $activeLunch ? date('h:i A', strtotime($activeLunch['start_time'])) : null,
        'start_time'            => $activeLunch ? date('h:i A', strtotime($activeLunch['start_time'])) : null,
        'hours_summary'         => $hoursSummary,
        'present_days'          => $presentDays,
        'total_month_days'      => $daysInMonth,
        'present_days_formatted'=> "{$presentDays} / {$daysInMonth}",
        'is_non_working'        => $isNonWorking,
        'is_holiday'            => !empty($dayCheck['is_holiday']),
        'is_sunday'             => !empty($dayCheck['is_sunday']),
        'holiday_name'          => $holidayName,
        'holiday_reason'        => $dayCheck['reason'] ?? '',
        'is_working_override'   => $isWorkingOverride,
        'can_mark_attendance'   => !$isNonWorking,
        'day_type'              => $isNonWorking ? 'holiday' : ($isWorkingOverride ? 'working_override' : 'working_day')
    ];
    echo json_encode(array_merge(['success' => true, 'data' => $payload], $payload));
    exit;
}

echo json_encode(['success' => false, 'msg' => 'Unknown action']);
