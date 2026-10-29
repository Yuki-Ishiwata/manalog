ALTER TABLE users
  MODIFY COLUMN role ENUM('admin','teacher','parent','student') NOT NULL;

CREATE TABLE parent_student_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_user_id BIGINT UNSIGNED NOT NULL,
  student_user_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_parent_student (parent_user_id, student_user_id),
  INDEX idx_parent_student_student (student_user_id),
  CONSTRAINT fk_parent_link_parent FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_parent_link_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
