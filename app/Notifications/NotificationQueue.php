<?php
// app/Notifications/NotificationQueue.php
namespace App\Notifications;

use PDO;

class NotificationQueue {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Check if an idempotency key has already been queued, processed, or logged
     */
    public function hasIdempotencyKey(string $key): bool {
        $stmt = $this->pdo->prepare("
            SELECT id FROM notification_queue 
            WHERE idempotency_key = ? 
            LIMIT 1
        ");
        $stmt->execute([$key]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Enqueue a notification with scheduled time and idempotency protection
     */
    public function enqueue(
        int $userId,
        string $type,
        array $payload,
        string $scheduledAt,
        string $idempotencyKey,
        array $channels = ['crm', 'email'],
        ?int $employeeId = null,
        int $maxAttempts = 3
    ): ?int {
        // Prevent duplicate enqueue
        if ($this->hasIdempotencyKey($idempotencyKey)) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO notification_queue 
                (user_id, employee_id, notification_type, idempotency_key, channels, payload, scheduled_at, status, attempts, max_attempts, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?, NOW())
            ");

            $stmt->execute([
                $userId,
                $employeeId,
                $type,
                $idempotencyKey,
                json_encode($channels),
                json_encode($payload),
                $scheduledAt,
                $maxAttempts
            ]);

            return (int)$this->pdo->lastInsertId();
        } catch (\Exception $e) {
            // Check for duplicate key exception
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Fetch pending items ready for processing
     */
    public function getDueItems(int $limit = 50): array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM notification_queue 
            WHERE status = 'pending' AND scheduled_at <= NOW() AND attempts < max_attempts
            ORDER BY scheduled_at ASC 
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Mark queue item as processing
     */
    public function markProcessing(int $queueId): bool {
        $stmt = $this->pdo->prepare("
            UPDATE notification_queue 
            SET status = 'processing', attempts = attempts + 1 
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$queueId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark queue item as completed/sent
     */
    public function markSent(int $queueId): void {
        $stmt = $this->pdo->prepare("
            UPDATE notification_queue 
            SET status = 'sent', processed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$queueId]);
    }

    /**
     * Mark queue item as failed or retry
     */
    public function markFailed(int $queueId, string $error, bool $isFinal = false): void {
        $status = $isFinal ? 'failed' : 'pending';
        $stmt = $this->pdo->prepare("
            UPDATE notification_queue 
            SET status = ?, last_error = ?, processed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$status, $error, $queueId]);
    }

    /**
     * Cancel an existing pending queue item
     */
    public function cancel(string $idempotencyKey, string $reason = 'Condition no longer holds'): bool {
        $stmt = $this->pdo->prepare("
            UPDATE notification_queue 
            SET status = 'cancelled', last_error = ?, processed_at = NOW() 
            WHERE idempotency_key = ? AND status IN ('pending', 'processing')
        ");
        $stmt->execute([$reason, $idempotencyKey]);
        return $stmt->rowCount() > 0;
    }
}
