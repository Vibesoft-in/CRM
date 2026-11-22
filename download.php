<?php
// download.php — Universal Secure File Download Endpoint for CRM & Mobile
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// Require login (either admin session, employee session, or API token)
if (!is_logged_in()) {
    // Check mobile bearer token
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    $token = '';
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
        $token = $m[1];
    } elseif (!empty($_GET['token'])) {
        $token = $_GET['token'];
    }

    $authenticated = false;
    if (!empty($token)) {
        $tokenHash = hash('sha256', $token);
        $tStmt = $pdo->prepare("SELECT user_id, expires_at FROM user_sessions WHERE session_id_hash = ? AND revoked_at IS NULL LIMIT 1");
        $tStmt->execute([$tokenHash]);
        $row = $tStmt->fetch();
        if ($row && strtotime($row['expires_at']) > time()) {
            $authenticated = true;
        } else {
            $uStmt = $pdo->prepare("SELECT id FROM users WHERE api_key = ? LIMIT 1");
            $uStmt->execute([$token]);
            if ($uStmt->fetchColumn()) {
                $authenticated = true;
            }
        }
    }

    if (!$authenticated) {
        http_response_code(401);
        die("Unauthorized access. Please login to download documents.");
    }
}

$fileRel = $_GET['file'] ?? '';
$docId   = intval($_GET['id'] ?? 0);
$customName = trim($_GET['name'] ?? '');

$filePath = '';

if ($docId > 0) {
    $stmt = $pdo->prepare("SELECT file_path, doc_name FROM employee_documents WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($doc) {
        $fileRel = $doc['file_path'];
        if (empty($customName)) {
            $ext = pathinfo($fileRel, PATHINFO_EXTENSION);
            $customName = preg_replace('/[^a-zA-Z0-9_\-\s]/', '_', $doc['doc_name']) . '.' . $ext;
        }
    }
}

if (empty($fileRel)) {
    http_response_code(400);
    die("No document specified.");
}

// Clean relative path and verify inside uploads directory
$fileRel = ltrim(str_replace('\\', '/', $fileRel), '/');
// Strip BASE_URL prefix if present
if (strpos($fileRel, 'uploads/') !== false) {
    $fileRel = substr($fileRel, strpos($fileRel, 'uploads/'));
}

$baseUploads = realpath(__DIR__ . '/uploads');
$targetFullPath = realpath(__DIR__ . '/' . $fileRel);

if (!$targetFullPath || !$baseUploads || strpos($targetFullPath, $baseUploads) !== 0 || !file_exists($targetFullPath)) {
    http_response_code(404);
    die("File not found or access denied.");
}

$ext = strtolower(pathinfo($targetFullPath, PATHINFO_EXTENSION));

$mimeTypes = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'svg'  => 'image/svg+xml',
    'txt'  => 'text/plain',
    'csv'  => 'text/csv',
    'json' => 'application/json',
    'zip'  => 'application/zip',
];

$mime = $mimeTypes[$ext] ?? 'application/octet-stream';
$downloadFileName = !empty($customName) ? basename($customName) : basename($targetFullPath);

// Clean output buffers
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($downloadFileName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadFileName));
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . filesize($targetFullPath));

readfile($targetFullPath);
exit;
