-- Link privately stored learning photos to the chat message that uploaded them.
ALTER TABLE learning_problems
  ADD COLUMN source_item_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id,
  ADD UNIQUE KEY uq_learning_problem_source_item (source_item_key);

CREATE TABLE learning_photo_chat_messages (
  photo_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (photo_id, message_id),
  INDEX idx_learning_photo_chat_session (session_id, message_id),
  CONSTRAINT fk_learning_photo_chat_photo FOREIGN KEY (photo_id) REFERENCES learning_photos(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_photo_chat_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_photo_chat_session FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
