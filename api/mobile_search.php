<?php
// Crm and backend/api/mobile_search.php — Global Multi-Entity Search API
require_once __DIR__ . '/mobile_helper.php';

$auth = authenticate_mobile();
$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    json_success([
        'clients'   => [],
        'invoices'  => [],
        'employees' => [],
        'projects'  => []
    ], 'Search query too short');
}

$term = "%$q%";

// 1. Search Customers
$cStmt = $pdo->prepare("SELECT id, name, phone, email, client_type, status FROM customers WHERE name LIKE ? OR email LIKE ? OR phone LIKE ? LIMIT 10");
$cStmt->execute([$term, $term, $term]);
$clients = $cStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Search Invoices
$iStmt = $pdo->prepare("SELECT i.id, i.invoice_no, i.total_amount, i.status, c.name as customer_name FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE i.invoice_no LIKE ? OR c.name LIKE ? LIMIT 10");
$iStmt->execute([$term, $term]);
$invoices = $iStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Search Employees (Admin/Manager role only)
$employees = [];
if (in_array($auth['role'], ['admin', 'super admin', 'manager', 'hr manager', 'staff'])) {
    $eStmt = $pdo->prepare("SELECT id, employee_code, full_name, designation, department, phone FROM employees WHERE full_name LIKE ? OR employee_code LIKE ? OR phone LIKE ? LIMIT 10");
    $eStmt->execute([$term, $term, $term]);
    $employees = $eStmt->fetchAll(PDO::FETCH_ASSOC);
}

// 4. Search Projects
$pStmt = $pdo->prepare("SELECT p.id, p.project_name, p.status, c.name as customer_name FROM projects p LEFT JOIN customers c ON c.id = p.customer_id WHERE p.project_name LIKE ? OR c.name LIKE ? LIMIT 10");
$pStmt->execute([$term, $term]);
$projects = $pStmt->fetchAll(PDO::FETCH_ASSOC);

json_success([
    'query'     => $q,
    'clients'   => $clients,
    'invoices'  => $invoices,
    'employees' => $employees,
    'projects'  => $projects
], 'Search results loaded');
