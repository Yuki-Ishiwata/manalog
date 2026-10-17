CREATE TABLE review_excluded_subjects (
  student_id BIGINT UNSIGNED NOT NULL,
  subject VARCHAR(100) NOT NULL,
  PRIMARY KEY (student_id, subject),
  CONSTRAINT fk_review_excluded_subjects_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
