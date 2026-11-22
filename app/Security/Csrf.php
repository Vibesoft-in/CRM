<?php
// app/Security/Csrf.php — Cryptographic CSRF Defense
namespace App\Security;

class Csrf {
    public static function init(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            self::generate();
        }
    }

    public static function generate(): string {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }

    public static function token(): string {
        self::init();
        return $_SESSION['csrf_token'];
    }

    public static function field(): string {
        return '<input type="hidden" name="csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function verify(?string $token): bool {
        self::init();
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            AuditLogger::securityEvent('SECURITY_CSRF_MISSING', [
                'provided' => !empty($token) ? 'yes' : 'no'
            ]);
            return false;
        }

        $valid = hash_equals($_SESSION['csrf_token'], (string)$token);
        if (!$valid) {
            AuditLogger::securityEvent('SECURITY_CSRF_INVALID', [
                'uri' => $_SERVER['REQUEST_URI'] ?? ''
            ]);
        }
        return $valid;
    }
}
