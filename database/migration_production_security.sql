-- database/migration_production_security.sql
-- Production Security Hardening Schema

CREATE TABLE IF NOT EXISTS security_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(100) NOT NULL,
  user_id INT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent TEXT NULL,
  details TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sec_event (event_type, created_at),
  INDEX idx_sec_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(45) NOT NULL,
  identifier VARCHAR(191) NOT NULL,
  attempt_count INT DEFAULT 1,
  last_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  locked_until DATETIME NULL,
  UNIQUE KEY uk_ip_identifier (ip_address, identifier),
  INDEX idx_locked (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  session_id_hash VARCHAR(64) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent TEXT NULL,
  last_activity_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  INDEX idx_user_sess (user_id, session_id_hash),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optimize query performance for secure scoped queries
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_status (status);
ALTER TABLE employees ADD INDEX IF NOT EXISTS idx_emp_user (user_id);
ALTER TABLE payroll ADD INDEX IF NOT EXISTS idx_payroll_emp (employee_id);
ALTER TABLE attendance ADD INDEX IF NOT EXISTS idx_att_emp_date (employee_id, attendance_date);
ALTER TABLE work_reports ADD INDEX IF NOT EXISTS idx_wr_emp_date (employee_id, report_date);
ALTER TABLE daily_work_reports ADD INDEX IF NOT EXISTS idx_dwr_emp_date (employee_id, report_date);
