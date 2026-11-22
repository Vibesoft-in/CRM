<?php
// modules/settings/notification_history.php — Admin Notification History & Audit Logs
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/layout.php';
require_admin();

$filterEmp = intval($_GET['emp_id'] ?? 0);
$filterType = trim($_GET['type'] ?? '');
$filterChannel = trim($_GET['channel'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterDate = trim($_GET['date'] ?? '');

$where = ["1=1"];
$params = [];

if ($filterEmp > 0) {
    $where[] = "l.user_id = ?";
    $params[] = $filterEmp;
}
if (!empty($filterType)) {
    $where[] = "n.type = ?";
    $params[] = $filterType;
}
if (!empty($filterChannel)) {
    $where[] = "l.channel = ?";
    $params[] = $filterChannel;
}
if (!empty($filterStatus)) {
    $where[] = "l.status = ?";
    $params[] = $filterStatus;
}
if (!empty($filterDate)) {
    $where[] = "DATE(l.sent_at) = ?";
    $params[] = $filterDate;
}

$whereClause = implode(" AND ", $where);

// Fetch logs
$stmt = $pdo->prepare("
    SELECT l.*, u.name as user_name, u.email as user_email,
           n.title as notif_title, n.message as notif_message, n.type as notif_type, n.link as notif_link
    FROM notification_logs l
    LEFT JOIN users u ON u.id = l.user_id
    LEFT JOIN notifications n ON n.id = l.notification_id
    WHERE {$whereClause}
    ORDER BY l.sent_at DESC
    LIMIT 100
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Fetch users for dropdown
$users = $pdo->query("SELECT id, name, email FROM users ORDER BY name ASC")->fetchAll();
?>

<div class="container-fluid py-3">
  <!-- Page Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
    <div>
      <h4 class="mb-1 text-dark fw-bold"><i class="bi bi-clock-history text-primary me-2"></i>Notification History</h4>
      <p class="text-muted small mb-0">System-wide audit trail of notifications sent across all channels (CRM, Email, Web Push, Mobile).</p>
    </div>
    <div class="d-flex gap-2">
      <a href="send_notification.php" class="btn btn-success btn-sm shadow-sm">
        <i class="bi bi-send-fill me-1"></i> Send Custom Email / Alert
      </a>
      <a href="notifications.php" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-gear me-1"></i> Notification Settings
      </a>
    </div>
  </div>

  <!-- Filters Card -->
  <div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-body p-3">
      <form method="GET" action="" class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label small fw-semibold">Employee / User</label>
          <select name="emp_id" class="form-select form-select-sm">
            <option value="">All Users</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= $u['id'] ?>" <?= $filterEmp === (int)$u['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['email']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small fw-semibold">Channel</label>
          <select name="channel" class="form-select form-select-sm">
            <option value="">All Channels</option>
            <option value="crm" <?= $filterChannel === 'crm' ? 'selected' : '' ?>>CRM In-App</option>
            <option value="email" <?= $filterChannel === 'email' ? 'selected' : '' ?>>Email</option>
            <option value="web_push" <?= $filterChannel === 'web_push' ? 'selected' : '' ?>>Web Push</option>
            <option value="mobile_push" <?= $filterChannel === 'mobile_push' ? 'selected' : '' ?>>Mobile Push</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small fw-semibold">Status</label>
          <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            <option value="success" <?= $filterStatus === 'success' ? 'selected' : '' ?>>Success</option>
            <option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>Failed</option>
            <option value="skipped" <?= $filterStatus === 'skipped' ? 'selected' : '' ?>>Skipped</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small fw-semibold">Date</label>
          <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterDate) ?>">
        </div>
        <div class="col-md-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-filter me-1"></i> Filter</button>
          <a href="notification_history.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Logs Table -->
  <div class="card border-0 shadow-sm rounded-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Time</th>
            <th>Recipient</th>
            <th>Channel</th>
            <th>Notification Details</th>
            <th>Status</th>
            <th>Response / Error</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($logs)): ?>
            <tr>
              <td colspan="6" class="text-center py-5 text-muted">
                <i class="bi bi-bell-slash fs-1 d-block mb-2"></i>
                No notification logs found matching your filters.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($logs as $log): ?>
              <?php
                $statusBadge = 'bg-secondary';
                if ($log['status'] === 'success') $statusBadge = 'bg-success';
                elseif ($log['status'] === 'failed') $statusBadge = 'bg-danger';
                elseif ($log['status'] === 'skipped') $statusBadge = 'bg-warning text-dark';

                $channelBadge = 'bg-light text-dark border';
                if ($log['channel'] === 'email') $channelBadge = 'bg-info-subtle text-info-emphasis border';
                elseif ($log['channel'] === 'crm') $channelBadge = 'bg-primary-subtle text-primary border';
                elseif ($log['channel'] === 'web_push') $channelBadge = 'bg-warning-subtle text-warning-emphasis border';
                elseif ($log['channel'] === 'mobile_push') $channelBadge = 'bg-dark-subtle text-dark border';
              ?>
              <tr>
                <td class="small text-nowrap">
                  <div class="fw-medium text-dark"><?= date('d M Y', strtotime($log['sent_at'])) ?></div>
                  <div class="text-muted" style="font-size:0.75rem;"><?= date('h:i:s A', strtotime($log['sent_at'])) ?></div>
                </td>
                <td>
                  <div class="fw-semibold text-dark"><?= htmlspecialchars($log['user_name'] ?? 'User #' . $log['user_id']) ?></div>
                  <div class="text-muted small"><?= htmlspecialchars($log['user_email'] ?? '') ?></div>
                </td>
                <td>
                  <span class="badge <?= $channelBadge ?> px-2 py-1 text-uppercase" style="font-size:0.7rem;">
                    <?= htmlspecialchars($log['channel']) ?>
                  </span>
                </td>
                <td>
                  <div class="fw-semibold text-dark small"><?= htmlspecialchars($log['notif_title'] ?? 'Notification') ?></div>
                  <div class="text-muted small text-truncate" style="max-width:320px;" title="<?= htmlspecialchars($log['notif_message'] ?? '') ?>">
                    <?= htmlspecialchars($log['notif_message'] ?? '') ?>
                  </div>
                </td>
                <td>
                  <span class="badge <?= $statusBadge ?> px-2 py-1 text-capitalize">
                    <?= htmlspecialchars($log['status']) ?>
                  </span>
                </td>
                <td class="small">
                  <?php if (!empty($log['error_message'])): ?>
                    <span class="text-danger" title="<?= htmlspecialchars($log['error_message']) ?>">
                      <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars(substr($log['error_message'], 0, 45)) ?><?= strlen($log['error_message']) > 45 ? '...' : '' ?>
                    </span>
                  <?php elseif (!empty($log['response'])): ?>
                    <span class="text-muted text-truncate d-inline-block" style="max-width:200px;" title="<?= htmlspecialchars($log['response']) ?>">
                      <?= htmlspecialchars($log['response']) ?>
                    </span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
