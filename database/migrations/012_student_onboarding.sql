ALTER TABLE student_profiles
  ADD COLUMN onboarding_step TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER grade,
  ADD COLUMN onboarding_completed_at DATETIME NULL AFTER onboarding_step;

-- Accounts that already existed before onboarding was introduced keep using the app normally.
UPDATE student_profiles
SET onboarding_step = 4, onboarding_completed_at = CURRENT_TIMESTAMP
WHERE onboarding_completed_at IS NULL;
