<?php
// modules/settings/send_notification.php — Custom Email & Notification Sender
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/layout.php';
require_admin();

use App\Notifications\NotificationService;
use App\Notifications\NotificationTemplate;

$service = NotificationService::getInstance($pdo);
$csrf = csrf_token();

$msg = '';
$err = '';
$resultsSummary = null;

// Fetch active employees with linked user and email info
$employees = $pdo->query("
    SELECT e.id as employee_id, e.employee_code, e.full_name, e.email as employee_email, e.designation, e.department,
           u.id as user_id, u.email as user_email
    FROM employees e
    LEFT JOIN users u ON u.id = e.user_id OR (e.user_id IS NULL AND u.employee_id = e.id)
    WHERE e.status = 'active'
    ORDER BY e.full_name ASC
")->fetchAll();

// Handle Custom Send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_custom_notification'])) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = "Security token mismatch. Please reload and try again.";
    } else {
        $targetScope   = $_POST['target_scope'] ?? 'selected';
        $selectedEmps  = $_POST['employee_ids'] ?? [];
        $title         = trim($_POST['title'] ?? '');
        $message       = trim($_POST['message'] ?? '');
        $link          = trim($_POST['link'] ?? '');
        $channels      = $_POST['channels'] ?? ['email', 'crm'];
        $notifType     = trim($_POST['notif_type'] ?? 'system_alert');

        if (empty($title) || empty($message)) {
            $err = "Please provide both Subject / Title and Message content.";
        } elseif (empty($channels)) {
            $err = "Please select at least one delivery channel (Email or CRM).";
        } else {
            // Determine target employee list
            $targetEmployees = [];
            if ($targetScope === 'all') {
                $targetEmployees = $employees;
            } else {
                if (empty($selectedEmps)) {
                    $err = "Please select at least one employee to send to.";
                } else {
                    $selectedSet = array_flip($selectedEmps);
                    foreach ($employees as $emp) {
                        if (isset($selectedSet[$emp['employee_id']])) {
                            $targetEmployees[] = $emp;
                        }
                    }
                }
            }

            if (empty($err)) {
                $sentCount = 0;
                $emailSuccess = 0;
                $emailFailed = 0;
                $crmSuccess = 0;
                $webPushSuccess = 0;
                $mobilePushSuccess = 0;
                $errorsList = [];

                foreach ($targetEmployees as $emp) {
                    $userId = (int)($emp['user_id'] ?? 0);
                    $empName = $emp['full_name'];
                    $empEmail = !empty($emp['employee_email']) ? $emp['employee_email'] : ($emp['user_email'] ?? '');

                    if ($userId <= 0) {
                        // Attempt to find user by email or employee_id
                        $findUser = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? OR email = ? LIMIT 1");
                        $findUser->execute([$emp['employee_id'], $empEmail]);
                        $userId = (int)$findUser->fetchColumn();
                    }

                    if ($userId <= 0) {
                        $errorsList[] = "{$empName}: No linked user account found.";
                        continue;
                    }

                    // Personalized variable replacement
                    $personalizedMsg = str_replace(
                        ['{{employee_name}}', '{{date}}', '{{company_name}}'],
                        [$empName, date('d M Y'), 'VibeSoft CMS'],
                        $message
                    );
                    $personalizedTitle = str_replace(
                        ['{{employee_name}}', '{{date}}', '{{company_name}}'],
                        [$empName, date('d M Y'), 'VibeSoft CMS'],
                        $title
                    );

                    $payload = [
                        'title'         => $personalizedTitle,
                        'message'       => $personalizedMsg,
                        'link'          => $link,
                        'employee_name' => $empName,
                        'date'          => date('d M Y')
                    ];

                    $res = $service->send($userId, $notifType, $payload, $channels);

                    if ($res['success']) {
                        $sentCount++;
                        $chResults = $res['channels'] ?? [];
                        
                        if (!empty($chResults['email']['success'])) $emailSuccess++;
                        elseif (in_array('email', $channels) && empty($chResults['email']['skipped'])) {
                            $emailFailed++;
                            if (!empty($chResults['email']['error'])) {
                                $errorsList[] = "{$empName} (Email): " . $chResults['email']['error'];
                            }
                        }

                        if (!empty($chResults['crm']['success'])) $crmSuccess++;
                        if (!empty($chResults['web_push']['success'])) $webPushSuccess++;
                        if (!empty($chResults['mobile_push']['success'])) $mobilePushSuccess++;
                    } else {
                        $errorsList[] = "{$empName}: " . ($res['error'] ?? 'Delivery failed');
                    }
                }

                $resultsSummary = [
                    'total_targeted' => count($targetEmployees),
                    'total_processed' => $sentCount,
                    'email_success' => $emailSuccess,
                    'email_failed' => $emailFailed,
                    'crm_success' => $crmSuccess,
                    'web_push_success' => $webPushSuccess,
                    'mobile_push_success' => $mobilePushSuccess,
                    'errors' => $errorsList
                ];

                $msg = "Custom notification dispatched to {$sentCount} of " . count($targetEmployees) . " employee(s)!";
            }
        }
    }
}
?>

