# Software requirements specification — GentleCare

Status: reconstruction of the implemented system, 2026-11-15. This document describes what the delivered app does, so the SRS and the demonstration cannot drift apart.

## 1. Purpose

GentleCare lets patients find OB-GYN providers, see available times, and book an appointment without phone calls. Doctors publish availability and manage visits. Clinics have an account for future scheduling features. The system is a single centralized web application written for a school capstone; it is not a medical record system and must not be used for real patient data in its current demo deployment.

## 2. Actors

| Actor | Description |
| --- | --- |
| Patient | Person seeking an appointment; can search, book, cancel, and receive notifications |
| Doctor | Licensed provider; manages locations, slots, appointments, and receives notifications |
| Clinic | Clinic account; currently a dashboard and planned scheduling features |
| Email provider | Brevo; delivers verification codes |
| Map tile provider | OpenStreetMap; serves map images to the patient map |
| System/CLI | Scheduled sweeper and seed scripts run by the operator |

## 3. Functional requirements

### 3.1 Account and session

- **FR-01** A visitor can register as patient, doctor, or clinic with name, email, password, optional phone, and role-specific fields.
- **FR-02** Registration sends a six-digit code to the email through Brevo. The account stays unverified until the code is entered.
- **FR-03** The code expires after 15 minutes and is invalidated after five wrong attempts.
- **FR-04** Login requires a verified email and the correct password.
- **FR-05** A successful login creates a fresh session and rotates the CSRF token.
- **FR-06** Logout destroys the session and clears the cookie.
- **FR-07** Five failed logins per 15 minutes per email+IP return HTTP 429.
- **FR-08** Each role is redirected to its own dashboard and cannot open another role's actions.

### 3.2 Provider discovery

- **FR-09** A signed-in patient can list doctors and clinics with name, specialty, address, and optional coordinates.
- **FR-10** The patient can filter by text.
- **FR-11** The patient can view providers on a map; providers without coordinates remain in the list with a note.
- **FR-12** The map library is served by the application itself; only tile images come from OpenStreetMap.

### 3.3 Availability

- **FR-13** A doctor can publish a slot with location, date, start time, and duration.
- **FR-14** Local times are interpreted in the practice location's IANA time zone and stored in UTC.
- **FR-15** The system rejects a slot in the past, an end before the start, and a duplicate start for the same doctor.
- **FR-16** A doctor can list only their own slots; a patient can list only available future slots.
- **FR-17** A slot that is currently reserved does not appear as available.

### 3.4 Booking

- **FR-18** A patient can book an available slot and must choose at least one purpose.
- **FR-19** Choosing "Other" requires a description.
- **FR-20** A booking creates a confirmed appointment, purpose rows, a reservation, and a history row in one transaction.
- **FR-21** A patient can hold only one active appointment at a time.
- **FR-22** A slot can be held by only one patient at a time, including under simultaneous requests.
- **FR-23** The confirmation shows provider, location, local date, local time, purposes, and status.

### 3.5 Appointment lifecycle

- **FR-24** A patient or the owning doctor can cancel a confirmed appointment; this releases the slot and notifies the other party.
- **FR-25** The owning doctor can mark a confirmed appointment completed or no-show; both release the slot and notify the patient.
- **FR-26** A confirmed appointment whose slot has ended is completed automatically and releases its reservation.
- **FR-27** Every transition is appended to appointment history with actor, action, reason, and UTC time.
- **FR-28** A patient and a doctor can list their own appointments with status and UTC timestamps.

### 3.6 Practice locations

- **FR-29** A doctor can create and update practice locations with a PSGC-validated Philippine address and optional coordinates.
- **FR-30** The system rejects invalid PSGC parent/child combinations and non-text names.
- **FR-31** Location time zones default to `Asia/Manila`.
- **FR-32** Owners can only read and change their own locations.

### 3.7 Notifications and audit

- **FR-33** Cancellation, completion, and no-show create a notification for the other party.
- **FR-33a** A signed-in user can list their own latest notifications and mark them read; other users' rows are never returned or updated.
- **FR-34** Authentication and appointment events are written to `audit_log` with user, result, IP, and UTC time.
- **FR-35** Audit rows are not editable from the browser.

### 3.8 Clinic account

- **FR-36** A clinic can sign in and see its dashboard.
- **FR-37** Clinic-wide appointment management and reports are labelled as planned; controls that have no function are disabled.

### 3.9 Administration and operations

- **FR-38** The schema installs from `Database/Ob-Gyn.sql` into an empty database.
- **FR-39** `seed_sample_data.php` creates the documented sample accounts and is idempotent.
- **FR-40** `prepare_demo.php` creates upcoming weekday slots for verified doctors with mapped locations and is idempotent.
- **FR-41** `sweep_appointments.php` can be scheduled to close finished appointments and release reservations.

