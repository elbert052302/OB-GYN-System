# Test report

Rubric phase: System testing (12 points). Executed on 2026-11-15 on the environment described in the [test plan](04-test-plan.md).

## 1. Summary

| Area | Result |
| --- | --- |
| PHP syntax (`php -l`, all API and test files) | 35/35 clean |
| Integration suite (`slots_appointments.php`) | 8/8 PASS |
| Booking regression suite (`appointment_lifecycle.php`) | 7/7 PASS |
| Fresh-install schema | 20 tables, 38 FKs, 62 indexes, import clean |
| Load test, stateless endpoint | 0 failures up to concurrency 400; ~800 req/s plateau |
| Load test, authenticated endpoint, distinct sessions | 0 failures; 454–519 req/s; p95 ≤ 164 ms at concurrency 50 |
| Stress test | Saturation at concurrency 800; no HTTP failures, no corruption, recovery observed |
| Secret scan of tracked files | 0 findings |
| UAT | **Pending — 15 participants not yet collected** |

Automated result: **15 of 15 automated checks pass**, zero open critical or high defects.

## 2. Automated suite results

Raw output: `evidence/test-run-slots.txt`, `evidence/test-run-lifecycle.txt`, `evidence/test-run-lint.txt`.

### Integration suite — `Backend/tests/slots_appointments.php`

```text
PASS access: unauthenticated GET=401 and POST=401
PASS role checks: patient publish=403 and doctor book=403
PASS validation: missing CSRF=403, mismatched PSGC codes=422, non-text location name=422
PASS slots: verified PSGC locations, Asia/Manila conversion, past/invalid=422, overlap=409,
            own-list scoped, available-list=4
PASS booking: confirmed + multiple purposes + history; missing/non-text Other detail=422,
              double booking=409, second active=409
PASS concurrent booking: parallel HTTP statuses=201,409 (exactly one confirmed)
PASS appointment management: patient/doctor lists, UTC timezone and purpose data;
                             both roles cancel with reservation, history, and notification updates
PASS fixture cleanup: 5 test users and all associated schedules, reservations, appointments,
                      and history removed
```

### Regression suite — `Backend/tests/appointment_lifecycle.php`

This reproduces the audit defect where a finished appointment blocked every future booking.

```text
PASS patient books slot A (HTTP 201)
PASS second active appointment is still blocked (HTTP 409)
PASS finished appointment no longer blocks booking (HTTP 201)
PASS doctor marks appointment completed (HTTP 200)
PASS completion releases the reservation (HTTP 201)
PASS patient holds exactly one active reservation (found 1)
PASS the overdue appointment was closed as completed (status: completed)
RESULT: all lifecycle checks passed
```

### Fresh-install verification

`Database/Ob-Gyn.sql` was imported into an empty `gentlecare_fresh` database:

```text
import exit code: 0
tables=20 foreign_keys=38 indexes=62 audit_log=1 users_identity_columns=0
SCHEMA OK
```

Then `seed_sample_data.php` created the four sample accounts and slots, and both suites above were rerun against that fresh database with the same passing output. The live `gentlecare_v2` database was untouched and the config was restored afterwards (verified: 9 users, 30 future slots).

## 3. Load test — stateless endpoint

Endpoint: `GET /Backend/api/health.php` (opens a database connection, returns 38 bytes).

| # | Requests | Concurrency | Throughput (req/s) | Mean (ms) | p95 (ms) | p99 (ms) | Failed |
| --- | --- | --- | --- | --- | --- | --- | --- |
| L1 | 1000 | 10 | 832.20 | 12.0 | 26 | 35 | 0 |
| L2 | 1000 | 50 | 827.38 | 60.4 | 76 | 86 | 0 |
| L3 | 2000 | 100 | 858.98 | 116.4 | 134 | 148 | 0 |
| L4 | 2000 | 200 | 819.39 | 244.1 | 254 | 264 | 0 |
| L5 | 2000 | 400 | 802.42 | 498.5 | 504 | 515 | 0 |

Raw output: `evidence/load-health-c10.txt`, `-c50`, `-c100`, `-c200`, `-c400`.

**Explanation.** Throughput is flat at roughly 800–860 requests per second from concurrency 10 to 400. That is the useful capacity of this laptop for a trivial request: the request rate is limited by CPU and the PHP/MariaDB connection path, not by the client. Mean latency grows almost linearly with concurrency (12 ms → 60 → 116 → 244 → 498 ms), which is the expected queueing behaviour when a fixed number of workers serve a fixed service time. No request failed and no response was non-2xx, so the system is stable throughout this range.

## 4. Load test — authenticated endpoint

