<?php
// Crm and backend/api/team.php — RESTful API for Team, Employees, and HR Management
require_once __DIR__ . '/mobile_helper.php';
require_once __DIR__ . '/../includes/audit_helper.php';

$auth = authenticate_mobile();
// Strict RBAC: Employees cannot access full team management
require_role_api(['admin', 'super admin', 'manager', 'hr manager'], $auth);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = get_json_input();
$type = strtolower(trim($_GET['type'] ?? ($input['type'] ?? 'employees'))); // 'employees', 'departments', 'advances'
$id = (int)($_GET['id'] ?? ($input['id'] ?? 0));

// ====================================================
// SECTION A: EMPLOYEES
// ====================================================
if ($type === 'employees') {
    // A1. GET /api/team.php?type=employees (DETAIL or LIST)
    if ($method === 'GET') {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                SELECT e.*, d.name as department_name, des.name as designation_name, u.email as user_email, u.role as user_role
                FROM employees e
                LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN designations des ON des.id = e.designation_id
                LEFT JOIN users u ON (u.employee_id = e.id OR u.id = e.user_id)
                WHERE e.id = ? LIMIT 1
            ");
            $stmt->execute([$id]);
            $emp = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$emp) json_error('Employee not found', 404);

            // Fetch current month attendance summary
            $mStart = date('Y-m-01');
            $mEnd   = date('Y-m-t');
            $attStmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN status NOT IN ('absent', 'on_leave') THEN total_hours ELSE 0 END) as total_work_hours
                FROM attendance 
                WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
            ");
            $attStmt->execute([$id, $mStart, $mEnd]);
            $emp['attendance_summary'] = $attStmt->fetch(PDO::FETCH_ASSOC);

            json_success(['employee' => $emp], 'Employee detail loaded');
        }

        $search       = trim($_GET['q'] ?? '');
        $statusFilter = trim($_GET['status'] ?? 'active');
        $deptFilter   = (int)($_GET['department_id'] ?? 0);
        $pagination   = get_pagination_params(20, 100);

        $where = ["1=1"];
        $params = [];

        if (!empty($statusFilter) && $statusFilter !== 'all') {
            $where[] = "e.status = ?";
            $params[] = $statusFilter;
        }
        if ($deptFilter > 0) {
            $where[] = "e.department_id = ?";
            $params[] = $deptFilter;
        }
        if (!empty($search)) {
            $where[] = "(e.full_name LIKE ? OR e.employee_code LIKE ? OR e.phone LIKE ? OR e.email LIKE ?)";
            $term = "%" . sanitize_like_term($search) . "%";
            $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
        }

        $whereSql = implode(" AND ", $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM employees e WHERE $whereSql");
        $countStmt->execute($params);
        $totalRecords = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT e.id, e.employee_code, e.full_name, e.department, e.designation, e.phone, e.email, 
                   e.joining_date, e.base_salary, e.status, e.profile_photo
            FROM employees e
            WHERE $whereSql
            ORDER BY e.id DESC
            LIMIT ? OFFSET ?
        ");

        $bindIdx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($bindIdx++, $p);
        }
        $stmt->bindValue($bindIdx++, $pagination['limit'], PDO::PARAM_INT);
        $stmt->bindValue($bindIdx++, $pagination['offset'], PDO::PARAM_INT);
        $stmt->execute();
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        paginate_response($employees, $totalRecords, $pagination['page'], $pagination['limit'], 'Employees loaded', ['employees' => $employees]);
    }

    // A2. POST /api/team.php?type=employees (CREATE EMPLOYEE or STATUS CHANGE)
    if ($method === 'POST') {
        require_role_api(['admin', 'super admin', 'hr manager'], $auth);

        // A2A. STATUS CHANGE
        if ($action === 'change_status') {
            $newStatus = strtolower(trim($input['status'] ?? ''));
            if ($id <= 0 || !in_array($newStatus, ['active', 'probation', 'on_leave', 'resigned', 'terminated'])) {
                json_error('Valid Employee ID and status (active/probation/on_leave/resigned/terminated) are required', 400);
            }

            $pdo->prepare("UPDATE employees SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            log_audit('HR', 'Changed Employee Status to ' . $newStatus, $id);
            json_success(['id' => $id, 'status' => $newStatus], 'Employee status updated successfully');
        }

        $empCode     = trim($input['employee_code'] ?? '');
        $fullName    = trim($input['full_name'] ?? '');
        $email       = trim($input['email'] ?? '');
        $phone       = trim($input['phone'] ?? '');
        $department  = trim($input['department'] ?? 'General');
        $designation = trim($input['designation'] ?? 'Staff');
        $joiningDate = !empty($input['joining_date']) ? $input['joining_date'] : date('Y-m-d');
        $baseSalary  = (float)($input['base_salary'] ?? 0);
        $status      = $input['status'] ?? 'active';

        validate_or_fail($input, [
            'full_name' => 'required|min:2',
            'email'     => 'required|email',
            'phone'     => 'required|min:10'
        ]);

        if (empty($empCode)) {
            $lastId = (int)($pdo->query("SELECT MAX(id) FROM employees")->fetchColumn() ?: 0) + 1;
            $empCode = 'EMP-' . str_pad($lastId, 4, '0', STR_PAD_LEFT);
        }

        // Duplicate Check: Check employee_code or email
        $dupChk = $pdo->prepare("SELECT id FROM employees WHERE employee_code = ? OR email = ? LIMIT 1");
        $dupChk->execute([$empCode, $email]);
        if ($dupChk->fetchColumn()) {
            json_error("An employee with code '{$empCode}' or email '{$email}' already exists.", 409, [
                'code' => 'DUPLICATE_RECORD'
            ]);
        }

        try {
            $ins = $pdo->prepare("
                INSERT INTO employees 
                  (employee_code, full_name, email, phone, department, designation, joining_date, base_salary, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([$empCode, $fullName, $email, $phone, $department, $designation, $joiningDate, $baseSalary, $status]);
            $newEmpId = (int)$pdo->lastInsertId();
        } catch (\PDOException $e) {
            handle_db_exception($e, 'employee');
        }

        log_audit('HR', 'Created Employee', $newEmpId, ['name' => $fullName, 'code' => $empCode]);

        json_success(['id' => $newEmpId, 'employee_code' => $empCode, 'full_name' => $fullName], 'Employee created successfully', 201);
    }

    // A3. PUT /api/team.php?type=employees&id={id} (UPDATE EMPLOYEE)
    if ($method === 'PUT') {
        require_role_api(['admin', 'super admin', 'hr manager'], $auth);
        if ($id <= 0) json_error('Employee ID is required', 400);

        $eStmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? LIMIT 1");
        $eStmt->execute([$id]);
        $existing = $eStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) json_error('Employee not found', 404);

        $fullName    = trim($input['full_name'] ?? $existing['full_name']);
        $email       = trim($input['email'] ?? $existing['email']);
        $phone       = trim($input['phone'] ?? $existing['phone']);
        $department  = trim($input['department'] ?? $existing['department']);
        $designation = trim($input['designation'] ?? $existing['designation']);
        $baseSalary  = isset($input['base_salary']) ? (float)$input['base_salary'] : (float)$existing['base_salary'];
        $status      = $input['status'] ?? $existing['status'];

        $upd = $pdo->prepare("
            UPDATE employees 
            SET full_name = ?, email = ?, phone = ?, department = ?, designation = ?, base_salary = ?, status = ?
            WHERE id = ?
        ");
        $upd->execute([$fullName, $email, $phone, $department, $designation, $baseSalary, $status, $id]);

        log_audit('HR', 'Updated Employee', $id, ['name' => $fullName]);

        json_success(['id' => $id, 'full_name' => $fullName], 'Employee updated successfully');
    }

    // A4. DELETE /api/team.php?type=employees&id={id}
    if ($method === 'DELETE') {
        require_role_api(['admin', 'super admin'], $auth);
        if ($id <= 0) json_error('Employee ID is required', 400);

        // Terminate rather than hard delete to keep history
        $pdo->prepare("UPDATE employees SET status = 'terminated' WHERE id = ?")->execute([$id]);
        log_audit('HR', 'Terminated Employee', $id);

        json_success(['id' => $id, 'status' => 'terminated'], 'Employee status set to terminated');
    }
}

// ====================================================
// SECTION B: DEPARTMENTS & DESIGNATIONS
// ====================================================
if ($type === 'departments') {
    if ($method === 'GET') {
        $depts = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $desigs = $pdo->query("SELECT * FROM designations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        json_success([
            'departments'  => $depts,
            'designations' => $desigs
        ], 'Departments and Designations loaded');
    }

    if ($method === 'POST') {
        require_role_api(['admin', 'super admin', 'hr manager'], $auth);
        $name = trim($input['name'] ?? '');
        $subType = trim($input['sub_type'] ?? 'department'); // 'department' or 'designation'
        $desc = trim($input['description'] ?? '');

        if (empty($name)) json_error('Name is required', 400);

        if ($subType === 'designation') {
            $deptId = (int)($input['department_id'] ?? 1);
            $stmt = $pdo->prepare("INSERT INTO designations (name, department_id, description) VALUES (?, ?, ?)");
            $stmt->execute([$name, $deptId, $desc]);
            $newId = (int)$pdo->lastInsertId();
            json_success(['id' => $newId, 'name' => $name], 'Designation created successfully', 201);
        } else {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
            $stmt = $pdo->prepare("INSERT INTO departments (name, slug, description) VALUES (?, ?, ?)");
            $stmt->execute([$name, $slug, $desc]);
            $newId = (int)$pdo->lastInsertId();
            json_success(['id' => $newId, 'name' => $name], 'Department created successfully', 201);
        }
    }
}

// ====================================================
// SECTION C: SALARY ADVANCES
// ====================================================
if ($type === 'advances') {
    if ($method === 'GET') {
        $empFilter = (int)($_GET['employee_id'] ?? 0);
        $where = ["1=1"];
        $params = [];
        if ($empFilter > 0) {
            $where[] = "sa.employee_id = ?";
            $params[] = $empFilter;
        }
        $whereSql = implode(" AND ", $where);

        $stmt = $pdo->prepare("
            SELECT sa.*, e.full_name as employee_name, e.employee_code, e.department
            FROM salary_advances sa
            JOIN employees e ON e.id = sa.employee_id
            WHERE $whereSql
            ORDER BY sa.id DESC
        ");
        $stmt->execute($params);
        $advances = $stmt->fetchAll(PDO::FETCH_ASSOC);

        json_success(['salary_advances' => $advances], 'Salary advances loaded');
    }

    if ($method === 'POST') {
        require_role_api(['admin', 'super admin', 'hr manager', 'finance manager'], $auth);

        $employeeId    = (int)($input['employee_id'] ?? 0);
        $amount         = (float)($input['amount'] ?? 0);
        $requestDate   = !empty($input['request_date']) ? $input['request_date'] : date('Y-m-d');
        $reason         = trim($input['reason'] ?? 'Salary Advance');
        $paymentMethod = trim($input['payment_method'] ?? 'Bank Transfer');
        $payrollMonth  = (int)date('n', strtotime($requestDate));
        $payrollYear   = (int)date('Y', strtotime($requestDate));

        if ($employeeId <= 0 || $amount <= 0) {
            json_error('Employee ID and positive Amount are required', 400);
        }

        $ins = $pdo->prepare("
            INSERT INTO salary_advances 
              (employee_id, request_date, amount, reason, payment_method, status, payroll_month, payroll_year, approved_by, created_at)
            VALUES (?, ?, ?, ?, ?, 'Paid', ?, ?, ?, NOW())
        ");
        $ins->execute([$employeeId, $requestDate, $amount, $reason, $paymentMethod, $payrollMonth, $payrollYear, $auth['user_id']]);
        $newAdvId = (int)$pdo->lastInsertId();

        log_audit('HR', 'Recorded Salary Advance', $newAdvId, ['employee_id' => $employeeId, 'amount' => $amount]);
        json_success(['id' => $newAdvId, 'amount' => $amount], 'Salary advance recorded successfully', 201);
    }
}

json_error('Method Not Allowed', 405);
