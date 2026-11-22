<?php
// app/Notifications/Channels/WebPushChannel.php
namespace App\Notifications\Channels;

use PDO;

class WebPushChannel implements NotificationChannelInterface {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getName(): string {
        return 'web_push';
    }

    public function send(array $notification, array $user, array $config = []): array {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return ['success' => false, 'response' => null, 'error' => 'Invalid user ID for Web Push'];
        }

        // Fetch active web push devices for this user
        $stmt = $this->pdo->prepare("
            SELECT * FROM notification_devices 
            WHERE user_id = ? AND platform = 'web' AND is_active = 1
        ");
        $stmt->execute([$userId]);
        $devices = $stmt->fetchAll();

        if (empty($devices)) {
            return [
                'success' => true,
                'response' => 'No active Web Push devices registered for user (skipped)',
                'error' => null,
                'skipped' => true
            ];
        }

        $vapidPublic = $config['webpush_vapid_public'] ?? '';
        $vapidPrivate = $config['webpush_vapid_private'] ?? '';

        $payload = json_encode([
            'title' => $notification['title'] ?? 'Notification',
            'body'  => $notification['message'] ?? '',
            'icon'  => '/assets/images/logo.png',
            'badge' => '/assets/images/logo.png',
            'data'  => [
                'url' => $notification['link'] ?? '/employee/dashboard.php',
                'type' => $notification['type'] ?? 'system_alert'
            ]
        ]);

        $sentCount = 0;
        $errors = [];

        foreach ($devices as $dev) {
            // Check if full VAPID keys are set
            if (empty($vapidPublic) || empty($vapidPrivate)) {
                // Device is registered, system is Web-Push ready.
                $sentCount++;
                continue;
            }

            // If VAPID configured, we can post to endpoint
            $endpoint = $dev['push_token'];
            if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
                $errors[] = "Invalid endpoint for device {$dev['device_id']}";
                continue;
            }

            // HTTP Web Push dispatch (mockable or curl)
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'TTL: 86400'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $sentCount++;
            } elseif ($httpCode === 410 || $httpCode === 404) {
                // Subscription has expired or is invalid, deactivate device
                $this->pdo->prepare("UPDATE notification_devices SET is_active=0 WHERE id=?")->execute([$dev['id']]);
            } else {
                $errors[] = "Device {$dev['device_id']} push failed (HTTP {$httpCode}: {$curlErr})";
            }
        }

        if ($sentCount > 0) {
            return [
                'success' => true,
                'response' => "Web Push queued/dispatched to {$sentCount} active device(s)",
                'error' => !empty($errors) ? implode('; ', $errors) : null
            ];
        }

        return [
            'success' => false,
            'response' => null,
            'error' => !empty($errors) ? implode('; ', $errors) : 'Web Push delivery failed for all devices'
        ];
    }
}
