# GentleCare user manual

Version: 2026-11-15. Applies to the local XAMPP demo and a laptop-to-laptop copy.

## 1. Starting the system

1. Open **XAMPP Control Panel**.
2. Start **Apache**.
3. Start **MySQL**. This build listens on port **3307**, not 3306.
4. Confirm the API: open `http://127.0.0.1/OB-GYN/Backend/api/health.php`. You should see `{"status":"ok","database":"connected"}`.
5. Open the app: `http://127.0.0.1/OB-GYN/`.

If the database connection fails, check `Backend/config.php`, the port, and that `gentlecare_v2` exists.

## 2. Accounts

Demo accounts (all use `SamplePassword!2026`):

| Role | Email |
| --- | --- |
| Patient | `sample.patient@gentlecare.local` |
| Doctor (Manila) | `sample.doctor.manila@gentlecare.local` |
| Doctor (Pagadian) | `sample.doctor.pagadian@gentlecare.local` |
| Clinic | `sample.clinic@gentlecare.local` |

Real registration sends a six-digit code through Brevo. Unverified accounts cannot log in.

## 3. Patient guide

### 3.1 Log in

1. Enter the email and password.
2. Press **Log In**. The patient dashboard opens.

### 3.2 Find a provider

1. Press **Book Now** or use the search bar.
2. Type a name, specialty, or place, or use **Map**.
3. The list shows doctors and clinics. The map shows doctors and clinics that saved coordinates; providers without a pin are still listed.
4. Press **View details** for the address, specialty, and contact details.

### 3.3 Book an appointment

1. On the provider details panel, press **Book Now**.
2. Tick one or more **reasons for the visit**. If you tick **Other**, type a description.
3. Press **Continue**.
4. Pick a highlighted date, then choose a time.
5. Review the doctor, location, date, and time.
6. Press **Confirm**. The appointment appears under **My appointments** with status `Confirmed`.

Rules:

- Only one active appointment per patient.
- A slot can be held by one patient only.
- Slots in the past cannot be booked.
- Times are shown in the practice location's time zone.

### 3.4 Cancel

1. Open **My appointments**.
2. Press **Cancel** on the confirmed appointment and confirm.
3. The slot becomes available to other patients, and the doctor receives a notification.

### 3.5 Notifications

Open the bell icon to see appointment changes. Cancelled, completed, and no-show events create a notification.

### 3.6 Map privacy

The map loads tiles from OpenStreetMap. Using **Use my location** is optional; your coordinates stay in the browser and are not sent to the server. Providers' saved coordinates are used only to place pins.

## 4. Doctor guide

### 4.1 Log in

Use the doctor email. The doctor dashboard shows today's schedule and counts.

### 4.2 Add or update a practice location

1. Open **Practice locations**.
2. Choose country, province/NCR, city, barangay, and optional street.
3. Optionally press **Use my location** to save a map pin, or type coordinates if the form offers them.
4. Press **Save**.

The address is validated against the Philippine PSGC dataset, so a mismatched province/city/barangay is rejected.

### 4.3 Publish a slot

1. Open **Appointments**.
2. Choose the practice location, date, start time, and duration.
3. Press **Publish**.
4. The slot appears in your list and becomes bookable by patients.

A doctor cannot publish two slots with the same start time.

### 4.4 Manage appointments

- **Cancel** releases the slot and notifies the patient.
- **Complete** closes a visit after it happens, releases the reservation, and notifies the patient.
- **No-show** closes the visit as missed and releases the reservation.

A confirmed appointment whose time has passed is completed automatically, so it can never block a patient from booking again.

### 4.5 Clinical records (preview)

The **Records** tab is a preview only. Editing is disabled or clearly labelled, and nothing is saved to the server. Do not enter real patient information there.

## 5. Clinic guide

A clinic account can sign in and see the clinic dashboard. Clinic-wide appointment lists, operational records, and reports are **planned features**; the screen says so explicitly and the filters are disabled rather than pretending to work.

## 6. Registration

1. Press **Create account**.
2. Choose Patient, Doctor, or Clinic.
3. Fill in name, email, password, optional phone, and the role-specific fields.
4. Submit. Brevo sends a six-digit code that expires in 15 minutes.
5. Enter the code. After verification you can log in.

If the email does not arrive, check spam, then register again or use a different address. After five wrong codes the code is invalidated.

## 7. Common problems

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `database":"disconnected"` | MariaDB not running or wrong port | Start MySQL; check port 3307 in `Backend/config.php` |
| Login says the account is not verified | Email code never entered | Use the code from the email; register again if it expired |
| No available times | No future slots for that doctor | Doctor publishes slots, or run `php Backend/tests/prepare_demo.php` |
| Map is blank | No internet, or tiles blocked | Provider list still works; check the network |
| "Session expired" on save | Session timed out | Refresh and log in again |
| 429 error | Too many attempts | Wait 15 minutes |
| Demo on another laptop cannot send email | Secrets file missing | Copy `C:\xampp\secrets\gentlecare.php`, or temporarily enable the local code switch |

## 8. Resetting the demo

Run these from the project root:

```powershell
& C:\xampp\php\php.exe Backend\tests\prepare_demo.php
& C:\xampp\php\php.exe Backend\tests\sweep_appointments.php
```

- `prepare_demo.php` adds upcoming weekday slots for verified doctors with a mapped location. It is safe to rerun.
- `sweep_appointments.php` closes appointments whose slot has ended and releases reservations.

To rebuild the whole database, back up first, then import `Database/Ob-Gyn.sql` into an empty database and run `seed_sample_data.php`.

## 9. Where the evidence lives

- Feature and security evidence: `docs/`
- Automated test output: `docs/evidence/`
- API reference and authorization matrix: `Backend/README.md`
- Historical test notes: `Backend/TEST_LOG.md`
