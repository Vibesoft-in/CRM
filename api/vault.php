<?php
// Crm and backend/api/vault.php — RESTful API for Credential Vault (AES-256-GCM)
require_once __DIR__ . '/mobile_helper.php';
require_once __DIR__ . '/../includes/vault_helper.php';
require_once __DIR__ . '/../includes/audit_helper.php';

$auth = authenticate_mobile();
// Strict RBAC: Only Admin and Managers can access Credential Vault
require_role_api(['admin', 'super admin', 'manager'], $auth);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = get_json_input();
$action = $_GET['action'] ?? ($input['action'] ?? '');
$id = (int)($_GET['id'] ?? ($input['id'] ?? 0));

// ====================================================
// 1. GET /api/vault.php (LIST CREDENTIALS)
// ====================================================
if ($method === 'GET') {
    if ($action === 'generate_password') {
        $length = max(8, min(64, (int)($_GET['length'] ?? 16)));
        $pwd = generate_strong_password($length);
        json_success(['password' => $pwd], 'Password generated');
    }

    // 1A. ACCESS LOGS AUDIT TRAIL
    if ($action === 'logs' && $id > 0) {
        $stmt = $pdo->prepare("
            SELECT a.id, a.action, a.details, a.created_at, u.name as user_name, u.email as user_email
            FROM audit_logs a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.module = 'Credential Vault' AND a.record_id = ?
            ORDER BY a.id DESC
            LIMIT 50
        ");
        $stmt->execute([$id]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_success(['credential_id' => $id, 'logs' => $logs], 'Access logs loaded');
    }

    // 1B. SOCIAL MEDIA VAULT FILTER
    if ($action === 'social_media') {
        $search = trim($_GET['q'] ?? '');
        $where = ["c.platform IN ('Social Media','Instagram','Facebook','LinkedIn','YouTube','Google Business','Meta Ads','Twitter','TikTok')"];
        $params = [];
        if (!empty($search)) {
            $where[] = "(cust.name LIKE ? OR c.account_name LIKE ? OR c.username_email LIKE ?)";
            $term = "%" . sanitize_like_term($search) . "%";
            $params = [$term, $term, $term];
        }
        $whereSql = implode(" AND ", $where);
        $stmt = $pdo->prepare("
            SELECT c.id, c.customer_id, c.platform, c.account_name, c.username_email, c.login_url, 
                   c.account_id, c.two_factor_enabled, c.created_at, cust.name AS customer_name
            FROM credentials c
            JOIN customers cust ON cust.id = c.customer_id
            WHERE $whereSql
            ORDER BY cust.name ASC, c.platform ASC
        ");
        $stmt->execute($params);
        json_success(['accounts' => $stmt->fetchAll(PDO::FETCH_ASSOC)], 'Social media accounts loaded');
    }

    $customerId = (int)($_GET['customer_id'] ?? 0);
    $where = ["1=1"];
    $params = [];

    if ($customerId > 0) {
        $where[] = "c.customer_id = ?";
        $params[] = $customerId;
    }

    $whereSql = implode(" AND ", $where);
    $stmt = $pdo->prepare("
        SELECT c.id, c.customer_id, c.platform, c.account_name, c.username_email, c.login_url, 
               c.account_id, c.two_factor_enabled, c.created_at, cust.name as client_name
        FROM credentials c
        JOIN customers cust ON cust.id = c.customer_id
        WHERE $whereSql
        ORDER BY c.id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_success(['credentials' => $rows], 'Credentials loaded (masked)');
}

// ====================================================
// 2. POST /api/vault.php (REVEAL PASSWORD or CREATE)
// ====================================================
if ($method === 'POST') {
    // 2A. REVEAL PASSWORD (STRICT ADMIN AUDIT CHECK + RATE LIMITING)
    if ($action === 'reveal') {
        require_role_api(['admin', 'super admin'], $auth); // Admin only

        // Enforce rate limiting: Max 15 reveals per minute per admin
        check_api_rate_limit('vault_reveal', (string)$auth['user_id'], 15, 60);

        $credId = $id > 0 ? $id : (int)($input['credential_id'] ?? 0);
        if ($credId <= 0) json_error('Credential ID is required', 400, ['code' => 'INVALID_ID']);

        $stmt = $pdo->prepare("
            SELECT c.*, cust.name as client_name 
            FROM credentials c 
            JOIN customers cust ON cust.id = c.customer_id 
            WHERE c.id = ? LIMIT 1
        ");
        $stmt->execute([$credId]);
        $cred = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cred) json_error('Credential not found', 404);

        $decrypted = decrypt_credential($cred['encrypted_password']);

        // Log security audit (Never log decrypted password)
        log_audit('Credential Vault', 'Revealed Password via API', $credId, [
            'client'   => $cred['client_name'],
            'platform' => $cred['platform'],
            'account'  => $cred['account_name']
        ]);

        json_success([
            'id'         => (int)$cred['id'],
            'platform'   => $cred['platform'],
            'account'    => $cred['account_name'],
            'username'   => $cred['username_email'],
            'password'   => $decrypted,
            'url'        => $cred['login_url'],
            'two_factor' => $cred['two_factor_info']
        ], 'Credential revealed securely');
    }

    // 2B. CREATE NEW CREDENTIAL (AES-256-GCM ENCRYPTION)
    $customerId   = (int)($input['customer_id'] ?? 0);
    $platform     = trim($input['platform'] ?? 'Other');
    $accountName  = trim($input['account_name'] ?? '');
    $username     = trim($input['username_email'] ?? ($input['username'] ?? ''));
    $plainPass    = trim($input['password'] ?? '');
    $loginUrl     = trim($input['login_url'] ?? '');
    $accountId    = trim($input['account_id'] ?? '');
    $twoFactor    = isset($input['two_factor_enabled']) ? (int)$input['two_factor_enabled'] : 0;
    $twoFactorInfo= trim($input['two_factor_info'] ?? '');
    $recoveryEmail= trim($input['recovery_email'] ?? '');
    $recoveryPhone= trim($input['recovery_phone'] ?? '');
    $notes        = trim($input['notes'] ?? '');

    if ($customerId <= 0 || empty($accountName) || empty($plainPass)) {
        json_error('Customer ID, Account Name, and Password are required', 400);
    }

    $encryptedPass = encrypt_credential($plainPass);

    try {
        $ins = $pdo->prepare("
            INSERT INTO credentials 
              (customer_id, platform, account_name, username_email, encrypted_password, login_url, account_id, two_factor_enabled, two_factor_info, recovery_email, recovery_phone, notes, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $ins->execute([
            $customerId, $platform, $accountName, $username, $encryptedPass, $loginUrl, $accountId,
            $twoFactor, $twoFactorInfo, $recoveryEmail, $recoveryPhone, $notes
        ]);
        $newId = (int)$pdo->lastInsertId();
    } catch (\PDOException $e) {
        handle_db_exception($e, 'credential');
    }

    log_audit('Credential Vault', 'Created Credential Entry', $newId, ['platform' => $platform, 'account' => $accountName]);

    json_success(['id' => $newId, 'platform' => $platform], 'Credential saved and encrypted securely', 201);
}

// ====================================================
// 3. PUT /api/vault.php?id={id} (UPDATE CREDENTIAL)
// ====================================================
if ($method === 'PUT') {
    require_role_api(['admin', 'super admin', 'manager'], $auth);
    if ($id <= 0) json_error('Credential ID is required', 400);

    $stmt = $pdo->prepare("SELECT * FROM credentials WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) json_error('Credential not found', 404);

    $platform     = trim($input['platform'] ?? $existing['platform']);
    $accountName  = trim($input['account_name'] ?? $existing['account_name']);
    $username     = trim($input['username_email'] ?? ($input['username'] ?? $existing['username_email']));
    $loginUrl     = trim($input['login_url'] ?? $existing['login_url']);
    $accountId    = trim($input['account_id'] ?? $existing['account_id']);
    $twoFactor    = isset($input['two_factor_enabled']) ? (int)$input['two_factor_enabled'] : (int)$existing['two_factor_enabled'];
    $twoFactorInfo= trim($input['two_factor_info'] ?? $existing['two_factor_info']);
    $recoveryEmail= trim($input['recovery_email'] ?? $existing['recovery_email']);
    $recoveryPhone= trim($input['recovery_phone'] ?? $existing['recovery_phone']);
    $notes        = trim($input['notes'] ?? $existing['notes']);

    $encPass = $existing['encrypted_password'];
    if (!empty($input['password'])) {
        $encPass = encrypt_credential(trim($input['password']));
    }

    $upd = $pdo->prepare("
        UPDATE credentials 
        SET platform = ?, account_name = ?, username_email = ?, encrypted_password = ?, login_url = ?, 
            account_id = ?, two_factor_enabled = ?, two_factor_info = ?, recovery_email = ?, recovery_phone = ?, notes = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $upd->execute([
        $platform, $accountName, $username, $encPass, $loginUrl, 
        $accountId, $twoFactor, $twoFactorInfo, $recoveryEmail, $recoveryPhone, $notes, $id
    ]);

    log_audit('Credential Vault', 'Updated Credential Entry', $id, ['platform' => $platform, 'account' => $accountName]);
    json_success(['id' => $id, 'platform' => $platform], 'Credential updated successfully');
}

// ====================================================
// 4. DELETE /api/vault.php?id={id}
// ====================================================
if ($method === 'DELETE') {
    require_role_api(['admin', 'super admin'], $auth);
    if ($id <= 0) json_error('Credential ID is required', 400);

    $pdo->prepare("DELETE FROM credentials WHERE id = ?")->execute([$id]);
    log_audit('Credential Vault', 'Deleted Credential', $id);

    json_success(['id' => $id], 'Credential deleted successfully');
}

json_error('Method Not Allowed', 405);
