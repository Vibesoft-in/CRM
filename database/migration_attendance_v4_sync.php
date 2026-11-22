<?php
// Crm and backend/database/migration_attendance_v4_sync.php
require_once __DIR__ . '/../config.php';

echo "=== STARTING DATABASE MIGRATION & HISTORICAL DATA SYNC ===\n";

// 1. Settings Table: ensure admin_otp_bypass_enabled exists
try {
    $hasCol = false;
    $cols = $pdo->query("SHOW COLUMNS FROM settings LIKE 'admin_otp_bypass_enabled'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE settings ADD COLUMN admin_otp_bypass_enabled TINYINT(1) DEFAULT 1");
        echo "[✓] Added admin_otp_bypass_enabled column to settings table.\n";
    } else {
        echo "[✓] admin_otp_bypass_enabled column already present.\n";
    }
} catch (Throwable $e) {
    echo "[!] Error updating settings table: " . $e->getMessage() . "\n";
}

// 2. Safely correct historical Sunday records where status was mistakenly marked 'absent' without punch
try {
    $sql = "UPDATE attendance 
            SET status = 'holiday', notes = 'Sunday Weekly Off' 
            WHERE DAYOFWEEK(attendance_date) = 1 
              AND (check_in IS NULL OR check_in = '' OR check_in = '00:00:00') 
              AND status = 'absent'";
    $affected = $pdo->exec($sql);
    echo "[✓] Updated $affected historical Sunday records to 'holiday'.\n";
} catch (Throwable $e) {
    echo "[!] Error correcting Sunday records: " . $e->getMessage() . "\n";
}

// 3. Verify attendance records on Sundays
$stmt = $pdo->query("SELECT id, employee_id, attendance_date, DAYNAME(attendance_date) as day, status, check_in, check_out, total_hours 
                     FROM attendance 
                     WHERE DAYOFWEEK(attendance_date) = 1 
                     ORDER BY attendance_date DESC");
$sundays = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "[✓] Current Sunday records in database: " . count($sundays) . "\n";
foreach ($sundays as $s) {
    echo "    ID: {$s['id']} | Date: {$s['attendance_date']} ({$s['day']}) | Status: {$s['status']} | Hours: {$s['total_hours']}\n";
}

echo "=== MIGRATION FINISHED ===\n";
