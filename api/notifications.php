<?php
// api/notifications.php — Centralized Notification API Endpoints
require_once __DIR__ . '/mobile_helper.php';

use App\Notifications\NotificationService;
use App\Notifications\NotificationTemplate;

// Support both Web Session (CRM UI dropdown) and Mobile Bearer Token
global $pdo;
$userId = 0;
$auth = null;

if (!empty($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
    $auth = [
        'user_id'     => $userId,
        'role'        => $_SESSION['user_role'] ?? 'employee',
        'employee_id' => $_SESSION['employee_id'] ?? null,
    ];
} elseif (!empty($_SESSION['employee_id'])) {
    $findU = $pdo->prepare("SELECT user_id FROM employees WHERE id = ? LIMIT 1");
    $findU->execute([$_SESSION['employee_id']]);
    $userId = (int)$findU->fetchColumn();
    if ($userId > 0) {
        $auth = [
            'user_id'     => $userId,
            'role'        => $_SESSION['user_role'] ?? 'employee',
            'employee_id' => (int)$_SESSION['employee_id'],
        ];
    }
}

if (!$auth) {
    $auth = authenticate_mobile();
    $userId = (int)$auth['user_id'];
}

global $pdo;
$service = NotificationService::getInstance($pdo);
$action = $_GET['action'] ?? ($_POST['action'] ?? 'unread_count');
$input = get_json_input();

switch ($action) {
    case 'unread_count':
        $count = $service->getUnreadCount($userId);
        json_success(['unread_count' => $count], 'Unread count fetched');
        break;

    case 'recent':
        $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
        $notifications = $service->getRecent($userId, $limit);
        $isAdmin = $service->isUserAdmin($userId);
        
        $formatted = array_map(function($n) use ($isAdmin) {
            $createdTs = strtotime($n['created_at']);
            $diff = max(0, time() - $createdTs);
            if ($diff < 60) {
                $relTime = 'Just now';
            } elseif ($diff < 3600) {
                $mins = max(1, (int)floor($diff / 60));
                $relTime = $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
            } elseif ($diff < 86400) {
                $hours = (int)floor($diff / 3600);
                $relTime = $hours . ' hr' . ($hours > 1 ? 's' : '') . ' ago';
            } else {
                $relTime = date('d M', $createdTs);
            }

            $tpl = NotificationTemplate::render($n['type']);
            $icon = $tpl['icon'] ?? 'bi-bell text-primary';

            $rawTitle = trim($n['title'] ?? '');
            $actorName = trim($n['actor_name'] ?? '');
            if ($isAdmin && !empty($actorName) && strtolower($actorName) !== 'admin') {
                if (stripos($rawTitle, $actorName) === false) {
                    $displayTitle = $actorName . ': ' . $rawTitle;
                } else {
                    $displayTitle = $rawTitle;
                }
            } else {
                $displayTitle = $rawTitle;
            }

            $rawLink = (string)($n['link'] ?? '');
            if ($isAdmin && !empty($rawLink)) {
                // Map employee links to admin view
                $rawLink = str_replace('/employee/attendance.php', 'modules/team/attendance.php', $rawLink);
                $rawLink = str_replace('/employee/leave.php', 'modules/team/leave.php', $rawLink);
                $rawLink = str_replace('/employee/work-reports.php', 'modules/team/work_reports.php', $rawLink);
                $rawLink = str_replace('/employee/profile.php', 'modules/team/employees.php', $rawLink);
            }

            return [
                'id'            => (int)$n['id'],
                'type'          => $n['type'],
                'title'         => htmlspecialchars($displayTitle),
                'message'       => htmlspecialchars($n['message'] ?? ''),
                'link'          => !empty($rawLink) && $rawLink !== '#' ? (BASE_URL . ltrim($rawLink, '/')) : '#',
                'is_read'       => (int)$n['is_read'],
                'icon'          => $icon,
                'created_at'    => $n['created_at'],
                'relative_time' => $relTime,
                'formatted_time'=> date('h:i A', $createdTs)
            ];
        }, $notifications);


        $unreadCount = $service->getUnreadCount($userId);
        json_success([
            'unread_count'  => $unreadCount,
            'notifications' => $formatted
        ], 'Notifications fetched');
        break;

    case 'mark_read':
        $id = intval($input['id'] ?? ($_GET['id'] ?? 0));
        if ($id <= 0) {
            json_error('Invalid Notification ID', 400);
        }
        $res = $service->markAsRead($id, $userId);
        json_success(['marked' => $res], 'Notification marked as read');
        break;

    case 'mark_all_read':
        $res = $service->markAllAsRead($userId);
        json_success(['marked_all' => $res], 'All notifications marked as read');
        break;

    case 'register_device':
        $deviceId  = trim($input['device_id'] ?? '');
        $platform  = trim($input['platform'] ?? 'android');
        $pushToken = trim($input['push_token'] ?? '');
        $p256dhKey = trim($input['p256dh_key'] ?? '');
        $authKey   = trim($input['auth_key'] ?? '');
        $browser   = trim($input['browser'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Android App'));

        if (empty($deviceId) || empty($pushToken)) {
            json_error('Missing device_id or push_token', 400);
        }

        if (!in_array($platform, ['web', 'android', 'ios'])) {
            $platform = 'android';
        }

        $stmt = $pdo->prepare("
            INSERT INTO notification_devices 
            (user_id, device_id, platform, push_token, p256dh_key, auth_key, browser, is_active, last_seen, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                push_token = VALUES(push_token),
                p256dh_key = VALUES(p256dh_key),
                auth_key = VALUES(auth_key),
                browser = VALUES(browser),
                is_active = 1,
                last_seen = NOW(),
                updated_at = NOW()
        ");

        $stmt->execute([
            $userId,
            $deviceId,
            $platform,
            $pushToken,
            $p256dhKey,
            $authKey,
            substr($browser, 0, 100)
        ]);

        json_success([], 'Device registered successfully for push notifications');
        break;

    case 'remove_device':
        $deviceId = trim($input['device_id'] ?? '');
        if (!empty($deviceId)) {
            $stmt = $pdo->prepare("UPDATE notification_devices SET is_active = 0 WHERE user_id = ? AND device_id = ?");
            $stmt->execute([$userId, $deviceId]);
        }
        json_success([], 'Device unregistered successfully');
        break;

    case 'send':
        require_role_api(['admin', 'super admin', 'manager'], $auth);
        $targetUserId = (int)($input['target_user_id'] ?? 0);
        $title        = trim($input['title'] ?? '');
        $message      = trim($input['message'] ?? '');
        $type         = trim($input['type'] ?? 'info');
        $link         = trim($input['link'] ?? '');

        if (empty($title) || empty($message)) {
            json_error('Title and message are required', 400);
        }

        if ($targetUserId > 0) {
            $service->send($targetUserId, $type, ['title' => $title, 'message' => $message, 'link' => $link]);
        } else {
            // Broadcast to all active users
            $allUsers = $pdo->query("SELECT id FROM users WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($allUsers as $uId) {
                $service->send((int)$uId, $type, ['title' => $title, 'message' => $message, 'link' => $link]);
            }
        }
        json_success([], 'Notification sent successfully');
        break;

    default:
        json_error('Invalid notification action', 400);
        break;
}
