<?php
// database/run_notification_migration.php — Executes Centralized Notification System Migration
require_once __DIR__ . '/../config.php';

echo "Running Centralized Notification Engine Migration...\n";

$sql = file_get_contents(__DIR__ . '/migration_notifications.sql');
try {
    // Split by semicolons and execute
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $count = 0;
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            $pdo->exec($stmt);
            $count++;
        }
    }

    // Ensure existing notifications table has all necessary columns
    $cols = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
    $colsMap = array_flip($cols);

    if (!isset($colsMap['payload'])) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN payload JSON NULL AFTER link");
        echo "✔ Added payload column to notifications.\n";
    }
    if (!isset($colsMap['channel'])) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN channel VARCHAR(32) NOT NULL DEFAULT 'crm' AFTER payload");
        echo "✔ Added channel column to notifications.\n";
    }
    if (!isset($colsMap['status'])) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN status ENUM('unread', 'read', 'archived') DEFAULT 'unread' AFTER channel");
        echo "✔ Added status column to notifications.\n";
    }
    if (!isset($colsMap['read_at'])) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL AFTER is_read");
        echo "✔ Added read_at column to notifications.\n";
    }
    // Upgrade type from ENUM to VARCHAR(64)
    $pdo->exec("ALTER TABLE notifications MODIFY COLUMN type VARCHAR(64) NOT NULL DEFAULT 'system_alert'");
    echo "✔ Upgraded type column on notifications to VARCHAR(64).\n";

    echo "✔ Migration completed successfully! Executed $count statements.\n";
} catch(Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}
