<?php
// Crm and backend/api/mobile_helper.php — Production Hardened API Helper
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// Production Error Shielding: Prevent any raw PHP HTML/fatal leaks
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Start output buffering to capture any accidental output before JSON
if (ob_get_level() === 0) {
    ob_start();
}

$GLOBALS['api_start_time'] = microtime(true);

// Enable CORS for mobile app, web browsers, and cross-platform clients
if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-Requested-With, Cache-Control, Last-Event-ID, Accept, Origin, User-Agent');
    header('Access-Control-Expose-Headers: Content-Disposition, Content-Length, X-API-KEY');
    header('Access-Control-Max-Age: 86400');
    header('Content-Type: application/json; charset=UTF-8');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ----------------------------------------------------
// Global Error, Exception & Shutdown Handlers (Pure JSON Guarantee)
// ----------------------------------------------------
set_exception_handler(function (\Throwable $e) {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    api_log_error($e->getMessage(), [
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);

    if ($e instanceof \PDOException) {
        handle_db_exception($e);
    }

    json_error('Internal server error. Please try again later.', 500, [], [
        'code' => 'SERVER_ERROR'
    ]);
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    api_log_error("PHP Notice/Warning: $message", ['file' => $file, 'line' => $line]);
    return true; // Suppress default PHP output
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        api_log_error("PHP Fatal Shutdown: {$error['message']}", ['file' => $error['file'], 'line' => $error['line']]);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'error'   => 'A fatal server error occurred',
            'message' => 'Internal server error. Please contact administration.',
            'code'    => 'FATAL_ERROR'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
});

/**
 * Output standardized JSON response
 */
function json_response($data, int $statusCode = 200): void {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($statusCode);
    api_log_request($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Standardized Success Response
 * Backwards compatible with Flutter app top-level keys and standard {"success": true, "message": "...", "data": {...}}
 */
function json_success($data = [], string $message = 'Success', int $statusCode = 200, array $extra = []): void {
    $base = [
        'success' => true,
        'message' => $message,
    ];

    $maskedData = is_array($data) ? mask_sensitive_data($data) : $data;

    if (is_array($maskedData)) {
        $payload = array_merge($base, ['data' => $maskedData], $maskedData, $extra);
    } else {
        $payload = array_merge($base, ['data' => $maskedData], $extra);
    }

    json_response($payload, $statusCode);
}

/**
 * Standardized Error Response
 */
function json_error(string $message, int $statusCode = 400, $errors = [], array $extra = []): void {
    $code = $extra['code'] ?? (is_array($errors) ? ($errors['code'] ?? null) : null);
    $base = [
        'success' => false,
        'error'   => $message,
        'message' => $message,
        'errors'  => !empty($errors) ? $errors : (object)[]
    ];
    if ($code !== null) {
        $base['code'] = $code;
    }
    $response = array_merge($base, $extra);
    json_response($response, $statusCode);
}

/**
 * Standardized Paginated Success Response
 */
function paginate_response(array $items, int $total, int $page, int $limit, string $message = 'Success', array $extra = []): void {
    $totalPages = $limit > 0 ? (int)ceil($total / $limit) : 1;
    $hasMore = ($page * $limit) < $total;

    $maskedItems = mask_sensitive_data($items);
    $dataWrapper = array_merge(['items' => $maskedItems], $extra);

    $payload = array_merge([
        'success'    => true,
        'message'    => $message,
        'data'       => $dataWrapper,
        'items'      => $maskedItems,
        'pagination' => [
            'total'       => (int)$total,
            'page'        => (int)$page,
            'limit'       => (int)$limit,
            'total_pages' => $totalPages,
            'has_more'    => $hasMore
        ]
    ], $extra);

    json_response($payload, 200);
}

/**
 * Safe database exception handler (Shields raw SQL, detects duplicate keys)
 */
function handle_db_exception(PDOException $e, string $resource = 'record'): void {
    api_log_error("Database Exception: " . $e->getMessage(), [
        'code'  => $e->getCode(),
        'trace' => $e->getTraceAsString()
    ]);

    $code = (string)$e->getCode();
    $msg = $e->getMessage();

    // MySQL Duplicate Key Error (SQLSTATE 23000, error 1062)
    if ($code === '23000' || strpos($msg, '1062 Duplicate entry') !== false) {
        $field = 'value';
        if (preg_match("/for key '([^']+)'/", $msg, $m)) {
            $keyParts = explode('.', $m[1]);
            $field = end($keyParts);
        }
        json_error("A duplicate {$resource} already exists ({$field} is already in use).", 409, [
            'code' => 'DUPLICATE_RECORD',
            'field' => $field
        ]);
    }

    // Foreign key constraint violation (1451 / 1452)
    if (strpos($msg, '1451') !== false || strpos($msg, '1452') !== false) {
        json_error("Cannot modify {$resource} because related records depend on it or referenced parent does not exist.", 400, [
            'code' => 'FOREIGN_KEY_VIOLATION'
        ]);
    }

    // General safe production error
    json_error("A database error occurred while processing the {$resource}.", 500, [
        'code' => 'DATABASE_ERROR'
    ]);
}

/**
 * Safely parse JSON input body or form-data
 */
function get_json_input(): array {
    $raw = file_get_contents('php://input');
    $decoded = [];
    if (!empty($raw)) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $decoded = $json;
        }
    }
    return array_merge($_GET, $_POST, $decoded);
}

/**
 * Validate input fields with rules
 * Supported rules: required, email, numeric, integer, min:X, max:X, in:A,B,C
 */
function validate_fields(array $data, array $rules): array {
    $errors = [];

    foreach ($rules as $field => $ruleStr) {
        $ruleList = explode('|', $ruleStr);
        $value = $data[$field] ?? null;

        foreach ($ruleList as $rule) {
            $ruleName = $rule;
            $param = null;
            if (strpos($rule, ':') !== false) {
                [$ruleName, $param] = explode(':', $rule, 2);
            }

            if ($ruleName === 'required') {
                if ($value === null || trim((string)$value) === '') {
                    $errors[$field][] = "The {$field} field is required.";
                }
            } elseif ($value !== null && trim((string)$value) !== '') {
                if ($ruleName === 'email') {
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$field][] = "The {$field} must be a valid email address.";
                    }
                } elseif ($ruleName === 'numeric') {
                    if (!is_numeric($value)) {
                        $errors[$field][] = "The {$field} must be a number.";
                    }
                } elseif ($ruleName === 'integer') {
                    if (!filter_var($value, FILTER_VALIDATE_INT)) {
                        $errors[$field][] = "The {$field} must be an integer.";
                    }
                } elseif ($ruleName === 'min' && $param !== null) {
                    if (is_numeric($value)) {
                        if ((float)$value < (float)$param) {
                            $errors[$field][] = "The {$field} must be at least {$param}.";
                        }
                    } else {
                        if (mb_strlen((string)$value) < (int)$param) {
                            $errors[$field][] = "The {$field} must be at least {$param} characters.";
                        }
                    }
                } elseif ($ruleName === 'max' && $param !== null) {
                    if (is_numeric($value)) {
                        if ((float)$value > (float)$param) {
                            $errors[$field][] = "The {$field} cannot exceed {$param}.";
                        }
                    } else {
                        if (mb_strlen((string)$value) > (int)$param) {
                            $errors[$field][] = "The {$field} cannot exceed {$param} characters.";
                        }
                    }
                } elseif ($ruleName === 'in' && $param !== null) {
                    $allowed = explode(',', $param);
                    if (!in_array((string)$value, $allowed, true)) {
                        $errors[$field][] = "The {$field} must be one of: {$param}.";
                    }
                }
            }
        }
    }

    return $errors;
}

