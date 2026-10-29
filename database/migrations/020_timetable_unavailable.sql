ALTER TABLE student_profiles
  ADD COLUMN timetable_unavailable BOOLEAN NOT NULL DEFAULT FALSE AFTER grade;
