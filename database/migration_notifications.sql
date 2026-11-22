-- database/migration_notifications.sql
-- Centralized Multi-Channel Notification Engine Schema

-- 1. Notifications Table (In-App CRM Notifications)
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  type VARCHAR(64) NOT NULL DEFAULT 'system_alert',
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  link VARCHAR(255) NULL,
  payload JSON NULL,
  channel VARCHAR(32) NOT NULL DEFAULT 'crm',
  status ENUM('unread', 'read', 'archived') DEFAULT 'unread',
  is_read TINYINT(1) DEFAULT 0,
  read_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_unread (user_id, is_read, created_at),
  INDEX idx_type_created (type, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Notification Queue Table (Background & Scheduled Processing)
CREATE TABLE IF NOT EXISTS notification_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  employee_id INT NULL,
  notification_type VARCHAR(64) NOT NULL,
  idempotency_key VARCHAR(128) NOT NULL UNIQUE,
  channels VARCHAR(255) NOT NULL DEFAULT '["crm","email"]',
  payload JSON NULL,
  scheduled_at DATETIME NOT NULL,
  status ENUM('pending', 'processing', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  max_attempts INT NOT NULL DEFAULT 3,
  last_error TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  INDEX idx_queue_status_sched (status, scheduled_at),
  INDEX idx_queue_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Notification Logs Table (Audit & Debugging across all channels)
CREATE TABLE IF NOT EXISTS notification_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  notification_id INT NULL,
  queue_id INT NULL,
  user_id INT NOT NULL,
  channel ENUM('crm', 'email', 'web_push', 'mobile_push') NOT NULL,
  status ENUM('success', 'failed', 'skipped') NOT NULL,
  response TEXT NULL,
  error_message TEXT NULL,
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_log_user_channel (user_id, channel, sent_at),
  INDEX idx_log_status (status, sent_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Notification Devices Table (Web Push Subscriptions & Mobile FCM Tokens)
CREATE TABLE IF NOT EXISTS notification_devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  device_id VARCHAR(128) NOT NULL,
  platform ENUM('web', 'android', 'ios') NOT NULL DEFAULT 'web',
  type VARCHAR(32) NOT NULL DEFAULT 'browser',
  push_token TEXT NOT NULL,
  p256dh_key TEXT NULL,
  auth_key TEXT NULL,
  browser VARCHAR(100) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_device (user_id, device_id),
  INDEX idx_device_active (is_active, platform),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Notification Settings Table (Admin Configurable Key-Value Store)
CREATE TABLE IF NOT EXISTS notification_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(64) NOT NULL UNIQUE,
  setting_value TEXT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. User Notification Preferences Table
CREATE TABLE IF NOT EXISTS user_notification_preferences (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  notification_type VARCHAR(64) NOT NULL,
  crm_enabled TINYINT(1) NOT NULL DEFAULT 1,
  email_enabled TINYINT(1) NOT NULL DEFAULT 1,
  web_push_enabled TINYINT(1) NOT NULL DEFAULT 1,
  mobile_push_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_pref_type (user_id, notification_type),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Insert Default Notification Settings
INSERT INTO notification_settings (setting_key, setting_value) VALUES
('attendance_reminder_enabled', '1'),
('attendance_expected_time', '10:00:00'),
('attendance_reminder_delay', '2'),
('attendance_channels', '["crm","email","web_push","mobile_push"]'),
('punchout_reminder_enabled', '1'),
('punchout_expected_time', '20:00:00'),
('punchout_reminder_delay', '2'),
('punchout_auto_half_day', '1'),
('punchout_channels', '["crm","email","web_push","mobile_push"]'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_user', ''),
('smtp_pass', ''),
('smtp_secure', 'tls'),
('smtp_from_email', ''),
('smtp_from_name', 'VibeSoft CMS'),
('webpush_vapid_public', ''),
('webpush_vapid_private', ''),
('webpush_subject', 'mailto:admin@vibesoft.in'),
('fcm_server_key', '')
ON DUPLICATE KEY UPDATE setting_key=setting_key;