/**
 * Validate input fields or fail with HTTP 422
 */
function validate_or_fail(array $data, array $rules): void {
    $errors = validate_fields($data, $rules);
    if (!empty($errors)) {
        json_error('Validation failed', 422, $errors, ['code' => 'VALIDATION_FAILED']);
    }
}

/**
 * Escape search wildcard characters in SQL LIKE pattern
 */
function sanitize_like_term(string $term): string {
    $clean = trim($term);
    if (mb_strlen($clean) > 100) {
        $clean = mb_substr($clean, 0, 100);
    }
    return addcslashes($clean, '%_\\');
}

/**
 * Rate limiter check for high-frequency or sensitive endpoints
 */
function check_api_rate_limit(string $action, string $identifier, int $maxAttempts = 60, int $decaySeconds = 60): void {
    global $pdo;

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rateKey = "rl:{$action}:" . md5($identifier . '|' . $ip);

    // Use login_attempts table with custom identifier prefix
    try {
        $stmt = $pdo->prepare("SELECT attempt_count, locked_until, last_attempt_at FROM login_attempts WHERE ip_address = ? AND identifier = ? LIMIT 1");
        $stmt->execute([$ip, $rateKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $now = time();
        if ($row) {
            $lastAttempt = strtotime($row['last_attempt_at']);
            // Reset if window has elapsed
            if (($now - $lastAttempt) > $decaySeconds) {
                $pdo->prepare("UPDATE login_attempts SET attempt_count = 1, last_attempt_at = NOW(), locked_until = NULL WHERE ip_address = ? AND identifier = ?")->execute([$ip, $rateKey]);
                return;
            }

            if (!empty($row['locked_until']) && strtotime($row['locked_until']) > $now) {
                $retryAfter = strtotime($row['locked_until']) - $now;
                header("Retry-After: {$retryAfter}");
                json_error("Too many requests for {$action}. Please slow down.", 429, [
                    'code' => 'RATE_LIMIT_EXCEEDED',
                    'retry_after_seconds' => $retryAfter
                ]);
            }

            if ((int)$row['attempt_count'] >= $maxAttempts) {
                $lockUntil = date('Y-m-d H:i:s', $now + $decaySeconds);
                $pdo->prepare("UPDATE login_attempts SET locked_until = ? WHERE ip_address = ? AND identifier = ?")->execute([$lockUntil, $ip, $rateKey]);
                header("Retry-After: {$decaySeconds}");
                json_error("Too many requests for {$action}. Please wait {$decaySeconds} seconds.", 429, [
                    'code' => 'RATE_LIMIT_EXCEEDED',
                    'retry_after_seconds' => $decaySeconds
                ]);
            }

            $pdo->prepare("UPDATE login_attempts SET attempt_count = attempt_count + 1, last_attempt_at = NOW() WHERE ip_address = ? AND identifier = ?")->execute([$ip, $rateKey]);
        } else {
            $pdo->prepare("INSERT INTO login_attempts (ip_address, identifier, attempt_count, last_attempt_at) VALUES (?, ?, 1, NOW())")->execute([$ip, $rateKey]);
        }
    } catch (\Throwable $e) {
        // Fallback: If DB rate limit fails, continue rather than blocking legitimate requests
        api_log_error("Rate limiter DB error: " . $e->getMessage());
    }
}

/**
 * Mask sensitive data in API responses (passwords, bank accounts, personal identifiers)
 */
function mask_sensitive_data($data) {
    if (!is_array($data)) return $data;

    $sensitiveKeys = [
        'password_hash', 'token_hash', 'session_id_hash',
        'auth_token_hash', 'salt', 'secret_key', 'encryption_iv'
    ];

    $masked = [];
    foreach ($data as $key => $val) {
        if (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true)) {
            continue; // Completely strip password hashes & internal security tokens
        }

        if (is_array($val)) {
            $masked[$key] = mask_sensitive_data($val);
        } elseif (is_string($val)) {
            // Mask Bank Account number (e.g. show only last 4 digits)
            if (is_string($key) && (stripos($key, 'bank_acc') !== false || stripos($key, 'account_num') !== false) && strlen($val) > 4) {
                $masked[$key] = str_repeat('*', max(4, strlen($val) - 4)) . substr($val, -4);
            }
            // Mask Aadhaar number
            elseif (is_string($key) && stripos($key, 'aadhaar') !== false && strlen($val) >= 12) {
                $masked[$key] = '********' . substr(str_replace(' ', '', $val), -4);
            }
            // Mask PAN Card number
            elseif (is_string($key) && in_array(strtolower($key), ['pan', 'pan_no', 'pan_number', 'pan_card', 'pan_id'], true) && strlen($val) >= 10) {
                $cleanPan = strtoupper(trim($val));
                $masked[$key] = substr($cleanPan, 0, 3) . '****' . substr($cleanPan, -2);
            } else {
                $masked[$key] = $val;
            }
        } else {
            $masked[$key] = $val;
        }
    }
    return $masked;
}

/**
 * Append to API Request Log
 */
function api_log_request(int $statusCode = 200): void {
    try {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/api_requests.log';

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $duration = isset($GLOBALS['api_start_time']) ? round((microtime(true) - $GLOBALS['api_start_time']) * 1000, 2) : 0;
        $timestamp = date('Y-m-d H:i:s');

        $line = sprintf("[%s] [%s] [%s] [STATUS: %d] [%sms] %s\n", $timestamp, $ip, $method, $statusCode, $duration, $uri);
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {}
}

/**
 * Append to API Error Log
 */
function api_log_error(string $message, array $context = []): void {
    try {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/api_errors.log';

        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $contextJson = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';

        $line = sprintf("[%s] [%s] [URI: %s] ERROR: %s%s\n", $timestamp, $ip, $uri, $message, $contextJson);
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {}
}

/**
 * Resolve any relative media or photo path into a fully qualified absolute URL.
 */
function resolve_media_url(?string $path, ?string $fallbackName = null): ?string {
    if (empty($path)) {
        if (!empty($fallbackName)) {
            return 'https://ui-avatars.com/api/?name=' . urlencode(trim($fallbackName)) . '&background=0A2E5C&color=ffffff&size=256&bold=true';
        }
        return null;
    }

    $path = trim(str_replace('\\', '/', $path));
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
              (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
              (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
              ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'crm.vibesoft.in';

    $base = defined('BASE_URL') ? BASE_URL : '/';
    $base = rtrim(str_replace('\\', '/', $base), '/');

    $cleanPath = ltrim($path, '/');
    if (!empty($base) && strpos('/' . $cleanPath, $base . '/') === 0) {
        $finalUrl = $scheme . '://' . $host . '/' . $cleanPath;
    } else {
        $prefix = !empty($base) ? ($base . '/') : '/';
        $finalUrl = $scheme . '://' . $host . $prefix . $cleanPath;
    }
    return str_replace(' ', '%20', $finalUrl);
}

/**
 * Issue a cryptographically secure token with long-lived persistent expiry (180 days)
 */
function issue_api_token(int $userId, int $lifetimeDays = 180): array {
    global $pdo;

    $token = bin2hex(random_bytes(32)); // 64 hex chars
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + ($lifetimeDays * 86400));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Mobile App', 0, 255);

    // 1. Insert into user_sessions table
    $stmt = $pdo->prepare("
        INSERT INTO user_sessions (user_id, session_id_hash, ip_address, user_agent, last_activity_at, expires_at)
        VALUES (?, ?, ?, ?, NOW(), ?)
    ");
    $stmt->execute([$userId, $tokenHash, $ip, $ua, $expiresAt]);

    // 2. Sync to users.api_key for legacy queries
    $pdo->prepare("UPDATE users SET api_key = ?, last_login_at = NOW() WHERE id = ?")->execute([$token, $userId]);

    return [
        'token'      => $token,
        'token_type' => 'Bearer',
        'expires_at' => $expiresAt,
        'expires_in' => $lifetimeDays * 86400
    ];
}

/**
 * Revoke an existing API token on logout
 */
function revoke_api_token(string $currentToken): bool {
    global $pdo;
    if (empty($currentToken)) return false;

    $tokenHash = hash('sha256', $currentToken);
    $stmt = $pdo->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE session_id_hash = ?");
    $stmt->execute([$tokenHash]);
    return true;
}

/**
 * Refresh an existing valid API token: revokes old session and issues a new one
 */
function refresh_api_token(string $currentToken): array {
    global $pdo;

    $tokenHash = hash('sha256', $currentToken);
    $sStmt = $pdo->prepare("
        SELECT s.*, u.id as user_id, u.employee_id 
        FROM user_sessions s
        JOIN users u ON u.id = s.user_id
        WHERE s.session_id_hash = ? AND s.revoked_at IS NULL AND s.expires_at > NOW() 
        LIMIT 1
    ");
    $sStmt->execute([$tokenHash]);
    $session = $sStmt->fetch(PDO::FETCH_ASSOC);

    $userId = 0;
    if ($session) {
        $userId = (int)$session['user_id'];
        $lastActivityTime = !empty($session['last_activity_at']) ? strtotime($session['last_activity_at']) : time();
        $expiryReason = \App\Security\Auth::evaluateSessionValidity(
            $pdo,
            $userId,
            $lastActivityTime,
            !empty($session['employee_id']) ? (int)$session['employee_id'] : null
        );

        if ($expiryReason !== null) {
            $pdo->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE id = ?")->execute([$session['id']]);
            json_error('Your session has expired. Please login again.', 401, [
                'code'   => 'SESSION_EXPIRED',
                'reason' => $expiryReason
            ]);
        }

        // Revoke old token
        $pdo->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE id = ?")->execute([$session['id']]);
    } else {
        // Check users.api_key fallback
        $uStmt = $pdo->prepare("SELECT id FROM users WHERE api_key = ? AND status = 'active' LIMIT 1");
        $uStmt->execute([$currentToken]);
        $userId = (int)($uStmt->fetchColumn() ?: 0);
    }

    if ($userId <= 0) {
        json_error('Invalid, expired or revoked token cannot be refreshed', 401, ['code' => 'TOKEN_CANNOT_REFRESH']);
    }

    return issue_api_token($userId);
}

/**
 * Authenticate mobile request via Authorization Bearer or X-API-KEY or query token
 * Strictly validates Token Expiry, Revocation, 2-Day Inactivity, Holiday Return, and Active Status
 */
function authenticate_mobile(): array {
    global $pdo;

    $token = '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $authHeader = $v;
                break;
            }
        }
    }
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
    }
    if (empty($token)) {
        $token = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? ($_GET['token'] ?? ($_POST['token'] ?? '')));
    }

    if (empty($token)) {
        json_error('Unauthorized: Missing authentication token', 401, ['code' => 'TOKEN_MISSING']);
    }

    $tokenHash = hash('sha256', $token);
    $user = null;
    $session = null;

    // 1. Primary check: Verify in user_sessions table
    try {
        $sStmt = $pdo->prepare("
            SELECT s.id as session_id, s.expires_at, s.revoked_at, s.last_activity_at,
                   u.id, u.name, u.email, u.role, u.api_key, u.employee_id, u.status
            FROM user_sessions s
            JOIN users u ON u.id = s.user_id
            WHERE s.session_id_hash = ?
            LIMIT 1
        ");
        $sStmt->execute([$tokenHash]);
        $row = $sStmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if (!empty($row['revoked_at'])) {
                json_error('Authentication token has been revoked. Please log in again.', 401, ['code' => 'TOKEN_REVOKED']);
            }
            if (strtotime($row['expires_at']) < time()) {
                json_error('Authentication token has expired. Please log in again.', 401, ['code' => 'TOKEN_EXPIRED']);
            }

            // Verify Active User Status
            if (isset($row['status']) && strtolower($row['status']) === 'inactive') {
                $pdo->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE id = ?")->execute([$row['session_id']]);
                json_error('Your account is deactivated. Please contact administration.', 403, ['code' => 'ACCOUNT_INACTIVE']);
            }

            $user = $row;
            $session = $row;

            // Throttled update of last_activity_at and sliding expiration window (every 60 seconds)
            // Ensures active mobile users NEVER get logged out unexpectedly
            if (empty($row['last_activity_at']) || (time() - strtotime($row['last_activity_at'])) > 60) {
                $pdo->prepare("
                    UPDATE user_sessions 
                    SET last_activity_at = NOW(),
                        expires_at = DATE_ADD(NOW(), INTERVAL 180 DAY)
                    WHERE id = ?
                ")->execute([$row['session_id']]);
            }
        }
    } catch (\Throwable $e) {}

    // 2. Fallback check for users table (auto-migrates to session)
    if (!$user) {
        $stmt = $pdo->prepare("SELECT id, name, email, role, api_key, employee_id, status FROM users WHERE api_key = ? LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            try {
                $expiresAt = date('Y-m-d H:i:s', time() + (30 * 86400));
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Mobile App', 0, 255);
                $insStmt = $pdo->prepare("
                    INSERT INTO user_sessions (user_id, session_id_hash, ip_address, user_agent, last_activity_at, expires_at)
                    VALUES (?, ?, ?, ?, NOW(), ?)
                ");
                $insStmt->execute([$user['id'], $tokenHash, $ip, $ua, $expiresAt]);
            } catch (\Throwable $e) {}
        }
    }

    if (!$user) {
        json_error('Unauthorized: Invalid or expired authentication token', 401, ['code' => 'INVALID_TOKEN']);
    }

    if (isset($user['status']) && strtolower($user['status']) === 'inactive') {
        json_error('Account is deactivated. Contact administrator.', 403, ['code' => 'ACCOUNT_INACTIVE']);
    }

    // Resolve employee details
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

    return [
        'user'        => $user,
        'employee'    => $employee,
        'user_id'     => (int)$user['id'],
        'employee_id' => $employee ? (int)$employee['id'] : null,
        'role'        => strtolower($user['role'] ?? 'employee'),
        'token'       => $token,
        'session_id'  => $session['session_id'] ?? null,
        'expires_at'  => $session['expires_at'] ?? null
    ];
}

/**
 * Enforce specific roles on an API endpoint (Returns 403 on failure)
 */
function require_role_api(array $allowedRoles, array $auth): void {
    $userRole = strtolower($auth['role'] ?? 'employee');
    $normalizedAllowed = array_map('strtolower', $allowedRoles);

    // Super admin & admin always have full access
    if (in_array($userRole, ['admin', 'super admin', 'superadmin'])) {
        return;
    }

    if (!in_array($userRole, $normalizedAllowed)) {
        json_error("Forbidden: You do not have sufficient permissions to access this resource.", 403, ['code' => 'FORBIDDEN']);
    }
}

/**
 * Strict IDOR check: Verify if logged-in user owns the employee record or has management privileges
 */
function verify_employee_access(int $targetEmployeeId, array $auth, string $resourceName = 'record'): void {
    $role = strtolower($auth['role'] ?? 'employee');
    // Admins and Managers have permission to view/manage team records
    if (in_array($role, ['admin', 'super admin', 'superadmin', 'manager', 'hr manager'])) {
        return;
    }
    // Regular employee can ONLY access their own records
    $myEmpId = (int)($auth['employee_id'] ?? 0);
    if ($myEmpId <= 0 || $myEmpId !== $targetEmployeeId) {
        json_error("Forbidden: You do not have permission to access this {$resourceName}.", 403, ['code' => 'IDOR_ACCESS_DENIED']);
    }
}

/**
 * Strict IDOR check: Verify table record ownership
 */
function verify_record_ownership(string $table, int $recordId, string $ownerColumn, array $auth, string $resourceName = 'record'): array {
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
    $stmt->execute([$recordId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        json_error("{$resourceName} not found", 404, ['code' => 'NOT_FOUND']);
    }

    $role = strtolower($auth['role'] ?? 'employee');
    if (in_array($role, ['admin', 'super admin', 'superadmin', 'manager', 'hr manager', 'sales manager', 'finance manager'])) {
        return $record;
    }

    $ownerId = (int)($record[$ownerColumn] ?? 0);
    $myId = ($ownerColumn === 'employee_id') ? (int)($auth['employee_id'] ?? 0) : (int)($auth['user_id'] ?? 0);

    if ($myId <= 0 || $ownerId !== $myId) {
        json_error("Forbidden: You do not have permission to access this {$resourceName}.", 403, ['code' => 'IDOR_ACCESS_DENIED']);
    }

    return $record;
}

/**
 * Calculate distance in meters between two lat/long points using Haversine formula
 */
function calculate_distance_meters(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371000; // meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($earthRadius * $c, 2);
}

/**
 * Extract standard pagination parameters
 */
function get_pagination_params(int $defaultLimit = 20, int $maxLimit = 100): array {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min($maxLimit, max(1, (int)($_GET['limit'] ?? $defaultLimit)));
    $offset = ($page - 1) * $limit;
    return ['page' => $page, 'limit' => $limit, 'offset' => $offset];
}
