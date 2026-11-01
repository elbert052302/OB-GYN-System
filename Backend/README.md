# GentleCare backend

PHP JSON endpoints for GentleCare, using MariaDB through PDO. The supported account roles are `patient`, `doctor`, and `clinic`. Database timestamps are UTC; local appointment input is interpreted using the selected practice location's IANA time zone.

## Local XAMPP setup

Requirements: XAMPP Apache, PHP with `pdo_mysql` and `mbstring`, and MariaDB listening on port `3307`.

1. Copy `Backend/config.example.php` to `Backend/config.php`. Set the database credentials for your local XAMPP instance. `Backend/config.php` is gitignored; never commit it.
2. Create a **new, empty** database and import the schema. In PowerShell, from the project root:

   ```powershell
   & C:\xampp\mysql\bin\mysql.exe --protocol=TCP --host=127.0.0.1 --port=3307 --user=root -e "CREATE DATABASE IF NOT EXISTS gentlecare_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   & $env:ComSpec /d /s /c '"C:\xampp\mysql\bin\mysql.exe" --protocol=TCP --host=127.0.0.1 --port=3307 --user=root gentlecare_v2 < "C:\xampp\htdocs\OB-GYN\Database\Ob-Gyn.sql"'
   ```

   Back up any existing target before replacing it. The SQL file defines tables; it is not a migration. Never import it into `gentlecare_db` or another populated database.
3. Open `http://127.0.0.1/OB-GYN/Backend/api/health.php`. A working connection returns HTTP 200 with `{"status":"ok","database":"connected"}`.
4. Open `http://127.0.0.1/OB-GYN/` to launch the GentleCare sign-in page.
5. Configure PHP's mail transport and set `GENTLECARE_MAIL_FROM` for normal email verification. If email cannot be delivered during local development, set `development.expose_verification_code_on_localhost` to `true` in the local-only `config.php`. The verification code is returned only when mail delivery fails and the request comes from `127.0.0.1` or `::1`. Keep this option **false in production**; the committed `config.example.php` defaults it to false.
6. Philippine location choices are served from the bundled PSA PSGC quarterly dataset in `Backend/data/psgc/`; no external geocoder is needed to validate addresses. The optional latitude/longitude database columns are retained for compatibility with existing databases.
7. For an existing database that predates map coordinates, apply `Backend/migrations/20261018_provider_map_locations.sql` once to add optional latitude/longitude columns for clinic and doctor practice locations. Doctors can save a practice pin from their device location or enter coordinates; clinics can add a pin during registration or update it from the clinic dashboard. Existing records without coordinates remain available in provider search but cannot appear as map pins until a pin is saved.
8. For an existing database, apply `Backend/migrations/20261020_patient_addresses.sql` once to add the nullable patient address column. New patient registrations require a valid Philippine address; existing patients can continue to log in without one.
9. For an existing database, apply `Backend/migrations/20261020_auth_rate_limits.sql` once to create the login and registration rate-limit table.

Patient booking starts with a provider list or map. Selecting a doctor or clinic opens its available profile details with a **Book Now** action; doctors continue through purpose, available date, time, review/edit, and confirmation. The provider-type filter beside search applies to both the list and map. Clinics are distinct directory/map listings and do not have online appointment schedules in the current system; their **Book Now** action explains this limitation and offers a way to browse doctors. Bookable slots belong to doctors and their practice locations. The separate map view requests the patient's location only after they press **Use my location**; the app does not send it to the GentleCare API. OpenStreetMap receives map-tile requests for the displayed area, so patients who prefer not to share location with that service can search by city or area instead. The map and its tiles use Leaflet and OpenStreetMap and require an internet connection. Browser location is available on localhost.

Provider addresses use Country → Province/NCR/independent region → City/Municipality → Barangay → optional Street. Province, city, municipality, sub-municipality, and barangay choices are validated against the PSA PSGC 2Q 2026 Publication Datafile. Independent cities and BARMM special-area municipalities without a province are grouped under their actual PSGC region instead of being assigned to a false province. Street names are not part of PSGC and are entered manually. NCR uses a dedicated `NCR` selector value, and its sub-municipality addresses include their parent city.

The API sets each PDO session's MySQL time zone to UTC and uses prepared statements. New Philippine practice locations use `Asia/Manila`; local appointment timestamps are converted to UTC before storage. Existing locations retain their stored time zone.

