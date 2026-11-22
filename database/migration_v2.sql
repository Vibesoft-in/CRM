-- database/migration_v2.sql - Advanced Agency CRM/ERP Schema Update

-- 1. Extend Customers Table
ALTER TABLE customers 
ADD COLUMN IF NOT EXISTS client_type ENUM('monthly','one_time','project','retainer') DEFAULT 'monthly',
ADD COLUMN IF NOT EXISTS status ENUM('lead','active','inactive','completed','archived') DEFAULT 'active',
ADD COLUMN IF NOT EXISTS contact_person VARCHAR(150),
ADD COLUMN IF NOT EXISTS alternate_phone VARCHAR(30),
ADD COLUMN IF NOT EXISTS whatsapp VARCHAR(30),
ADD COLUMN IF NOT EXISTS city VARCHAR(100),
ADD COLUMN IF NOT EXISTS state VARCHAR(100),
ADD COLUMN IF NOT EXISTS country VARCHAR(100) DEFAULT 'India',
ADD COLUMN IF NOT EXISTS pincode VARCHAR(20),
ADD COLUMN IF NOT EXISTS website VARCHAR(255),
ADD COLUMN IF NOT EXISTS pan VARCHAR(30),
ADD COLUMN IF NOT EXISTS notes TEXT,
ADD COLUMN IF NOT EXISTS monthly_service_name VARCHAR(255),
ADD COLUMN IF NOT EXISTS monthly_amount DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS billing_start_date DATE,
ADD COLUMN IF NOT EXISTS billing_day INT DEFAULT 1,
ADD COLUMN IF NOT EXISTS due_days INT DEFAULT 10,
ADD COLUMN IF NOT EXISTS auto_invoice TINYINT(1) DEFAULT 1,
ADD COLUMN IF NOT EXISTS onboard_date DATE;

-- 2. Extend Invoices Table for Extra Info & Statuses
ALTER TABLE invoices
ADD COLUMN IF NOT EXISTS status ENUM('draft','sent','viewed','partially_paid','paid','overdue','cancelled') DEFAULT 'sent',
ADD COLUMN IF NOT EXISTS due_date DATE,
ADD COLUMN IF NOT EXISTS invoice_date DATE,
ADD COLUMN IF NOT EXISTS payment_mode VARCHAR(50) DEFAULT 'Pending',
ADD COLUMN IF NOT EXISTS amount_paid DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS amount_due DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS discount DECIMAL(12,2) DEFAULT 0;

-- Update invoice_date to created_at date where invoice_date is NULL
UPDATE invoices SET invoice_date = DATE(created_at) WHERE invoice_date IS NULL;
UPDATE invoices SET amount_due = total_amount - amount_paid WHERE amount_due = 0 AND total_amount > 0;

-- 3. Financial Payments Table
CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payment_no VARCHAR(100) UNIQUE NOT NULL,
  customer_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_date DATE NOT NULL,
  payment_method ENUM('Cash','Bank Transfer','UPI','Card','Cheque','Other') NOT NULL,
  transaction_id VARCHAR(100),
  reference VARCHAR(255),
  notes TEXT,
  unallocated_amount DECIMAL(12,2) DEFAULT 0,
  created_by INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

-- 4. Payment Allocations Table
CREATE TABLE IF NOT EXISTS payment_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payment_id INT NOT NULL,
  invoice_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);

-- 5. Financial Client Ledger Table
CREATE TABLE IF NOT EXISTS client_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  entry_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  reference_type ENUM('invoice','payment','extra_work','refund','adjustment') NOT NULL,
  reference_id INT NOT NULL,
  debit DECIMAL(12,2) DEFAULT 0,
  credit DECIMAL(12,2) DEFAULT 0,
  balance DECIMAL(12,2) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

-- 6. Monthly Billing Cycles
CREATE TABLE IF NOT EXISTS billing_cycles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  billing_month INT NOT NULL,
  billing_year INT NOT NULL,
  invoice_id INT,
  status ENUM('draft','generated','sent','partially_paid','paid','overdue') DEFAULT 'generated',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_cycle (customer_id, billing_month, billing_year),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

