<?php
// database/run_vishal_hr_migration.php
require_once __DIR__ . '/../config.php';

echo "=== STARTING VISHAL ADVERTISEMENT HR SYSTEM MIGRATION ===\n";

// 1. Run SQL migration
$sql = file_get_contents(__DIR__ . '/migration_vishal_hr.sql');
$statements = array_filter(array_map('trim', explode(';', $sql)));
$executed = 0;
foreach ($statements as $stmt) {
    if (!empty($stmt)) {
        try {
            $pdo->exec($stmt);
            $executed++;
        } catch (Exception $e) {
            echo "Notice on statement: " . $e->getMessage() . "\n";
        }
    }
}
echo "Executed $executed schema statements.\n";

// 2. Seed Departments
$depts = [
    ['Production', 'production', 'Indoor & Outdoor Signage, 3D LED Letters, CNC & Laser Cutting, Metal Fabrication', 1],
    ['Design & Creative', 'design', 'Graphic Design, 3D CAD Mockups, Architectural Visuals & Artwork', 2],
    ['Sales & Marketing', 'sales', 'Corporate Signage Contracts, Hoardings, B2B Client Relations & Inquiries', 3],
    ['Installation & Field Operations', 'installation', 'On-site Board Mounting, Iron Structure Assembly, Structural Glazing', 4],
    ['Accounts & HR', 'accounts', 'Finance, Payroll, Vendor Purchases, Billing & Store Inventory', 5]
];

$deptMap = [];
foreach ($depts as $d) {
    $stmt = $pdo->prepare("INSERT INTO departments (name, slug, description, sort_order) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE description=VALUES(description)");
    $stmt->execute([$d[0], $d[1], $d[2], $d[3]]);
    $id = $pdo->query("SELECT id FROM departments WHERE slug='{$d[1]}'")->fetchColumn();
    $deptMap[$d[1]] = $id;
}
echo "Departments seeded.\n";

// 3. Seed Designations
$designations = [
    ['Production Supervisor', $deptMap['production']],
    ['Senior Signage Fabricator', $deptMap['production']],
    ['CNC & Laser Operator', $deptMap['production']],
    ['Art Director / 3D Visualizer', $deptMap['design']],
    ['Senior Graphic Designer', $deptMap['design']],
    ['Sales Manager', $deptMap['sales']],
    ['Business Development Executive', $deptMap['sales']],
    ['Lead Billboard & LED Installer', $deptMap['installation']],
    ['Field Installation Technician', $deptMap['installation']],
    ['Accountant & Office Admin', $deptMap['accounts']]
];

$desigMap = [];
foreach ($designations as $des) {
    $check = $pdo->prepare("SELECT id FROM designations WHERE name=? AND department_id=?");
    $check->execute([$des[0], $des[1]]);
    $existId = $check->fetchColumn();
    if (!$existId) {
        $ins = $pdo->prepare("INSERT INTO designations (name, department_id) VALUES (?, ?)");
        $ins->execute([$des[0], $des[1]]);
        $desigMap[$des[0]] = $pdo->lastInsertId();
    } else {
        $desigMap[$des[0]] = $existId;
    }
}
echo "Designations seeded.\n";

// 4. Seed Leave Types
$leaveTypes = [
    ['Casual Leave', 12, 1],
    ['Sick Leave', 10, 1],
    ['Earned / Paid Leave', 15, 1],
    ['Unpaid Leave', 0, 0]
];
foreach ($leaveTypes as $lt) {
    $pdo->prepare("INSERT INTO leave_types (name, days_per_year, is_paid) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE days_per_year=VALUES(days_per_year)")
        ->execute([$lt[0], $lt[1], $lt[2]]);
}

// 5. Seed Holidays
$holidays = [
    ['Independence Day', '2026-08-15', 'National Holiday'],
    ['Raksha Bandhan', '2026-08-28', 'Festival Holiday'],
    ['Gandhi Jayanti', '2026-10-02', 'National Holiday'],
    ['Diwali', '2026-11-08', 'Festival of Lights'],
    ['Vishwakarma Puja', '2026-09-17', 'Craftsmen & Industrial Machinery Holiday']
];
foreach ($holidays as $h) {
    $pdo->prepare("INSERT INTO holidays (name, holiday_date, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name=VALUES(name)")
        ->execute([$h[0], $h[1], $h[2]]);
}

// 6. Setup Employees & User Accounts
$passwordHash = password_hash('password123', PASSWORD_BCRYPT);