## Authentication and sessions

`Backend/api/auth.php` uses JSON request bodies and a session-bound `X-CSRF-Token` for POST actions. Fetch the token with `GET /auth.php?action=csrf`; include the returned token and the same session cookie on subsequent POSTs. Successful verification and login rotate the session and return a fresh CSRF token. Registration is not authenticated until email verification succeeds.

Registration fields:

- All roles: `role`, `name`, `email`, `password`; optional `phone`.
- Patient `profile`: `dateOfBirth` (`YYYY-MM-DD`), `emergencyContact`, `country` (`PH`), `provinceCode` (PSGC province code, `NCR`, or `REGION:xx` for a province-less area), `cityCode`, `barangayCode`, and optional `street`. The server validates the PSGC hierarchy and constructs the saved address.
- Doctor `profile`: `licenseNumber`, `specialization`, and either `country` (two-letter ISO country code; the server selects that country's default time zone) or legacy `time_zone`.
- Clinic `profile`: `clinicName`, `country` (`PH`), `provinceCode` (PSGC province code, `NCR`, or `REGION:xx` for a province-less area), `cityCode`, `barangayCode`, and optional `street`; a valid clinic phone is required. The server validates the PSGC parent-child relationships and constructs the saved address.
- Clinic map `profile` options: optional paired `latitude` and `longitude` decimal coordinates. Omit both to skip adding a pin during registration.

## Endpoint reference

The slot and appointment endpoints use the consistent response shape `{"ok":true,"error":null,"data":...}` or `{"ok":false,"error":{"message":"..."},"data":null}`. They require a valid session and enforce roles and location ownership on the server. Every POST also requires the session's CSRF token.

| Method and endpoint | Role | Parameters | Success response |
|---|---|---|---|
| `GET /health.php` | Public | None | HTTP 200, database connection status; HTTP 503 when unavailable |
| `GET /countries.php` | Public | None | HTTP 200, supported ISO country codes and their default IANA time zones |
| `GET /geography.php?action=provinces` | Public | None | HTTP 200, PSGC provinces, NCR, and region groups for province-less cities/municipalities |
| `GET /geography.php?action=cities&province_code=...` | Public | PSGC province code, `NCR`, or a `REGION:xx` group | HTTP 200, cities, municipalities, and sub-municipalities in that selection |
| `GET /geography.php?action=barangays&province_code=...&city_code=...` | Public | Province/region selector code and PSGC city/municipality code | HTTP 200, barangays belonging to that city/municipality |
| `GET /providers.php` | Patient | Session cookie | HTTP 200, verified doctors' and clinics' active practice locations with saved addresses |
| `GET /providers.php?action=mine` | Clinic | Session cookie | HTTP 200, the signed-in clinic's saved map coordinates |
| `POST /providers.php?action=update_location` | Clinic | Optional paired `latitude` and `longitude`; CSRF | HTTP 200, updates or clears the signed-in clinic's map pin; invalid or unpaired coordinates return HTTP 422 |
| `GET /auth.php?action=csrf` | Public | None | HTTP 200, `csrfToken` |
| `GET /auth.php?action=me` | Any signed-in role | Session cookie | HTTP 200, current `user`; HTTP 401 when signed out |
| `POST /auth.php?action=register` | Public | Common registration fields and role-specific `profile` above; CSRF | HTTP 201, verification instructions and `emailSent`; addresses are validated against PSGC and constructed server-side; HTTP 429 after 30 attempts per hour per IP |
| `POST /auth.php?action=verify` | Public | `email`, six-digit `code`; CSRF | HTTP 200, signed-in `user` and rotated `csrfToken` |
| `POST /auth.php?action=login` | Public | `identifier` (email or phone), `password`; CSRF | HTTP 200, signed-in `user` and rotated `csrfToken`; unverified accounts are rejected; HTTP 429 after 5 wrong passwords per 15 minutes per identifier and IP |
| `POST /auth.php?action=logout` | Signed-in role | Empty JSON object; CSRF | HTTP 200, sign-out message; clears the session |
| `GET /slots.php?action=available` | Patient | None | HTTP 200, future unreserved `slots`, with UTC timestamps and each location's `timeZone` |
| `GET /slots.php?action=mine` | Doctor | None | HTTP 200, only the signed-in doctor's `slots`, with reservation state and UTC timestamps |
| `GET /slots.php?action=locations` | Doctor | None | HTTP 200, only the signed-in doctor's active practice `locations` |
| `POST /slots.php?action=create_location` | Doctor | `name`, `country` (`PH`), `province_code`, `city_code`, `barangay_code`, optional `street`, and optional paired `latitude`/`longitude`; CSRF | HTTP 201, new owned location with a server-constructed address and `Asia/Manila` time zone; invalid PSGC hierarchy or coordinates return HTTP 422 |
| `POST /slots.php?action=create_slot` | Doctor | `location_id`, local `date` (`YYYY-MM-DD`), local `start_time`, local `end_time`; CSRF | HTTP 201, slot ID, location `timeZone`, and UTC start/end; past or invalid local time returns HTTP 422, overlap returns HTTP 409 |
| `POST /appointments.php?action=book` | Patient | `slot_id`, non-empty `purposes` array; `other_description` required when `Other` is selected; CSRF | HTTP 201, a `confirmed` appointment with UTC timestamps and location `timeZone`; unavailable slot or existing active patient reservation returns HTTP 409 |
| `GET /appointments_manage.php?action=mine` | Patient or Doctor | Session cookie | HTTP 200, only the signed-in user's appointments, with UTC timestamps, location time zone, names, and purposes |
| `POST /appointments_manage.php?action=cancel` | Owning Patient or Doctor | `appointment_id`, optional `reason`; CSRF | HTTP 200, cancels a confirmed appointment transactionally, releases its active reservation, records history, and notifies the other participant |

### Role-versus-endpoint authorization matrix

`Yes` means the role can call the endpoint; `Owner only` means the signed-in patient or doctor must own the appointment. All POST requests require a session-bound CSRF token.

| Endpoint group | Public | Patient | Doctor | Clinic |
|---|---:|---:|---:|---:|
| Health, countries, geography, and CSRF token | Yes | Yes | Yes | Yes |
| Register, verify, and login | Yes | Yes | Yes | Yes |
| Session lookup (`auth.php?action=me`) | No | Yes | Yes | Yes |
| Logout | No | Yes | Yes | Yes |
| Provider directory | No | Yes | No | No |
| Own clinic map pin (`providers.php?action=mine/update_location`) | No | No | No | Yes |
| Available slots | No | Yes | No | No |
| Own slots, locations, and slot publishing | No | No | Yes | No |
| Book an appointment | No | Yes | No | No |
| List own appointments | No | Yes | Yes | No |
| Cancel an appointment | No | Owner only | Owner only | No |

Clinics do not yet have appointment, slot, or clinical-record management endpoints.

Authentication responses use their existing JSON shapes (`error`, `user`, `csrfToken`, and registration fields). For slot/booking endpoints, malformed requests return HTTP 400/413/415, unauthenticated requests 401, wrong roles or missing CSRF 403, invalid fields 422, missing owned resources 404, conflicts 409, and service failures 503.

Slot `date`, `start_time`, and `end_time` describe wall-clock values at that slot's selected location. Nonexistent or ambiguous daylight-saving local times are rejected rather than guessed. Slots are stored in UTC. Overlap checks apply across the doctor's locations. A successful booking locks the slot and patient profile in a transaction, then creates the confirmed appointment, its purposes, the unique active reservation, and booking history as one atomic operation.

## Five-minute local demo

1. Start Apache and MariaDB in XAMPP; check `GET /OB-GYN/Backend/api/health.php`.
2. For a local mail-less demo only, enable the localhost verification fallback in the ignored `Backend/config.php`. Register a Doctor and a Patient using the existing frontend; note each development verification code and verify both accounts. Do not enable this fallback on a public host.
3. In PowerShell, set the API base and sign in the doctor. Reuse the same web session and returned CSRF token for POST requests:

   ```powershell
   $base = 'http://127.0.0.1/OB-GYN/Backend/api'
   $doctorEmail = Read-Host 'Doctor email'
   $doctorPassword = Read-Host 'Doctor password'
   $doctorSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
   $doctorCsrf = Invoke-RestMethod "$base/auth.php?action=csrf" -WebSession $doctorSession
   $doctorLogin = Invoke-RestMethod "$base/auth.php?action=login" -Method Post -WebSession $doctorSession -Headers @{ 'X-CSRF-Token' = $doctorCsrf.csrfToken } -ContentType 'application/json' -Body (@{ identifier = $doctorEmail; password = $doctorPassword } | ConvertTo-Json)
   $doctorToken = $doctorLogin.csrfToken
   $location = Invoke-RestMethod "$base/slots.php?action=create_location" -Method Post -WebSession $doctorSession -Headers @{ 'X-CSRF-Token' = $doctorToken } -ContentType 'application/json' -Body (@{ name = 'Demo location'; country = 'PH'; province_code = '0702200000'; city_code = '0702201000'; barangay_code = '0702201001'; street = 'Osmeña Street' } | ConvertTo-Json)
   $locationId = $location.data.location.id
   ```

4. Publish a future slot using local wall-clock time. Pick a time not already occupied in this doctor's schedule:

   ```powershell
   $localDate = (Get-Date).AddDays(14).ToString('yyyy-MM-dd')
   $slot = Invoke-RestMethod "$base/slots.php?action=create_slot" -Method Post -WebSession $doctorSession -Headers @{ 'X-CSRF-Token' = $doctorToken } -ContentType 'application/json' -Body (@{ location_id = $locationId; date = $localDate; start_time = '16:00'; end_time = '16:30' } | ConvertTo-Json)
   $slot.data.slot
   ```

   The result displays UTC timestamps and the selected practice location's time zone (`Asia/Manila` for this example).
5. Sign in as the Patient in a **separate** `WebRequestSession`, fetch `GET /slots.php?action=available`, and book the returned slot:

   ```powershell
   $patientEmail = Read-Host 'Patient email'
   $patientPassword = Read-Host 'Patient password'
   $patientSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
   $patientCsrf = Invoke-RestMethod "$base/auth.php?action=csrf" -WebSession $patientSession
   $patientLogin = Invoke-RestMethod "$base/auth.php?action=login" -Method Post -WebSession $patientSession -Headers @{ 'X-CSRF-Token' = $patientCsrf.csrfToken } -ContentType 'application/json' -Body (@{ identifier = $patientEmail; password = $patientPassword } | ConvertTo-Json)
   $patientToken = $patientLogin.csrfToken
   $available = Invoke-RestMethod "$base/slots.php?action=available" -WebSession $patientSession
   $available.data.slots | Format-Table id, startsAtUtc, endsAtUtc
   $slotId = Read-Host 'Slot ID to book'
   Invoke-RestMethod "$base/appointments.php?action=book" -Method Post -WebSession $patientSession -Headers @{ 'X-CSRF-Token' = $patientToken } -ContentType 'application/json' -Body (@{ slot_id = [int]$slotId; purposes = @('Demo purpose', 'Other'); other_description = 'Demo description' } | ConvertTo-Json)
   ```

   Confirm the response says `confirmed`. A second booking attempt for the same slot or a second active appointment for that patient returns HTTP 409.

### Sample data and automated checks

The CLI-only sample-data script creates one verified patient, two verified doctors with Manila and Pagadian practice locations, one clinic, and future slots. Its local sample password is defined in the script; do not use these accounts or credentials on a public deployment. Running it repeatedly does not create duplicate sample accounts, locations, or slots:

```powershell
& C:\xampp\php\php.exe Backend\tests\seed_sample_data.php
```

With Apache and MariaDB running, rerun all standalone PHP tests from the project root. `booking_worker.php` is a helper launched by `slots_appointments.php`, not a standalone test; `seed_sample_data.php` is intentionally run separately because it leaves sample data in the database.

```powershell
$testFiles = Get-ChildItem Backend\tests\*.php | Where-Object { $_.Name -notin @('booking_worker.php', 'seed_sample_data.php') } | Sort-Object Name
foreach ($testFile in $testFiles) {
    Write-Host "=== $($testFile.Name) ==="
    & C:\xampp\php\php.exe $testFile.FullName
    if ($LASTEXITCODE -ne 0) { throw "Test failed: $($testFile.Name)" }
}
```

Paste the exact commands, environment details (PHP version, MariaDB port/database, and whether Apache was running), and every `PASS` line into `Backend/TEST_LOG.md`. Include any failure output if a test fails. Do not paste passwords, verification codes, session cookies, or database credentials. Successful API tests remove their temporary fixtures; the sample-data script does not.
