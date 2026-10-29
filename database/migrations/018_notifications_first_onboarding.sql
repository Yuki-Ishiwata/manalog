-- Restart incomplete onboarding at the new first step (home screen and push setup).
-- Completed students keep their current onboarding state.
UPDATE student_profiles
SET onboarding_step = 1
WHERE onboarding_completed_at IS NULL;
