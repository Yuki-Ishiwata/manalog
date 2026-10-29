-- Depends on 011_weekly_review_sessions.sql. Additive Phase 3 data only.
ALTER TABLE notification_preferences
  ADD COLUMN weekly_review_enabled BOOLEAN NOT NULL DEFAULT FALSE AFTER paused,
  ADD COLUMN weekly_review_day TINYINT UNSIGNED NOT NULL DEFAULT 6 AFTER weekly_review_enabled,
  ADD COLUMN weekly_review_time TIME NOT NULL DEFAULT '10:00:00' AFTER weekly_review_day;

CREATE TABLE weekly_review_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  week_start DATE NOT NULL,
  week_end DATE NOT NULL,
  source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('proposed','in_progress','completed','partial','not_done') NOT NULL DEFAULT 'proposed',
  generation_source ENUM('ai','rules','none') NOT NULL DEFAULT 'none',
  recommendations_json LONGTEXT NOT NULL,
  model VARCHAR(100) NULL,
  generated_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_weekly_review_plan_student_week (student_id, week_start),
  INDEX idx_weekly_review_plan_range (week_start, week_end, status),
  CONSTRAINT fk_weekly_review_plan_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE weekly_review_sessions
  ADD COLUMN plan_id BIGINT UNSIGNED NULL AFTER session_id,
  ADD UNIQUE KEY uq_weekly_review_session_plan (plan_id),
  ADD CONSTRAINT fk_weekly_review_session_plan FOREIGN KEY (plan_id) REFERENCES weekly_review_plans(id) ON DELETE SET NULL;

CREATE TABLE weekly_review_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  item_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  position TINYINT UNSIGNED NOT NULL,
  learning_record_id BIGINT UNSIGNED NULL,
  details_json LONGTEXT NOT NULL,
  status ENUM('proposed','in_progress','completed','not_done','needs_review') NOT NULL DEFAULT 'proposed',
  last_outcome ENUM('recalled','hinted','unsure','unable','reported_done','photo_submitted','unclear') NULL,
  understanding_level ENUM('not_understood','needs_help','partial','mostly_understood','confident') NULL,
  last_reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_weekly_review_item_key (plan_id, item_key),
  UNIQUE KEY uq_weekly_review_item_position (plan_id, position),
  INDEX idx_weekly_review_item_followup (student_id, status, updated_at),
  CONSTRAINT fk_weekly_review_item_plan FOREIGN KEY (plan_id) REFERENCES weekly_review_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_weekly_review_item_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_weekly_review_item_record FOREIGN KEY (learning_record_id) REFERENCES learning_records(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE weekly_review_api_usage (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NULL,
  session_id BIGINT UNSIGNED NULL,
  request_type ENUM('proposal','conversation') NOT NULL,
  model VARCHAR(100) NOT NULL,
  response_id VARCHAR(100) NULL,
  input_tokens INT UNSIGNED NULL,
  output_tokens INT UNSIGNED NULL,
  input_usd_per_million DECIMAL(12,6) NULL,
  output_usd_per_million DECIMAL(12,6) NULL,
  estimated_cost_usd DECIMAL(14,8) NULL,
  success BOOLEAN NOT NULL,
  error_code VARCHAR(100) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_weekly_review_usage_student_date (student_id, created_at),
  CONSTRAINT fk_weekly_review_usage_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_weekly_review_usage_plan FOREIGN KEY (plan_id) REFERENCES weekly_review_plans(id) ON DELETE SET NULL,
  CONSTRAINT fk_weekly_review_usage_session FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE weekly_review_responses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  user_message_id BIGINT UNSIGNED NOT NULL,
  assistant_message_id BIGINT UNSIGNED NOT NULL,
  answer_status ENUM('recalled','hinted','unsure','unable','reported_done','photo_submitted','unclear') NOT NULL DEFAULT 'unclear',
  understanding_level ENUM('not_understood','needs_help','partial','mostly_understood','confident') NULL,
  understanding_note TEXT NULL,
  usage_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_weekly_review_response_user_message (user_message_id),
  INDEX idx_weekly_review_response_item (item_id, created_at),
  CONSTRAINT fk_weekly_review_response_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_weekly_review_response_plan FOREIGN KEY (plan_id) REFERENCES weekly_review_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_weekly_review_response_item FOREIGN KEY (item_id) REFERENCES weekly_review_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_weekly_review_response_session FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_weekly_review_response_user_message FOREIGN KEY (user_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_weekly_review_response_assistant_message FOREIGN KEY (assistant_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_weekly_review_response_usage FOREIGN KEY (usage_id) REFERENCES weekly_review_api_usage(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE weekly_review_notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  week_start DATE NOT NULL,
  due_at DATETIME NOT NULL,
  session_id BIGINT UNSIGNED NULL,
  status ENUM('pending','sending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  lease_until DATETIME NULL,
  sent_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_weekly_review_notification_student_week (student_id, week_start),
  INDEX idx_weekly_review_notification_status (status, due_at, lease_until),
  CONSTRAINT fk_weekly_review_notification_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_weekly_review_notification_session FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
