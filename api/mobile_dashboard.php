<?php
// Crm and backend/api/mobile_dashboard.php — Role-Adaptive Dynamic Dashboard API
require_once __DIR__ . '/mobile_helper.php';
require_once __DIR__ . '/../includes/ledger_helper.php';

$auth = authenticate_mobile();
$empId = $auth['employee_id'];
$userId = (int)$auth['user_id'];
$userRole = strtolower(trim($auth['user']['role'] ?? 'employee'));
$isAdminOrManager = in_array($userRole, ['admin', 'super admin', 'manager', 'finance manager', 'sales manager']);

$today = date('Y-m-d');
$currentMonth = (int)date('m');
$currentYear = (int)date('Y');

// Auto-process daily attendance rules (5 PM absent cutoff & 8 PM half-day cutoff)
if (function_exists('auto_process_daily_attendance')) {
    auto_process_daily_attendance($pdo, $today);
}

// ----------------------------------------------------
// 1. Employee / Personal Punch State (For all users who have employee ID)
// ----------------------------------------------------
$settingsStmt = $pdo->query("SELECT * FROM attendance_settings LIMIT 1");
$settings = $settingsStmt ? $settingsStmt->fetch(PDO::FETCH_ASSOC) : null;
$expectedIn = $settings ? date('h:i A', strtotime($settings['expected_check_in'])) : '10:00 AM';

$todayDayCheck = is_date_holiday_or_sunday($today);
$isTodayNonWorking = !empty($todayDayCheck['is_non_working']);
$isTodayWorkingOverride = !empty($todayDayCheck['is_working_sunday']) || (!empty($todayDayCheck['reason']) && stripos($todayDayCheck['reason'], 'working') !== false);
$todayHolidayName = $todayDayCheck['holiday_name'] ?: ($todayDayCheck['reason'] ?: 'Holiday / Weekly Off');

$todayAttendance = null;
if ($isTodayNonWorking) {
    $punchStatus = 'HOLIDAY';
    $punchNote = "Today is an Official Holiday / Weekly Off ({$todayHolidayName}). Attendance punch is closed.";
} elseif ($isTodayWorkingOverride) {
    $punchStatus = 'PENDING';
    $punchNote = "Scheduled Working Day: Admin has marked today as a normal working day (" . ($todayDayCheck['reason'] ?: 'Working Day') . "). You can mark attendance.";
} else {
    $punchStatus = 'PENDING';
    $punchNote = "You haven't clocked in yet today.";
}
$hasTodayWorkReport = false;

if ($empId) {
    $attStmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
    $attStmt->execute([$empId, $today]);
    $todayAttendance = $attStmt->fetch(PDO::FETCH_ASSOC);
}

$activeLunch = $empId ? get_active_lunch_session($pdo, $empId, $today) : null;
$isOnLunch = !empty($activeLunch);
$totalLunchSec = $empId ? get_today_lunch_duration_seconds($pdo, $empId, $today) : 0;

$netWorkSeconds = 0;
if ($todayAttendance) {
    $attStatus = strtolower($todayAttendance['status'] ?? '');
    if ($attStatus === 'absent') {
        $punchStatus = 'ABSENT';
        $punchNote = "Marked Absent for today. Working hours: 0h 00m.";
        $todayAttendance['check_in'] = null;
        $todayAttendance['check_out'] = null;
        $todayAttendance['total_hours'] = 0;
        $isOnLunch = false;
        $totalLunchSec = 0;
        $netWorkSeconds = 0;
    } elseif ($attStatus === 'on_leave') {
        $punchStatus = 'ON_LEAVE';
        $punchNote = "On Leave for today. Enjoy your day off!";
        $todayAttendance['check_in'] = null;
        $todayAttendance['check_out'] = null;
        $todayAttendance['total_hours'] = 0;
        $isOnLunch = false;
        $totalLunchSec = 0;
        $netWorkSeconds = 0;
    } elseif (!empty($todayAttendance['check_in']) && empty($todayAttendance['check_out'])) {
        $punchStatus = $isOnLunch ? 'ON_LUNCH' : 'CLOCKED_IN';
        $inTime = date('h:i A', strtotime($todayAttendance['check_in']));
        $punchNote = $isOnLunch ? "On Break since " . date('h:i A', strtotime($activeLunch['start_time'])) . "." : "Clocked in at {$inTime}.";
        $netWorkSeconds = max(0, strtotime(date('Y-m-d H:i:s')) - strtotime($todayAttendance['check_in']) - $totalLunchSec);
    } elseif (!empty($todayAttendance['check_out'])) {
        $punchStatus = 'COMPLETED';
        $outTime = date('h:i A', strtotime($todayAttendance['check_out']));
        $punchNote = "Clocked out at {$outTime} (" . format_minutes_hm((int)round(($todayAttendance['total_hours'] ?? 0) * 60)) . " worked).";
        $netWorkSeconds = (int)round(($todayAttendance['total_hours'] ?? 0) * 3600);
    }
}

