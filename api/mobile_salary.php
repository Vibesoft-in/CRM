<?php
// Crm and backend/api/mobile_salary.php
require_once __DIR__ . '/mobile_helper.php';

$auth = authenticate_mobile();
$empId = $auth['employee_id'];
if (!$empId) {
    json_error('No linked employee profile found for this account', 400);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'overview');

$token = '';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
}
if (empty($token)) {
    $token = $_SERVER['HTTP_X_API_KEY'] ?? ($auth['user']['api_key'] ?? '');
}

// ----------------------------------------------------
// ACTION: OVERVIEW (MONTHLY BREAKDOWN & TREND)
// ----------------------------------------------------
if ($action === 'overview') {
    // Determine target month and year
    $reqMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
    $reqYear  = isset($_GET['year']) ? (int)$_GET['year'] : 0;

    if ($reqMonth <= 0 || $reqYear <= 0) {
        // Default to latest finalized payroll for this employee, or current month
        $latStmt = $pdo->prepare("SELECT payroll_month, payroll_year FROM payroll WHERE employee_id = ? ORDER BY payroll_year DESC, payroll_month DESC LIMIT 1");
        $latStmt->execute([$empId]);
        $lat = $latStmt->fetch(PDO::FETCH_ASSOC);
        if ($lat) {
            $month = (int)$lat['payroll_month'];
            $year  = (int)$lat['payroll_year'];
        } else {
            $month = (int)date('m');
            $year  = (int)date('Y');
        }
    } else {
        $month = $reqMonth;
        $year  = $reqYear;
    }

    $monthName = date('F Y', mktime(0, 0, 0, $month, 10, $year));

    // Fetch payroll record
    $pStmt = $pdo->prepare("SELECT * FROM payroll WHERE employee_id = ? AND payroll_month = ? AND payroll_year = ? LIMIT 1");
    $pStmt->execute([$empId, $month, $year]);
    $pay = $pStmt->fetch(PDO::FETCH_ASSOC);

    // Fetch employee details
    $eStmt = $pdo->prepare("
        SELECT e.*, d.name as dept_title, des.name as desig_title 
        FROM employees e 
        LEFT JOIN departments d ON d.id = e.department_id 
        LEFT JOIN designations des ON des.id = e.designation_id 
        WHERE e.id = ?
    ");
    $eStmt->execute([$empId]);
    $empDetails = $eStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $baseSalary = (float)($empDetails['base_salary'] ?? 0);
    $salaryType = $empDetails['salary_type'] ?? 'Monthly';
    $employeeType = $empDetails['employee_type'] ?? 'Full Time';

    // Fetch attendance stats for this month
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $mStart = sprintf('%04d-%02d-01', $year, $month);
    $mEnd   = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
    $attStmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
            SUM(late_minutes) as total_late_min,
            SUM(CASE WHEN status NOT IN ('absent', 'on_leave') THEN total_hours ELSE 0 END) as total_work_hours
        FROM attendance 
        WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
    ");
    $attStmt->execute([$empId, $mStart, $mEnd]);
    $attStats = $attStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $presentDays = (int)($attStats['present_days'] ?? 0);
    $lateDays    = (int)($attStats['late_days'] ?? 0);
    $absentDays  = (int)($attStats['absent_days'] ?? 0);
    $totalHours  = round((float)($attStats['total_work_hours'] ?? 0), 1);

    // Build earnings and deductions breakdown
    $earningsList = [];
    $deductionsList = [];
    $grossEarnings = 0.0;
    $totalDeductions = 0.0;
    $netPay = 0.0;

    if ($pay) {
        $netPay = (float)$pay['net_salary'];

        // Check custom itemized payroll items
        $itemStmt = $pdo->prepare("SELECT * FROM payroll_items WHERE payroll_id = ? ORDER BY id ASC");
        $itemStmt->execute([$pay['id']]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($items)) {
            foreach ($items as $it) {
                $amt = (float)$it['amount'];
                if ($it['type'] === 'earning') {
                    $grossEarnings += $amt;
                    $earningsList[] = ['head' => $it['name'], 'title' => $it['name'], 'amount' => $amt, 'formatted' => number_format($amt, 2)];
                } else {
                    $totalDeductions += $amt;
                    $deductionsList[] = ['head' => $it['name'], 'title' => $it['name'], 'amount' => $amt, 'formatted' => number_format($amt, 2)];
                }
            }
        } else {
            $base = (float)$pay['base_salary'];
            $allow = (float)$pay['allowances'];
            $bonus = (float)$pay['bonus'];
            $grossEarnings = $base + $allow + $bonus;

            $earningsList[] = ['head' => 'Basic Salary', 'title' => 'Basic Salary', 'amount' => $base, 'formatted' => number_format($base, 2)];
            if ($allow > 0) {
                $earningsList[] = ['head' => 'House Rent Allowance (HRA)', 'title' => 'House Rent Allowance (HRA)', 'amount' => $allow, 'formatted' => number_format($allow, 2)];
            }
            if ($bonus > 0) {
                $earningsList[] = ['head' => 'Bonus / Incentives', 'title' => 'Bonus / Incentives', 'amount' => $bonus, 'formatted' => number_format($bonus, 2)];
            }

            $adv = (float)$pay['advance_deducted'];
            $late = (float)$pay['late_deduction'];
            $other = (float)$pay['other_deduction'];
            $totalDeductions = $adv + $late + $other;

            if ($adv > 0) {
                $deductionsList[] = ['head' => 'Advance Salary Deducted', 'title' => 'Advance Salary Deducted', 'amount' => $adv, 'formatted' => number_format($adv, 2)];
            }
            if ($late > 0) {
                $lateLabel = $lateDays > 0 ? "Late Deduction ($lateDays Times)" : "Late Arrival Deduction";
                $deductionsList[] = ['head' => $lateLabel, 'title' => $lateLabel, 'amount' => $late, 'formatted' => number_format($late, 2)];
            }
            if ($other > 0) {
                $absentLabel = $absentDays > 0 ? "Absent Cut ($absentDays Days)" : "Absence / Leave Deductions";
                $deductionsList[] = ['head' => $absentLabel, 'title' => $absentLabel, 'amount' => $other, 'formatted' => number_format($other, 2)];
            }
        }
    } else {
        // Projected preview based on employee base salary
        $netPay = $baseSalary;
        $grossEarnings = $baseSalary;
        if ($baseSalary > 0) {
            $earningsList[] = ['head' => 'Basic Salary', 'title' => 'Basic Salary', 'amount' => $baseSalary, 'formatted' => number_format($baseSalary, 2)];
        }
    }

    // Historical 6 months list for History section and Month Picker
    $allPayStmt = $pdo->prepare("
        SELECT id, payroll_month, payroll_year, net_salary, amount_paid, status, payment_date 
        FROM payroll 
        WHERE employee_id = ? 
        ORDER BY payroll_year DESC, payroll_month DESC 
        LIMIT 12
    ");
    $allPayStmt->execute([$empId]);
    $historyRows = $allPayStmt->fetchAll(PDO::FETCH_ASSOC);

    $historyList = [];
    $totalPaid = 0.0;
    $totalPending = 0.0;

    foreach ($historyRows as $hr) {
        $hrM = (int)$hr['payroll_month'];
        $hrY = (int)$hr['payroll_year'];
        $hrLabel = date('F Y', mktime(0, 0, 0, $hrM, 10, $hrY));
        $hrNet = (float)$hr['net_salary'];
        $hrStatus = ucfirst(strtolower($hr['status'] ?? 'Paid'));

        if (strtolower($hrStatus) === 'paid') {
            $totalPaid += $hrNet;
        } else {
            $paid = (float)($hr['amount_paid'] ?? 0);
            $totalPaid += $paid;
            $totalPending += max(0, $hrNet - $paid);
        }

        $historyList[] = [
            'id'             => (int)$hr['id'],
            'month'          => $hrM,
            'year'           => $hrY,
            'month_label'    => $hrLabel,
            'status'         => $hrStatus,
            'amount'         => $hrNet,
            'formatted'      => '₹' . number_format($hrNet, 2),
            'payment_date'   => !empty($hr['payment_date']) ? date('d M Y', strtotime($hr['payment_date'])) : '—',
        ];
    }

    // Available months list for picker (using day 1 to avoid 29-31 date overflow)
    $availableMonths = [];
    $curM = (int)date('n');
    $curY = (int)date('Y');
    for ($i = 0; $i < 12; $i++) {
        $mTime = mktime(0, 0, 0, $curM - $i, 1, $curY);
        $am = (int)date('n', $mTime);
        $ay = (int)date('Y', $mTime);
        $availableMonths[] = [
            'month'       => $am,
            'year'        => $ay,
            'month_label' => date('F Y', $mTime),
            'is_selected' => ($am === $month && $ay === $year),
        ];
    }

    // Calculate upcoming payment (next month)
    $nextMonthTime = strtotime("+1 month", mktime(0, 0, 0, $month, 1, $year));
    $nextMonthName = date('F Y', $nextMonthTime);
    $nextPayDate   = "5 " . date('M Y', $nextMonthTime);
    $upcomingAmount = $grossEarnings > 0 ? $grossEarnings : $baseSalary;

    // Convert net salary to words
    $netWords = function_exists('convert_number_to_words') ? convert_number_to_words($netPay) : ('Rupees ' . number_format($netPay, 2));

    // Company Settings for Payslip
    $settings = $pdo->query("SELECT company_name, phone, email, address, website FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $companyName = $settings['company_name'] ?? 'VibeSoft';
    $companyWebsite = $settings['website'] ?? 'https://vibesoft.in';
    $companyEmail = $settings['email'] ?? 'contact.vibesoft@gmail.com';

    $downloadUrl = BASE_URL . 'api/mobile_salary_download.php?' . ($pay ? ('id=' . $pay['id']) : ('month=' . $month . '&year=' . $year)) . '&token=' . urlencode($token);

    json_success([
        'payroll_id'               => $pay ? (int)$pay['id'] : 0,
        'payroll_status'           => $pay ? strtoupper($pay['status'] ?? 'PROCESSED') : 'ESTIMATED',
        'selected_month'           => $month,
        'selected_year'            => $year,
        'month_label'              => $monthName,
        'available_months'         => $availableMonths,
        'gross_earnings'           => $grossEarnings,
        'gross_earnings_formatted' => '₹' . number_format($grossEarnings, 2),
        'total_deductions'         => $totalDeductions,
        'total_deductions_formatted' => '₹' . number_format($totalDeductions, 2),
        'net_pay'                  => $netPay,
        'net_pay_formatted'        => '₹' . number_format($netPay, 2),
        'net_pay_in_words'         => ucwords($netWords) . ' Only',
        'earnings'                 => $earningsList,
        'deductions'               => $deductionsList,
        'download_url'             => $downloadUrl,
        'cards'                    => [
            'base_salary'             => $baseSalary,
            'base_salary_formatted'   => '₹' . number_format($baseSalary, 2),
            'salary_type'             => $salaryType,
            'employee_type'           => $employeeType,
            'total_paid'              => $totalPaid,
            'total_paid_formatted'    => '₹' . number_format($totalPaid, 2),
            'total_pending'           => $totalPending,
            'total_pending_formatted' => '₹' . number_format($totalPending, 2),
            'pending_status'          => ($totalPending > 0 ? 'Awaiting Clearance' : '✓ All Dues Cleared'),
        ],
        'upcoming_payment'         => [
            'month'                   => $nextMonthName,
            'status'                  => 'On Time',
            'expected_date'           => "Expected Payment Date: $nextPayDate",
            'date_raw'                => $nextPayDate,
            'amount'                  => $upcomingAmount,
            'amount_formatted'        => '₹' . number_format($upcomingAmount, 2),
        ],
        'history'                  => $historyList,
        'attendance_summary'       => [
            'total_days'              => $daysInMonth,
            'present_days'            => $presentDays,
            'late_days'               => $lateDays,
            'absent_days'             => $absentDays,
            'work_hours'              => $totalHours,
            'summary_text'            => "$presentDays Present · $lateDays Late",
        ],
        'slip_data'                => [
            'id'                      => $pay ? (int)$pay['id'] : 0,
            'month'                   => $monthName,
            'status'                  => $pay ? strtoupper($pay['status'] ?? 'PAID') : 'PAID',
            'payment_date'            => !empty($pay['payment_date']) ? date('d M Y', strtotime($pay['payment_date'])) : date('d M Y'),
            'company_name'            => $companyName,
            'company_website'         => $companyWebsite,
            'company_email'           => $companyEmail,
            'employee_name'           => $empDetails['full_name'] ?? 'Employee',
            'employee_code'           => $empDetails['employee_code'] ?? ('EMP' . ($empDetails['id'] ?? '102')),
            'department'              => $empDetails['dept_title'] ?? ($empDetails['department'] ?? 'Development'),
            'designation'             => $empDetails['desig_title'] ?? ($empDetails['designation'] ?? 'Developer'),
            'joining_date'            => !empty($empDetails['joining_date']) ? date('d M Y', strtotime($empDetails['joining_date'])) : '—',
            'bank_name'               => $empDetails['bank_name'] ?? '',
            'account_no'              => !empty($empDetails['bank_acc_no']) ? $empDetails['bank_acc_no'] : 'On File',
            'ifsc_code'               => $empDetails['bank_ifsc'] ?? '',
            'pan'                     => $empDetails['pan'] ?? '',
            'attendance_line'         => "$presentDays Present · $lateDays Late",
            'gross_earnings_formatted'=> '₹' . number_format($grossEarnings, 2),
            'total_deductions_formatted' => '₹' . number_format($totalDeductions, 2),
            'net_pay_formatted'       => '₹' . number_format($netPay, 2),
            'net_pay_words'           => ucwords($netWords) . ' Only',
        ]
    ], 'Salary overview loaded');
}


// ----------------------------------------------------
// ACTION: SLIPS (LIST OF PROCESSED PAYSLIPS)
// ----------------------------------------------------
if ($action === 'slips') {
    $stmt = $pdo->prepare("
        SELECT * FROM payroll 
        WHERE employee_id = ? 
        ORDER BY payroll_year DESC, payroll_month DESC 
        LIMIT 12
    ");
    $stmt->execute([$empId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $token = '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
    }
    if (empty($token)) {
        $token = $_SERVER['HTTP_X_API_KEY'] ?? ($auth['user']['api_key'] ?? '');
    }
    $slips = [];
    foreach ($rows as $r) {
        $mNum = (int)$r['payroll_month'];
        $mName = date('F', mktime(0, 0, 0, $mNum, 10)) . ' ' . $r['payroll_year'];
        $net = (float)$r['net_salary'];
        $slips[] = [
            'id'                => (int)$r['id'],
            'month'             => $mName,
            'status'            => strtoupper($r['status'] ?? 'PROCESSED'),
            'net_pay'           => $net,
            'net_pay_formatted' => '₹' . number_format($net, 2),
            'download_url'      => BASE_URL . 'api/mobile_salary_download.php?id=' . $r['id'] . '&token=' . urlencode($token),
            'base_salary'       => (float)($r['base_salary'] ?? 0),
            'base_salary_formatted' => '₹' . number_format((float)($r['base_salary'] ?? 0), 2),
            'allowances'        => (float)($r['allowances'] ?? 0),
            'allowances_formatted' => '₹' . number_format((float)($r['allowances'] ?? 0), 2),
            'bonus'             => (float)($r['bonus'] ?? 0),
            'bonus_formatted'   => '₹' . number_format((float)($r['bonus'] ?? 0), 2),
            'advance_deducted'  => (float)($r['advance_deducted'] ?? 0),
            'advance_formatted' => '-₹' . number_format((float)($r['advance_deducted'] ?? 0), 2),
            'late_deduction'    => (float)($r['late_deduction'] ?? 0),
            'late_formatted'    => '-₹' . number_format((float)($r['late_deduction'] ?? 0), 2),
            'other_deduction'   => (float)($r['other_deduction'] ?? 0),
            'other_formatted'   => '-₹' . number_format((float)($r['other_deduction'] ?? 0), 2),
        ];
    }

    json_success([
        'slips' => $slips
    ], 'Salary slips loaded');
}

// ----------------------------------------------------
// ACTION: SLIP DETAIL (FULL ITEMIZED BREAKDOWN)
// ----------------------------------------------------
if ($action === 'slip_detail') {
    $payrollId = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
    if ($payrollId <= 0) json_error('Salary slip ID is required', 400);

    $pStmt = $pdo->prepare("SELECT * FROM payroll WHERE id = ? LIMIT 1");
    $pStmt->execute([$payrollId]);
    $pay = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$pay) json_error('Salary slip not found', 404);

    $isAdmin = in_array(strtolower($auth['role'] ?? ''), ['admin', 'super admin', 'superadmin']);
    if (!$isAdmin && (int)$pay['employee_id'] !== $empId) {
        json_error('Access Denied: You are not authorized to view this salary slip.', 403);
    }

    $m = (int)$pay['payroll_month'];
    $y = (int)$pay['payroll_year'];
    $monthName = date('F Y', mktime(0, 0, 0, $m, 10, $y));

    // Fetch Employee Details
    $eStmt = $pdo->prepare("
        SELECT e.*, d.name as dept_title, des.name as desig_title 
        FROM employees e 
        LEFT JOIN departments d ON d.id = e.department_id 
        LEFT JOIN designations des ON des.id = e.designation_id 
        WHERE e.id = ?
    ");
    $eStmt->execute([$pay['employee_id']]);
    $empDetails = $eStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Fetch Attendance Stats
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $m, $y);
    $mStart = sprintf('%04d-%02d-01', $y, $m);
    $mEnd   = sprintf('%04d-%02d-%02d', $y, $m, $daysInMonth);
    $attStmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
            SUM(late_minutes) as total_late_min,
            SUM(CASE WHEN status NOT IN ('absent', 'on_leave') THEN total_hours ELSE 0 END) as total_work_hours
        FROM attendance 
        WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
    ");
    $attStmt->execute([$pay['employee_id'], $mStart, $mEnd]);
    $attStats = $attStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Company Settings
    $settings = $pdo->query("SELECT company_name, phone, email, address FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $companyName = $settings['company_name'] ?? 'VibeSoft ERP';
    $companyAddress = $settings['address'] ?? 'Kashipur, Uttarakhand';
    $companyPhone = $settings['phone'] ?? '';
    $companyEmail = $settings['email'] ?? '';

    $earnings = [];
    $deductions = [];

    $base = (float)$pay['base_salary'];
    $allow = (float)$pay['allowances'];
    $bonus = (float)$pay['bonus'];
    $gross = $base + $allow + $bonus;

    $earnings[] = ['title' => 'Basic Salary', 'amount' => $base, 'formatted' => '₹' . number_format($base, 2)];
    if ($allow > 0) $earnings[] = ['title' => 'Allowances', 'amount' => $allow, 'formatted' => '₹' . number_format($allow, 2)];
    if ($bonus > 0) $earnings[] = ['title' => 'Bonus / Incentives', 'amount' => $bonus, 'formatted' => '₹' . number_format($bonus, 2)];

    $adv = (float)$pay['advance_deducted'];
    $late = (float)$pay['late_deduction'];
    $other = (float)$pay['other_deduction'];
    $totalDeductions = $adv + $late + $other;

    if ($adv > 0) $deductions[] = ['title' => 'Advance Salary Deducted', 'amount' => $adv, 'formatted' => '-₹' . number_format($adv, 2)];
    if ($late > 0) $deductions[] = ['title' => 'Late Arrival Deduction', 'amount' => $late, 'formatted' => '-₹' . number_format($late, 2)];
    if ($other > 0) $deductions[] = ['title' => 'Other Deductions / Tax', 'amount' => $other, 'formatted' => '-₹' . number_format($other, 2)];

    $netSalary = (float)$pay['net_salary'];
    $netInWords = function_exists('convert_number_to_words') ? convert_number_to_words($netSalary) : ('Rupees ' . number_format($netSalary, 2));

    $token = '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
    }
    if (empty($token)) {
        $token = $_SERVER['HTTP_X_API_KEY'] ?? ($auth['user']['api_key'] ?? '');
    }

    $cleanBaseUrl = rtrim(defined('BASE_URL') ? BASE_URL : '/', '/');
    $cleanBaseUrl = str_replace(' ', '%20', $cleanBaseUrl);
    $downloadPdfUrl = $cleanBaseUrl . '/api/mobile_salary_download.php?id=' . $pay['id'] . '&format=pdf&token=' . urlencode($token);
    $viewHtmlUrl = $cleanBaseUrl . '/api/mobile_salary_download.php?id=' . $pay['id'] . '&format=html&token=' . urlencode($token);

    json_success([
        'id'                       => (int)$pay['id'],
        'month'                    => $monthName,
        'status'                   => strtoupper($pay['status'] ?? 'PROCESSED'),
        'gross_earnings'           => $gross,
        'gross_earnings_formatted' => '₹' . number_format($gross, 2),
        'total_deductions'         => $totalDeductions,
        'total_deductions_formatted'=> '-₹' . number_format($totalDeductions, 2),
        'net_pay'                  => $netSalary,
        'net_pay_formatted'        => '₹' . number_format($netSalary, 2),
        'net_pay_in_words'         => ucwords($netInWords) . ' Only',
        'earnings'                 => $earnings,
        'deductions'               => $deductions,
        'download_url'             => $downloadPdfUrl,
        'view_html_url'            => $viewHtmlUrl,
        'payment_date'             => $pay['payment_date'] ?? '',
        'company'                  => [
            'name'    => $companyName,
            'address' => $companyAddress,
            'phone'   => $companyPhone,
            'email'   => $companyEmail,
        ],
        'employee'                 => [
            'code'         => $empDetails['employee_code'] ?? ('EMP-' . $empDetails['id']),
            'name'         => $empDetails['full_name'] ?? '',
            'designation'  => $empDetails['desig_title'] ?? ($empDetails['designation'] ?? 'Staff'),
            'department'   => $empDetails['dept_title'] ?? ($empDetails['department'] ?? 'General'),
            'joining_date' => !empty($empDetails['joining_date']) ? date('d M Y', strtotime($empDetails['joining_date'])) : '—',
            'bank_name'    => $empDetails['bank_name'] ?? 'State Bank of India',
            'bank_acc_no'  => !empty($empDetails['bank_acc_no']) ? $empDetails['bank_acc_no'] : 'On File',
            'bank_ifsc'    => $empDetails['bank_ifsc'] ?? 'SBIN0000000',
            'pan'          => $empDetails['pan'] ?? '—',
        ],
        'attendance'               => [
            'total_days'   => $daysInMonth,
            'present_days' => (int)($attStats['present_days'] ?? 0),
            'late_days'    => (int)($attStats['late_days'] ?? 0),
            'absent_days'  => (int)($attStats['absent_days'] ?? 0),
            'work_hours'   => round((float)($attStats['total_work_hours'] ?? 0), 1),
        ],
    ], 'Salary slip detail loaded');
}

json_error('Unknown action', 400);

