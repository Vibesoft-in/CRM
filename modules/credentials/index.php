<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_once '../../includes/vault_helper.php';
require_once '../../includes/audit_helper.php';
require_login();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_credential') {
        if (!verify_csrf($_POST['csrf'] ?? '')) {
            $err = 'Invalid security token';
        } else {
            $id                   = (int)($_POST['credential_id'] ?? 0);
            $customer_id          = (int)$_POST['customer_id'];
            $platform             = $_POST['platform'];
            $account_name         = trim($_POST['account_name']);
            $username_email       = trim($_POST['username_email']);
            $raw_password         = trim($_POST['password']);
            $login_url            = trim($_POST['login_url']);
            $account_id           = trim($_POST['account_id']);
            $two_factor_enabled   = isset($_POST['two_factor_enabled']) ? 1 : 0;
            $two_factor_info      = trim($_POST['two_factor_info']);
            $recovery_email       = trim($_POST['recovery_email']);
            $recovery_phone       = trim($_POST['recovery_phone']);
            $notes                = trim($_POST['notes']);
            $change_reason        = trim($_POST['change_reason'] ?: 'Routine update');

            // Process dynamic custom fields
            $custom_names = $_POST['custom_field_name'] ?? [];
            $custom_vals  = $_POST['custom_field_value'] ?? [];
            $custom_fields = [];
            if (is_array($custom_names)) {
                for ($i = 0; $i < count($custom_names); $i++) {
                    $fn = trim($custom_names[$i] ?? '');
                    $fv = trim($custom_vals[$i] ?? '');
                    if (!empty($fn)) {
                        $custom_fields[] = ['name' => $fn, 'value' => $fv];
                    }
                }
            }
            $custom_fields_json = !empty($custom_fields) ? json_encode($custom_fields, JSON_UNESCAPED_UNICODE) : null;
            
            if ($customer_id <= 0 || empty($platform) || empty($account_name)) {
                $err = 'Client, Platform, and Account Name are required.';
            } else {
                if ($id > 0) {
                    // Fetch existing to check if password changed
                    $stmtCurr = $pdo->prepare("SELECT encrypted_password FROM credentials WHERE id = ?");
                    $stmtCurr->execute([$id]);
                    $curr = $stmtCurr->fetch();
                    
                    $encPass = $curr['encrypted_password'];
                    if (!empty($raw_password)) {
                        $encPass = encrypt_credential($raw_password);
                        
                        // Log to password history
                        $insHist = $pdo->prepare("INSERT INTO credential_password_history (credential_id, encrypted_password, changed_by, change_reason, created_at) VALUES (?, ?, ?, ?, NOW())");
                        $insHist->execute([$id, $curr['encrypted_password'], $_SESSION['user_id'], $change_reason]);
                    }
                    
                    $upd = $pdo->prepare("UPDATE credentials SET customer_id=?, platform=?, account_name=?, username_email=?, encrypted_password=?, login_url=?, account_id=?, two_factor_enabled=?, two_factor_info=?, recovery_email=?, recovery_phone=?, notes=?, custom_fields=? WHERE id=?");
                    $upd->execute([$customer_id, $platform, $account_name, $username_email, $encPass, $login_url, $account_id, $two_factor_enabled, $two_factor_info, $recovery_email, $recovery_phone, $notes, $custom_fields_json, $id]);
                    
                    log_audit('Credential Vault', 'Updated Credential', $id, ['platform' => $platform, 'account' => $account_name]);
                    set_flash('Credential updated securely!', 'success');
                } else {
                    if (empty($raw_password)) {
                        $err = 'Password is required for new credential.';
                    } else {
                        $encPass = encrypt_credential($raw_password);
                        $ins = $pdo->prepare("INSERT INTO credentials (customer_id, platform, account_name, username_email, encrypted_password, login_url, account_id, two_factor_enabled, two_factor_info, recovery_email, recovery_phone, notes, custom_fields, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $ins->execute([$customer_id, $platform, $account_name, $username_email, $encPass, $login_url, $account_id, $two_factor_enabled, $two_factor_info, $recovery_email, $recovery_phone, $notes, $custom_fields_json]);
                        $newId = $pdo->lastInsertId();
                        
                        log_audit('Credential Vault', 'Created Credential', $newId, ['platform' => $platform, 'account' => $account_name]);
                        set_flash('Credential added to Vault with AES-256 encryption!', 'success');
                    }
                }
                if (!$err) {
                    header('Location: index.php');
                    exit;
                }
            }
        }
    }

    if ($_POST['action'] === 'delete_credential') {
        if (!verify_csrf($_POST['csrf'] ?? '')) {
            $err = 'Invalid security token';
        } else {
            $delId = (int)($_POST['id'] ?? 0);
            $curr = $pdo->query("SELECT platform, account_name FROM credentials WHERE id = {$delId}")->fetch();
            if ($curr) {
                $pdo->prepare("DELETE FROM credentials WHERE id = ?")->execute([$delId]);
                log_audit('Credential Vault', 'Deleted Credential', $delId, ['platform' => $curr['platform'], 'account' => $curr['account_name']]);
                set_flash('Credential deleted securely from Vault.', 'success');
            }
            header('Location: index.php');
            exit;
        }
    }
}

