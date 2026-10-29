-- Track review progress so a targeted recall check can be added only when useful.
ALTER TABLE chat_sessions
  ADD COLUMN review_stage ENUM('activity','duration','recall','difficulty','knowledge_check','completed') NOT NULL DEFAULT 'recall' AFTER questions_per_subject;

ALTER TABLE chat_session_subjects
  ADD COLUMN review_stage ENUM('recall','difficulty','knowledge_check','completed') NOT NULL DEFAULT 'recall' AFTER subject;

-- Keep any sessions already in progress on their pre-Phase-2 flow.
UPDATE chat_sessions s
SET s.review_stage = CASE
  WHEN s.session_type = 'night' THEN CASE
    WHEN (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user') = 0 THEN 'activity'
    WHEN (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user') = 1 THEN 'duration'
    WHEN (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user') = 2 THEN 'difficulty'
    ELSE 'completed'
  END
  WHEN s.session_type = 'after_school' AND NOT EXISTS (SELECT 1 FROM chat_session_subjects css WHERE css.session_id = s.id) THEN CASE
    WHEN (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user') = 0 THEN 'recall'
    WHEN (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user') = 1 THEN 'difficulty'
    ELSE 'completed'
  END
  ELSE s.review_stage
END
WHERE s.ended_at IS NULL AND s.session_type IN ('after_school','night');

UPDATE chat_session_subjects css
JOIN chat_sessions s ON s.id = css.session_id
SET css.review_stage = CASE
  WHEN (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user') >= css.position * s.questions_per_subject THEN 'completed'
  WHEN (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user') >= (css.position - 1) * s.questions_per_subject + 1 THEN 'difficulty'
  ELSE 'recall'
END
WHERE s.ended_at IS NULL AND s.session_type = 'after_school';
