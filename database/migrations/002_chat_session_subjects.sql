CREATE TABLE IF NOT EXISTS chat_session_subjects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  position TINYINT UNSIGNED NOT NULL,
  period_number TINYINT UNSIGNED NOT NULL,
  subject VARCHAR(100) NOT NULL,
  UNIQUE KEY uq_session_subject_position (session_id, position),
  CONSTRAINT fk_chat_session_subjects_session FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