$sampleEmployees = [
    [
        'code' => 'EMP101',
        'name' => 'Kunal Sengar',
        'first' => 'Kunal',
        'last' => 'Sengar',
        'email' => 'kunal@vishaladv.com',
        'phone' => '+91 98765 43210',
        'dept' => 'design',
        'dept_name' => 'Design & Creative',
        'desig' => 'Art Director / 3D Visualizer',
        'salary' => 28000,
        'role' => 'Employee'
    ],
    [
        'code' => 'EMP102',
        'name' => 'Rahul Kumar',
        'first' => 'Rahul',
        'last' => 'Kumar',
        'email' => 'rahul@vishaladv.com',
        'phone' => '+91 98123 45678',
        'dept' => 'production',
        'dept_name' => 'Production',
        'desig' => 'Production Supervisor',
        'salary' => 25000,
        'role' => 'Manager'
    ],
    [
        'code' => 'EMP103',
        'name' => 'Amit Verma',
        'first' => 'Amit',
        'last' => 'Verma',
        'email' => 'amit@vishaladv.com',
        'phone' => '+91 98234 56789',
        'dept' => 'design',
        'dept_name' => 'Design & Creative',
        'desig' => 'Senior Graphic Designer',
        'salary' => 24000,
        'role' => 'Employee'
    ],
    [
        'code' => 'EMP104',
        'name' => 'Vikram Singh',
        'first' => 'Vikram',
        'last' => 'Singh',
        'email' => 'vikram@vishaladv.com',
        'phone' => '+91 98345 67890',
        'dept' => 'installation',
        'dept_name' => 'Installation & Field Operations',
        'desig' => 'Lead Billboard & LED Installer',
        'salary' => 22000,
        'role' => 'Employee'
    ],
    [
        'code' => 'EMP105',
        'name' => 'Neha Gupta',
        'first' => 'Neha',
        'last' => 'Gupta',
        'email' => 'neha@vishaladv.com',
        'phone' => '+91 98456 78901',
        'dept' => 'sales',
        'dept_name' => 'Sales & Marketing',
        'desig' => 'Business Development Executive',
        'salary' => 24000,
        'role' => 'Employee'
    ],
    [
        'code' => 'EMP106',
        'name' => 'Ramesh Sharma',
        'first' => 'Ramesh',
        'last' => 'Sharma',
        'email' => 'ramesh@vishaladv.com',
        'phone' => '+91 98567 89012',
        'dept' => 'production',
        'dept_name' => 'Production',
        'desig' => 'CNC & Laser Operator',
        'salary' => 20000,
        'role' => 'Employee'
    ]
];

$empIdMap = [];

