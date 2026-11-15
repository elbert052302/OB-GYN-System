# Submission checklist

Use this before every deadline and again 24 hours before the defense.

## 1. Code and repository

- [ ] `git status` is clean except files intentionally excluded.
- [ ] All changes are committed and pushed to `origin`.
- [ ] A tag marks the submitted build (`git tag -a final-YYYY-MM-DD -m "..."` and `git push --tags`).
- [ ] `.gitignore` excludes `Backend/config.php`, logs, editor files, and `Backend/tests/tmp/`.
- [ ] No real secret is tracked: run the secret scan, confirm 0 findings.
- [ ] `Backend/tests/tmp/` does not exist or is empty.
- [ ] No `.zip`, database dump, or personal UAT data is committed.

## 2. Database

- [ ] `Database/Ob-Gyn.sql` imports into an empty database with zero errors.
- [ ] A fresh install + `seed_sample_data.php` + `prepare_demo.php` gives bookable future slots.
- [ ] `mysqldump` backup of the demo database exists outside the repo.
- [ ] The dump restores successfully into a scratch database at least once.

## 3. Configuration and secrets

- [ ] `C:\xampp\secrets\gentlecare.php` exists on the demo laptop with the real Brevo key and sender.
- [ ] `Backend/config.php` points at the correct database and port.
- [ ] `development.expose_verification_code_on_localhost` is `false`.
- [ ] If the local code switch was used for a demo, it is reverted.

## 4. Quality gates

- [ ] `php -l` clean on every PHP file.
- [ ] `Backend/tests/slots_appointments.php` passes with `GENTLECARE_API_BASE` set.
- [ ] `Backend/tests/appointment_lifecycle.php` passes.
- [ ] `http://127.0.0.1/OB-GYN/Backend/api/health.php` returns 200.
- [ ] Browser: patient login, map, booking, doctor login, clinic login, zero console errors.
- [ ] Load test rerun on the demo laptop and recorded in `docs/05-test-report.md`.

## 5. Documentation

- [ ] `docs/README.md` index matches the actual files.
- [ ] `docs/01-external-api-integration.md` has the Brevo logs screenshot attached.
- [ ] `docs/02-security-implementation.md` has the eight evidence items available.
- [ ] `docs/03-database-design.md` data dictionary matches the submitted schema.
- [ ] `docs/04-test-plan.md` and `05-test-report.md` contain the final numbers.
- [ ] UAT results contain 15 real rows and signed consent forms.
- [ ] Defect log has zero open critical/high items.
- [ ] User manual matches the current UI labels.
- [ ] SRS claims match what the demo shows.

## 6. Presentation

- [ ] Slides exported as PPTX **and** PDF (backup).
- [ ] Demo video recorded (5–10 minutes) and stored locally plus one cloud copy.
- [ ] Demo script rehearsed end to end at least twice, once on the demo laptop.
- [ ] Screenshots of the key screens are saved in case the live demo fails.
- [ ] Sample accounts verified on the demo laptop.
- [ ] Future slots prepared the morning of the defense (`prepare_demo.php`).
- [ ] Backup zip of the project and the database dump are on a USB drive.
- [ ] Laptop charger, HDMI adapter, and a clicker if available.

## 7. Panel and submission

- [ ] `docs/09-defense-notes.md` reviewed; numbers match the report.
- [ ] Known limitations memorized: no admin portal, records preview, clinic reports planned, local HTTP only, CSP inline scripts.
- [ ] Action log from previous panel feedback updated with what changed and where.
- [ ] Submission portal/link tested; file names follow the required format.
- [ ] Final tag pushed; repository link opens in a private window.

## 8. Thirty minutes before the defense

1. Start Apache and MySQL.
2. Open `health.php` and confirm `"database":"connected"`.
3. Run `prepare_demo.php`.
4. Log in as patient, doctor, and clinic once to warm sessions.
5. Open the dashboard, the test report, and the security checklist in separate tabs.
6. Silence notifications; set display scaling so the panel can read the screen.

## 9. After the defense

- [ ] Record panel questions and requested changes in the action log.
- [ ] Fix or explicitly defer each item with a reason.
- [ ] Commit, tag `post-defense-YYYY-MM-DD`, and push.
- [ ] Archive the final zip and database dump.
