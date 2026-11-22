-- database/migration_realtime.sql
-- Schema for Realtime Event Streaming and Missed Event Synchronization

CREATE TABLE IF NOT EXISTS realtime_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_name VARCHAR(64) NOT NULL,
  resource_type VARCHAR(32) NOT NULL,
  resource_id INT NOT NULL,
  action VARCHAR(32) NOT NULL,
  target_roles VARCHAR(255) NULL,
  target_user_id INT NULL,
  payload JSON NOT NULL,
  actor_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_stream_poll (id, target_user_id),
  INDEX idx_created (created_at),
  INDEX idx_resource (resource_type, resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
