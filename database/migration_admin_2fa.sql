-- database/migration_admin_2fa.sql
-- Adds Admin Two-Factor Authentication (2FA) support and OTP verification table

ALTER TABLE settings 
ADD COLUMN IF NOT EXISTS admin_2fa_email VARCHAR(150) DEFAULT 'rishabhtyagi162@gmail.com',
ADD COLUMN IF NOT EXISTS admin_2fa_enabled TINYINT(1) DEFAULT 1;

UPDATE settings SET admin_2fa_email = 'rishabhtyagi162@gmail.com', admin_2fa_enabled = 1 WHERE admin_2fa_email IS NULL OR admin_2fa_email = '';

CREATE TABLE IF NOT EXISTS admin_login_otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email_sent_to VARCHAR(150) NOT NULL,
    otp_code VARCHAR(10) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    attempts INT DEFAULT 0,
    is_used TINYINT(1) DEFAULT 0,
    expires_at DATETIME NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(user_id),
    INDEX(expires_at),
    INDEX(is_used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
