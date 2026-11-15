# Security implementation

Rubric phase: Security (8 points). This document maps each control to the code that implements it and to evidence the panel can inspect.

## 1. Threat model in one paragraph

The app handles names, birth dates, addresses, phone numbers, and appointment reasons. The realistic risks for a capstone deployment are credential stuffing, session theft, CSRF, injection, cross-site scripting, unauthorised access to another user's appointments, spam registration, and leaked API keys. The controls below target those risks. The system is not presented as production-hardened; Section 9 lists the honest limitations.

## 2. Authentication

| Control | Implementation |
| --- | --- |
| Password hashing | `password_hash($password, PASSWORD_ARGON2ID)`; `password_verify()` on login |
| Hash upgrade | `password_needs_rehash()` rehashes on successful login |
| Email uniqueness | `UNIQUE KEY uq_users_email` |
| Email verification | Six-digit code, Argon2id hash at rest, 15-minute expiry, five attempts, 10 requests per 15 minutes |
| Unverified accounts | Login returns 403 until `email_verified_at` is set |
| Login throttling | 5 failures per 15 minutes per email+IP; HTTP 429 afterwards |
| Session fixation | `session_regenerate_id(true)` after login and after verification |
| Logout | Session data cleared, cookie invalidated, CSRF token rotated |

Reference: `Backend/api/auth.php`, `Backend/api/bootstrap.php`.

## 3. Session and cookie hardening

Set in `Backend/api/bootstrap.php` lines 4–11:

- `session.use_strict_mode = 1` — rejects attacker-supplied session IDs.
- `session.use_only_cookies = 1` — no session ID in URLs.
- `session.cookie_httponly = 1` — JavaScript cannot read the cookie.
- `session.cookie_samesite = Lax` — blocks cross-site POST cookies while keeping normal navigation usable.
- `session.cookie_secure = 1` when HTTPS is detected.
- `Strict-Transport-Security` is sent only over HTTPS.

Honest note for the panel: a deployed copy must run behind HTTPS so `Secure` is active. The local XAMPP demo is HTTP by design.

## 4. CSRF

Every state-changing request is JSON-only and requires a session-bound token:

- `GET /auth.php?action=csrf` issues a 64-hex-character token.
- Clients send it as `X-CSRF-Token`.
- The server compares with `hash_equals()` (`gc_api_require_csrf()` in `bootstrap.php`).
- Tokens rotate on login, registration verification, and logout.
- The API rejects `Content-Type` other than `application/json`, which also blocks simple HTML form CSRF.

Test evidence: `PASS validation: missing CSRF=403` in `evidence/test-run-slots.txt`.

## 5. SQL injection

- Every query uses PDO prepared statements with bound parameters.
- Dynamic SQL is limited to fixed column names chosen by role (`appointment.patient_user_id` vs `appointment.doctor_user_id`), never user text.
- PDO is configured with `ATTR_ERRMODE => ERRMODE_EXCEPTION` and `ATTR_EMULATE_PREPARES => false`.

Reference: `Backend/api/bootstrap.php` (`gc_db()`), all files under `Backend/api/`.

## 6. Output encoding and XSS

- The single-page dashboards build DOM with `textContent` and `createElement`, not `innerHTML`, for user-controlled values.
- API responses are `application/json` with `X-Content-Type-Options: nosniff`.
- A Content-Security-Policy on every HTML entry point restricts scripts and styles to the same origin. The patient map allows images only from the OpenStreetMap tile hosts.

Captured headers: `evidence/http-headers.txt`.

```text
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline';
  style-src 'self' 'unsafe-inline'; img-src 'self' data: https://*.tile.openstreetmap.org;
  frame-ancestors 'none'; base-uri 'self';
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
X-Content-Type-Options: nosniff
```

`'unsafe-inline'` remains because the dashboards are single-file pages with inline scripts and styles. Moving those to external files is the next step if the CSP must be strict. Saying this plainly is better than pretending the policy is stricter than it is.

## 7. Authorization

