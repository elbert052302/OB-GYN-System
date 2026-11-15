# Test plan

Rubric phase: System testing (12 points). Companion document: [test report](05-test-report.md).

## 1. Objectives

1. Prove every advertised user journey works from the browser against the real API and database.
2. Prove the booking rules hold under simultaneous requests.
3. Prove the API rejects unauthenticated, unauthorised, malformed, and cross-site requests.
4. Measure throughput and find the saturation point on the demo hardware.
5. Collect user-acceptance feedback from at least 15 people and close every critical/high defect.

## 2. Scope

**In scope**

- Registration, Brevo verification, login, logout, session expiry, role redirects.
- Patient provider search, map, provider details, booking flow, cancellation.
- Doctor location management, slot publishing, appointment list, complete/no-show.
- Clinic account access and planned-feature messaging.
- Fresh install from `Database/Ob-GYN.sql`.
- Load and stress behaviour of the API.
- Security controls listed in [02-security-implementation.md](02-security-implementation.md).

**Out of scope (deliberate, labelled in the UI)**

- Doctor clinical-record editing (preview only).
- Clinic-wide appointment management and reports (planned).
- Admin portal (not part of the capstone scope).
- Payment, SMS, telemedicine video, prescriptions.
- Multi-tenant production deployment, horizontal scaling, disaster recovery drills.

## 3. Test strategy

| Level | What it covers | Tooling |
| --- | --- | --- |
| Static | PHP syntax on every API and test file | `php -l` |
| Integration | Real HTTP requests, real MariaDB, session/CSRF/roles | `Backend/tests/slots_appointments.php` |
| Regression | The specific "past appointment blocks booking forever" defect | `Backend/tests/appointment_lifecycle.php` |
| Concurrency | Two simultaneous bookings for the same slot | `booking_worker.php` launched by the integration suite |
| Security | 401/403/415/422/429 paths, CSRF, role gates | Integration suite plus manual checks |
| Load | Steady traffic inside expected capacity | ApacheBench, multi-session PHP client |
| Stress | Traffic beyond capacity until degradation | ApacheBench |
| Acceptance | Real users following the UAT script | UAT pack in `06-uat/` |
| Recovery | Fresh install, seed, rerun suites | Scratch database `gentlecare_fresh` |

Test data is generated per run with a random suffix and removed in a `finally` block, so a run never leaves bookings or users behind. The four sample accounts are seeded separately and documented in `Backend/README.md`.

## 4. Environment

| Item | Value |
| --- | --- |
| Machine | Acer laptop, Windows, local XAMPP |
| Web server | Apache 2.4.58 (Win64), `mpm_winnt`, 150 worker threads |
| PHP | 8.2.12, `pdo_mysql`, `curl`, `mbstring` |
| Database | MariaDB 10.4.32, port 3307 |
| Browser | Chromium (OpenCode in-app browser) |
| Load client | ApacheBench 2.3 + PHP `curl_multi` for multi-session runs |
| Network | Loopback `127.0.0.1`; no external latency except Brevo and OSM tiles |
| Date | 2026-11-15 |

Loopback removes network variance, so the numbers show application capacity rather than internet speed. That is appropriate for a capstone; the report states it explicitly.

## 5. Entry and exit criteria

**Entry**

- Fresh schema imports with zero SQL errors.
- `php -l` passes on all PHP files.
- Apache and MariaDB running; `health.php` returns 200.

**Exit**

- All automated checks pass.
- Zero open critical or high defects.
- Load test shows acceptable latency at expected concurrency.
- Stress behaviour is understood and documented.
- UAT reaches 15 participants with median score ≥ 4/5 and no open high defect.

## 6. Functional test cases

IDs are referenced by the test report.