// Global Credential Search
$search = trim($_GET['q'] ?? '');
$customer_id = (int)($_GET['customer_id'] ?? 0);

$where = ["1=1"];
$params = [];

if ($customer_id > 0) {
    $where[] = "c.customer_id = ?";
    $params[] = $customer_id;
}

if ($search !== '') {
    $where[] = "(cust.name LIKE ? OR c.platform LIKE ? OR c.account_name LIKE ? OR c.username_email LIKE ? OR c.login_url LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

$whereSql = implode(" AND ", $where);
$stmt = $pdo->prepare("
    SELECT c.*, cust.name AS client_name
    FROM credentials c
    JOIN customers cust ON cust.id = c.customer_id
    WHERE $whereSql
    ORDER BY cust.name ASC, c.platform ASC
");
$stmt->execute($params);
$credentials = $stmt->fetchAll();

$customers = $pdo->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name ASC")->fetchAll();
?>

<style>
  #credentialModal .modal-dialog,
  #viewDetailsModal .modal-dialog {
    max-height: calc(100vh - 2rem);
    margin: 1rem auto;
  }
  #credentialModal .modal-content,
  #viewDetailsModal .modal-content {
    max-height: calc(100vh - 2.5rem);
    display: flex !important;
    flex-direction: column !important;
  }
  #credentialModal .modal-body,
  #viewDetailsModal .modal-body {
    overflow-y: auto !important;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: rgba(0, 0, 0, 0.25) transparent;
  }
  #credentialModal .modal-body::-webkit-scrollbar,
  #viewDetailsModal .modal-body::-webkit-scrollbar {
    width: 6px;
  }
  #credentialModal .modal-body::-webkit-scrollbar-thumb,
  #viewDetailsModal .modal-body::-webkit-scrollbar-thumb {
    background-color: rgba(0, 0, 0, 0.25);
    border-radius: 4px;
  }
</style>

<div class="container-fluid">
  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
      <h4 class="fw-bold text-dark mb-1"><i class="bi bi-shield-lock text-primary me-2"></i>Client Credential Vault</h4>
      <p class="text-muted small mb-0">AES-256-GCM encrypted password vault with role permission controls and audit logging.</p>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary shadow-sm fw-semibold" onclick="openAddCredentialModal()">
        <i class="bi bi-plus-circle me-1"></i> Add Credential
      </button>
    </div>
  </div>

  <?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm border-0" role="alert" style="border-radius: 12px;">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Search Card -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
    <div class="card-body p-3">
      <form method="get" class="row g-2 align-items-center">
        <div class="col-md-6">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" name="q" class="form-control border-start-0 ps-0 shadow-none" placeholder="Search client, platform, email, account, login URL, account ID..." value="<?= e($search) ?>">
          </div>
        </div>
        <div class="col-md-4">
          <select name="customer_id" class="form-select form-select-sm shadow-none" onchange="this.form.submit()">
            <option value="">All Clients</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= $c['id'] ?>" <?= $customer_id==$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
          <button class="btn btn-sm btn-primary w-100 shadow-none"><i class="bi bi-search me-1"></i> Search</button>
          <a href="index.php" class="btn btn-sm btn-light border shadow-none"><i class="bi bi-x-circle"></i></a>
        </div>
      </form>
    </div>
  </div>

  <!-- Credentials Table -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; overflow: hidden;">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" style="font-size:0.88rem;">
        <thead class="bg-light text-muted small uppercase">
          <tr>
            <th class="ps-4">Client</th>
            <th>Platform & Account</th>
            <th>Username / Email</th>
            <th>Login URL</th>
            <th>2FA & Security</th>
            <th>Details & Attributes</th>
            <th class="text-end pe-4">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($credentials): ?>
            <?php foreach ($credentials as $cred): 
              $customFieldsList = json_decode($cred['custom_fields'] ?? '[]', true) ?: [];
            ?>
              <tr>
                <td class="ps-4">
                  <a href="../customers/view.php?id=<?= $cred['customer_id'] ?>" class="fw-bold text-dark text-decoration-none">
                    <i class="bi bi-building me-1 text-muted"></i><?= e($cred['client_name']) ?>
                  </a>
                </td>
                <td>
                  <div class="d-flex align-items-center gap-1 mb-1">
                    <span class="badge bg-light text-dark border"><i class="bi bi-globe me-1 text-primary"></i><?= e($cred['platform']) ?></span>
                    <strong class="text-dark"><?= e($cred['account_name']) ?></strong>
                  </div>
                  <?php if (!empty($cred['account_id'])): ?>
                    <div><span class="badge bg-light text-secondary border" style="font-size:0.7rem;"><i class="bi bi-tag me-1"></i>ID: <?= e($cred['account_id']) ?></span></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($cred['username_email'])): ?>
                    <code class="text-dark bg-light px-2 py-1 rounded border"><?= e($cred['username_email']) ?></code>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($cred['login_url']): ?>
                    <a href="<?= e($cred['login_url']) ?>" target="_blank" class="text-decoration-none small text-primary d-inline-flex align-items-center">
                      <i class="bi bi-box-arrow-up-right me-1"></i>Open Login
                    </a>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div>
                    <?php if ($cred['two_factor_enabled']): ?>
                      <span class="badge bg-success-subtle text-success border border-success-subtle py-1"><i class="bi bi-shield-check me-1"></i>2FA Active</span>
                    <?php else: ?>
                      <span class="badge bg-secondary-subtle text-secondary py-1">2FA Off</span>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($cred['recovery_email']) || !empty($cred['recovery_phone'])): ?>
                    <div class="text-muted small mt-1" style="font-size:0.74rem;">
                      <i class="bi bi-shield-shaded me-1 text-muted"></i><?= e($cred['recovery_email'] ?: $cred['recovery_phone']) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="d-flex flex-wrap gap-1 align-items-center">
                    <?php if (!empty($cred['notes'])): ?>
                      <span class="badge bg-light text-muted border" title="<?= e($cred['notes']) ?>"><i class="bi bi-card-text me-1"></i>Notes</span>
                    <?php endif; ?>
                    <?php if (count($customFieldsList) > 0): ?>
                      <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= count($customFieldsList) ?> Custom Fields</span>
                    <?php endif; ?>
                    <?php if (empty($cred['notes']) && count($customFieldsList) === 0): ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="text-end pe-4">
                  <!-- Action buttons with icons only (no text) -->
                  <div class="dropdown">
                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <li>
                        <button class="dropdown-item" 
                                type="button"
                                data-cred='<?= htmlspecialchars(json_encode($cred, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>'
                                onclick="viewVaultDetailsFromBtn(this)">
                          <i class="bi bi-info-circle text-info me-2"></i> View Full Details
                        </button>
                      </li>
                      <li>
                        <button class="dropdown-item" 
                                type="button"
                                data-cred='<?= htmlspecialchars(json_encode($cred, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>'
                                onclick="openEditCredentialModalFromBtn(this)">
                          <i class="bi bi-pencil-square text-primary me-2"></i> Edit Credential
                        </button>
                      </li>
                      <li>
                        <button class="dropdown-item" 
                                type="button"
                                onclick="revealVaultPassword(<?= $cred['id'] ?>, '<?= e(addslashes($cred['account_name'])) ?>')">
                          <i class="bi bi-eye text-warning me-2"></i> Reveal Password
                        </button>
                      </li>
                      <li>
                        <a href="history.php?id=<?= $cred['id'] ?>" class="dropdown-item">
                          <i class="bi bi-clock-history text-secondary me-2"></i> Password History
                        </a>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <button class="dropdown-item text-danger" 
                                type="button"
                                onclick="confirmDeleteCredential(<?= $cred['id'] ?>, '<?= e(addslashes($cred['account_name'])) ?>')">
                          <i class="bi bi-trash me-2"></i> Delete Credential
                        </button>
                      </li>
                    </ul>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="text-center py-5 text-muted">
                <i class="bi bi-shield-lock fs-1 d-block mb-2 text-muted"></i>
                No credentials stored in vault matching your criteria.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: Add / Edit Credential -->
