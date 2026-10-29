-- Phase 2 learning record foundation.
-- Original chat messages and uploaded image files remain available as source evidence.

CREATE TABLE learning_units (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  subject VARCHAR(100) NOT NULL,
  unit_name VARCHAR(200) NOT NULL,
  unit_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_learning_unit_student_key (student_id, unit_key),
  INDEX idx_learning_unit_student_subject (student_id, subject),
  CONSTRAINT fk_learning_unit_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  learning_date DATE NOT NULL,
  learning_unit_id BIGINT UNSIGNED NULL,
  subject VARCHAR(100) NULL,
  unit_name VARCHAR(200) NULL,
  activity_type ENUM('school','homework','juku','other','unknown') NOT NULL DEFAULT 'unknown',
  summary TEXT NULL,
  study_minutes SMALLINT UNSIGNED NULL,
  not_understood TEXT NULL,
  next_check TEXT NULL,
  source_session_id BIGINT UNSIGNED NULL,
  idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
  extraction_version VARCHAR(40) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_learning_record_idempotency (student_id, idempotency_key),
  INDEX idx_learning_record_student_date (student_id, learning_date, id),
  INDEX idx_learning_record_unit_date (learning_unit_id, learning_date),
  INDEX idx_learning_record_session (source_session_id),
  CONSTRAINT fk_learning_record_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_record_unit FOREIGN KEY (learning_unit_id) REFERENCES learning_units(id) ON DELETE SET NULL,
  CONSTRAINT fk_learning_record_session FOREIGN KEY (source_session_id) REFERENCES chat_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_extraction_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  lease_until DATETIME NULL,
  next_attempt_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_learning_extraction_session (session_id),
  INDEX idx_learning_extraction_queue (status, next_attempt_at, lease_until, created_at),
  CONSTRAINT fk_learning_extraction_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_extraction_session FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_record_questions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  learning_record_id BIGINT UNSIGNED NOT NULL,
  sequence_number SMALLINT UNSIGNED NOT NULL,
  question_text TEXT NOT NULL,
  answer_text TEXT NULL,
  answer_status ENUM('unanswered','answered','unsure','skipped','hint_given') NOT NULL DEFAULT 'unanswered',
  ai_estimated_understanding ENUM('not_understood','needs_help','partial','mostly_understood','confident') NULL,
  understanding_note TEXT NULL,
  next_check TEXT NULL,
  source_question_message_id BIGINT UNSIGNED NULL,
  source_answer_message_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_learning_question_sequence (learning_record_id, sequence_number),
  INDEX idx_learning_question_source_question (source_question_message_id),
  INDEX idx_learning_question_source_answer (source_answer_message_id),
  CONSTRAINT fk_learning_question_record FOREIGN KEY (learning_record_id) REFERENCES learning_records(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_question_chat_question FOREIGN KEY (source_question_message_id) REFERENCES chat_messages(id) ON DELETE SET NULL,
  CONSTRAINT fk_learning_question_chat_answer FOREIGN KEY (source_answer_message_id) REFERENCES chat_messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_understanding_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  learning_unit_id BIGINT UNSIGNED NOT NULL,
  learning_record_id BIGINT UNSIGNED NULL,
  observation_source ENUM('student_self_report','ai_estimate','question_response','later_review') NOT NULL,
  understanding_level ENUM('not_understood','needs_help','partial','mostly_understood','confident') NOT NULL,
  evidence_note TEXT NULL,
  confidence DECIMAL(5,4) NULL,
  observed_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_understanding_student_unit_time (student_id, learning_unit_id, observed_at),
  INDEX idx_understanding_record (learning_record_id),
  CONSTRAINT chk_understanding_confidence CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 1)),
  CONSTRAINT fk_understanding_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_understanding_unit FOREIGN KEY (learning_unit_id) REFERENCES learning_units(id) ON DELETE CASCADE,
  CONSTRAINT fk_understanding_record FOREIGN KEY (learning_record_id) REFERENCES learning_records(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_photos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  storage_key VARCHAR(255) NOT NULL,
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  mime_type VARCHAR(40) NOT NULL,
  byte_size INT UNSIGNED NOT NULL,
  width SMALLINT UNSIGNED NULL,
  height SMALLINT UNSIGNED NULL,
  analysis_status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  analysis_provider VARCHAR(40) NULL,
  analysis_model VARCHAR(100) NULL,
  analysis_version VARCHAR(40) NULL,
  analysis_result LONGTEXT NULL,
  analysis_error VARCHAR(1000) NULL,
  analyzed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_learning_photo_storage_key (storage_key),
  UNIQUE KEY uq_learning_photo_student_hash (student_id, sha256),
  INDEX idx_learning_photo_student_status (student_id, analysis_status, created_at),
  CONSTRAINT fk_learning_photo_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_record_photos (
  learning_record_id BIGINT UNSIGNED NOT NULL,
  photo_id BIGINT UNSIGNED NOT NULL,
  relation_status ENUM('suggested','confirmed','unresolved','not_related') NOT NULL DEFAULT 'unresolved',
  relation_reason VARCHAR(500) NULL,
  confidence DECIMAL(5,4) NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (learning_record_id, photo_id),
  INDEX idx_learning_record_photo_photo (photo_id),
  CONSTRAINT chk_learning_photo_relation_confidence CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 1)),
  CONSTRAINT fk_learning_record_photo_record FOREIGN KEY (learning_record_id) REFERENCES learning_records(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_record_photo_photo FOREIGN KEY (photo_id) REFERENCES learning_photos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_problems (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  learning_record_id BIGINT UNSIGNED NOT NULL,
  material_name VARCHAR(200) NULL,
  page_number VARCHAR(30) NULL,
  problem_number VARCHAR(60) NULL,
  problem_text TEXT NULL,
  student_answer TEXT NULL,
  answer_result ENUM('correct','partially_correct','incorrect','ungraded') NOT NULL DEFAULT 'ungraded',
  result_source ENUM('student_mark','ai_estimate','teacher_confirmed','unknown') NOT NULL DEFAULT 'unknown',
  analysis_confidence DECIMAL(5,4) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_learning_problem_record (learning_record_id, id),
  CONSTRAINT chk_learning_problem_confidence CHECK (analysis_confidence IS NULL OR (analysis_confidence >= 0 AND analysis_confidence <= 1)),
  CONSTRAINT fk_learning_problem_record FOREIGN KEY (learning_record_id) REFERENCES learning_records(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_problem_photos (
  problem_id BIGINT UNSIGNED NOT NULL,
  photo_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (problem_id, photo_id),
  INDEX idx_learning_problem_photo_photo (photo_id),
  CONSTRAINT fk_learning_problem_photo_problem FOREIGN KEY (problem_id) REFERENCES learning_problems(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_problem_photo_photo FOREIGN KEY (photo_id) REFERENCES learning_photos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_record_chat_messages (
  learning_record_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  relation_type ENUM('evidence','question','answer','context') NOT NULL DEFAULT 'evidence',
  PRIMARY KEY (learning_record_id, message_id, relation_type),
  INDEX idx_learning_record_chat_message (message_id),
  CONSTRAINT fk_learning_record_chat_record FOREIGN KEY (learning_record_id) REFERENCES learning_records(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_record_chat_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