| ID | Area | Case | Expected |
| --- | --- | --- | --- |
| TC-01 | Auth | Register patient with valid data and Brevo code | Account created, code mailed, verify succeeds, login allowed |
| TC-02 | Auth | Login before verification | 403, unverified message |
| TC-03 | Auth | Wrong password 5 times | 429 after the limit |
| TC-04 | Auth | Login with correct password | 200, session cookie, CSRF token rotated |
| TC-05 | Auth | Logout then reuse the old cookie | 401 on protected endpoints |
| TC-06 | Auth | Register with duplicate email | 409/422, no second account |
| TC-07 | Auth | Verify with wrong code 5 times | Code invalidated, new code required |
| TC-08 | Auth | Verify after 15 minutes | Expired message |
| TC-09 | Access | Call protected endpoint without login | 401 |
| TC-10 | Access | Patient calls doctor publish | 403 |
| TC-11 | Access | Doctor calls patient booking | 403 |
| TC-12 | Access | Patient reads another patient's appointments | Only own rows returned |
| TC-13 | CSRF | POST with missing token | 403 |
| TC-14 | CSRF | POST with token from another session | 403 |
| TC-15 | Input | Non-JSON content type | 415 |
| TC-16 | Input | Body larger than 16 KB | 413 |
| TC-17 | Input | Invalid PSGC parent/child combination | 422 |
| TC-18 | Input | Past slot or end before start | 422 |
| TC-19 | Input | Overlapping slot for the same doctor | 409 |
| TC-20 | Slots | Publish slot in Asia/Manila | Stored as UTC, shown back in Manila time |
| TC-21 | Slots | Doctor sees only own slots | Scoped list |
| TC-22 | Booking | Book available slot | 201, confirmed, purposes saved, history row |
| TC-23 | Booking | Book same slot twice | Second request 409 |
| TC-24 | Booking | Two patients book the same slot simultaneously | Exactly one 201, one 409 |
| TC-25 | Booking | Patient books a second active appointment | 409 |
| TC-26 | Booking | Book a past slot | 422 |
| TC-27 | Booking | "Other" purpose without description | 422 |
| TC-28 | Lifecycle | Cancel as patient | Status cancelled, reservation released, notification, history |
| TC-29 | Lifecycle | Complete as doctor | Status completed, reservation released, notification, history |
| TC-30 | Lifecycle | Mark no-show as doctor | Status no_show, reservation released, notification, history |
| TC-31 | Lifecycle | Past confirmed appointment | Auto-completed, reservation released, patient can book again |
| TC-32 | UI | Patient map | Leaflet initializes, OSM tiles load, no console errors |
| TC-33 | UI | Patient booked-slot calendar | Dates and times in practice time zone |
| TC-34 | UI | Doctor dashboard today's queue | Uses the real current date, no mojibake |
| TC-35 | UI | Clinic dashboard | Explicit planned-feature notices, filters disabled |
| TC-36 | DB | Fresh install of `Database/Ob-Gyn.sql` | 20 tables, 38 FKs, imports cleanly |
| TC-37 | DB | Seed sample data on fresh install | Four verified accounts, locations, future slots |
| TC-38 | DB | Rerun seed script | Idempotent, no duplicates |
| TC-39 | Email | Brevo rejects request | Account not created, actionable error |
| TC-40 | Email | Verification code storage | Only Argon2id hash, never plaintext |
| TC-41 | Security | Response headers | CSP, DENY, nosniff, referrer policy present |
| TC-42 | Security | Secret scan of tracked files | Zero findings |
| TC-43 | Performance | 1000 requests at concurrency 10 | Zero failures, stable latency |
| TC-44 | Performance | Concurrency 100/200/400 | Zero failures, latency grows linearly |
| TC-45 | Performance | Concurrency 800 | Documented degradation, no data corruption |
| TC-46 | Acceptance | 15 users follow the UAT script | Scores collected, defects logged |
| TC-47 | UI | Patient notification panel | Real notifications with timestamps; unread badge clears on open |

## 7. Load and stress design

**Load profile (inside expected capacity)**

- Stateless endpoint: `GET /Backend/api/health.php`, which opens a database connection and returns 38 bytes. This isolates server + DB connection handling.
- Authenticated endpoint: `GET /Backend/api/slots.php?action=available`, which joins doctors, locations, and slots and returns about 9.6 KB. This is the heaviest read a patient performs.
- One session per virtual user. This matters because PHP serializes requests that share one session file; using a single cookie would measure the session lock, not the server. The multi-session client creates ten throwaway verified patients, logs each in, and rotates sessions across requests.

**Stress profile (beyond capacity)**

- Same stateless endpoint, concurrency 100 → 200 → 400 → 800.
- Stop when throughput collapses or failures appear. On this machine the collapse happens at 800; no HTTP failure occurs, so the limit is saturation rather than a crash.

**Metrics**

- Throughput (requests/second), mean, p50, p95, p99, longest request, failed requests, non-2xx responses.
- Pass condition for load: zero failed requests, p95 under 300 ms at the expected concurrency (50).
- Pass condition for stress: no corrupted data, no unhandled error, service recovers after the burst.

## 8. Defect severity

| Severity | Definition | Response |
| --- | --- | --- |
| Critical | Data loss, wrong medical/appointment state, auth bypass, secret exposure | Fix before any demo |
| High | A core journey is blocked or gives a wrong result | Fix before UAT exit |
| Medium | Workaround exists, cosmetic in a secondary screen | Fix if time allows |
| Low | Wording, alignment, non-blocking polish | Backlog |

## 9. Risks

| Risk | Mitigation |
| --- | --- |
| Venue network blocks Brevo | Fail-closed path documented; demo can use the local code switch briefly, then revert |
| OSM tiles unavailable | Map container shows a status message; list view still works |
| 15 UAT participants hard to schedule | Forms in `06-uat/` are ready; recruit classmates and relatives early |
| Demo laptop differs from build laptop | Backup zip + `mysqldump` + secrets file checklist in `10-submission-checklist.md` |
| Single-session load test misleads | Multi-session client is the reported figure; single-session result is kept as a explained footnote |