<div class="modal fade" id="credentialModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <form method="post" id="credentialForm" class="modal-content border-0 shadow" style="border-radius: 16px; max-height: calc(100vh - 3.5rem); display: flex; flex-direction: column;">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save_credential">
      <input type="hidden" name="credential_id" id="modalCredId" value="0">
      
      <div class="modal-header border-bottom py-3 px-4 bg-white" style="border-top-left-radius: 16px; border-top-right-radius: 16px; flex-shrink: 0;">
        <h5 class="modal-title fw-bold" id="modalTitle">
          <i class="bi bi-shield-lock text-primary me-2"></i><span id="modalTitleText">Add Encrypted Credential</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      
      <div class="modal-body p-4" style="overflow-y: auto; max-height: calc(85vh - 140px); flex: 1 1 auto;">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-medium">Select Client <span class="text-danger">*</span></label>
            <select name="customer_id" id="credCustomerId" class="form-select shadow-none" required>
              <option value="">-- Select Client --</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $customer_id==$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Platform Category <span class="text-danger">*</span></label>
            <select name="platform" id="credPlatform" class="form-select shadow-none" required>
              <option value="Social Media">Social Media</option>
              <option value="Website">Website</option>
              <option value="Hosting">Hosting / CPanel</option>
              <option value="Domain">Domain Registrar</option>
              <option value="Email">Email Account</option>
              <option value="Google Business">Google Business Profile</option>
              <option value="Google Ads">Google Ads</option>
              <option value="Meta Ads">Meta Ads Manager</option>
              <option value="Instagram">Instagram</option>
              <option value="Facebook">Facebook Page</option>
              <option value="LinkedIn">LinkedIn</option>
              <option value="YouTube">YouTube</option>
              <option value="WordPress">WordPress Admin</option>
              <option value="Shopify">Shopify Store</option>
              <option value="Other">Other Platform</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Account Name <span class="text-danger">*</span></label>
            <input type="text" name="account_name" id="credAccountName" class="form-control shadow-none" placeholder="e.g. Production CPanel, Main AWS Console, Official Instagram" required>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Username / Login Email</label>
            <input type="text" name="username_email" id="credUsernameEmail" class="form-control shadow-none" placeholder="e.g. admin@vibesoft.in or brand_handle">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-medium">Account / Project ID</label>
            <input type="text" name="account_id" id="credAccountId" class="form-control shadow-none" placeholder="e.g. ACC-1092, AWS Account ID, Subscription ID">
          </div>
          <div class="col-md-8">
            <label class="form-label fw-medium">Login URL</label>
            <input type="text" name="login_url" id="credLoginUrl" class="form-control shadow-none" placeholder="e.g. https://cpanel.domain.com:2083 or www.domain.com/login">
          </div>
          <div class="col-md-12">
            <label class="form-label fw-medium">Password <span class="text-danger" id="passwordRequiredStar">*</span></label>
            <div class="input-group">
              <input type="text" name="password" id="vaultPasswordInput" class="form-control shadow-none" placeholder="Enter secure password (or click generate)" required>
              <button type="button" class="btn btn-outline-secondary shadow-none" onclick="generateVaultPassword()">
                <i class="bi bi-shuffle me-1"></i>Generate
              </button>
            </div>
            <small class="text-muted" id="passwordHelpNote" style="display:none;"><i class="bi bi-info-circle me-1"></i>Leave blank if you do not wish to change the existing encrypted password.</small>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Recovery Email</label>
            <input type="email" name="recovery_email" id="credRecoveryEmail" class="form-control shadow-none" placeholder="e.g. backup@vibesoft.in">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Recovery Phone</label>
            <input type="text" name="recovery_phone" id="credRecoveryPhone" class="form-control shadow-none" placeholder="e.g. +91 98765 43210">
          </div>

          <!-- 2FA Security & Add Custom Field Button right next to it -->
          <div class="col-md-12">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" name="two_factor_enabled" value="1" id="twoFactor">
                <label class="form-check-label fw-semibold" for="twoFactor">
                  <i class="bi bi-shield-check text-success me-1"></i>2FA Security Enabled
                </label>
              </div>
              <button type="button" class="btn btn-sm btn-outline-primary shadow-none" onclick="addCustomFieldRow()">
                <i class="bi bi-plus-circle me-1"></i> Add Custom Field
              </button>
            </div>
            <input type="text" name="two_factor_info" id="credTwoFactorInfo" class="form-control shadow-none" placeholder="e.g. Google Authenticator, SMS to +91..., Backup codes in secure note...">
          </div>

          <!-- Custom Fields Dynamic Container -->
          <div class="col-md-12">
            <div id="customFieldsHeader" style="display:none;" class="mb-1">
              <label class="form-label small fw-semibold text-muted mb-0">
                <i class="bi bi-card-checklist me-1 text-primary"></i>Custom Key-Value Attributes
              </label>
            </div>
            <div id="customFieldsContainer" class="d-flex flex-column gap-2"></div>
          </div>

          <div class="col-md-12">
            <label class="form-label fw-medium">Vault Notes / Security Instructions</label>
            <textarea name="notes" id="credNotes" class="form-control shadow-none" rows="2" placeholder="Security guidelines, secret PINs, API restrictions, port numbers..."></textarea>
          </div>

          <div class="col-md-12" id="changeReasonGroup" style="display:none;">
            <label class="form-label fw-medium small text-muted">Password Change Reason (Security Audit)</label>
            <input type="text" name="change_reason" id="credChangeReason" class="form-control form-control-sm shadow-none" placeholder="e.g. Scheduled rotation, employee offboarding, security patch">
          </div>
        </div>
      </div>

      <div class="modal-footer border-top bg-light bg-opacity-75 py-3 px-4" style="border-bottom-left-radius: 16px; border-bottom-right-radius: 16px; flex-shrink: 0;">
        <button type="button" class="btn btn-light border px-3 shadow-none" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary fw-semibold px-4 shadow-none" id="modalSubmitBtn">
          <i class="bi bi-shield-check me-1"></i><span id="modalSubmitText">Save to Encrypted Vault</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: View Full Details -->
