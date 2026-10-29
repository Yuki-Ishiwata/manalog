CREATE TABLE instructor_learning_summaries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  date_from DATE NOT NULL,
  date_to DATE NOT NULL,
  source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  summary_json LONGTEXT NOT NULL,
  model VARCHAR(100) NOT NULL,
  generated_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_instructor_summary_period (student_id, date_from, date_to),
  INDEX idx_instructor_summary_generated (generated_at),
  CONSTRAINT fk_instructor_summary_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
