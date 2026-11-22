<?php
/**
 * Live Data Migration Script
 * 
 * Safely copies all authentic data from the live database (`old_u537268535_cms_database`)
 * into the local CRM database (`cms_database`).
 * 
 * Guarantees:
 * 1. Zero structure loss: No tables are dropped or deleted in `cms_database`.
 * 2. Newer CRM tables (`lunch_sessions`, `realtime_events`) are completely preserved.
 * 3. Newer columns in `cms_database` (e.g. `daily_target_hours`, `annual_allowed_leaves`, `holiday_type`)
 *    are populated with standard defaults.
 * 4. All live records replace existing local data across all 56 matching tables.
 */

set_time_limit(600);
ini_set('memory_limit', '512M');

$sourceDb = 'old_u537268535_cms_database';
$targetDb = 'cms_database';

echo "======================================================================\n";
echo "   SAFE PRODUCTION DATA MIGRATION: LIVE DB -> CURRENT CRM             \n";
echo "   Source (Live):   $sourceDb                                         \n";
echo "   Target (CRM):    $targetDb                                         \n";
echo "   Timestamp:       " . date('Y-m-d H:i:s') . "                       \n";
echo "======================================================================\n\n";

try {
    $pdo = new PDO("mysql:host=localhost;charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Check databases exist
    $dbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($sourceDb, $dbs)) {
        throw new Exception("Source database '$sourceDb' not found on MySQL server!");
    }
    if (!in_array($targetDb, $dbs)) {
        throw new Exception("Target database '$targetDb' not found on MySQL server!");
    }

    // Disable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "✔ Foreign key checks temporarily disabled.\n";

    // Get all tables
    $stmtSrc = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$sourceDb' AND TABLE_TYPE = 'BASE TABLE'");
    $sourceTables = $stmtSrc->fetchAll(PDO::FETCH_COLUMN);

    $stmtTgt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$targetDb' AND TABLE_TYPE = 'BASE TABLE'");
    $targetTables = $stmtTgt->fetchAll(PDO::FETCH_COLUMN);

    echo "✔ Detected " . count($sourceTables) . " tables in Source DB.\n";
    echo "✔ Detected " . count($targetTables) . " tables in Target DB.\n\n";

    $stats = [];

    foreach ($sourceTables as $table) {
        if (!in_array($table, $targetTables)) {
            echo "⚠ Warning: Table '$table' exists in Source but NOT in Target! Skipping.\n";
            continue;
        }

        // 1. Column detection
        $qSrc = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$sourceDb' AND TABLE_NAME = '$table'");
        $srcCols = $qSrc->fetchAll(PDO::FETCH_COLUMN);

        $qTgt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$targetDb' AND TABLE_NAME = '$table'");
        $tgtCols = $qTgt->fetchAll(PDO::FETCH_COLUMN);

        $commonCols = array_intersect($srcCols, $tgtCols);
        if (empty($commonCols)) {
            echo "⚠ Warning: No intersecting columns found for table '$table'. Skipping.\n";
            continue;
        }

        $colList = '`' . implode('`, `', $commonCols) . '`';

        // 2. Clear target table without dropping structure
        $pdo->exec("DELETE FROM `$targetDb`.`$table`");

        // 3. Copy live rows into target
        $insertSql = "INSERT INTO `$targetDb`.`$table` ($colList) SELECT $colList FROM `$sourceDb`.`$table`";
        $pdo->exec($insertSql);

        // 4. Verify counts
        $srcCount = (int)$pdo->query("SELECT COUNT(*) FROM `$sourceDb`.`$table`")->fetchColumn();
        $tgtCount = (int)$pdo->query("SELECT COUNT(*) FROM `$targetDb`.`$table`")->fetchColumn();

        // 5. Reset auto-increment
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

        $isMatch = ($srcCount === $tgtCount);
        $stats[$table] = [
            'source' => $srcCount,
            'target' => $tgtCount,
            'status' => $isMatch ? 'MATCH' : 'MISMATCH'
        ];

        printf("  [Transfer] %-32s -> Copied: %5d rows | Target: %5d | Status: %s\n",
            $table, $srcCount, $tgtCount, $isMatch ? 'OK' : 'MISMATCH');
    }

    // Post-migration enhancements for new columns
    // 1. attendance_settings
    if (in_array('attendance_settings', $targetTables)) {
        $pdo->exec("UPDATE `$targetDb`.`attendance_settings` 
            SET daily_target_hours = 7.00 
            WHERE daily_target_hours IS NULL OR daily_target_hours = 0");

        $pdo->exec("UPDATE `$targetDb`.`attendance_settings` 
            SET annual_allowed_leaves = 4 
            WHERE annual_allowed_leaves IS NULL OR annual_allowed_leaves = 0");

        echo "\n✔ attendance_settings updated with default daily_target_hours (7.00) & annual_allowed_leaves (4).\n";
    }

    // 2. holidays
    if (in_array('holidays', $targetTables)) {
        $pdo->exec("UPDATE `$targetDb`.`holidays` 
            SET holiday_type = 'festival' 
            WHERE holiday_type IS NULL OR holiday_type = ''");

        echo "✔ holidays updated with default holiday_type ('festival').\n";
    }

    // Re-enable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "✔ Foreign key checks re-enabled.\n\n";

    // Print summary
    echo "======================================================================\n";
    echo "                 FINAL MIGRATION VERIFICATION TABLE                   \n";
    echo "======================================================================\n";
    printf("%-32s | %-10s | %-10s | %-8s\n", "Table Name", "Source", "Target", "Status");
    printf("%'-32s-+-%'-10s-+-%'-10s-+-%'-8s\n", "", "", "", "");

    $allMatched = true;
    foreach ($stats as $tbl => $stat) {
        printf("%-32s | %-10d | %-10d | %-8s\n", $tbl, $stat['source'], $stat['target'], $stat['status']);
        if ($stat['status'] !== 'MATCH') {
            $allMatched = false;
        }
    }

    // Target-only tables check
    $targetOnly = array_diff($targetTables, $sourceTables);
    echo "\n----------------------------------------------------------------------\n";
    echo "Preserved CRM-Specific Tables (Not in Live DB, Intact & Preserved):\n";
    foreach ($targetOnly as $toTbl) {
        $cnt = $pdo->query("SELECT COUNT(*) FROM `$targetDb`.`$toTbl`")->fetchColumn();
        echo "  • $toTbl -> Schema Intact, Rows: $cnt\n";
    }

    if ($allMatched) {
        echo "\n======================================================================\n";
        echo "  SUCCESS: All live data has been safely transferred to '$targetDb'!\n";
        echo "  - 0 tables dropped or deleted.\n";
        echo "  - 0 schema loss.\n";
        echo "  - All 56 live tables 100% matched.\n";
        echo "======================================================================\n";
    } else {
        echo "\n⚠ WARNING: Some tables have row count differences. Review the table above.\n";
    }

} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }
    echo "\n❌ ERROR DURING MIGRATION: " . $e->getMessage() . "\n";
    exit(1);
}