require_once __DIR__ . '/../employee/profile_handler.php';

$todayRep = null;
if ($empId) {
    $wrStmt = $pdo->prepare("SELECT id, tasks_completed, projects_worked, hours_worked, created_at, status FROM work_reports WHERE employee_id = ? AND report_date = ? LIMIT 1");
    $wrStmt->execute([$empId, $today]);
    $repRow = $wrStmt->fetch(PDO::FETCH_ASSOC);
    if ($repRow) {
        $hasTodayWorkReport = true;
        $todayRep = [
            'id'              => (int)$repRow['id'],
            'tasks_completed' => $repRow['tasks_completed'] ?? '',
            'projects_worked' => $repRow['projects_worked'] ?? 'General',
            'hours_worked'    => (float)($repRow['hours_worked'] ?? 0),
            'submitted_at'    => date('h:i A', strtotime($repRow['created_at'])),
            'status'          => $repRow['status'] ?? 'submitted'
        ];
    }
}

// ----------------------------------------------------
// 2. Personal Monthly Attendance, Target Hours & Leave Metrics
// ----------------------------------------------------
$hoursSummary = $empId ? calculate_working_hours_summary($pdo, $empId, $today) : null;
$leaveSummary = $empId ? get_employee_annual_leave_summary($pdo, $empId, $currentYear) : null;
$leaveBalance = $leaveSummary ? $leaveSummary['remaining_allowed'] : 0;
$profileScore = calculate_profile_completion($auth['employee'] ?? []);

$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$presentDays = 0;
$lateDays = 0;
$absentDays = 0;
$halfDays = 0;
$totalLateMin = 0;
$totalWorkHours = 0.0;
$recentAttendance = [];

if ($empId) {
    $curYear  = (int)date('Y');
    $curMonth = (int)date('n');
    $attSummary = get_employee_month_attendance_summary($pdo, $empId, $curYear, $curMonth);

    $presentDays   = $attSummary['present_days'];
    $lateDays      = $attSummary['late_days'];
    $absentDays    = $attSummary['absent_days'];
    $halfDays      = $attSummary['half_days'];
    $totalLateMin  = $attSummary['total_late_mins'];
    $totalWorkHours= $attSummary['total_work_hours'];

    if (!empty($attSummary['daily_rows'])) {
        $todayStr = date('Y-m-d');
        $pastRows = array_filter($attSummary['daily_rows'], function($r) use ($todayStr) {
            return $r['date'] <= $todayStr;
        });
        $pastRows = array_reverse($pastRows);
        $slice = array_slice($pastRows, 0, 7);
        foreach ($slice as $r) {
            $recentAttendance[] = [
                'date'           => $r['date'],
                'formatted_date' => date('d M Y', strtotime($r['date'])),
                'day'            => $r['day'],
                'status'         => $r['status'],
                'status_raw'     => $r['status_raw'],
                'indicator'      => $r['indicator'],
                'check_in'       => !empty($r['check_in']) ? date('h:i:s A', strtotime($r['check_in'])) : '—',
                'check_out'      => !empty($r['check_out']) ? date('h:i:s A', strtotime($r['check_out'])) : '—',
                'break_duration' => $r['break_seconds'] > 0 ? $r['break_duration'] : '—',
                'work_hours'     => $r['total_hours'] > 0 ? (format_hours_hms($r['total_hours'], true) . ' (' . number_format($r['total_hours'], 1) . 'h)') : '—',
                'late_duration'  => $r['late_minutes'] > 0 ? format_minutes_hms($r['late_minutes'], true) : '—',
                'remarks'        => $r['remarks'] ?? '',
            ];
        }
    }
}


