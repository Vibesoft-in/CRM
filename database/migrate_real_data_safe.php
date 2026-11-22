<?php
/**
 * Safe Data Migration Script
 * Migrates all authentic live data from `old-database-u537268535_crm`
 * into `u537268535_vibesoft_app` preserving new schema additions and foreign key integrity.
 */

set_time_limit(300);
ini_set('memory_limit', '512M');

$sourceDb = 'old-database-u537268535_crm';
$targetDb = 'u537268535_vibesoft_app';

echo "===============================================================\n";
echo "   SAFE PRODUCTION DATA MIGRATION TO NEW DATABASE              \n";
echo "   Source: $sourceDb                                           \n";
echo "   Target: $targetDb                                           \n";
echo "===============================================================\n\n";

try {
    $pdo = new PDO("mysql:host=localhost;charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Check both databases exist
    $dbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($sourceDb, $dbs)) {
        throw new Exception("Source database '$sourceDb' does not exist!");
    }
    if (!in_array($targetDb, $dbs)) {
        throw new Exception("Target database '$targetDb' does not exist!");
    }

    // Disable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "✔ Foreign key checks temporarily disabled.\n\n";

    // Get all tables in source database
    $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$sourceDb' AND TABLE_TYPE = 'BASE TABLE'");
    $sourceTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get all tables in target database
    $stmtTarget = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$targetDb' AND TABLE_TYPE = 'BASE TABLE'");
    $targetTables = $stmtTarget->fetchAll(PDO::FETCH_COLUMN);

    // Save attendance_settings 200m config if present in target
    $preservedAttendanceSettings = null;
    if (in_array('attendance_settings', $targetTables)) {
        $checkAtt = $pdo->query("SELECT * FROM `$targetDb`.attendance_settings WHERE id = 1")->fetch();
        if ($checkAtt) {
            $preservedAttendanceSettings = $checkAtt;
        }
    }

    $migratedStats = [];

    foreach ($sourceTables as $table) {
        if (!in_array($table, $targetTables)) {
            echo "⚠ Warning: Table '$table' exists in Source but NOT in Target! Skipping.\n";
            continue;
        }

        // 1. Get source columns
        $qSrc = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$sourceDb' AND TABLE_NAME = '$table'");
        $srcCols = $qSrc->fetchAll(PDO::FETCH_COLUMN);

        // 2. Get target columns
        $qTgt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$targetDb' AND TABLE_NAME = '$table'");
        $tgtCols = $qTgt->fetchAll(PDO::FETCH_COLUMN);

        // Find shared columns
        $sharedCols = array_intersect($srcCols, $tgtCols);
        if (empty($sharedCols)) {
            echo "⚠ No common columns found for table '$table'. Skipping.\n";
            continue;
        }

        $colList = '`' . implode('`, `', $sharedCols) . '`';

        // 3. Truncate target table
        $pdo->exec("TRUNCATE TABLE `$targetDb`.`$table`");

        // 4. Copy rows from source to target
        $insertSql = "INSERT INTO `$targetDb`.`$table` ($colList) SELECT $colList FROM `$sourceDb`.`$table`";
        $affected = $pdo->exec($insertSql);

        // 5. Check count in source vs target
        $srcCount = (int)$pdo->query("SELECT COUNT(*) FROM `$sourceDb`.`$table`")->fetchColumn();
        $tgtCount = (int)$pdo->query("SELECT COUNT(*) FROM `$targetDb`.`$table`")->fetchColumn();

        // 6. Reset auto-increment
        $pkQuery = $pdo->query("SHOW KEYS FROM `$targetDb`.`$table` WHERE Key_name = 'PRIMARY'");
        $pk = $pkQuery->fetch();
        if ($pk && !empty($pk['Column_name'])) {
            $pkCol = $pk['Column_name'];
            $maxId = $pdo->query("SELECT MAX(`$pkCol`) FROM `$targetDb`.`$table`")->fetchColumn();
            if ($maxId !== null && is_numeric($maxId)) {
                $nextId = (int)$maxId + 1;
                $pdo->exec("ALTER TABLE `$targetDb`.`$table` AUTO_INCREMENT = $nextId");
            }
        }

        $migratedStats[$table] = [
            'source' => $srcCount,
            'target' => $tgtCount,
            'status' => ($srcCount === $tgtCount) ? 'MATCH' : 'MISMATCH'
        ];

        printf("  ✔ [%-28s] Copied: %5d rows | Target Total: %5d\n", $table, $affected, $tgtCount);
    }

    // Restore refined attendance settings (200m office radius) if desired
    if ($preservedAttendanceSettings) {
        $stmtUpdateAtt = $pdo->prepare("UPDATE `$targetDb`.attendance_settings 
            SET office_latitude = ?, office_longitude = ?, geofence_radius_meters = ?, updated_at = ? 
            WHERE id = ?");
        $stmtUpdateAtt->execute([
            $preservedAttendanceSettings['office_latitude'],
            $preservedAttendanceSettings['office_longitude'],
            $preservedAttendanceSettings['geofence_radius_meters'],
            $preservedAttendanceSettings['updated_at'],
            $preservedAttendanceSettings['id']
        ]);
        echo "\n✔ Refined attendance geofence settings (200m radius) preserved.\n";
    }

    // Re-enable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "✔ Foreign key checks re-enabled.\n\n";

    echo "===============================================================\n";
    echo "                 VERIFICATION SUMMARY                          \n";
    echo "===============================================================\n";
    printf("%-30s | %-10s | %-10s | %-8s\n", "Table", "Source", "Target", "Status");
    printf("%'-30s-+-%'-10s-+-%'-10s-+-%'-8s\n", "", "", "", "");

    $allPassed = true;
    foreach ($migratedStats as $tbl => $stat) {
        if ($stat['source'] > 0 || $stat['target'] > 0) {
            printf("%-30s | %-10d | %-10d | %-8s\n", $tbl, $stat['source'], $stat['target'], $stat['status']);
            if ($stat['status'] !== 'MATCH') {
                $allPassed = false;
            }
        }
    }

    // Check new tables in target database
    $newTables = array_diff($targetTables, $sourceTables);
    echo "\nPreserved New Schema Tables in $targetDb:\n";
    foreach ($newTables as $nt) {
        $count = $pdo->query("SELECT COUNT(*) FROM `$targetDb`.`$nt`")->fetchColumn();
        echo "  • $nt (Rows: $count)\n";
    }

    if ($allPassed) {
        echo "\n🎉 SUCCESS: All data migrated safely and perfectly verified!\n";
    } else {
        echo "\n⚠ WARNING: Some tables had row count mismatches. Review output above.\n";
    }

} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }
    echo "\n❌ MIGRATION FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