<div class="container-fluid py-3">
  <!-- Page Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
    <div>
      <h4 class="mb-1 text-dark fw-bold"><i class="bi bi-send-fill text-primary me-2"></i>Custom Email & Reminder Sender</h4>
      <p class="text-muted small mb-0">Select individual employees or broadcast custom alerts, work reminders, and announcements across Email & CRM.</p>
    </div>
    <div class="d-flex gap-2 mt-2 mt-sm-0">
      <a href="notifications.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-gear me-1"></i> Notification Settings
      </a>
      <a href="notification_history.php" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-clock-history me-1"></i> Sent Logs
      </a>
    </div>
  </div>

  <?php if (!empty($msg)): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
      <div class="d-flex align-items-center">
        <i class="bi bi-check-circle-fill fs-5 me-2"></i>
        <div>
          <strong><?= htmlspecialchars($msg) ?></strong>
          <?php if ($resultsSummary): ?>
            <div class="mt-1 small">
              <span class="me-3"><i class="bi bi-envelope-check text-success me-1"></i>Email Delivered: <strong><?= $resultsSummary['email_success'] ?></strong></span>
              <span class="me-3"><i class="bi bi-bell text-primary me-1"></i>CRM In-App: <strong><?= $resultsSummary['crm_success'] ?></strong></span>
              <?php if ($resultsSummary['email_failed'] > 0): ?>
                <span class="text-danger"><i class="bi bi-x-circle me-1"></i>Email Failed: <strong><?= $resultsSummary['email_failed'] ?></strong></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if (!empty($err)): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
      <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($resultsSummary && !empty($resultsSummary['errors'])): ?>
    <div class="alert alert-warning alert-dismissible fade show shadow-sm" role="alert">
      <h6 class="fw-bold mb-1"><i class="bi bi-info-circle me-1"></i> Delivery Notes & Warnings:</h6>
      <ul class="mb-0 small ps-3">
        <?php foreach (array_slice($resultsSummary['errors'], 0, 5) as $eLine): ?>
          <li><?= htmlspecialchars($eLine) ?></li>
        <?php endforeach; ?>
        <?php if (count($resultsSummary['errors']) > 5): ?>
          <li>...and <?= count($resultsSummary['errors']) - 5 ?> more. Check Notification History for full logs.</li>
        <?php endif; ?>
      </ul>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <form method="POST" action="" id="customSendForm">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="send_custom_notification" value="1">

    <div class="row g-4">
      <!-- LEFT COLUMN: RECIPIENT SELECTION -->
      <div class="col-lg-5">
        <div class="card border-0 shadow-sm rounded-3 h-100">
          <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-people text-primary me-2"></i>1. Select Recipients</h6>
            <span class="badge bg-light text-dark border"><?= count($employees) ?> Active Employees</span>
          </div>
          <div class="card-body p-3">
            <!-- Target Scope Radios -->
            <div class="mb-3 d-flex gap-3 bg-light p-2 rounded-2 border">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="target_scope" id="scopeSelected" value="selected" checked onchange="toggleScope()">
                <label class="form-check-label small fw-semibold" for="scopeSelected">Specific Employees</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="target_scope" id="scopeAll" value="all" onchange="toggleScope()">
                <label class="form-check-label small fw-semibold" for="scopeAll">All Employees (Broadcast)</label>
              </div>
            </div>

            <!-- Employee Search & Select Container -->
            <div id="employeeSelectContainer">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <input type="text" id="empSearchInput" class="form-control form-control-sm" placeholder="🔍 Search employee by name, code..." onkeyup="filterEmployees()">
              </div>
              <div class="d-flex justify-content-between align-items-center mb-2 px-1">
                <span class="text-muted small" id="selectedCounter">0 selected</span>
                <div>
                  <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none small me-2" onclick="selectAll(true)">Select All</button>
                  <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none small text-secondary" onclick="selectAll(false)">Clear</button>
                </div>
              </div>

              <!-- Employee Scrollable Checklist -->
              <div class="border rounded-2 p-2" style="max-height: 380px; overflow-y: auto;" id="empChecklist">
                <?php foreach ($employees as $emp): ?>
                  <?php $hasEmail = !empty($emp['employee_email']) || !empty($emp['user_email']); ?>
                  <div class="form-check emp-item py-1 border-bottom border-light" data-search="<?= strtolower(htmlspecialchars($emp['full_name'] . ' ' . $emp['employee_code'] . ' ' . ($emp['designation'] ?? ''))) ?>">
                    <input class="form-check-input emp-checkbox" type="checkbox" name="employee_ids[]" value="<?= $emp['employee_id'] ?>" id="emp_<?= $emp['employee_id'] ?>" onchange="updateCount()">
                    <label class="form-check-label d-block small" for="emp_<?= $emp['employee_id'] ?>" style="cursor: pointer;">
                      <div class="fw-semibold text-dark"><?= htmlspecialchars($emp['full_name']) ?></div>
                      <div class="text-muted" style="font-size:0.75rem;">
                        <?= htmlspecialchars($emp['employee_code']) ?> · <?= htmlspecialchars($emp['designation'] ?? 'Staff') ?>
                        <?php if ($hasEmail): ?>
                          <span class="text-success ms-1"><i class="bi bi-envelope-fill"></i></span>
                        <?php else: ?>
                          <span class="text-danger ms-1" title="No email on profile"><i class="bi bi-envelope-x"></i></span>
                        <?php endif; ?>
                      </div>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div id="allBroadcastNotice" class="alert alert-info py-2 px-3 small mt-3" style="display: none;">
              <i class="bi bi-info-circle me-1"></i> Notification will be sent to <strong>all <?= count($employees) ?> active employees</strong> simultaneously.
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT COLUMN: MESSAGE COMPOSITION & CHANNELS -->
      <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-3">
          <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square text-success me-2"></i>2. Compose Message & Choose Channels</h6>
            <span class="badge bg-light text-primary border">Templates Available</span>
          </div>
          <div class="card-body p-3">
            <!-- Preset Template Selector -->
            <div class="mb-3">
              <label class="form-label small fw-semibold">Quick Template Presets (Auto-fills form)</label>
              <select id="templatePreset" class="form-select form-select-sm" onchange="applyTemplate()">
                <option value="custom">✏ Custom Message</option>
                <option value="att_reminder">⏰ Attendance Check-In Reminder</option>
                <option value="punchout_reminder">🚪 Punch-Out Reminder</option>
                <option value="work_report">📝 Daily Work Report Submission Reminder</option>
                <option value="meeting_notice">📅 Team Meeting / Review Notice</option>
                <option value="doc_submit">📄 Document / ID Submission Request</option>
                <option value="urgent_alert">🚨 Urgent Office Announcement</option>
              </select>
            </div>

            <!-- Subject / Title -->
            <div class="mb-3">
              <label class="form-label small fw-semibold">Subject / Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="notifTitle" class="form-control" placeholder="e.g. Friendly Reminder: Please mark your attendance" required>
            </div>

            <!-- Message Body -->
            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label small fw-semibold mb-0">Message Content <span class="text-danger">*</span></label>
                <div class="text-muted" style="font-size:0.7rem;">
                  Placeholders: <code>{{employee_name}}</code>, <code>{{date}}</code>, <code>{{company_name}}</code>
                </div>
              </div>
              <textarea name="message" id="notifMessage" rows="6" class="form-control" placeholder="Type your custom email or reminder message here..." required></textarea>
            </div>

            <!-- Optional Action Link -->
            <div class="mb-3">
              <label class="form-label small fw-semibold">Action Button Link (Optional)</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text bg-light"><i class="bi bi-link-45deg"></i></span>
                <input type="text" name="link" id="notifLink" class="form-control" placeholder="e.g. /employee/dashboard.php or /employee/work-reports.php">
              </div>
              <div class="form-text small" style="font-size:0.72rem;">Employees who receive this via email will see a button linking to this page. CRM notifications will open this link on click.</div>
            </div>

            <!-- Channels Selection -->
            <div class="mb-3">
              <label class="form-label small fw-semibold mb-2">Delivery Channels <span class="text-danger">*</span></label>
              <div class="d-flex flex-wrap gap-3 bg-light p-3 rounded-2 border">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="channels[]" value="email" id="chEmail" checked>
                  <label class="form-check-label small fw-semibold" for="chEmail"><i class="bi bi-envelope text-primary me-1"></i> Email</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="channels[]" value="crm" id="chCrm" checked>
                  <label class="form-check-label small fw-semibold" for="chCrm"><i class="bi bi-bell text-warning me-1"></i> CRM In-App</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="channels[]" value="web_push" id="chWeb">
                  <label class="form-check-label small fw-semibold" for="chWeb"><i class="bi bi-browser-chrome text-info me-1"></i> Web Push</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="channels[]" value="mobile_push" id="chMob">
                  <label class="form-check-label small fw-semibold" for="chMob"><i class="bi bi-phone text-dark me-1"></i> Mobile Push</label>
                </div>
              </div>
            </div>

            <!-- Hidden Notification Type -->
            <input type="hidden" name="notif_type" id="notifType" value="system_alert">

            <!-- Submit Button -->
            <div class="text-end mt-4">
              <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold shadow-sm" onclick="return confirmSend();">
                <i class="bi bi-send-check me-1"></i> Send Custom Email & Reminders
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
const templates = {
  custom: {
    type: 'system_alert',
    title: '',
    message: '',
    link: ''
  },
  att_reminder: {
    type: 'attendance_reminder',
    title: 'Attendance Reminder: Please Mark Attendance',
    message: 'Hello {{employee_name}},\n\nThis is a friendly reminder that your attendance has not yet been marked today ({{date}}).\n\nPlease clock in on the Employee Portal at your earliest convenience.',
    link: '/employee/dashboard.php'
  },
  punchout_reminder: {
    type: 'punchout_reminder',
    title: 'Reminder: Don\'t Forget to Punch Out Today',
    message: 'Hello {{employee_name}},\n\nPlease remember to punch out before leaving the office today so that your working hours are accurately calculated.',
    link: '/employee/attendance.php'
  },
  work_report: {
    type: 'task_assigned',
    title: 'Daily Evening Work Report Submission Reminder',
    message: 'Hello {{employee_name}},\n\nPlease submit your Daily Evening Work Report for {{date}} before concluding your shift.\n\nMake sure to mention the projects worked on and tasks completed.',
    link: '/employee/work-reports.php'
  },
  meeting_notice: {
    type: 'admin_announcement',
    title: 'Team Meeting Notification',
    message: 'Hello {{employee_name}},\n\nYou are requested to join an important team review meeting today at 04:30 PM in the conference hall / Google Meet.',
    link: '/employee/dashboard.php'
  },
  doc_submit: {
    type: 'system_alert',
    title: 'Action Required: Pending Document Submission',
    message: 'Hello {{employee_name}},\n\nOur HR records indicate that some of your verification documents or profile information are pending.\n\nPlease upload or verify your details in your profile section.',
    link: '/employee/profile.php'
  },
  urgent_alert: {
    type: 'admin_announcement',
    title: 'Urgent Announcement from Management',
    message: 'Hello {{employee_name}},\n\nPlease note this important company update: [Enter your announcement details here].\n\nFor any queries, please reach out to HR or Management.',
    link: '/employee/dashboard.php'
  }
};

