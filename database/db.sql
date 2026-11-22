-- database/db.sql (complete schema for Invoice App)

-- Users table
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(150) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','staff') DEFAULT 'staff',
  api_key VARCHAR(255) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Default Admin User (password = admin123)
INSERT INTO users (name,email,password_hash,role) VALUES
('Admin','admin@example.com',
'y$fuKVaOfNPPtKXc4apsKMt.2XFR3euS8XcBLN46vlt26qIpxGu5Ja.',
'admin')
ON DUPLICATE KEY UPDATE email=email;

-- Customers table
CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  gstin VARCHAR(30),
  phone VARCHAR(30),
  email VARCHAR(100),
  billing_address TEXT,
  shipping_address TEXT,
  state_code VARCHAR(5),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Items table
CREATE TABLE IF NOT EXISTS items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  hsn_sac VARCHAR(50),
  unit VARCHAR(20) DEFAULT 'pcs',
  default_price DECIMAL(12,2) DEFAULT 0,
  default_tax_rate DECIMAL(5,2) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Invoices table
CREATE TABLE IF NOT EXISTS invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(100) UNIQUE NOT NULL,
  customer_id INT NOT NULL,
  place_of_supply VARCHAR(10),
  notes TEXT,
  taxable_value DECIMAL(12,2) DEFAULT 0,
  tax_total DECIMAL(12,2) DEFAULT 0,
  total_amount DECIMAL(12,2) DEFAULT 0,
  created_by INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Invoice Items table
CREATE TABLE IF NOT EXISTS invoice_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  hsn_sac VARCHAR(50),
  qty DECIMAL(12,2) DEFAULT 0,
  rate DECIMAL(12,2) DEFAULT 0,
  tax_rate DECIMAL(5,2) DEFAULT 0,
  line_tax DECIMAL(12,2) DEFAULT 0,
  line_total DECIMAL(12,2) DEFAULT 0,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);

-- Settings table (company details)
CREATE TABLE IF NOT EXISTS settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_name VARCHAR(255),
  gstin VARCHAR(50),
  pan VARCHAR(50),
  address TEXT,
  phone VARCHAR(50),
  email VARCHAR(100),
  bank_details TEXT,
  logo VARCHAR(255),
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default settings row
INSERT INTO settings (company_name,email,phone)
VALUES ('My Company','info@company.com','9999999999')
ON DUPLICATE KEY UPDATE company_name=company_name;
