-- Give students default daily reminder times only for missing weekday rows.
-- Existing rows are preserved so an intentionally blank or custom time is not overwritten.
INSERT IGNORE INTO notification_schedule (student_id, day_of_week, after_school_time, night_time)
SELECT u.id, days.day_of_week, '17:00:00', '22:00:00'
FROM users AS u
CROSS JOIN (
  SELECT 1 AS day_of_week UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
) AS days
WHERE u.role = 'student';
