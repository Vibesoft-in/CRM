<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/vault_helper.php';
require_login();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) die("Invalid Credential ID");

$stmt = $pdo->prepare("SELECT c.*, cust.name AS client_name FROM credentials c JOIN customers cust ON cust.id = c.customer_id WHERE c.id = ?");
$stmt->execute([$id]);
$cred = $stmt->fetch();
if (!$cred) die("Credential not found");

$histStmt = $pdo->prepare("
    SELECT h.*, u.name AS user_name
    FROM credential_password_history h
    LEFT JOIN users u ON u.id = h.changed_by
    WHERE h.credential_id = ?
    ORDER BY h.created_at DESC
");
$histStmt->execute([$id]);
$history = $histStmt->fetchAll();
?>

<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-clock-history text-primary me-2"></i>Password Change History</h4>
      <p class="text-muted small mb-0">Audit history for <?= e($cred['client_name']) ?> &bull; <?= e($cred['platform']) ?> (<?= e($cred['account_name']) ?>)</p>
    </div>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Vault</a>
  </div>

  <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="bg-light text-muted small uppercase">
          <tr>
            <th class="ps-3">Date & Time</th>
            <th>Changed By</th>
            <th>Reason / Note</th>
            <th class="pe-3">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($history): ?>
            <?php foreach ($history as $h): ?>
              <tr>
                <td class="ps-3 fw-medium"><?= e($h['created_at']) ?></td>
                <td><span class="fw-semibold text-dark"><?= e($h['user_name'] ?: 'System/Admin') ?></span></td>
                <td><?= e($h['change_reason'] ?: 'Routine Security Update') ?></td>
                <td class="pe-3"><span class="badge bg-secondary"><i class="bi bi-lock-fill me-1"></i>Encrypted in History</span></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="4" class="text-center py-4 text-muted">No previous password changes recorded for this credential.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>
