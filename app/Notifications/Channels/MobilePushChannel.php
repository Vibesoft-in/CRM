<?php
// app/Notifications/Channels/MobilePushChannel.php
namespace App\Notifications\Channels;

use PDO;

class MobilePushChannel implements NotificationChannelInterface {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getName(): string {
        return 'mobile_push';
    }

    public function send(array $notification, array $user, array $config = []): array {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return ['success' => false, 'response' => null, 'error' => 'Invalid user ID for Mobile Push'];
        }

        // Fetch active mobile devices for this user
        $stmt = $this->pdo->prepare("
            SELECT * FROM notification_devices 
            WHERE user_id = ? AND platform IN ('android', 'ios') AND is_active = 1
        ");
        $stmt->execute([$userId]);
        $devices = $stmt->fetchAll();

        if (empty($devices)) {
            return [
                'success' => true,
                'response' => 'No active mobile devices registered for user (skipped)',
                'error' => null,
                'skipped' => true
            ];
        }

        $fcmKey = $config['fcm_server_key'] ?? '';
        if (empty($fcmKey)) {
            return [
                'success' => true,
                'response' => 'Mobile device(s) registered; FCM server key not configured (ready for mobile deployment)',
                'error' => null,
                'skipped' => true
            ];
        }

        $tokens = array_column($devices, 'push_token');
        if (empty($tokens)) {
            return ['success' => true, 'response' => 'No push tokens available', 'skipped' => true];
        }

        // Send via FCM Legacy / v1 Endpoint
        $fcmPayload = [
            'registration_ids' => array_values(array_unique($tokens)),
            'notification' => [
                'title' => $notification['title'] ?? 'Notification',
                'body'  => $notification['message'] ?? '',
                'sound' => 'default'
            ],
            'data' => [
                'type' => $notification['type'] ?? 'system_alert',
                'link' => $notification['link'] ?? '',
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
            ],
            'priority' => 'high'
        ];

        $ch = curl_init('https://fcm.googleapis.com/fcm/send');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fcmPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: key=' . $fcmKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200) {
            return [
                'success' => true,
                'response' => 'FCM Mobile Push delivered successfully to ' . count($tokens) . ' token(s)',
                'error' => null
            ];
        }

        return [
            'success' => false,
            'response' => $res,
            'error' => "FCM Mobile Push failed (HTTP {$httpCode}: {$err})"
        ];
    }
}
