<?php
// app/Security/Auth.php — Centralized Authentication & Session Security
namespace App\Security;

use PDO;

class Auth {
    private const IDLE_TIMEOUT_SECONDS = 172800; // 2 days (48 hours)
    private const ABSOLUTE_TIMEOUT_SECONDS = 2592000; // 30 days

    private static ?array $cachedUser = null;

    private static function getPdo(): PDO {
        global $pdo;
        return $pdo;
    }

    /**
     * Authoritative Server-Side Session Validity Engine
     * Evaluates:
     * 1. 2-Day Inactivity Rule (>= 172,800 seconds / 48 hours)
     * 2. Holiday Return Rule (Intervening holiday or Sunday between last activity and today)
     * 3. Approved Leave Return Rule (Intervening approved leave)
     *
     * @param PDO $pdo
     * @param int $userId
     * @param int $lastActivityTimestamp
     * @param int|null $employeeId
     * @return string|null Expiry reason code if expired, null if valid
     */
    public static function evaluateSessionValidity(PDO $pdo, int $userId, int $lastActivityTimestamp, ?int $employeeId = null): ?string {
        if ($userId <= 0 || $lastActivityTimestamp <= 0) {
            return 'INVALID_SESSION';
        }

        $now = time();

        // Rule A: 2-Day Inactivity Threshold (48 hours = 172,800 seconds)
        if (($now - $lastActivityTimestamp) >= self::IDLE_TIMEOUT_SECONDS) {
            return 'INACTIVITY_EXPIRY';
        }

        // Rule B: Holiday & Sunday Return Rule
        $lastActivityDate = date('Y-m-d', $lastActivityTimestamp);
        $todayDate        = date('Y-m-d', $now);

        if ($todayDate > $lastActivityDate) {
            $year = (int)date('Y', $lastActivityTimestamp);
            $currentYear = (int)date('Y', $now);

            $holidaysMap = function_exists('get_active_holidays_map') ? get_active_holidays_map($pdo, $year) : [];
            if ($currentYear !== $year && function_exists('get_active_holidays_map')) {
                $holidaysMap = array_merge($holidaysMap, get_active_holidays_map($pdo, $currentYear));
            }

            // Check intervening days [lastActivityDate, yesterday]
            $curr = strtotime($lastActivityDate);
            $yesterdayTs = strtotime($todayDate . ' -1 day');

            while ($curr <= $yesterdayTs) {
                $checkDate = date('Y-m-d', $curr);
                if (function_exists('is_date_holiday_or_sunday')) {
                    $hInfo = is_date_holiday_or_sunday($checkDate, $holidaysMap);
                    if (!empty($hInfo['is_non_working'])) {
                        return 'HOLIDAY_RETURN_EXPIRY';
                    }
                } else {
                    if ((int)date('w', $curr) === 0) {
                        return 'HOLIDAY_RETURN_EXPIRY';
                    }
                }
                $curr = strtotime('+1 day', $curr);
            }

            // Rule C: Approved Leave Return Rule
            if (!empty($employeeId) && $employeeId > 0) {
                try {
                    $yesterdayStr = date('Y-m-d', $yesterdayTs);
                    $stmt = $pdo->prepare("
                        SELECT id 
                        FROM leave_requests 
                        WHERE employee_id = ? 
                          AND status = 'approved' 
                          AND start_date <= ? 
                          AND end_date >= ? 
                        LIMIT 1
                    ");
                    $stmt->execute([$employeeId, $yesterdayStr, $lastActivityDate]);
                    if ($stmt->fetch()) {
                        return 'LEAVE_RETURN_EXPIRY';
                    }
                } catch (\Throwable $e) {}
            }
        }

        return null; // Session is active & valid
    }

    /**
     * Start secure session with production cookie parameters
     */
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                       (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');

            session_start();
        }

        self::validateSession();
    }