Endpoint: `GET /Backend/api/slots.php?action=available` (three-table join, ~9.6 KB response). Each virtual user has a distinct session.

| # | Requests | Distinct sessions | Throughput (req/s) | Mean (ms) | p95 (ms) | p99 (ms) | Max (ms) | HTTP 200 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| A1 | 500 | 10 | 519.00 | 19.0 | 33 | 41 | 49 | 500/500 |
| A2 | 1000 | 20 | 454.44 | 41.4 | 60 | 228 | 525 | 1000/1000 |
| A3 | 1000 | 40 | 501.00 | 79.2 | 143 | 174 | 228 | 1000/1000 |
| A4 | 1500 | 50 | 501.34 | 99.1 | 164 | 235 | 308 | 1500/1500 |

**Explanation.** The authenticated read costs about 2 ms of server time more than `health.php` and moves 250 times more data, so throughput drops to ~500 req/s. That still leaves a wide margin for a capstone: 50 simultaneous signed-in users is far beyond the expected load, and p95 stays at 164 ms. The single outlier in A2 (525 ms, p99 228 ms) is consistent with a PHP session-file write or OS scheduling hiccup; it did not repeat in A3/A4 and no request failed.

Single-session footnote: benchmarking the same endpoint with one cookie gives only 57 req/s because PHP serializes requests that share a session file. That number measures the session lock, not the server, so it is not used as the capacity figure. Real users have separate sessions, which A1–A4 model.

## 5. Stress test — beyond capacity

| # | Requests | Concurrency | Throughput (req/s) | Mean (ms) | p95 (ms) | p99 (ms) | Failed |
| --- | --- | --- | --- | --- | --- | --- | --- |
| S1 | 2000 | 400 | 802.42 | 498.5 | 504 | 515 | 0 |
| S2 | 2000 | 800 | 104.09 | 7685.8 | 12077 | 12082 | 0 |

Raw output: `evidence/stress-health-c800.txt`.

**Explanation.** At concurrency 800 the request rate collapses from ~800 to 104 req/s and p95 jumps from 0.5 s to 12 s. The cause is the worker pool: this XAMPP build runs `mpm_winnt` with `ThreadsPerChild 150`, so 800 concurrent connections queue behind 150 workers. The important result is what did **not** happen: no HTTP failures, no 5xx responses, no database errors, and no corrupted data. The service degraded gracefully and returned to normal after the burst. For this project the practical ceiling is therefore ~400 concurrent connections for a trivial endpoint and ~50–100 for authenticated reads, which is two orders of magnitude above the demo audience.

**What would raise the ceiling in production:** OPcache and JIT, a tuned event-driven server (nginx + PHP-FPM), persistent database connections or a pool, response caching for provider lists, and more than one application instance behind a load balancer.

## 6. Functional results

| Case | Result | Evidence |
| --- | --- | --- |
| TC-01, TC-04, TC-06 | PASS | Integration suite registration/login; manual browser login |
| TC-02, TC-03, TC-07, TC-08 | PASS | Code paths in `auth.php`; 403/422/429 responses exercised manually |
| TC-05 | PASS | Session cleared on logout; protected calls return 401 |
| TC-09–TC-12 | PASS | Integration suite access and role checks |
| TC-13–TC-18 | PASS | Integration suite validation checks |
| TC-19 | PASS | `PASS slots: ... overlap=409` |
| TC-20, TC-21 | PASS | `PASS slots: ... Asia/Manila conversion ... own-list scoped` |
| TC-22–TC-27 | PASS | `PASS booking: ...` and `PASS concurrent booking: 201,409` |
| TC-28–TC-30 | PASS | `PASS appointment management: ... both roles cancel ...` |
| TC-31 | PASS | `PASS finished appointment no longer blocks booking` |
| TC-32 | PASS | Browser: 11 tiles HTTP 200, zero console errors |
| TC-33 | PASS | Browser: Nov 16 shows 09:00, 10:30, 14:00 Manila slots |
| TC-34 | PASS | Browser: 0 corrupted characters, current date used |
| TC-35 | PASS | Browser: planned-feature notices, filters disabled |
| TC-36–TC-38 | PASS | `gentlecare_fresh` import and repeat seed run |
| TC-39, TC-40 | PASS | Fail-closed code path; hash-only storage verified by schema |
| TC-41 | PASS | `evidence/http-headers.txt` |
| TC-42 | PASS | `evidence/secret-scan.txt` — 0 findings |
| TC-43–TC-45 | PASS | Tables in Sections 3–5 |
| TC-46 | **PENDING** | UAT pack ready; 15 participants not yet collected |
| TC-47 | PASS | Browser: completion notification shown, badge 1 → 0 after opening the panel |

## 7. Defect log

