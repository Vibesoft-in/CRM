<?php
// config.php

// ----------------------------------------------------
// 0. Load .env Configuration (if exists)
// ----------------------------------------------------
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

// ----------------------------------------------------
// 1. Environment Detection (Local vs Production)
// ----------------------------------------------------
$serverName = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '';
$isLocal = in_array($serverName, ['localhost', '127.0.0.1', '::1'])
           || (stripos(__DIR__, 'xampp') !== false)
           || (php_sapi_name() === 'cli' && (stripos(__DIR__, 'xampp') !== false || getenv('DB_NAME') === 'crm'));

if (!defined('ENV')) {
    define('ENV', getenv('APP_ENV') ?: ($isLocal ? 'local' : 'production'));
}
date_default_timezone_set('Asia/Kolkata');

// Central Application Version
if (!defined('APP_VERSION')) {
    define('APP_VERSION', 'V1.3.5');
}

if (!defined('BASE_URL')) {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
    $currentDir = rtrim(str_replace('\\', '/', __DIR__), '/');
    if (!empty($docRoot) && stripos($currentDir, $docRoot) === 0) {
        $baseUrl = substr($currentDir, strlen($docRoot)) . '/';
    } else {
        $baseUrl = '/Vibesoft_CRM/Crm and backend/';
    }
    define('BASE_URL', $baseUrl);
}
define('UPLOADS_DIR', __DIR__ . '/assets/uploads/');
define('INVOICE_PDF_DIR', UPLOADS_DIR . 'invoices/');

if (!is_dir(INVOICE_PDF_DIR)) {
    @mkdir(INVOICE_PDF_DIR, 0755, true);
}

// Secure session cookies before starting session
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

// ----------------------------------------------------
// 2. Database Credentials
// ----------------------------------------------------
if (getenv('DB_NAME')) {
    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbName = getenv('DB_NAME');
    $dbUser = getenv('DB_USER') ?: 'root';
    $dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
} elseif (ENV === 'local') {
    // Local XAMPP MySQL credentials
    $dbHost = 'localhost';
    $dbName = 'crm';
    $dbUser = 'root';
    $dbPass = '';
} else {
    // Hostinger Production MySQL credentials
    $dbHost = 'localhost';
    $dbName = 'u537268535_cms_v1_17_sep';
    $dbUser = 'u537268535_Vibesoft_v1';
    $dbPass = 'Vibesoft@#2021';
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    // Explicitly synchronize MySQL session timezone with PHP (Asia/Kolkata +05:30)
    $pdo->exec("SET time_zone = '+05:30'");
} catch (Exception $e) {
    if (ENV === 'local') {
        die("DB Connection error: " . $e->getMessage());
    } else {
        die("DB Connection failed.");
    }
}
