<?php
// app/Notifications/Channels/CrmChannel.php
namespace App\Notifications\Channels;

use PDO;

class CrmChannel implements NotificationChannelInterface {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getName(): string {
        return 'crm';
    }

    public function send(array $notification, array $user, array $config = []): array {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return ['success' => false, 'response' => null, 'error' => 'Invalid user ID for CRM notification'];
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, link, payload, channel, status, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'crm', 'unread', 0, NOW())
            ");

            $payloadJson = !empty($notification['payload']) ? json_encode($notification['payload']) : null;
            $link = $notification['link'] ?? null;

            $stmt->execute([
                $userId,
                $notification['type'] ?? 'system_alert',
                $notification['title'] ?? 'Notification',
                $notification['message'] ?? '',
                $link,
                $payloadJson
            ]);

            $notifId = (int)$this->pdo->lastInsertId();

            return [
                'success' => true,
                'response' => "CRM notification created with ID: {$notifId}",
                'notification_id' => $notifId,
                'error' => null
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'response' => null,
                'error' => 'CRM channel DB error: ' . $e->getMessage()
            ];
        }
    }
}