<div class="modal fade" id="viewDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow" style="border-radius: 16px; max-height: calc(100vh - 3.5rem); display: flex; flex-direction: column;">
      <div class="modal-header border-bottom py-3 px-4 bg-white" style="border-top-left-radius: 16px; border-top-right-radius: 16px; flex-shrink: 0;">
        <div>
          <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2">
            <i class="bi bi-shield-lock-fill text-primary"></i>
            <span id="viewDetailAccountName">Credential Details</span>
          </h5>
          <small class="text-muted" id="viewDetailClientSubtitle"></small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4" style="overflow-y: auto; max-height: calc(85vh - 140px); flex: 1 1 auto;">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="p-3 bg-light rounded-3 border">
              <small class="text-muted text-uppercase fw-semibold" style="font-size:0.7rem;">Client Organization</small>
              <div class="fw-bold text-dark fs-6" id="viewDetailClient">—</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="p-3 bg-light rounded-3 border">
              <small class="text-muted text-uppercase fw-semibold" style="font-size:0.7rem;">Platform Category</small>
              <div class="fw-bold text-dark fs-6" id="viewDetailPlatform">—</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="p-3 bg-light rounded-3 border">
              <small class="text-muted text-uppercase fw-semibold" style="font-size:0.7rem;">Account Name & ID</small>
              <div class="fw-bold text-dark" id="viewDetailAccountFull">—</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="p-3 bg-light rounded-3 border">
              <small class="text-muted text-uppercase fw-semibold" style="font-size:0.7rem;">Username / Login Email</small>
              <div class="d-flex align-items-center justify-content-between mt-1">
                <code class="text-dark fs-6 text-break" id="viewDetailUsername">—</code>
                <button type="button" class="btn btn-sm btn-outline-secondary p-1 px-2 ms-2" onclick="copyFromElement('viewDetailUsername')" title="Copy Username">
                  <i class="bi bi-copy"></i>
                </button>
              </div>
            </div>
          </div>
          <div class="col-md-12">
            <div class="p-3 bg-light rounded-3 border">
              <small class="text-muted text-uppercase fw-semibold" style="font-size:0.7rem;">Login Web Address (URL)</small>
              <div class="d-flex align-items-center justify-content-between mt-1">
                <div class="text-break" id="viewDetailUrlWrapper">—</div>
                <button type="button" class="btn btn-sm btn-outline-secondary p-1 px-2 ms-2" onclick="copyFromElement('viewDetailUrlText')" title="Copy URL">
                  <i class="bi bi-copy"></i>
                </button>
              </div>
            </div>
          </div>

          <!-- Password Row -->
          <div class="col-md-12">
            <div class="p-3 bg-light rounded-3 border">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <small class="text-muted text-uppercase fw-semibold" style="font-size:0.7rem;">Encrypted Password</small>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size:0.7rem;"><i class="bi bi-lock me-1"></i>AES-256</span>
              </div>
              <div class="d-flex align-items-center justify-content-between gap-2">
                <span class="font-monospace fs-5 fw-bold text-dark text-break" id="viewDetailPasswordMasked">••••••••••••••••</span>
                <div class="d-flex gap-1">
                  <button type="button" class="btn btn-sm btn-outline-danger px-3 shadow-none" id="viewDetailRevealBtn" onclick="revealFromDetailsModal()">
                    <i class="bi bi-eye me-1"></i>Reveal
                  </button>
                  <button type="button" class="btn btn-sm btn-primary px-3 shadow-none" id="viewDetailCopyBtn" style="display:none;" onclick="copyRevealedPasswordFromDetails()">
                    <i class="bi bi-copy me-1"></i>Copy
                  </button>
                </div>
              </div>
              <div class="text-muted small mt-1" id="viewDetailCountdownNote" style="display:none; font-size:0.75rem;">
                <i class="bi bi-clock me-1 text-warning"></i>Auto-masking in <span id="viewDetailSeconds" class="fw-bold text-danger">10</span>s. Access logged to audit history.
              </div>
            </div>
          </div>

          <!-- 2FA & Recovery -->
          <div class="col-md-6">
            <div class="p-3 bg-light rounded-3 border h-100">
              <small class="text-muted text-uppercase fw-semibold" style="font-size:0.7rem;">Two-Factor Authentication (2FA)</small>
              <div class="mt-1" id="viewDetail2FA">—</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="p-3 bg-light rounded-3 border h-100">
              <small class="text-muted text-uppercase fw-semibold" style="font-size:0.7rem;">Recovery Contact</small>
              <div class="mt-1" id="viewDetailRecovery">—</div>
            </div>
          </div>

          <!-- Custom Fields Section -->
          <div class="col-md-12" id="viewDetailCustomFieldsSection" style="display:none;">
            <div class="p-3 bg-light rounded-3 border">
              <small class="text-muted text-uppercase fw-semibold d-block mb-2" style="font-size:0.7rem;">
                <i class="bi bi-card-checklist me-1 text-primary"></i>Custom Additional Fields
              </small>
              <div class="row g-2" id="viewDetailCustomFieldsList"></div>
            </div>
          </div>

          <!-- Notes -->
          <div class="col-md-12">
            <div class="p-3 bg-light rounded-3 border">
              <small class="text-muted text-uppercase fw-semibold" style="font-size:0.7rem;">Vault Notes & Security Guidelines</small>
              <div class="mt-1 text-dark small text-break" id="viewDetailNotes" style="white-space: pre-wrap;">—</div>
            </div>
          </div>

          <!-- Timestamps -->
          <div class="col-md-12">
            <div class="d-flex justify-content-between text-muted" style="font-size:0.75rem;">
              <span id="viewDetailCreatedAt"></span>
              <span id="viewDetailUpdatedAt"></span>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-top bg-light bg-opacity-75 py-3 px-4" style="border-bottom-left-radius: 16px; border-bottom-right-radius: 16px; flex-shrink: 0;">
        <button type="button" class="btn btn-light border px-3 shadow-none" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary px-3 shadow-none" id="viewDetailEditBtn">
          <i class="bi bi-pencil-square me-1"></i> Edit This Credential
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Secure Reveal (Standalone) -->
<div class="modal fade" id="revealModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <div class="modal-header border-bottom-0 pb-0">
        <h5 class="modal-title fw-bold text-danger"><i class="bi bi-shield-exclamation me-2"></i>Revealed Credential Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center py-4">
        <p class="text-muted small mb-2" id="revealAccountTitle"></p>
        
        <div class="p-3 bg-light rounded-3 border position-relative mb-3">
          <span class="fs-4 fw-bold font-monospace text-dark d-block text-break" id="revealedPasswordText">******</span>
          <button class="btn btn-sm btn-primary position-absolute top-50 end-0 translate-middle-y me-2 shadow-none" onclick="copyRevealedPassword()"><i class="bi bi-copy"></i> Copy</button>
        </div>
        
        <div class="alert alert-warning py-2 mb-0 small border-0">
          <i class="bi bi-clock me-1"></i> Auto-hiding in <span id="revealCountdown" class="fw-bold text-danger">10</span> seconds. Access logged to audit history.
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Delete Confirmation -->
<div class="modal fade" id="deleteCredentialModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow" style="border-radius: 16px;">
      <div class="modal-body p-4 text-center">
        <div class="text-danger mb-3"><i class="bi bi-exclamation-triangle fs-1"></i></div>
        <h5 class="fw-bold mb-2">Delete Credential?</h5>
        <p class="text-muted small mb-4" id="deleteCredMsg"></p>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="delete_credential">
          <input type="hidden" name="id" id="deleteCredId" value="">
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-light w-50 py-2 shadow-none" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger w-50 py-2 fw-semibold shadow-none">Delete</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
let countdownTimer;
let detailsCountdownTimer;
let currentDetailCred = null;
let currentDetailPassword = '';