// ----------------------------------------------------
// 3. Personal Recent Salary Slip
// ----------------------------------------------------
$recentSalary = null;
if ($empId) {
    try {
        $sStmt = $pdo->prepare("
            SELECT * FROM payroll 
            WHERE employee_id = ? 
            ORDER BY payroll_year DESC, payroll_month DESC 
            LIMIT 1
        ");
        $sStmt->execute([$empId]);
        $pay = $sStmt->fetch(PDO::FETCH_ASSOC);
        if ($pay) {
            $monthNum = (int)$pay['payroll_month'];
            $monthName = date('F', mktime(0, 0, 0, $monthNum, 10));
            $recentSalary = [
                'id'                   => (int)$pay['id'],
                'month_name'           => $monthName . ' ' . $pay['payroll_year'],
                'title'                => $monthName . ' Salary',
                'processed_date'       => 'Processed on ' . date('M jS', strtotime($pay['created_at'] ?? 'now')),
                'net_salary'           => (float)$pay['net_salary'],
                'net_salary_formatted' => '₹' . number_format((float)$pay['net_salary'], 2),
                'status'               => $pay['status']
            ];
        }
    } catch (\Throwable $e) {}
}

$employeeName = $auth['employee']['full_name'] ?? $auth['user']['name'];
$firstName = explode(' ', trim($employeeName))[0];
$department = $auth['employee']['department'] ?? 'General';
if (empty($department)) $department = 'General';

// ----------------------------------------------------
// 4. ROLE-SPECIFIC DATA: EXECUTIVE / ADMIN / MANAGER
// ----------------------------------------------------
$adminData = null;

if ($isAdminOrManager) {
    // 4.1 Date Range Filtering
    $range = $_GET['range'] ?? 'this_month';
    $startDate = null;
    $endDate = null;

    switch ($range) {
        case 'today':
            $startDate = date('Y-m-d');
            $endDate = date('Y-m-d');
            break;
        case 'yesterday':
            $startDate = date('Y-m-d', strtotime('-1 day'));
            $endDate = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'this_week':
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'this_month':
            $startDate = date('Y-m-01');
            $endDate = date('Y-m-t');
            break;
        case 'last_month':
            $startDate = date('Y-m-01', strtotime('first day of last month'));
            $endDate = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'last_3_months':
            $startDate = date('Y-m-01', strtotime('-2 months'));
            $endDate = date('Y-m-t');
            break;
        case 'this_year':
            $startDate = date('Y-01-01');
            $endDate = date('Y-12-31');
            break;
        case 'custom':
            $rawFrom = $_GET['from'] ?? ($_GET['start_date'] ?? '');
            $rawTo = $_GET['to'] ?? ($_GET['end_date'] ?? '');
            if (!empty($rawFrom) && !empty($rawTo)) {
                if ($rawFrom <= $rawTo) {
                    $startDate = $rawFrom;
                    $endDate = $rawTo;
                } else {
                    $startDate = $rawTo;
                    $endDate = $rawFrom;
                }
            } elseif (!empty($rawFrom)) {
                $startDate = $rawFrom;
                $endDate = date('Y-m-t', strtotime($rawFrom));
            } else {
                $startDate = date('Y-m-01');
                $endDate = date('Y-m-t');
            }
            break;
        case 'all':
        default:
            $range = 'all';
            $startDate = null;
            $endDate = null;
            break;
    }

    // 4.2 Centralized Financial Metrics (from ledger_helper.php)
    $financialSummary = get_financial_summary($startDate, $endDate);

    // 4.3 Attention Alerts
    // Overdue Invoices
    $overdueStmt = $pdo->query("
        SELECT i.id, i.invoice_no, i.total_amount, i.amount_due, i.due_date, c.name AS client_name
        FROM invoices i
        JOIN customers c ON c.id = i.customer_id
        WHERE i.status != 'paid' AND i.status != 'cancelled'
          AND ((i.due_date IS NOT NULL AND i.due_date < CURDATE()) OR i.status = 'overdue')
        ORDER BY i.due_date ASC
        LIMIT 5
    ");
    $overdueList = $overdueStmt ? $overdueStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $overdueCount = (int)($pdo->query("SELECT COUNT(*) FROM invoices WHERE status NOT IN ('paid','cancelled') AND due_date < CURDATE()")->fetchColumn() ?: 0);

    // Pending Salaries
    $pendingSalStmt = $pdo->query("
        SELECT emp.id, emp.full_name, emp.base_salary, emp.employee_code
        FROM employees emp
        LEFT JOIN payroll p ON p.employee_id = emp.id AND p.payroll_month = MONTH(CURDATE()) AND p.payroll_year = YEAR(CURDATE())
        WHERE emp.status = 'active' AND (p.id IS NULL OR p.status != 'Paid')
        LIMIT 5
    ");
    $pendingSalList = $pendingSalStmt ? $pendingSalStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $pendingSalCount = (int)($pdo->query("
        SELECT COUNT(*) FROM employees 
        WHERE status = 'active' AND id NOT IN (
            SELECT employee_id FROM payroll WHERE payroll_month = MONTH(CURDATE()) AND payroll_year = YEAR(CURDATE()) AND status = 'Paid'
        )
    ")->fetchColumn() ?: 0);

    // Pending Monthly Billing Invoices
    $pendingMonthlyCount = (int)($pdo->query("
        SELECT COUNT(*) FROM customers 
        WHERE client_type = 'monthly' AND status = 'active' AND auto_invoice = 1 AND id NOT IN (
            SELECT customer_id FROM billing_cycles WHERE billing_month = MONTH(CURDATE()) AND billing_year = YEAR(CURDATE())
        )
    ")->fetchColumn() ?: 0);

    // 4.4 Recent Transactions Feed (Unified payments, invoices, expenses, payroll)
    $txWhereP = "p.status = 'Completed'";
    $txWhereI = "i.status != 'cancelled'";
    $txWhereE = "ex.status = 'Paid'";
    $txWherePr = "pr.status = 'Paid' AND COALESCE(NULLIF(pr.amount_paid, 0), pr.net_salary) > 0";
    $txParams = [];

    if ($startDate && $endDate) {
        $txWhereP .= " AND COALESCE(p.payment_date, DATE(p.created_at)) BETWEEN ? AND ?";
        $txWhereI .= " AND COALESCE(i.invoice_date, DATE(i.created_at)) BETWEEN ? AND ?";
        $txWhereE .= " AND COALESCE(ex.expense_date, DATE(ex.created_at)) BETWEEN ? AND ?";
        $txWherePr .= " AND STR_TO_DATE(CONCAT(pr.payroll_year, '-', LPAD(pr.payroll_month, 2, '00'), '-01'), '%Y-%m-%d') <= ? AND LAST_DAY(STR_TO_DATE(CONCAT(pr.payroll_year, '-', LPAD(pr.payroll_month, 2, '00'), '-01'), '%Y-%m-%d')) >= ?";
        $txParams = [$startDate, $endDate, $startDate, $endDate, $startDate, $endDate, $endDate, $startDate];
    }

    $recentTxSql = "
        (SELECT 'Client Payment' AS type, COALESCE(p.payment_date, DATE(p.created_at)) AS tx_date, c.name AS party, p.payment_no AS ref, p.amount, p.payment_method, 'Received' AS tx_status, p.id, 'payment' AS tag
         FROM payments p
         JOIN customers c ON c.id = p.customer_id
         WHERE $txWhereP)
        UNION ALL
        (SELECT 'Invoice Billed' AS type, COALESCE(i.invoice_date, DATE(i.created_at)) AS tx_date, c.name AS party, i.invoice_no AS ref, i.total_amount AS amount, i.payment_mode AS payment_method, i.status AS tx_status, i.id, 'invoice' AS tag
         FROM invoices i
         JOIN customers c ON c.id = i.customer_id
         WHERE $txWhereI)
        UNION ALL
        (SELECT 'Expense' AS type, COALESCE(ex.expense_date, DATE(ex.created_at)) AS tx_date, ex.category AS party, COALESCE(ex.vendor, ex.description) AS ref, ex.amount, ex.payment_mode AS payment_method, ex.status AS tx_status, ex.id, 'expense' AS tag
         FROM expenses ex
         WHERE $txWhereE)
        UNION ALL
        (SELECT 'Team Payroll' AS type, COALESCE(pr.payment_date, STR_TO_DATE(CONCAT(pr.payroll_year, '-', LPAD(pr.payroll_month, 2, '00'), '-01'), '%Y-%m-%d')) AS tx_date, emp.full_name AS party, CONCAT('Salary ', pr.payroll_month, '/', pr.payroll_year) AS ref, COALESCE(NULLIF(pr.amount_paid, 0), pr.net_salary) AS amount, pr.payment_method, 'Paid' AS tx_status, pr.id, 'payroll' AS tag
         FROM payroll pr
         JOIN employees emp ON emp.id = pr.employee_id
         WHERE $txWherePr)
        ORDER BY tx_date DESC, id DESC
        LIMIT 10
    ";
    $recentTxStmt = $pdo->prepare($recentTxSql);
    $recentTxStmt->execute($txParams);
    $recentTransactions = $recentTxStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4.5 Active Clients & Counts
    $activeClientsCount = (int)($pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'active'")->fetchColumn() ?: 0);
    $totalEmployeesCount = (int)($pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn() ?: 0);

    $adminData = [
        'range'                  => $range,
        'metrics'                => [
            'total_revenue'      => (float)$financialSummary['total_revenue'],
            'total_received'     => (float)$financialSummary['total_received'],
            'total_pending'      => (float)$financialSummary['total_pending'],
            'total_expenses'     => (float)$financialSummary['total_expenses'],
            'team_payroll'       => (float)$financialSummary['team_payroll'],
            'net_profit'         => (float)$financialSummary['net_profit'],
            'profit_margin'      => (float)$financialSummary['profit_margin'],
            'active_clients'     => $activeClientsCount,
            'active_employees'   => $totalEmployeesCount,
        ],
        'attention'              => [
            'overdue_count'         => $overdueCount,
            'overdue_invoices'      => $overdueList,
            'pending_sal_count'     => $pendingSalCount,
            'pending_salaries'      => $pendingSalList,
            'pending_monthly_count' => $pendingMonthlyCount,
        ],
        'recent_transactions'    => $recentTransactions,
    ];
}

// Calculate net work seconds today
$netWorkSeconds = 0;
if ($todayAttendance && !empty($todayAttendance['check_in'])) {
    $checkInTs = strtotime($todayAttendance['check_in']);
    if (!empty($todayAttendance['check_out'])) {
        $checkOutTs = strtotime($todayAttendance['check_out']);
        $rawSec = max(0, $checkOutTs - $checkInTs);
    } else {
        $rawSec = max(0, time() - $checkInTs);
    }
    $netWorkSeconds = max(0, $rawSec - $totalLunchSec);
}

$geofence = [
    'enabled'                => !empty($settings['geofence_enabled']),
    'office_latitude'        => (float)($settings['office_latitude'] ?? 29.22006923),
    'office_longitude'       => (float)($settings['office_longitude'] ?? 78.96778362),
    'geofence_radius_meters' => (int)($settings['geofence_radius_meters'] ?? 200),
    'office_address'         => $settings['office_address'] ?? 'Vibesoft, Chamunda Vihar Phase 1, Ramnagar Road, Kashipur, Uttarakhand 244713'
];

json_success([
    'role'             => $auth['user']['role'] ?? 'Employee',
    'is_admin'         => $isAdminOrManager,
    'employee' => [
        'id'            => $empId,
        'name'          => $employeeName,
        'first_name'    => $firstName,
        'email'         => $auth['employee']['email'] ?? ($auth['user']['email'] ?? ''),
        'department'    => $department,
        'designation'   => $auth['employee']['designation'] ?? ($auth['user']['role'] ?? 'Staff'),
        'employee_code' => $auth['employee']['employee_code'] ?? ('EMP-' . ($empId ?: $userId)),
        'profile_photo' => resolve_media_url($auth['employee']['profile_photo'] ?? null, $employeeName),
    ],
    'header' => [
        'greeting' => 'Good ' . (date('H') < 12 ? 'Morning' : (date('H') < 17 ? 'Afternoon' : 'Evening')) . ', ' . $firstName,
        'subtitle' => $isAdminOrManager ? 'Executive Business Control Center | ' . date('l, M j') : ($department . ' Department | ' . date('l, M j')),
    ],
    'profile_completion' => $profileScore,
    'geofence'           => $geofence,
    'today_attendance'   => [
        'status'                 => $punchStatus,
        'is_on_lunch'            => $isOnLunch,
        'expected_in'            => $expectedIn,
        'check_in'               => $todayAttendance['check_in'] ?? null,
        'check_out'              => $todayAttendance['check_out'] ?? null,
        'note'                   => $punchNote,
        'can_clock_in'           => (!$isTodayNonWorking && $punchStatus === 'PENDING'),
        'can_clock_out'          => ($punchStatus === 'CLOCKED_IN' || $punchStatus === 'ON_LUNCH'),
        'can_start_lunch'        => ($punchStatus === 'CLOCKED_IN'),
        'can_end_lunch'          => $isOnLunch,
        'can_mark_attendance'    => !$isTodayNonWorking,
        'is_non_working'         => $isTodayNonWorking,
        'is_holiday'             => !empty($todayDayCheck['is_holiday']),
        'is_sunday'              => !empty($todayDayCheck['is_sunday']),
        'is_working_override'    => $isTodayWorkingOverride,
        'holiday_name'           => $todayHolidayName,
        'holiday_reason'         => $todayDayCheck['reason'] ?? '',
        'day_type'               => $isTodayNonWorking ? 'holiday' : ($isTodayWorkingOverride ? 'working_override' : 'working_day'),
        'has_work_report'        => $hasTodayWorkReport,
        'net_work_seconds'       => $netWorkSeconds,
        'net_work_duration'      => format_minutes_hm((int)round($netWorkSeconds / 60)),
        'late_minutes'           => (int)($todayAttendance['late_minutes'] ?? 0),
        'late_duration'          => format_minutes_hm((int)($todayAttendance['late_minutes'] ?? 0)),
        'lunch_seconds'          => $totalLunchSec,
        'lunch_duration_formatted'=> format_minutes_hm((int)round($totalLunchSec / 60)),
        'lunch_start'            => !empty($hoursSummary['lunch_start']) ? $hoursSummary['lunch_start'] : ($activeLunch ? date('h:i A', strtotime($activeLunch['start_time'])) : null),
        'lunch_end'              => !empty($hoursSummary['lunch_end']) ? $hoursSummary['lunch_end'] : null,
        'lunch_duration'         => $hoursSummary['lunch_formatted'] ?? format_minutes_hm((int)round($totalLunchSec / 60)),
    ],
    'today_work_report'  => $todayRep,
    'lunch_state' => [
        'is_on_lunch'            => $isOnLunch,
        'start_time'             => $activeLunch ? date('h:i A', strtotime($activeLunch['start_time'])) : null,
        'duration_seconds'       => $totalLunchSec,
        'duration_formatted'     => format_minutes_hm((int)round($totalLunchSec / 60))
    ],
    'working_hours_summary' => $hoursSummary,
    'leave_summary'         => $leaveSummary,
    'metrics' => [
        'present_days'        => $presentDays,
        'total_month_days'    => (int)date('t'),
        'present_days_formatted' => $presentDays . ' / ' . (int)date('t'),
        'late_days'           => $lateDays,
        'total_late_min'      => $totalLateMin,
        'late_duration'       => format_minutes_hm($totalLateMin),
        'work_hours'          => ($totalWorkHours . 'h'),
        'work_hours_formatted'=> format_hours_hms($totalWorkHours, true),
        'leave_balance'       => $leaveBalance,
        'extra_leaves'        => $leaveSummary ? $leaveSummary['extra_leaves'] : 0,
        'allowed_leaves'      => $leaveSummary ? $leaveSummary['allowed_leaves'] : 4,
        'used_leave'          => $leaveSummary ? $leaveSummary['total_taken'] : 0,
    ],
    'recent_attendance' => $recentAttendance,
    'recent_salary'     => $recentSalary,
    'admin_data'        => $adminData,
], 'Dashboard data loaded successfully');
