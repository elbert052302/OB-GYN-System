# Backend test log

## Step 2-3: timezone registration and database connection

- Environment: XAMPP PHP 8.2, Apache on localhost, MariaDB on port 3307.
- Before schema import, backed up the existing empty `gentlecare_v2` database outside the repository. Its 18 tables each contained zero rows.
- Recreated only `gentlecare_v2` and imported `Database/Ob-Gyn.sql`; verified 18 tables. The `time_zone` columns on doctor profiles, clinic profiles, and practice locations are `NOT NULL` with no default.
- `GET /OB-GYN/Backend/api/health.php`: **PASS**, HTTP 200 `{"status":"ok","database":"connected"}`.
- `php -l Backend/api/health.php`: **PASS**.
- `php -l Backend/api/auth.php`: **PASS**.
- `php -l Backend/tests/timezone_registration.php`: **PASS**.

Timezone registration endpoint test output:

```text
PASS doctor: missing=400, invalid=400, valid=201 (Asia/Calcutta stored; test account removed)
PASS clinic: missing=400, invalid=400, valid=201 (UTC stored; test account removed)
```

Verification codes and test credentials were not written to this log. Temporary test accounts were deleted after confirming their stored timezone.
