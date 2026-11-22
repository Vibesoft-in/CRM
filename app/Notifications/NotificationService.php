<?php
// app/Notifications/NotificationService.php
namespace App\Notifications;

use PDO;
use App\Notifications\Channels\CrmChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Channels\MobilePushChannel;
use App\Notifications\Channels\NotificationChannelInterface;

class NotificationService {
    private static ?NotificationService $instance = null;
    private PDO $pdo;
    private NotificationQueue $queue;
    /** @var array<string, NotificationChannelInterface> */
    private array $channels = [];
    private ?array $cachedSettings = null;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->queue = new NotificationQueue($pdo);

        // Register default channels
        $this->registerChannel(new CrmChannel($pdo));
        $this->registerChannel(new EmailChannel());
        $this->registerChannel(new WebPushChannel($pdo));
        $this->registerChannel(new MobilePushChannel($pdo));
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

    public function registerChannel(NotificationChannelInterface $channel): void {
        $this->channels[$channel->getName()] = $channel;
    }

    public function getQueue(): NotificationQueue {
        return $this->queue;
    }

    /**
     * Fetch all notification settings from DB with fallback defaults
     */
    public function getSettings(): array {
        if ($this->cachedSettings !== null) {
            return $this->cachedSettings;
        }

        $defaults = [
            'attendance_reminder_enabled' => '1',
            'attendance_expected_time' => '10:00:00',
            'attendance_reminder_delay' => '2',
            'attendance_channels' => '["crm","email","web_push","mobile_push"]',
            'punchout_reminder_enabled' => '1',
            'punchout_expected_time' => '20:00:00',
            'punchout_reminder_delay' => '2',
            'punchout_auto_half_day' => '1',
            'punchout_channels' => '["crm","email","web_push","mobile_push"]',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_user' => '',
            'smtp_pass' => '',
            'smtp_secure' => 'tls',
            'smtp_from_email' => '',
            'smtp_from_name' => 'VibeSoft CMS',
            'webpush_vapid_public' => '',
            'webpush_vapid_private' => '',
            'webpush_subject' => 'mailto:admin@vibesoft.in',
            'fcm_server_key' => ''
        ];

        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM notification_settings");
            $rows = $stmt ? $stmt->fetchAll() : [];
            foreach ($rows as $r) {
                $defaults[$r['setting_key']] = $r['setting_value'];
            }
        } catch (\Exception $e) {
            // If table doesn't exist yet, return defaults
        }

