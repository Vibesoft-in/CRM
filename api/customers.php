<?php
// Crm and backend/api/customers.php — Full RESTful API for Client Management
require_once __DIR__ . '/mobile_helper.php';
require_once __DIR__ . '/../includes/ledger_helper.php';
require_once __DIR__ . '/../includes/audit_helper.php';

$auth = authenticate_mobile();
// Strict RBAC: Employees cannot access CRM Customers
require_role_api(['admin', 'super admin', 'manager', 'sales manager', 'finance manager', 'staff'], $auth);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = get_json_input();
$action = $_GET['action'] ?? ($input['action'] ?? '');
$id = (int)($_GET['id'] ?? ($input['id'] ?? 0));

// ====================================================
// 1. GET /api/customers.php (LIST or DETAIL or LEDGER)
// ====================================================
if ($method === 'GET') {
    // 1A. CLIENT DETAIL
    if ($id > 0 && !in_array($action, ['ledger', 'quotations'])) {
        $stmt = $pdo->prepare("
            SELECT c.*,
              COALESCE((SELECT SUM(total_amount) FROM invoices WHERE customer_id = c.id AND status != 'cancelled'), 0) AS total_billed,
              COALESCE((SELECT SUM(amount) FROM payments WHERE customer_id = c.id), 0) AS total_received
            FROM customers c 
            WHERE c.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            json_error('Customer not found', 404, ['code' => 'CUSTOMER_NOT_FOUND']);
        }

        $billed = (float)$client['total_billed'];
        $received = (float)$client['total_received'];
        $client['outstanding_balance'] = max(0, $billed - $received);

        // Fetch Recent Invoices
        $invStmt = $pdo->prepare("SELECT id, invoice_no, invoice_date, due_date, total_amount, amount_paid, amount_due, status FROM invoices WHERE customer_id = ? ORDER BY id DESC LIMIT 10");
        $invStmt->execute([$id]);
        $client['recent_invoices'] = $invStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Recent Payments
        $payStmt = $pdo->prepare("SELECT id, payment_no, payment_date, amount, payment_method, transaction_id, reference FROM payments WHERE customer_id = ? ORDER BY id DESC LIMIT 10");
        $payStmt->execute([$id]);
        $client['recent_payments'] = $payStmt->fetchAll(PDO::FETCH_ASSOC);

        // Current Ledger Balance
        $balStmt = $pdo->prepare("SELECT balance FROM client_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1");
        $balStmt->execute([$id]);
        $client['ledger_balance'] = (float)($balStmt->fetchColumn() ?: 0);

        json_success(['customer' => $client], 'Customer detail loaded');
    }

    // 1B. CLIENT STATEMENT OF ACCOUNT / LEDGER
    if ($action === 'ledger' && $id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM client_ledger WHERE customer_id = ? ORDER BY entry_date ASC, id ASC");
        $stmt->execute([$id]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        json_success([
            'customer_id' => $id,
            'entries'     => $entries
        ], 'Customer ledger loaded');
    }

    // 1C. CLIENT QUOTATIONS
    if ($action === 'quotations' && $id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM client_quotations WHERE customer_id = ? ORDER BY quotation_date DESC, id DESC");
        $stmt->execute([$id]);
        $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        json_success([
            'customer_id' => $id,
            'quotations'  => $quotations
        ], 'Customer quotations loaded');
    }

    // 1D. MONTHLY BILLING SUMMARY & STATUS (FOR RECURRING INVOICING - OPTIMIZED BATCH QUERY)
    if ($action === 'monthly_billing') {
        $selectedMonth = trim($_GET['month'] ?? date('Y-m'));
        $year     = (int)date('Y', strtotime($selectedMonth . '-01'));
        $monthNum = (int)date('m', strtotime($selectedMonth . '-01'));

        $stmt = $pdo->query("SELECT id, name, client_type, monthly_amount, monthly_service_name, billing_day, status, auto_invoice FROM customers WHERE client_type = 'monthly' ORDER BY name ASC");
        $allMonthly = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $clientIds = array_column($allMonthly, 'id');
        $invoicesByClient = [];
        if (!empty($clientIds)) {
            $inPlaceholders = implode(',', array_fill(0, count($clientIds), '?'));
            $minDate = date('Y-m-d', strtotime('-40 days', strtotime($selectedMonth . '-01')));
            $maxDate = date('Y-m-d', strtotime('+40 days', strtotime($selectedMonth . '-28')));
            $invParams = array_merge($clientIds, [$minDate, $maxDate]);
            $invStmt = $pdo->prepare("
                SELECT id, customer_id, invoice_no, invoice_date, total_amount, status, due_date, amount_paid, amount_due, notes
                FROM invoices 
                WHERE customer_id IN ($inPlaceholders) AND invoice_date BETWEEN ? AND ? AND status != 'cancelled'
            ");
            $invStmt->execute($invParams);
            $allInvs = $invStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allInvs as $inv) {
                $invoicesByClient[$inv['customer_id']][] = $inv;
            }
        }

        $billingList = [];
        foreach ($allMonthly as $c) {
            $bDay = max(1, min(28, (int)($c['billing_day'] ?: 1)));
            $cycleEndStr = sprintf('%04d-%02d-%02d', $year, $monthNum, $bDay);
            $cycleStartStr = date('Y-m-d', strtotime('-1 month', strtotime($cycleEndStr)));

            // Match invoice in pre-fetched batch array (prevents N+1 database queries)
            $matchedInv = null;
            if (!empty($invoicesByClient[$c['id']])) {
                $startWin = strtotime('-5 days', strtotime($cycleEndStr));
                $endWin   = strtotime('+5 days', strtotime($cycleEndStr));
                $notePattern = date('d M', strtotime($cycleStartStr));
                foreach ($invoicesByClient[$c['id']] as $cand) {
                    $candDate = strtotime($cand['invoice_date']);
                    if (($candDate >= $startWin && $candDate <= $endWin) || (strpos($cand['notes'] ?? '', $notePattern) !== false)) {
                        $matchedInv = $cand;
                        break;
                    }
                }
            }

            $billingList[] = [
                'id'                   => (int)$c['id'],
                'name'                 => $c['name'],
                'monthly_amount'       => (float)($c['monthly_amount'] ?? 0),
                'monthly_service_name' => $c['monthly_service_name'] ?? '',
                'billing_day'          => $bDay,
                'status'               => $c['status'],
                'auto_invoice'         => (int)($c['auto_invoice'] ?? 1),
                'cycle_start'          => $cycleStartStr,
                'cycle_end'            => $cycleEndStr,
                'invoice_generated'    => !empty($matchedInv),
                'linked_invoice'       => $matchedInv ?: null
            ];
        }

        json_success([
            'month'   => $selectedMonth,
            'clients' => $billingList
        ], 'Monthly billing status loaded');
    }

    // 1E. LIST ALL CUSTOMERS (WITH SEARCH, FILTERS, PAGINATION, SORTING)
    $typeFilter   = trim($_GET['type'] ?? '');
    $statusFilter = trim($_GET['status'] ?? '');
    $search       = trim($_GET['q'] ?? '');
    $sortCol      = trim($_GET['sort'] ?? 'id');
    $sortOrder    = strtolower(trim($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $pagination   = get_pagination_params(20, 100);

    $allowedSort = ['id', 'name', 'created_at', 'monthly_amount', 'status'];
    if (!in_array($sortCol, $allowedSort)) {
        $sortCol = 'id';
    }

    $where = ["1=1"];
    $params = [];

    if (!empty($typeFilter)) {
        $where[] = "c.client_type = ?";
        $params[] = $typeFilter;
    }
    if (!empty($statusFilter)) {
        $where[] = "c.status = ?";
        $params[] = $statusFilter;
    }
    if (!empty($search)) {
        $where[] = "(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.contact_person LIKE ? OR c.gstin LIKE ?)";
        $term = "%" . sanitize_like_term($search) . "%";
        $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
    }

    $whereSql = implode(" AND ", $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $whereSql");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT c.*,
          COALESCE((SELECT SUM(total_amount) FROM invoices WHERE customer_id = c.id AND status != 'cancelled'), 0) AS total_billed,
          COALESCE((SELECT SUM(amount) FROM payments WHERE customer_id = c.id), 0) AS total_received
        FROM customers c
        WHERE $whereSql
        ORDER BY c.{$sortCol} {$sortOrder}
        LIMIT ? OFFSET ?
    ");

    $bindIdx = 1;
    foreach ($params as $p) {
        $stmt->bindValue($bindIdx++, $p);
    }
    $stmt->bindValue($bindIdx++, $pagination['limit'], PDO::PARAM_INT);
    $stmt->bindValue($bindIdx++, $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $clients = [];
    foreach ($rows as $r) {
        $billed = (float)$r['total_billed'];
        $received = (float)$r['total_received'];
        $balance = max(0, $billed - $received);

        $clients[] = [
            'id'                  => (int)$r['id'],
            'name'                => $r['name'],
            'client_type'         => $r['client_type'] ?? 'monthly',
            'status'              => $r['status'] ?? 'active',
            'contact_person'      => $r['contact_person'] ?? '',
            'phone'               => $r['phone'] ?? '',
            'email'               => $r['email'] ?? '',
            'city'                => $r['city'] ?? '',
            'state'               => $r['state'] ?? '',
            'monthly_service'     => $r['monthly_service_name'] ?? '',
            'monthly_amount'      => (float)($r['monthly_amount'] ?? 0),
            'billing_day'         => (int)($r['billing_day'] ?? 1),
            'due_days'            => (int)($r['due_days'] ?? 10),
            'total_billed'        => $billed,
            'total_received'      => $received,
            'outstanding_balance' => $balance,
            'created_at'          => $r['created_at']
        ];
    }

    paginate_response($clients, $totalRecords, $pagination['page'], $pagination['limit'], 'Customers loaded', ['customers' => $clients]);
}

// ====================================================
// 2. POST /api/customers.php (CREATE or STATUS CHANGE)
// ====================================================
if ($method === 'POST') {
    // 2A. QUICK STATUS CHANGE
    if ($action === 'change_status' || $action === 'update_status') {
        require_role_api(['admin', 'super admin', 'manager', 'sales manager'], $auth);
        $newStatus = strtolower(trim($input['status'] ?? ''));
        if ($id <= 0 || !in_array($newStatus, ['lead', 'active', 'inactive', 'completed', 'archived'])) {
            json_error('Valid customer ID and status (lead/active/inactive/completed/archived) are required', 400);
        }

        $upd = $pdo->prepare("UPDATE customers SET status = ? WHERE id = ?");
        $upd->execute([$newStatus, $id]);
        log_audit('Customer', 'Status Changed to ' . $newStatus, $id, ['status' => $newStatus]);

        try {
            require_once __DIR__ . '/../app/Realtime/RealtimeService.php';
            \App\Realtime\RealtimeService::getInstance($pdo)->emit(
                'crm.status_changed',
                'customer',
                $id,
                'status_change',
                ['id' => $id, 'new_status' => $newStatus],
                ['admin', 'manager', 'sales manager', 'staff'],
                null,
                $auth['user_id']
            );
        } catch (\Throwable $e) {}

        json_success(['id' => $id, 'status' => $newStatus], 'Customer status updated successfully');
    }

    // 2B. UPDATE OVERVIEW & SCOPE NOTES
    if ($action === 'overview_notes') {
        require_role_api(['admin', 'super admin', 'manager', 'sales manager'], $auth);
        if ($id <= 0) json_error('Customer ID required', 400);

        $notes = trim($input['overview_notes'] ?? '');
        $upd = $pdo->prepare("UPDATE customers SET overview_notes = ? WHERE id = ?");
        $upd->execute([$notes, $id]);

        log_audit('Customer', 'Updated Overview Notes', $id);
        json_success(['id' => $id], 'Overview notes updated successfully');
    }

    // 2C. UPLOAD / ADD CLIENT QUOTATION
    if ($action === 'upload_quotation') {
        require_role_api(['admin', 'super admin', 'manager', 'sales manager'], $auth);
        if ($id <= 0) json_error('Customer ID required', 400);

        $title         = trim($input['title'] ?? '');
        $quotationNo   = trim($input['quotation_no'] ?? '');
        $amount        = (float)($input['amount'] ?? 0);
        $quotationType = $input['quotation_type'] ?? 'sent';
        $status        = $input['status'] ?? 'approved';
        $quotationDate = !empty($input['quotation_date']) ? $input['quotation_date'] : date('Y-m-d');
        $scopeOfWork   = trim($input['scope_of_work'] ?? '');

        if (empty($title)) {
            json_error('Quotation title is required', 400);
        }

        $filePath = null;
        if (!empty($_FILES['quotation_file']['name'])) {
            $qUploadDir = __DIR__ . '/../assets/uploads/quotations/';
            if (!is_dir($qUploadDir)) {
                @mkdir($qUploadDir, 0777, true);
            }
            $ext = strtolower(pathinfo($_FILES['quotation_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'])) {
                $fileName = 'QUOT_' . $id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['quotation_file']['tmp_name'], $qUploadDir . $fileName)) {
                    $filePath = 'assets/uploads/quotations/' . $fileName;
                }
            }
        } elseif (!empty($input['file_base64']) && !empty($input['file_name'])) {
            $qUploadDir = __DIR__ . '/../assets/uploads/quotations/';
            if (!is_dir($qUploadDir)) {
                @mkdir($qUploadDir, 0777, true);
            }
            $ext = strtolower(pathinfo($input['file_name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'])) {
                $fileName = 'QUOT_' . $id . '_' . time() . '.' . $ext;
                file_put_contents($qUploadDir . $fileName, base64_decode($input['file_base64']));
                $filePath = 'assets/uploads/quotations/' . $fileName;
            }
        }

        $insQ = $pdo->prepare("
            INSERT INTO client_quotations (
                customer_id, quotation_no, title, amount, scope_of_work, quotation_type, status, file_path, quotation_date, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $insQ->execute([$id, $quotationNo, $title, $amount, $scopeOfWork, $quotationType, $status, $filePath, $quotationDate, $auth['user_id']]);
        $newQId = (int)$pdo->lastInsertId();

        log_audit('Quotation', 'Created Quotation', $newQId, ['customer_id' => $id, 'title' => $title]);
        json_success(['id' => $newQId, 'title' => $title, 'file_path' => $filePath], 'Quotation created successfully', 201);
    }

    // 2D. UPDATE QUOTATION STATUS
    if ($action === 'update_quotation_status') {
        require_role_api(['admin', 'super admin', 'manager', 'sales manager'], $auth);
        $qId = (int)($input['quotation_id'] ?? ($input['id'] ?? 0));
        $newStatus = trim($input['status'] ?? '');

        if ($qId <= 0 || empty($newStatus)) {
            json_error('Quotation ID and status are required', 400);
        }

        $pdo->prepare("UPDATE client_quotations SET status = ? WHERE id = ?")->execute([$newStatus, $qId]);
        log_audit('Quotation', 'Updated Quotation Status', $qId, ['status' => $newStatus]);
        json_success(['id' => $qId, 'status' => $newStatus], 'Quotation status updated');
    }

    // 2E. GENERATE MONTHLY RECURRING INVOICE (SINGLE or BULK)
    if ($action === 'generate_monthly_invoice') {
        require_role_api(['admin', 'super admin', 'manager', 'finance manager'], $auth);
        $mode = $input['mode'] ?? 'single'; // 'single' or 'bulk'

        if ($mode === 'single') {
            $customerId  = (int)($input['customer_id'] ?? $id);
            $invDate     = !empty($input['invoice_date']) ? $input['invoice_date'] : date('Y-m-d');
            $dueDate     = !empty($input['due_date']) ? $input['due_date'] : date('Y-m-d', strtotime($invDate . ' + 10 days'));
            $customAmt   = (float)($input['custom_amount'] ?? 0);
            $serviceNote = trim($input['service_note'] ?? '');

            $cStmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $cStmt->execute([$customerId]);
            $client = $cStmt->fetch(PDO::FETCH_ASSOC);

            if (!$client) json_error('Client not found', 404);
            if ($customAmt <= 0) $customAmt = (float)$client['monthly_amount'];
            if ($customAmt <= 0) json_error('Valid monthly amount is required', 400);

            // Generate invoice number
            $ctrRow = $pdo->query("SELECT last_number FROM invoice_counter WHERE id = 1 FOR UPDATE")->fetch();
            $newNum = ($ctrRow ? (int)$ctrRow['last_number'] : 10000) + 1;
            $pdo->exec("UPDATE invoice_counter SET last_number = {$newNum} WHERE id = 1");
            $invNo = 'INV-' . $newNum;

            $desc = !empty($serviceNote) ? $serviceNote : ($client['monthly_service_name'] ?: 'Monthly Retainer Service');

            $ins = $pdo->prepare("INSERT INTO invoices (invoice_no, invoice_date, payment_mode, customer_id, place_of_supply, notes, taxable_value, tax_total, total_amount, status, due_date, amount_paid, amount_due, created_by, created_at) VALUES (?, ?, 'Pending', ?, '', ?, ?, 0, ?, 'sent', ?, 0, ?, ?, NOW())");
            $ins->execute([$invNo, $invDate, $customerId, $desc, $customAmt, $customAmt, $dueDate, $customAmt, $auth['user_id']]);
            $invId = (int)$pdo->lastInsertId();

            $insItem = $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, qty, rate, line_total, tax_rate) VALUES (?, ?, 1, ?, ?, 0)");
            $insItem->execute([$invId, $desc, $customAmt, $customAmt]);

            post_invoice_to_ledger($invId);

            log_audit('Invoice', 'Generated Monthly Invoice', $invId, ['invoice_no' => $invNo, 'client' => $client['name']]);
            json_success(['id' => $invId, 'invoice_no' => $invNo, 'total_amount' => $customAmt], 'Monthly invoice generated successfully', 201);
        } elseif ($mode === 'bulk') {
            $month = (int)date('m');
            $year  = (int)date('Y');
            $monthlyClients = $pdo->query("SELECT * FROM customers WHERE client_type = 'monthly' AND status = 'active' AND auto_invoice = 1")->fetchAll(PDO::FETCH_ASSOC);
            $generated = [];

            foreach ($monthlyClients as $client) {
                $bDay = min(28, (int)($client['billing_day'] ?: 1));
                $cycleEndStr = sprintf('%04d-%02d-%02d', $year, $month, $bDay);
                $cycleStartStr = date('Y-m-d', strtotime('-1 month', strtotime($cycleEndStr)));

                // Check if already created
                $checkInv = $pdo->prepare("SELECT id FROM invoices WHERE customer_id = ? AND (invoice_date BETWEEN ? AND ? OR notes LIKE ?) AND status != 'cancelled'");
                $checkInv->execute([$client['id'], date('Y-m-d', strtotime('-5 days', strtotime($cycleEndStr))), date('Y-m-d', strtotime('+5 days', strtotime($cycleEndStr))), "%" . date('d M', strtotime($cycleStartStr)) . "%"]);

                if (!$checkInv->fetch() && (float)$client['monthly_amount'] > 0) {
                    $ctrRow = $pdo->query("SELECT last_number FROM invoice_counter WHERE id = 1 FOR UPDATE")->fetch();
                    $newNum = ($ctrRow ? (int)$ctrRow['last_number'] : 10000) + 1;
                    $pdo->exec("UPDATE invoice_counter SET last_number = {$newNum} WHERE id = 1");
                    $invNo = 'INV-' . $newNum;

                    $desc = ($client['monthly_service_name'] ?: 'Monthly Retainer Service') . " (" . date('d M Y', strtotime($cycleStartStr)) . " - " . date('d M Y', strtotime($cycleEndStr)) . ")";
                    $amt = (float)$client['monthly_amount'];
                    $dueDate = date('Y-m-d', strtotime('+10 days', strtotime($cycleEndStr)));

                    $ins = $pdo->prepare("INSERT INTO invoices (invoice_no, invoice_date, payment_mode, customer_id, place_of_supply, notes, taxable_value, tax_total, total_amount, status, due_date, amount_paid, amount_due, created_by, created_at) VALUES (?, ?, 'Pending', ?, '', ?, ?, 0, ?, 'sent', ?, 0, ?, ?, NOW())");
                    $ins->execute([$invNo, $cycleEndStr, $client['id'], $desc, $amt, $amt, $dueDate, $amt, $auth['user_id']]);
                    $invId = (int)$pdo->lastInsertId();

                    $insItem = $pdo->prepare("INSERT INTO invoice_items (invoice_id, name, qty, rate, line_total, tax_rate) VALUES (?, ?, 1, ?, ?, 0)");
                    $insItem->execute([$invId, $desc, $amt, $amt]);

                    post_invoice_to_ledger($invId);
                    $generated[] = ['client' => $client['name'], 'invoice_no' => $invNo, 'amount' => $amt];
                }
            }

            json_success(['count' => count($generated), 'generated' => $generated], count($generated) . ' recurring monthly invoices generated');
        }
    }

    // 2B. CREATE NEW CUSTOMER
    require_role_api(['admin', 'super admin', 'manager', 'sales manager'], $auth);

    validate_or_fail($input, [
        'name' => 'required|min:2|max:150'
    ]);

    $name = trim($input['name'] ?? '');
    $clientType     = $input['client_type'] ?? 'monthly';
    $status         = $input['status'] ?? 'active';
    $contactPerson  = trim($input['contact_person'] ?? '');
    $phone          = trim($input['phone'] ?? '');
    $alternatePhone = trim($input['alternate_phone'] ?? '');
    $whatsapp       = trim($input['whatsapp'] ?? '');
    $email          = trim($input['email'] ?? '');
    $gstin          = trim($input['gstin'] ?? '');
    $pan            = trim($input['pan'] ?? '');
    $billingAddress = trim($input['billing_address'] ?? '');
    $shippingAddress= trim($input['shipping_address'] ?? '');
    $city           = trim($input['city'] ?? '');
    $state          = trim($input['state'] ?? '');
    $pincode        = trim($input['pincode'] ?? '');
    $website        = trim($input['website'] ?? '');
    $notes          = trim($input['notes'] ?? '');
    $monthlyService = trim($input['monthly_service_name'] ?? '');
    $monthlyAmount  = (float)($input['monthly_amount'] ?? 0);
    $billingStart   = !empty($input['billing_start_date']) ? $input['billing_start_date'] : date('Y-m-d');
    $billingDay     = max(1, min(28, (int)($input['billing_day'] ?? 1)));
    $dueDays        = max(1, (int)($input['due_days'] ?? 10));
    $autoInvoice    = isset($input['auto_invoice']) ? (int)$input['auto_invoice'] : 1;
    $onboardDate    = !empty($input['onboard_date']) ? $input['onboard_date'] : date('Y-m-d');

    if (empty($name)) {
        json_error("Customer name is required.", 422, ['field' => 'name']);
    }
    if (empty($billingAddress)) {
        json_error("Billing address is required.", 422, ['field' => 'billing_address']);
    }
    if (empty($city)) {
        json_error("City is required.", 422, ['field' => 'city']);
    }
    if (empty($state)) {
        json_error("State is required.", 422, ['field' => 'state']);
    }
    if (empty($pincode)) {
        json_error("PIN code is required.", 422, ['field' => 'pincode']);
    }

    // Duplicate Check: Check existing email if provided
    if (!empty($email)) {
        $dupChk = $pdo->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
        $dupChk->execute([$email]);
        if ($dupChk->fetchColumn()) {
            json_error("A customer with email '{$email}' already exists.", 409, [
                'code'  => 'DUPLICATE_RECORD',
                'field' => 'email'
            ]);
        }
    }

    $sql = "INSERT INTO customers 
        (name, client_type, status, contact_person, phone, alternate_phone, whatsapp, email, 
         gstin, pan, billing_address, shipping_address, city, state, pincode, website, notes,
         monthly_service_name, monthly_amount, billing_start_date, billing_day, due_days, auto_invoice, onboard_date, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $name, $clientType, $status, $contactPerson, $phone, $alternatePhone, $whatsapp, $email,
            $gstin, $pan, $billingAddress, $shippingAddress, $city, $state, $pincode, $website, $notes,
            $monthlyService, $monthlyAmount, $billingStart, $billingDay, $dueDays, $autoInvoice, $onboardDate
        ]);
        $newId = (int)$pdo->lastInsertId();
    } catch (\PDOException $e) {
        handle_db_exception($e, 'customer');
    }

    log_audit('Customer', 'Created Client', $newId, ['name' => $name, 'client_type' => $clientType]);

    try {
        require_once __DIR__ . '/../app/Realtime/RealtimeService.php';
        \App\Realtime\RealtimeService::getInstance($pdo)->emit(
            'crm.record_created',
            'customer',
            $newId,
            'create',
            [
                'id'          => $newId,
                'name'        => $name,
                'client_type' => $clientType,
                'status'      => $status,
                'created_at'  => date('Y-m-d H:i:s')
            ],
            ['admin', 'manager', 'sales manager', 'staff'],
            null,
            $auth['user_id']
        );
    } catch (\Throwable $e) {}

    json_success(['id' => $newId, 'name' => $name], 'Customer created successfully', 201);
}

// ====================================================
// 3. PUT /api/customers.php (UPDATE CUSTOMER)
// ====================================================
if ($method === 'PUT') {
    require_role_api(['admin', 'super admin', 'manager', 'sales manager'], $auth);

    if ($id <= 0) {
        json_error('Valid Customer ID is required for update', 400, ['code' => 'INVALID_ID']);
    }

    $cStmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
    $cStmt->execute([$id]);
    $existing = $cStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        json_error('Customer not found', 404, ['code' => 'CUSTOMER_NOT_FOUND']);
    }

    $name           = trim($input['name'] ?? $existing['name']);
    $clientType     = $input['client_type'] ?? $existing['client_type'];
    $status         = $input['status'] ?? $existing['status'];
    $contactPerson  = trim($input['contact_person'] ?? $existing['contact_person']);
    $phone          = trim($input['phone'] ?? $existing['phone']);
    $alternatePhone = trim($input['alternate_phone'] ?? $existing['alternate_phone']);
    $whatsapp       = trim($input['whatsapp'] ?? $existing['whatsapp']);
    $email          = trim($input['email'] ?? $existing['email']);
    $gstin          = trim($input['gstin'] ?? $existing['gstin']);
    $pan            = trim($input['pan'] ?? $existing['pan']);
    $billingAddress = trim($input['billing_address'] ?? $existing['billing_address']);
    $shippingAddress= trim($input['shipping_address'] ?? $existing['shipping_address']);
    $city           = trim($input['city'] ?? $existing['city']);
    $state          = trim($input['state'] ?? $existing['state']);
    $pincode        = trim($input['pincode'] ?? $existing['pincode']);
    $website        = trim($input['website'] ?? $existing['website']);
    $notes          = trim($input['notes'] ?? $existing['notes']);
    $monthlyService = trim($input['monthly_service_name'] ?? $existing['monthly_service_name']);
    $monthlyAmount  = isset($input['monthly_amount']) ? (float)$input['monthly_amount'] : (float)$existing['monthly_amount'];
    $billingDay     = isset($input['billing_day']) ? (int)$input['billing_day'] : (int)$existing['billing_day'];
    $dueDays        = isset($input['due_days']) ? (int)$input['due_days'] : (int)$existing['due_days'];
    $autoInvoice    = isset($input['auto_invoice']) ? (int)$input['auto_invoice'] : (int)$existing['auto_invoice'];

    // Check duplicate email on update
    if (!empty($email) && $email !== ($existing['email'] ?? '')) {
        $dupChk = $pdo->prepare("SELECT id FROM customers WHERE email = ? AND id != ? LIMIT 1");
        $dupChk->execute([$email, $id]);
        if ($dupChk->fetchColumn()) {
            json_error("A customer with email '{$email}' already exists.", 409, [
                'code'  => 'DUPLICATE_RECORD',
                'field' => 'email'
            ]);
        }
    }

    $sql = "UPDATE customers SET 
        name = ?, client_type = ?, status = ?, contact_person = ?, phone = ?, alternate_phone = ?, whatsapp = ?, email = ?,
        gstin = ?, pan = ?, billing_address = ?, shipping_address = ?, city = ?, state = ?, pincode = ?, website = ?, notes = ?,
        monthly_service_name = ?, monthly_amount = ?, billing_day = ?, due_days = ?, auto_invoice = ?
        WHERE id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $name, $clientType, $status, $contactPerson, $phone, $alternatePhone, $whatsapp, $email,
            $gstin, $pan, $billingAddress, $shippingAddress, $city, $state, $pincode, $website, $notes,
            $monthlyService, $monthlyAmount, $billingDay, $dueDays, $autoInvoice, $id
        ]);
    } catch (\PDOException $e) {
        handle_db_exception($e, 'customer');
    }

    log_audit('Customer', 'Updated Client', $id, ['name' => $name]);

    try {
        require_once __DIR__ . '/../app/Realtime/RealtimeService.php';
        \App\Realtime\RealtimeService::getInstance($pdo)->emit(
            'crm.record_updated',
            'customer',
            $id,
            'update',
            [
                'id'          => $id,
                'name'        => $name,
                'client_type' => $clientType,
                'status'      => $status,
                'updated_at'  => date('Y-m-d H:i:s')
            ],
            ['admin', 'manager', 'sales manager', 'staff'],
            null,
            $auth['user_id']
        );
    } catch (\Throwable $e) {}

    json_success(['id' => $id, 'name' => $name], 'Customer updated successfully');
}

