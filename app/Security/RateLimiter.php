<?php
// app/Security/RateLimiter.php — Login Brute-Force & API Rate Limiting
namespace App\Security;

use PDO;

class RateLimiter {
    private static function getPdo(): PDO {
        global $pdo;
        return $pdo;
    }

    private static function getIp(): string {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Check if the IP/identifier is currently locked out
     */
    public static function isLocked(string $identifier, int $maxAttempts = 5, int $lockoutMinutes = 15): bool {
        try {
            $pdo = self::getPdo();
            $ip = self::getIp();

            $stmt = $pdo->prepare("SELECT attempt_count, locked_until, last_attempt_at FROM login_attempts WHERE ip_address = ? AND identifier = ?");
            $stmt->execute([$ip, $identifier]);
            $row = $stmt->fetch();

            if (!$row) return false;

            $now = time();
            $lastAttemptTime = !empty($row['last_attempt_at']) ? strtotime($row['last_attempt_at']) : 0;

            // If last attempt was more than lockout duration ago, reset attempts
            if (($now - $lastAttemptTime) > ($lockoutMinutes * 60)) {
                self::clear($identifier);
                return false;
            }

            if (!empty($row['locked_until'])) {
                if (strtotime($row['locked_until']) > $now) {
                    return true; // Still locked
                } else {
                    // Lockout period has passed; reset attempts
                    self::clear($identifier);
                    return false;
                }
            }

            return (int)$row['attempt_count'] >= $maxAttempts;
        } catch (\Throwable $e) {
            error_log("RateLimiter isLocked error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record a failed attempt. If threshold reached, apply lockout.
     */
    public static function hit(string $identifier, int $maxAttempts = 5, int $lockoutMinutes = 15): int {
        try {
            $pdo = self::getPdo();
            $ip = self::getIp();

            $stmt = $pdo->prepare("
                INSERT INTO login_attempts (ip_address, identifier, attempt_count, last_attempt_at)
                VALUES (?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE 
                  attempt_count = attempt_count + 1,
                  last_attempt_at = NOW()
            ");
            $stmt->execute([$ip, $identifier]);

            // Check current count
            $chk = $pdo->prepare("SELECT attempt_count FROM login_attempts WHERE ip_address = ? AND identifier = ?");
            $chk->execute([$ip, $identifier]);
            $count = (int)$chk->fetchColumn();

            if ($count >= $maxAttempts) {
                $lockedUntil = date('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));
                $lockStmt = $pdo->prepare("UPDATE login_attempts SET locked_until = ? WHERE ip_address = ? AND identifier = ?");
                $lockStmt->execute([$lockedUntil, $ip, $identifier]);

                AuditLogger::securityEvent('SECURITY_RATE_LIMIT_LOCKOUT', [
                    'identifier' => $identifier,
                    'attempts' => $count,
                    'locked_until' => $lockedUntil
                ]);
            }

            return $count;
        } catch (\Throwable $e) {
            error_log("RateLimiter hit error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Clear failed attempts upon successful authentication
     */
    public static function clear(string $identifier): void {
        try {
            $pdo = self::getPdo();
            $ip = self::getIp();
            $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND identifier = ?");
            $stmt->execute([$ip, $identifier]);
        } catch (\Throwable $e) {
            error_log("RateLimiter clear error: " . $e->getMessage());
        }
    }

    /**
     * Get remaining lockout seconds
     */
    public static function getRemainingLockoutSeconds(string $identifier): int {
        try {
            $pdo = self::getPdo();
            $ip = self::getIp();
            $stmt = $pdo->prepare("SELECT locked_until FROM login_attempts WHERE ip_address = ? AND identifier = ?");
            $stmt->execute([$ip, $identifier]);
            $lockedUntil = $stmt->fetchColumn();
            if ($lockedUntil) {
                return max(0, strtotime($lockedUntil) - time());
            }
            return 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
