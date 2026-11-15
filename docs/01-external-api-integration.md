# External API integration — Brevo transactional email

Rubric phase: External API (6 points). Implemented, configured, and used by the live registration flow.

## 1. Purpose in the system

An account must not become usable until the owner proves they can read mail at that address. Registration creates the account in an unverified state, sends a six-digit code through Brevo, and only marks the account verified after the code is entered correctly. Login rejects unverified accounts.

This is a real dependency, not a decorative example: if Brevo rejects the request, the account is not created (or the existing unverified account cannot log in), and the API returns a specific error.

## 2. Provider and endpoint

| Item | Value |
| --- | --- |
| Provider | Brevo (formerly Sendinblue) |
| API | Transactional email, `POST https://api.brevo.com/v3/smtp/email` |
| Authentication | `api-key` request header |
| Payload | JSON: `sender`, `to`, `subject`, `textContent` |
| Timeouts | 3 s connect, 5 s total |
| Client | PHP cURL (`Backend/api/bootstrap.php`, `gc_send_verification_code()`) |

## 3. End-to-end flow

```text
Patient/Doctor/Clinic form
        |
        | POST /Backend/api/auth.php?action=register  (JSON + X-CSRF-Token)
        v
auth.php: validate role, name, email, password, optional phone, PSGC address
        |
        | INSERT users (email_verified_at = NULL,
        |               verification_code_hash = argon2id(code),
        |               verification_expires_at = now + 15 min)
        v
gc_send_verification_code(email, name, code)
        |
        | POST https://api.brevo.com/v3/smtp/email
        v
Brevo  --->  inbox: "Your GentleCare verification code is 123456"
        |
        | user submits code
        v
auth.php?action=verify: rate limit -> attempt counter -> password_verify(code, hash)
        |
        v
UPDATE users SET email_verified_at = UTC_TIMESTAMP(), verification_code_hash = NULL, ...
        |
        v
Login allowed. Session regenerated and CSRF token rotated.
```

## 4. Implementation references

| Concern | Location |
| --- | --- |
| Brevo request, error mapping | `Backend/api/bootstrap.php` lines 337–421 |
| Registration, code generation, expiry | `Backend/api/auth.php` lines 79–272 |
| Verification and attempt limit | `Backend/api/auth.php` lines 274–318 |
| Login gate for unverified accounts | `Backend/api/auth.php` lines 320–368 |
| Secret loading (outside web root) | `Backend/config.php` |
| Config template | `Backend/config.example.php` |

Code rules enforced by the flow:

- The plaintext code is never stored. Only an Argon2id hash is written to `users.verification_code_hash`.
- The code expires after 15 minutes.
- Five wrong attempts invalidate the code; the user must register again or request a new code.
- Verification requests are rate limited to 10 per 15 minutes per IP.
- Registration is rate limited to 30 per 60 minutes per IP.
- The API never returns the code unless the local demo switch is explicitly enabled.

## 5. Error handling

The integration fails closed. Every branch below returns a useful message and leaves the account unusable:

| Condition | Behaviour |
| --- | --- |
| Missing API key or invalid sender | `GcEmailDeliveryException`; HTTP 503 to the client |
| Connection failure or timeout | Logged; HTTP 503 |
| HTTP 401, unrecognized IP | Message names Brevo Authorized IPs and the exact settings page |
| HTTP 401, bad key | Message points to `C:\xampp\secrets\gentlecare.php` |
| HTTP 403 | Message names key permissions and account status |
| Any other non-2xx | Message includes the HTTP status and the sender-verification hint |

No account is created when delivery fails, which prevents a user from being stranded in an unverified state after a provider outage.

## 6. Secrets handling

The API key is not in the repository.

- `Backend/config.php` is gitignored.
- The real values live in `C:\xampp\secrets\gentlecare.php`, outside the web root.
- `Backend/config.example.php` and `Backend/README.md` contain only placeholders such as `YOUR_BREVO_API_KEY`.
- A tracked-file secret scan (see `evidence/secret-scan.txt`) reports zero findings.
- Git history was searched for the key prefix `xkeysib-`; the only hit is the placeholder in the README.

Local demo option: `development.expose_verification_code_on_localhost` can be set to `true` so the API returns the code in its JSON response when email delivery is unavailable. It defaults to `false` and must stay `false` for any shared or deployed copy.

## 7. Evidence to show the panel

1. `docs/evidence/screenshots/11-registration.png` — registration screen with the PSGC address fields.
2. A live demo: register a real address, open the Brevo email, enter the code, then log in.
3. Brevo dashboard: **Transactional** → **Logs** shows the accepted message and delivery status. Capture this immediately after the demo registration.
4. `docs/evidence/http-headers.txt` shows the API refuses unknown content types and returns JSON with hardening headers.
5. If the venue network blocks Brevo, show the stored provider logs plus `Backend/TEST_LOG.md` and explain the fail-closed behaviour.

## 8. Why this earns the external-API points

- A third-party service is part of a required user journey, not a side demo.
- Credentials are provisioned outside the repository and never echoed.
- The provider's failure modes are handled explicitly with actionable messages.
- Sensitive data (the verification code) is hashed at rest and expires.
- The integration is covered by the automated registration test in `Backend/tests/slots_appointments.php`.