function applyTemplate() {
  const sel = document.getElementById('templatePreset').value;
  const tpl = templates[sel];
  if (!tpl) return;

  if (sel !== 'custom') {
    document.getElementById('notifTitle').value = tpl.title;
    document.getElementById('notifMessage').value = tpl.message;
    document.getElementById('notifLink').value = tpl.link;
    document.getElementById('notifType').value = tpl.type;
  }
}

function toggleScope() {
  const isAll = document.getElementById('scopeAll').checked;
  document.getElementById('employeeSelectContainer').style.display = isAll ? 'none' : '';
  document.getElementById('allBroadcastNotice').style.display = isAll ? '' : 'none';
}

function filterEmployees() {
  const q = document.getElementById('empSearchInput').value.toLowerCase().trim();
  const items = document.querySelectorAll('.emp-item');
  items.forEach(it => {
    const text = it.getAttribute('data-search') || '';
    it.style.display = (q === '' || text.includes(q)) ? '' : 'none';
  });
}

function selectAll(state) {
  const visibleCheckboxes = document.querySelectorAll('.emp-item:not([style*="display: none"]) .emp-checkbox');
  visibleCheckboxes.forEach(cb => cb.checked = state);
  updateCount();
}

function updateCount() {
  const totalChecked = document.querySelectorAll('.emp-checkbox:checked').length;
  document.getElementById('selectedCounter').textContent = `${totalChecked} selected`;
}

function confirmSend() {
  const isAll = document.getElementById('scopeAll').checked;
  const count = isAll ? 'ALL active employees' : document.querySelectorAll('.emp-checkbox:checked').length + ' selected employee(s)';
  
  if (!isAll && document.querySelectorAll('.emp-checkbox:checked').length === 0) {
    alert('Please select at least one employee recipient.');
    return false;
  }

  return confirm(`Are you sure you want to send this custom notification to ${count}?`);
}

// Initial count
updateCount();
</script>
