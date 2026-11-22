<?php
// modules/settings/notifications.php — Admin Notification Settings
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/layout.php';
require_admin();

use App\Notifications\NotificationService;

$service = NotificationService::getInstance($pdo);
$settings = $service->getSettings();
$csrf = csrf_token();

$msg = '';
$err = '';
$testResult = null;

// Handle Form Save & Test Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = "Security token mismatch. Please reload the page.";
    } elseif (isset($_POST['save_settings'])) {
        $attChannels = $_POST['attendance_channels'] ?? [];
        $punchChannels = $_POST['punchout_channels'] ?? [];

        $newSettings = [
            'attendance_reminder_enabled' => isset($_POST['attendance_reminder_enabled']) ? '1' : '0',
            'attendance_expected_time'    => trim($_POST['attendance_expected_time'] ?? '10:00:00'),
            'attendance_reminder_delay'   => max(0, intval($_POST['attendance_reminder_delay'] ?? 2)),
            'attendance_channels'         => $attChannels,

            'punchout_reminder_enabled'   => isset($_POST['punchout_reminder_enabled']) ? '1' : '0',
            'punchout_expected_time'      => trim($_POST['punchout_expected_time'] ?? '20:00:00'),
            'punchout_reminder_delay'     => max(0, intval($_POST['punchout_reminder_delay'] ?? 2)),
            'punchout_auto_half_day'      => isset($_POST['punchout_auto_half_day']) ? '1' : '0',
            'punchout_channels'           => $punchChannels,

            'smtp_host'                   => trim($_POST['smtp_host'] ?? ''),
            'smtp_port'                   => trim($_POST['smtp_port'] ?? '587'),
            'smtp_user'                   => trim($_POST['smtp_user'] ?? ''),
            'smtp_pass'                   => trim($_POST['smtp_pass'] ?? ''),
            'smtp_secure'                 => trim($_POST['smtp_secure'] ?? 'tls'),
            'smtp_from_email'             => trim($_POST['smtp_from_email'] ?? ''),
            'smtp_from_name'              => trim($_POST['smtp_from_name'] ?? 'VibeSoft CMS'),

            'webpush_vapid_public'        => trim($_POST['webpush_vapid_public'] ?? ''),
            'webpush_vapid_private'       => trim($_POST['webpush_vapid_private'] ?? ''),
            'webpush_subject'             => trim($_POST['webpush_subject'] ?? 'mailto:admin@vibesoft.in'),

            'fcm_server_key'              => trim($_POST['fcm_server_key'] ?? '')
        ];

        $service->saveSettings($newSettings);
        $settings = $service->getSettings();
        $msg = "Notification settings updated successfully!";
    } elseif (isset($_POST['test_channel'])) {
        $ch = trim($_POST['test_channel']);
        $adminUserId = (int)$_SESSION['user_id'];
        
        $testPayload = [
            'employee_name'   => $_SESSION['user_name'] ?? 'Admin',
            'date'            => date('d M Y'),
            'attendance_time' => '10:00 AM',
            'punchout_time'   => '08:00 PM',
            'message'         => "This is a test notification dispatched via {$ch} at " . date('h:i:s A') . "."
        ];

        $res = $service->send($adminUserId, 'system_alert', $testPayload, [$ch]);
        $channelResult = $res['channels'][$ch] ?? null;

        if ($channelResult && $channelResult['success']) {
            $msg = "✔ Test for [{$ch}] succeeded: " . ($channelResult['response'] ?? 'Dispatched successfully.');
        } else {
            $err = "❌ Test for [{$ch}] failed: " . ($channelResult['error'] ?? 'Unknown dispatch error');
        }
    }
}

$attChannels = json_decode($settings['attendance_channels'] ?? '[]', true) ?: ['crm', 'email'];
$punchChannels = json_decode($settings['punchout_channels'] ?? '[]', true) ?: ['crm', 'email'];
?>

