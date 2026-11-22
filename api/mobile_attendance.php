<?php
// Crm and backend/api/mobile_attendance.php — Multi-view Attendance API (Month, Week, Day)
require_once __DIR__ . '/mobile_helper.php';
require_once __DIR__ . '/../includes/functions.php';

$auth = authenticate_mobile();
$empId = $auth['employee_id'];
if (!$empId) {
    json_error('No linked employee profile found for this account', 400);
}

// Strict IDOR Check: Ensure employee only queries their own attendance unless Manager/Admin
$targetEmpId = (int)($_GET['employee_id'] ?? ($_POST['employee_id'] ?? $empId));
if ($targetEmpId !== $empId) {
    verify_employee_access($targetEmpId, $auth, 'attendance records');
    $empId = $targetEmpId;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'history');
$input = get_json_input();

// Auto-process daily attendance rules (5 PM cutoff & 8 PM cutoff)
auto_process_daily_attendance($pdo, date('Y-m-d'));

// Fetch attendance settings
$sStmt = $pdo->query("SELECT * FROM attendance_settings LIMIT 1");
$settings = $sStmt ? $sStmt->fetch(PDO::FETCH_ASSOC) : null;

$officeLat = (float)($settings['office_latitude'] ?? 29.22006923);
$officeLng = (float)($settings['office_longitude'] ?? 78.96778362);
$geofenceRadius = (int)($settings['geofence_radius_meters'] ?? 200);
$geofenceEnabled = !empty($settings['geofence_enabled']);
$expectedCheckIn = $settings['expected_check_in'] ?? '10:00:00';
$expectedCheckOut = $settings['expected_check_out'] ?? '19:00:00';
$graceMinutes = (int)($settings['grace_minutes'] ?? 15);

// ==========================================
// ACTION: OFFICE LOCATION (GEOFENCE STATUS)
// ==========================================
if ($action === 'office_location' || $action === 'status') {
    $today = date('Y-m-d');
    $dayCheck = is_date_holiday_or_sunday($today);
    $isNonWorking = !empty($dayCheck['is_non_working']);
    $isWorkingOverride = !empty($dayCheck['is_working_sunday']) || (!empty($dayCheck['reason']) && stripos($dayCheck['reason'], 'working') !== false);
    $holidayName = $dayCheck['holiday_name'] ?: ($dayCheck['reason'] ?: 'Holiday / Weekly Off');

    json_success([
        'geofence_enabled'       => $geofenceEnabled,
        'office_latitude'        => $officeLat,
        'office_longitude'       => $officeLng,
        'geofence_radius_meters' => $geofenceRadius,
        'office_address'         => $settings['office_address'] ?? 'Vibesoft, Kashipur, Uttarakhand',
        'is_non_working'         => $isNonWorking,
        'is_holiday'             => !empty($dayCheck['is_holiday']),
        'is_sunday'              => !empty($dayCheck['is_sunday']),
        'is_working_override'    => $isWorkingOverride,
        'holiday_name'           => $holidayName,
        'holiday_reason'         => $dayCheck['reason'] ?? '',
        'can_mark_attendance'    => !$isNonWorking
    ], 'Office location loaded');
}

