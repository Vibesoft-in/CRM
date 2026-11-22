-- database/migration_attendance.sql
-- Attendance Management System - Full Schema

-- ============================================================
-- 1. Attendance Settings Table
-- ============================================================
CREATE TABLE IF NOT EXISTS attendance_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  expected_check_in TIME NOT NULL DEFAULT '10:00:00',
  expected_check_out TIME NOT NULL DEFAULT '19:00:00',
  grace_minutes INT NOT NULL DEFAULT 15,
  late_deduction_per_hour DECIMAL(8,2) NOT NULL DEFAULT 0,
  half_day_threshold_hours DECIMAL(4,2) NOT NULL DEFAULT 4.00,
  working_days_per_month INT NOT NULL DEFAULT 26,
  overtime_after_hours DECIMAL(4,2) NOT NULL DEFAULT 9.00,
  geofence_enabled TINYINT(1) NOT NULL DEFAULT 1,
  office_latitude DECIMAL(10,8) NOT NULL DEFAULT 29.22006923,
  office_longitude DECIMAL(11,8) NOT NULL DEFAULT 78.96778362,
  geofence_radius_meters INT NOT NULL DEFAULT 200,
  office_address VARCHAR(255) NOT NULL DEFAULT 'Vibesoft, Chamunda Vihar Phase 1, Ramnagar Road, Kashipur, Uttarakhand 244713',
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO attendance_settings
  (expected_check_in, expected_check_out, grace_minutes, late_deduction_per_hour, half_day_threshold_hours, working_days_per_month, overtime_after_hours, geofence_enabled, office_latitude, office_longitude, geofence_radius_meters, office_address)
VALUES
  ('10:00:00', '19:00:00', 15, 50.00, 4.00, 26, 9.00, 1, 29.22006923, 78.96778362, 200, 'Vibesoft, Chamunda Vihar Phase 1, Ramnagar Road, Kashipur, Uttarakhand 244713')
ON DUPLICATE KEY UPDATE id=id;

-- ============================================================
-- 2. Attendance Holidays Table
-- ============================================================
CREATE TABLE IF NOT EXISTS attendance_holidays (
  id INT AUTO_INCREMENT PRIMARY KEY,
  holiday_date DATE NOT NULL UNIQUE,
  holiday_name VARCHAR(150) NOT NULL,
  holiday_type ENUM('national','optional','company') DEFAULT 'national',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- 3. Attendance Table (Daily Punch Records)
-- ============================================================
CREATE TABLE IF NOT EXISTS attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  attendance_date DATE NOT NULL,
  status ENUM('present','absent','half_day','holiday','on_leave','work_from_home','late') DEFAULT 'absent',
  check_in TIME NULL,
  check_out TIME NULL,
  late_minutes INT DEFAULT 0,
  total_hours DECIMAL(5,2) DEFAULT 0,
  overtime_hours DECIMAL(5,2) DEFAULT 0,
  leave_type VARCHAR(100) NULL,
  notes TEXT NULL,
  marked_by INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_attendance (employee_id, attendance_date),
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- ============================================================
-- 4. Daily Work Reports Table (Evening Report)
-- ============================================================
CREATE TABLE IF NOT EXISTS daily_work_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  report_date DATE NOT NULL,
  morning_tasks TEXT NULL,
  evening_tasks TEXT NULL,
  projects_worked TEXT NULL,
  clients_worked TEXT NULL,
  hours_worked DECIMAL(4,2) DEFAULT 0,
  blockers TEXT NULL,
  plan_for_tomorrow TEXT NULL,
  mood ENUM('great','good','okay','tired','stressed') DEFAULT 'good',
  rating TINYINT DEFAULT NULL COMMENT '1-5 rating by manager',
  review_note TEXT NULL,
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_daily_report (employee_id, report_date),
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);