| Endpoint | Patient | Doctor | Clinic | Anonymous |
| --- | --- | --- | --- | --- |
| `auth.php?action=csrf`, `login`, `register`, `verify` | yes | yes | yes | yes |
| `auth.php?action=me`, `logout` | yes | yes | yes | no |
| `slots.php?action=available` | yes | no | no | no |
| `slots.php?action=mine` | no | yes | no | no |
| `slots.php?action=publish` | no | yes | no | no |
| `appointments.php?action=book` | yes | no | no | no |
| `appointments_manage.php?action=mine` | own only | own only | no | no |
| `appointments_manage.php?action=cancel` | own only | own only | no | no |
| `appointments_manage.php?action=complete`, `no_show` | no | own only | no | no |
| `notifications.php?action=list`, `read` | own only | own only | own only | no |
| `providers.php` | yes | yes | yes | no, when signed in only |
| `profile.php` | own | own | own | no |

Role gates are enforced server-side with `gc_api_require_user('patient'|'doctor'|'clinic')`; ownership checks are separate SQL predicates on the session user ID. The integration suite verifies patient-publish=403 and doctor-book=403.

## 8. Audit logging

`audit_log` records authentication and appointment events with user ID, action, result, IP, and UTC timestamp. It is written by `gc_audit()` inside the API and cannot be modified from the browser. Examples of recorded actions: `login` (success/failed/unverified), `register`, `verify`, `book_appointment`, `cancel_appointment`, `complete_appointment`, `mark_appointment_no_show`.

Live check:

```sql
SELECT action, result, COUNT(*) FROM audit_log GROUP BY action, result ORDER BY COUNT(*) DESC;
```

## 9. Defects fixed during hardening

| Defect | Impact | Fix |
| --- | --- | --- |
| Finished appointment kept its reservation forever | A patient could never book again after one past visit | `gc_release_finished_appointments()` closes overdue appointments and deletes reservations; booking only blocks future slots; regression test `appointment_lifecycle.php` |
| Map was loaded from a CDN but CSP allowed only `'self'` | Map silently failed; console CSP errors | Leaflet self-hosted under `Patient/vendor/leaflet`, CSP allows only OSM tiles |
| Test temp files committed with a live session cookie and CSRF token | Secret material in the repository | Removed from git and disk; `Backend/tests/tmp/` ignored |
| Hard-coded test passwords in two test scripts | Plaintext credentials in the repository | Random password per run |
| Stale install schema | Fresh clone could not run | `Database/Ob-Gyn.sql` regenerated and verified against a clean database |

## 10. Secret management

- No API keys, database passwords, or real account passwords are tracked.
- `Backend/config.php` is gitignored; the real values sit in `C:\xampp\secrets\gentlecare.php`.
- `.htaccess` denies direct HTTP access to `config.php` and to implementation files (`bootstrap.php`, `rate_limit.php`, `geography_data.php`).
- A tracked-file scan for Brevo keys, AWS keys, private-key blocks, long token assignments, and session cookies returns zero findings (`evidence/secret-scan.txt`).
- `git log --all -S 'xkeysib-'` finds only the README placeholder.

## 11. Known limitations (say these before the panel asks)

1. Local demo runs over HTTP; production must terminate TLS and enable HSTS and `Secure` cookies.
2. CSP still allows inline script because the dashboards embed their JavaScript; extracting it is the next hardening step.
3. No multi-factor authentication, password reset, or account lockout beyond time-window rate limits.
4. No admin portal: provider verification is a database/CLI operation by design for this capstone.
5. Clinical-record editing is a labelled preview and is not persisted, so it holds no patient data on the server.
6. Rate-limit counters are database rows; a distributed deployment would move them to Redis.

## 12. Eight-item evidence checklist for the panel

1. Argon2id hashes in `users.password_hash` (`SELECT role, LEFT(password_hash, 30) FROM users LIMIT 5;`).
2. Unverified login rejected — register without verifying, then attempt login (403).
3. Wrong password five times → HTTP 429.
4. Missing CSRF token → HTTP 403 (`evidence/test-run-slots.txt`).
5. Patient calling doctor publish → HTTP 403 (same suite).
6. Response headers screenshot (`evidence/http-headers.txt`).
7. `audit_log` rows for failed and successful logins.
8. Secret scan and `git log -S` output showing no real key in history.
