ALTER TABLE chat_sessions
  MODIFY session_type ENUM('general','after_school','night','weekly_review') NOT NULL DEFAULT 'general';

CREATE TABLE weekly_review_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  week_start DATE NOT NULL,
  week_end DATE NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL UNIQUE,
  selected_items_json LONGTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_weekly_review_student_week (student_id, week_start),
  CONSTRAINT fk_weekly_review_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_weekly_review_session FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
