<?php
// Crm and backend/database/run_realtime_migration.php
require_once __DIR__ . '/../config.php';

try {
    echo "Running Realtime Event Migration...\n";
    $sql = file_get_contents(__DIR__ . '/migration_realtime.sql');
    $pdo->exec($sql);
    echo "Migration completed successfully! realtime_events table is ready.\n";
} catch (\Throwable $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
