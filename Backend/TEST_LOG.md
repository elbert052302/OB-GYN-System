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

## Step 4: authentication end to end

- Local `Backend/config.php` has `development.expose_verification_code_on_localhost` enabled for loopback testing; `Backend/config.example.php` keeps it **false**. The fallback is returned only when email delivery fails, the flag is enabled, and the HTTP client address is `127.0.0.1` or `::1`.
- `php Backend/tests/auth_flow.php`: **PASS** for Patient, Doctor, and Clinic. Each flow registered, rejected an unverified login, verified the email using the localhost development fallback, confirmed the session, rejected logout without CSRF, logged out and confirmed session lookup returned 401, then logged in and logged out successfully.

```text
PASS auth patient: register=201, unverified-login=403, verify=200, session=200, missing-CSRF=403, logout=200/session-after-logout=401, login=200; fixture removed
PASS auth doctor: register=201, unverified-login=403, verify=200, session=200, missing-CSRF=403, logout=200/session-after-logout=401, login=200; fixture removed
PASS auth clinic: register=201, unverified-login=403, verify=200, session=200, missing-CSRF=403, logout=200/session-after-logout=401, login=200; fixture removed
```

- `gentlecare_v2.users` after the test: **0**. Verification codes and passwords were not logged.
