<?php
// app/Security/AuditLogger.php — Production Security & Audit Logging
namespace App\Security;

use PDO;

class AuditLogger {
    private static function getPdo(): PDO {
        global $pdo;
        return $pdo;
    }

    private static function getIp(): string {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private static function getUserAgent(): string {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);
    }

    /**
     * Log business state change to audit_logs
     */
    public static function log(
        string $action,
        string $module,
        ?string $resourceType = null,
        $resourceId = null,
        $oldValues = null,
        $newValues = null,
        ?int $userId = null
    ): void {
        try {
            $pdo = self::getPdo();
            if ($userId === null) {
                $userId = $_SESSION['user_id'] ?? null;
            }

            $userName = $_SESSION['user_name'] ?? 'System';
            $details = [];
            if ($resourceType) $details['resource_type'] = $resourceType;
            if ($oldValues)    $details['old_values']    = $oldValues;
            if ($newValues)    $details['new_values']    = $newValues;

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs 
                  (user_id, user_name, module, action, record_id, details, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $userId,
                $userName,
                $module,
                $action,
                $resourceId ? (int)$resourceId : null,
                !empty($details) ? json_encode($details, JSON_UNESCAPED_SLASHES) : null,
                self::getIp()
            ]);
        } catch (\Throwable $e) {
            // Fail open for logging so business logic doesn't crash, but log to php error log
            error_log("AuditLogger Error: " . $e->getMessage());
        }
    }

    /**
     * Log suspicious / unauthorized security event to security_events
     */
    public static function securityEvent(string $eventType, array $details = [], ?int $userId = null): void {
        try {
            $pdo = self::getPdo();
            if ($userId === null) {
                $userId = $_SESSION['user_id'] ?? null;
            }

            $stmt = $pdo->prepare("
                INSERT INTO security_events (event_type, user_id, ip_address, user_agent, details, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $eventType,
                $userId,
                self::getIp(),
                self::getUserAgent(),
                json_encode($details, JSON_UNESCAPED_SLASHES)
            ]);
        } catch (\Throwable $e) {
            error_log("SecurityEvent Log Error: " . $e->getMessage());
        }
    }
}
