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

## Step 6: slot, booking, access-control, and concurrency tests

- Ran with `php Backend/tests/timezone_registration.php`, `php Backend/tests/auth_flow.php`, and `php Backend/tests/slots_appointments.php` against the local Apache API and `gentlecare_v2`.
- All PHP API and test files passed `php -l`.
- The endpoint suite used temporary verified test accounts and deleted all fixtures after the run. Parallel booking workers synchronized at a start barrier before submitting concurrent HTTP requests.

```text
PASS doctor: missing=400, invalid=400, valid=201 (Asia/Calcutta stored; test account removed)
PASS clinic: missing=400, invalid=400, valid=201 (UTC stored; test account removed)
PASS auth patient: register=201, unverified-login=403, verify=200, session=200, missing-CSRF=403, logout=200/session-after-logout=401, login=200; fixture removed
PASS auth doctor: register=201, unverified-login=403, verify=200, session=200, missing-CSRF=403, logout=200/session-after-logout=401, login=200; fixture removed
PASS auth clinic: register=201, unverified-login=403, verify=200, session=200, missing-CSRF=403, logout=200/session-after-logout=401, login=200; fixture removed
PASS access: unauthenticated GET=401 and POST=401
PASS role checks: patient publish=403 and doctor book=403
PASS validation: missing CSRF=403, invalid timezone=422, non-text location field=422
PASS slots: location=201, UTC and America/New_York conversion, DST gap/past/invalid=422, overlap=409, own-list scoped, available-list=4
PASS booking: confirmed + multiple purposes + history; missing/non-text Other detail=422, double booking=409, second active=409
PASS concurrent booking: parallel HTTP statuses=201,409 (exactly one confirmed)
PASS fixture cleanup: 5 test users and all associated schedules, reservations, appointments, and history removed
```

- Final `gentlecare_v2` fixture counts: users=0, locations=0, slots=0, appointments=0, reservations=0.
- Re-ran the entire suite after adding non-text field validation checks; all output above is from that final run.

## Current implementation validation - 2026-10-20

- Applied `Backend/migrations/20261020_auth_rate_limits.sql` to the local `gentlecare_v2` database.
- Ran the standalone PHP tests with Apache and MariaDB available, including the new expiration, rate-limit, and appointment-management coverage. The seeded sample data was present; the schedule tests correctly scoped their fixture assertions to their own doctor.
- Ran `php -l` over every PHP file under `Backend/`: **PASS**.

```text
=== auth_flow.php ===
PASS auth patient: register=201, unverified-login=403, verify=200, session=200, missing-CSRF=403, logout=200/session-after-logout=401, login=200; fixture removed
PASS auth doctor: register=201, unverified-login=403, verify=200, session=200, missing-CSRF=403, logout=200/session-after-logout=401, login=200; fixture removed
PASS auth clinic: register=201, unverified-login=403, verify=200, session=200, missing-CSRF=403, logout=200/session-after-logout=401, login=200; fixture removed
=== expired_registration.php ===
PASS expired registration: email, phone, and license released for re-registration; fixture removed
=== geography.php ===
PASS PSGC dataset counts, province/NCR/independent-region hierarchy, and invalid parent rejection
=== rate_limit.php ===
PASS rate limits: successful login clears failures; login=5 per 15 minutes; registration=30 per hour
=== slots_appointments.php ===
PASS access: unauthenticated GET=401 and POST=401
PASS role checks: patient publish=403 and doctor book=403
PASS validation: missing CSRF=403, mismatched PSGC codes=422, non-text location name=422
PASS slots: verified PSGC locations, Asia/Manila conversion, past/invalid=422, overlap=409, own-list scoped, available-list=4
PASS booking: confirmed + multiple purposes + history; missing/non-text Other detail=422, double booking=409, second active=409
PASS concurrent booking: parallel HTTP statuses=201,409 (exactly one confirmed)
PASS appointment management: patient/doctor lists, UTC timezone and purpose data; both roles cancel with reservation, history, and notification updates
PASS fixture cleanup: 5 test users and all associated schedules, reservations, appointments, and history removed
=== timezone_registration.php ===
PASS doctor: missing=400, invalid=400, valid=201 (Asia/Manila stored; test account removed)
PASS clinic: missing=400, invalid=400, valid=201 (Asia/Manila stored; test account removed)
```

- Ran `seed_sample_data.php` twice: **PASS**. Final sample counts: four verified users across patient/doctor/clinic roles, two mapped practice locations, and three future slots; no duplicate sample records were created.
- Apache returned HTTP 403 for a PHP test file, the new migration, `Database/Ob-Gyn.sql`, and the PSGC JSON data file.
- Browser checks with API fixtures confirmed patient booking payload submission, doctor slot publishing in `Asia/Manila`, cached CSRF refresh after HTTP 403, friendly non-JSON handling, and a request timeout at approximately 15 seconds.
- Sample users and their locations/slots intentionally remain in the local development database. No passwords, verification codes, or session cookies were recorded.
