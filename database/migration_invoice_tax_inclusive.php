<?php
// Crm and backend/database/migration_invoice_tax_inclusive.php
require_once __DIR__ . '/../config.php';

global $pdo;

echo "Running Migration: Add is_tax_inclusive column to invoice_items...\n";

try {
    // Check if column already exists
    $cols = $pdo->query("SHOW COLUMNS FROM invoice_items LIKE 'is_tax_inclusive'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE invoice_items ADD COLUMN is_tax_inclusive TINYINT(1) NOT NULL DEFAULT 0 AFTER tax_rate");
        echo "Successfully added 'is_tax_inclusive' column to invoice_items table.\n";
    } else {
        echo "Column 'is_tax_inclusive' already exists in invoice_items table.\n";
    }

    // Verify
    $verify = $pdo->query("SHOW COLUMNS FROM invoice_items LIKE 'is_tax_inclusive'")->fetch(PDO::FETCH_ASSOC);
    echo "Verification: " . print_r($verify, true) . "\n";
    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
    exit(1);
}