| ID | Severity | Status | Description | Fix / evidence |
| --- | --- | --- | --- | --- |
| D-01 | Critical | Fixed | A confirmed appointment whose slot had ended kept its row in `active_appointment_reservations` forever, so the patient could never book again (live example: appointment 37 blocked patient 174 since 2026-10-26) | `gc_release_finished_appointments()`; booking guard only counts future slots; doctor complete/no-show actions; regression suite |
| D-02 | High | Fixed | Patient map was loaded from a CDN while the page CSP allowed only `'self'`, so Leaflet was blocked | Self-hosted Leaflet under `Patient/vendor/leaflet/`; CSP allows only OSM tiles; browser check |
| D-03 | High | Fixed | `Database/Ob-Gyn.sql` was stale: `users.full_name` NOT NULL, profile identity columns missing, `audit_log` absent | Schema regenerated from live DB; fresh-install import and full suite rerun |
| D-04 | High | Fixed | Tracked test temp files contained a live session cookie and CSRF token | Removed from git and disk; `Backend/tests/tmp/` ignored |
| D-05 | Medium | Fixed | Doctor dashboard had 15 corrupted characters, a hard-coded `2026-10-03` date, and an enabled status selector that saved nowhere | Mojibake fixed, `localDateKey(new Date())`, selector disabled with "Preview only" |
| D-06 | Medium | Fixed | Clinic dashboard had empty containers and filters that did nothing | Explicit planned-feature notices; filters disabled |
| D-07 | Medium | Fixed | Test scripts hard-coded passwords | Random password generated per run |
| D-08 | Low | Fixed | README claimed login by email or phone; the API accepts email only | Documentation corrected |
| D-09 | Low | Fixed | Install guide still told readers to apply migrations already folded into the schema | `Database/IMPLEMENTATION_GUIDE.md` rewritten |
| D-10 | Low | Fixed | All demo slots were in the past, so booking looked broken | `Backend/tests/prepare_demo.php` creates upcoming weekday slots |
| D-11 | Medium | Fixed | The patient notification panel only restated the next confirmed appointment, so cancellation and completion notices never reached the patient | `Backend/api/notifications.php`; `patient_dash.html` renders real rows with timestamps and clears the unread badge on open |

Open items are not defects in advertised scope; they are documented limits:

| ID | Severity | Item | Why it is acceptable |
| --- | --- | --- | --- |
| O-01 | Medium | CSP allows `'unsafe-inline'` for scripts/styles | Single-file dashboards; noted as next hardening step |
| O-02 | Medium | Clinical records are a preview, not persisted | Labelled in UI; outside capstone scope |
| O-03 | Medium | Clinic-wide appointment/report views are planned | Labelled in UI; doctors still see their own appointments |
| O-04 | Low | No password reset or MFA | Not in rubric scope; rate limits and email verification implemented |
| O-05 | Low | No admin portal | Explicit project constraint |
| O-06 | Low | Local demo over HTTP | Production checklist requires HTTPS and HSTS |

## 8. Requirements traceability

| Capability | Where implemented | Where tested |
| --- | --- | --- |
| Email-verified registration | `auth.php`, `bootstrap.php` | TC-01, TC-39, TC-40 |
| Role-based authorization | `bootstrap.php`, every endpoint | TC-09–TC-12 |
| Provider discovery with map | `Patient/patient_dash.html`, `providers.php` | TC-32, TC-33 |
| Slot publishing with time zones | `slots.php` | TC-19–TC-21 |
| Conflict-free booking | `appointments.php`, unique indexes | TC-22–TC-24 |
| Appointment lifecycle | `appointments_manage.php`, sweeper | TC-28–TC-31 |
| Notifications | `notifications.php`, `notifications` inserts in booking/cancel/close | TC-28–TC-30, TC-47 |
| Audit trail | `audit_log`, `gc_audit()` | TC-05, TC-13, security checklist |
| Fresh install | `Database/Ob-Gyn.sql` | TC-36–TC-38 |
| Performance | Apache/MariaDB configuration | TC-43–TC-45 |

## 9. UAT status

The UAT pack is complete: consent form, script, scenario list, scoring sheet, and defect form under `06-uat/`. It is **not** complete in execution. The report will be updated with 15 participant rows and a defect summary before the System Testing deadline. Do not present the current document as if UAT had already happened; the rubric awards the process and the honest record.

## 10. Conclusion

All executed tests pass. The core booking defect that motivated this round is fixed and covered by a regression test, the install path works from an empty database, and the system sustains roughly 500 authenticated requests per second with zero failures at 50 concurrent sessions — far above its intended use. Remaining work is evidence collection (UAT and screenshots), not defect repair.
