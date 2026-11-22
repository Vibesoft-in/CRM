<?php
// includes/functions.php — Core Helpers & Security Integration
require_once __DIR__ . '/../config.php';

// Load Security Infrastructure
require_once __DIR__ . '/../app/Security/AuditLogger.php';
require_once __DIR__ . '/../app/Security/RateLimiter.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/Security/SecurityHeaders.php';
require_once __DIR__ . '/../app/Security/Auth.php';
require_once __DIR__ . '/../app/Security/Authorization.php';
require_once __DIR__ . '/../app/Security/TwoFactorService.php';

// Load Notification Infrastructure
require_once __DIR__ . '/../app/Notifications/Channels/NotificationChannelInterface.php';
require_once __DIR__ . '/../app/Notifications/Channels/CrmChannel.php';
require_once __DIR__ . '/../app/Notifications/Channels/EmailChannel.php';
require_once __DIR__ . '/../app/Notifications/Channels/WebPushChannel.php';
require_once __DIR__ . '/../app/Notifications/Channels/MobilePushChannel.php';
require_once __DIR__ . '/../app/Notifications/NotificationTemplate.php';
require_once __DIR__ . '/../app/Notifications/NotificationQueue.php';
require_once __DIR__ . '/../app/Notifications/NotificationService.php';

use App\Security\Auth;
use App\Security\Authorization;
use App\Security\Csrf;
use App\Security\SecurityHeaders;
use App\Security\AuditLogger;
use App\Security\RateLimiter;
use App\Notifications\NotificationService;
use App\Notifications\NotificationTemplate;

// Initialize Session & Security Headers
Auth::startSession();
SecurityHeaders::apply();

// XSS escape
function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// CSRF Delegation
function csrf_token(): string { return Csrf::token(); }
function csrf_field(): string { return Csrf::field(); }
function verify_csrf(?string $t): bool { return Csrf::verify($t); }

// Auth Delegation
function is_logged_in(): bool { return Auth::check(); }
function login_user(array $user): void { Auth::login($user); }
function require_login(): void { Auth::requireLogin(); }
function require_role(array $roles): void { Auth::requireRole($roles); }
function require_admin(): void {
    require_login();
    if (!is_admin()) {
        abort_forbidden('Administrator access required.');
    }
}
function is_admin(): bool { return Auth::isAdmin(); }
function is_manager(): bool { return Auth::isManager(); }
function is_employee(): bool { return Auth::isEmployee(); }
function can_access_admin_panel(): bool { return Auth::canAccessAdminPanel(); }
function require_admin_panel(): void { Auth::requireAdminPanel(); }
function get_logged_employee_id(): ?int { return Auth::employeeId(); }

// RBAC Delegation
function has_permission(string $perm_key): bool {
    return Authorization::can($perm_key);
}
function require_permission(string $perm_key): void {
    Authorization::requirePermission($perm_key);
}

// Strict IDOR Resource Authorizations
function authorize_salary_slip(int $payrollId): array {
    return Authorization::authorizeSalarySlip($payrollId);
}
function authorize_employee(int $employeeId): array {
    return Authorization::authorizeEmployee($employeeId);
}
function authorize_work_report(int $reportId): array {
    return Authorization::authorizeWorkReport($reportId);
}
function authorize_attendance(int $attendanceId): array {
    return Authorization::authorizeAttendance($attendanceId);
}
function abort_forbidden(string $message = 'Access Denied'): void {
    Auth::abortForbidden($message);
}

// Money formatting
function money($v) { return number_format((float)$v,2,'.',','); }

// Amount to words (uses intl extension)


function convert_number_to_words($number) {
    $hyphen      = '-';
    $conjunction = ' and ';
    $separator   = ', ';
    $negative    = 'negative ';
    $decimal     = ' point ';
    $dictionary  = [
        0 => 'Zero',
        1 => 'One',
        2 => 'Two',
        3 => 'Three',
        4 => 'Four',
        5 => 'Five',
        6 => 'Six',
        7 => 'Seven',
        8 => 'Eight',
        9 => 'Nine',
        10 => 'Ten',
        11 => 'Eleven',
        12 => 'Twelve',
        13 => 'Thirteen',
        14 => 'Fourteen',
        15 => 'Fifteen',
        16 => 'Sixteen',
        17 => 'Seventeen',
        18 => 'Eighteen',
        19 => 'Nineteen',
        20 => 'Twenty',
        30 => 'Thirty',
        40 => 'Forty',
        50 => 'Fifty',
        60 => 'Sixty',
        70 => 'Seventy',
        80 => 'Eighty',
        90 => 'Ninety',
        100 => 'Hundred',
        1000 => 'Thousand',
        100000 => 'Lakh',
        10000000 => 'Crore'
    ];

    if (!is_numeric($number)) {
        return false;
    }

    if ($number < 0) {
        return $negative . convert_number_to_words(abs($number));
    }

    $string = $fraction = null;

    if (strpos($number, '.') !== false) {
        list($number, $fraction) = explode('.', $number);
    }

    switch (true) {
        case $number < 21:
            $string = $dictionary[$number];
            break;
        case $number < 100:
            $tens   = ((int) ($number / 10)) * 10;
            $units  = $number % 10;
            $string = $dictionary[$tens];
            if ($units) {
                $string .= $hyphen . $dictionary[$units];
            }
            break;
        case $number < 1000:
            $hundreds  = (int)($number / 100);
            $remainder = $number % 100;
            $string = $dictionary[$hundreds] . ' ' . $dictionary[100];
            if ($remainder) {
                $string .= $conjunction . convert_number_to_words($remainder);
            }
            break;
        default:
            $baseUnit   = pow(100, floor(log($number, 100)));
            if ($baseUnit > 10000000) $baseUnit = 10000000; // Crores max
            elseif ($baseUnit > 100000) $baseUnit = 100000; // Lakhs
            elseif ($baseUnit > 1000) $baseUnit = 1000;     // Thousands

            $numBaseUnits = (int) ($number / $baseUnit);
            $remainder    = $number % $baseUnit;
            $string = convert_number_to_words($numBaseUnits) . ' ' . $dictionary[$baseUnit];
            if ($remainder) {
                $string .= $remainder < 100 ? $conjunction : $separator;
                $string .= convert_number_to_words($remainder);
            }
            break;
    }

    if (null !== $fraction && is_numeric($fraction)) {
        $string .= $decimal;
        $words = [];
        foreach (str_split((string) $fraction) as $digit) {
            $words[] = $dictionary[$digit];
        }
        $string .= implode(' ', $words);
    }

    return $string;
}

// Wrapper to show Rupees in invoices
function amount_in_words($amount) {
    $amount = round($amount, 2);
    $rupees = floor($amount);
    $paise = round(($amount - $rupees) * 100);
    $words = convert_number_to_words($rupees) . " Rupees";
    if ($paise > 0) {
        $words .= " and " . convert_number_to_words($paise) . " Paise";
    }
    return $words . " Only";
}

ob_start();
// in includes/functions.php
function set_flash($message, $type = 'info') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'] = [
        'message' => $message,
        'type'    => $type
    ];
}

function get_flash() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Render HTML for Client Logo or Initial Avatar fallback
 */
function get_client_logo_html($client, $size = 40, $extraClass = '') {
    $clientName = is_array($client) ? ($client['name'] ?? ($client['client_name'] ?? '')) : (string)$client;
    $logoPath = is_array($client) ? ($client['client_logo'] ?? ($client['logo'] ?? '')) : '';

    $hasLogo = false;
    $logoUrl = '';

    if (!empty($logoPath)) {
        if (file_exists(__DIR__ . '/../' . ltrim($logoPath, '/'))) {
            $hasLogo = true;
            $logoUrl = BASE_URL . ltrim($logoPath, '/');
        } elseif (file_exists(__DIR__ . '/../uploads/client_Logo/' . basename($logoPath))) {
            $hasLogo = true;
            $logoUrl = BASE_URL . 'uploads/client_Logo/' . basename($logoPath);
        } elseif (file_exists(__DIR__ . '/../assets/uploads/client_logo/' . basename($logoPath))) {
            $hasLogo = true;
            $logoUrl = BASE_URL . 'assets/uploads/client_logo/' . basename($logoPath);
        }
    }

    // Auto-match fallback in uploads/client_Logo/ if logo field is empty or missing in DB
    if (!$hasLogo && !empty($clientName)) {
        $clientDir = __DIR__ . '/../uploads/client_Logo/';
        if (is_dir($clientDir)) {
            $files = scandir($clientDir);
            $cleanName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $clientName));
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $cleanFile = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($file, PATHINFO_FILENAME)));
                if (!empty($cleanName) && !empty($cleanFile) && (strpos($cleanName, $cleanFile) !== false || strpos($cleanFile, $cleanName) !== false)) {
                    $hasLogo = true;
                    $logoUrl = BASE_URL . 'uploads/client_Logo/' . $file;
                    break;
                }
            }
        }
    }

    $sizePx = intval($size);
    $fontSize = round($sizePx * 0.42, 1) . 'px';

    if ($hasLogo && !empty($logoUrl)) {
        return '<img src="' . e($logoUrl) . '" class="rounded-circle object-fit-cover me-3 border shadow-sm ' . e($extraClass) . '" style="width: ' . $sizePx . 'px; height: ' . $sizePx . 'px;" alt="' . e($clientName) . '">';
    } else {
        $initial = !empty($clientName) ? strtoupper(substr($clientName, 0, 1)) : 'C';
        return '<div class="rounded-circle bg-primary text-white fw-bold d-flex align-items-center justify-content-center me-3 shadow-sm ' . e($extraClass) . '" style="width: ' . $sizePx . 'px; height: ' . $sizePx . 'px; font-size: ' . $fontSize . ';">' . $initial . '</div>';
    }
}

/**
 * Render HTML for Employee Profile Photo or Initials Avatar fallback
 */
function get_employee_avatar_html($emp, $size = 40, $extraClass = '') {
    $empName   = is_array($emp) ? ($emp['full_name'] ?? ($emp['name'] ?? '')) : (string)$emp;
    $photoPath = is_array($emp) ? ($emp['profile_photo'] ?? '') : '';

    $hasPhoto = false;
    $photoUrl = '';

    if (!empty($photoPath)) {
        if (file_exists(__DIR__ . '/../' . ltrim($photoPath, '/'))) {
            $hasPhoto = true;
            $photoUrl = BASE_URL . ltrim($photoPath, '/');
        } elseif (file_exists(__DIR__ . '/../uploads/employee_photos/' . basename($photoPath))) {
            $hasPhoto = true;
            $photoUrl = BASE_URL . 'uploads/employee_photos/' . basename($photoPath);
        }
    }

    $sizePx = intval($size);
    $fontSize = round($sizePx * 0.4, 1) . 'px';

    if ($hasPhoto && !empty($photoUrl)) {
        return '<img src="' . e($photoUrl) . '" class="rounded-circle object-fit-cover me-3 border shadow-sm ' . e($extraClass) . '" style="width: ' . $sizePx . 'px; height: ' . $sizePx . 'px;" alt="' . e($empName) . '">';
    } else {
        // Generate Initials (e.g., "Kunal Sengar" -> "KS")
        $words = explode(' ', trim($empName));
        $initials = '';
        if (count($words) >= 2) {
            $initials = strtoupper(substr($words[0], 0, 1) . substr($words[count($words) - 1], 0, 1));
        } elseif (count($words) === 1 && !empty($words[0])) {
            $initials = strtoupper(substr($words[0], 0, 2));
        } else {
            $initials = 'EM';
        }
        return '<div class="rounded-circle bg-primary text-white fw-bold d-flex align-items-center justify-content-center me-3 shadow-sm ' . e($extraClass) . '" style="width: ' . $sizePx . 'px; height: ' . $sizePx . 'px; font-size: ' . $fontSize . ';">' . $initials . '</div>';
    }
}

/**
 * Get dynamic greeting based on time of day
 */
function get_time_greeting(): string {
    $hour = (int)date('G'); // 0 to 23
    if ($hour >= 4 && $hour < 12) {
        return "Good Morning";
    } elseif ($hour >= 12 && $hour < 17) {
        return "Good Afternoon";
    } elseif ($hour >= 17 && $hour < 22) {
        return "Good Evening";
    } else {
        return "Good Evening";
    }
}

/**
 * Format seconds into Hours, Minutes, Seconds (e.g. 02h 15m 30s or 2 hrs 15 mins 30 secs)
 */
function format_seconds_hms(int $seconds, bool $compact = false): string {
    if ($seconds <= 0) {
        return $compact ? '00h 00m 00s' : '0 hrs 0 mins 0 secs';
    }
    $h = (int)floor($seconds / 3600);
    $m = (int)floor(($seconds % 3600) / 60);
    $s = (int)($seconds % 60);
    if ($compact) {
        return sprintf('%02dh %02dm %02ds', $h, $m, $s);
    }
    $parts = [];
    if ($h > 0) $parts[] = "{$h} hr" . ($h > 1 ? 's' : '');
    if ($m > 0 || $h > 0) $parts[] = "{$m} min" . ($m > 1 ? 's' : '');
    $parts[] = "{$s} sec" . ($s > 1 ? 's' : '');
    return implode(' ', $parts);
}

function format_minutes_hms($minutes, bool $compact = false): string {
    $sec = (int)round(((float)$minutes) * 60);
    return format_seconds_hms($sec, $compact);
}

function format_hours_hms($hours, bool $compact = false): string {
    $sec = (int)round(((float)$hours) * 3600);
    return format_seconds_hms($sec, $compact);
}

/**
 * Calculate difference in seconds between two times/datetimes
 */
function calc_time_diff_seconds(?string $timeIn, ?string $timeOut): int {
    if (empty($timeIn) || empty($timeOut)) return 0;
    $tIn  = strtotime(str_contains($timeIn, '-') ? $timeIn : ('2000-01-01 ' . $timeIn));
    $tOut = strtotime(str_contains($timeOut, '-') ? $timeOut : ('2000-01-01 ' . $timeOut));
    if ($tOut <= $tIn) return 0;
    return max(0, $tOut - $tIn);
}

/**
 * Auto-process attendance rules for 5 PM and 8 PM
 * Rule 1: No check-in by 5:00 PM -> Mark Absent
 * Rule 2: At/After 8:00 PM (end of day) -> If checked in without check-out, mark Half Day, check_out='12:00:00', note='Punch out nahi kiya tha isliye half mark hua hai'
 */
