<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$searchTerm = "%$q%";
$cleanNum = preg_replace('/[^0-9]/', '', $q);
$cleanSearch = $cleanNum ? "%$cleanNum%" : $searchTerm;

$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.phone, c.whatsapp, c.contact_person, c.client_logo,
           (SELECT COALESCE(SUM(amount_due), 0) FROM invoices WHERE customer_id = c.id AND status != 'cancelled') AS total_due
    FROM customers c
    WHERE c.name LIKE ? 
       OR c.contact_person LIKE ? 
       OR c.phone LIKE ? 
       OR c.alternate_phone LIKE ? 
       OR c.whatsapp LIKE ?
    ORDER BY 
      CASE 
        WHEN c.name LIKE ? THEN 1
        WHEN c.phone LIKE ? THEN 2
        ELSE 3
      END,
      c.name ASC
    LIMIT 8
");
$stmt->execute([
    $searchTerm, $searchTerm, $cleanSearch, $cleanSearch, $cleanSearch,
    "$q%", "$q%"
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows);
exit;