// ==========================================
// ACTION: CLOCK IN
// ==========================================
if ($action === 'clock_in') {
    check_api_rate_limit('clock_in', (string)$empId, 6, 60);

    $today = date('Y-m-d');

    // Strict Holiday / Non-working Day Check
    $dayCheck = is_date_holiday_or_sunday($today);
    if (!empty($dayCheck['is_non_working'])) {
        $hName = $dayCheck['holiday_name'] ?: ($dayCheck['reason'] ?: 'Holiday / Weekly Off');
        json_error("Today is an official holiday / weekly off ({$hName}). Attendance cannot be marked for today.", 400, [
            'code'            => 'HOLIDAY_BLOCKED',
            'holiday_name'    => $hName,
            'reason'          => $dayCheck['reason'] ?? ''
        ]);
    }

    $lat = isset($input['latitude']) ? (float)$input['latitude'] : null;
    $lng = isset($input['longitude']) ? (float)$input['longitude'] : null;

    if ($geofenceEnabled) {
        if ($lat === null || $lng === null) {
            json_error("Aap bina location open kare attendance nahi laga sakte.", 403, [
                'code' => 'LOCATION_REQUIRED'
            ]);
        }
        $distance = calculate_distance_meters($lat, $lng, $officeLat, $officeLng);
        if ($distance > $geofenceRadius) {
            json_error("Please go to office to mark attendance. You are {$distance}m away (allowed: {$geofenceRadius}m).", 403, [
                'code'            => 'OUTSIDE_OFFICE',
                'distance_meters' => $distance,
                'allowed_radius'  => $geofenceRadius
            ]);
        }
    } elseif ($lat !== null && $lng !== null) {
        $distance = calculate_distance_meters($lat, $lng, $officeLat, $officeLng);
    }

    $nowTime = date('H:i:s');
    $nowMin = ((int)date('G') * 60) + (int)date('i');

    // After 5:00 PM, check-in is permitted but marked as late
    $isAfter5PM = ($nowMin >= 1020);

    // Check existing
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
    $stmt->execute([$empId, $today]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && !empty($existing['check_in'])) {
        json_error("Already clocked in today at " . date('h:i A', strtotime($existing['check_in'])), 400);
    }

    // Determine if late (after 10:00 AM + grace minutes)
    $expectedTimestamp = strtotime($today . ' ' . $expectedCheckIn) + ($graceMinutes * 60);
    $currentTimestamp = strtotime($today . ' ' . $nowTime);
    $lateMinutes = 0;
    $status = 'present';

    if ($currentTimestamp > $expectedTimestamp || !empty($isAfter5PM)) {
        $status = 'late';
        $lateMinutes = (int)round(($currentTimestamp - strtotime($today . ' ' . $expectedCheckIn)) / 60);
    }

    if ($existing) {
        $uStmt = $pdo->prepare("
            UPDATE attendance 
            SET status = ?, check_in = ?, late_minutes = ?, punch_latitude = ?, punch_longitude = ?, punch_distance_meters = ?, notes = 'Clocked in via Mobile App', updated_at = NOW() 
            WHERE id = ?
        ");
        $uStmt->execute([$status, $nowTime, $lateMinutes, $lat, $lng, (int)$distance, $existing['id']]);
        $attendanceId = $existing['id'];
    } else {
        $iStmt = $pdo->prepare("
            INSERT INTO attendance (employee_id, attendance_date, status, check_in, late_minutes, punch_latitude, punch_longitude, punch_distance_meters, notes, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Clocked in via Mobile App', NOW(), NOW())
        ");
        $iStmt->execute([$empId, $today, $status, $nowTime, $lateMinutes, $lat, $lng, (int)$distance]);
        $attendanceId = (int)$pdo->lastInsertId();
    }

    try {
        require_once __DIR__ . '/../app/Realtime/RealtimeService.php';
        \App\Realtime\RealtimeService::getInstance($pdo)->emit(
            'attendance.updated',
            'attendance',
            $attendanceId,
            'status_change',
            [
                'employee_id'       => (int)$empId,
                'status'            => 'CLOCKED_IN',
                'attendance_status' => $status,
                'time'              => date('h:i A', strtotime($nowTime)),
                'date'              => $today
            ],
            ['admin', 'manager'],
            null,
            $auth['user_id']
        );
    } catch (\Throwable $e) {}

    create_action_notification(
        $pdo,
        (int)$auth['user_id'],
        'Punch-In Successful',
        "You clocked in successfully at " . date('h:i A', strtotime($nowTime)) . " (" . ucfirst($status) . ").",
        '/employee/attendance.php',
        'attendance_clock_in'
    );

    json_success([
        'time'            => date('h:i A', strtotime($nowTime)),
        'status'          => $status,
        'late_minutes'    => $lateMinutes,
        'distance_meters' => $distance
    ], 'Clocked in successfully at ' . date('h:i A', strtotime($nowTime)));
}

// ==========================================
// ACTION: LUNCH START
// ==========================================
if ($action === 'lunch_start') {
    check_api_rate_limit('lunch_start', (string)$empId, 6, 60);

    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    // Verify employee has clocked in today and not clocked out
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
    $stmt->execute([$empId, $today]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$att || empty($att['check_in'])) {
        json_error("You must clock in first before starting a break.", 400);
    }
    if (!empty($att['check_out']) && $att['check_out'] !== '00:00:00') {
        json_error("Cannot start break after clocking out.", 400);
    }

    // Check if already active
    $active = get_active_lunch_session($pdo, $empId, $today);
    if ($active) {
        json_error("Break is already started at " . date('h:i A', strtotime($active['start_time'])), 400);
    }

    // Location verification if geofencing is enabled
    $lat = isset($input['latitude']) ? (float)$input['latitude'] : null;
    $lng = isset($input['longitude']) ? (float)$input['longitude'] : null;
    $distance = 0;
    if ($lat !== null && $lng !== null) {
        $distance = calculate_distance_meters($lat, $lng, $officeLat, $officeLng);
        if ($geofenceEnabled && $distance > $geofenceRadius) {
            json_error("You are {$distance}m away from the office. Geofence radius is {$geofenceRadius}m.", 403, [
                'distance_meters' => $distance,
                'allowed_radius'  => $geofenceRadius
            ]);
        }
    }

    $ins = $pdo->prepare("
        INSERT INTO lunch_sessions (attendance_id, employee_id, session_date, start_time, start_latitude, start_longitude, status, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
    ");
    $ins->execute([$att['id'], $empId, $today, $nowTime, $lat, $lng]);
    $lunchId = (int)$pdo->lastInsertId();

    try {
        require_once __DIR__ . '/../app/Realtime/RealtimeService.php';
        \App\Realtime\RealtimeService::getInstance($pdo)->emit(
            'attendance.lunch',
            'lunch_session',
            $lunchId,
            'start',
            [
                'employee_id' => (int)$empId,
                'status'      => 'LUNCH_STARTED',
                'time'        => date('h:i A', strtotime($nowTime)),
                'date'        => $today
            ],
            ['admin', 'manager'],
            null,
            $auth['user_id']
        );
    } catch (\Throwable $e) {}

    create_action_notification(
        $pdo,
        (int)$auth['user_id'],
        'Break Started',
        "Your break started at " . date('h:i A', strtotime($nowTime)) . ".",
        '/employee/attendance.php',
        'attendance_break_start'
    );

    json_success([
        'lunch_id'    => $lunchId,
        'break_id'    => $lunchId,
        'start_time'  => date('h:i A', strtotime($nowTime)),
        'is_on_lunch' => true,
        'is_on_break' => true
    ], 'Break started at ' . date('h:i A', strtotime($nowTime)));
}

// ==========================================
// ACTION: LUNCH / BREAK END
// ==========================================
if ($action === 'lunch_end' || $action === 'break_end') {
    check_api_rate_limit('lunch_end', (string)$empId, 6, 60);

    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    $active = get_active_lunch_session($pdo, $empId, $today);
    if (!$active) {
        json_error("No active break session found.", 400);
    }

    $lat = isset($input['latitude']) ? (float)$input['latitude'] : null;
    $lng = isset($input['longitude']) ? (float)$input['longitude'] : null;
    $distance = 0;
    if ($lat !== null && $lng !== null) {
        $distance = calculate_distance_meters($lat, $lng, $officeLat, $officeLng);
        if ($geofenceEnabled && $distance > $geofenceRadius) {
            json_error("You are {$distance}m away from the office. Geofence radius is {$geofenceRadius}m.", 403, [
                'distance_meters' => $distance,
                'allowed_radius'  => $geofenceRadius
            ]);
        }
    }

    $startTs = strtotime($today . ' ' . $active['start_time']);
    $endTs   = strtotime($today . ' ' . $nowTime);
    $durSec  = max(0, $endTs - $startTs);
    // Cap at 1:30 hours (5400 seconds) if unresumed
    if ($durSec > 5400) {
        $durSec = 5400;
        $nowTime = date('H:i:s', $startTs + 5400);
    }

    $uStmt = $pdo->prepare("
        UPDATE lunch_sessions 
        SET end_time = ?, duration_seconds = ?, end_latitude = ?, end_longitude = ?, status = 'completed', updated_at = NOW() 
        WHERE id = ?
    ");
    $uStmt->execute([$nowTime, $durSec, $lat, $lng, $active['id']]);

    $totalLunchSec = get_today_lunch_duration_seconds($pdo, $empId, $today);

    try {
        require_once __DIR__ . '/../app/Realtime/RealtimeService.php';
        \App\Realtime\RealtimeService::getInstance($pdo)->emit(
            'attendance.lunch',
            'lunch_session',
            (int)$active['id'],
            'end',
            [
                'employee_id'           => (int)$empId,
                'status'                => 'LUNCH_ENDED',
                'time'                  => date('h:i A', strtotime($nowTime)),
                'duration_seconds'      => $durSec,
                'duration_formatted'    => format_minutes_hm((int)round($durSec / 60)),
                'date'                  => $today
            ],
            ['admin', 'manager'],
            null,
            $auth['user_id']
        );
    } catch (\Throwable $e) {}

    create_action_notification(
        $pdo,
        (int)$auth['user_id'],
        'Break Ended',
        "Your break ended at " . date('h:i A', strtotime($nowTime)) . " (Duration: " . format_minutes_hm((int)round($durSec / 60)) . ").",
        '/employee/attendance.php',
        'attendance_break_end'
    );

    json_success([
        'end_time'              => date('h:i A', strtotime($nowTime)),
        'duration_seconds'      => $durSec,
        'duration_formatted'    => format_minutes_hm((int)round($durSec / 60)),
        'total_lunch_seconds'   => $totalLunchSec,
        'total_break_seconds'   => $totalLunchSec,
        'total_break_formatted' => format_minutes_hm((int)round($totalLunchSec / 60)),
        'is_on_lunch'           => false,
        'is_on_break'           => false
    ], 'Break ended. Duration: ' . format_minutes_hm((int)round($durSec / 60)));
}

// ==========================================
// ACTION: LUNCH / BREAK STATUS
// ==========================================
if ($action === 'lunch_status' || $action === 'break_status') {
    $today = date('Y-m-d');
    $active = get_active_lunch_session($pdo, $empId, $today);
    $totalLunchSec = get_today_lunch_duration_seconds($pdo, $empId, $today);
    $sessions = get_today_lunch_sessions($pdo, $empId, $today);

    json_success([
        'is_on_lunch'          => !empty($active),
        'is_on_break'          => !empty($active),
        'active_session'       => $active,
        'total_lunch_seconds'  => $totalLunchSec,
        'total_lunch_formatted'=> format_minutes_hm((int)round($totalLunchSec / 60)),
        'total_break_seconds'  => $totalLunchSec,
        'total_break_formatted'=> format_minutes_hm((int)round($totalLunchSec / 60)),
        'sessions'             => $sessions
    ], 'Break status loaded');
}

// ==========================================
// ACTION: CLOCK OUT
// ==========================================
if ($action === 'clock_out') {
    check_api_rate_limit('clock_out', (string)$empId, 6, 60);

    $today = date('Y-m-d');
    $nowTime = date('H:i:s');
    $nowMin = ((int)date('G') * 60) + (int)date('i');

    $lat = isset($input['latitude']) ? (float)$input['latitude'] : null;
    $lng = isset($input['longitude']) ? (float)$input['longitude'] : null;

    if ($geofenceEnabled) {
        if ($lat === null || $lng === null) {
            json_error("Aap bina location open kare attendance nahi laga sakte.", 403, [
                'code' => 'LOCATION_REQUIRED'
            ]);
        }
        $distance = calculate_distance_meters($lat, $lng, $officeLat, $officeLng);
        if ($distance > $geofenceRadius) {
            json_error("Please go to office to punch out. You are {$distance}m away (allowed: {$geofenceRadius}m).", 403, [
                'code'            => 'OUTSIDE_OFFICE',
                'distance_meters' => $distance,
                'allowed_radius'  => $geofenceRadius
            ]);
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
    $stmt->execute([$empId, $today]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing || empty($existing['check_in'])) {
        json_error("You have not clocked in yet today.", 400);
    }
    $isAutoHalfDay = (!empty($existing['check_out']) && $existing['check_out'] === '12:00:00' && $existing['status'] === 'half_day');
    if (!empty($existing['check_out']) && $existing['check_out'] !== '00:00:00' && !$isAutoHalfDay) {
        json_error("Already clocked out today at " . date('h:i A', strtotime($existing['check_out'])), 400);
    }

    // If on lunch when clocking out, auto-mark unresumed break as 1:30 hours (5400s)
    $activeLunch = get_active_lunch_session($pdo, $empId, $today);
    if ($activeLunch) {
        $lunchStartTs = strtotime($today . ' ' . $activeLunch['start_time']);
        $autoEndTime  = date('H:i:s', $lunchStartTs + 5400);
        $pdo->prepare("UPDATE lunch_sessions SET end_time = ?, duration_seconds = 5400, status = 'completed', updated_at = NOW() WHERE id = ?")
            ->execute([$autoEndTime, $activeLunch['id']]);
    }

    $inSeconds = strtotime($today . ' ' . $existing['check_in']);
    $outSeconds = strtotime($today . ' ' . $nowTime);
    $rawWorkSec = max(0, $outSeconds - $inSeconds);
    $lunchSec   = get_today_lunch_duration_seconds($pdo, $empId, $today);
    $netWorkSec = max(0, $rawWorkSec - $lunchSec);
    $totalHours = round($netWorkSec / 3600, 2);

    // Late compensation business rule from web app:
    // If someone came late, but stayed and completed expected shift duration:
    $dailyTargetHours = (float)($settings['daily_target_hours'] ?? 7.00);
    $currentStatus = $existing['status'];
    $notes = $existing['notes'] ?? '';
    if (($existing['late_minutes'] ?? 0) > 0 && $totalHours >= $dailyTargetHours) {
        $currentStatus = 'present';
        $compNote = "Late arrival compensated (Worked " . format_minutes_hm((int)round($totalHours * 60)) . ")";
        if (!str_contains($notes, 'compensated')) {
            $notes = trim($notes . ($notes ? ' | ' : '') . $compNote);
        }
    }

    $uStmt = $pdo->prepare("
        UPDATE attendance 
        SET check_out = ?, total_hours = ?, status = ?, notes = ?, updated_at = NOW() 
        WHERE id = ?
    ");
    $uStmt->execute([$nowTime, $totalHours, $currentStatus, $notes, $existing['id']]);

    try {
        require_once __DIR__ . '/../app/Realtime/RealtimeService.php';
        \App\Realtime\RealtimeService::getInstance($pdo)->emit(
            'attendance.updated',
            'attendance',
            (int)$existing['id'],
            'status_change',
            [
                'employee_id' => (int)$empId,
                'status'      => 'CLOCKED_OUT',
                'time'        => date('h:i A', strtotime($nowTime)),
                'total_hours' => $totalHours,
                'date'        => $today
            ],
            ['admin', 'manager'],
            null,
            $auth['user_id']
        );
    } catch (\Throwable $e) {}

    create_action_notification(
        $pdo,
        (int)$auth['user_id'],
        'Punch-Out Successful',
        "You clocked out successfully at " . date('h:i A', strtotime($nowTime)) . " (Total Worked: " . format_minutes_hm((int)round($totalHours * 60)) . ").",
        '/employee/attendance.php',
        'attendance_clock_out'
    );

    json_success([
        'time'            => date('h:i A', strtotime($nowTime)),
        'total_hours'     => $totalHours,
        'total_formatted' => format_minutes_hm((int)round($totalHours * 60)),
        'lunch_formatted' => format_minutes_hm((int)round($lunchSec / 60)),
        'status'          => $currentStatus
    ], 'Clocked out successfully at ' . date('h:i A', strtotime($nowTime)));
}

// ==========================================
// ACTION: HISTORY (MONTH, WEEK, OR DAY WISE)
// ==========================================
$viewType = strtolower(trim($_GET['view'] ?? 'month')); // 'month', 'week', 'day'

// -------------------------------------------------------------------
// 1. DAY-WISE ATTENDANCE VIEW
// -------------------------------------------------------------------
if ($viewType === 'day') {
    $targetDate = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        $targetDate = date('Y-m-d');
    }

    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
    $stmt->execute([$empId, $targetDate]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch daily work report for this date
    $repStmt = $pdo->prepare("SELECT id, status, tasks_completed, projects_worked, hours_worked FROM work_reports WHERE employee_id = ? AND report_date = ? LIMIT 1");
    $repStmt->execute([$empId, $targetDate]);
    $report = $repStmt->fetch(PDO::FETCH_ASSOC);

    $dateTs = strtotime($targetDate);
    $dayName = date('l', $dateTs);
    $formattedDate = date('M d, Y', $dateTs);

    $holidaysMap = get_active_holidays_map($pdo, (int)date('Y', $dateTs));
    $resolved = resolve_daily_attendance_status($pdo, $empId, $targetDate, $r, $holidaysMap);
    $metrics = calculate_working_hours_summary($pdo, $empId, $targetDate);

    json_success([
        'view_type'      => 'day',
        'date'           => $targetDate,
        'day_name'       => $dayName,
        'formatted_date' => $formattedDate,
        'is_today'       => ($targetDate === date('Y-m-d')),
        'is_sunday'      => $resolved['is_sunday'],
        'is_holiday'     => $resolved['is_holiday'],
        'holiday_name'   => $resolved['holiday_name'],
        'holiday_reason' => $resolved['holiday_reason'],
        'has_record'     => !empty($r),
        'record'         => [
            'id'             => $resolved['record_id'],
            'status'         => $resolved['status'],
            'status_raw'     => $resolved['status_raw'],
            'indicator'      => $resolved['indicator'],
            'punch_in'       => $resolved['check_in_formatted'],
            'punch_out'      => $resolved['check_out_formatted'],
            'check_in'       => $resolved['check_in_formatted'],
            'check_out'      => $resolved['check_out_formatted'],
            'total_hours'    => $resolved['hours_formatted'],
            'raw_hours'      => $resolved['total_hours'],
            'late_minutes'   => $resolved['late_minutes'],
            'late_duration'  => $resolved['late_formatted'],
            'lunch_start'    => $resolved['lunch_start'],
            'lunch_end'      => $resolved['lunch_end'],
            'lunch_seconds'  => $resolved['lunch_seconds'],
            'lunch_duration' => $resolved['lunch_duration_formatted'],
            'working_duration'=> $resolved['hours_formatted'],
            'difference_from_target' => $resolved['difference_formatted'],
            'leave_status'   => $resolved['leave_status'],
            'notes'          => $resolved['notes'],
            'distance_meters'=> $r['punch_distance_meters'] ?? null,
            'work_mode'      => $r['work_mode'] ?? 'Office'
        ],
        'working_hours_metrics' => $metrics,
        'has_work_report'=> !empty($report),
        'work_report'    => $report ? [
            'id'              => (int)$report['id'],
            'status'          => $report['status'] ?? 'submitted',
            'tasks_completed' => $report['tasks_completed'] ?? '',
            'projects_worked' => $report['projects_worked'] ?? '',
            'hours_worked'    => (float)($report['hours_worked'] ?? 0)
        ] : null
    ], 'Day attendance loaded');
}

// -------------------------------------------------------------------
// 2. WEEK-WISE ATTENDANCE VIEW
// -------------------------------------------------------------------
if ($viewType === 'week') {
    $refDate = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDate)) {
        $refDate = date('Y-m-d');
    }

    // Monday to Sunday of the reference week
    $refTs = strtotime($refDate);
    $weekStart = date('Y-m-d', strtotime('monday this week', $refTs));
    $weekEnd = date('Y-m-d', strtotime('sunday this week', $refTs));

    $holidaysMap = get_active_holidays_map($pdo, (int)date('Y', $refTs));
    $weekWorkingDays = get_working_days_count($weekStart, $weekEnd, $holidaysMap);
    $weekTargetMins = $weekWorkingDays * 420; // 7 hours per working day

    $wStmt = $pdo->prepare("
        SELECT * FROM attendance 
        WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? 
        ORDER BY attendance_date ASC
    ");
    $wStmt->execute([$empId, $weekStart, $weekEnd]);
    $records = $wStmt->fetchAll(PDO::FETCH_ASSOC);

    $recByDate = [];
    foreach ($records as $r) {
        $recByDate[$r['attendance_date']] = $r;
    }

    $days = [];
    $totalPres = 0;
    $totalAbs = 0;
    $totalLate = 0;
    $totalHalf = 0;
    $totalSec = 0;

    for ($d = 0; $d < 7; $d++) {
        $dayDate = date('Y-m-d', strtotime("$weekStart +$d days"));
        $dayTs = strtotime($dayDate);
        $r = $recByDate[$dayDate] ?? null;
        $resolved = resolve_daily_attendance_status($pdo, $empId, $dayDate, $r, $holidaysMap);

        $totalSec += $resolved['net_work_seconds'];
        if ($resolved['status_raw'] === 'present') $totalPres++;
        elseif ($resolved['status_raw'] === 'late') { $totalLate++; $totalPres++; }
        elseif ($resolved['status_raw'] === 'absent') $totalAbs++;
        elseif ($resolved['status_raw'] === 'half_day') { $totalHalf++; $totalPres += 0.5; }

        $days[] = [
            'date'           => $dayDate,
            'day_name'       => date('D', $dayTs),
            'day_number'     => date('d', $dayTs),
            'formatted_date' => date('M d', $dayTs),
            'status'         => $resolved['status'],
            'status_raw'     => $resolved['status_raw'],
            'indicator'      => $resolved['indicator'],
            'is_sunday'      => $resolved['is_sunday'],
            'is_holiday'     => $resolved['is_holiday'],
            'holiday_name'   => $resolved['holiday_name'],
            'punch_in'       => $resolved['check_in_formatted'],
            'punch_out'      => $resolved['check_out_formatted'],
            'check_in'       => $resolved['check_in_formatted'],
            'check_out'      => $resolved['check_out_formatted'],
            'lunch_start'    => $resolved['lunch_start'],
            'lunch_end'      => $resolved['lunch_end'],
            'lunch_duration' => $resolved['lunch_duration_formatted'],
            'lunch_seconds'  => $resolved['lunch_seconds'],
            'total_hours'    => $resolved['hours_formatted'],
            'working_duration'=> $resolved['hours_formatted'],
            'raw_hours'      => $resolved['total_hours'],
            'late_minutes'   => $resolved['late_minutes'],
            'late_duration'  => $resolved['late_formatted'],
            'difference_from_target' => $resolved['difference_formatted'],
            'leave_status'   => $resolved['leave_status'],
            'notes'          => $resolved['notes']
        ];
    }

    $totH = floor($totalSec / 3600);
    $totM = floor(($totalSec % 3600) / 60);
    $weekWorkedMins = (int)floor($totalSec / 60);
    $weekDiffMins = $weekWorkedMins - $weekTargetMins;

    json_success([
        'view_type'   => 'week',
        'start_date'  => $weekStart,
        'end_date'    => $weekEnd,
        'week_label'  => date('d M', strtotime($weekStart)) . ' - ' . date('d M Y', strtotime($weekEnd)),
        'summary'     => [
            'working_days'        => $weekWorkingDays,
            'days_in_week'        => 7,
            'target_minutes'      => $weekTargetMins,
            'target_hours'        => format_minutes_hm($weekTargetMins),
            'total_present'       => $totalPres,
            'present_days'        => $totalPres,
            'present_days_formatted' => "$totalPres / 7",
            'total_absent'        => $totalAbs,
            'late_arrivals'       => $totalLate,
            'half_days'           => $totalHalf,
            'total_hours'         => sprintf('%02dh %02dm', $totH, $totM),
            'raw_total_hours'     => round($totalSec / 3600, 1),
            'difference_minutes'  => $weekDiffMins,
            'difference_formatted'=> format_minute_difference($weekDiffMins),
            'extra_minutes'       => max(0, $weekDiffMins),
            'extra_formatted'     => format_minute_difference(max(0, $weekDiffMins)),
            'shortfall_minutes'   => max(0, -$weekDiffMins),
            'shortfall_formatted' => max(0, -$weekDiffMins) > 0 ? ("-" . format_minutes_hm(max(0, -$weekDiffMins))) : "0m"
        ],
        'days'        => $days
    ], 'Weekly attendance loaded');
}

// -------------------------------------------------------------------
// 3. MONTH-WISE ATTENDANCE VIEW (Default)
// -------------------------------------------------------------------
$month = (int)($_GET['month'] ?? date('m'));
$year = (int)($_GET['year'] ?? date('Y'));

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$monthStart = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
$monthEnd = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-$daysInMonth";

$holidaysMap = get_active_holidays_map($pdo, $year);
$monthWorkingDays = get_working_days_count($monthStart, $monthEnd, $holidaysMap);
$monthTargetMins = $monthWorkingDays * 420; // 7 hours per working day

$logsStmt = $pdo->prepare("
    SELECT * FROM attendance 
    WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
    ORDER BY attendance_date DESC
");
$logsStmt->execute([$empId, $monthStart, $monthEnd]);
$dbRecords = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

$recordsByDate = [];
foreach ($dbRecords as $r) {
    $recordsByDate[$r['attendance_date']] = $r;
}

$totalPresent = 0;
$totalAbsent = 0;
$totalLate = 0;
$totalHalfDay = 0;
$totalLeave = 0;
$totalHoliday = 0;
$totalSeconds = 0;
$totalLateSeconds = 0;

$logs = [];

// Loop through all days of the month from today (or end of month) down to day 1
$todayDateStr = date('Y-m-d');
$maxDay = ($year == (int)date('Y') && $month == (int)date('m')) ? (int)date('d') : $daysInMonth;for ($d = $maxDay; $d >= 1; $d--) {
    $dateStr = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-" . str_pad($d, 2, '0', STR_PAD_LEFT);
    $dateTs = strtotime($dateStr);
    $r = $recordsByDate[$dateStr] ?? null;
    $resolved = resolve_daily_attendance_status($pdo, $empId, $dateStr, $r, $holidaysMap);

    if ($resolved['status_raw'] === 'present') {
        $totalPresent++;
    } elseif ($resolved['status_raw'] === 'late') {
        $totalLate++;
        $totalPresent++;
    } elseif ($resolved['status_raw'] === 'absent') {
        $totalAbsent++;
    } elseif ($resolved['status_raw'] === 'half_day') {
        $totalHalfDay++;
    } elseif ($resolved['status_raw'] === 'on_leave') {
        $totalLeave++;
    } elseif ($resolved['status_raw'] === 'holiday') {
        $totalHoliday++;
    }

    $totalSeconds += $resolved['net_work_seconds'];
    $totalLateSeconds += ($resolved['late_minutes'] * 60);

    $logs[] = [
        'id'             => $resolved['record_id'],
        'date'           => date('M d', $dateTs),
        'day'            => date('D', $dateTs),
        'day_name'       => date('D', $dateTs),
        'full_date'      => $dateStr,
        'status'         => $resolved['status'],
        'status_raw'     => $resolved['status_raw'],
        'indicator'      => $resolved['indicator'],
        'is_sunday'      => $resolved['is_sunday'],
        'is_holiday'     => $resolved['is_holiday'],
        'holiday_name'   => $resolved['holiday_name'],
        'punch_in'       => $resolved['check_in_formatted'],
        'punch_out'      => $resolved['check_out_formatted'],
        'check_in'       => $resolved['check_in_formatted'],
        'check_out'      => $resolved['check_out_formatted'],
        'lunch_start'    => $resolved['lunch_start'],
        'lunch_end'      => $resolved['lunch_end'],
        'lunch_duration' => $resolved['lunch_duration_formatted'],
        'lunch_seconds'  => $resolved['lunch_seconds'],
        'break_seconds'  => $resolved['lunch_seconds'],
        'break_duration' => $resolved['lunch_duration_formatted'],
        'total_hours'    => $resolved['hours_formatted'],
        'work_duration'  => $resolved['hours_formatted'],
        'working_duration'=> $resolved['hours_formatted'],
        'raw_hours'      => $resolved['total_hours'],
        'late_minutes'   => $resolved['late_minutes'],
        'late_duration'  => $resolved['late_formatted'],
        'difference_from_target' => $resolved['difference_formatted'],
        'leave_status'   => $resolved['leave_status'],
        'notes'          => $resolved['notes'] ?? '',
        'remarks'        => $resolved['remarks'] ?? ($resolved['notes'] ?? ''),
    ];
}

$totHrs = floor($totalSeconds / 3600);
$totMins = floor(($totalSeconds % 3600) / 60);
$totalHoursFormatted = sprintf('%02dh %02dm', $totHrs, $totMins);
$monthWorkedMins = (int)floor($totalSeconds / 60);
$monthDiffMins = $monthWorkedMins - $monthTargetMins;

$monthName = date('F Y', mktime(0, 0, 0, $month, 10, $year));

// Overall working-hours metrics
$metrics = calculate_working_hours_summary($pdo, $empId, date('Y-m-d'));

json_success([
    'view_type'   => 'month',
    'month'       => $month,
    'year'        => $year,
    'month_label' => $monthName,
    'summary'     => [
        'working_days'        => $monthWorkingDays,
        'days_in_month'       => $daysInMonth,
        'target_minutes'      => $monthTargetMins,
        'target_hours'        => format_minutes_hm($monthTargetMins),
        'total_present'       => $totalPresent,
        'present_days'        => $totalPresent,
        'present_days_formatted' => "$totalPresent / $daysInMonth",
        'total_absent'        => $totalAbsent,
        'late_arrivals'       => $totalLate,
        'half_days'           => $totalHalfDay,
        'total_leaves'        => $totalLeave,
        'holidays'            => $totalHoliday,
        'holiday'             => $totalHoliday,
        'total_hours'         => $totalHoursFormatted,
        'raw_total_hours'     => round($totalSeconds / 3600, 1),
        'difference_minutes'  => $monthDiffMins,
        'difference_formatted'=> format_minute_difference($monthDiffMins),
        'extra_minutes'       => max(0, $monthDiffMins),
        'extra_formatted'     => format_minute_difference(max(0, $monthDiffMins)),
        'shortfall_minutes'   => max(0, -$monthDiffMins),
        'shortfall_formatted' => max(0, -$monthDiffMins) > 0 ? ("-" . format_minutes_hm(max(0, -$monthDiffMins))) : "0m",
        'progress_percentage' => $monthTargetMins > 0 ? min(1.0, round($monthWorkedMins / $monthTargetMins, 2)) : 1.0
    ],
    'working_hours_metrics' => $metrics,
    'logs'        => $logs
], 'Monthly attendance loaded');