function auto_process_daily_attendance(PDO $pdo, ?string $targetDate = null): array {
    $result = ['absent_marked' => 0, 'half_day_marked' => 0];
    try {
        $pdo->query("SELECT 1 FROM attendance LIMIT 1");
    } catch (Exception $e) {
        return $result;
    }

    $today = date('Y-m-d');
    $nowHour = (int)date('G'); // 0-23
    $nowMinute = (int)date('i');
    $nowTotalMinutes = ($nowHour * 60) + $nowMinute;

    $datesToProcess = [];
    if (!empty($targetDate)) {
        $datesToProcess[] = $targetDate;
    } else {
        // Backfill up to the last 14 days + today so past days with no logins are never missed
        for ($back = 14; $back >= 1; $back--) {
            $datesToProcess[] = date('Y-m-d', strtotime("-$back days"));
        }
        $datesToProcess[] = $today;
    }

    // Clean up any records that were accidentally inserted on non-working Sundays
    try {
        $pdo->query("DELETE FROM attendance WHERE DAYOFWEEK(attendance_date) = 1");
    } catch (\Throwable $t) {}

    $holidaysMap = get_active_holidays_map($pdo, (int)date('Y'));
    $overridesMap = get_holiday_overrides_map($pdo, (int)date('Y'));

    $activeEmployees = $pdo->query("SELECT id FROM employees WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($activeEmployees)) {
        return $result;
    }
    $inList = implode(',', array_map('intval', $activeEmployees));

    $insStmt = $pdo->prepare("INSERT IGNORE INTO attendance (employee_id, attendance_date, status, notes, marked_by) VALUES (?, ?, 'absent', 'Auto-marked: 5:00 PM tak check-in nahi kiya', 0)");
    $updHalfStmt = $pdo->prepare("UPDATE attendance SET status = 'half_day', check_out = '12:00:00', total_hours = ?, notes = ? WHERE id = ?");

    foreach ($datesToProcess as $date) {
        $isToday = ($date === $today);
        $isPast  = ($date < $today);

        $dayCheck = is_date_holiday_or_sunday($date, $holidaysMap, $overridesMap);
        $isNonWorking = (bool)$dayCheck['is_non_working'];

        // Non-working days should never be marked absent
        if ($isNonWorking) {
            continue;
        }

        $shouldProcess5PM = $isPast || ($isToday && $nowTotalMinutes >= 1020);
        $shouldProcess8PM = $isPast || ($isToday && $nowTotalMinutes >= 1200);

        // Rule 1: Mark Unmarked Active Employees as Absent after 5:00 PM
        if ($shouldProcess5PM) {
            $existing = $pdo->query("SELECT employee_id FROM attendance WHERE attendance_date = '{$date}' AND employee_id IN ($inList)")->fetchAll(PDO::FETCH_COLUMN);
            $existingMap = array_flip($existing);

            foreach ($activeEmployees as $empId) {
                if (!isset($existingMap[$empId])) {
                    $insStmt->execute([$empId, $date]);
                    $result['absent_marked']++;
                }
            }
        }

        // Rule 2: Process Un-punched-out records after 8:00 PM -> Half Day @ 12:00:00
        if ($shouldProcess8PM) {
            $unclosedStmt = $pdo->prepare("
                SELECT a.id, a.employee_id, a.check_in, a.notes, e.full_name, e.user_id, u.id as direct_user_id
                FROM attendance a 
                LEFT JOIN employees e ON e.id = a.employee_id 
                LEFT JOIN users u ON u.id = e.user_id OR (e.user_id IS NULL AND u.employee_id = e.id)
                WHERE a.attendance_date = ? 
                  AND a.check_in IS NOT NULL 
                  AND (a.check_out IS NULL OR a.check_out = '00:00:00' OR a.check_out = '') 
                  AND a.status IN ('present', 'late')
            ");
            $unclosedStmt->execute([$date]);
            $unclosed = $unclosedStmt->fetchAll();

            $notifService = NotificationService::getInstance($pdo);

            foreach ($unclosed as $row) {
                $checkIn = $row['check_in'];
                $diffSec = calc_time_diff_seconds($checkIn, '12:00:00');
                $calcHours = $diffSec > 0 ? round($diffSec / 3600, 2) : 4.0;
                if ($calcHours <= 0) $calcHours = 4.0;

                $existingNote = trim($row['notes'] ?? '');
                $reasonNote = 'Punch out nahi kiya tha isliye half mark hua hai';
                $newNote = !empty($existingNote) ? ($existingNote . ' | ' . $reasonNote) : $reasonNote;

                $updHalfStmt->execute([$calcHours, $newNote, $row['id']]);
                $result['half_day_marked']++;

                if ($isToday) {
                    $userId = (int)($row['user_id'] ?: $row['direct_user_id']);
                    if ($userId > 0) {
                        $idKey = "punchout_reminder_{$row['employee_id']}_{$date}";
                        if (!$notifService->getQueue()->hasIdempotencyKey($idKey)) {
                            $notifService->getQueue()->enqueue(
                                $userId,
                                'punchout_reminder',
                                [
                                    'employee_name' => $row['full_name'] ?? 'Team Member',
                                    'date' => date('d M Y', strtotime($date)),
                                    'punchout_time' => '08:00 PM'
                                ],
                                date('Y-m-d H:i:s'),
                                $idKey,
                                ['crm', 'email', 'web_push', 'mobile_push'],
                                (int)$row['employee_id']
                            );
                            $notifService->send($userId, 'punchout_reminder', [
                                'employee_name' => $row['full_name'] ?? 'Team Member',
                                'date' => date('d M Y', strtotime($date)),
                                'punchout_time' => '08:00 PM'
                            ], null, $idKey);
                        }
                    }
                }
            }
        }
    }

    return $result;
}

/**
 * Global helper to send notification to a user
 */
function notify_user(int $userId, string $type, array $params = [], ?array $channels = null): array {
    global $pdo;
    return NotificationService::getInstance($pdo)->send($userId, $type, $params, $channels);
}

/**
 * Global helper to send notification to multiple users
 */
function notify_users(array $userIds, string $type, array $params = [], ?array $channels = null): array {
    global $pdo;
    return NotificationService::sendToUsers($userIds, $type, $params, $channels);
}

/**
 * Render responsive notification bell dropdown with live polling and AJAX
 */
function render_notification_bell(): void {
    if (!is_logged_in()) return;
    $userId = (int)($_SESSION['user_id'] ?? 0);
    global $pdo;
    $service = NotificationService::getInstance($pdo);
    $initialUnread = $service->getUnreadCount($userId);
    $recentNotifs = $service->getRecent($userId, 8);
    $isEmp = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/employee/') !== false);
    $allUrl = $isEmp ? (BASE_URL . 'employee/notifications.php') : (BASE_URL . 'modules/settings/notification_history.php');
    ?>
    <div class="dropdown me-2" id="cmsNotificationDropdown">
      <button class="btn btn-light position-relative rounded-circle p-2 d-flex align-items-center justify-content-center border-0 shadow-none" 
              type="button" id="notifBellBtn" data-bs-toggle="dropdown" aria-expanded="false" 
              style="width: 38px; height: 38px; background: #f8fafc;" title="Notifications">
        <i class="bi bi-bell fs-5 text-secondary"></i>
        <span id="notifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" 
              style="<?= $initialUnread > 0 ? '' : 'display:none;' ?> font-size: 0.65rem;">
          <?= $initialUnread > 99 ? '99+' : $initialUnread ?>
        </span>
      </button>
      <div class="dropdown-menu dropdown-menu-end shadow border-0 p-0" style="width: 350px; max-width: 92vw;" aria-labelledby="notifBellBtn">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-light rounded-top">
          <div class="d-flex align-items-center gap-2">
            <h6 class="mb-0 fw-bold text-dark">Notifications</h6>
            <span class="badge bg-primary rounded-pill" id="notifDropdownCount" style="font-size:0.7rem;"><?= $initialUnread ?></span>
          </div>
          <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 small text-primary fw-semibold" id="notifMarkAllReadBtn" onclick="if(window.markAllNotifsReadGlobal) window.markAllNotifsReadGlobal(event);" style="font-size:0.78rem;">
            Mark all as read
          </button>
        </div>
        <div id="notifListContainer" style="max-height: 360px; overflow-y: auto;">
          <?php if (empty($recentNotifs)): ?>
            <div class="p-4 text-center text-muted small"><i class="bi bi-bell-slash fs-3 d-block mb-2 text-secondary"></i>No notifications yet</div>
          <?php else: ?>
            <?php foreach ($recentNotifs as $n): 
              $isUnread = ((int)$n['is_read'] === 0);
              $createdTs = strtotime($n['created_at']);
              $diff = time() - $createdTs;
              if ($diff < 60) $relTime = 'Just now';
              elseif ($diff < 3600) $relTime = floor($diff / 60) . ' mins ago';
              elseif ($diff < 86400) $relTime = floor($diff / 3600) . ' hours ago';
              else $relTime = date('d M', $createdTs);

              $tpl = NotificationTemplate::render($n['type']);
              $icon = $tpl['icon'] ?? 'bi-bell text-primary';
              
              $rawLink = (string)($n['link'] ?? '');
              $isAdminUser = is_admin() || is_manager();
              if ($isAdminUser && !empty($rawLink)) {
                  $rawLink = str_replace('/employee/attendance.php', 'modules/team/attendance.php', $rawLink);
                  $rawLink = str_replace('/employee/leave.php', 'modules/team/leave.php', $rawLink);
                  $rawLink = str_replace('/employee/work-reports.php', 'modules/team/work_reports.php', $rawLink);
                  $rawLink = str_replace('/employee/profile.php', 'modules/team/employees.php', $rawLink);
              }
              $itemLink = !empty($rawLink) && $rawLink !== '#' ? (BASE_URL . ltrim($rawLink, '/')) : '#';
              
              $dispTitle = trim($n['title'] ?? '');
              $actorName = trim($n['actor_name'] ?? '');
              if ($isAdminUser && !empty($actorName) && strtolower($actorName) !== 'admin') {
                  if (stripos($dispTitle, $actorName) === false) {
                      $dispTitle = $actorName . ': ' . $dispTitle;
                  }
              }
              $cleanMsg = str_replace(["\r", "\n"], ' ', $n['message'] ?? '');
            ?>
              <div class="p-3 border-bottom notif-item d-flex align-items-start gap-2 <?= $isUnread ? 'bg-light bg-opacity-75 notif-unread' : '' ?>" 
                   style="cursor: pointer; transition: background 0.15s;" 
                   onclick="handleNotifClick(<?= (int)$n['id'] ?>, <?= htmlspecialchars(json_encode($itemLink), ENT_QUOTES, 'UTF-8') ?>)">
                <div class="fs-5 mt-0"><i class="bi <?= e($icon) ?>"></i></div>
                <div class="flex-grow-1 overflow-hidden">
                  <div class="d-flex justify-content-between align-items-baseline mb-1">
                    <strong class="text-dark text-truncate small <?= $isUnread ? 'fw-bold' : 'fw-medium' ?>"><?= e($dispTitle) ?></strong>
                    <span class="text-muted text-nowrap ms-1" style="font-size:0.7rem;"><?= e($relTime) ?></span>
                  </div>
                  <div class="text-muted small text-truncate" style="font-size:0.75rem;" title="<?= e($cleanMsg) ?>"><?= e($cleanMsg) ?></div>
                </div>
                <?php if ($isUnread): ?>
                  <span class="p-1 bg-primary rounded-circle mt-2 notif-dot"></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="p-2 border-top text-center bg-light rounded-bottom">
          <a href="<?= $allUrl ?>" class="btn btn-link btn-sm text-decoration-none fw-semibold text-primary py-1" style="font-size:0.8rem;">
            View All Notifications <i class="bi bi-arrow-right ms-1"></i>
          </a>
        </div>
      </div>
    </div>
    <script>
    (function() {
      const API_URL = '<?= rtrim(BASE_URL, '/') ?>/api/notifications.php';
      const badge = document.getElementById('notifBadge');
      const countEl = document.getElementById('notifDropdownCount');
      const container = document.getElementById('notifListContainer');
      const markAllBtn = document.getElementById('notifMarkAllReadBtn');
      const bellBtn = document.getElementById('notifBellBtn');

      function updateBadge(c) {
        if (c > 0) {
          if (badge) {
            badge.textContent = c > 99 ? '99+' : c;
            badge.style.display = '';
          }
          if (countEl) countEl.textContent = c;
        } else {
          if (badge) badge.style.display = 'none';
          if (countEl) countEl.textContent = '0';
        }
      }

      function loadNotifications() {
        fetch(API_URL + '?action=recent&limit=8', { credentials: 'same-origin' })
          .then(r => r.json())
          .then(res => {
            if (!res || !res.success) return;
            updateBadge(res.unread_count);
            if (!res.notifications || res.notifications.length === 0) {
              container.innerHTML = '<div class="p-4 text-center text-muted small"><i class="bi bi-bell-slash fs-3 d-block mb-2 text-secondary"></i>No notifications yet</div>';
              return;
            }
            let html = '';
            res.notifications.forEach(n => {
              const isUnread = (n.is_read === 0);
              const safeLink = encodeURI(n.link || '#');
              const cleanMsg = (n.message || '').replace(/\r?\n/g, ' ').replace(/"/g, '&quot;');
              const cleanTitle = (n.title || '').replace(/"/g, '&quot;');
              html += `
                <div class="p-3 border-bottom notif-item d-flex align-items-start gap-2 ${isUnread ? 'bg-light bg-opacity-75 notif-unread' : ''}" 
                     style="cursor: pointer; transition: background 0.15s;" 
                     onclick="handleNotifClick(${n.id}, '${safeLink}')">
                  <div class="fs-5 mt-0"><i class="bi ${n.icon || 'bi-bell text-primary'}"></i></div>
                  <div class="flex-grow-1 overflow-hidden">
                    <div class="d-flex justify-content-between align-items-baseline mb-1">
                      <strong class="text-dark text-truncate small ${isUnread ? 'fw-bold' : 'fw-medium'}">${cleanTitle}</strong>
                      <span class="text-muted text-nowrap ms-1" style="font-size:0.7rem;">${n.relative_time}</span>
                    </div>
                    <div class="text-muted small text-truncate" style="font-size:0.75rem;" title="${cleanMsg}">${cleanMsg}</div>
                  </div>
                  ${isUnread ? '<span class="p-1 bg-primary rounded-circle mt-2 notif-dot"></span>' : ''}
                </div>
              `;
            });
            container.innerHTML = html;
          })
          .catch(() => {});
      }

      window.handleNotifClick = function(id, link) {
        fetch(API_URL + '?action=mark_read&id=' + id, { method: 'POST', credentials: 'same-origin' })
          .finally(() => {
            if (link && link !== '#' && link !== '') {
              window.location.href = link;
            } else {
              loadNotifications();
            }
          });
      };

      window.markAllNotifsReadGlobal = function(e) {
        if (e && e.stopPropagation) e.stopPropagation();
        updateBadge(0);
        document.querySelectorAll('.notif-dot').forEach(el => el.remove());
        document.querySelectorAll('.notif-unread').forEach(el => el.classList.remove('bg-light', 'bg-opacity-75', 'notif-unread'));
        fetch(API_URL + '?action=mark_all_read', { method: 'POST', credentials: 'same-origin' })
          .then(r => r.json())
          .catch(() => {});
      };

      if (markAllBtn) {
        markAllBtn.addEventListener('click', window.markAllNotifsReadGlobal);
      }

      if (bellBtn) {
        bellBtn.addEventListener('show.bs.dropdown', function() {
          loadNotifications();
        });
      }

      // Live Polling every 45 seconds
      setInterval(() => {
        fetch(API_URL + '?action=unread_count', { credentials: 'same-origin' })
          .then(r => r.json())
          .then(res => {
            if (res && res.success) updateBadge(res.unread_count);
          })
          .catch(() => {});
      }, 45000);
    })();
    </script>
    <?php
}

/**
 * Detect human-readable device name and browser from User Agent
 */
function get_client_device_name(?string $userAgent = null): string {
    $ua = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Device');
    
    // Detect Operating System
    $os = 'Unknown OS';
    if (preg_match('/windows nt 10/i', $ua))          $os = 'Windows 10/11';
    elseif (preg_match('/windows nt 6.3/i', $ua))      $os = 'Windows 8.1';
    elseif (preg_match('/windows nt 6.1/i', $ua))      $os = 'Windows 7';
    elseif (preg_match('/windows/i', $ua))             $os = 'Windows PC';
    elseif (preg_match('/iphone/i', $ua))              $os = 'iPhone (iOS)';
    elseif (preg_match('/ipad/i', $ua))                $os = 'iPad (iPadOS)';
    elseif (preg_match('/android/i', $ua))             $os = 'Android Device';
    elseif (preg_match('/macintosh|mac os x/i', $ua))  $os = 'Mac (macOS)';
    elseif (preg_match('/linux/i', $ua))               $os = 'Linux PC';

    // Detect Browser
    $browser = 'Browser';
    if (preg_match('/edg/i', $ua))                    $browser = 'Edge';
    elseif (preg_match('/chrome|crios/i', $ua))        $browser = 'Chrome';
    elseif (preg_match('/firefox|fxios/i', $ua))       $browser = 'Firefox';
    elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/opera|opr/i', $ua))           $browser = 'Opera';

    return "{$os} · {$browser}";
}

/**
 * Fetch password change history logs for an employee
 */
function get_employee_password_logs(PDO $pdo, int $employeeId, int $limit = 30): array {
    $stmt = $pdo->prepare("
        SELECT * FROM employee_password_logs 
        WHERE employee_id = ? 
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    $stmt->bindValue(1, $employeeId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Regenerate / Reset Password for an Employee and record audit log with device name
 */
function reset_employee_password(PDO $pdo, int $employeeId, string $newPassword, bool $sendEmail = false): array {
    $empStmt = $pdo->prepare("
        SELECT e.*, u.id as user_id, u.email as user_email 
        FROM employees e 
        LEFT JOIN users u ON u.id = e.user_id OR (e.user_id IS NULL AND u.employee_id = e.id) 
        WHERE e.id = ?
    ");
    $empStmt->execute([$employeeId]);
    $emp = $empStmt->fetch();

    if (!$emp) {
        return ['success' => false, 'error' => 'Employee record not found.'];
    }

    $empName = $emp['full_name'];
    $empEmail = !empty($emp['email']) ? $emp['email'] : ($emp['user_email'] ?? '');
    $userId = (int)($emp['user_id'] ?? 0);

    if ($userId <= 0 && !empty($empEmail)) {
        $uChk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $uChk->execute([$empEmail]);
        $userId = (int)$uChk->fetchColumn();
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

    if ($userId > 0) {
        $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $upd->execute([$newHash, $userId]);
    } else {
        $ins = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, employee_id, status) VALUES (?, ?, ?, 'Employee', ?, 'active')");
        $ins->execute([$empName, $empEmail, $newHash, $employeeId]);
        $userId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE employees SET user_id = ? WHERE id = ?")->execute([$userId, $employeeId]);
    }

    // Capture Client Device and IP Information
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $deviceName = get_client_device_name($userAgent);
    $adminId   = $_SESSION['user_id'] ?? null;
    $adminName = $_SESSION['user_name'] ?? 'Admin';

    // Log old/new password into employee_password_logs
    try {
        $logStmt = $pdo->prepare("
            INSERT INTO employee_password_logs 
            (employee_id, user_id, changed_by_user_id, changed_by_name, password_plain, device_name, ip_address, user_agent, change_type, notes, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'admin_reset', ?, NOW())
        ");
        $logStmt->execute([
            $employeeId,
            $userId,
            $adminId,
            $adminName,
            $newPassword,
            $deviceName,
            $ipAddress,
            $userAgent,
            'Password regenerated by ' . $adminName
        ]);
    } catch (\Exception $e) {
        // Log table insert guard
    }

    // Security Audit Log
    if (class_exists('App\Security\AuditLogger')) {
        \App\Security\AuditLogger::securityEvent('ADMIN_RESET_EMPLOYEE_PASSWORD', [
            'employee_id' => $employeeId,
            'user_id'     => $userId,
            'device_name' => $deviceName,
            'admin_id'    => $adminId
        ]);
    }

    $emailSent = false;
    $emailError = null;

    if ($sendEmail && !empty($empEmail)) {
        try {
            $notifService = \App\Notifications\NotificationService::getInstance($pdo);
            $loginUrl = defined('BASE_URL') ? BASE_URL . 'modules/auth/login.php' : '/modules/auth/login.php';
            $emailRes = $notifService->send($userId, 'system_alert', [
                'title'   => 'Your Login Password Has Been Reset',
                'message' => "Hello {$empName},\n\nYour Employee Portal login credentials have been reset by administration.\n\nLogin Email: {$empEmail}\nNew Password: {$newPassword}\nPortal Login Link: {$loginUrl}\n\nPlease login and change your password after logging in.",
                'link'    => '/employee/dashboard.php'
            ], ['email', 'crm']);

            if (!empty($emailRes['channels']['email']['success'])) {
                $emailSent = true;
            } else {
                $emailError = $emailRes['channels']['email']['error'] ?? 'Email delivery failed';
            }
        } catch (\Exception $e) {
            $emailError = $e->getMessage();
        }
    }

    return [
        'success'       => true,
        'employee_id'   => $employeeId,
        'employee_name' => $empName,
        'employee_code' => $emp['employee_code'],
        'email'         => $empEmail,
        'new_password'  => $newPassword,
        'device_name'   => $deviceName,
        'email_sent'    => $emailSent,
        'email_error'   => $emailError
    ];
}

/**
 * Return resolved company logo URL
 */
function get_company_logo(): string {
    global $pdo;
    try {
        if ($pdo) {
            $stmt = $pdo->query("SELECT logo FROM settings ORDER BY id ASC LIMIT 1");
            $logo = $stmt ? $stmt->fetchColumn() : null;
            if ($logo && file_exists(__DIR__ . '/../' . ltrim($logo, '/\\'))) {
                return BASE_URL . ltrim($logo, '/\\');
            }
        }
    } catch (\Throwable $e) {
        // Fallback silently
    }

    if (file_exists(__DIR__ . '/../assets/images/logo.png')) {
        return BASE_URL . 'assets/images/logo.png';
    }

    return BASE_URL . 'assets/images/logo.png';
}

/**
 * Format minutes into Xh Ym format (e.g. 7h 00m, 6h 40m)
 */
function format_minutes_hm(int $totalMinutes): string {
    $isNegative = ($totalMinutes < 0);
    $abs = abs($totalMinutes);
    $h = (int)floor($abs / 60);
    $m = (int)($abs % 60);
    return ($isNegative ? '-' : '') . sprintf('%dh %02dm', $h, $m);
}

/**
 * Format minute difference into +Xm or -Xm
 */
function format_minute_difference(int $diffMinutes): string {
    if ($diffMinutes > 0) {
        $h = (int)floor($diffMinutes / 60);
        $m = $diffMinutes % 60;
        return $h > 0 ? "+{$h}h {$m}m" : "+{$m}m";
    } elseif ($diffMinutes < 0) {
        $abs = abs($diffMinutes);
        $h = (int)floor($abs / 60);
        $m = $abs % 60;
        return $h > 0 ? "-{$h}h {$m}m" : "-{$m}m";
    }
    return "0m";
}

/**
 * Fetch all active holidays indexed by date
 */
function get_active_holidays_map(PDO $pdo, ?int $year = null): array {
    $holidays = [];
    $sql = "SELECT id, holiday_date, name, COALESCE(holiday_type, 'festival') as holiday_type, description 
            FROM holidays 
            WHERE (status = 'active' OR status IS NULL)";
    $params = [];
    if ($year !== null) {
        $sql .= " AND YEAR(holiday_date) = ?";
        $params[] = $year;
    }
    $sql .= " ORDER BY holiday_date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $holidays[$r['holiday_date']] = [
            'id'    => (int)$r['id'],
            'date'  => $r['holiday_date'],
            'name'  => $r['name'],
            'type'  => $r['holiday_type'] ?? 'festival',
            'desc'  => $r['description'] ?? ''
        ];
    }
    return $holidays;
}

/**
 * Fetch calendar date overrides (e.g. Sunday scheduled as working day, or a custom holiday scheduled)
 */
function get_holiday_overrides_map(PDO $pdo, ?int $year = null, bool $forceRefresh = false): array {
    static $cached = [];
    $cacheKey = $year ?? 'all';
    if (!$forceRefresh && isset($cached[$cacheKey])) {
        return $cached[$cacheKey];
    }

    $overrides = [];
    try {
        $sql = "SELECT override_date, action, reason FROM holiday_overrides";
        $params = [];
        if ($year !== null) {
            $sql .= " WHERE YEAR(override_date) = ?";
            $params[] = $year;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $overrides[$row['override_date']] = $row;
        }
    } catch (\Throwable $e) {}
    $cached[$cacheKey] = $overrides;
    return $overrides;
}

/**
 * Check if a date is a Sunday or an active holiday, respecting admin overrides
 */
function is_date_holiday_or_sunday(string $date, ?array $holidaysMap = null, ?array $overridesMap = null): array {
    $ts = strtotime($date);
    $isSunday = ((int)date('w', $ts) === 0);

    if ($holidaysMap === null) {
        global $pdo;
        $holidaysMap = ($pdo instanceof PDO) ? get_active_holidays_map($pdo, (int)date('Y', $ts)) : [];
    }

    if ($overridesMap === null) {
        global $pdo;
        if ($pdo instanceof PDO) {
            $overridesMap = get_holiday_overrides_map($pdo, (int)date('Y', $ts));
        } else {
            $overridesMap = [];
        }
    }


    $override = $overridesMap[$date] ?? null;

    // Check if admin explicitly scheduled this day as a working day (e.g. working Sunday)
    if ($override && $override['action'] === 'working_day') {
        return [
            'is_non_working'    => false,
            'is_sunday'         => $isSunday,
            'is_holiday'        => false,
            'is_working_sunday' => $isSunday,
            'reason'            => $override['reason'] ?: ($isSunday ? 'Working Sunday (Scheduled)' : 'Working Day'),
            'holiday_name'      => null,
            'holiday_type'      => null
        ];
    }

    // Check if admin explicitly scheduled this day as a holiday
    if ($override && $override['action'] === 'holiday') {
        return [
            'is_non_working'    => true,
            'is_sunday'         => $isSunday,
            'is_holiday'        => true,
            'is_working_sunday' => false,
            'reason'            => $override['reason'] ?: 'Scheduled Holiday',
            'holiday_name'      => $override['reason'] ?: 'Scheduled Holiday',
            'holiday_type'      => 'company'
        ];
    }

    $h = $holidaysMap[$date] ?? null;
    $isHoliday = ($h && strtolower($h['type'] ?? '') !== 'optional');

    if ($isSunday && $isHoliday) {
        return [
            'is_non_working'    => true,
            'is_sunday'         => true,
            'is_holiday'        => true,
            'is_working_sunday' => false,
            'reason'            => 'Sunday & ' . $h['name'],
            'holiday_name'      => $h['name'],
            'holiday_type'      => $h['type']
        ];
    } elseif ($isSunday) {
        return [
            'is_non_working'    => true,
            'is_sunday'         => true,
            'is_holiday'        => false,
            'is_working_sunday' => false,
            'reason'            => 'Sunday',
            'holiday_name'      => 'Sunday Weekly Off',
            'holiday_type'      => 'weekly_off'
        ];
    } elseif ($isHoliday) {
        return [
            'is_non_working'    => true,
            'is_sunday'         => false,
            'is_holiday'        => true,
            'is_working_sunday' => false,
            'reason'            => $h['name'],
            'holiday_name'      => $h['name'],
            'holiday_type'      => $h['type']
        ];
    }

    return [
        'is_non_working'    => false,
        'is_sunday'         => false,
        'is_holiday'        => false,
        'is_working_sunday' => false,
        'reason'            => null,
        'holiday_name'      => null,
        'holiday_type'      => null
    ];
}


/**
 * Count working days in a date range (excluding Sundays & active holidays)
 * Flexibly accepts ($startDate, $endDate, $holidaysMap) OR ($pdo, $startDate, $endDate)
 */
function get_working_days_count($param1, $param2, $param3 = null): int {
    if ($param1 instanceof PDO) {
        $pdo = $param1;
        $startDate = (string)$param2;
        $endDate = (string)$param3;
        $year = (int)date('Y', strtotime($startDate));
        $holidaysMap = get_active_holidays_map($pdo, $year);
    } else {
        $startDate = (string)$param1;
        $endDate = (string)$param2;
        $holidaysMap = is_array($param3) ? $param3 : [];
    }

    $startTs = strtotime($startDate);
    $endTs   = strtotime($endDate);
    if ($endTs < $startTs) return 0;

    $workingDays = 0;
    $currTs = $startTs;
    while ($currTs <= $endTs) {
        $dStr = date('Y-m-d', $currTs);
        $check = is_date_holiday_or_sunday($dStr, $holidaysMap);
        if (!$check['is_non_working']) {
            $workingDays++;
        }
        $currTs = strtotime('+1 day', $currTs);
    }
    return $workingDays;
}

/**
 * Centralized Attendance & Holiday Status Resolution Engine
 * Strictly follows business rule hierarchy:
 * 1. Holiday / Sunday (H) -> If punched & worked, Present (P) with note; else Holiday (H)
 * 2. Approved Leave (L)
 * 3. Working Day with Punch -> Present (P), Late (Late/P), Half Day (HD)
 * 4. Working Day without Punch -> Past = Absent (A), Today = Pending, Future = Upcoming
 */
function resolve_daily_attendance_status(PDO $pdo, int $employeeId, string $date, ?array $attRow = null, ?array $holidaysMap = null): array {
    $dateTs = strtotime($date);
    $year = (int)date('Y', $dateTs);
    if ($holidaysMap === null) {
        $holidaysMap = get_active_holidays_map($pdo, $year);
    }

    // Day of week & Holiday check
    $dayCheck = is_date_holiday_or_sunday($date, $holidaysMap);
    $isSunday = (bool)$dayCheck['is_sunday'];
    $isHoliday = (bool)$dayCheck['is_holiday'];
    $isNonWorking = (bool)$dayCheck['is_non_working'];

    // Fetch attendance row if not provided
    if ($attRow === null) {
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
        $stmt->execute([$employeeId, $date]);
        $attRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Check if employee legitimately punched in/out
    $hasPunch = ($attRow && !empty($attRow['check_in']) && $attRow['check_in'] !== '00:00:00');
    $checkInTime = $hasPunch ? $attRow['check_in'] : null;
    $checkOutTime = ($attRow && !empty($attRow['check_out']) && $attRow['check_out'] !== '00:00:00') ? $attRow['check_out'] : null;

    // Fetch lunch sessions for this date
    $lunchSessions = get_today_lunch_sessions($pdo, $employeeId, $date);
    $activeLunch = get_active_lunch_session($pdo, $employeeId, $date);
    $isOnLunch = !empty($activeLunch);
    $lunchSec = get_today_lunch_duration_seconds($pdo, $employeeId, $date);
    $latestSession = !empty($lunchSessions) ? end($lunchSessions) : null;
    $lunchStart = $latestSession ? date('h:i A', strtotime($latestSession['start_time'])) : null;
    $lunchEnd = ($latestSession && !empty($latestSession['end_time'])) ? date('h:i A', strtotime($latestSession['end_time'])) : ($isOnLunch ? 'In Progress' : null);

    // Calculate net working hours
    $rawWorkSec = 0;
    $isRecordAbsentOrLeave = ($attRow && in_array(strtolower($attRow['status'] ?? ''), ['absent', 'on_leave']));
    if ($hasPunch && !$isRecordAbsentOrLeave) {
        if ($checkOutTime) {
            $rawWorkSec = calc_time_diff_seconds($checkInTime, $checkOutTime);
        } elseif ($date === date('Y-m-d')) {
            $rawWorkSec = calc_time_diff_seconds($checkInTime, date('H:i:s'));
        }
    }
    $netWorkSec = $isRecordAbsentOrLeave ? 0 : max(0, $rawWorkSec - $lunchSec);
    $totalHours = $isRecordAbsentOrLeave ? 0.00 : round($netWorkSec / 3600, 2);
    if (!$isRecordAbsentOrLeave && $attRow && (float)($attRow['total_hours'] ?? 0) > 0 && !$checkOutTime && $date !== date('Y-m-d')) {
        $totalHours = (float)$attRow['total_hours'];
    }

    // Fetch settings for daily target
    static $cachedDailyTargetMins = null;
    if ($cachedDailyTargetMins === null) {
        $s = $pdo->query("SELECT daily_target_hours FROM attendance_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $cachedDailyTargetMins = (int)round(((float)($s['daily_target_hours'] ?? 7.00)) * 60);
    }
    $dailyTargetMins = $isNonWorking ? 0 : $cachedDailyTargetMins;
    $workedMinutes = (int)floor($netWorkSec / 60);
    $diffMinutes = $workedMinutes - $dailyTargetMins;

    // Check Approved Leave (Priority 2)
    $leaveStmt = $pdo->prepare("SELECT lr.*, lt.name as leave_type_name 
                                FROM leave_requests lr 
                                LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id 
                                WHERE lr.employee_id = ? 
                                  AND ? BETWEEN lr.start_date AND lr.end_date 
                                  AND lr.status = 'approved' 
                                LIMIT 1");
    $leaveStmt->execute([$employeeId, $date]);
    $approvedLeave = $leaveStmt->fetch(PDO::FETCH_ASSOC);

    // RESOLUTION HIERARCHY
    $status = 'present';
    $statusRaw = 'present';
    $indicator = 'P';
    $holidayReason = null;
    $notes = $attRow['notes'] ?? '';

    // Priority 1: Holiday / Sunday (Sundays and Official Holidays are strictly OFF / Holiday)
    if ($isNonWorking) {
        $status = $isSunday ? 'Sunday Weekly Off' : ($dayCheck['holiday_name'] ?: 'Holiday');
        $statusRaw = 'holiday';
        $indicator = 'H';
        $holidayReason = $dayCheck['reason'] ?: ($isSunday ? 'Sunday Weekly Off' : 'Holiday');
        if (empty($notes)) {
            $notes = $holidayReason;
        }
    }
    // Priority 2: Admin Marked Absent
    elseif ($attRow && strtolower($attRow['status'] ?? '') === 'absent') {
        $status = 'Absent';
        $statusRaw = 'absent';
        $indicator = 'A';
        $totalHours = 0.00;
        $netWorkSec = 0;
        $workedMinutes = 0;
        $checkInTime = null;
        $checkOutTime = null;
        if (empty($notes)) {
            $notes = 'Marked Absent';
        }
    }
    // Priority 3: Approved Leave or Admin Marked On Leave
    elseif (!empty($approvedLeave) || ($attRow && strtolower($attRow['status'] ?? '') === 'on_leave')) {
        $status = 'On Leave';
        $statusRaw = 'on_leave';
        $indicator = 'LV';
        $totalHours = 0.00;
        $netWorkSec = 0;
        $workedMinutes = 0;
        $checkInTime = null;
        $checkOutTime = null;
        if (empty($notes)) {
            $notes = !empty($approvedLeave['leave_type_name']) ? $approvedLeave['leave_type_name'] : 'On Leave';
        }
    }
    // Priority 4: Working day with punch
    elseif ($hasPunch) {
        $dbStatus = strtolower($attRow['status'] ?? 'present');
        $lateMins = (int)($attRow['late_minutes'] ?? 0);

        if ($dbStatus === 'half_day') {
            $status = 'Half Day';
            $statusRaw = 'half_day';
            $indicator = 'HD';
        } elseif ($dbStatus === 'late' || $lateMins > 0) {
            // If stayed full shift and compensated:
            if ($totalHours >= round($cachedDailyTargetMins / 60, 2)) {
                $status = 'Present';
                $statusRaw = 'present';
                $indicator = 'P';
            } else {
                $status = 'Late';
                $statusRaw = 'late';
                $indicator = 'L';
            }
        } else {
            $status = 'Present';
            $statusRaw = 'present';
            $indicator = 'P';
        }
    }
    // Priority 4: Working day without punch
    else {
        $todayStr = date('Y-m-d');
        if ($date < $todayStr) {
            $status = 'Absent';
            $statusRaw = 'absent';
            $indicator = 'A';
        } elseif ($date === $todayStr) {
            $status = 'Not Marked';
            $statusRaw = 'pending';
            $indicator = '—';
        } else {
            $status = 'Upcoming';
            $statusRaw = 'upcoming';
            $indicator = '—';
        }
    }

    return [
        'date'                  => $date,
        'day'                   => date('D', $dateTs),
        'day_name'              => date('l', $dateTs),
        'formatted_date'        => date('d M Y', $dateTs),
        'is_sunday'             => $isSunday,
        'is_holiday'            => $isHoliday,
        'is_non_working'        => $isNonWorking,
        'holiday_name'          => $isNonWorking ? ($dayCheck['holiday_name'] ?? ($isSunday ? 'Sunday Weekly Off' : null)) : null,
        'holiday_type'          => $isNonWorking ? ($dayCheck['holiday_type'] ?? ($isSunday ? 'weekly_off' : null)) : null,
        'holiday_reason'        => $isNonWorking ? $holidayReason : null,
        'status'                => $status,
        'status_raw'            => $statusRaw,
        'status_label'          => $status,
        'indicator'             => $indicator,
        'indicator_badge'       => $indicator,
        'has_record'            => !empty($attRow),
        'record_id'             => ($attRow && isset($attRow['id'])) ? (int)$attRow['id'] : null,
        'check_in'              => $checkInTime,
        'check_out'             => $checkOutTime,
        'check_in_formatted'    => $checkInTime ? date('h:i A', strtotime($checkInTime)) : '—',
        'check_out_formatted'   => $checkOutTime ? date('h:i A', strtotime($checkOutTime)) : ($hasPunch && $date === date('Y-m-d') ? 'In Progress' : '—'),
        'lunch_start'           => $lunchStart,
        'lunch_end'             => $lunchEnd,
        'lunch_seconds'         => $lunchSec,
        'lunch_duration'        => format_minutes_hm((int)round($lunchSec / 60)),
        'lunch_duration_formatted' => format_minutes_hm((int)round($lunchSec / 60)),
        'is_on_lunch'           => $isOnLunch,
        'total_hours'           => $totalHours,
        'hours_formatted'       => $totalHours > 0 ? sprintf('%02dh %02dm', floor($totalHours), round(($totalHours - floor($totalHours)) * 60)) : '—',
        'net_work_hours'        => $totalHours,
        'net_work_duration'     => $totalHours > 0 ? sprintf('%02dh %02dm', floor($totalHours), round(($totalHours - floor($totalHours)) * 60)) : '—',
        'net_work_seconds'      => $netWorkSec,
        'late_minutes'          => $attRow ? (int)($attRow['late_minutes'] ?? 0) : 0,
        'late_formatted'        => ($attRow && (int)($attRow['late_minutes'] ?? 0) > 0) ? format_minutes_hm((int)$attRow['late_minutes']) : '—',
        'target_minutes'        => $dailyTargetMins,
        'difference_minutes'    => $diffMinutes,
        'difference_formatted'  => format_minute_difference($diffMinutes),
        'leave_status'          => !empty($approvedLeave) ? 'Approved Leave' : null,
        'notes'                 => $notes,
        'remarks'               => $notes
    ];
}

/**
 * Calculate full monthly attendance summary for an employee,
 * exactly matching the A & R (Attendance & Reports) engine.
 *
 * @param PDO $pdo
 * @param int $employeeId
 * @param int $year
 * @param int $month
 * @param array|null $holidaysMap
 * @param array|null $overridesMap
 * @return array
 */
function get_employee_month_attendance_summary(
    PDO $pdo,
    int $employeeId,
    int $year,
    int $month,
    ?array $holidaysMap = null,
    ?array $overridesMap = null
): array {
    if ($month < 1 || $month > 12) $month = (int)date('n');
    if ($year < 2020 || $year > 2035) $year = (int)date('Y');

    if ($holidaysMap === null) {
        $holidaysMap = get_active_holidays_map($pdo, $year);
    }
    if ($overridesMap === null) {
        $overridesMap = get_holiday_overrides_map($pdo, $year);
    }

    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $monthStart  = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd    = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
    $today       = date('Y-m-d');

    // Fetch employee details
    static $empCache = [];
    if (!isset($empCache[$employeeId])) {
        $st = $pdo->prepare("SELECT id, full_name, employee_code, designation, department, base_salary, joining_date FROM employees WHERE id = ?");
        $st->execute([$employeeId]);
        $empCache[$employeeId] = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    $emp = $empCache[$employeeId];
    $joiningDate = !empty($emp['joining_date']) ? $emp['joining_date'] : '2000-01-01';

    // Fetch attendance rows
    $attStmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date ASC");
    $attStmt->execute([$employeeId, $monthStart, $monthEnd]);
    $attRows = $attStmt->fetchAll(PDO::FETCH_ASSOC);
    $attByDate = [];
    foreach ($attRows as $row) {
        $attByDate[$row['attendance_date']] = $row;
    }

    // Fetch breaks / lunch sessions
    $brkStmt = $pdo->prepare("SELECT * FROM lunch_sessions WHERE employee_id = ? AND session_date BETWEEN ? AND ? ORDER BY session_date ASC, id ASC");
    $brkStmt->execute([$employeeId, $monthStart, $monthEnd]);
    $brkRows = $brkStmt->fetchAll(PDO::FETCH_ASSOC);
    $breaksByDate = [];
    $totalBreakSeconds = 0;
    foreach ($brkRows as $br) {
        $bDate = $br['session_date'];
        if (!isset($breaksByDate[$bDate])) {
            $breaksByDate[$bDate] = [
                'sessions' => [],
                'total_seconds' => 0,
                'has_active' => false,
            ];
        }
        $durSec = (int)($br['duration_seconds'] ?? 0);
        if ($br['status'] === 'active') {
            $breaksByDate[$bDate]['has_active'] = true;
            if (!empty($br['start_time'])) {
                $startTs = strtotime($br['session_date'] . ' ' . $br['start_time']);
                $durSec = max(0, time() - $startTs);
            }
        }
        $breaksByDate[$bDate]['total_seconds'] += $durSec;
        $breaksByDate[$bDate]['sessions'][] = $br;
        $totalBreakSeconds += $durSec;
    }

    $totalWorkingDaysInMonth = 0;
    $totalHolidaysInMonth = 0;
    $presentDays = 0;
    $absentDays = 0;
    $halfDays = 0;
    $lateDays = 0;
    $leaveDays = 0;
    $totalLateMinutes = 0;
    $totalWorkHours = 0.0;
    $dailyRows = [];

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $dTs  = strtotime($dStr);
        $isPastOrToday = ($dStr <= $today);
        $isJoined = ($dStr >= $joiningDate);

        $dayCheck = is_date_holiday_or_sunday($dStr, $holidaysMap, $overridesMap);
        $isNonWorking = (bool)$dayCheck['is_non_working'];

        if ($isNonWorking) {
            $totalHolidaysInMonth++;
        } else {
            $totalWorkingDaysInMonth++;
        }

        $attRow = $attByDate[$dStr] ?? null;
        $res = resolve_daily_attendance_status($pdo, $employeeId, $dStr, $attRow, $holidaysMap);

        $statusRaw = $res['status_raw'];

        if ($isJoined) {
            if ($isNonWorking) {
                // Strictly Sunday Weekly Off or Holiday - never count towards present or absent
            } elseif ($statusRaw === 'present') {
                $presentDays++;
            } elseif ($statusRaw === 'late') {
                $presentDays++;
                $lateDays++;
            } elseif ($statusRaw === 'half_day') {
                $halfDays++;
                $presentDays += 0.5;
            } elseif ($statusRaw === 'absent') {
                if ($isPastOrToday) {
                    $absentDays++;
                }
            } elseif ($statusRaw === 'on_leave') {
                $leaveDays++;
            }
        }

        if ($attRow && !empty($attRow['late_minutes'])) {
            $totalLateMinutes += (int)$attRow['late_minutes'];
        }
        if (!empty($res['total_hours']) && !in_array($statusRaw, ['absent', 'on_leave'])) {
            $totalWorkHours += (float)$res['total_hours'];
        }

        $brkInfo = $breaksByDate[$dStr] ?? null;
        $breakSec = $brkInfo ? (int)$brkInfo['total_seconds'] : 0;
        $breakMins = (int)round($breakSec / 60);

        $breakDetailStr = '—';
        $breakDurationStr = '—';
        $breakTimeStr = '';
        if ($brkInfo && ($breakSec > 0 || $brkInfo['has_active'])) {
            $breakDurationStr = format_minutes_hms($breakMins, true);
            $timeParts = [];
            foreach ($brkInfo['sessions'] as $s) {
                $st = !empty($s['start_time']) ? date('h:i A', strtotime($s['start_time'])) : '';
                $et = !empty($s['end_time']) ? date('h:i A', strtotime($s['end_time'])) : ($s['status'] === 'active' ? 'Active' : '');
                if ($st) $timeParts[] = "$st" . ($et ? " - $et" : "");
            }
            if (!empty($timeParts)) {
                $breakTimeStr = implode(', ', $timeParts);
                $breakDetailStr = $breakDurationStr . ' (' . $breakTimeStr . ')';
            } else {
                $breakDetailStr = $breakDurationStr;
            }
        }

        $dailyRows[$dStr] = [
            'date'                  => $dStr,
            'day_num'               => $d,
            'day'                   => date('D', $dTs),
            'day_name'              => date('l', $dTs),
            'formatted_date'        => date('d M Y', $dTs),
            'is_today'              => ($dStr === $today),
            'is_future'             => ($dStr > $today),
            'is_joined'             => $isJoined,
            'is_non_working'        => $isNonWorking,
            'day_check'             => $dayCheck,
            'attendance'            => $attRow,
            'resolution'            => $res,
            'status'                => $res['status'],
            'status_raw'            => $res['status_raw'],
            'indicator'             => $res['indicator'],
            'check_in'              => $res['check_in'],
            'check_out'             => $res['check_out'],
            'check_in_formatted'    => $res['check_in_formatted'],
            'check_out_formatted'   => $res['check_out_formatted'],
            'break_seconds'         => $breakSec,
            'break_minutes'         => $breakMins,
            'break_duration'        => $breakDurationStr,
            'break_time'            => $breakTimeStr,
            'break_details'         => $breakDetailStr,
            'break_info'            => $brkInfo,
            'total_hours'           => $res['total_hours'],
            'work_hours_formatted'  => $res['hours_formatted'],
            'late_minutes'          => (int)($attRow['late_minutes'] ?? 0),
            'late_duration'         => ((int)($attRow['late_minutes'] ?? 0) > 0) ? format_minutes_hms((int)$attRow['late_minutes'], true) : '—',
            'remarks'               => $res['notes'] ?: ($attRow['notes'] ?? ''),
            'notes'                 => $res['notes'] ?: ($attRow['notes'] ?? ''),
        ];
    }

    return [
        'emp'                     => $emp,
        'year'                    => $year,
        'month'                   => $month,
        'days_in_month'           => $daysInMonth,
        'total_working_days'      => $totalWorkingDaysInMonth,
        'total_holidays'          => $totalHolidaysInMonth,
        'present'                 => $presentDays,
        'present_days'            => $presentDays,
        'absent'                  => $absentDays,
        'absent_days'             => $absentDays,
        'half_day'                => $halfDays,
        'half_days'               => $halfDays,
        'late_days'               => $lateDays,
        'on_leave'                => $leaveDays,
        'leave_days'              => $leaveDays,
        'total_late_mins'         => $totalLateMinutes,
        'total_late_hrs'          => round($totalLateMinutes / 60, 2),
        'total_hours'             => round($totalWorkHours, 2),
        'total_work_hours'        => round($totalWorkHours, 2),
        'total_break_sec'         => $totalBreakSeconds,
        'total_break_mins'        => (int)round($totalBreakSeconds / 60),
        'total_break_hrs'         => round($totalBreakSeconds / 3600, 2),
        'att_by_date'             => $attByDate,
        'breaks_by_date'          => $breaksByDate,
        'daily_rows'              => $dailyRows,
    ];
}

/**
 * Auto-mark expired / unresumed breaks as 1:30 hours (5400 seconds).
 * Rule: When a user takes a break and does not resume it, 
 * after 1 hour 30 mins (5400 seconds) from start_time,
 * the break is automatically completed with 1:30 hours duration,
 * and this time is excluded from working hours/minutes.
 */
function auto_close_expired_lunch_sessions(PDO $pdo, ?int $employeeId = null, ?string $date = null): int {
    static $lastRun = 0;
    $nowTs = time();

    $isSpecific = ($employeeId !== null && $employeeId > 0 && !empty($date));
    if (!$isSpecific && ($nowTs - $lastRun < 5)) {
        return 0;
    }
    if (!$isSpecific) {
        $lastRun = $nowTs;
    }

    try {
        $params = [];
        $where = "status = 'active'";
        if ($isSpecific) {
            $where .= " AND employee_id = ? AND session_date = ?";
            $params[] = $employeeId;
            $params[] = $date;
        }

        $stmt = $pdo->prepare("SELECT id, employee_id, session_date, start_time FROM lunch_sessions WHERE {$where}");
        $stmt->execute($params);
        $activeSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($activeSessions)) {
            return 0;
        }

        $closedCount = 0;
        $updStmt = $pdo->prepare("
            UPDATE lunch_sessions 
            SET end_time = ?, duration_seconds = ?, status = 'completed', updated_at = NOW() 
            WHERE id = ?
        ");

        foreach ($activeSessions as $s) {
            $sessionDate = $s['session_date'];
            $startTime   = $s['start_time'];
            $startTs     = strtotime($sessionDate . ' ' . $startTime);

            // If session is from a past date or 5400 seconds (1 hour 30 mins) have elapsed
            if ($sessionDate < date('Y-m-d') || ($nowTs - $startTs) >= 5400) {
                $autoEndTs = $startTs + 5400; // start_time + 1:30:00
                $autoEndTime = date('H:i:s', $autoEndTs);
                $updStmt->execute([$autoEndTime, 5400, $s['id']]);
                $closedCount++;

                // Notify employee via in-app & mobile push
                try {
                    $empUserId = get_user_id_by_employee_id($pdo, (int)$s['employee_id']);
                    if ($empUserId) {
                        create_action_notification(
                            $pdo,
                            (int)$empUserId,
                            'Lunch Break Overdue Notice',
                            'Your lunch break exceeded the 1 hour 30 minutes limit and was automatically ended because work was not resumed in time.',
                            '/employee/attendance.php',
                            'lunch_resume_missed',
                            ['session_id' => $s['id'], 'date' => $sessionDate]
                        );
                    }
                } catch (\Throwable $nt) {}
            }
        }

        return $closedCount;
    } catch (\Throwable $t) {
        error_log("auto_close_expired_lunch_sessions error: " . $t->getMessage());
        return 0;
    }
}

/**
 * Fetch all lunch sessions for an employee on a date
 */
function get_today_lunch_sessions(PDO $pdo, int $employeeId, string $date): array {
    auto_close_expired_lunch_sessions($pdo, $employeeId, $date);
    $stmt = $pdo->prepare("SELECT * FROM lunch_sessions WHERE employee_id = ? AND session_date = ? ORDER BY id ASC");
    $stmt->execute([$employeeId, $date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch currently active lunch session
 */
function get_active_lunch_session(PDO $pdo, int $employeeId, string $date): ?array {
    auto_close_expired_lunch_sessions($pdo, $employeeId, $date);
    $stmt = $pdo->prepare("SELECT * FROM lunch_sessions WHERE employee_id = ? AND session_date = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$employeeId, $date]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ?: null;
}

/**
 * Calculate total lunch duration in seconds for an employee on a date (including live elapsed if active, capped at 1:30h)
 */
function get_today_lunch_duration_seconds(PDO $pdo, int $employeeId, string $date): int {
    auto_close_expired_lunch_sessions($pdo, $employeeId, $date);
    $stmt = $pdo->prepare("SELECT * FROM lunch_sessions WHERE employee_id = ? AND session_date = ? ORDER BY id ASC");
    $stmt->execute([$employeeId, $date]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalSec = 0;
    $nowSec = time();

    foreach ($sessions as $s) {
        if ($s['status'] === 'completed') {
            $totalSec += (int)($s['duration_seconds'] ?? 0);
        } elseif ($s['status'] === 'active' && !empty($s['start_time'])) {
            $startSec = strtotime($s['session_date'] . ' ' . $s['start_time']);
            if ($nowSec > $startSec) {
                // If unresumed, active break duration caps at 1:30 hours (5400 seconds)
                $elapsed = ($nowSec - $startSec);
                $totalSec += min(5400, $elapsed);
            }
        }
    }
    return $totalSec;
}

/**
 * Calculate actual work report duration from real attendance & lunch records
 */
function calculate_work_report_duration(PDO $pdo, int $employeeId, string $reportDate): array {
    $stmt = $pdo->prepare("SELECT check_in, check_out, total_hours FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
    $stmt->execute([$employeeId, $reportDate]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC);

    $lunchSec  = get_today_lunch_duration_seconds($pdo, $employeeId, $reportDate);
    $lunchMins = (int)round($lunchSec / 60);

    if (!$att || empty($att['check_in'])) {
        return [
            'hours'              => 0.00,
            'minutes'            => 0,
            'duration_formatted' => '0h 00m',
            'is_clocked_in'      => false,
            'is_clocked_out'     => false,
            'lunch_seconds'      => $lunchSec,
            'lunch_minutes'      => $lunchMins,
            'total_work_seconds' => 0
        ];
    }

    $isClockedIn  = true;
    $isClockedOut = (!empty($att['check_out']) && $att['check_out'] !== '00:00:00');

    if ($isClockedOut) {
        $rawSec = calc_time_diff_seconds($att['check_in'], $att['check_out']);
    } elseif ($reportDate === date('Y-m-d')) {
        $rawSec = calc_time_diff_seconds($att['check_in'], date('H:i:s'));
    } else {
        $rawSec = 0;
    }

    $netSec = max(0, $rawSec - $lunchSec);
    $hours  = round($netSec / 3600, 2);
    $mins   = (int)floor($netSec / 60);

    return [
        'hours'              => $hours,
        'minutes'            => $mins,
        'duration_formatted' => format_minutes_hm($mins),
        'is_clocked_in'      => $isClockedIn,
        'is_clocked_out'     => $isClockedOut,
        'lunch_seconds'      => $lunchSec,
        'lunch_minutes'      => $lunchMins,
        'total_work_seconds' => $netSec
    ];
}

/**
 * Get annual leave allowance & usage summary (4 leaves per year)
 */
function get_employee_annual_leave_summary(PDO $pdo, int $employeeId, ?int $year = null): array {
    $year = $year ?: (int)date('Y');

    // Fetch allowed leaves quota from attendance_settings
    $sStmt = $pdo->query("SELECT annual_allowed_leaves FROM attendance_settings LIMIT 1");
    $allowedQuota = 4;
    if ($sStmt) {
        $val = $sStmt->fetchColumn();
        if ($val !== false && is_numeric($val)) {
            $allowedQuota = (int)$val;
        }
    }

    // Fetch approved leave days from leave_requests for this year
    $lStmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_days), 0) 
        FROM leave_requests 
        WHERE employee_id = ? AND status = 'approved' AND YEAR(start_date) = ?
    ");
    $lStmt->execute([$employeeId, $year]);
    $approvedDays = (int)$lStmt->fetchColumn();

    // Also count attendance records where status = 'on_leave' in this year if not covered in leave_requests
    $attLeaveStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM attendance 
        WHERE employee_id = ? AND status = 'on_leave' AND YEAR(attendance_date) = ?
    ");
    $attLeaveStmt->execute([$employeeId, $year]);
    $attLeaveCount = (int)$attLeaveStmt->fetchColumn();
    $totalLeavesTaken = max($approvedDays, $attLeaveCount);

    $usedAllowed = min($totalLeavesTaken, $allowedQuota);
    $remainingAllowed = max(0, $allowedQuota - $totalLeavesTaken);
    $extraLeaves = max(0, $totalLeavesTaken - $allowedQuota);

    return [
        'year'              => $year,
        'allowed_leaves'    => $allowedQuota,
        'used_allowed'      => $usedAllowed,
        'remaining_allowed' => $remainingAllowed,
        'extra_leaves'      => $extraLeaves,
        'total_taken'       => $totalLeavesTaken
    ];
}

/**
 * Calculate comprehensive working-hours metrics for Daily (7h), Weekly, Monthly, Yearly
 */
function calculate_working_hours_summary(PDO $pdo, int $employeeId, ?string $targetDate = null): array {
    $date = $targetDate ?: date('Y-m-d');
    $year = (int)date('Y', strtotime($date));
    $month = (int)date('m', strtotime($date));

    // 1. Settings
    $s = $pdo->query("SELECT * FROM attendance_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $dailyTargetHours = (float)($s['daily_target_hours'] ?? 7.00);
    $dailyTargetMinutes = (int)round($dailyTargetHours * 60); // 420 minutes

    // 2. Active Holidays
    $holidaysMap = get_active_holidays_map($pdo, $year);

    // 3. DAILY METRICS
    $dayCheck = is_date_holiday_or_sunday($date, $holidaysMap);
    $dayTargetMins = $dayCheck['is_non_working'] ? 0 : $dailyTargetMinutes;

    // Fetch attendance on target date
    $aStmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
    $aStmt->execute([$employeeId, $date]);
    $att = $aStmt->fetch(PDO::FETCH_ASSOC);

    $lunchSessions = get_today_lunch_sessions($pdo, $employeeId, $date);
    $activeLunch = get_active_lunch_session($pdo, $employeeId, $date);
    $isOnLunch = !empty($activeLunch);
    $todayLunchSec = get_today_lunch_duration_seconds($pdo, $employeeId, $date);

    $dailyWorkedSec = 0;
    if ($att && in_array(strtolower($att['status'] ?? ''), ['absent', 'on_leave'])) {
        $dailyWorkedSec = 0;
    } elseif ($att && !empty($att['check_in'])) {
        if (!empty($att['check_out']) && $att['check_out'] !== '00:00:00') {
            $rawSec = calc_time_diff_seconds($att['check_in'], $att['check_out']);
        } elseif ($date === date('Y-m-d')) {
            $rawSec = calc_time_diff_seconds($att['check_in'], date('H:i:s'));
        } else {
            $rawSec = 0;
        }
        $dailyWorkedSec = max(0, $rawSec - $todayLunchSec);
    }
    $dailyWorkedMins = (int)floor($dailyWorkedSec / 60);
    $dailyDiffMins = $dailyWorkedMins - $dayTargetMins;
    $dailyExtraMins = max(0, $dailyDiffMins);
    $dailyShortfallMins = max(0, -$dailyDiffMins);

    // 4. WEEKLY METRICS (Monday to Sunday)
    $ts = strtotime($date);
    $weekStart = date('Y-m-d', strtotime('monday this week', $ts));
    $weekEnd   = date('Y-m-d', strtotime('sunday this week', $ts));
    $weekWorkingDays = get_working_days_count($weekStart, $weekEnd, $holidaysMap);
    $weekTargetMins = $weekWorkingDays * $dailyTargetMinutes;

    $wStmt = $pdo->prepare("
        SELECT attendance_date, check_in, check_out, total_hours, status 
        FROM attendance 
        WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
    ");
    $wStmt->execute([$employeeId, $weekStart, $weekEnd]);
    $weekRows = $wStmt->fetchAll(PDO::FETCH_ASSOC);

    $weekWorkedSec = 0;
    $weekLeaveDays = 0;
    $weekAttendedDays = 0;
    foreach ($weekRows as $wr) {
        if ($wr['status'] === 'on_leave') $weekLeaveDays++;
        if (in_array($wr['status'], ['present', 'late', 'half_day']) || (!empty($wr['check_in']) && !in_array($wr['status'], ['absent', 'on_leave']))) {
            $weekAttendedDays++;
        }
        if (in_array($wr['status'], ['absent', 'on_leave'])) {
            continue; // Absent/On-leave days never count towards working hours
        }
        if ($wr['attendance_date'] === $date) {
            $weekWorkedSec += $dailyWorkedSec;
        } elseif (!empty($wr['check_in']) && !empty($wr['check_out'])) {
            $lSec = get_today_lunch_duration_seconds($pdo, $employeeId, $wr['attendance_date']);
            $rSec = calc_time_diff_seconds($wr['check_in'], $wr['check_out']);
            $weekWorkedSec += max(0, $rSec - $lSec);
        }
    }
    $weekWorkedMins = (int)floor($weekWorkedSec / 60);
    $weekDiffMins = $weekWorkedMins - $weekTargetMins;
    $weekExtraMins = max(0, $weekDiffMins);
    $weekShortfallMins = max(0, -$weekDiffMins);

    // 5. MONTHLY METRICS
    $monthStart = date('Y-m-01', $ts);
    $monthEnd   = date('Y-m-t', $ts);
    $monthWorkingDays = get_working_days_count($monthStart, $monthEnd, $holidaysMap);
    $monthTargetMins = $monthWorkingDays * $dailyTargetMinutes;

    $mStmt = $pdo->prepare("
        SELECT attendance_date, check_in, check_out, total_hours, status 
        FROM attendance 
        WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
    ");
    $mStmt->execute([$employeeId, $monthStart, $monthEnd]);
    $monthRows = $mStmt->fetchAll(PDO::FETCH_ASSOC);

    $monthWorkedSec = 0;
    $monthLeaveDays = 0;
    $monthAttendedDays = 0;
    foreach ($monthRows as $mr) {
        if ($mr['status'] === 'on_leave') $monthLeaveDays++;
        if (in_array($mr['status'], ['present', 'late', 'half_day']) || (!empty($mr['check_in']) && !in_array($mr['status'], ['absent', 'on_leave']))) {
            $monthAttendedDays++;
        }
        if (in_array($mr['status'], ['absent', 'on_leave'])) {
            continue; // Absent/On-leave days never count towards working hours
        }
        if ($mr['attendance_date'] === $date) {
            $monthWorkedSec += $dailyWorkedSec;
        } elseif (!empty($mr['check_in']) && !empty($mr['check_out'])) {
            $lSec = get_today_lunch_duration_seconds($pdo, $employeeId, $mr['attendance_date']);
            $rSec = calc_time_diff_seconds($mr['check_in'], $mr['check_out']);
            $monthWorkedSec += max(0, $rSec - $lSec);
        }
    }
    $monthWorkedMins = (int)floor($monthWorkedSec / 60);
    $monthDiffMins = $monthWorkedMins - $monthTargetMins;
    $monthExtraMins = max(0, $monthDiffMins);
    $monthShortfallMins = max(0, -$monthDiffMins);

    // 6. YEARLY METRICS
    $yearStart = "$year-01-01";
    $yearEnd   = "$year-12-31";
    $yearWorkingDays = get_working_days_count($yearStart, $yearEnd, $holidaysMap);
    $yearTargetMins = $yearWorkingDays * $dailyTargetMinutes;

    $yStmt = $pdo->prepare("
        SELECT attendance_date, check_in, check_out, total_hours, status 
        FROM attendance 
        WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
    ");
    $yStmt->execute([$employeeId, $yearStart, $yearEnd]);
    $yearRows = $yStmt->fetchAll(PDO::FETCH_ASSOC);

    $yearWorkedSec = 0;
    $yearLeaveDays = 0;
    $yearAttendedDays = 0;
    foreach ($yearRows as $yr) {
        if ($yr['status'] === 'on_leave') $yearLeaveDays++;
        if (in_array($yr['status'], ['present', 'late', 'half_day']) || (!empty($yr['check_in']) && !in_array($yr['status'], ['absent', 'on_leave']))) {
            $yearAttendedDays++;
        }
        if (in_array($yr['status'], ['absent', 'on_leave'])) {
            continue; // Absent/On-leave days never count towards working hours
        }
        if ($yr['attendance_date'] === $date) {
            $yearWorkedSec += $dailyWorkedSec;
        } elseif (!empty($yr['check_in']) && !empty($yr['check_out'])) {
            $lSec = get_today_lunch_duration_seconds($pdo, $employeeId, $yr['attendance_date']);
            $rSec = calc_time_diff_seconds($yr['check_in'], $yr['check_out']);
            $yearWorkedSec += max(0, $rSec - $lSec);
        }
    }
    $yearWorkedMins = (int)floor($yearWorkedSec / 60);
    $yearDiffMins = $yearWorkedMins - $yearTargetMins;
    $yearExtraMins = max(0, $yearDiffMins);
    $yearShortfallMins = max(0, -$yearDiffMins);

    // Leave Summary (4 leaves allowance)
    $leaveSummary = get_employee_annual_leave_summary($pdo, $employeeId, $year);

    return [
        'date'                  => $date,
        'is_sunday'             => $dayCheck['is_sunday'],
        'is_holiday'            => $dayCheck['is_holiday'],
        'is_sunday_or_holiday'  => (bool)$dayCheck['is_non_working'],
        'holiday_reason'        => $dayCheck['reason'],
        'day_type'              => $dayCheck['reason'] ?: ($dayCheck['is_sunday'] ? 'Sunday (Weekly Off)' : ($dayCheck['is_holiday'] ? 'Festival Holiday' : 'Working Day')),
        'is_on_lunch'           => $isOnLunch,
        'is_on_break'           => $isOnLunch,
        'lunch_start'           => (!empty($lunchSessions) ? date('h:i A', strtotime(end($lunchSessions)['start_time'])) : null),
        'lunch_end'             => (!empty($lunchSessions) && !empty(end($lunchSessions)['end_time']) ? date('h:i A', strtotime(end($lunchSessions)['end_time'])) : ($isOnLunch ? 'In Progress' : null)),
        'lunch_seconds'         => $todayLunchSec,
        'lunch_formatted'       => format_minutes_hm((int)round($todayLunchSec / 60)),
        'break_seconds'         => $todayLunchSec,
        'break_formatted'       => format_minutes_hm((int)round($todayLunchSec / 60)),
        'lunch_sessions'        => $lunchSessions,
        'active_lunch'          => $activeLunch,
        'daily' => [
            'target_minutes'      => $dayTargetMins,
            'target_formatted'    => format_minutes_hm($dayTargetMins),
            'worked_seconds'      => $dailyWorkedSec,
            'worked_minutes'      => $dailyWorkedMins,
            'worked_formatted'    => format_minutes_hm($dailyWorkedMins),
            'diff_minutes'        => $dailyDiffMins,
            'diff_formatted'      => format_minute_difference($dailyDiffMins),
            'difference_minutes'  => $dailyDiffMins,
            'difference_formatted'=> format_minute_difference($dailyDiffMins),
            'extra_minutes'       => $dailyExtraMins,
            'extra_formatted'     => format_minute_difference($dailyExtraMins),
            'shortfall_minutes'   => $dailyShortfallMins,
            'shortfall_formatted' => $dailyShortfallMins > 0 ? ("-" . format_minutes_hm($dailyShortfallMins)) : "0m",
            'is_sunday_or_holiday'=> (bool)$dayCheck['is_non_working'],
            'is_sunday'           => (bool)$dayCheck['is_sunday'],
            'is_holiday'          => (bool)$dayCheck['is_holiday'],
            'day_type'            => $dayCheck['reason'] ?: ($dayCheck['is_sunday'] ? 'Sunday (Weekly Off)' : ($dayCheck['is_holiday'] ? 'Festival Holiday' : 'Working Day')),
            'status'              => $dailyDiffMins >= 0 ? 'achieved' : 'shortfall'
        ],
        'weekly' => [
            'working_days'        => $weekWorkingDays,
            'attended_days'       => $weekAttendedDays,
            'days_formatted'      => "{$weekAttendedDays} / {$weekWorkingDays}",
            'target_minutes'      => $weekTargetMins,
            'target_formatted'    => format_minutes_hm($weekTargetMins),
            'worked_minutes'      => $weekWorkedMins,
            'worked_formatted'    => format_minutes_hm($weekWorkedMins),
            'diff_minutes'        => $weekDiffMins,
            'diff_formatted'      => format_minute_difference($weekDiffMins),
            'difference_minutes'  => $weekDiffMins,
            'difference_formatted'=> format_minute_difference($weekDiffMins),
            'extra_minutes'       => $weekExtraMins,
            'extra_formatted'     => format_minute_difference($weekExtraMins),
            'shortfall_minutes'   => $weekShortfallMins,
            'shortfall_formatted' => $weekShortfallMins > 0 ? ("-" . format_minutes_hm($weekShortfallMins)) : "0m",
            'leave_days'          => $weekLeaveDays,
            'status'              => $weekDiffMins >= 0 ? 'achieved' : 'shortfall'
        ],
        'monthly' => [
            'working_days'        => $monthWorkingDays,
            'attended_days'       => $monthAttendedDays,
            'days_formatted'      => "{$monthAttendedDays} / {$monthWorkingDays}",
            'target_minutes'      => $monthTargetMins,
            'target_formatted'    => format_minutes_hm($monthTargetMins),
            'worked_minutes'      => $monthWorkedMins,
            'worked_formatted'    => format_minutes_hm($monthWorkedMins),
            'diff_minutes'        => $monthDiffMins,
            'diff_formatted'      => format_minute_difference($monthDiffMins),
            'difference_minutes'  => $monthDiffMins,
            'difference_formatted'=> format_minute_difference($monthDiffMins),
            'extra_minutes'       => $monthExtraMins,
            'extra_formatted'     => format_minute_difference($monthExtraMins),
            'shortfall_minutes'   => $monthShortfallMins,
            'shortfall_formatted' => $monthShortfallMins > 0 ? ("-" . format_minutes_hm($monthShortfallMins)) : "0m",
            'leave_days'          => $monthLeaveDays,
            'status'              => $monthDiffMins >= 0 ? 'achieved' : 'shortfall'
        ],
        'yearly' => [
            'working_days'        => $yearWorkingDays,
            'attended_days'       => $yearAttendedDays,
            'days_formatted'      => "{$yearAttendedDays} / {$yearWorkingDays}",
            'target_minutes'      => $yearTargetMins,
            'target_formatted'    => format_minutes_hm($yearTargetMins),
            'worked_minutes'      => $yearWorkedMins,
            'worked_formatted'    => format_minutes_hm($yearWorkedMins),
            'diff_minutes'        => $yearDiffMins,
            'diff_formatted'      => format_minute_difference($yearDiffMins),
            'difference_minutes'  => $yearDiffMins,
            'difference_formatted'=> format_minute_difference($yearDiffMins),
            'extra_minutes'       => $yearExtraMins,
            'extra_formatted'     => format_minute_difference($yearExtraMins),
            'shortfall_minutes'   => $yearShortfallMins,
            'shortfall_formatted' => $yearShortfallMins > 0 ? ("-" . format_minutes_hm($yearShortfallMins)) : "0m",
            'leave_days'          => $yearLeaveDays,
            'status'              => $yearDiffMins >= 0 ? 'achieved' : 'shortfall'
        ],
        'leave_summary' => $leaveSummary
    ];
}

/**
 * Calculate invoice line item amounts according to tax mode (exclusive vs inclusive)
 * 
 * When Toggle is OFF (Exclusive):
 *   Taxable Base Amount = (Qty * Rate) - Discount
 *   Tax Amount = Taxable Base Amount * (Tax Rate / 100)
 *   Final Line Total = Taxable Base Amount + Tax Amount
 * 
 * When Toggle is ON (Inclusive):
 *   Entered Rate is the agreed tax-inclusive rate per unit.
 *   Net Inclusive Amount = (Qty * Rate) - Discount
 *   Taxable Base Amount = Net Inclusive Amount * 100 / (100 + Tax Rate)
 *   Tax Amount = Net Inclusive Amount - Taxable Base Amount
 *   Final Line Total = Taxable Base Amount + Tax Amount = Net Inclusive Amount
 * 
 * Rounding Rule:
 *   Tax Amount is derived as (Net Inclusive - Taxable Base), ensuring that
 *   Taxable Base + Tax Amount strictly equals Net Inclusive with zero rounding discrepancy.
 */
function calculate_invoice_line_item(float $qty, float $rate, float $taxRate, float $discount = 0.0, bool $isTaxInclusive = false): array {
    $qty = max(1, (int)round($qty));
    $rate = max(0.0, $rate);
    $taxRate = max(0.0, $taxRate);
    $discount = min(100.0, max(0.0, $discount));

    if ($isTaxInclusive) {
        $grossInclusive = round($qty * $rate, 2);
        $discAmount = round($grossInclusive * ($discount / 100), 2);
        $netInclusive = max(0.0, round($grossInclusive - $discAmount, 2));

        if ($taxRate > 0) {
            $taxableAmount = round(($netInclusive * 100.0) / (100.0 + $taxRate), 2);
            $taxAmount = round($netInclusive - $taxableAmount, 2);
        } else {
            $taxableAmount = $netInclusive;
            $taxAmount = 0.0;
        }

        $lineTotal = $taxableAmount; // Stored in invoice_items.line_total (taxable base)
        $finalAmount = $netInclusive; // Line total including tax
    } else {
        $grossBase = round($qty * $rate, 2);
        $discAmount = round($grossBase * ($discount / 100), 2);
        $netBase = max(0.0, round($grossBase - $discAmount, 2));

        $taxableAmount = $netBase;
        $taxAmount = round($taxableAmount * ($taxRate / 100.0), 2);
        $lineTotal = $taxableAmount;
        $finalAmount = round($taxableAmount + $taxAmount, 2);
    }

    return [
        'qty'               => $qty,
        'rate'              => $rate,
        'tax_rate'          => $taxRate,
        'discount'          => $discount,
        'discount_amount'   => $discAmount,
        'is_tax_inclusive'  => $isTaxInclusive ? 1 : 0,
        'taxable_amount'    => $taxableAmount,
        'tax_amount'        => $taxAmount,
        'line_tax'          => $taxAmount,
        'line_total'        => $lineTotal,
        'final_amount'      => $finalAmount,
    ];
}

/**
 * Calculate full invoice totals from an array of line items and invoice discount
 */
function calculate_invoice_totals(array $items, float $invoiceDiscount = 0.0): array {
    $calculatedItems = [];
    $totalTaxable = 0.0;
    $totalTax = 0.0;
    $totalFinal = 0.0;

    foreach ($items as $it) {
        $qty      = max(1, (int)($it['qty'] ?? 1));
        $rate     = (float)($it['rate'] ?? 0);
        $taxRate  = (float)($it['tax_rate'] ?? 0);
        $disc     = (float)($it['discount'] ?? 0);
        $isInc    = !empty($it['is_tax_inclusive']) || !empty($it['tax_included']);

        $res = calculate_invoice_line_item($qty, $rate, $taxRate, $disc, $isInc);
        $res['name'] = trim($it['name'] ?? '');
        $res['hsn_sac'] = trim($it['hsn_sac'] ?? ($it['hsn'] ?? ''));
        $res['handler_name'] = trim($it['handler_name'] ?? '');

        $totalTaxable += $res['taxable_amount'];
        $totalTax += $res['tax_amount'];
        $totalFinal += $res['final_amount'];

        $calculatedItems[] = $res;
    }

    $totalTaxable = round($totalTaxable, 2);
    $totalTax = round($totalTax, 2);
    $grandTotal = max(0.0, round(($totalTaxable + $totalTax) - $invoiceDiscount, 2));

    return [
        'items'         => $calculatedItems,
        'taxable_value' => $totalTaxable,
        'tax_total'     => $totalTax,
        'subtotal'      => $totalTaxable,
        'discount'      => round($invoiceDiscount, 2),
        'total_amount'  => $grandTotal,
    ];
}

/**
 * Master List of Indian States & Union Territories with GST State Codes
 */
function get_indian_gst_states(): array {
    return [
        '01' => 'Jammu and Kashmir',
        '02' => 'Himachal Pradesh',
        '03' => 'Punjab',
        '04' => 'Chandigarh',
        '05' => 'Uttarakhand',
        '06' => 'Haryana',
        '07' => 'Delhi',
        '08' => 'Rajasthan',
        '09' => 'Uttar Pradesh',
        '10' => 'Bihar',
        '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh',
        '13' => 'Nagaland',
        '14' => 'Manipur',
        '15' => 'Mizoram',
        '16' => 'Tripura',
        '17' => 'Meghalaya',
        '18' => 'Assam',
        '19' => 'West Bengal',
        '20' => 'Jharkhand',
        '21' => 'Odisha',
        '22' => 'Chhattisgarh',
        '23' => 'Madhya Pradesh',
        '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu',
        '27' => 'Maharashtra',
        '29' => 'Karnataka',
        '30' => 'Goa',
        '31' => 'Lakshadweep',
        '32' => 'Kerala',
        '33' => 'Tamil Nadu',
        '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands',
        '36' => 'Telangana',
        '37' => 'Andhra Pradesh',
        '38' => 'Ladakh',
        '97' => 'Other Territory',
    ];
}

/**
 * Normalize state name or code to a standardized 2-digit GST state code
 */
function normalize_gst_state_code(?string $stateOrCode): string {
    if (!$stateOrCode) return '';
    $val = trim($stateOrCode);
    if ($val === '') return '';

    $states = get_indian_gst_states();

    // Check if directly matches a 2-digit key
    $padCode = str_pad(ltrim($val, '0'), 2, '0', STR_PAD_LEFT);
    if (isset($states[$padCode])) {
        return $padCode;
    }
    if (isset($states[$val])) {
        return $val;
    }

    // Common abbreviations
    $clean = preg_replace('/[^a-z]/i', '', strtolower($val));
    if ($clean === 'uk' || $clean === 'ua' || $clean === 'uttaranchal' || $clean === 'uttarakhand') return '05';
    if ($clean === 'up' || $clean === 'uttarpradesh') return '09';
    if ($clean === 'dl' || $clean === 'delhi' || $clean === 'newdelhi') return '07';
    if ($clean === 'hr' || $clean === 'haryana') return '06';
    if ($clean === 'pb' || $clean === 'punjab') return '03';
    if ($clean === 'rj' || $clean === 'rajasthan') return '08';
    if ($clean === 'mh' || $clean === 'maharashtra') return '27';
    if ($clean === 'ka' || $clean === 'karnataka') return '29';
    if ($clean === 'mp' || $clean === 'madhyapradesh') return '23';
    if ($clean === 'gj' || $clean === 'gujarat') return '24';
    if ($clean === 'wb' || $clean === 'westbengal') return '19';
    if ($clean === 'br' || $clean === 'bihar') return '10';
    if ($clean === 'tn' || $clean === 'tamilnadu') return '33';
    if ($clean === 'ts' || $clean === 'telangana') return '36';
    if ($clean === 'ap' || $clean === 'andhrapradesh') return '37';
    if ($clean === 'kl' || $clean === 'kerala') return '32';
    if ($clean === 'ch' || $clean === 'chandigarh') return '04';
    if ($clean === 'hp' || $clean === 'himachalpradesh') return '02';
    if ($clean === 'jk' || $clean === 'jammukashmir' || $clean === 'jammuandkashmir') return '01';

    // Match full name
    foreach ($states as $code => $name) {
        $cleanName = preg_replace('/[^a-z]/i', '', strtolower($name));
        if ($clean === $cleanName) {
            return $code;
        }
    }

    return '';
}

/**
 * Get State Name from GST code or state name string
 */
function get_gst_state_name(?string $stateOrCode): string {
    $code = normalize_gst_state_code($stateOrCode);
    $states = get_indian_gst_states();
    return $states[$code] ?? (trim($stateOrCode ?? ''));
}

/**
 * Get Business GST Registration State Details from settings table
 */
function get_business_gst_state_details(): array {
    global $pdo;
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $stateCode = '05'; // Default Uttarakhand
    $stateName = 'Uttarakhand';
    $gstin = '';
    $bizAddress = '';

    try {
        if ($pdo) {
            $stmt = $pdo->query("SELECT gstin, address FROM settings LIMIT 1");
            $settings = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($settings) {
                $gstin = trim($settings['gstin'] ?? '');
                $bizAddress = trim($settings['address'] ?? '');
                if (strlen($gstin) >= 2) {
                    $codePrefix = substr($gstin, 0, 2);
                    $derivedCode = normalize_gst_state_code($codePrefix);
                    if ($derivedCode !== '') {
                        $stateCode = $derivedCode;
                        $stateName = get_gst_state_name($derivedCode);
                    }
                } elseif (!empty($bizAddress)) {
                    $derivedCode = normalize_gst_state_code($bizAddress);
                    if ($derivedCode !== '') {
                        $stateCode = $derivedCode;
                        $stateName = get_gst_state_name($derivedCode);
                    }
                }
            }
        }
    } catch (\Throwable $e) {}

    $cached = [
        'state_code' => $stateCode,
        'state_name' => $stateName,
        'gstin'      => $gstin,
        'address'    => $bizAddress,
    ];
    return $cached;
}

/**
 * Determine GST Type: 'INTRA' (CGST+SGST), 'INTER' (IGST), 'NONE', or 'UNKNOWN'
 */
function determine_gst_type(?string $clientStateOrCode, ?string $clientGstin = null, ?string $invoiceType = 'gst'): string {
    if ($invoiceType === 'personal') {
        return 'NONE';
    }
    $cleanGstin = trim($clientGstin ?? '');
    if ($cleanGstin === '') {
        return 'NONE';
    }

    $clientCode = normalize_gst_state_code($clientStateOrCode);
    if ($clientCode === '' && strlen($cleanGstin) >= 2 && ctype_digit(substr($cleanGstin, 0, 2))) {
        $clientCode = normalize_gst_state_code(substr($cleanGstin, 0, 2));
    }

    if ($clientCode === '') {
        return 'UNKNOWN';
    }

    $business = get_business_gst_state_details();
    $bizCode = $business['state_code'];

    return ($clientCode === $bizCode) ? 'INTRA' : 'INTER';
}

/**
 * Calculate dynamic GST breakdown (CGST, SGST, IGST) for an invoice record or array
 */
function get_invoice_tax_breakdown($invoice, $customer = null, bool $forceRecalculate = false): array {
    global $pdo;

    $taxTotal = round((float)($invoice['tax_total'] ?? 0), 2);
    $taxableValue = round((float)($invoice['taxable_value'] ?? 0), 2);
    $invoiceType = $invoice['invoice_type'] ?? 'gst';
    $invoiceId = (int)($invoice['id'] ?? ($invoice['invoice_id'] ?? 0));

    // If customer data is not passed or incomplete, fetch customer
    if (!$customer && !empty($invoice['customer_id']) && $pdo) {
        $cStmt = $pdo->prepare("SELECT id, name, gstin, state, state_code, billing_address FROM customers WHERE id = ?");
        $cStmt->execute([(int)$invoice['customer_id']]);
        $customer = $cStmt->fetch(PDO::FETCH_ASSOC);
    }

    $customerGstin = trim($customer['gstin'] ?? ($invoice['customer_gstin'] ?? ''));
    $placeCode = normalize_gst_state_code($invoice['place_of_supply'] ?? ($customer['state_code'] ?? ($customer['state'] ?? '')));
    $clientStateName = get_gst_state_name($placeCode ?: ($customer['state'] ?? ''));

    // Clients without GSTIN or personal bill or 0 tax: No GST applied
    if ($invoiceType === 'personal' || empty($customerGstin) || $taxTotal <= 0) {
        return [
            'gst_type'          => 'NONE',
            'taxable_value'     => $taxableValue,
            'tax_total'         => 0.00,
            'cgst_amount'       => 0.00,
            'sgst_amount'       => 0.00,
            'igst_amount'       => 0.00,
            'cgst_rate'         => 0,
            'sgst_rate'         => 0,
            'igst_rate'         => 0,
            'total_rate'        => 0,
            'has_gst'           => false,
            'place_of_supply'   => $placeCode,
            'client_state_name' => $clientStateName,
        ];
    }

    // Determine primary tax rate from items or totals
    $primaryRate = 18.0;
    if ($invoiceId > 0 && $pdo) {
        $stmtR = $pdo->prepare("SELECT tax_rate FROM invoice_items WHERE invoice_id = ? AND tax_rate > 0 ORDER BY id ASC LIMIT 1");
        $stmtR->execute([$invoiceId]);
        $rateFound = $stmtR->fetchColumn();
        if ($rateFound !== false && (float)$rateFound > 0) {
            $primaryRate = (float)$rateFound;
        }
    } elseif ($taxableValue > 0) {
        $primaryRate = round(($taxTotal / $taxableValue) * 100, 1);
    }

    // Dynamic determination using Place of Supply or Customer State
    $placeOfSupply = trim($invoice['place_of_supply'] ?? '');
    $clientState = trim($customer['state_code'] ?? ($customer['state'] ?? ''));
    $gstType = determine_gst_type($placeOfSupply ?: $clientState, $customerGstin, $invoiceType);

    // Historical check: if gst_invoices table already has stored amounts AND matches current client GST type
    if (!$forceRecalculate && $invoiceId > 0 && $pdo) {
        $stmtG = $pdo->prepare("SELECT cgst_amount, sgst_amount, igst_amount FROM gst_invoices WHERE invoice_id = ?");
        $stmtG->execute([$invoiceId]);
        $gstRow = $stmtG->fetch(PDO::FETCH_ASSOC);
        if ($gstRow) {
            $cg = (float)$gstRow['cgst_amount'];
            $sg = (float)$gstRow['sgst_amount'];
            $ig = (float)$gstRow['igst_amount'];

            if ($gstType === 'INTER' && $ig > 0 && $cg == 0 && $sg == 0) {
                return [
                    'gst_type'          => 'INTER',
                    'taxable_value'     => $taxableValue,
                    'tax_total'         => $taxTotal,
                    'cgst_amount'       => 0.00,
                    'sgst_amount'       => 0.00,
                    'igst_amount'       => $taxTotal,
                    'cgst_rate'         => 0,
                    'sgst_rate'         => 0,
                    'igst_rate'         => $primaryRate,
                    'total_rate'        => $primaryRate,
                    'has_gst'           => true,
                    'place_of_supply'   => $placeCode,
                    'client_state_name' => $clientStateName,
                ];
            } elseif ($gstType === 'INTRA' && ($cg > 0 || $sg > 0) && $ig == 0) {
                $halfRate = round($primaryRate / 2, 2);
                $cgstAmt = round($taxTotal / 2, 2);
                $sgstAmt = round($taxTotal - $cgstAmt, 2);
                return [
                    'gst_type'          => 'INTRA',
                    'taxable_value'     => $taxableValue,
                    'tax_total'         => $taxTotal,
                    'cgst_amount'       => $cgstAmt,
                    'sgst_amount'       => $sgstAmt,
                    'igst_amount'       => 0.00,
                    'cgst_rate'         => $halfRate,
                    'sgst_rate'         => $halfRate,
                    'igst_rate'         => 0,
                    'total_rate'        => $primaryRate,
                    'has_gst'           => true,
                    'place_of_supply'   => $placeCode,
                    'client_state_name' => $clientStateName,
                ];
            }
        }
    }

    // Dynamic determination using Place of Supply or Customer State
    $placeOfSupply = trim($invoice['place_of_supply'] ?? '');
    $clientState = trim($customer['state_code'] ?? ($customer['state'] ?? ''));
    $gstType = determine_gst_type($placeOfSupply ?: $clientState, $customerGstin, $invoiceType);

    if ($gstType === 'INTRA') {
        $cgst = round($taxTotal / 2, 2);
        $sgst = round($taxTotal - $cgst, 2);
        $halfRate = round($primaryRate / 2, 2);
        return [
            'gst_type'          => 'INTRA',
            'taxable_value'     => $taxableValue,
            'tax_total'         => $taxTotal,
            'cgst_amount'       => $cgst,
            'sgst_amount'       => $sgst,
            'igst_amount'       => 0.00,
            'cgst_rate'         => $halfRate,
            'sgst_rate'         => $halfRate,
            'igst_rate'         => 0,
            'total_rate'        => $primaryRate,
            'has_gst'           => true,
            'place_of_supply'   => $placeCode,
            'client_state_name' => $clientStateName,
        ];
    } else {
        return [
            'gst_type'          => 'INTER',
            'taxable_value'     => $taxableValue,
            'tax_total'         => $taxTotal,
            'cgst_amount'       => 0.00,
            'sgst_amount'       => 0.00,
            'igst_amount'       => $taxTotal,
            'cgst_rate'         => 0,
            'sgst_rate'         => 0,
            'igst_rate'         => $primaryRate,
            'total_rate'        => $primaryRate,
            'has_gst'           => true,
            'place_of_supply'   => $placeCode,
            'client_state_name' => $clientStateName,
        ];
    }
}

/**
 * Get user_id associated with an employee_id
 */
function get_user_id_by_employee_id(PDO $pdo, int $empId): ?int {
    if ($empId <= 0) return null;
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$empId]);
        $uid = $stmt->fetchColumn();
        return $uid ? (int)$uid : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Create a persistent action notification in the notifications table.
 * Strictly for real state-changing user actions (Check-in, Punch-out, Break, Leave, Profile, Docs, Password, Reports).
 */
function create_action_notification(PDO $pdo, int $userId, string $title, string $message, ?string $link = null, string $type = 'action_complete', ?array $payload = null): ?int {
    if ($userId <= 0) return null;
    try {
        $nowIso = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, payload, channel, status, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'crm', 'unread', 0, ?)
        ");
        $payloadJson = !empty($payload) ? json_encode($payload) : null;
        $stmt->execute([$userId, $type, $title, $message, $link, $payloadJson, $nowIso]);
        $notifId = (int)$pdo->lastInsertId();

        // Check if user is an employee; if so, also notify all active Admin users!
        try {
            $userRoleStmt = $pdo->prepare("SELECT role, name FROM users WHERE id = ? LIMIT 1");
            $userRoleStmt->execute([$userId]);
            $actor = $userRoleStmt->fetch(PDO::FETCH_ASSOC);
            $actorRole = strtolower(trim((string)($actor['role'] ?? 'employee')));
            $actorName = trim((string)($actor['name'] ?? 'Employee'));

            if ($actorRole !== 'admin') {
                $admins = $pdo->query("SELECT DISTINCT id FROM users WHERE LOWER(role) IN ('admin', 'super admin', 'manager') AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($admins)) {
                    $adminTitle = "{$actorName}: {$title}";
                    $adminMsg = "{$actorName}: {$message}";
                    
                    $adminLink = $link;
                    if (!empty($adminLink)) {
                        $adminLink = str_replace('/employee/attendance.php', 'modules/team/attendance.php', $adminLink);
                        $adminLink = str_replace('/employee/leave.php', 'modules/team/leave.php', $adminLink);
                        $adminLink = str_replace('/employee/work-reports.php', 'modules/team/work_reports.php', $adminLink);
                        $adminLink = str_replace('/employee/profile.php', 'modules/team/employees.php', $adminLink);
                    }

                    $adminIns = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, message, link, payload, channel, status, is_read, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'crm', 'unread', 0, ?)
                    ");
                    $adminPayloadJson = json_encode(array_merge($payload ?: [], ['actor_user_id' => $userId, 'actor_name' => $actorName]));

                    foreach ($admins as $adminId) {
                        if ((int)$adminId !== $userId) {
                            $adminIns->execute([(int)$adminId, $type, $adminTitle, $adminMsg, $adminLink, $adminPayloadJson, $nowIso]);
                        }
                    }
                }
            }
        } catch (\Throwable $t) {}

        // Broadcast real-time notification if RealtimeService is loaded
        try {
            if (class_exists('\\App\\Realtime\\RealtimeService')) {
                \App\Realtime\RealtimeService::getInstance($pdo)->emit(
                    'notification.new',
                    'notification',
                    $notifId,
                    'create',
                    [
                        'id' => $notifId,
                        'user_id' => $userId,
                        'type' => $type,
                        'title' => $title,
                        'message' => $message,
                        'link' => $link
                    ],
                    [],
                    $userId
                );
            }
        } catch (\Throwable $t) {}

        // Dispatch Mobile Push Notification via FCM to target user's registered mobile device(s)
        try {
            if (class_exists('\\App\\Notifications\\Channels\\MobilePushChannel') && class_exists('\\App\\Notifications\\NotificationService')) {
                $notifService = \App\Notifications\NotificationService::getInstance($pdo);
                $settings = $notifService->getSettings();
                $mobileChannel = new \App\Notifications\Channels\MobilePushChannel($pdo);
                
                $uStmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
                $uStmt->execute([$userId]);
                $targetUser = $uStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($targetUser) {
                    $mobileChannel->send([
                        'id'      => $notifId,
                        'title'   => $title,
                        'message' => $message,
                        'type'    => $type,
                        'link'    => $link
                    ], $targetUser, $settings);
                }
            }
        } catch (\Throwable $mpErr) {
            error_log("Failed to dispatch mobile push in create_action_notification: " . $mpErr->getMessage());
        }

        return $notifId;
    } catch (\Throwable $e) {
        error_log("Failed to create action notification: " . $e->getMessage());
        return null;
    }
}

/**
 * Returns 4-digit Indian Financial Year string (e.g. '2627' for FY 2026-27).
 * Indian fiscal year begins April 1 and ends March 31.
 */
function get_financial_year($date = null) {
    $time = $date ? strtotime($date) : time();
    $month = (int)date('n', $time);
    $year = (int)date('Y', $time);
    if ($month >= 4) {
        $fyStart = $year;
        $fyEnd = $year + 1;
    } else {
        $fyStart = $year - 1;
        $fyEnd = $year;
    }
    return substr($fyStart, -2) . substr($fyEnd, -2);
}

/**
 * Atomically generates next sequential invoice number and returns breakdown.
 * Format: VS-financialyear-month-invoicenumber (e.g. VS-2627-09-001)
 * For personal payments: VSP-2627-09-001
 */
function generate_next_invoice_number($invoice_type = 'gst', $invoice_date = null) {
    global $pdo;
    $date = $invoice_date ?: date('Y-m-d');
    $fy = get_financial_year($date);
    $month = date('m', strtotime($date));

    if ($invoice_type === 'personal') {
        $stmt = $pdo->query("SELECT last_serial FROM personal_invoice_counter WHERE id = 1 FOR UPDATE");
        $row = $stmt ? $stmt->fetch() : null;
        $serial = $row ? ((int)$row['last_serial'] + 1) : 1;
        $pdo->prepare("INSERT INTO personal_invoice_counter (id, last_serial) VALUES (1, ?) ON DUPLICATE KEY UPDATE last_serial = ?")->execute([$serial, $serial]);
        $prefix = "VSP";
    } else {
        $stmt = $pdo->query("SELECT last_serial FROM gst_invoice_counter WHERE id = 1 FOR UPDATE");
        $row = $stmt ? $stmt->fetch() : null;
        $serial = $row ? ((int)$row['last_serial'] + 1) : 1;
        $pdo->prepare("INSERT INTO gst_invoice_counter (id, last_serial) VALUES (1, ?) ON DUPLICATE KEY UPDATE last_serial = ?")->execute([$serial, $serial]);
        $prefix = "VS";
    }

    $padded = str_pad($serial, 3, '0', STR_PAD_LEFT);
    $invoice_no = "{$prefix}-{$fy}-{$month}-{$padded}";

    return [
        'invoice_no'     => $invoice_no,
        'serial_no'      => $serial,
        'financial_year' => $fy,
        'month_str'      => $month,
        'invoice_type'   => $invoice_type
    ];
}

/**
 * Record an invoice into dedicated GST or Personal registry table
 */
function sync_invoice_to_subregistry($invoice_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmt->execute([$invoice_id]);
    $inv = $stmt->fetch();
    if (!$inv) return false;

    $type = $inv['invoice_type'] ?? 'gst';
    $fy = get_financial_year($inv['invoice_date']);
    $m = date('m', strtotime($inv['invoice_date'] ?: 'now'));
    $serial = (int)($inv['serial_no'] ?: 0);
    $status = ($inv['status'] === 'cancelled') ? 'cancelled' : 'active';
    $reason = $inv['cancellation_reason'] ?? null;

    if ($type === 'personal') {
        // Clean up from GST registry if it was previously there
        $pdo->prepare("DELETE FROM gst_invoices WHERE invoice_id = ?")->execute([$invoice_id]);

        $chk = $pdo->prepare("SELECT id FROM personal_invoices WHERE invoice_id = ?");
        $chk->execute([$invoice_id]);
        if ($chk->fetch()) {
            $pdo->prepare("UPDATE personal_invoices SET total_amount = ?, status = ?, cancellation_reason = ?, invoice_date = ?, financial_year = ?, month_str = ? WHERE invoice_id = ?")
                ->execute([$inv['total_amount'], $status, $reason, $inv['invoice_date'], $fy, $m, $invoice_id]);
        } else {
            if ($serial <= 0) {
                $maxS = (int)($pdo->query("SELECT MAX(serial_no) FROM personal_invoices")->fetchColumn() ?: 0);
                $serial = $maxS + 1;
                $pdo->prepare("UPDATE invoices SET serial_no = ? WHERE id = ?")->execute([$serial, $invoice_id]);
            }
            $pdo->prepare("INSERT INTO personal_invoices (serial_no, invoice_no, invoice_id, customer_id, invoice_date, total_amount, payment_mode, financial_year, month_str, status, cancellation_reason, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$serial, $inv['invoice_no'], $invoice_id, $inv['customer_id'], $inv['invoice_date'], $inv['total_amount'], $inv['payment_mode'] ?: 'Personal Account', $fy, $m, $status, $reason, $inv['notes']]);
        }
    } else {
        // Clean up from Personal registry if it was previously there
        $pdo->prepare("DELETE FROM personal_invoices WHERE invoice_id = ?")->execute([$invoice_id]);

        $chk = $pdo->prepare("SELECT id FROM gst_invoices WHERE invoice_id = ?");
        $chk->execute([$invoice_id]);
        $taxTotal = round((float)($inv['tax_total'] ?? 0), 2);
        $taxable = round((float)($inv['taxable_value'] ?? 0), 2);

        // Fetch customer to check GSTIN and State
        $cStmt = $pdo->prepare("SELECT id, name, gstin, state, state_code, billing_address FROM customers WHERE id = ?");
        $cStmt->execute([(int)$inv['customer_id']]);
        $cust = $cStmt->fetch(PDO::FETCH_ASSOC);

        $breakdown = get_invoice_tax_breakdown($inv, $cust, true);
        $cgst = (float)$breakdown['cgst_amount'];
        $sgst = (float)$breakdown['sgst_amount'];
        $igst = (float)$breakdown['igst_amount'];

        if ($chk->fetch()) {
            $pdo->prepare("UPDATE gst_invoices SET taxable_value = ?, cgst_amount = ?, sgst_amount = ?, igst_amount = ?, tax_total = ?, total_amount = ?, status = ?, cancellation_reason = ?, invoice_date = ?, financial_year = ?, month_str = ? WHERE invoice_id = ?")
                ->execute([$taxable, $cgst, $sgst, $igst, $taxTotal, $inv['total_amount'], $status, $reason, $inv['invoice_date'], $fy, $m, $invoice_id]);
        } else {
            if ($serial <= 0) {
                $maxS = (int)($pdo->query("SELECT MAX(serial_no) FROM gst_invoices")->fetchColumn() ?: 0);
                $serial = $maxS + 1;
                $pdo->prepare("UPDATE invoices SET serial_no = ? WHERE id = ?")->execute([$serial, $invoice_id]);
            }
            $pdo->prepare("INSERT INTO gst_invoices (serial_no, invoice_no, invoice_id, customer_id, invoice_date, taxable_value, cgst_amount, sgst_amount, igst_amount, tax_total, total_amount, financial_year, month_str, status, cancellation_reason, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$serial, $inv['invoice_no'], $invoice_id, $inv['customer_id'], $inv['invoice_date'], $taxable, $cgst, $sgst, $igst, $taxTotal, $inv['total_amount'], $fy, $m, $status, $reason, $inv['notes']]);
        }
    }
    return true;
}

/**
 * Cancel an invoice safely:
 * - Mark as cancelled and zero out due
 * - Keep serial number in continuous series for CA compliance
 * - Remove ledger debit so client balance reflects cancellation
 */
function cancel_invoice($invoice_id, $reason = '', $user_id = null) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmt->execute([$invoice_id]);
    $inv = $stmt->fetch();
    if (!$inv) return false;

    $user_id = $user_id ?: ($_SESSION['user_id'] ?? null);
    $now = date('Y-m-d H:i:s');
    $cleanReason = trim($reason ?: 'Cancelled by administrator');

    $pdo->beginTransaction();
    try {
        // 1. Update invoice record
        $upd = $pdo->prepare("UPDATE invoices SET status = 'cancelled', amount_due = 0.00, cancellation_reason = ?, cancelled_at = ?, cancelled_by = ? WHERE id = ?");
        $upd->execute([$cleanReason, $now, $user_id, $invoice_id]);

        // 2. Update subregistries
        $pdo->prepare("UPDATE gst_invoices SET status = 'cancelled', cancellation_reason = ? WHERE invoice_id = ?")->execute([$cleanReason, $invoice_id]);
        $pdo->prepare("UPDATE personal_invoices SET status = 'cancelled', cancellation_reason = ? WHERE invoice_id = ?")->execute([$cleanReason, $invoice_id]);

        // 3. Update client ledger: set debit to 0 and prefix description
        $chkLedger = $pdo->prepare("SELECT id, description FROM client_ledger WHERE reference_type = 'invoice' AND reference_id = ?");
        $chkLedger->execute([$invoice_id]);
        $ledg = $chkLedger->fetch();
        if ($ledg) {
            $desc = '[CANCELLED] ' . str_replace('[CANCELLED] ', '', $ledg['description']);
            $pdo->prepare("UPDATE client_ledger SET debit = 0.00, description = ? WHERE id = ?")->execute([$desc, $ledg['id']]);
        }

        // 4. Recalculate customer balance
        if (!empty($inv['customer_id']) && function_exists('recalculate_customer_ledger')) {
            recalculate_customer_ledger($inv['customer_id']);
        }

        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log("Error cancelling invoice #{$invoice_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Restore a cancelled invoice back to active state
 */
function restore_invoice($invoice_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmt->execute([$invoice_id]);
    $inv = $stmt->fetch();
    if (!$inv) return false;

    $pdo->beginTransaction();
    try {
        $amountPaid = (float)($inv['amount_paid'] ?? 0);
        $totalAmount = (float)($inv['total_amount'] ?? 0);
        $amountDue = max(0, $totalAmount - $amountPaid);
        $newStatus = ($amountPaid >= $totalAmount && $totalAmount > 0) ? 'paid' : ($amountPaid > 0 ? 'partially_paid' : 'sent');

        $upd = $pdo->prepare("UPDATE invoices SET status = ?, amount_due = ?, cancellation_reason = NULL, cancelled_at = NULL, cancelled_by = NULL WHERE id = ?");
        $upd->execute([$newStatus, $amountDue, $invoice_id]);

        $pdo->prepare("UPDATE gst_invoices SET status = 'active', cancellation_reason = NULL WHERE invoice_id = ?")->execute([$invoice_id]);
        $pdo->prepare("UPDATE personal_invoices SET status = 'active', cancellation_reason = NULL WHERE invoice_id = ?")->execute([$invoice_id]);

        // Restore ledger debit
        $chkLedger = $pdo->prepare("SELECT id FROM client_ledger WHERE reference_type = 'invoice' AND reference_id = ?");
        $chkLedger->execute([$invoice_id]);
        $ledg = $chkLedger->fetch();
        if ($ledg) {
            $cleanDesc = "Invoice " . $inv['invoice_no'];
            $pdo->prepare("UPDATE client_ledger SET debit = ?, description = ? WHERE id = ?")->execute([$totalAmount, $cleanDesc, $ledg['id']]);
        } elseif (function_exists('post_invoice_to_ledger')) {
            post_invoice_to_ledger($invoice_id);
        }

        if (!empty($inv['customer_id']) && function_exists('recalculate_customer_ledger')) {
            recalculate_customer_ledger($inv['customer_id']);
        }

        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return false;
    }
}

/**
 * Permanently replaces an employee's document of a given category.
 * Removes all previous physical files of this doc_type for this employee from disk,
 * purges previous duplicate records from employee_documents table,
 * inserts the new single record, and updates the employees table column.
 */
function sync_replace_employee_document(PDO $pdo, int $empId, string $docType, string $newFilePath, int $uploadedByUserId = 1, ?string $customTitle = null): bool {
    $docTypeNorm = trim($docType);
    $column = null;
    $defaultTitle = $customTitle;

    if (strcasecmp($docTypeNorm, 'Resume') === 0 || strcasecmp($docTypeNorm, 'CV') === 0) {
        $docTypeNorm = 'Resume';
        $column = 'resume_file';
        if (empty($defaultTitle)) $defaultTitle = 'Resume / Curriculum Vitae';
    } elseif (strcasecmp($docTypeNorm, 'ID Proof') === 0 || strcasecmp($docTypeNorm, 'Aadhaar') === 0 || strcasecmp($docTypeNorm, 'PAN') === 0 || strcasecmp($docTypeNorm, 'Govt ID') === 0) {
        $docTypeNorm = 'ID Proof';
        $column = 'aadhaar_file';
        if (empty($defaultTitle)) $defaultTitle = 'Govtment ID (Aadhaar / PAN)';
    } elseif (strcasecmp($docTypeNorm, 'Offer Letter') === 0) {
        $docTypeNorm = 'Offer Letter';
        $column = 'offer_letter_file';
        if (empty($defaultTitle)) $defaultTitle = 'Official Offer Letter';
    }

    $crmRoot = realpath(__DIR__ . '/..');

    // 1. Collect all old file paths for this employee & doc_type to permanently remove from disk
    $oldPaths = [];

    // From employee_documents table
    $stmtOld = $pdo->prepare("SELECT file_path FROM employee_documents WHERE employee_id = ? AND (doc_type = ? OR doc_type = ?)");
    $stmtOld->execute([$empId, $docTypeNorm, $docType]);
    while ($row = $stmtOld->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['file_path'])) {
            $oldPaths[] = $row['file_path'];
        }
    }

    // From employees table column
    if ($column) {
        $stmtEmp = $pdo->prepare("SELECT {$column} FROM employees WHERE id = ?");
        $stmtEmp->execute([$empId]);
        $empOld = $stmtEmp->fetchColumn();
        if (!empty($empOld)) {
            $oldPaths[] = $empOld;
        }
    }

    $cleanNew = ltrim(str_replace('\\', '/', $newFilePath), '/');

    // 2. Permanently delete old files from disk
    foreach (array_unique($oldPaths) as $oldRel) {
        $cleanOld = ltrim(str_replace('\\', '/', $oldRel), '/');
        if (!empty($cleanOld) && $cleanOld !== $cleanNew) {
            $diskFile = $crmRoot . '/' . $cleanOld;
            if (file_exists($diskFile) && is_file($diskFile)) {
                @unlink($diskFile);
            }
        }
    }

    // 3. Remove old rows from employee_documents
    $pdo->prepare("DELETE FROM employee_documents WHERE employee_id = ? AND (doc_type = ? OR doc_type = ?)")
        ->execute([$empId, $docTypeNorm, $docType]);

    // 4. Insert single new record into employee_documents
    $ins = $pdo->prepare("INSERT INTO employee_documents (employee_id, doc_name, doc_type, file_path, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $ins->execute([$empId, $defaultTitle, $docTypeNorm, $cleanNew, $uploadedByUserId]);

    // 5. Update matching column in employees table
    if ($column) {
        if ($column === 'offer_letter_file') {
            $pdo->prepare("UPDATE employees SET offer_letter_file = ?, offer_letter_status = 'Sent', offer_letter_date = CURDATE() WHERE id = ?")
                ->execute([$cleanNew, $empId]);
        } else {
            $pdo->prepare("UPDATE employees SET {$column} = ? WHERE id = ?")
                ->execute([$cleanNew, $empId]);
        }
    }

    return true;
}

/**
 * Permanently deletes an employee's document of a given type or specific doc_id from disk and database.
 */
function sync_delete_employee_document(PDO $pdo, int $empId, ?int $docId = null, ?string $docType = null): bool {
    $crmRoot = realpath(__DIR__ . '/..');

    $oldPaths = [];
    $docTypes = [];

    if ($docId && $docId > 0) {
        $s = $pdo->prepare("SELECT doc_type, file_path FROM employee_documents WHERE id = ? AND employee_id = ?");
        $s->execute([$docId, $empId]);
        if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
            $oldPaths[] = $r['file_path'];
            $docTypes[] = $r['doc_type'];
        }
        $pdo->prepare("DELETE FROM employee_documents WHERE id = ? AND employee_id = ?")->execute([$docId, $empId]);
    } elseif (!empty($docType)) {
        $s = $pdo->prepare("SELECT file_path FROM employee_documents WHERE employee_id = ? AND doc_type = ?");
        $s->execute([$empId, $docType]);
        while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
            $oldPaths[] = $r['file_path'];
        }
        $docTypes[] = $docType;
        $pdo->prepare("DELETE FROM employee_documents WHERE employee_id = ? AND doc_type = ?")->execute([$empId, $docType]);
    }

    // Unlink disk files
    foreach (array_unique($oldPaths) as $p) {
        $clean = ltrim(str_replace('\\', '/', $p), '/');
        if (!empty($clean)) {
            $disk = $crmRoot . '/' . $clean;
            if (file_exists($disk) && is_file($disk)) {
                @unlink($disk);
            }
        }
    }

    // Update employees table if column matches
    foreach ($docTypes as $dt) {
        if (strcasecmp($dt, 'Resume') === 0) {
            $pdo->prepare("UPDATE employees SET resume_file = NULL WHERE id = ?")->execute([$empId]);
        } elseif (strcasecmp($dt, 'ID Proof') === 0) {
            $pdo->prepare("UPDATE employees SET aadhaar_file = NULL WHERE id = ?")->execute([$empId]);
        } elseif (strcasecmp($dt, 'Offer Letter') === 0) {
            $pdo->prepare("UPDATE employees SET offer_letter_file = NULL, offer_letter_status = 'Not Generated' WHERE id = ?")->execute([$empId]);
        }
    }

    return true;
}
