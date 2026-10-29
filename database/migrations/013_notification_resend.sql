ALTER TABLE notification_deliveries
  ADD COLUMN resend_status ENUM('pending','sending','sent','failed','skipped') NULL DEFAULT NULL AFTER last_error,
  ADD COLUMN resend_due_at DATETIME NULL AFTER resend_status,
  ADD COLUMN resend_lease_until DATETIME NULL AFTER resend_due_at,
  ADD COLUMN resend_sent_at DATETIME NULL AFTER resend_lease_until,
  ADD COLUMN resend_error VARCHAR(1000) NULL AFTER resend_sent_at,
  ADD INDEX idx_notification_resend (resend_status, resend_due_at);