<div class="container-fluid py-3">
  <!-- Page Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
    <div>
      <h4 class="mb-1 text-dark fw-bold"><i class="bi bi-bell-fill text-primary me-2"></i>Notification Engine Settings</h4>
      <p class="text-muted small mb-0">Configure multi-channel alerts (CRM, Email, Web Push, Mobile) for attendance reminders and CRM events.</p>
    </div>
    <div class="d-flex gap-2 mt-2 mt-sm-0">
      <a href="send_notification.php" class="btn btn-success btn-sm shadow-sm">
        <i class="bi bi-send-fill me-1"></i> Custom Email / Reminder
      </a>
      <a href="notification_history.php" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-clock-history me-1"></i> Sent Logs
      </a>
      <a href="../../cron/notification_worker.php?key=vibesoft_cron_secret_2026" target="_blank" class="btn btn-outline-secondary btn-sm" title="Trigger background worker manually">
        <i class="bi bi-arrow-repeat me-1"></i> Trigger Worker Now
      </a>
    </div>
  </div>

  <?php if (!empty($msg)): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
      <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($msg) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if (!empty($err)): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
      <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <form method="POST" action="">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="save_settings" value="1">

    <div class="row g-4">
      <!-- 1. ATTENDANCE REMINDER CONFIGURATION -->
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100">
          <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-alarm text-warning me-2"></i>Attendance Reminder (Morning)</h6>
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="attendance_reminder_enabled" id="attRemEnable" value="1" <?= ($settings['attendance_reminder_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold" for="attRemEnable">Enable</label>
            </div>
          </div>
          <div class="card-body">
            <p class="text-muted small">Sends automated reminder if active employee has not checked in by expected check-in time plus delay.</p>
            
            <div class="row g-3 mb-3">
              <div class="col-sm-6">
                <label class="form-label small fw-semibold">Expected Check-In Time</label>
                <input type="time" name="attendance_expected_time" class="form-control" value="<?= htmlspecialchars($settings['attendance_expected_time'] ?? '10:00:00') ?>" required>
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold">Reminder Delay (Minutes)</label>
                <div class="input-group">
                  <input type="number" name="attendance_reminder_delay" class="form-control" value="<?= htmlspecialchars($settings['attendance_reminder_delay'] ?? 2) ?>" min="0" max="60" required>
                  <span class="input-group-text small">mins</span>
                </div>
              </div>
            </div>

            <label class="form-label small fw-semibold mb-2">Delivery Channels</label>
            <div class="d-flex flex-wrap gap-3 bg-light p-3 rounded-2 border">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="attendance_channels[]" value="crm" id="attChCrm" <?= in_array('crm', $attChannels) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="attChCrm"><i class="bi bi-bell me-1"></i> CRM In-App</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="attendance_channels[]" value="email" id="attChEmail" <?= in_array('email', $attChannels) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="attChEmail"><i class="bi bi-envelope me-1"></i> Email</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="attendance_channels[]" value="web_push" id="attChWeb" <?= in_array('web_push', $attChannels) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="attChWeb"><i class="bi bi-browser-chrome me-1"></i> Web Push</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="attendance_channels[]" value="mobile_push" id="attChMob" <?= in_array('mobile_push', $attChannels) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="attChMob"><i class="bi bi-phone me-1"></i> Mobile Push</label>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 2. PUNCH-OUT REMINDER & AUTO HALF-DAY -->
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100">
          <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-box-arrow-right text-danger me-2"></i>Punch-Out Reminder & Auto Half-Day</h6>
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="punchout_reminder_enabled" id="punchRemEnable" value="1" <?= ($settings['punchout_reminder_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold" for="punchRemEnable">Enable</label>
            </div>
          </div>
          <div class="card-body">
            <p class="text-muted small">Sends punch-out notification and applies Half-Day status (12:00:00 checkout) if employee checked in but did not punch out.</p>
            
            <div class="row g-3 mb-3">
              <div class="col-sm-6">
                <label class="form-label small fw-semibold">Expected Punch-Out Time</label>
                <input type="time" name="punchout_expected_time" class="form-control" value="<?= htmlspecialchars($settings['punchout_expected_time'] ?? '20:00:00') ?>" required>
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold">Reminder Delay (Minutes)</label>
                <div class="input-group">
                  <input type="number" name="punchout_reminder_delay" class="form-control" value="<?= htmlspecialchars($settings['punchout_reminder_delay'] ?? 2) ?>" min="0" max="60" required>
                  <span class="input-group-text small">mins</span>
                </div>
              </div>
            </div>

            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" name="punchout_auto_half_day" id="autoHalfDayCheck" value="1" <?= ($settings['punchout_auto_half_day'] ?? '1') === '1' ? 'checked' : '' ?>>
              <label class="form-check-label small fw-semibold" for="autoHalfDayCheck">Auto-apply Half Day status on missing punch-out</label>
            </div>

            <label class="form-label small fw-semibold mb-2">Delivery Channels</label>
            <div class="d-flex flex-wrap gap-3 bg-light p-3 rounded-2 border">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="punchout_channels[]" value="crm" id="punchChCrm" <?= in_array('crm', $punchChannels) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="punchChCrm"><i class="bi bi-bell me-1"></i> CRM In-App</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="punchout_channels[]" value="email" id="punchChEmail" <?= in_array('email', $punchChannels) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="punchChEmail"><i class="bi bi-envelope me-1"></i> Email</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="punchout_channels[]" value="web_push" id="punchChWeb" <?= in_array('web_push', $punchChannels) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="punchChWeb"><i class="bi bi-browser-chrome me-1"></i> Web Push</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="punchout_channels[]" value="mobile_push" id="punchChMob" <?= in_array('mobile_push', $punchChannels) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="punchChMob"><i class="bi bi-phone me-1"></i> Mobile Push</label>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 3. SMTP EMAIL CONFIGURATION -->
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100">
          <div class="card-header bg-white py-3 border-bottom">
            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-envelope-at text-primary me-2"></i>Email (SMTP) Configuration</h6>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-sm-8">
                <label class="form-label small fw-semibold">SMTP Host</label>
                <input type="text" name="smtp_host" class="form-control form-control-sm" placeholder="smtp.gmail.com or mail.yourdomain.com" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>">
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold">Port</label>
                <input type="text" name="smtp_port" class="form-control form-control-sm" placeholder="587" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>">
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold">SMTP Username</label>
                <input type="text" name="smtp_user" class="form-control form-control-sm" placeholder="user@domain.com" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>">
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold">SMTP Password</label>
                <input type="password" name="smtp_pass" class="form-control form-control-sm" placeholder="••••••••••••" value="<?= htmlspecialchars($settings['smtp_pass'] ?? '') ?>">
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold">Encryption</label>
                <select name="smtp_secure" class="form-select form-select-sm">
                  <option value="tls" <?= ($settings['smtp_secure'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS (Recommended 587)</option>
                  <option value="ssl" <?= ($settings['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                  <option value="none" <?= ($settings['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                </select>
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold">From Email</label>
                <input type="email" name="smtp_from_email" class="form-control form-control-sm" placeholder="no-reply@domain.com" value="<?= htmlspecialchars($settings['smtp_from_email'] ?? '') ?>">
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold">From Name</label>
                <input type="text" name="smtp_from_name" class="form-control form-control-sm" value="<?= htmlspecialchars($settings['smtp_from_name'] ?? 'VibeSoft CMS') ?>">
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 4. PUSH NOTIFICATIONS (WEB & MOBILE) -->
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100">
          <div class="card-header bg-white py-3 border-bottom">
            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-broadcast text-info me-2"></i>Web Push (VAPID) & Mobile (FCM)</h6>
          </div>
          <div class="card-body">
            <h6 class="text-secondary small fw-bold text-uppercase mb-2">Web Push (VAPID Keys)</h6>
            <div class="mb-2">
              <label class="form-label small fw-semibold">VAPID Public Key</label>
              <input type="text" name="webpush_vapid_public" class="form-control form-control-sm" placeholder="BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvPuB20bgU..." value="<?= htmlspecialchars($settings['webpush_vapid_public'] ?? '') ?>">
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">VAPID Private Key</label>
              <input type="password" name="webpush_vapid_private" class="form-control form-control-sm" placeholder="••••••••••••" value="<?= htmlspecialchars($settings['webpush_vapid_private'] ?? '') ?>">
            </div>
            <div class="mb-3">
              <label class="form-label small fw-semibold">VAPID Subject / Contact</label>
              <input type="text" name="webpush_subject" class="form-control form-control-sm" placeholder="mailto:admin@vibesoft.in" value="<?= htmlspecialchars($settings['webpush_subject'] ?? '') ?>">
            </div>

            <h6 class="text-secondary small fw-bold text-uppercase mb-2 border-top pt-2">Mobile App (Firebase Cloud Messaging)</h6>
            <div>
              <label class="form-label small fw-semibold">FCM Server Key / Token</label>
              <input type="password" name="fcm_server_key" class="form-control form-control-sm" placeholder="AAAA... Firebase Legacy or Service Account token" value="<?= htmlspecialchars($settings['fcm_server_key'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- SAVE BUTTON -->
    <div class="mt-4 text-end">
      <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold shadow-sm">
        <i class="bi bi-check2-circle me-1"></i> Save Notification Settings
      </button>
    </div>
  </form>

  <!-- 5. ADMIN TEST DISPATCH TOOLS -->
  <div class="card border-0 shadow-sm rounded-3 mt-4">
    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
      <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-tools text-success me-2"></i>Channel Testing Tools</h6>
      <span class="badge bg-light text-dark border">Test recipient: <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?> (<?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>)</span>
    </div>
    <div class="card-body">
      <p class="text-muted small mb-3">Click below to send an isolated test notification to yourself to verify channel connectivity without affecting any employee.</p>
      
      <div class="d-flex flex-wrap gap-2">
        <form method="POST" action="" class="d-inline">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="test_channel" value="crm">
          <button type="submit" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-bell me-1"></i> Test CRM Notification
          </button>
        </form>

        <form method="POST" action="" class="d-inline">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="test_channel" value="email">
          <button type="submit" class="btn btn-outline-success btn-sm">
            <i class="bi bi-envelope me-1"></i> Test Email Dispatch
          </button>
        </form>

        <form method="POST" action="" class="d-inline">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="test_channel" value="web_push">
          <button type="submit" class="btn btn-outline-info btn-sm">
            <i class="bi bi-browser-chrome me-1"></i> Test Web Push
          </button>
        </form>

        <form method="POST" action="" class="d-inline">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="test_channel" value="mobile_push">
          <button type="submit" class="btn btn-outline-dark btn-sm">
            <i class="bi bi-phone me-1"></i> Test Mobile Push
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