-- 7. Extra Work Table
CREATE TABLE IF NOT EXISTS extra_work (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  project_id INT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  work_date DATE NOT NULL,
  assigned_employee_id INT NULL,
  qty DECIMAL(10,2) DEFAULT 1,
  rate DECIMAL(12,2) NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  status ENUM('requested','approved','in_progress','completed','billed','paid','cancelled') DEFAULT 'requested',
  billing_method ENUM('current_monthly','next_monthly','separate_invoice','final_project') DEFAULT 'current_monthly',
  invoice_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

-- 8. Credential Vault
CREATE TABLE IF NOT EXISTS credentials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  platform ENUM('Social Media','Website','Hosting','Domain','Email','Google Business','Google Ads','Meta Ads','Instagram','Facebook','LinkedIn','YouTube','WordPress','Shopify','Other') NOT NULL,
  account_name VARCHAR(255) NOT NULL,
  username_email VARCHAR(255),
  encrypted_password TEXT NOT NULL,
  login_url VARCHAR(255),
  account_id VARCHAR(100),
  two_factor_enabled TINYINT(1) DEFAULT 0,
  two_factor_info TEXT,
  recovery_email VARCHAR(255),
  recovery_phone VARCHAR(50),
  notes TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS credential_password_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  credential_id INT NOT NULL,
  encrypted_password TEXT NOT NULL,
  changed_by INT NOT NULL,
  change_reason VARCHAR(255),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (credential_id) REFERENCES credentials(id) ON DELETE CASCADE
);

-- 9. Employees & Team
CREATE TABLE IF NOT EXISTS employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_code VARCHAR(50) UNIQUE NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  profile_photo VARCHAR(255),
  father_mother_name VARCHAR(150),
  dob DATE,
  gender ENUM('Male','Female','Other'),
  phone VARCHAR(30) NOT NULL,
  alternate_phone VARCHAR(30),
  email VARCHAR(150) UNIQUE NOT NULL,
  whatsapp VARCHAR(30),
  address TEXT,
  city VARCHAR(100),
  state VARCHAR(100),
  pincode VARCHAR(20),
  emergency_contact VARCHAR(100),
  joining_date DATE NOT NULL,
  department VARCHAR(100),
  designation VARCHAR(100),
  employee_type ENUM('Full Time','Part Time','Intern','Freelancer','Contract','Consultant') DEFAULT 'Full Time',
  salary_type ENUM('Monthly','Daily','Hourly','Project Based','Stipend') DEFAULT 'Monthly',
  base_salary DECIMAL(12,2) DEFAULT 0,
  bank_name VARCHAR(150),
  bank_acc_no VARCHAR(100),
  bank_ifsc VARCHAR(50),
  pan VARCHAR(30),
  aadhaar_ref VARCHAR(50),
  notes TEXT,
  status ENUM('active','on_leave','terminated','resigned') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS employee_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  doc_name VARCHAR(150) NOT NULL,
  doc_type ENUM('Resume','Offer Letter','Joining Letter','ID Proof','Address Proof','Certificates','Experience Letter','Bank Document','Other') NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  expiry_date DATE,
  uploaded_by INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS employee_skills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  skill_name VARCHAR(100) NOT NULL,
  skill_level ENUM('Beginner','Intermediate','Advanced','Expert') DEFAULT 'Intermediate',
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- 10. Payroll & Advances
CREATE TABLE IF NOT EXISTS salary_advances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  request_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  reason VARCHAR(255),
  payment_method ENUM('Cash','Bank Transfer','UPI','Cheque') DEFAULT 'Bank Transfer',
  status ENUM('Requested','Approved','Paid','Deducted','Cancelled') DEFAULT 'Paid',
  payroll_month INT,
  payroll_year INT,
  approved_by INT,
  expense_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payroll (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  payroll_month INT NOT NULL,
  payroll_year INT NOT NULL,
  base_salary DECIMAL(12,2) NOT NULL,
  allowances DECIMAL(12,2) DEFAULT 0,
  bonus DECIMAL(12,2) DEFAULT 0,
  advance_deducted DECIMAL(12,2) DEFAULT 0,
  late_deduction DECIMAL(12,2) DEFAULT 0,
  other_deduction DECIMAL(12,2) DEFAULT 0,
  net_salary DECIMAL(12,2) NOT NULL,
  amount_paid DECIMAL(12,2) DEFAULT 0,
  amount_pending DECIMAL(12,2) DEFAULT 0,
  status ENUM('Draft','Pending','Partially Paid','Paid','Cancelled') DEFAULT 'Pending',
  payment_date DATE,
  payment_method VARCHAR(50),
  transaction_ref VARCHAR(100),
  expense_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_payroll (employee_id, payroll_month, payroll_year),
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- 11. Projects
CREATE TABLE IF NOT EXISTS projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  project_name VARCHAR(255) NOT NULL,
  project_type ENUM('Monthly','One-Time','Retainer','Fixed') DEFAULT 'Fixed',
  start_date DATE,
  end_date DATE,
  budget DECIMAL(12,2) DEFAULT 0,
  agreed_amount DECIMAL(12,2) DEFAULT 0,
  advance_required DECIMAL(12,2) DEFAULT 0,
  status ENUM('Planning','Active','On Hold','Completed','Cancelled') DEFAULT 'Active',
  description TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS project_members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  employee_id INT NOT NULL,
  role VARCHAR(100),
  allocated_hours INT DEFAULT 0,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- 12. Expenses Enhancement
ALTER TABLE expenses
ADD COLUMN IF NOT EXISTS vendor VARCHAR(150),
ADD COLUMN IF NOT EXISTS tax_amount DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS project_id INT NULL,
ADD COLUMN IF NOT EXISTS customer_id INT NULL,
ADD COLUMN IF NOT EXISTS employee_id INT NULL,
ADD COLUMN IF NOT EXISTS is_recurring TINYINT(1) DEFAULT 0,
ADD COLUMN IF NOT EXISTS receipt_file VARCHAR(255),
ADD COLUMN IF NOT EXISTS reference_no VARCHAR(100),
ADD COLUMN IF NOT EXISTS status ENUM('Pending','Paid','Cancelled') DEFAULT 'Paid';

-- 13. RBAC, Audit Logs & Settings
CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_name VARCHAR(50) UNIQUE NOT NULL,
  description VARCHAR(255)
);

