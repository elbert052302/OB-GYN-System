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
4. Configure PHP's mail transport and set `GENTLECARE_MAIL_FROM` for normal email verification. If email cannot be delivered during local development, set `development.expose_verification_code_on_localhost` to `true` in the local-only `config.php`. The verification code is returned only when mail delivery fails and the request comes from `127.0.0.1` or `::1`. Keep this option **false in production**; the committed `config.example.php` defaults it to false.

The API sets each PDO session's MySQL time zone to UTC and uses prepared statements. Local timestamps are converted to UTC before storage. Profile and location time zones are required and validated against PHP's IANA time-zone identifiers, including backward-compatible aliases.

## Authentication and sessions

`Backend/api/auth.php` uses JSON request bodies and a session-bound `X-CSRF-Token` for POST actions. Fetch the token with `GET /auth.php?action=csrf`; include the returned token and the same session cookie on subsequent POSTs. Successful verification and login rotate the session and return a fresh CSRF token. Registration is not authenticated until email verification succeeds.

Registration fields:

- All roles: `role`, `name`, `email`, `password`; optional `phone`.
- Patient `profile`: `dateOfBirth` (`YYYY-MM-DD`), `emergencyContact`.
- Doctor `profile`: `licenseNumber`, `specialization`, `time_zone`.
- Clinic `profile`: `clinicName`, `address`, `time_zone`; a valid clinic phone is required.

## Endpoint reference

The slot and appointment endpoints use the consistent response shape `{"ok":true,"error":null,"data":...}` or `{"ok":false,"error":{"message":"..."},"data":null}`. They require a valid session and enforce roles and location ownership on the server. Every POST also requires the session's CSRF token.

| Method and endpoint | Role | Parameters | Success response |
|---|---|---|---|
| `GET /health.php` | Public | None | HTTP 200, database connection status; HTTP 503 when unavailable |
| `GET /auth.php?action=csrf` | Public | None | HTTP 200, `csrfToken` |
| `GET /auth.php?action=me` | Any signed-in role | Session cookie | HTTP 200, current `user`; HTTP 401 when signed out |
| `POST /auth.php?action=register` | Public | Common registration fields and role-specific `profile` above; CSRF | HTTP 201, verification instructions and `emailSent`; a local fallback code only when the development conditions are met |
| `POST /auth.php?action=verify` | Public | `email`, six-digit `code`; CSRF | HTTP 200, signed-in `user` and rotated `csrfToken` |
| `POST /auth.php?action=login` | Public | `identifier` (email or phone), `password`; CSRF | HTTP 200, signed-in `user` and rotated `csrfToken`; unverified accounts are rejected |
| `POST /auth.php?action=logout` | Signed-in role | Empty JSON object; CSRF | HTTP 200, sign-out message; clears the session |
| `GET /slots.php?action=available` | Patient | None | HTTP 200, future unreserved `slots`, with UTC timestamps and each location's `timeZone` |
| `GET /slots.php?action=mine` | Doctor | None | HTTP 200, only the signed-in doctor's `slots`, with reservation state and UTC timestamps |
| `GET /slots.php?action=locations` | Doctor | None | HTTP 200, only the signed-in doctor's active practice `locations` |
| `POST /slots.php?action=create_location` | Doctor | `name`, `address`, required `time_zone`; CSRF | HTTP 201, new owned `location`; invalid location/time zone returns HTTP 422 |
| `POST /slots.php?action=create_slot` | Doctor | `location_id`, local `date` (`YYYY-MM-DD`), local `start_time`, local `end_time`; CSRF | HTTP 201, slot ID, location `timeZone`, and UTC start/end; past or invalid local time returns HTTP 422, overlap returns HTTP 409 |
| `POST /appointments.php?action=book` | Patient | `slot_id`, non-empty `purposes` array; `other_description` required when `Other` is selected; CSRF | HTTP 201, a `confirmed` appointment with UTC timestamps and location `timeZone`; unavailable slot or existing active patient reservation returns HTTP 409 |

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
   $location = Invoke-RestMethod "$base/slots.php?action=create_location" -Method Post -WebSession $doctorSession -Headers @{ 'X-CSRF-Token' = $doctorToken } -ContentType 'application/json' -Body (@{ name = 'Demo location'; address = 'Demo address'; time_zone = 'America/New_York' } | ConvertTo-Json)
   $locationId = $location.data.location.id
   ```

4. Publish a future slot using local wall-clock time. Pick a time not already occupied in this doctor's schedule:

   ```powershell
   $localDate = (Get-Date).AddDays(14).ToString('yyyy-MM-dd')
   $slot = Invoke-RestMethod "$base/slots.php?action=create_slot" -Method Post -WebSession $doctorSession -Headers @{ 'X-CSRF-Token' = $doctorToken } -ContentType 'application/json' -Body (@{ location_id = $locationId; date = $localDate; start_time = '16:00'; end_time = '16:30' } | ConvertTo-Json)
   $slot.data.slot
   ```

   The result displays the UTC timestamps and `America/New_York` time zone.
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

Run the endpoint and cleanup checks from the project root:

```powershell
& C:\xampp\php\php.exe Backend\tests\timezone_registration.php
& C:\xampp\php\php.exe Backend\tests\auth_flow.php
& C:\xampp\php\php.exe Backend\tests\slots_appointments.php
```

The test suite logs real results in `TEST_LOG.md`; test accounts and schedule/booking fixtures are removed after each run.
