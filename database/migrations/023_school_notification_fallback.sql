ALTER TABLE notification_deliveries
  ADD COLUMN delivery_channel ENUM('push','line','email') NOT NULL DEFAULT 'push' AFTER notification_type;

CREATE TABLE notification_delivery_external (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('line','email') NOT NULL,
  recipient_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('pending','sending','sent','failed','invalid') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  lease_until DATETIME NULL,
  sent_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_external_delivery_recipient (delivery_id, channel, recipient_hash),
  INDEX idx_external_delivery_status (status, lease_until),
  CONSTRAINT fk_external_delivery FOREIGN KEY (delivery_id) REFERENCES notification_deliveries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
