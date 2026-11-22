<?php
// modules/credentials/ajax_vault.php
require_once '../../includes/functions.php';
require_once '../../includes/vault_helper.php';
require_once '../../includes/audit_helper.php';

header('Content-Type: application/json');

if (!is_logged_in() || !can_access_admin_panel()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: Administrator or Manager privilege required.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'reveal') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }
    
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        exit;
    }
    
    global $pdo;
    $stmt = $pdo->prepare("SELECT c.*, cust.name AS client_name FROM credentials c JOIN customers cust ON cust.id = c.customer_id WHERE c.id = ?");
    $stmt->execute([$id]);
    $cred = $stmt->fetch();
    
    if (!$cred) {
        echo json_encode(['success' => false, 'message' => 'Credential not found']);
        exit;
    }
    
    // Decrypt password
    $decrypted = decrypt_credential($cred['encrypted_password']);
    
    // Audit Log entry - Never log actual password
    log_audit('Credential Vault', 'Revealed Password', $id, [
        'client' => $cred['client_name'],
        'platform' => $cred['platform'],
        'account' => $cred['account_name']
    ]);
    
    echo json_encode([
        'success' => true,
        'password' => $decrypted,
        'username' => $cred['username_email'],
        'url' => $cred['login_url'],
        'two_factor' => $cred['two_factor_info'],
        'account_id' => $cred['account_id'] ?? '',
        'recovery_email' => $cred['recovery_email'] ?? '',
        'recovery_phone' => $cred['recovery_phone'] ?? '',
        'notes' => $cred['notes'] ?? '',
        'custom_fields' => $cred['custom_fields'] ?? null
    ]);
    exit;
}

if ($action === 'generate_password') {
    $length = (int)($_GET['length'] ?? 16);
    $pwd = generate_strong_password($length);
    echo json_encode(['success' => true, 'password' => $pwd]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid Action']);