// Generate password helper
function generateVaultPassword() {
  $.get('ajax_vault.php?action=generate_password', function(res) {
    if (res.success) {
      $('#vaultPasswordInput').val(res.password);
    }
  });
}

// Add Custom Field row helper
function addCustomFieldRow(name = '', value = '') {
  $('#customFieldsHeader').show();
  const html = `
    <div class="row g-2 align-items-center custom-field-row">
      <div class="col-5">
        <input type="text" name="custom_field_name[]" class="form-control form-control-sm shadow-none" placeholder="Field Name (e.g. Port, API Key, PIN, Server IP)" value="${escapeHtml(name)}">
      </div>
      <div class="col-6">
        <input type="text" name="custom_field_value[]" class="form-control form-control-sm shadow-none" placeholder="Field Value" value="${escapeHtml(value)}">
      </div>
      <div class="col-1 text-center">
        <button type="button" class="btn btn-sm btn-outline-danger w-100 p-1 shadow-none" onclick="removeCustomFieldRow(this)" title="Remove Field">
          <i class="bi bi-trash"></i>
        </button>
      </div>
    </div>
  `;
  const $row = $(html);
  $('#customFieldsContainer').append($row);
  if (!name && !value) {
    setTimeout(function() {
      if ($row[0]) {
        $row[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        $row.find('input').first().focus();
      }
    }, 50);
  }
}

function removeCustomFieldRow(btn) {
  $(btn).closest('.custom-field-row').remove();
  if ($('#customFieldsContainer .custom-field-row').length === 0) {
    $('#customFieldsHeader').hide();
  }
}

// Open Add Credential Modal
function openAddCredentialModal() {
  $('#modalCredId').val(0);
  $('#modalTitleText').text('Add Encrypted Credential');
  $('#modalSubmitText').text('Save to Encrypted Vault');
  $('#credCustomerId').val('<?= $customer_id ?: "" ?>');
  $('#credPlatform').val('Social Media');
  $('#credAccountName').val('');
  $('#credUsernameEmail').val('');
  $('#credAccountId').val('');
  $('#credLoginUrl').val('');
  $('#vaultPasswordInput').val('').prop('required', true);
  $('#passwordRequiredStar').show();
  $('#passwordHelpNote').hide();
  $('#credRecoveryEmail').val('');
  $('#credRecoveryPhone').val('');
  $('#twoFactor').prop('checked', false);
  $('#credTwoFactorInfo').val('');
  $('#credNotes').val('');
  $('#changeReasonGroup').hide();
  $('#credChangeReason').val('');
  $('#customFieldsContainer').empty();
  $('#customFieldsHeader').hide();
  
  bootstrap.Modal.getOrCreateInstance(document.getElementById('credentialModal')).show();
}

// Helper to open Edit Modal from data attribute
function openEditCredentialModalFromBtn(btn) {
  try {
    const cred = JSON.parse(btn.getAttribute('data-cred'));
    openEditCredentialModal(cred);
  } catch(e) {
    console.error('Error parsing cred:', e);
  }
}

// Open Edit Credential Modal with pre-filled details
function openEditCredentialModal(cred) {
  // Hide details modal if open
  const detailsModalEl = document.getElementById('viewDetailsModal');
  if (detailsModalEl) {
    const dmInstance = bootstrap.Modal.getInstance(detailsModalEl);
    if (dmInstance) dmInstance.hide();
  }

  $('#modalCredId').val(cred.id);
  $('#modalTitleText').text('Edit Encrypted Credential');
  $('#modalSubmitText').text('Update Credential');
  $('#credCustomerId').val(cred.customer_id);
  $('#credPlatform').val(cred.platform);
  $('#credAccountName').val(cred.account_name);
  $('#credUsernameEmail').val(cred.username_email || '');
  $('#credAccountId').val(cred.account_id || '');
  $('#credLoginUrl').val(cred.login_url || '');
  
  // Password is not required when editing
  $('#vaultPasswordInput').val('').prop('required', false);
  $('#passwordRequiredStar').hide();
  $('#passwordHelpNote').show();
  $('#changeReasonGroup').show();
  $('#credChangeReason').val('');

  $('#credRecoveryEmail').val(cred.recovery_email || '');
  $('#credRecoveryPhone').val(cred.recovery_phone || '');
  $('#twoFactor').prop('checked', parseInt(cred.two_factor_enabled, 10) === 1);
  $('#credTwoFactorInfo').val(cred.two_factor_info || '');
  $('#credNotes').val(cred.notes || '');

  // Populate custom fields
  $('#customFieldsContainer').empty();
  $('#customFieldsHeader').hide();
  let customFields = [];
  try {
    if (typeof cred.custom_fields === 'string' && cred.custom_fields.trim()) {
      customFields = JSON.parse(cred.custom_fields);
    } else if (Array.isArray(cred.custom_fields)) {
      customFields = cred.custom_fields;
    }
  } catch(e) {}

  if (Array.isArray(customFields) && customFields.length > 0) {
    customFields.forEach(function(cf) {
      addCustomFieldRow(cf.name, cf.value);
    });
  }

  bootstrap.Modal.getOrCreateInstance(document.getElementById('credentialModal')).show();
}

// Helper to open View Details Modal from data attribute
function viewVaultDetailsFromBtn(btn) {
  try {
    const cred = JSON.parse(btn.getAttribute('data-cred'));
    viewVaultDetails(cred);
  } catch(e) {
    console.error('Error parsing cred:', e);
  }
}

// View Full Details Modal showing all stored information
function viewVaultDetails(cred) {
  currentDetailCred = cred;
  currentDetailPassword = '';
  clearInterval(detailsCountdownTimer);

  $('#viewDetailAccountName').text(cred.account_name || 'Credential Details');
  $('#viewDetailClientSubtitle').text((cred.client_name || 'Client') + ' · ' + cred.platform);
  $('#viewDetailClient').text(cred.client_name || '—');
  $('#viewDetailPlatform').html('<span class="badge bg-light text-dark border"><i class="bi bi-globe me-1 text-primary"></i>' + escapeHtml(cred.platform) + '</span>');
  
  let accFull = escapeHtml(cred.account_name);
  if (cred.account_id) {
    accFull += ' <span class="badge bg-secondary-subtle text-secondary border ms-1">ID: ' + escapeHtml(cred.account_id) + '</span>';
  }
  $('#viewDetailAccountFull').html(accFull);
  
  $('#viewDetailUsername').text(cred.username_email || '—');
  
  if (cred.login_url) {
    $('#viewDetailUrlWrapper').html('<a href="' + escapeHtml(cred.login_url) + '" target="_blank" class="text-primary text-decoration-none fw-semibold"><i class="bi bi-box-arrow-up-right me-1"></i><span id="viewDetailUrlText">' + escapeHtml(cred.login_url) + '</span></a>');
  } else {
    $('#viewDetailUrlWrapper').html('<span class="text-muted" id="viewDetailUrlText">—</span>');
  }

  // Password masked reset
  $('#viewDetailPasswordMasked').text('••••••••••••••••');
  $('#viewDetailRevealBtn').show().html('<i class="bi bi-eye me-1"></i>Reveal');
  $('#viewDetailCopyBtn').hide();
  $('#viewDetailCountdownNote').hide();

  // 2FA details
  if (parseInt(cred.two_factor_enabled, 10) === 1) {
    let twoFaHtml = '<span class="badge bg-success-subtle text-success border border-success-subtle py-1"><i class="bi bi-shield-check me-1"></i>2FA Active</span>';
    if (cred.two_factor_info) {
      twoFaHtml += '<div class="small text-dark mt-1"><strong>Details:</strong> ' + escapeHtml(cred.two_factor_info) + '</div>';
    }
    $('#viewDetail2FA').html(twoFaHtml);
  } else {
    $('#viewDetail2FA').html('<span class="badge bg-secondary-subtle text-secondary py-1">2FA Disabled / Off</span>');
  }

  // Recovery details
  let recHtml = '';
  if (cred.recovery_email) {
    recHtml += '<div><i class="bi bi-envelope-at me-1 text-muted"></i>' + escapeHtml(cred.recovery_email) + '</div>';
  }
  if (cred.recovery_phone) {
    recHtml += '<div><i class="bi bi-telephone me-1 text-muted"></i>' + escapeHtml(cred.recovery_phone) + '</div>';
  }
  if (!recHtml) recHtml = '<span class="text-muted">—</span>';
  $('#viewDetailRecovery').html(recHtml);

  // Custom fields
  let customFields = [];
  try {
    if (typeof cred.custom_fields === 'string' && cred.custom_fields.trim()) {
      customFields = JSON.parse(cred.custom_fields);
    } else if (Array.isArray(cred.custom_fields)) {
      customFields = cred.custom_fields;
    }
  } catch(e) {}

  if (Array.isArray(customFields) && customFields.length > 0) {
    $('#viewDetailCustomFieldsSection').show();
    let cfHtml = '';
    customFields.forEach(function(cf) {
      cfHtml += `
        <div class="col-sm-6">
          <div class="p-2 bg-white rounded-2 border d-flex justify-content-between align-items-center">
            <div>
              <span class="text-muted small d-block" style="font-size:0.72rem;">${escapeHtml(cf.name)}</span>
              <strong class="text-dark small text-break">${escapeHtml(cf.value || '—')}</strong>
            </div>
            <button type="button" class="btn btn-sm btn-link text-muted p-0 ms-1" onclick="copyTextDirect('${escapeJs(cf.value)}')" title="Copy Value">
              <i class="bi bi-copy"></i>
            </button>
          </div>
        </div>
      `;
    });
    $('#viewDetailCustomFieldsList').html(cfHtml);
  } else {
    $('#viewDetailCustomFieldsSection').hide();
    $('#viewDetailCustomFieldsList').empty();
  }

  // Notes
  $('#viewDetailNotes').text(cred.notes || 'No extra notes specified.');

  // Timestamps
  $('#viewDetailCreatedAt').text(cred.created_at ? 'Created: ' + cred.created_at : '');
  $('#viewDetailUpdatedAt').text(cred.updated_at ? 'Last Updated: ' + cred.updated_at : '');

  // Edit button inside details modal
  $('#viewDetailEditBtn').off('click').on('click', function() {
    openEditCredentialModal(cred);
  });

  bootstrap.Modal.getOrCreateInstance(document.getElementById('viewDetailsModal')).show();
}

// Reveal password inside Details Modal
function revealFromDetailsModal() {
  if (!currentDetailCred) return;
  
  $('#viewDetailPasswordMasked').text('Decrypting...');
  $.post('ajax_vault.php', {
    action: 'reveal',
    id: currentDetailCred.id,
    csrf: '<?= csrf_token() ?>'
  }, function(res) {
    if (res.success) {
      currentDetailPassword = res.password;
      $('#viewDetailPasswordMasked').text(res.password);
      $('#viewDetailRevealBtn').hide();
      $('#viewDetailCopyBtn').show();
      $('#viewDetailCountdownNote').show();

      let seconds = 10;
      $('#viewDetailSeconds').text(seconds);
      clearInterval(detailsCountdownTimer);
      
      detailsCountdownTimer = setInterval(function() {
        seconds--;
        $('#viewDetailSeconds').text(seconds);
        if (seconds <= 0) {
          clearInterval(detailsCountdownTimer);
          $('#viewDetailPasswordMasked').text('••••••••••••••••');
          $('#viewDetailRevealBtn').show();
          $('#viewDetailCopyBtn').hide();
          $('#viewDetailCountdownNote').hide();
          currentDetailPassword = '';
        }
      }, 1000);
    } else {
      $('#viewDetailPasswordMasked').text('Error: ' + res.message);
    }
  });
}

function copyRevealedPasswordFromDetails() {
  if (currentDetailPassword) {
    navigator.clipboard.writeText(currentDetailPassword).then(function() {
      alert('Password copied to clipboard!');
    });
  }
}

// Reveal password in standalone modal
function revealVaultPassword(credId, accountTitle) {
  $('#revealAccountTitle').text(accountTitle);
  $('#revealedPasswordText').text('Decrypting...');
  
  const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('revealModal'));
  modal.show();
  
  $.post('ajax_vault.php', {
    action: 'reveal',
    id: credId,
    csrf: '<?= csrf_token() ?>'
  }, function(res) {
    if (res.success) {
      $('#revealedPasswordText').text(res.password);
      
      let seconds = 10;
      $('#revealCountdown').text(seconds);
      clearInterval(countdownTimer);
      
      countdownTimer = setInterval(function() {
        seconds--;
        $('#revealCountdown').text(seconds);
        if (seconds <= 0) {
          clearInterval(countdownTimer);
          modal.hide();
        }
      }, 1000);
    } else {
      $('#revealedPasswordText').text('Error: ' + res.message);
    }
  });
}

function copyRevealedPassword() {
  const pass = $('#revealedPasswordText').text();
  navigator.clipboard.writeText(pass).then(function() {
    alert('Password copied to clipboard!');
  });
}

function copyFromElement(elId) {
  const text = document.getElementById(elId)?.textContent || '';
  if (text && text !== '—') {
    navigator.clipboard.writeText(text).then(function() {
      alert('Copied to clipboard: ' + text);
    });
  }
}

function copyTextDirect(text) {
  if (text) {
    navigator.clipboard.writeText(text).then(function() {
      alert('Copied: ' + text);
    });
  }
}

function confirmDeleteCredential(id, name) {
  document.getElementById('deleteCredId').value = id;
  document.getElementById('deleteCredMsg').textContent = 'Are you sure you want to permanently delete the credential for "' + name + '"?';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteCredentialModal')).show();
}

function escapeHtml(text) {
  if (!text) return '';
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function escapeJs(text) {
  if (!text) return '';
  return String(text).replace(/'/g, "\\'").replace(/"/g, '\\"');
}
</script>

<?php include '../../includes/footer.php'; ?>
