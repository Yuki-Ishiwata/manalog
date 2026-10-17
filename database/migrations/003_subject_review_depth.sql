ALTER TABLE chat_sessions
  ADD COLUMN questions_per_subject TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER session_type;