        $this->cachedSettings = $defaults;
        return $this->cachedSettings;
    }

    public function getSetting(string $key, $default = null) {
        $settings = $this->getSettings();
        return $settings[$key] ?? $default;
    }

    public function saveSettings(array $newSettings): bool {
        $stmt = $this->pdo->prepare("
            INSERT INTO notification_settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");

        foreach ($newSettings as $key => $val) {
            if (is_array($val)) $val = json_encode($val);
            $stmt->execute([$key, (string)$val]);
        }

        $this->cachedSettings = null;
        return true;
    }

    /**
     * Send notification immediately to a user across requested channels
     * 
     * @param int $userId Target user ID
     * @param string $type Notification type (e.g. 'attendance_reminder', 'leave_approved')
     * @param array $data Array of variables for template, or manual ['title' => ..., 'message' => ..., 'link' => ...]
     * @param array|null $channels List of channels to send to (defaults to all enabled or user preferences)
     * @param string|null $idempotencyKey Optional key to guarantee uniqueness
     * @return array Channel execution results
     */
    public function send(int $userId, string $type, array $data = [], ?array $channels = null, ?string $idempotencyKey = null): array {
        // Fetch target user info
        $uStmt = $this->pdo->prepare("SELECT id, name, email, employee_id FROM users WHERE id = ?");
        $uStmt->execute([$userId]);
        $user = $uStmt->fetch();

        if (!$user) {
            return ['success' => false, 'error' => "User ID {$userId} not found"];
        }

        // Render notification payload via template
        $notification = NotificationTemplate::render($type, $data);

        // Check user preferences
        $pref = $this->getUserPreferences($userId, $type);

        // Determine active channels
        if ($channels === null) {
            $channels = ['crm', 'email', 'web_push', 'mobile_push'];
        }

        $results = [];
        $settings = $this->getSettings();
        $notifId = null;

        foreach ($channels as $chName) {
            // Check user channel toggle
            $prefKey = $chName . '_enabled';
            if (isset($pref[$prefKey]) && (int)$pref[$prefKey] === 0) {
                $this->logChannel($notifId, null, $userId, $chName, 'skipped', null, 'Disabled by user preference');
                $results[$chName] = ['success' => true, 'skipped' => true, 'reason' => 'User preference disabled'];
                continue;
            }

            if (!isset($this->channels[$chName])) {
                $results[$chName] = ['success' => false, 'error' => "Unknown channel: {$chName}"];
                continue;
            }

            $channelObj = $this->channels[$chName];
            $res = $channelObj->send($notification, $user, $settings);
            $status = $res['success'] ? 'success' : 'failed';
            if (!empty($res['skipped'])) {
                $status = 'skipped';
            }

            if ($chName === 'crm' && !empty($res['notification_id'])) {
                $notifId = (int)$res['notification_id'];
            }

            $this->logChannel($notifId, null, $userId, $chName, $status, $res['response'] ?? null, $res['error'] ?? null);
            $results[$chName] = $res;
        }

        // Emit realtime event to user-specific stream
        try {
            require_once __DIR__ . '/../Realtime/RealtimeService.php';
            \App\Realtime\RealtimeService::getInstance($this->pdo)->emit(
                'notification.new',
                'notification',
                $notifId ?? 0,
                'alert',
                [
                    'id'         => $notifId ?? 0,
                    'type'       => $type,
                    'title'      => $notification['title'] ?? 'New Notification',
                    'message'    => $notification['message'] ?? '',
                    'link'       => $notification['link'] ?? '',
                    'created_at' => date('Y-m-d H:i:s')
                ],
                null,
                $userId
            );
        } catch (\Throwable $e) {}

        return [
            'success' => true,
            'notification_id' => $notifId,
            'channels' => $results
        ];
    }

    public static function sendToUser(int $userId, string $type, array $data = [], ?array $channels = null): array {
        return self::getInstance()->send($userId, $type, $data, $channels);
    }

    public static function sendToUsers(array $userIds, string $type, array $data = [], ?array $channels = null): array {
        $service = self::getInstance();
        $results = [];
        foreach ($userIds as $uid) {
            $results[$uid] = $service->send((int)$uid, $type, $data, $channels);
        }
        return $results;
    }

    /**
     * Schedule a notification in the queue with idempotency protection
     */
    public function queue(
        int $userId,
        string $type,
        array $payload,
        string $scheduledAt,
        string $idempotencyKey,
        array $channels = ['crm', 'email'],
        ?int $employeeId = null
    ): ?int {
        return $this->queue->enqueue($userId, $type, $payload, $scheduledAt, $idempotencyKey, $channels, $employeeId);
    }

    /**
     * Log a channel dispatch event
     */
    public function logChannel(
        ?int $notificationId,
        ?int $queueId,
        int $userId,
        string $channel,
        string $status,
        ?string $response = null,
        ?string $error = null
    ): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO notification_logs (notification_id, queue_id, user_id, channel, status, response, error_message, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $notificationId,
                $queueId,
                $userId,
                $channel,
                $status,
                $response,
                $error
            ]);
        } catch (\Exception $e) {
            // Non-blocking log failure
        }
    }

    public function getUserPreferences(int $userId, string $type): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT crm_enabled, email_enabled, web_push_enabled, mobile_push_enabled 
                FROM user_notification_preferences 
                WHERE user_id = ? AND notification_type = ?
            ");
            $stmt->execute([$userId, $type]);
            $pref = $stmt->fetch();
            if ($pref) return $pref;
        } catch (\Exception $e) {}

        // Default: all enabled
        return [
            'crm_enabled' => 1,
            'email_enabled' => 1,
            'web_push_enabled' => 1,
            'mobile_push_enabled' => 1
        ];
    }

    /**
     * Check if user is an Admin or Manager
     */
    public function isUserAdmin(int $userId): bool {
        try {
            $stmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $role = strtolower(trim((string)$stmt->fetchColumn()));
            return in_array($role, ['admin', 'super admin', 'manager'], true);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * In-App: Get unread notification count for a user (or system-wide for Admin)
     */
    public function getUnreadCount(int $userId): int {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE user_id = ? AND is_read = 0
            ");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * In-App: Fetch recent notifications for dropdown or list
     */
    public function getRecent(int $userId, int $limit = 10, int $offset = 0): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT n.*, u.name as actor_name, u.role as actor_role 
                FROM notifications n 
                LEFT JOIN users u ON u.id = n.user_id 
                WHERE n.user_id = ? 
                ORDER BY n.created_at DESC 
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function markAsRead(int $notificationId, int $userId): bool {
        try {
            if ($this->isUserAdmin($userId)) {
                $stmt = $this->pdo->prepare("
                    UPDATE notifications 
                    SET is_read = 1, status = 'read', read_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$notificationId]);
            } else {
                $stmt = $this->pdo->prepare("
                    UPDATE notifications 
                    SET is_read = 1, status = 'read', read_at = NOW() 
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$notificationId, $userId]);
            }
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function markAllAsRead(int $userId): bool {
        try {
            if ($this->isUserAdmin($userId)) {
                $stmt = $this->pdo->query("
                    UPDATE notifications 
                    SET is_read = 1, status = 'read', read_at = NOW() 
                    WHERE is_read = 0
                ");
            } else {
                $stmt = $this->pdo->prepare("
                    UPDATE notifications 
                    SET is_read = 1, status = 'read', read_at = NOW() 
                    WHERE user_id = ? AND is_read = 0
                ");
                $stmt->execute([$userId]);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }


    /**
     * Process Attendance Reminders (e.g. 10:02 AM)
     * Checks if current time is >= expected_time + delay
     * Verifies latest attendance state in real-time, skips if already checked in.
     */
    public function processAttendanceReminders(?string $targetDate = null): array {
        $settings = $this->getSettings();
        if (($settings['attendance_reminder_enabled'] ?? '1') !== '1') {
            return ['processed' => 0, 'skipped' => 0, 'reason' => 'Attendance reminder disabled'];
        }

        $today = date('Y-m-d');
        $date = $targetDate ?: $today;
        if ($date !== $today) {
            return ['processed' => 0, 'skipped' => 0, 'reason' => 'Attendance reminders only apply to current date'];
        }

        $expectedTime = $settings['attendance_expected_time'] ?? '10:00:00';
        $delayMinutes = (int)($settings['attendance_reminder_delay'] ?? 2);
        
        $expectedTs = strtotime("{$date} {$expectedTime}");
        $reminderTs = $expectedTs + ($delayMinutes * 60);
        $nowTs = time();

        // Not yet time for reminder
        if ($nowTs < $reminderTs) {
            return ['processed' => 0, 'skipped' => 0, 'reason' => 'Reminder time not yet reached'];
        }

        // Fetch active employees with linked user account
        $empStmt = $this->pdo->query("
            SELECT e.id as employee_id, e.user_id, e.full_name, e.email, u.id as direct_user_id, u.email as user_email, u.name as user_name
            FROM employees e
            LEFT JOIN users u ON u.id = e.user_id OR (e.user_id IS NULL AND u.employee_id = e.id)
            WHERE e.status = 'active'
        ");
        $employees = $empStmt ? $empStmt->fetchAll() : [];

        $channels = json_decode($settings['attendance_channels'] ?? '["crm","email","web_push","mobile_push"]', true);
        if (!is_array($channels)) $channels = ['crm', 'email'];

        $processed = 0;
        $skipped = 0;

        foreach ($employees as $emp) {
            $empId = (int)$emp['employee_id'];
            $userId = (int)($emp['user_id'] ?: $emp['direct_user_id']);
            if ($userId <= 0) {
                continue; // Cannot notify employee without a user account
            }

            $idempotencyKey = "attendance_reminder_{$empId}_{$date}";

            // 1. Idempotency Check: Was it already sent or queued?
            if ($this->queue->hasIdempotencyKey($idempotencyKey)) {
                $skipped++;
                continue;
            }

            // 2. CRITICAL REAL-TIME ATTENDANCE CHECK: Did the employee check in?
            $attStmt = $this->pdo->prepare("
                SELECT check_in, status FROM attendance 
                WHERE employee_id = ? AND attendance_date = ? 
                LIMIT 1
            ");
            $attStmt->execute([$empId, $date]);
            $att = $attStmt->fetch();

            if ($att && !empty($att['check_in'])) {
                // Employee marked attendance (even at 10:01 or 10:02 AM)! Skip reminder completely!
                $skipped++;
                continue;
            }

            // If employee is on leave or holiday today, skip reminder
            if ($att && in_array($att['status'], ['on_leave', 'holiday'])) {
                $skipped++;
                continue;
            }

            // 3. Mark idempotency key in queue first to lock against race conditions
            $payload = [
                'employee_name' => $emp['full_name'],
                'date' => date('d M Y', strtotime($date)),
                'attendance_time' => date('h:i A', $expectedTs)
            ];

            $this->queue->enqueue(
                $userId,
                'attendance_reminder',
                $payload,
                date('Y-m-d H:i:s'),
                $idempotencyKey,
                $channels,
                $empId
            );

            // 4. Send notification immediately across channels
            $this->send($userId, 'attendance_reminder', $payload, $channels, $idempotencyKey);
            $processed++;
        }

        return ['processed' => $processed, 'skipped' => $skipped];
    }

    /**
     * Process Punch-Out Reminders (e.g. 8:02 PM)
     * If employee hasn't punched out past expected time + delay:
     * 1. Applies existing CRM attendance rules (auto half-day at 12:00:00).
     * 2. Sends punchout_reminder notification.
     */
    public function processPunchoutReminders(?string $targetDate = null): array {
        $settings = $this->getSettings();
        if (($settings['punchout_reminder_enabled'] ?? '1') !== '1') {
            return ['processed' => 0, 'skipped' => 0, 'reason' => 'Punch-out reminder disabled'];
        }

        $today = date('Y-m-d');
        $date = $targetDate ?: $today;
        if ($date !== $today) {
            return ['processed' => 0, 'skipped' => 0, 'reason' => 'Punch-out reminders only apply to current date'];
        }

        $expectedTime = $settings['punchout_expected_time'] ?? '20:00:00';
        $delayMinutes = (int)($settings['punchout_reminder_delay'] ?? 2);
        
        $expectedTs = strtotime("{$date} {$expectedTime}");
        $reminderTs = $expectedTs + ($delayMinutes * 60);
        $nowTs = time();

        if ($nowTs < $reminderTs) {
            return ['processed' => 0, 'skipped' => 0, 'reason' => 'Punch-out reminder time not yet reached'];
        }

        // Fetch attendance records that have check-in but NO check-out
        $attStmt = $this->pdo->prepare("
            SELECT a.id, a.employee_id, a.check_in, a.check_out, a.status, a.notes,
                   e.user_id, e.full_name, u.id as direct_user_id
            FROM attendance a
            JOIN employees e ON e.id = a.employee_id
            LEFT JOIN users u ON u.id = e.user_id OR (e.user_id IS NULL AND u.employee_id = e.id)
            WHERE a.attendance_date = ? 
              AND a.check_in IS NOT NULL 
              AND (a.check_out IS NULL OR a.check_out = '00:00:00' OR a.check_out = '')
              AND a.status IN ('present', 'late')
        ");
        $attStmt->execute([$date]);
        $unpunched = $attStmt->fetchAll();

        $channels = json_decode($settings['punchout_channels'] ?? '["crm","email","web_push","mobile_push"]', true);
        if (!is_array($channels)) $channels = ['crm', 'email'];

        $autoHalfDay = (($settings['punchout_auto_half_day'] ?? '1') === '1');
        $processed = 0;
        $skipped = 0;

        foreach ($unpunched as $row) {
            $empId = (int)$row['employee_id'];
            $userId = (int)($row['user_id'] ?: $row['direct_user_id']);
            if ($userId <= 0) {
                continue;
            }

            $idempotencyKey = "punchout_reminder_{$empId}_{$date}";

            // 1. Idempotency Check: Was it already processed?
            if ($this->queue->hasIdempotencyKey($idempotencyKey)) {
                $skipped++;
                continue;
            }

            // 2. Real-time verification: Re-query latest check_out state
            $checkStmt = $this->pdo->prepare("SELECT check_out FROM attendance WHERE id = ?");
            $checkStmt->execute([$row['id']]);
            $currentCheckOut = $checkStmt->fetchColumn();
            if (!empty($currentCheckOut) && $currentCheckOut !== '00:00:00') {
                // Employee punched out right before or during execution!
                $skipped++;
                continue;
            }

            // 3. Apply existing half-day rule if configured
            if ($autoHalfDay) {
                $checkIn = $row['check_in'];
                $diffSec = calc_time_diff_seconds($checkIn, '12:00:00');
                $calcHours = $diffSec > 0 ? round($diffSec / 3600, 2) : 4.0;
                if ($calcHours <= 0) $calcHours = 4.0;

                $existingNote = trim($row['notes'] ?? '');
                $reasonNote = 'Punch out nahi kiya tha isliye half mark hua hai';
                $newNote = !empty($existingNote) ? ($existingNote . ' | ' . $reasonNote) : $reasonNote;

                $updStmt = $this->pdo->prepare("
                    UPDATE attendance 
                    SET status = 'half_day', check_out = '12:00:00', total_hours = ?, notes = ? 
                    WHERE id = ?
                ");
                $updStmt->execute([$calcHours, $newNote, $row['id']]);
            }

            // 4. Lock idempotency key in queue
            $payload = [
                'employee_name' => $row['full_name'],
                'date' => date('d M Y', strtotime($date)),
                'punchout_time' => date('h:i A', $expectedTs)
            ];

            $this->queue->enqueue(
                $userId,
                'punchout_reminder',
                $payload,
                date('Y-m-d H:i:s'),
                $idempotencyKey,
                $channels,
                $empId
            );

            // 5. Send notification across channels
            $this->send($userId, 'punchout_reminder', $payload, $channels, $idempotencyKey);
            $processed++;
        }

        return ['processed' => $processed, 'skipped' => $skipped];
    }

    /**
     * Process general queued notifications (with retry logic)
     */
    public function processDueQueue(int $limit = 30): array {
        $dueItems = $this->queue->getDueItems($limit);
        $processed = 0;
        $failed = 0;

        foreach ($dueItems as $item) {
            $queueId = (int)$item['id'];
            if (!$this->queue->markProcessing($queueId)) {
                continue; // Concurrency guard
            }

            $userId = (int)$item['user_id'];
            $type = $item['notification_type'];
            $payload = json_decode($item['payload'] ?? '[]', true) ?: [];
            $channels = json_decode($item['channels'] ?? '["crm","email"]', true) ?: ['crm'];

            try {
                $res = $this->send($userId, $type, $payload, $channels, $item['idempotency_key']);
                if ($res['success']) {
                    $this->queue->markSent($queueId);
                    $processed++;
                } else {
                    $isFinal = ($item['attempts'] + 1 >= $item['max_attempts']);
                    $this->queue->markFailed($queueId, $res['error'] ?? 'Delivery failed', $isFinal);
                    $failed++;
                }
            } catch (\Exception $e) {
                $isFinal = ($item['attempts'] + 1 >= $item['max_attempts']);
                $this->queue->markFailed($queueId, $e->getMessage(), $isFinal);
                $failed++;
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }
}
