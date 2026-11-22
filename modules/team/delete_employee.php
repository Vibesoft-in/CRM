<?php
require_once '../../includes/functions.php';
require_login();

// Strict RBAC: Only authorized Admin can delete an employee
if (!is_admin() && !has_permission('employees.delete')) {
    \App\Security\AuditLogger::securityEvent('SECURITY_UNAUTHORIZED_DELETE_ATTEMPT', [
        'target_emp_id' => $_POST['id'] ?? null,
        'user_id' => $_SESSION['user_id'] ?? null
    ]);
    abort_forbidden("Access Denied: Only administrators can delete employee profiles.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash("Invalid request method.", "danger");
    header("Location: employees.php");
    exit;
}

if (!verify_csrf($_POST['csrf'] ?? '')) {
    set_flash("Invalid security token.", "danger");
    header("Location: employees.php");
    exit;
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash("Invalid Employee ID.", "danger");
    header("Location: employees.php");
    exit;
}

$stmt = $pdo->prepare("SELECT id, full_name, profile_photo FROM employees WHERE id = ?");
$stmt->execute([$id]);
$employee = $stmt->fetch();

if (!$employee) {
    set_flash("Employee not found.", "warning");
    header("Location: employees.php");
    exit;
}

// Optionally delete profile photo file if it exists
if (!empty($employee['profile_photo'])) {
    $photoPath = __DIR__ . '/../../' . $employee['profile_photo'];
    if (file_exists($photoPath)) {
        @unlink($photoPath);
    }
}

// Delete associated documents files
$docStmt = $pdo->prepare("SELECT file_path FROM employee_documents WHERE employee_id = ?");
$docStmt->execute([$id]);
$docs = $docStmt->fetchAll();
foreach ($docs as $doc) {
    if (!empty($doc['file_path'])) {
        $dPath = __DIR__ . '/../../' . $doc['file_path'];
        if (file_exists($dPath)) {
            @unlink($dPath);
        }
    }
}

// Delete employee record (DB foreign keys ON DELETE CASCADE handle skills, documents, etc.)
$delStmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
$delStmt->execute([$id]);

set_flash("Employee '" . e($employee['full_name']) . "' deleted successfully.", "success");
header("Location: employees.php");
exit;
