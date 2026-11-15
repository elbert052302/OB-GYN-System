# Defense notes and demo script

## 1. Five-minute demo path

Open with the problem in one sentence: *patients waste time calling clinics for OB-GYN appointments, and double bookings happen when two people call at once.*

| Time | Action | Say |
| --- | --- | --- |
| 0:00 | Show `http://127.0.0.1/OB-GYN/` | "One web app, three roles: patient, doctor, clinic." |
| 0:30 | Log in as the sample patient | "Registration is email-verified through Brevo; login rejects unverified accounts." |
| 0:50 | Open **Book Now**, filter Manila, open the map | "Providers are searched by text or map. The map is Leaflet served by the app, with OpenStreetMap tiles." |
| 1:30 | Open Dr. Santos, pick a purpose, continue | "Purposes are structured, with a required description for Other." |
| 2:00 | Pick the next weekday, choose 09:00, confirm | "Times are stored in UTC and shown in the clinic's time zone." |
| 2:30 | Show **My appointments** | "The slot is reserved. A patient can hold only one active appointment." |
| 2:50 | Log in as the doctor, open **Appointments**, publish a slot | "Doctors control their own availability and locations." |
| 3:20 | Mark the patient's visit **Complete** | "The reservation is released and the patient is notified." |
| 3:40 | Log in as the patient, show the notification, book again | "That is the defect I fixed: a finished appointment used to block booking forever. The regression test proves it can't come back." |
| 4:10 | Show `docs/05-test-report.md` tables | "Zero failures at 50 concurrent signed-in users; p95 164 ms; graceful degradation at 800 without corruption." |
| 4:40 | Show the security checklist | "Argon2id, CSRF, role gates, rate limits, CSP, audit log, no secrets in git." |
| 5:00 | Close | "Scope is honest: clinical records and clinic-wide reports are labelled as preview or planned." |

## 2. Numbers to quote

| Metric | Value |
| --- | --- |
| Automated checks | 15 of 15 pass |
| Stateless throughput | ~800–860 req/s, 0 failures, concurrency 10–400 |
| Authenticated throughput | ~454–519 req/s, 0 failures, 10–50 distinct sessions |
| p95 at 50 signed-in users | 164 ms |
| Stress knee | 800 concurrent → 104 req/s, p95 12 s, still 0 failures |
| Fresh install | 20 tables, 38 FKs, 62 indexes, suites rerun clean |
| Secret scan | 0 findings |
| Schema differences vs live | 0 |

## 3. Why each rubric area should score

- **Proposal / planning / SRS:** the SRS is reconstructed from the delivered system, so every claim is demonstrable.
- **GUI prototype:** real screens, browser-verified, responsive layout, no lorem ipsum.
- **Database design:** ERD, data dictionary from the live schema, normalisation notes, unique indexes that enforce booking rules.
- **Back end:** JSON API with role gates, validation, transactions, and status codes; 15 passing checks.
- **Front-end/back-end integration:** the browser talks to the real API and database, including file-less map assets and live booking.
- **External API:** Brevo is part of registration, fails closed, and its key is outside the repository.
- **Security:** eight-item evidence checklist in `02-security-implementation.md`.
- **System testing:** test plan, automated results, load/stress tables with explanation, defect log, and a real UAT process.
- **Finalization:** user manual, install guide, evidence pack, and submission checklist.
- **Defense:** a five-minute script with measurable claims and an honest scope.

## 4. Likely questions

**"Is this production-ready?"**
No, and the documentation says so. It is a capstone demo. Production needs HTTPS, backups, monitoring, a password-reset flow, and a hardening pass on CSP.

**"Why only one active appointment per patient?"**
It prevents duplicate visits and makes the reservation model simple. The rule is a unique index, so it holds under concurrency. A real clinic could relax it with a configurable limit.

**"What happens if two patients click Book at the same time?"**
Both requests try to insert into `active_appointment_reservations`. The unique key on `slot_id` lets exactly one succeed; the other gets 409. The test runs two parallel HTTP requests and asserts 201/409.

**"How do you know the past appointment no longer blocks booking?"**
`appointment_lifecycle.php` books a slot, moves it into the past, then books a second slot without any manual cleanup. It passes only if the sweeper releases the reservation.

**"Where is the Brevo key?"**
Outside the repository in `C:\xampp\secrets\gentlecare.php`. `Backend/config.php` is gitignored. The scan and `git log -S 'xkeysib-'` show no real key in history.

**"What did you cut?"**
An admin portal, clinical-record persistence, clinic-wide reports, password reset, and MFA. Each is either labelled in the UI or listed as a limitation. The cut list is in `05-test-report.md`.

**"What would you do with two more weeks?"**
Move inline scripts into files and tighten CSP, add password reset and MFA, implement clinical records with an audit trail, add clinic-wide scheduling, and set up CI to run the suites on every push.

**"How many users tested it?"**
The UAT pack is ready; the measured sessions are recorded in `06-uat/UAT-results-template.md`. State the real number on the day. Never inflate it.

**"Is the load test realistic?"**
It is loopback on one laptop, so it measures application capacity, not internet latency. The authenticated test uses 10–50 distinct sessions because PHP session locking would otherwise measure the lock instead of the server.

## 5. Failure drills

| If this breaks during the demo | Recovery |
| --- | --- |
| Database connection | Start MySQL, confirm port 3307, open `health.php` |
| No available slots | Run `prepare_demo.php` before the demo, or publish a slot as the doctor |
| Brevo blocked by venue network | Use prepared accounts; show stored evidence and explain fail-closed behaviour |
| Map tiles blocked | Provider list still works; explain the tile host policy |
| Browser session expired | Refresh and log in again; the sample password is in the README |
| Laptop differs from build | Use the backup zip, the SQL dump, and copy the secrets file |

## 6. Do not claim

- Real patient use, real clinic partnerships, HIPAA/Data Privacy Act certification.
- That clinical records are saved.
- That clinic-wide reports work.
- That a UAT number was reached if the forms are not signed.
- That the system is penetration-tested. The controls are implemented and tested, not audited by a third party.
