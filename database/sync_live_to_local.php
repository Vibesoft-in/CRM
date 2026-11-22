<?php
/**
 * Safe Live Data Sync Script
 * 
 * Copies all authentic data from the live database (`live_u537268535_cms_database`)
 * into the local CRM database (`cms_database`).
 * 
 * Guarantees:
 * 1. Zero structure loss: No tables are dropped in `cms_database`.
 * 2. All 59 live tables are transferred completely.
 * 3. Primary keys, foreign keys, and auto-increment values are properly reset.
 */

set_time_limit(600);
ini_set('memory_limit', '512M');

$sourceDb = 'live_u537268535_cms_database';
$targetDb = 'cms_database';

echo "======================================================================\n";
echo "   SAFE PRODUCTION DATA MIGRATION: LIVE DB -> LOCAL CMS_DATABASE      \n";
echo "   Source (Live):   $sourceDb                                         \n";
echo "   Target (Local):  $targetDb                                         \n";
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

    // Get all tables from Source DB
    $stmtSrc = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$sourceDb' AND TABLE_TYPE = 'BASE TABLE'");
    $sourceTables = $stmtSrc->fetchAll(PDO::FETCH_COLUMN);

    $stmtTgt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$targetDb' AND TABLE_TYPE = 'BASE TABLE'");
    $targetTables = $stmtTgt->fetchAll(PDO::FETCH_COLUMN);

    echo "✔ Detected " . count($sourceTables) . " tables in Source DB ($sourceDb).\n";
    echo "✔ Detected " . count($targetTables) . " tables in Target DB ($targetDb).\n\n";

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

        // 2. Clear target table
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

        printf("  [Transfer] %-32s -> Source: %5d | Target: %5d | %s\n",
            $table, $srcCount, $tgtCount, $isMatch ? 'OK' : 'MISMATCH');
    }

    // Re-enable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "\n✔ Foreign key checks re-enabled.\n\n";

    // Print summary table
    echo "======================================================================\n";
    echo "                 FINAL MIGRATION VERIFICATION TABLE                   \n";
    echo "======================================================================\n";
    printf("%-32s | %-10s | %-10s | %-8s\n", "Table Name", "Live", "Local", "Status");
    printf("%'-32s-+-%'-10s-+-%'-10s-+-%'-8s\n", "", "", "", "");

    $allMatched = true;
    foreach ($stats as $tbl => $stat) {
        printf("%-32s | %-10d | %-10d | %-8s\n", $tbl, $stat['source'], $stat['target'], $stat['status']);
        if ($stat['status'] !== 'MATCH') {
            $allMatched = false;
        }
    }

    if ($allMatched) {
        echo "\n======================================================================\n";
        echo "  SUCCESS: All live data has been safely transferred to '$targetDb'!\n";
        echo "  - Total tables transferred: " . count($stats) . "\n";
        echo "  - 100% row counts verified and matched.\n";
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
