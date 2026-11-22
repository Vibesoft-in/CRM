<?php
// database/create_holiday_overrides.php
require_once __DIR__ . '/../config.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS holiday_overrides (
        id INT AUTO_INCREMENT PRIMARY KEY,
        override_date DATE NOT NULL UNIQUE,
        action ENUM('working_day', 'holiday') NOT NULL,
        reason VARCHAR(255) NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "holiday_overrides table ready.\n";

    // Clean up erroneous auto-marked absent rows on unpunched Sundays
    $cleaned = $pdo->exec("DELETE FROM attendance WHERE DAYOFWEEK(attendance_date) = 1 AND (check_in IS NULL OR check_in = '' OR check_in = '00:00:00') AND notes LIKE '%5:00 PM tak check-in nahi kiya%'");
    echo "Cleaned {$cleaned} erroneous Sunday absent records.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
