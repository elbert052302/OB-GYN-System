-- GentleCare profile identity migration
-- Review and execute in phpMyAdmin only after backing up the existing MySQL database.
-- This migration adds the missing profile identity columns and copies data from users.
-- It preserves user IDs, existing profile data, and existing foreign-key relationships.

ALTER TABLE patient_profiles
    ADD COLUMN full_name VARCHAR(120) NULL AFTER user_id,
    ADD COLUMN phone VARCHAR(16) NULL AFTER full_name;

ALTER TABLE doctor_profiles
    ADD COLUMN full_name VARCHAR(120) NULL AFTER user_id,
    ADD COLUMN phone VARCHAR(16) NULL AFTER full_name;

ALTER TABLE clinic_profiles
    ADD COLUMN phone VARCHAR(16) NULL AFTER clinic_name;

-- Insert placeholder profile rows only for accounts that do not yet have a profile record.
-- This is intentionally conservative: existing profile rows are retained as-is.
INSERT INTO patient_profiles (user_id, full_name, phone, date_of_birth, emergency_contact, address)
SELECT u.id,
       u.full_name,
       u.phone,
       DATE('1970-01-01') AS date_of_birth,
       'Not provided' AS emergency_contact,
       NULL AS address
FROM users u
LEFT JOIN patient_profiles p ON p.user_id = u.id
WHERE u.role = 'patient' AND p.user_id IS NULL
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone);

INSERT INTO doctor_profiles (user_id, full_name, phone, license_number, specialization, time_zone)
SELECT u.id,
       u.full_name,
       u.phone,
       'UNSPECIFIED' AS license_number,
       'Unspecified' AS specialization,
       'UTC' AS time_zone
FROM users u
LEFT JOIN doctor_profiles d ON d.user_id = u.id
WHERE u.role = 'doctor' AND d.user_id IS NULL
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone);

INSERT INTO clinic_profiles (user_id, clinic_name, phone, address, time_zone, latitude, longitude)
SELECT u.id,
       COALESCE(c.clinic_name, CONCAT('Clinic ', u.id)) AS clinic_name,
       u.phone AS phone,
       COALESCE(c.address, 'Not provided') AS address,
       COALESCE(c.time_zone, 'UTC') AS time_zone,
       c.latitude,
       c.longitude
FROM users u
LEFT JOIN clinic_profiles c ON c.user_id = u.id
WHERE u.role = 'clinic' AND c.user_id IS NULL
ON DUPLICATE KEY UPDATE clinic_name = VALUES(clinic_name), phone = VALUES(phone), address = VALUES(address), time_zone = VALUES(time_zone), latitude = VALUES(latitude), longitude = VALUES(longitude);

-- Copy names and phone numbers into profile tables without overwriting valid existing values.
UPDATE patient_profiles p
JOIN users u ON u.id = p.user_id
SET p.full_name = COALESCE(p.full_name, u.full_name),
    p.phone = COALESCE(p.phone, u.phone)
WHERE u.role = 'patient';

UPDATE doctor_profiles d
JOIN users u ON u.id = d.user_id
SET d.full_name = COALESCE(d.full_name, u.full_name),
    d.phone = COALESCE(d.phone, u.phone)
WHERE u.role = 'doctor';

UPDATE clinic_profiles c
JOIN users u ON u.id = c.user_id
SET c.phone = COALESCE(c.phone, u.phone)
WHERE u.role = 'clinic';

-- Validate migration results before removing legacy columns from users.
-- Recommended manual verification queries:
-- SELECT role, COUNT(*) FROM users;
-- SELECT COUNT(*) FROM patient_profiles;
-- SELECT COUNT(*) FROM doctor_profiles;
-- SELECT COUNT(*) FROM clinic_profiles;
-- SELECT u.id, u.email, p.full_name, p.phone FROM users u JOIN patient_profiles p ON p.user_id = u.id WHERE u.role = 'patient' LIMIT 20;
-- SELECT u.id, u.email, d.full_name, d.phone FROM users u JOIN doctor_profiles d ON d.user_id = u.id WHERE u.role = 'doctor' LIMIT 20;
-- SELECT u.id, u.email, c.clinic_name, c.phone FROM users u JOIN clinic_profiles c ON c.user_id = u.id WHERE u.role = 'clinic' LIMIT 20;
