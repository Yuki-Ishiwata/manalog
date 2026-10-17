CREATE TABLE notification_schedule (
  student_id BIGINT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  after_school_time TIME NULL,
  night_time TIME NULL,
  PRIMARY KEY (student_id, day_of_week),
  CONSTRAINT chk_notification_day_of_week CHECK (day_of_week BETWEEN 1 AND 7),
  CONSTRAINT fk_notification_schedule_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notification_schedule (student_id, day_of_week, after_school_time, night_time)
SELECT p.student_id, days.day_of_week, p.after_school_time, p.night_time
FROM notification_preferences AS p
CROSS JOIN (
  SELECT 1 AS day_of_week UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
) AS days
WHERE p.after_school_time IS NOT NULL OR p.night_time IS NOT NULL;
