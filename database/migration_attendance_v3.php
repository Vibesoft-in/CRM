<?php
// Crm and backend/database/migration_attendance_v3.php
require_once __DIR__ . '/../config.php';

echo "Running Migration Attendance V3...\n";

try {
    // 1. Create lunch_sessions table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lunch_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            attendance_id INT NULL,
            employee_id INT NOT NULL,
            session_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NULL,
            duration_seconds INT DEFAULT 0,
            start_latitude DECIMAL(10,8) NULL,
            start_longitude DECIMAL(11,8) NULL,
            end_latitude DECIMAL(10,8) NULL,
            end_longitude DECIMAL(11,8) NULL,
            status ENUM('active','completed') DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_emp_date (employee_id, session_date),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "  [OK] Table `lunch_sessions` ready.\n";

    // 2. Add columns to attendance_settings if missing
    $cols = $pdo->query("DESCRIBE attendance_settings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('daily_target_hours', $cols)) {
        $pdo->exec("ALTER TABLE attendance_settings ADD COLUMN daily_target_hours DECIMAL(4,2) NOT NULL DEFAULT 7.00 AFTER overtime_after_hours");
        echo "  [OK] Added `daily_target_hours` to `attendance_settings`.\n";
    } else {
        echo "  [SKIP] `daily_target_hours` already exists.\n";
    }

    if (!in_array('annual_allowed_leaves', $cols)) {
        $pdo->exec("ALTER TABLE attendance_settings ADD COLUMN annual_allowed_leaves INT NOT NULL DEFAULT 4 AFTER daily_target_hours");
        echo "  [OK] Added `annual_allowed_leaves` to `attendance_settings`.\n";
    } else {
        echo "  [SKIP] `annual_allowed_leaves` already exists.\n";
    }

    // 3. Ensure attendance_settings has daily_target_hours set to 7.00 and annual_allowed_leaves set to 4
    $pdo->exec("UPDATE attendance_settings SET daily_target_hours = 7.00, annual_allowed_leaves = 4 WHERE id = 1");
    echo "  [OK] Updated `attendance_settings` defaults to 7h daily target and 4 annual leaves.\n";

    // 4. Ensure holidays table has type column if helpful
    $holCols = $pdo->query("DESCRIBE holidays")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('holiday_type', $holCols)) {
        $pdo->exec("ALTER TABLE holidays ADD COLUMN holiday_type ENUM('national','festival','company','optional') DEFAULT 'festival' AFTER name");
        echo "  [OK] Added `holiday_type` to `holidays`.\n";
    }

    // 5. Synchronize existing holidays to attendance_holidays so both tables are consistent
    $holidays = $pdo->query("SELECT * FROM holidays WHERE status = 'active' OR status IS NULL")->fetchAll();
    $syncStmt = $pdo->prepare("
        INSERT INTO attendance_holidays (id, holiday_date, holiday_name, holiday_type) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE holiday_name = VALUES(holiday_name), holiday_type = VALUES(holiday_type)
    ");
    foreach ($holidays as $h) {
        $type = in_array($h['holiday_type'] ?? '', ['national','optional','company']) ? $h['holiday_type'] : 'company';
        $syncStmt->execute([$h['id'], $h['holiday_date'], $h['name'], $type]);
    }
    echo "  [OK] Synchronized " . count($holidays) . " holidays to `attendance_holidays`.\n";

    echo "Migration Attendance V3 Completed Successfully!\n";
} catch (Exception $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
    exit(1);
}
