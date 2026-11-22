<?php
// Crm and backend/api/mobile_auth.php — Authentication Controller for Mobile API
require_once __DIR__ . '/mobile_helper.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'login');
$input = get_json_input();

// ----------------------------------------------------
// 0. ACTION: PING (Lightweight zero-DB latency & connectivity check)
// ----------------------------------------------------
if ($action === 'ping') {
    json_success([
        'status'      => 'online',
        'server_time' => date('Y-m-d H:i:s'),
        'service'     => 'VibeSoft Mobile API',
        'version'     => '1.0.0'
    ], 'Server is reachable');
}

// ----------------------------------------------------
// 1. ACTION: LOGIN
// ----------------------------------------------------
if ($action === 'login') {
    $loginInput = trim($input['username'] ?? ($input['email'] ?? ($input['login'] ?? '')));
    $password = trim($input['password'] ?? '');

    if (empty($loginInput) || empty($password)) {
        json_error('Email/Username and Password are required', 400, ['code' => 'MISSING_CREDENTIALS']);
    }

    // Rate Limiter Check (Brute force protection)
    if (\App\Security\RateLimiter::isLocked($loginInput)) {
        $remSec = \App\Security\RateLimiter::getRemainingLockoutSeconds($loginInput);
        $remMin = max(1, ceil($remSec / 60));
        json_error("Too many failed login attempts. Account temporarily locked for $remMin minute(s).", 429, [
            'code' => 'RATE_LIMITED',
            'retry_after_seconds' => $remSec
        ]);
    }

    // Flexible User Lookup: Email, Username, Linked Employee Code, Phone
    $stmt = $pdo->prepare("
        SELECT u.* 
        FROM users u
        LEFT JOIN employees e ON (e.user_id = u.id OR e.id = u.employee_id)
        WHERE u.email = ? OR u.name = ? OR e.employee_code = ? OR e.phone = ? OR e.email = ?
        LIMIT 1
    ");
    $stmt->execute([$loginInput, $loginInput, $loginInput, $loginInput, $loginInput]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $isValid = false;
    if ($user && !empty($user['password_hash'])) {
        if (password_verify($password, $user['password_hash'])) {
            $isValid = true;
        }
    }

    if (!$isValid) {
        $attempts = \App\Security\RateLimiter::hit($loginInput);
        \App\Security\AuditLogger::securityEvent('API_LOGIN_FAILED', [
            'identifier' => $loginInput,
            'attempts'   => $attempts,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);

        $remaining = max(0, 5 - $attempts);
        $msg = $remaining > 0 ? "Invalid login credentials. ($remaining attempts remaining)" : "Too many failed attempts. Account temporarily locked for 15 minutes.";
        json_error($msg, 401, ['code' => 'INVALID_CREDENTIALS', 'attempts_remaining' => $remaining]);
    }

    // Verify Active Status
    if (isset($user['status']) && strtolower($user['status']) === 'inactive') {
        \App\Security\AuditLogger::securityEvent('API_LOGIN_INACTIVE_USER', ['user_id' => $user['id']]);
        json_error('Your account is deactivated. Please contact administration.', 403, ['code' => 'ACCOUNT_INACTIVE']);
    }

    // Clear Rate Limiter on successful credentials
    \App\Security\RateLimiter::clear($loginInput);

    // Issue Secure 30-Day API Token
    $tokenData = issue_api_token((int)$user['id'], 30);

    // Fetch Linked Employee Details
    $employee = null;
    if (!empty($user['employee_id'])) {
        $eStmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? LIMIT 1");
        $eStmt->execute([$user['employee_id']]);
        $employee = $eStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$employee) {
        $eStmt = $pdo->prepare("SELECT * FROM employees WHERE user_id = ? OR email = ? LIMIT 1");
        $eStmt->execute([$user['id'], $user['email']]);
        $employee = $eStmt->fetch(PDO::FETCH_ASSOC);
    }

    \App\Security\AuditLogger::log('API_LOGIN_SUCCESS', 'auth', 'user', (int)$user['id']);

    // NEVER expose password_hash or internal secrets
    json_success([
        'token'      => $tokenData['token'],
        'token_type' => $tokenData['token_type'],
        'expires_at' => $tokenData['expires_at'],
        'user' => [
            'id'    => (int)$user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ],
        'employee' => $employee ? [
            'id'            => (int)$employee['id'],
            'employee_code' => $employee['employee_code'] ?? 'EMP-' . $employee['id'],
            'full_name'     => $employee['full_name'] ?? $user['name'],
            'department'    => $employee['department'] ?? 'General',
            'designation'   => $employee['designation'] ?? 'Staff',
            'phone'         => $employee['phone'] ?? '',
            'email'         => $employee['email'] ?? $user['email'],
            'profile_photo' => resolve_media_url($employee['profile_photo'] ?? null, $employee['full_name'] ?? ($user['name'] ?? null)),
            'joining_date'  => $employee['joining_date'] ?? null,
            'status'        => $employee['status'] ?? 'active'
        ] : null
    ], 'Login successful');
}

// ----------------------------------------------------
// 2. ACTION: ME (AUTHENTICATED USER DETAILS)
// ----------------------------------------------------
if ($action === 'me') {
    $auth = authenticate_mobile();
    $empData = $auth['employee'];
    if ($empData) {
        $empData['profile_photo_raw'] = $empData['profile_photo'] ?? null;
        $empData['profile_photo'] = resolve_media_url($empData['profile_photo'] ?? null, $empData['full_name'] ?? $auth['user']['name']);
    }
    json_success([
        'user' => [
            'id'            => $auth['user']['id'],
            'name'          => $auth['user']['name'],
            'email'         => $auth['user']['email'],
            'role'          => $auth['user']['role'],
            'profile_photo' => $empData ? $empData['profile_photo'] : null,
        ],
        'employee'   => $empData,
        'expires_at' => $auth['expires_at']
    ], 'Current user session loaded');
}

// ----------------------------------------------------
// 3. ACTION: REFRESH TOKEN
// ----------------------------------------------------
if ($action === 'refresh') {
    $token = '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
    }
    if (empty($token)) {
        $token = $input['token'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
    }

    if (empty($token)) {
        json_error('Token is required for refresh', 400, ['code' => 'MISSING_TOKEN']);
    }

    $newSession = refresh_api_token($token);
    json_success($newSession, 'Token refreshed successfully');
}

// ----------------------------------------------------
// 4. ACTION: LOGOUT
// ----------------------------------------------------
if ($action === 'logout') {
    $token = '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
    }
    if (empty($token)) {
        $token = $input['token'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
    }

    if (!empty($token)) {
        revoke_api_token($token);
    }

    json_success([], 'Logged out successfully');
}

// ----------------------------------------------------
// 5. ACTION: CHANGE PASSWORD
// ----------------------------------------------------
if ($action === 'change_password') {
    $auth = authenticate_mobile();
    $oldPass = trim($input['old_password'] ?? '');
    $newPass = trim($input['new_password'] ?? '');

    if (empty($oldPass) || empty($newPass)) {
        json_error('Old password and New password are required', 400);
    }
    if (strlen($newPass) < 6) {
        json_error('New password must be at least 6 characters long', 400);
    }

    $uStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $uStmt->execute([$auth['user_id']]);
    $currentHash = $uStmt->fetchColumn();

    if (!password_verify($oldPass, $currentHash)) {
        json_error('Incorrect current password', 400);
    }

    $newHash = password_hash($newPass, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $auth['user_id']]);

    \App\Security\AuditLogger::log('PASSWORD_CHANGE', 'auth', 'user', $auth['user_id']);

    json_success([], 'Password updated successfully');
}

json_error('Unknown authentication action', 400);
