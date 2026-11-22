<?php
// includes/audit_helper.php
require_once __DIR__ . '/../config.php';

/**
 * Log audit trail entry (never logs raw passwords or credentials)
 */
function log_audit($module, $action, $record_id = null, $details = null) {
    global $pdo;
    
    try {
        $userId = $_SESSION['user_id'] ?? null;
        $userName = $_SESSION['user_name'] ?? 'System';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        
        $detailsText = is_array($details) || is_object($details) ? json_encode($details) : (string)$details;
        
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, user_name, module, action, record_id, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $userId,
            $userName,
            $module,
            $action,
            $record_id,
            $detailsText,
            $ip
        ]);
    } catch (Exception $e) {
        error_log("Audit Log Error: " . $e->getMessage());
    }
}
