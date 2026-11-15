# GentleCare database guide

## Fresh install

`Database/Ob-Gyn.sql` is the canonical, structure-only schema for a brand-new database. It contains all 20 tables in foreign-key order, so no `FOREIGN_KEY_CHECKS` override is needed.

```powershell
& C:\xampp\mysql\bin\mysql.exe --protocol=TCP --host=127.0.0.1 --port=3307 --user=root -e "CREATE DATABASE IF NOT EXISTS gentlecare_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
& $env:ComSpec /d /s /c '"C:\xampp\mysql\bin\mysql.exe" --protocol=TCP --host=127.0.0.1 --port=3307 --user=root gentlecare_v2 < "C:\xampp\htdocs\OB-GYN\Database\Ob-Gyn.sql"'
```

Then optionally load demo data:

```powershell
& C:\xampp\php\php.exe Backend\tests\seed_sample_data.php
& C:\xampp\php\php.exe Backend\tests\prepare_demo.php
```

## Migrations are historical

Files in `Database/migrations` and `Backend/migrations` document how an existing database was upgraded. A fresh install from `Ob-Gyn.sql` already includes their changes, so do not import them again on a new database.

- `20261101_profile_identity_migration.sql` / `20261101_cleanup_users_identity_columns.sql` moved `full_name` and `phone` from `users` into the patient, doctor, and clinic profile tables. The current schema is already the post-cleanup shape.
- `Backend/migrations/20261018_provider_map_locations.sql` added provider latitude/longitude.
- `Backend/migrations/20261020_patient_addresses.sql` added the nullable patient address column.
- `Backend/migrations/20261020_auth_rate_limits.sql` added `auth_rate_limits`.
- `Backend/migrations/20261021_audit_log.sql` added `audit_log` (idempotent, `CREATE TABLE IF NOT EXISTS`).

## Upgrading an existing database

1. Back up the database first (`mysqldump` or phpMyAdmin export).
2. Apply only the migrations the target database is missing.
3. Keep `users.id` stable and keep the unique email constraint.
4. Do not add a phone uniqueness constraint.
5. Run `Backend/tests/slots_appointments.php` afterwards; it covers registration, slot publishing, booking, and cancellation against the live API.
