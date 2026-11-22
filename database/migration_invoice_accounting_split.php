<?php
require_once __DIR__ . '/../config.php';

try {
    echo "Running migration for invoice accounting split, personal expenses, and cancellation...\n";

    // 1. gst_invoices table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `gst_invoices` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `serial_no` INT(11) NOT NULL,
      `invoice_no` VARCHAR(50) NOT NULL UNIQUE,
      `invoice_id` INT(11) NOT NULL,
      `customer_id` INT(11) NOT NULL,
      `invoice_date` DATE NOT NULL,
      `taxable_value` DECIMAL(12,2) DEFAULT 0.00,
      `cgst_amount` DECIMAL(12,2) DEFAULT 0.00,
      `sgst_amount` DECIMAL(12,2) DEFAULT 0.00,
      `igst_amount` DECIMAL(12,2) DEFAULT 0.00,
      `tax_total` DECIMAL(12,2) DEFAULT 0.00,
      `total_amount` DECIMAL(12,2) DEFAULT 0.00,
      `financial_year` VARCHAR(20) DEFAULT NULL,
      `month_str` VARCHAR(10) DEFAULT NULL,
      `status` ENUM('active','cancelled') DEFAULT 'active',
      `cancellation_reason` TEXT DEFAULT NULL,
      `notes` TEXT DEFAULT NULL,
      `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_invoice_id` (`invoice_id`),
      KEY `idx_customer_id` (`customer_id`),
      KEY `idx_serial_no` (`serial_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    echo "✔ gst_invoices table created.\n";

    // 2. personal_invoices table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `personal_invoices` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `serial_no` INT(11) NOT NULL,
      `invoice_no` VARCHAR(50) NOT NULL UNIQUE,
      `invoice_id` INT(11) NOT NULL,
      `customer_id` INT(11) NOT NULL,
      `invoice_date` DATE NOT NULL,
      `total_amount` DECIMAL(12,2) DEFAULT 0.00,
      `payment_mode` VARCHAR(50) DEFAULT 'Personal Account',
      `financial_year` VARCHAR(20) DEFAULT NULL,
      `month_str` VARCHAR(10) DEFAULT NULL,
      `status` ENUM('active','cancelled') DEFAULT 'active',
      `cancellation_reason` TEXT DEFAULT NULL,
      `notes` TEXT DEFAULT NULL,
      `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_invoice_id` (`invoice_id`),
      KEY `idx_customer_id` (`customer_id`),
      KEY `idx_serial_no` (`serial_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    echo "✔ personal_invoices table created.\n";

    // 3. personal_expenses table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `personal_expenses` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `expense_date` DATE NOT NULL,
      `category` VARCHAR(100) NOT NULL,
      `title` VARCHAR(255) NOT NULL,
      `description` TEXT DEFAULT NULL,
      `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `payment_mode` VARCHAR(50) DEFAULT 'Cash',
      `receipt_file` VARCHAR(255) DEFAULT NULL,
      `status` VARCHAR(50) DEFAULT 'Paid',
      `created_by` INT(11) DEFAULT NULL,
      `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_expense_date` (`expense_date`),
      KEY `idx_category` (`category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    echo "✔ personal_expenses table created.\n";

    // 4. gst_invoice_counter & personal_invoice_counter
    $pdo->exec("CREATE TABLE IF NOT EXISTS `gst_invoice_counter` (
      `id` INT(11) NOT NULL PRIMARY KEY,
      `last_serial` INT(11) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $pdo->exec("INSERT INTO `gst_invoice_counter` (`id`, `last_serial`) VALUES (1, 0) ON DUPLICATE KEY UPDATE id=id");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `personal_invoice_counter` (
      `id` INT(11) NOT NULL PRIMARY KEY,
      `last_serial` INT(11) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $pdo->exec("INSERT INTO `personal_invoice_counter` (`id`, `last_serial`) VALUES (1, 0) ON DUPLICATE KEY UPDATE id=id");
    echo "✔ Sequence counter tables created.\n";

    // 5. Add columns to invoices table if missing
    $cols = $pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('invoice_type', $cols)) {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN `invoice_type` ENUM('gst','personal') DEFAULT 'gst' AFTER `discount`");
        echo "✔ Added invoice_type to invoices table.\n";
    }
    if (!in_array('serial_no', $cols)) {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN `serial_no` INT(11) DEFAULT NULL AFTER `invoice_type`");
        echo "✔ Added serial_no to invoices table.\n";
    }
    if (!in_array('cancellation_reason', $cols)) {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN `cancellation_reason` TEXT DEFAULT NULL AFTER `status`");
        echo "✔ Added cancellation_reason to invoices table.\n";
    }
    if (!in_array('cancelled_at', $cols)) {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN `cancelled_at` DATETIME DEFAULT NULL AFTER `cancellation_reason`");
        echo "✔ Added cancelled_at to invoices table.\n";
    }
    if (!in_array('cancelled_by', $cols)) {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN `cancelled_by` INT(11) DEFAULT NULL AFTER `cancelled_at`");
        echo "✔ Added cancelled_by to invoices table.\n";
    }

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
