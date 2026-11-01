# GentleCare database migration guide

Use this guide in phpMyAdmin only after creating a backup.

1. Back up the existing database.
2. Import the identity migration file: `Database/migrations/20261101_profile_identity_migration.sql`.
3. Verify the copied profile data in the profile tables.
4. Update the PHP backend code to read names and phone numbers from the profile tables.
5. Run the relevant app tests.
6. Only after verification, import the cleanup file: `Database/migrations/20261101_cleanup_users_identity_columns.sql`.
7. Confirm the application still works after cleanup.

Important:
- Keep users.id unchanged.
- Keep the email unique constraint.
- Do not add phone uniqueness constraints.
- Do not delete records while the migration is being validated.
- Do not disable foreign key checks.