foreach ($sampleEmployees as $se) {
    // 1. Create or update user
    $userCheck = $pdo->prepare("SELECT id FROM users WHERE email=?");
    $userCheck->execute([$se['email']]);
    $userId = $userCheck->fetchColumn();

    if (!$userId) {
        $uIns = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, 'active')");
        $uIns->execute([$se['name'], $se['email'], $passwordHash, $se['role']]);
        $userId = $pdo->lastInsertId();
    }

    // 2. Create or update employee
    $deptId = $deptMap[$se['dept']] ?? 1;
    $desigId = $desigMap[$se['desig']] ?? 1;

    $empCheck = $pdo->prepare("SELECT id FROM employees WHERE employee_code=?");
    $empCheck->execute([$se['code']]);
    $empId = $empCheck->fetchColumn();

    if ($empId) {
        $upd = $pdo->prepare("UPDATE employees SET 
            user_id=?, full_name=?, first_name=?, last_name=?, email=?, phone=?, 
            department=?, department_id=?, designation=?, designation_id=?, 
            base_salary=?, status='active' WHERE id=?");
        $upd->execute([
            $userId, $se['name'], $se['first'], $se['last'], $se['email'], $se['phone'],
            $se['dept_name'], $deptId, $se['desig'], $desigId,
            $se['salary'], $empId
        ]);
    } else {
        $ins = $pdo->prepare("INSERT INTO employees 
            (user_id, employee_code, full_name, first_name, last_name, email, phone, 
             department, department_id, designation, designation_id, joining_date, 
             employee_type, salary_type, base_salary, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '2025-01-10', 'Full Time', 'Monthly', ?, 'active')");
        $ins->execute([
            $userId, $se['code'], $se['name'], $se['first'], $se['last'], $se['email'], $se['phone'],
            $se['dept_name'], $deptId, $se['desig'], $desigId,
            $se['salary']
        ]);
        $empId = $pdo->lastInsertId();
    }

    // Link user to employee_id
    $pdo->prepare("UPDATE users SET employee_id=? WHERE id=?")->execute([$empId, $userId]);
    $empIdMap[$se['code']] = $empId;

    // Record historical salary in employee_salary
    $salCheck = $pdo->prepare("SELECT id FROM employee_salary WHERE employee_id=?");
    $salCheck->execute([$empId]);
    if (!$salCheck->fetchColumn()) {
        $pdo->prepare("INSERT INTO employee_salary (employee_id, effective_from, basic_salary, status) VALUES (?, '2026-01-01', ?, 'active')")
            ->execute([$empId, $se['salary']]);
    }
}
echo "Employees and user accounts verified.\n";

// 7. Seed Attendance & Work Reports from 2026-08-01 to 2026-09-01 (Aaj tak)
echo "Marking attendance & daily reports up to today (August 1 to September 1, 2026)...\n";

$startDate = new DateTime('2026-08-01');
$endDate   = new DateTime('2026-09-01');
$interval  = new DateInterval('P1D');
$period    = new DatePeriod($startDate, $interval, (clone $endDate)->modify('+1 day'));

// Real advertisement tasks by role
$taskCatalog = [
    'EMP101' => [ // 3D Visualizer & Art Director
        'morning' => 'Created 3D isometric elevation render for Hotel Shivalik rooftop 3D LED board.',
        'evening' => 'Finalized vector cut files for CNC acrylic lettering, sent to production. Color matched Pantone blue for corporate bank fascia.',
        'projects' => 'Hotel Shivalik LED Sign, HDFC Bank Fascia',
        'client' => 'Shivalik Hospitality'
    ],
    'EMP102' => [ // Production Supervisor
        'morning' => 'Inspected raw material arrival: 3mm Aludecor ACP sheets & Samsung 1.5W injected LED modules.',
        'evening' => 'Supervised laser cutting of 25 golden mirror acrylic channel letters. Conducted 4-hour burn-in stress test on IP67 waterproof power supply.',
        'projects' => 'Highway Mega Billboard, Retail Showroom Signage',
        'client' => 'City Mall Developers'
    ],
    'EMP103' => [ // Senior Graphic Designer
        'morning' => 'Designed high-resolution vector artwork for 40ft x 15ft roadside hoarding flex print.',
        'evening' => 'Prepared UV printing panels on 5mm sunboard. Checked CMYK color profiles and proof prints for retail boutique storefront.',
        'projects' => 'NH-58 Bypass Hoarding, Elite Boutique Branding',
        'client' => 'Elite Fashion'
    ],
    'EMP104' => [ // Lead Billboard & LED Installer
        'morning' => 'Loaded 1000W safety harness, scafolding, and structural brackets for on-site facade mounting.',
        'evening' => 'Successfully installed 30ft ACP frame and mounted illuminated 3D letters at 4th-floor facade. Completed wiring and safety earthing test.',
        'projects' => 'Metro Hospital Emergency Sign, Auto Hub Showroom',
        'client' => 'Metro Hospital'
    ],
    'EMP105' => [ // Sales & Client Relations
        'morning' => 'Attended client kickoff meeting for 12 branch directional signage rollout in Haridwar & Dehradun.',
        'evening' => 'Submitted detailed BOM cost estimates and site survey measurements. Closed deal for ₹1,85,000 corporate annual hoarding package.',
        'projects' => 'Branch Signage Rollout, Annual Hoarding Campaign',
        'client' => 'Sunrise Ayurvedic Pharma'
    ],
    'EMP106' => [ // CNC & Laser Operator
        'morning' => 'Calibrated CNC router spindle and zero-axis for 18mm high-density MDF wooden art carving.',
        'evening' => 'Cut 45 custom wooden jali panels and polished bevel edges. Cleaned optical mirrors of 150W CO2 laser cutting bed.',
        'projects' => 'Resort Wooden Art Interior, Designer Acrylic Panels',
        'client' => 'Pine Wood Luxury Resort'
    ]
];

$attCount = 0;
$repCount = 0;

foreach ($period as $dt) {
    $dateStr = $dt->format('Y-m-d');
    $dayOfWeek = (int)$dt->format('w'); // 0 = Sunday

    $isSunday = ($dayOfWeek === 0);
    $isIndependenceDay = ($dateStr === '2026-08-15');
    $isRakshaBandhan = ($dateStr === '2026-08-28');

    foreach ($empIdMap as $code => $empId) {
        $task = $taskCatalog[$code];

        if ($isSunday) {
            // Weekly Off
            $sql = "INSERT INTO attendance 
                      (employee_id, attendance_date, status, check_in, check_out, late_minutes, total_hours, notes, marked_by)
                    VALUES (?, ?, 'holiday', NULL, NULL, 0, 0, 'Weekly Off', 1)
                    ON DUPLICATE KEY UPDATE status=VALUES(status)";
            $pdo->prepare($sql)->execute([$empId, $dateStr]);
            $attCount++;
            continue;
        }

        if ($isIndependenceDay || $isRakshaBandhan) {
            // Holiday
            $hName = $isIndependenceDay ? 'Independence Day' : 'Raksha Bandhan';
            $sql = "INSERT INTO attendance 
                      (employee_id, attendance_date, status, check_in, check_out, late_minutes, total_hours, notes, marked_by)
                    VALUES (?, ?, 'holiday', NULL, NULL, 0, 0, ?, 1)
                    ON DUPLICATE KEY UPDATE status=VALUES(status)";
            $pdo->prepare($sql)->execute([$empId, $dateStr, $hName]);
            $attCount++;
            continue;
        }

        // On August 18, let's give Neha Gupta a planned Casual Leave
        if ($dateStr === '2026-08-18' && $code === 'EMP105') {
            $sql = "INSERT INTO attendance 
                      (employee_id, attendance_date, status, check_in, check_out, late_minutes, total_hours, leave_type, notes, marked_by)
                    VALUES (?, ?, 'on_leave', NULL, NULL, 0, 0, 'Casual Leave', 'Family Function', 1)
                    ON DUPLICATE KEY UPDATE status=VALUES(status)";
            $pdo->prepare($sql)->execute([$empId, $dateStr]);
            $attCount++;
            continue;
        }

        // Normal working day:
        // Hash the date and employee code for deterministic realistic times
        $hashVal = crc32($dateStr . $code);

        // Late on ~12% of days
        $isLate = (($hashVal % 9) === 0);

        if ($isLate) {
            // Late: 10:14 to 10:35 AM
            $lateMin = 14 + ($hashVal % 22); // 14 to 35 mins
            $checkInMin = $lateMin;
            $checkInHour = 10;
            if ($checkInMin >= 60) {
                $checkInHour += 1;
                $checkInMin -= 60;
            }
            $checkIn = sprintf("%02d:%02d:00", $checkInHour, $checkInMin);
            $lateMinutesRecorded = max(0, $lateMin - 10); // 10 min grace
            $status = 'late';
        } else {
            // On-time: 09:44 to 10:05 AM
            $min = 44 + ($hashVal % 22); // 44 to 65
            if ($min >= 60) {
                $checkInHour = 10;
                $checkInMin = $min - 60;
            } else {
                $checkInHour = 9;
                $checkInMin = $min;
            }
            $checkIn = sprintf("%02d:%02d:00", $checkInHour, $checkInMin);
            $lateMinutesRecorded = 0;
            $status = 'present';
        }

        // Check-out: 19:05 to 19:35
        $outMin = 5 + ($hashVal % 30);
        $checkOut = sprintf("19:%02d:00", $outMin);

        // Total hours: between 8.8 and 9.6 hrs
        $hoursWorked = round((strtotime("$dateStr $checkOut") - strtotime("$dateStr $checkIn")) / 3600, 2);

        // Record attendance
        $sqlAtt = "INSERT INTO attendance 
                     (employee_id, attendance_date, status, check_in, check_out, late_minutes, total_hours, overtime_hours, marked_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                   ON DUPLICATE KEY UPDATE 
                     status=VALUES(status), check_in=VALUES(check_in), check_out=VALUES(check_out),
                     late_minutes=VALUES(late_minutes), total_hours=VALUES(total_hours), overtime_hours=VALUES(overtime_hours)";
        $pdo->prepare($sqlAtt)->execute([
            $empId, $dateStr, $status, $checkIn, $checkOut,
            $lateMinutesRecorded, $hoursWorked, max(0, $hoursWorked - 9.0)
        ]);
        $attCount++;

        // Record Daily Work Report in both daily_work_reports and work_reports
        $sqlRep = "INSERT INTO daily_work_reports
                     (employee_id, report_date, morning_tasks, evening_tasks, projects_worked, clients_worked, hours_worked, blockers, plan_for_tomorrow, mood, rating, review_note, reviewed_by, reviewed_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 'None', 'Continue assembly & quality finish.', 'great', 5, 'Great precision and on-time completion.', 1, NOW())
                   ON DUPLICATE KEY UPDATE
                     morning_tasks=VALUES(morning_tasks), evening_tasks=VALUES(evening_tasks), hours_worked=VALUES(hours_worked), rating=5";
        $pdo->prepare($sqlRep)->execute([
            $empId, $dateStr, $task['morning'], $task['evening'], $task['projects'], $task['client'], $hoursWorked
        ]);

        $sqlWr = "INSERT INTO work_reports
                    (employee_id, report_date, summary, tasks_completed, projects_worked, hours_worked, challenges, tomorrow_plan, status, submitted_at, reviewed_by, reviewed_at, review_comment)
                  VALUES (?, ?, ?, ?, ?, ?, 'None', 'Continue assembly and client delivery.', 'approved', NOW(), 1, NOW(), 'Excellent work.')
                  ON DUPLICATE KEY UPDATE status='approved'";
        $pdo->prepare($sqlWr)->execute([
            $empId, $dateStr, $task['morning'], $task['evening'], $task['projects'], $hoursWorked
        ]);
        $repCount++;
    }
}

echo "Successfully generated $attCount attendance entries and $repCount daily work reports!\n";

// 8. Generate August 2026 Payroll snapshot for all employees
echo "Generating finalized August 2026 Payroll...\n";
foreach ($empIdMap as $code => $empId) {
    $emp = $pdo->query("SELECT * FROM employees WHERE id=$empId")->fetch();
    $baseSalary = (float)$emp['base_salary'];

    // Calculate August stats
    $attStats = $pdo->query("SELECT 
        SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
        SUM(late_minutes) as total_late_min,
        SUM(total_hours) as total_hrs
      FROM attendance 
      WHERE employee_id=$empId AND attendance_date BETWEEN '2026-08-01' AND '2026-08-31'
    ")->fetch();

    $lateHours = round(((float)$attStats['total_late_min']) / 60, 2);
    $lateDeduction = round($lateHours * 50, 2); // 50 per hour
    $netSalary = max(0, $baseSalary - $lateDeduction);

    $pCheck = $pdo->prepare("SELECT id FROM payroll WHERE employee_id=? AND payroll_month=8 AND payroll_year=2026");
    $pCheck->execute([$empId]);
    $existingP = $pCheck->fetchColumn();

    if ($existingP) {
        $pId = $existingP;
        $pdo->prepare("UPDATE payroll SET base_salary=?, late_deduction=?, net_salary=?, amount_paid=?, status='Paid', payment_date='2026-08-31', payment_method='Bank Transfer' WHERE id=?")
            ->execute([$baseSalary, $lateDeduction, $netSalary, $netSalary, $pId]);
    } else {
        $pdo->prepare("INSERT INTO payroll 
            (employee_id, payroll_month, payroll_year, base_salary, late_deduction, other_deduction, net_salary, amount_paid, amount_pending, status, payment_date, payment_method) 
            VALUES (?, 8, 2026, ?, ?, 0, ?, ?, 0, 'Paid', '2026-08-31', 'Bank Transfer')")
            ->execute([$empId, $baseSalary, $lateDeduction, $netSalary, $netSalary]);
        $pId = $pdo->lastInsertId();
    }

    // Insert payroll items
    $pdo->prepare("DELETE FROM payroll_items WHERE payroll_id=?")->execute([$pId]);
    $pdo->prepare("INSERT INTO payroll_items (payroll_id, type, name, amount, description) VALUES (?, 'earning', 'Basic Salary', ?, 'Monthly Base Pay')")
        ->execute([$pId, $baseSalary]);
    if ($lateDeduction > 0) {
        $pdo->prepare("INSERT INTO payroll_items (payroll_id, type, name, amount, description) VALUES (?, 'deduction', 'Late Arrival Penalty', ?, '{$lateHours} hours late deduction')")
            ->execute([$pId, $lateDeduction]);
    }
}
echo "August 2026 Payroll snapshot complete.\n";

echo "=== MIGRATION AND POPULATION COMPLETED SUCCESSFULLY! ===\n";
