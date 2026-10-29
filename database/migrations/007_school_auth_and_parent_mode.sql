ALTER TABLE users
  ADD COLUMN auth_source ENUM('local','school') NOT NULL DEFAULT 'local' AFTER role;

CREATE TABLE student_school_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  school_provider VARCHAR(50) NOT NULL,
  school_student_id INT NOT NULL,
  linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_verified_at TIMESTAMP NULL,
  UNIQUE KEY uq_school_student_identity (school_provider, school_student_id),
  CONSTRAINT fk_school_link_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  login_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  success BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_auth_attempt_login_time (login_hash, success, created_at),
  INDEX idx_auth_attempt_ip_time (ip_hash, success, created_at),
  INDEX idx_auth_attempt_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
