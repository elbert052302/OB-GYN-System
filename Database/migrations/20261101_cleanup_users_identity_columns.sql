-- Final cleanup migration.
-- Run only after the application code has been updated and the migration has been verified.
-- This removes legacy user identity columns after their data has been transferred to profile tables.

ALTER TABLE users DROP COLUMN full_name;
ALTER TABLE users DROP COLUMN phone;

-- The unique email constraint remains in place.
-- The phone uniqueness index is intentionally not recreated in profile tables.
-- MySQL automatically removes the index tied to users.phone when the column is dropped.
-- Do not add a second DROP INDEX after dropping the column, because the key no longer exists.
