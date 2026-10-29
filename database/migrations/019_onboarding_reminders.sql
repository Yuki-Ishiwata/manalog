CREATE TABLE onboarding_reminder_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  reminder_date DATE NOT NULL,
  due_at DATETIME NOT NULL,
  status ENUM('pending','sending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  lease_until DATETIME NULL,
  sent_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_onboarding_reminder_student_day (student_id, reminder_date),
  INDEX idx_onboarding_reminder_status (status, due_at, lease_until),
  CONSTRAINT fk_onboarding_reminder_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE onboarding_reminder_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_id BIGINT UNSIGNED NOT NULL,
  endpoint_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('pending','sending','sent','failed','invalid') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  lease_until DATETIME NULL,
  sent_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  UNIQUE KEY uq_onboarding_reminder_endpoint (delivery_id, endpoint_hash),
  CONSTRAINT fk_onboarding_reminder_device FOREIGN KEY (delivery_id) REFERENCES onboarding_reminder_deliveries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
