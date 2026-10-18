# GentleCare backend

The API source schema is `../Database/Ob-Gyn.sql`; the application loads database settings from `config.php` through `api/bootstrap.php`. On 2026-10-15 the configured `gentlecare_v2` database was inspected read-only: its 18 InnoDB tables and 118 columns match the source schema, but its reservation foreign key/indexes do not yet include the composite appointment/patient/slot integrity change documented below. No live migration was run.

## Local configuration

`config.php` contains local XAMPP database settings and is denied direct HTTP access by this directory's `.htaccess`. Keep credentials out of frontend files and source control. For deployment, provide configuration through a protected mechanism outside the web root; confirm Apache honors the access rule as part of deployment verification.

Configure `GENTLECARE_MAIL_FROM` for the Apache/PHP process with a sender address accepted by the configured PHP mail transport. Outside the localhost development fallback below, registration is rolled back unless PHP accepts the verification message for delivery. XAMPP's PHP mail transport must be configured separately before normal registration can succeed.

For local XAMPP testing only, `development.expose_verification_code_on_localhost` in `config.php` allows the registration response to include the verification code when mail delivery fails and the request originates from loopback. The code is not written to logs. This option must be `false` outside local development.

All API database connections use prepared statements and set the MySQL session timezone to UTC. PHP connection options and timezone were tested against an isolated MariaDB instance; the configured application database was unavailable for verification.

## Schema setup and migration safety

`Database/Ob-Gyn.sql` is a schema definition, not a data backup or a migration to apply blindly. It defines Patient, Doctor, and Clinic accounts; appointment statuses do not include Pending. The reservation table enforces one active reservation per patient and slot and uses a composite foreign key to ensure each reservation matches its appointment, patient, and slot.

The database does not enforce that reservation existence always agrees with appointment status. Future appointment operations must update appointment status and reservation rows in the same transaction, and failed operations must roll back as a unit. This behavior has not yet been implemented in an appointment API.

Never import the schema over a populated or existing database. Before any live migration:

1. Inspect the actual database schema using read-only metadata queries.
2. Check aggregate counts for reservation/appointment mismatches and status/reservation inconsistencies without exposing patient data.
3. Review any nonzero results and prepare a specific migration and verified restorable backup.
4. Obtain explicit approval before making any live database change.

For write-based schema and constraint tests, use a separate disposable database. The isolated schema/transaction tests and live aggregate-only preflight results are recorded in `../PHASE3-PHASE4-ACCEPTANCE.md`; they do not replace post-migration verification. Private permit files must be stored outside the Apache document root when the upload workflow is implemented.
