<?php
require_once __DIR__ . '/../config.php';

echo "Running employee_password_logs migration...\n";

$sql = "CREATE TABLE IF NOT EXISTS employee_password_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    user_id INT NULL,
    changed_by_user_id INT NULL,
    changed_by_name VARCHAR(150) NULL,
    password_plain VARCHAR(255) NOT NULL,
    device_name VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    change_type ENUM('admin_reset', 'self_change', 'initial_creation') DEFAULT 'admin_reset',
    notes VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_emp_id (employee_id),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

try {
    $pdo->exec($sql);
    echo "✔ employee_password_logs table created successfully!\n";
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}
