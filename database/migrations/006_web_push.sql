ALTER TABLE notification_schedule
  ADD COLUMN after_school_enabled BOOLEAN NOT NULL DEFAULT TRUE AFTER after_school_time,
  ADD COLUMN night_enabled BOOLEAN NOT NULL DEFAULT TRUE AFTER night_time;

ALTER TABLE notification_preferences
  ADD COLUMN paused BOOLEAN NOT NULL DEFAULT FALSE AFTER enabled;

CREATE TABLE push_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  endpoint_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth VARCHAR(255) NOT NULL,
  content_encoding VARCHAR(20) NOT NULL DEFAULT 'aes128gcm',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  revoked_at TIMESTAMP NULL,
  UNIQUE KEY uq_push_endpoint_hash (endpoint_hash),
  INDEX idx_push_student_active (student_id, revoked_at),
  CONSTRAINT fk_push_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE daily_review_sessions (
  student_id BIGINT UNSIGNED NOT NULL,
  review_date DATE NOT NULL,
  review_type ENUM('after_school','night') NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (student_id, review_date, review_type),
  UNIQUE KEY uq_daily_review_session (session_id),
  CONSTRAINT fk_daily_review_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_daily_review_session FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  review_date DATE NOT NULL,
  notification_type ENUM('after_school','night') NOT NULL,
  due_at DATETIME NOT NULL,
  session_id BIGINT UNSIGNED NULL,
  status ENUM('pending','sending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  lease_until DATETIME NULL,
  sent_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_notification_delivery (student_id, review_date, notification_type),
  INDEX idx_notification_delivery_status (status, due_at),
  CONSTRAINT fk_delivery_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_session FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_delivery_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_id BIGINT UNSIGNED NOT NULL,
  endpoint_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('pending','sending','sent','failed','invalid') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sent_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  UNIQUE KEY uq_delivery_endpoint (delivery_id, endpoint_hash),
  CONSTRAINT fk_delivery_device FOREIGN KEY (delivery_id) REFERENCES notification_deliveries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
