<?php
// Crm and backend/api/work.php — RESTful API for Projects and Extra Work Management
require_once __DIR__ . '/mobile_helper.php';
require_once __DIR__ . '/../includes/ledger_helper.php';
require_once __DIR__ . '/../includes/audit_helper.php';

$auth = authenticate_mobile();
require_role_api(['admin', 'super admin', 'manager', 'sales manager', 'staff'], $auth);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = get_json_input();
$type = strtolower(trim($_GET['type'] ?? ($input['type'] ?? 'projects'))); // 'projects' or 'extra_work'
$action = $_GET['action'] ?? ($input['action'] ?? '');
$id = (int)($_GET['id'] ?? ($input['id'] ?? 0));

// ====================================================
// SECTION A: PROJECTS
// ====================================================
if ($type === 'projects') {
    // A1. GET /api/work.php?type=projects
    if ($method === 'GET') {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                SELECT p.*, c.name as customer_name, c.phone as customer_phone
                FROM projects p
                LEFT JOIN customers c ON c.id = p.customer_id
                WHERE p.id = ? LIMIT 1
            ");
            $stmt->execute([$id]);
            $project = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$project) json_error('Project not found', 404);

            // Fetch team members if table exists
            $project['members'] = [];
            try {
                $mStmt = $pdo->prepare("
                    SELECT pm.id, pm.project_id, pm.employee_id, pm.role, 
                           e.full_name, e.employee_code 
                    FROM project_members pm
                    LEFT JOIN employees e ON e.id = pm.employee_id
                    WHERE pm.project_id = ?
                ");
                $mStmt->execute([$id]);
                $project['members'] = $mStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {}

            // Fetch linked extra work
            try {
                $ewStmt = $pdo->prepare("SELECT id, title, total_amount, status, work_date FROM extra_work WHERE customer_id = ? ORDER BY id DESC LIMIT 10");
                $ewStmt->execute([$project['customer_id']]);
                $project['extra_work'] = $ewStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {}

            json_success(['project' => $project], 'Project detail loaded');
        }

        $statusFilter   = trim($_GET['status'] ?? '');
        $customerFilter = (int)($_GET['customer_id'] ?? 0);
        $search         = trim($_GET['q'] ?? '');
        $pagination     = get_pagination_params(20, 100);

        $where = ["1=1"];
        $params = [];

        if (!empty($statusFilter)) {
            $where[] = "p.status = ?";
            $params[] = $statusFilter;
        }
        if ($customerFilter > 0) {
            $where[] = "p.customer_id = ?";
            $params[] = $customerFilter;
        }
        if (!empty($search)) {
            $where[] = "(p.project_name LIKE ? OR c.name LIKE ?)";
            $term = "%" . sanitize_like_term($search) . "%";
            $params[] = $term; $params[] = $term;
        }

        $whereSql = implode(" AND ", $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM projects p LEFT JOIN customers c ON c.id = p.customer_id WHERE $whereSql");
        $countStmt->execute($params);
        $totalRecords = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT p.*, c.name as customer_name
            FROM projects p
            LEFT JOIN customers c ON c.id = p.customer_id
            WHERE $whereSql
            ORDER BY p.id DESC
            LIMIT ? OFFSET ?
        ");

        $bindIdx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($bindIdx++, $p);
        }
        $stmt->bindValue($bindIdx++, $pagination['limit'], PDO::PARAM_INT);
        $stmt->bindValue($bindIdx++, $pagination['offset'], PDO::PARAM_INT);
        $stmt->execute();
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        paginate_response($projects, $totalRecords, $pagination['page'], $pagination['limit'], 'Projects loaded', ['projects' => $projects]);
    }

    // A2. POST /api/work.php?type=projects (CREATE, ASSIGN, or STATUS CHANGE)
    if ($method === 'POST') {
        // A2A. ASSIGN MEMBER TO PROJECT
        if ($action === 'assign_member') {
            $projectId  = $id > 0 ? $id : (int)($input['project_id'] ?? 0);
            $employeeId = (int)($input['employee_id'] ?? 0);
            $role       = trim($input['role'] ?? 'Developer');

            if ($projectId <= 0 || $employeeId <= 0) {
                json_error('Project ID and Employee ID are required', 400);
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS project_members (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    employee_id INT NOT NULL,
                    role VARCHAR(100) DEFAULT 'Developer',
                    assigned_at DATETIME NOT NULL,
                    UNIQUE KEY unique_proj_emp (project_id, employee_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Duplicate Member Check / Idempotent update
            $chk = $pdo->prepare("SELECT id, role FROM project_members WHERE project_id = ? AND employee_id = ? LIMIT 1");
            $chk->execute([$projectId, $employeeId]);
            $existingMember = $chk->fetch(PDO::FETCH_ASSOC);

            if ($existingMember) {
                if ($existingMember['role'] !== $role) {
                    $pdo->prepare("UPDATE project_members SET role = ? WHERE id = ?")->execute([$role, $existingMember['id']]);
                }
                log_audit('Project', 'Updated Assigned Team Member', $projectId, ['employee_id' => $employeeId, 'role' => $role]);
                json_success(['project_id' => $projectId, 'employee_id' => $employeeId, 'role' => $role], 'Member assigned successfully');
            }

            try {
                $insM = $pdo->prepare("INSERT INTO project_members (project_id, employee_id, role) VALUES (?, ?, ?)");
                $insM->execute([$projectId, $employeeId, $role]);
            } catch (\PDOException $e) {
                handle_db_exception($e, 'project_member');
            }

            log_audit('Project', 'Assigned Team Member', $projectId, ['employee_id' => $employeeId, 'role' => $role]);
            json_success(['project_id' => $projectId, 'employee_id' => $employeeId, 'role' => $role], 'Member assigned successfully');
        }

        // A2B. REMOVE MEMBER FROM PROJECT
        if ($action === 'remove_member') {
            $projectId  = $id > 0 ? $id : (int)($input['project_id'] ?? 0);
            $employeeId = (int)($input['employee_id'] ?? 0);

            if ($projectId <= 0 || $employeeId <= 0) {
                json_error('Project ID and Employee ID are required', 400);
            }

            $pdo->prepare("DELETE FROM project_members WHERE project_id = ? AND employee_id = ?")->execute([$projectId, $employeeId]);
            log_audit('Project', 'Removed Team Member', $projectId, ['employee_id' => $employeeId]);
            json_success(['project_id' => $projectId, 'employee_id' => $employeeId], 'Team member removed from project');
        }

        // A2C. CHANGE PROJECT STATUS
        if ($action === 'change_status') {
            $newStatus = trim($input['status'] ?? '');
            if ($id <= 0 || empty($newStatus)) {
                json_error('Project ID and valid status required', 400);
            }

            $pdo->prepare("UPDATE projects SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            log_audit('Project', 'Status Changed to ' . $newStatus, $id);
            json_success(['id' => $id, 'status' => $newStatus], 'Project status updated successfully');
        }

        $customerId     = (int)($input['customer_id'] ?? 0);
        $projectName    = trim($input['project_name'] ?? '');
        $projectType    = $input['project_type'] ?? 'Fixed';
        $startDate      = !empty($input['start_date']) ? $input['start_date'] : date('Y-m-d');
        $endDate        = !empty($input['end_date']) ? $input['end_date'] : null;
        $agreedAmount   = (float)($input['agreed_amount'] ?? 0);
        $advanceReq     = (float)($input['advance_required'] ?? 0);
        $status         = $input['status'] ?? 'Active';
        $description    = trim($input['description'] ?? '');

        if ($customerId <= 0 || empty($projectName)) {
            json_error('Customer and Project Name are required', 400);
        }

        $ins = $pdo->prepare("
            INSERT INTO projects (customer_id, project_name, project_type, start_date, end_date, agreed_amount, advance_required, status, description, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $ins->execute([$customerId, $projectName, $projectType, $startDate, $endDate, $agreedAmount, $advanceReq, $status, $description]);
        $newProjectId = (int)$pdo->lastInsertId();

        log_audit('Project', 'Created Project', $newProjectId, ['name' => $projectName]);

        json_success(['id' => $newProjectId, 'project_name' => $projectName], 'Project created successfully', 201);
    }

    // A3. PUT /api/work.php?type=projects&id={id} (UPDATE PROJECT)
    if ($method === 'PUT') {
        if ($id <= 0) json_error('Project ID is required', 400);

        $pStmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? LIMIT 1");
        $pStmt->execute([$id]);
        $existing = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) json_error('Project not found', 404);

        $customerId   = (int)($input['customer_id'] ?? $existing['customer_id']);
        $projectName  = trim($input['project_name'] ?? $existing['project_name']);
        $projectType  = $input['project_type'] ?? $existing['project_type'];
        $startDate    = $input['start_date'] ?? $existing['start_date'];
        $endDate      = $input['end_date'] ?? $existing['end_date'];
        $agreedAmount = isset($input['agreed_amount']) ? (float)$input['agreed_amount'] : (float)$existing['agreed_amount'];
        $advanceReq   = isset($input['advance_required']) ? (float)$input['advance_required'] : (float)$existing['advance_required'];
        $status       = $input['status'] ?? $existing['status'];
        $description  = trim($input['description'] ?? $existing['description']);

        $upd = $pdo->prepare("
            UPDATE projects SET customer_id = ?, project_name = ?, project_type = ?, start_date = ?, end_date = ?, 
                                agreed_amount = ?, advance_required = ?, status = ?, description = ?
            WHERE id = ?
        ");
        $upd->execute([$customerId, $projectName, $projectType, $startDate, $endDate, $agreedAmount, $advanceReq, $status, $description, $id]);

        log_audit('Project', 'Updated Project', $id, ['name' => $projectName]);

        json_success(['id' => $id, 'project_name' => $projectName], 'Project updated successfully');
    }

    // A4. DELETE /api/work.php?type=projects&id={id}
    if ($method === 'DELETE') {
        require_role_api(['admin', 'super admin'], $auth);
        if ($id <= 0) json_error('Project ID is required', 400);

        $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$id]);
        log_audit('Project', 'Deleted Project', $id);
        json_success(['id' => $id], 'Project deleted successfully');
    }
}

// ====================================================
// SECTION B: EXTRA WORK
// ====================================================
if ($type === 'extra_work') {
    // B1. GET /api/work.php?type=extra_work
    if ($method === 'GET') {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                SELECT ew.*, c.name as customer_name, i.invoice_no as linked_invoice_no
                FROM extra_work ew
                LEFT JOIN customers c ON c.id = ew.customer_id
                LEFT JOIN invoices i ON i.id = ew.invoice_id
                WHERE ew.id = ? LIMIT 1
            ");
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) json_error('Extra work not found', 404);

            json_success(['extra_work' => $item], 'Extra work detail loaded');
        }

        $customerFilter = (int)($_GET['customer_id'] ?? 0);
        $statusFilter   = trim($_GET['status'] ?? '');
        $pagination     = get_pagination_params(20, 100);

        $where = ["1=1"];
        $params = [];

        if ($customerFilter > 0) {
            $where[] = "ew.customer_id = ?";
            $params[] = $customerFilter;
        }
        if (!empty($statusFilter)) {
            $where[] = "ew.status = ?";
            $params[] = $statusFilter;
        }

        $whereSql = implode(" AND ", $where);

        $stmt = $pdo->prepare("
            SELECT ew.*, c.name as customer_name
            FROM extra_work ew
            LEFT JOIN customers c ON c.id = ew.customer_id
            WHERE $whereSql
            ORDER BY ew.id DESC
            LIMIT ? OFFSET ?
        ");

        $bindIdx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($bindIdx++, $p);
        }
        $stmt->bindValue($bindIdx++, $pagination['limit'], PDO::PARAM_INT);
        $stmt->bindValue($bindIdx++, $pagination['offset'], PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM extra_work ew LEFT JOIN customers c ON c.id = ew.customer_id WHERE $whereSql");
        $countStmt->execute($params);
        $totalExtra = (int)$countStmt->fetchColumn();

        paginate_response($items, $totalExtra, $pagination['page'], $pagination['limit'], 'Extra work items loaded', ['extra_work' => $items]);
    }

    // B2. POST /api/work.php?type=extra_work (CREATE or CHANGE STATUS)
    if ($method === 'POST') {
        if ($action === 'change_status') {
            $newStatus = strtolower(trim($input['status'] ?? ''));
            if ($id <= 0 || !in_array($newStatus, ['requested', 'approved', 'in_progress', 'completed', 'billed', 'cancelled'])) {
                json_error('Valid extra work ID and status required', 400);
            }
            $pdo->prepare("UPDATE extra_work SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            log_audit('Extra Work', 'Status Changed to ' . $newStatus, $id);
            json_success(['id' => $id, 'status' => $newStatus], 'Extra work status updated');
        }

        $customerId    = (int)($input['customer_id'] ?? 0);
        $title         = trim($input['title'] ?? '');
        $description   = trim($input['description'] ?? '');
        $workDate      = !empty($input['work_date']) ? $input['work_date'] : date('Y-m-d');
        $totalAmount   = (float)($input['total_amount'] ?? 0);
        $status        = $input['status'] ?? 'completed';
        $billingMethod = $input['billing_method'] ?? 'separate_invoice';

        if ($customerId <= 0 || empty($title) || $totalAmount <= 0) {
            json_error('Customer, Title, and positive Total Amount are required', 400);
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("
                INSERT INTO extra_work (customer_id, title, description, work_date, qty, rate, total_amount, status, billing_method, created_at)
                VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([$customerId, $title, $description, $workDate, $totalAmount, $totalAmount, $status, $billingMethod]);
            $extraWorkId = (int)$pdo->lastInsertId();

            $invoiceId = null;
            // Handle automated separate invoice creation
            if ($billingMethod === 'separate_invoice') {
                $ctrRow = $pdo->query("SELECT last_number FROM invoice_counter WHERE id = 1 FOR UPDATE")->fetch();
                $newNum = ($ctrRow ? (int)$ctrRow['last_number'] : 10000) + 1;
                $pdo->exec("UPDATE invoice_counter SET last_number = {$newNum} WHERE id = 1");
                $invoiceNo = 'INV-' . $newNum;
                $dueDate = date('Y-m-d', strtotime($workDate . ' + 10 days'));

                $insInv = $pdo->prepare("
                    INSERT INTO invoices (invoice_no, customer_id, invoice_date, due_date, taxable_value, total_amount, amount_due, status, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'sent', ?, NOW())
                ");
                $insInv->execute([$invoiceNo, $customerId, $workDate, $dueDate, $totalAmount, $totalAmount, $totalAmount, $auth['user_id']]);
                $invoiceId = (int)$pdo->lastInsertId();

                $insItem = $pdo->prepare("
                    INSERT INTO invoice_items (invoice_id, name, qty, rate, line_total)
                    VALUES (?, ?, 1, ?, ?)
                ");
                $insItem->execute([$invoiceId, $title, $totalAmount, $totalAmount]);

                post_invoice_to_ledger($invoiceId);

                $pdo->prepare("UPDATE extra_work SET invoice_id = ?, status = 'billed' WHERE id = ?")->execute([$invoiceId, $extraWorkId]);
            }

            $pdo->commit();

            log_audit('Extra Work', 'Created Extra Work', $extraWorkId, ['title' => $title, 'amount' => $totalAmount]);

            json_success([
                'id'         => $extraWorkId,
                'title'      => $title,
                'invoice_id' => $invoiceId
            ], 'Extra work recorded successfully', 201);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            json_error('Failed to create extra work: ' . $e->getMessage(), 500);
        }
    }

    // B3. PUT /api/work.php?type=extra_work&id={id} (UPDATE EXTRA WORK)
    if ($method === 'PUT') {
        if ($id <= 0) json_error('Valid extra work ID is required', 400);

        $stmt = $pdo->prepare("SELECT * FROM extra_work WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) json_error('Extra work not found', 404);

        $title       = trim($input['title'] ?? $existing['title']);
        $description = trim($input['description'] ?? $existing['description']);
        $workDate    = !empty($input['work_date']) ? $input['work_date'] : $existing['work_date'];
        $endDate     = !empty($input['end_date']) ? $input['end_date'] : $existing['end_date'];
        $totalAmount = isset($input['total_amount']) ? (float)$input['total_amount'] : (float)$existing['total_amount'];
        $status      = trim($input['status'] ?? $existing['status']);

        $upd = $pdo->prepare("
            UPDATE extra_work 
            SET title = ?, description = ?, work_date = ?, end_date = ?, total_amount = ?, rate = ?, status = ?
            WHERE id = ?
        ");
        $upd->execute([$title, $description, $workDate, $endDate, $totalAmount, $totalAmount, $status, $id]);

        log_audit('Extra Work', 'Updated Extra Work', $id, ['title' => $title]);
        json_success(['id' => $id, 'title' => $title], 'Extra work updated successfully');
    }

    // B4. DELETE /api/work.php?type=extra_work&id={id}
    if ($method === 'DELETE') {
        require_role_api(['admin', 'super admin'], $auth);
        if ($id <= 0) json_error('Valid extra work ID is required', 400);

        $pdo->prepare("DELETE FROM extra_work WHERE id = ?")->execute([$id]);
        log_audit('Extra Work', 'Deleted Extra Work', $id);
        json_success(['id' => $id], 'Extra work deleted successfully');
    }
}

json_error('Method Not Allowed', 405);