ALTER TABLE roles ADD COLUMN IF NOT EXISTS description VARCHAR(255);

CREATE TABLE IF NOT EXISTS permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  perm_key VARCHAR(100) UNIQUE NOT NULL,
  perm_group VARCHAR(50) NOT NULL,
  description VARCHAR(255)
);

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT NOT NULL,
  perm_id INT NOT NULL,
  PRIMARY KEY (role_id, perm_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (perm_id) REFERENCES permissions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  user_name VARCHAR(150),
  module VARCHAR(100) NOT NULL,
  action VARCHAR(100) NOT NULL,
  record_id INT,
  details TEXT,
  ip_address VARCHAR(45),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Seed basic roles
INSERT INTO roles (role_name, description) VALUES
('Super Admin', 'Full system access'),
('Admin', 'Administrative access'),
('Finance Manager', 'Financial and invoice management'),
('HR Manager', 'Team and payroll management'),
('Sales Manager', 'Client and work management'),
('Employee', 'Basic view access'),
('Viewer', 'Read-only access')
ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 14. Invoice Counter Table for Sequential Invoice Numbers
CREATE TABLE IF NOT EXISTS invoice_counter (
  id INT PRIMARY KEY,
  last_number INT NOT NULL DEFAULT 10000
);

INSERT INTO invoice_counter (id, last_number) VALUES (1, 10000)
ON DUPLICATE KEY UPDATE id=id;

-- 15. Extend Invoice Items Table
ALTER TABLE invoice_items
ADD COLUMN IF NOT EXISTS discount DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS handler_name VARCHAR(255) NULL;

