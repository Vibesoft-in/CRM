-- database/migration_vishal_hr.sql
-- Complete HR, Employee Management, Attendance, Work Reports, Leave & Payroll for Vishal Advertisement

-- 1. Departments
CREATE TABLE IF NOT EXISTS departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  slug VARCHAR(100) NOT NULL UNIQUE,
  description TEXT NULL,
  manager_id INT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  sort_order INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 2. Designations
CREATE TABLE IF NOT EXISTS designations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  department_id INT NULL,
  description TEXT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 3. Extend users table if columns don't exist
ALTER TABLE users ADD COLUMN IF NOT EXISTS employee_id INT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') DEFAULT 'active';
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL;

-- 4. Extend employees table
ALTER TABLE employees ADD COLUMN IF NOT EXISTS user_id INT NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS department_id INT NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS designation_id INT NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS manager_id INT NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS last_name VARCHAR(100) NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS emergency_contact_phone VARCHAR(50) NULL;

-- 5. Attendance Adjustments (Audit Log for Manual Edits)
CREATE TABLE IF NOT EXISTS attendance_adjustments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL,
  changed_by INT NOT NULL,
  old_status VARCHAR(50) NULL,
  new_status VARCHAR(50) NOT NULL,
  old_check_in TIME NULL,
  new_check_in TIME NULL,
  old_check_out TIME NULL,
  new_check_out TIME NULL,
  reason TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 6. Work Reports Table
CREATE TABLE IF NOT EXISTS work_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  report_date DATE NOT NULL,
  summary TEXT NULL,
  tasks_completed TEXT NOT NULL,
  projects_worked VARCHAR(255) NULL,
  hours_worked DECIMAL(4,2) DEFAULT 8.00,
  challenges TEXT NULL,
  tomorrow_plan TEXT NULL,
  status ENUM('draft','submitted','approved','rejected','revision_requested') DEFAULT 'submitted',
  submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  review_comment TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_work_report (employee_id, report_date),
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- 7. Leave Types
CREATE TABLE IF NOT EXISTS leave_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  days_per_year INT DEFAULT 12,
  is_paid TINYINT(1) DEFAULT 1,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 8. Leave Requests
CREATE TABLE IF NOT EXISTS leave_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  leave_type_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  total_days INT NOT NULL DEFAULT 1,
  reason TEXT NOT NULL,
  status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  review_comment TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE
);

-- 9. Holidays
CREATE TABLE IF NOT EXISTS holidays (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  holiday_date DATE NOT NULL UNIQUE,
  description TEXT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 10. Historical Employee Salary Structure
CREATE TABLE IF NOT EXISTS employee_salary (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  effective_from DATE NOT NULL,
  basic_salary DECIMAL(12,2) NOT NULL,
  housing_allowance DECIMAL(12,2) DEFAULT 0,
  transport_allowance DECIMAL(12,2) DEFAULT 0,
  other_allowance DECIMAL(12,2) DEFAULT 0,
  overtime_rate DECIMAL(12,2) DEFAULT 0,
  status ENUM('active','superseded') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- 11. Payroll Items
CREATE TABLE IF NOT EXISTS payroll_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payroll_id INT NOT NULL,
  type ENUM('earning','deduction') NOT NULL,
  name VARCHAR(150) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  description VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payroll_id) REFERENCES payroll(id) ON DELETE CASCADE
);

-- 12. Notifications
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  message TEXT NOT NULL,
  link VARCHAR(255) NULL,
  type ENUM('info','success','warning','danger') DEFAULT 'info',
  is_read TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