## 4. Non-functional requirements

| ID | Requirement | How it is met |
| --- | --- | --- |
| NFR-01 | Passwords and verification codes are never stored in plaintext | Argon2id hashes only |
| NFR-02 | All state-changing requests are CSRF-protected | Session token + `X-CSRF-Token` + `hash_equals` |
| NFR-03 | All SQL is injection-safe | PDO prepared statements, no string interpolation of user values |
| NFR-04 | API responses are not frameable or MIME-sniffable | `X-Frame-Options: DENY`, `nosniff`, CSP |
| NFR-05 | Pages are protected by a Content-Security-Policy | Per-directory `.htaccess`, self-hosted assets |
| NFR-06 | No secrets in the repository | Secrets outside web root, config gitignored, scan in evidence |
| NFR-07 | Role separation is enforced server-side | Role gate plus ownership predicates |
| NFR-08 | 50 simultaneous signed-in users get p95 ≤ 300 ms | Measured p95 164 ms at concurrency 50 |
| NFR-09 | No failed requests at expected load | 0 failures in all load runs |
| NFR-10 | The system degrades without data corruption under stress | c=800: latency rises, zero failures, recovery |
| NFR-11 | Time-zone correctness | UTC storage, IANA zone per location |
| NFR-12 | Fresh install is reproducible | Verified against an empty database |
| NFR-13 | Errors are actionable | Provider-specific messages and status codes |
| NFR-14 | The UI states what is not implemented | Preview and planned notices |
| NFR-15 | Works in a current Chromium browser at 1366×768 and above | Browser checks; responsive CSS |

## 5. External interfaces

| Interface | Direction | Format | Failure handling |
| --- | --- | --- | --- |
| Brevo email API | outbound HTTPS | JSON | Fail closed; account not created; actionable message |
| OpenStreetMap tiles | inbound images | PNG | Map status message; list remains usable |
| PSGC dataset | local JSON | JSON | Validation error 422 |
| Browser geolocation | inbound | JS API | Optional; form works without it |

## 6. Data requirements

- Accounts: unique email, role, Argon2id password hash, verification state.
- Profiles: patient (name, phone, birth date, emergency contact, address), doctor (name, phone, licence, specialty, zone), clinic (name, phone, zone, optional coordinates).
- Scheduling: locations, slots, appointments, purposes, reservations, history.
- Communication: notifications, audit log, rate limits.
- Retention: capstone demo data only; no real patient records. Clinical-record editing is not persisted.

Detailed columns, types, keys, and indexes are in [03-database-design.md](03-database-design.md).

## 7. Constraints and assumptions

- Single centralized web app; no native mobile client.
- No admin portal; provider verification is a database/CLI operation.
- Laptop-hosted demo; production deployment would need HTTPS, backups, and monitoring.
- Philippine address validation depends on the bundled PSGC quarterly dataset.
- Brevo requires a verified sender and, if enabled, an authorised outbound IP.
- The app assumes the server clock is correct; UTC is used for all scheduling decisions.

## 8. Acceptance criteria

1. All FRs above are demonstrated or explicitly labelled as planned.
2. All automated tests pass.
3. No open critical or high defects.
4. 15 UAT participants complete the script; medians meet the test plan targets.
5. Load test shows zero failures and p95 ≤ 300 ms at the expected concurrency.
6. A fresh clone can install the schema and demonstrate the core journey.

## 9. Traceability

| Requirement group | Implementation | Verification |
| --- | --- | --- |
| FR-01–FR-08 | `Backend/api/auth.php`, `bootstrap.php` | Integration suite, manual auth checks |
| FR-09–FR-12 | `Patient/patient_dash.html`, `providers.php` | Browser check, UAT U2 |
| FR-13–FR-17 | `slots.php` | Integration suite slots checks |
| FR-18–FR-23 | `appointments.php` | Integration suite booking checks, concurrency test |
| FR-24–FR-28 | `appointments_manage.php` | Integration suite management checks, lifecycle suite |
| FR-29–FR-32 | `locations.php` | Integration suite validation checks |
| FR-33–FR-35 | `notifications.php`, `notifications`, `audit_log` | Integration suite, browser check, SQL inspection |
| FR-36–FR-37 | `Clinic/clinic.js` | Browser check |
| FR-38–FR-41 | Scripts and `Database/Ob-Gyn.sql` | Fresh-install verification |
| NFR-01–NFR-07 | Security controls | `02-security-implementation.md` |
| NFR-08–NFR-10 | Performance | `05-test-report.md` |
| NFR-11–NFR-15 | Time zones, install, wording | Integration suite, browser checks |