    /**
     * Validate session idle timeout, holiday return, absolute lifetime, and active user status
     */
    public static function validateSession(): void {
        if (!self::check()) return;

        $now = time();
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $employeeId = isset($_SESSION['employee_id']) ? (int)$_SESSION['employee_id'] : null;
        $lastActivity = (int)($_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? $now);

        $pdo = self::getPdo();

        // 1. Authoritative Inactivity, Holiday Return & Leave Return Check
        $expiryReason = self::evaluateSessionValidity($pdo, $userId, $lastActivity, $employeeId);
        if ($expiryReason !== null) {
            AuditLogger::securityEvent('SECURITY_SESSION_EXPIRED', [
                'user_id' => $userId,
                'reason'  => $expiryReason,
                'last_activity' => date('Y-m-d H:i:s', $lastActivity)
            ]);
            self::logout();
            $_SESSION['session_expired'] = true;
            $_SESSION['session_expired_reason'] = $expiryReason;
            return;
        }

        // 2. Absolute lifetime check (30 days)
        if (isset($_SESSION['login_time']) && ($now - $_SESSION['login_time']) > self::ABSOLUTE_TIMEOUT_SECONDS) {
            AuditLogger::securityEvent('SECURITY_SESSION_LIFETIME_TIMEOUT', [
                'user_id' => $userId
            ]);
            self::logout();
            $_SESSION['session_expired'] = true;
            return;
        }

        // 3. Verify user status from DB directly (Active Check)
        if ($userId > 0) {
            $stmt = $pdo->prepare("SELECT id, name, email, role, status, employee_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $u = $stmt->fetch();

            if (!$u || strtolower($u['status'] ?? '') !== 'active') {
                AuditLogger::securityEvent('SECURITY_SESSION_USER_INACTIVE', [
                    'user_id' => $userId,
                    'status'  => $u['status'] ?? 'deleted'
                ]);
                self::logout();
                return;
            }

            self::$cachedUser = $u;
        }

        $_SESSION['last_activity'] = $now;

        // Throttled update of last_activity_at in user_sessions (every 60s)
        if (empty($_SESSION['last_db_ping']) || ($now - $_SESSION['last_db_ping']) > 60) {
            $_SESSION['last_db_ping'] = $now;
            try {
                $sessIdHash = hash('sha256', session_id());
                $pdo->prepare("UPDATE user_sessions SET last_activity_at = NOW() WHERE session_id_hash = ? AND revoked_at IS NULL")->execute([$sessIdHash]);
            } catch (\Throwable $e) {}
        }
    }

    public static function check(): bool {
        return !empty($_SESSION['user_id']);
    }

    public static function user(): ?array {
        if (!self::check()) return null;
        if (self::$cachedUser !== null) return self::$cachedUser;

        $pdo = self::getPdo();
        $stmt = $pdo->prepare("SELECT id, name, email, role, status, employee_id FROM users WHERE id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        self::$cachedUser = $stmt->fetch() ?: null;
        return self::$cachedUser;
    }

    public static function id(): ?int {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    public static function role(): string {
        $u = self::user();
        return strtolower($u['role'] ?? $_SESSION['role'] ?? '');
    }

    public static function employeeId(): ?int {
        return isset($_SESSION['employee_id']) ? (int)$_SESSION['employee_id'] : null;
    }

    public static function isAdmin(): bool {
        return in_array(self::role(), ['admin', 'super admin', 'superadmin']);
    }

    public static function isManager(): bool {
        return in_array(self::role(), ['manager', 'admin', 'super admin', 'superadmin']);
    }

    public static function isEmployee(): bool {
        return self::role() === 'employee';
    }

    public static function canAccessAdminPanel(): bool {
        return self::isAdmin() || self::isManager() || in_array(self::role(), ['staff', 'finance_manager', 'hr_manager'], true);
    }

    /**
     * Authenticate and initialize a secure session
     */
    public static function login(array $user): void {
        self::$cachedUser = null;
        $pdo = self::getPdo();

        // 1. Session fixation defense
        session_regenerate_id(true);

        $_SESSION['user_id']       = (int)$user['id'];
        $_SESSION['user_name']     = $user['name'];
        $_SESSION['user_email']    = $user['email'] ?? '';
        $_SESSION['role']          = strtolower($user['role'] ?? 'staff');
        $_SESSION['user_role']     = strtolower($user['role'] ?? 'staff');
        $_SESSION['login_time']    = time();
        $_SESSION['last_activity'] = time();

        // 2. Resolve linked employee profile
        $_SESSION['employee_id']   = null;
        $_SESSION['employee_code'] = null;
        $_SESSION['department']    = null;

        if (!empty($user['employee_id'])) {
            $eStmt = $pdo->prepare("SELECT id, employee_code, full_name, designation, department FROM employees WHERE id=?");
            $eStmt->execute([$user['employee_id']]);
            $emp = $eStmt->fetch();
        } else {
            $eStmt = $pdo->prepare("SELECT id, employee_code, full_name, designation, department FROM employees WHERE user_id=? OR email=?");
            $eStmt->execute([$user['id'], $user['email']]);
            $emp = $eStmt->fetch();
        }

        if ($emp) {
            $_SESSION['employee_id']   = (int)$emp['id'];
            $_SESSION['employee_code'] = $emp['employee_code'];
            $_SESSION['department']    = $emp['department'];
        }

        // 3. Track session record in user_sessions
        try {
            $sessIdHash = hash('sha256', session_id());
            $expiresAt  = date('Y-m-d H:i:s', time() + self::ABSOLUTE_TIMEOUT_SECONDS);
            $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $ua         = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);

            $stmt = $pdo->prepare("
                INSERT INTO user_sessions (user_id, session_id_hash, ip_address, user_agent, last_activity_at, expires_at)
                VALUES (?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([(int)$user['id'], $sessIdHash, $ip, $ua, $expiresAt]);

            // Update user last login
            $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([(int)$user['id']]);
        } catch (\Throwable $e) {
            error_log("Session tracking error: " . $e->getMessage());
        }

        AuditLogger::log('LOGIN_SUCCESS', 'auth', 'user', (int)$user['id']);
    }

    /**
     * Terminate user session completely
     */
    public static function logout(): void {
        try {
            if (self::check()) {
                $pdo = self::getPdo();
                $sessIdHash = hash('sha256', session_id());
                $pdo->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE session_id_hash = ?")->execute([$sessIdHash]);
                AuditLogger::log('LOGOUT', 'auth', 'user', self::id());
            }
        } catch (\Throwable $e) {}

        $_SESSION = [];
        self::$cachedUser = null;
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            $cookiePath = !empty($params['path']) ? $params['path'] : '/';
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                       (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
            setcookie(session_name(), '', time() - 42000, '/', '', $isHttps, true);
            if ($cookiePath !== '/') {
                setcookie(session_name(), '', time() - 42000, $cookiePath, $params['domain'], $params['secure'], $params['httponly']);
            }
        }
        @session_destroy();
    }

    public static function resetCache(): void {
        self::$cachedUser = null;
    }

    /**
     * Enforce authentication on protected page or API
     */
    public static function requireLogin(): void {
        self::startSession();
        SecurityHeaders::applyNoCache();
        if (!self::check()) {
            $isExpired = !empty($_SESSION['session_expired']);
            if (self::isApiRequest()) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error'   => $isExpired ? 'Your session has expired. Please login again.' : 'AUTHENTICATION_REQUIRED',
                    'message' => $isExpired ? 'Your session has expired. Please login again.' : 'Please sign in to access this resource.',
                    'code'    => $isExpired ? 'SESSION_EXPIRED' : 'AUTHENTICATION_REQUIRED'
                ]);
                exit;
            }

            $loginUrl = (defined('BASE_URL') ? BASE_URL : '/cms/') . 'modules/auth/login.php' . ($isExpired ? '?expired=1' : '');
            header('Location: ' . $loginUrl);
            exit;
        }
    }

    /**
     * Enforce admin panel access (Admins, Managers, Staff only)
     * Employees are blocked and redirected to their Employee Portal
     */
    public static function requireAdminPanel(): void {
        self::requireLogin();
        SecurityHeaders::applyNoCache();

        if (!self::canAccessAdminPanel()) {
            if (self::isEmployee()) {
                AuditLogger::securityEvent('SECURITY_EMPLOYEE_ADMIN_ATTEMPT', [
                    'user_id' => self::id(),
                    'uri'     => $_SERVER['REQUEST_URI'] ?? ''
                ]);
                if (function_exists('set_flash')) {
                    set_flash("Access Denied: Employees are not authorized to access the Admin Portal.", "danger");
                }
                $redirectUrl = defined('BASE_URL') ? BASE_URL . 'employee/dashboard.php' : '/cms/employee/dashboard.php';
                header('Location: ' . $redirectUrl);
                exit;
            }
            self::abortForbidden("Access Denied: Your account role does not have permission to access the Admin Portal.");
        }
    }

    /**
     * Enforce role-based access
     */
    public static function requireRole(array $allowedRoles): void {
        self::requireLogin();
        $currentRole = self::role();
        $normalized = array_map('strtolower', $allowedRoles);

        if (!in_array($currentRole, $normalized, true)) {
            AuditLogger::securityEvent('SECURITY_ROLE_VIOLATION', [
                'current_role' => $currentRole,
                'required_roles' => $allowedRoles,
                'uri' => $_SERVER['REQUEST_URI'] ?? ''
            ]);
            self::abortForbidden("Access Denied: Your role ($currentRole) is not authorized for this area.");
        }
    }

    /**
     * Abort request with HTTP 403 Forbidden
     */
    public static function abortForbidden(string $message = 'Access Denied: You do not have permission to view this resource.'): void {
        http_response_code(403);
        if (self::isApiRequest()) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error'   => 'FORBIDDEN',
                'message' => $message
            ]);
            exit;
        }

        // Render clean, secure access denied screen
        SecurityHeaders::apply();
        SecurityHeaders::applyNoCache();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>403 Forbidden — VibeSoft CMS</title>
          <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
          <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
          <style>body { background: #f8fafc; font-family: system-ui, -apple-system, sans-serif; }</style>
        </head>
        <body class="d-flex align-items-center justify-content-center min-vh-100 p-3">
          <div class="card border-0 shadow-sm p-4 text-center" style="max-width:440px; border-radius:16px;">
            <div class="text-danger mb-3"><i class="bi bi-shield-x fs-1"></i></div>
            <h4 class="fw-bold text-dark mb-2">403 — Forbidden</h4>
            <p class="text-muted small mb-4"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
            <div class="d-grid gap-2">
              <a href="javascript:history.back()" class="btn btn-primary fw-semibold btn-sm py-2">Go Back</a>
              <a href="<?= defined('BASE_URL') ? BASE_URL : '/cms/' ?>" class="btn btn-light btn-sm text-secondary">Return to Dashboard</a>
            </div>
          </div>
        </body>
        </html>
        <?php
        exit;
    }

    public static function isApiRequest(): bool {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return (strpos($uri, '/api/') !== false) ||
               (strpos($uri, '_api.php') !== false) ||
               (strpos($accept, 'application/json') !== false);
    }
}