// ====================================================
// 4. DELETE /api/customers.php (DELETE CUSTOMER)
// ====================================================
if ($method === 'DELETE') {
    require_role_api(['admin', 'super admin', 'manager'], $auth);

    // DELETE QUOTATION
    if ($action === 'delete_quotation') {
        $qId = (int)($input['quotation_id'] ?? ($_GET['quotation_id'] ?? $id));
        if ($qId <= 0) json_error('Quotation ID required', 400);

        $qStmt = $pdo->prepare("SELECT * FROM client_quotations WHERE id = ? LIMIT 1");
        $qStmt->execute([$qId]);
        $qRec = $qStmt->fetch(PDO::FETCH_ASSOC);

        if (!$qRec) json_error('Quotation not found', 404);

        if (!empty($qRec['file_path']) && file_exists(__DIR__ . '/../' . $qRec['file_path'])) {
            @unlink(__DIR__ . '/../' . $qRec['file_path']);
        }

        $pdo->prepare("DELETE FROM client_quotations WHERE id = ?")->execute([$qId]);
        log_audit('Quotation', 'Deleted Quotation', $qId, ['title' => $qRec['title']]);
        json_success(['id' => $qId], 'Quotation deleted successfully');
    }

    require_role_api(['admin', 'super admin'], $auth); // Admin only for customer deletion

    if ($id <= 0) {
        json_error('Valid Customer ID is required for deletion', 400);
    }

    $cStmt = $pdo->prepare("SELECT id, name FROM customers WHERE id = ? LIMIT 1");
    $cStmt->execute([$id]);
    $existing = $cStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        json_error('Customer not found', 404);
    }

    // Check if customer has invoices
    $invCount = (int)($pdo->query("SELECT COUNT(*) FROM invoices WHERE customer_id = $id")->fetchColumn() ?: 0);
    if ($invCount > 0) {
        // Soft delete / archive to preserve financial records
        $pdo->prepare("UPDATE customers SET status = 'archived' WHERE id = ?")->execute([$id]);
        log_audit('Customer', 'Archived Client with Invoices', $id, ['name' => $existing['name']]);

        try {
            require_once __DIR__ . '/../app/Realtime/RealtimeService.php';
            \App\Realtime\RealtimeService::getInstance($pdo)->emit(
                'crm.status_changed',
                'customer',
                $id,
                'status_change',
                ['id' => $id, 'name' => $existing['name'], 'archived' => true],
                ['admin', 'manager', 'sales manager', 'staff'],
                null,
                $auth['user_id']
            );
        } catch (\Throwable $e) {}

        json_success(['id' => $id, 'archived' => true], 'Customer has existing invoices, so account was archived.');
    } else {
        $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
        log_audit('Customer', 'Deleted Client', $id, ['name' => $existing['name']]);

        try {
            require_once __DIR__ . '/../app/Realtime/RealtimeService.php';
            \App\Realtime\RealtimeService::getInstance($pdo)->emit(
                'crm.record_deleted',
                'customer',
                $id,
                'delete',
                ['id' => $id, 'name' => $existing['name']],
                ['admin', 'manager', 'sales manager', 'staff'],
                null,
                $auth['user_id']
            );
        } catch (\Throwable $e) {}

        json_success(['id' => $id], 'Customer deleted successfully');
    }
}

json_error('Method Not Allowed', 405);
