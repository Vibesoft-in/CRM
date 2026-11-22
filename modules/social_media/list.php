<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/vault_helper.php';
require_login();

// Platform icon helper
function platformIcon($platform) {
    $icons = [
        'facebook' => 'bi-facebook text-primary',
        'instagram' => 'bi-instagram text-danger',
        'twitter' => 'bi-twitter-x text-dark',
        'linkedin' => 'bi-linkedin text-primary',
        'youtube' => 'bi-youtube text-danger',
        'tiktok' => 'bi-tiktok text-dark',
        'google business' => 'bi-google text-success',
        'meta ads' => 'bi-meta text-primary'
    ];
    $key = strtolower($platform);
    return $icons[$key] ?? 'bi-globe text-primary';
}

$search = trim($_GET['search_customer_name'] ?? '');

$where = ["c.platform IN ('Social Media','Instagram','Facebook','LinkedIn','YouTube','Google Business','Meta Ads')"];
$params = [];

if ($search !== '') {
    $where[] = "(cust.name LIKE ? OR c.account_name LIKE ? OR c.username_email LIKE ?)";
    $term = "%$search%";
    $params = [$term, $term, $term];
}

$whereSql = implode(" AND ", $where);
$stmt = $pdo->prepare("
    SELECT c.*, cust.name AS customer_name
    FROM credentials c
    JOIN customers cust ON cust.id = c.customer_id
    WHERE $whereSql
    ORDER BY cust.name ASC, c.platform ASC
");
$stmt->execute($params);
$socialAccounts = $stmt->fetchAll();
?>

<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-share text-primary me-2"></i>Client Social Media Vault</h4>
      <p class="text-muted small mb-0">Social media account credentials securely integrated with the AES-256 Vault.</p>
    </div>
    <a href="../credentials/index.php" class="btn btn-primary shadow-sm fw-semibold">
      <i class="bi bi-shield-lock me-1"></i> Open Full Credential Vault
    </a>
  </div>

  <!-- Search Bar -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
    <div class="card-body p-3">
      <form method="get" class="row g-2 align-items-center">
        <div class="col-md-9">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" name="search_customer_name" class="form-control border-start-0 ps-0" placeholder="Search by client name, platform, handle..." value="<?= e($search) ?>">
          </div>
        </div>
        <div class="col-md-3 d-flex gap-1">
          <button class="btn btn-sm btn-primary w-100"><i class="bi bi-search me-1"></i> Search Accounts</button>
          <a href="list.php" class="btn btn-sm btn-light border"><i class="bi bi-x-circle"></i></a>
        </div>
      </form>
    </div>
  </div>

  <!-- Cards Grid -->
  <?php if ($socialAccounts): ?>
    <div class="row g-4">
      <?php foreach ($socialAccounts as $acc): ?>
        <div class="col-md-6 col-lg-4">
          <div class="card border-0 shadow-sm h-100" style="border-radius: 14px;">
            <div class="card-body p-4">
              <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center">
                  <i class="bi <?= platformIcon($acc['platform']) ?> fs-2 me-3"></i>
                  <div>
                    <h6 class="fw-bold text-dark mb-0"><?= e($acc['platform']) ?></h6>
                    <small class="text-muted"><?= e($acc['account_name']) ?></small>
                  </div>
                </div>
                <?php if ($acc['two_factor_enabled']): ?>
                  <span class="badge bg-success-subtle text-success border border-success-subtle small">2FA</span>
                <?php endif; ?>
              </div>

              <div class="p-3 bg-light rounded-3 mb-3">
                <small class="text-muted d-block uppercase" style="font-size: 0.7rem;">CLIENT</small>
                <a href="../customers/view.php?id=<?= $acc['customer_id'] ?>" class="fw-bold text-dark text-decoration-none">
                  <?= e($acc['customer_name']) ?>
                </a>

                <small class="text-muted d-block uppercase mt-2" style="font-size: 0.7rem;">USERNAME / EMAIL</small>
                <code class="text-dark fw-semibold"><?= e($acc['username_email'] ?: 'N/A') ?></code>
              </div>

              <div class="d-flex justify-content-between align-items-center">
                <?php if ($acc['login_url']): ?>
                  <a href="<?= e($acc['login_url']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Open Page
                  </a>
                <?php else: ?>
                  <span></span>
                <?php endif; ?>

                <a href="../credentials/index.php?q=<?= urlencode($acc['customer_name']) ?>" class="btn btn-sm btn-outline-danger fw-semibold">
                  <i class="bi bi-key me-1"></i> View Vault Password
                </a>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="card border-0 shadow-sm text-center py-5" style="border-radius: 14px;">
      <div class="card-body">
        <i class="bi bi-share fs-1 text-muted d-block mb-2"></i>
        <h5 class="fw-bold text-dark">No Social Accounts Found</h5>
        <p class="text-muted small">No social media accounts stored in vault for the specified search.</p>
        <a href="../credentials/index.php" class="btn btn-sm btn-primary">Add Social Account to Vault</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
