# GentleCare documentation pack

GentleCare is a single centralized OB-GYN appointment web app for patients, doctors, and clinics. This folder holds the evidence for the capstone rubric.

| # | Document | Rubric phase | Status |
| --- | --- | --- | --- |
| 01 | [External API integration](01-external-api-integration.md) | External API (6) | Complete |
| 02 | [Security implementation](02-security-implementation.md) | Security (8) | Complete |
| 03 | [Database design](03-database-design.md) | Database design (8) | Complete |
| 04 | [Test plan](04-test-plan.md) | System testing (12) | Complete |
| 05 | [Test report](05-test-report.md) | System testing (12) | Complete for automated, load, and stress; UAT pending |
| 06 | [UAT pack](06-uat/UAT-plan.md) | System testing (12) | Forms ready, 15 participants pending |
| 07 | [User manual](07-user-manual.md) | Finalization (7) | Complete |
| 08 | [Software requirements specification](08-srs.md) | Planning / SRS (10) | Reconstruction of the implemented system |
| 09 | [Defense notes](09-defense-notes.md) | Defense (7) | Complete |
| 10 | [Submission checklist](10-submission-checklist.md) | Finalization (7) | Checklist ready |

Supporting evidence lives in [`evidence/`](evidence/):

- `http-headers.txt` — CSP, frame, MIME, and cache headers captured from the running app.
- `load-*.txt`, `stress-*.txt` — raw ApacheBench output behind the tables in the test report.
- `test-run-*.txt` — raw output of the automated suites.
- `secret-scan.txt` — result of the tracked-file secret scan.
- `screenshots/` — 11 UI captures (`01-login` through `11-registration`) taken from the running system: login, patient dashboard, booking calendar, review, confirmation, my appointments, provider map, doctor dashboard, doctor appointments, clinic dashboard, and registration.

## System summary

| Item | Value |
| --- | --- |
| Front end | HTML, CSS, vanilla JavaScript (`SignOrLog/`, `Patient/`, `Doctor/`, `Clinic/`) |
| Back end | PHP 8.2 JSON API (`Backend/api/`) |
| Database | MariaDB 10.4 (`gentlecare_v2`) |
| External API | Brevo transactional email (`POST https://api.brevo.com/v3/smtp/email`) |
| Maps | Leaflet 1.9.4 self-hosted, OpenStreetMap tiles |
| Local URL | `http://127.0.0.1/OB-GYN/` |

## What is real and what is a preview

Being explicit here protects the defense.

**Working end to end**

- Registration with Brevo email verification, login, logout, role-based redirects.
- Patient provider search with map/list, provider details, purpose selection, date and time selection, booking confirmation.
- Doctor practice-location management with PSGC address validation and optional map pin, slot publishing, appointment list, cancel/complete/no-show.
- Notifications written when an appointment is cancelled or closed.
- Concurrency-safe booking: one active appointment per patient, one patient per slot, enforced in SQL and verified with a parallel-request test.

**Preview or planned (clearly labelled in the UI)**

- Doctor clinical-record editing is a preview; records are not persisted to the server.
- Clinic-wide appointment listing, operational records, and reports are planned features.

Naming these in the app and in this pack is deliberate: the rubric rewards honest scope and working integration over a wide but fake surface.
