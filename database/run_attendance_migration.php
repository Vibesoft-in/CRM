<?php
require_once __DIR__ . '/../config.php';
$sql = file_get_contents(__DIR__ . '/migration_attendance.sql');
try {
    // Split by semicolons and execute one at a time
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $count = 0;
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            $pdo->exec($stmt);
            $count++;
        }
    }
    echo "Migration successful! Executed $count statements.\n";
} catch(Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
