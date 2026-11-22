<?php
// Crm and backend/app/Realtime/RealtimeService.php
namespace App\Realtime;

use PDO;

class RealtimeService {
    private static ?RealtimeService $instance = null;
    private PDO $pdo;

    private array $sensitiveKeys = [
        'password', 'password_hash', 'token', 'token_hash', 'session_id_hash',
        'auth_token_hash', 'salt', 'secret_key', 'encryption_iv', 'api_key',
        'bank_acc_no', 'aadhaar_ref', 'pan', 'two_factor_info', 'encrypted_password'
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public static function getInstance(?PDO $pdo = null): self {
        if (self::$instance === null) {
            if ($pdo === null) {
                global $pdo;
            }
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    /**
     * Broadcast an event to the realtime event stream
     *
     * @param string $eventName e.g. 'crm.record_created', 'attendance.updated', 'leave.status_changed'
     * @param string $resourceType e.g. 'customer', 'attendance', 'leave', 'work_report', 'notification'
     * @param int $resourceId The primary key ID of the affected resource
     * @param string $action 'create'|'update'|'delete'|'status_change'|'assignment'|'alert'
     * @param array $payload Minimal necessary data
     * @param array|null $targetRoles Allowed roles, or null for all authorized CRM roles
     * @param int|null $targetUserId Specific user ID for personal isolation, or null for public/role-wide
     * @param int|null $actorId User ID who triggered the action
     * @return int|null Inserted event ID
     */
    public function emit(
        string $eventName,
        string $resourceType,
        int $resourceId,
        string $action,
        array $payload,
        ?array $targetRoles = null,
        ?int $targetUserId = null,
        ?int $actorId = null
    ): ?int {
        try {
            $sanitizedPayload = $this->sanitizePayload($payload);
            $rolesJson = $targetRoles !== null ? json_encode(array_values($targetRoles)) : null;
            $payloadJson = json_encode($sanitizedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmt = $this->pdo->prepare("
                INSERT INTO realtime_events 
                (event_name, resource_type, resource_id, action, target_roles, target_user_id, payload, actor_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $eventName,
                $resourceType,
                $resourceId,
                $action,
                $rolesJson,
                $targetUserId,
                $payloadJson,
                $actorId
            ]);

            $eventId = (int)$this->pdo->lastInsertId();

            // Probabilistic pruning (1 in 50 writes) of events older than 7 days
            if (mt_rand(1, 50) === 1) {
                $this->pruneOldEvents(7);
            }

            return $eventId;
        } catch (\Throwable $e) {
            error_log("RealtimeService emit error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Query events newer than $sinceId authorized for the given user & role
     */
    public function getEventsSince(int $sinceId, ?int $userId, string $role, int $limit = 50): array {
        try {
            $cleanRole = strtolower(trim($role));
            $isAdmin = in_array($cleanRole, ['admin', 'super admin'], true);

            if ($isAdmin) {
                // Admins see all public/role events, plus any events specifically targeted to them or untargeted
                $stmt = $this->pdo->prepare("
                    SELECT id, event_name, resource_type, resource_id, action, target_roles, target_user_id, payload, created_at
                    FROM realtime_events
                    WHERE id > ?
                    ORDER BY id ASC
                    LIMIT ?
                ");
                $stmt->bindValue(1, $sinceId, PDO::PARAM_INT);
                $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            } else {
                // Non-admins:
                // 1. If target_user_id is set: ONLY that specific user can see it
                // 2. If target_user_id is NULL: must match role (or target_roles is NULL for all staff)
                $stmt = $this->pdo->prepare("
                    SELECT id, event_name, resource_type, resource_id, action, target_roles, target_user_id, payload, created_at
                    FROM realtime_events
                    WHERE id > ?
                      AND (
                            (target_user_id IS NOT NULL AND target_user_id = ?)
                            OR
                            (target_user_id IS NULL AND (
                                target_roles IS NULL 
                                OR JSON_CONTAINS(LOWER(target_roles), ?)
                            ))
                          )
                    ORDER BY id ASC
                    LIMIT ?
                ");
                $roleJson = json_encode($cleanRole);
                $stmt->bindValue(1, $sinceId, PDO::PARAM_INT);
                $stmt->bindValue(2, $userId ?? 0, PDO::PARAM_INT);
                $stmt->bindValue(3, $roleJson, PDO::PARAM_STR);
                $stmt->bindValue(4, $limit, PDO::PARAM_INT);
            }

            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $events = [];
            foreach ($rows as $row) {
                $payload = json_decode($row['payload'] ?? '{}', true);
                $events[] = [
                    'id'            => (int)$row['id'],
                    'event_name'    => $row['event_name'],
                    'resource_type' => $row['resource_type'],
                    'resource_id'   => (int)$row['resource_id'],
                    'action'        => $row['action'],
                    'payload'       => is_array($payload) ? $payload : [],
                    'created_at'    => $row['created_at'],
                ];
            }

            return $events;
        } catch (\Throwable $e) {
            error_log("RealtimeService getEventsSince error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete events older than specified number of days
     */
    public function pruneOldEvents(int $days = 7): void {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM realtime_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$days]);
        } catch (\Throwable $e) {
            error_log("RealtimeService prune error: " . $e->getMessage());
        }
    }

    /**
     * Recursively strip sensitive information from payload
     */
    private function sanitizePayload(array $data): array {
        $clean = [];
        foreach ($data as $key => $val) {
            if (is_string($key) && in_array(strtolower($key), $this->sensitiveKeys, true)) {
                continue; // Omit sensitive keys entirely
            }
            if (is_array($val)) {
                $clean[$key] = $this->sanitizePayload($val);
            } else {
                $clean[$key] = $val;
            }
        }
        return $clean;
    }
}
