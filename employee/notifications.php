<?php
// employee/notifications.php — Employee Notifications Center
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/layout.php';

use App\Notifications\NotificationService;
use App\Notifications\NotificationTemplate;

$service = NotificationService::getInstance($pdo);
$userId = (int)($_SESSION['user_id'] ?? 0);
$csrf = csrf_token();

// Handle Mark All Read action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    if (verify_csrf($_POST['csrf'] ?? '')) {
        $service->markAllAsRead($userId);
        header('Location: notifications.php');
        exit;
    }
}

// Single mark read via GET
if (isset($_GET['read_id'])) {
    $rId = intval($_GET['read_id']);
    $service->markAsRead($rId, $userId);
    header('Location: notifications.php');
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$where = "user_id = ?";
$params = [$userId];
if ($filter === 'unread') {
    $where .= " AND is_read = 0";
}

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE {$where} ORDER BY created_at DESC LIMIT 100");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

$unreadCount = $service->getUnreadCount($userId);
?>

<div class="container-fluid py-3">
  <!-- Page Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
    <div>
      <h4 class="mb-1 text-dark fw-bold"><i class="bi bi-bell-fill text-primary me-2"></i>My Notifications</h4>
      <p class="text-muted small mb-0">Stay informed about attendance reminders, work reports, leave updates, and company announcements.</p>
    </div>
    <div class="d-flex gap-2 mt-2 mt-sm-0">
      <?php if ($unreadCount > 0): ?>
        <form method="POST" action="" class="d-inline">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="mark_all_read" value="1">
          <button type="submit" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-check2-all me-1"></i> Mark All as Read (<?= $unreadCount ?>)
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Filter Pills -->
  <div class="d-flex gap-2 mb-3">
    <a href="notifications.php?filter=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-light border' ?>">
      All Notifications
    </a>
    <a href="notifications.php?filter=unread" class="btn btn-sm <?= $filter === 'unread' ? 'btn-primary' : 'btn-light border' ?>">
      Unread <?php if ($unreadCount > 0): ?><span class="badge bg-danger ms-1"><?= $unreadCount ?></span><?php endif; ?>
    </a>
  </div>

  <!-- Notifications List -->
  <div class="card border-0 shadow-sm rounded-3 overflow-hidden">
    <?php if (empty($notifications)): ?>
      <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-bell-slash fs-1 d-block mb-3 text-secondary"></i>
        <h6 class="fw-semibold">No notifications found</h6>
        <p class="small mb-0">You're all caught up! When you receive reminders or alerts, they'll appear here.</p>
      </div>
    <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($notifications as $n): ?>
          <?php
            $isUnread = ((int)$n['is_read'] === 0);
            $tpl = NotificationTemplate::render($n['type']);
            $icon = $tpl['icon'] ?? 'bi-bell text-primary';
            $link = !empty($n['link']) ? (BASE_URL . ltrim($n['link'], '/')) : null;
          ?>
          <div class="list-group-item p-3 d-flex align-items-start gap-3 <?= $isUnread ? 'bg-light bg-opacity-75' : '' ?>">
            <div class="fs-4 mt-1">
              <i class="bi <?= $icon ?>"></i>
            </div>
            <div class="flex-grow-1">
              <div class="d-flex flex-wrap justify-content-between align-items-center mb-1 gap-1">
                <h6 class="mb-0 fw-semibold text-dark <?= $isUnread ? 'fw-bold' : '' ?>">
                  <?= htmlspecialchars($n['title']) ?>
                  <?php if ($isUnread): ?>
                    <span class="badge bg-danger rounded-pill ms-2" style="font-size:0.65rem;">New</span>
                  <?php endif; ?>
                </h6>
                <small class="text-muted" style="font-size:0.72rem;"><?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></small>
              </div>
              <p class="text-muted small mb-2" style="white-space: pre-line;"><?= htmlspecialchars($n['message']) ?></p>
              
              <div class="d-flex gap-2 align-items-center">
                <?php if ($link): ?>
                  <a href="<?= htmlspecialchars($link) ?>" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.75rem;">
                    View Details <i class="bi bi-arrow-right ms-1"></i>
                  </a>
                <?php endif; ?>
                <?php if ($isUnread): ?>
                  <a href="notifications.php?read_id=<?= $n['id'] ?>" class="btn btn-sm btn-link text-decoration-none text-muted py-0 px-1" style="font-size:0.75rem;">
                    Mark as read
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
