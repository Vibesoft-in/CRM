<?php
require_once __DIR__ . '/../config.php';

try {
    echo "=== Vibesoft ERP Full Migration & Repair ===\n\n";

    // 1. invoice_counter
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_counter (
        id INT PRIMARY KEY,
        last_number INT NOT NULL DEFAULT 10000
    )");
    $pdo->exec("INSERT INTO invoice_counter (id, last_number) VALUES (1, 10000) ON DUPLICATE KEY UPDATE id=id");
    echo "✔ invoice_counter table verified.\n";

    // 2. invoice_items columns
    $cols = $pdo->query("SHOW COLUMNS FROM invoice_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('discount', $cols)) {
        $pdo->exec("ALTER TABLE invoice_items ADD COLUMN discount DECIMAL(12,2) DEFAULT 0");
        echo "✔ invoice_items.discount added.\n";
    }
    if (!in_array('handler_name', $cols)) {
        $pdo->exec("ALTER TABLE invoice_items ADD COLUMN handler_name VARCHAR(255) NULL");
        echo "✔ invoice_items.handler_name added.\n";
    }

    // 3. expenses columns
    $eCols = $pdo->query("SHOW COLUMNS FROM expenses")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('vendor', $eCols)) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN vendor VARCHAR(255) NULL AFTER category");
        echo "✔ expenses.vendor added.\n";
    }
    if (!in_array('tax_amount', $eCols)) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN tax_amount DECIMAL(12,2) DEFAULT 0 AFTER amount");
        echo "✔ expenses.tax_amount added.\n";
    }

    // 4. settings columns
    $setCols = $pdo->query("SHOW COLUMNS FROM settings")->fetchAll(PDO::FETCH_COLUMN);
    $newSettingCols = [
        'website'        => "VARCHAR(255) NULL",
        'bank_name'      => "VARCHAR(255) NULL",
        'account_holder' => "VARCHAR(255) NULL",
        'account_number' => "VARCHAR(100) NULL",
        'ifsc_code'      => "VARCHAR(50) NULL",
        'upi_id'         => "VARCHAR(100) NULL",
        'branch_name'    => "VARCHAR(255) NULL",
    ];
    foreach ($newSettingCols as $col => $def) {
        if (!in_array($col, $setCols)) {
            $pdo->exec("ALTER TABLE settings ADD COLUMN {$col} {$def}");
            echo "✔ settings.{$col} added.\n";
        }
    }

    // 5. customers columns
    $custCols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    $custNew = [
        'client_logo'          => "VARCHAR(255) NULL",
        'monthly_service_name' => "VARCHAR(255) NULL",
        'due_days'             => "INT DEFAULT 10",
        'auto_invoice'         => "TINYINT(1) DEFAULT 1",
        'billing_day'          => "INT DEFAULT 1",
        'billing_start_date'   => "DATE NULL"
    ];

    foreach ($custNew as $col => $def) {
        if (!in_array($col, $custCols)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN {$col} {$def}");
            echo "✔ customers.{$col} added.\n";
        }
    }

    // 6. payments columns
    $payCols = $pdo->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_COLUMN);
    $payNew = [
        'payment_no'         => "VARCHAR(100) NULL",
        'transaction_id'     => "VARCHAR(100) NULL",
        'reference'          => "VARCHAR(255) NULL",
        'notes'              => "TEXT NULL",
        'unallocated_amount' => "DECIMAL(12,2) DEFAULT 0",
        'created_by'         => "INT NULL"
    ];
    foreach ($payNew as $col => $def) {
        if (!in_array($col, $payCols)) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN {$col} {$def}");
            echo "✔ payments.{$col} added.\n";
        }
    }

    // 8. customer_services table
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        project_id INT NULL,
        service_name VARCHAR(255) NOT NULL,
        billing_type ENUM('monthly', 'one_time') DEFAULT 'monthly',
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
    )");
    echo "✔ customer_services table created/verified.\n";

    // 9. overview_notes in customers
    if (!in_array('overview_notes', $custCols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN overview_notes TEXT NULL AFTER notes");
        echo "✔ customers.overview_notes added.\n";
    }

    // 10. client_quotations table
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_quotations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        quotation_no VARCHAR(100) NULL,
        title VARCHAR(255) NOT NULL,
        amount DECIMAL(12,2) DEFAULT 0,
        scope_of_work TEXT NULL,
        quotation_type ENUM('sent', 'final', 'revised', 'draft') DEFAULT 'sent',
        status ENUM('pending', 'approved', 'rejected', 'superseded') DEFAULT 'approved',
        file_path VARCHAR(255) NULL,
        quotation_date DATE NULL,
        notes TEXT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
    )");
    echo "✔ client_quotations table created/verified.\n";

    // 11. employee_salary_history table
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_salary_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        old_salary DECIMAL(12,2) NOT NULL,
        new_salary DECIMAL(12,2) NOT NULL,
        increment_amount DECIMAL(12,2) NOT NULL,
        percentage_increase DECIMAL(5,2) DEFAULT 0,
        effective_date DATE NOT NULL,
        reason_notes TEXT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    )");
    echo "✔ employee_salary_history table created/verified.\n";

    // 12. Offer Letter columns in employees
    $empCols = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('offer_letter_status', $empCols)) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN offer_letter_status ENUM('Not Generated', 'Generated', 'Sent', 'Signed', 'Accepted') DEFAULT 'Not Generated'");
        echo "✔ employees.offer_letter_status added.\n";
    }
    if (!in_array('offer_letter_date', $empCols)) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN offer_letter_date DATE NULL");
        echo "✔ employees.offer_letter_date added.\n";
    }

    // 13. employees profile_photo check
    $empCols = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('profile_photo', $empCols)) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN profile_photo VARCHAR(255) NULL AFTER full_name");
        echo "✔ employees.profile_photo added.\n";
    }

    echo "\n=== Migration completed successfully! ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

