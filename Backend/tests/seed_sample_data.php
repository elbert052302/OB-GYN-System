<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/api/bootstrap.php';

function gc_seed_user(PDO $db, string $role, string $name, string $email): int
{
    $passwordHash = password_hash('SamplePassword!2026', PASSWORD_ARGON2ID);
    if ($passwordHash === false) {
        throw new RuntimeException('Could not hash the sample password.');
    }

    $lookup = $db->prepare('SELECT id, role FROM users WHERE email = :email LIMIT 1');
    $lookup->execute(['email' => $email]);
    $existing = $lookup->fetch();
    if ($existing && $existing['role'] !== $role) {
        throw new RuntimeException('A sample email is already used by a different account role.');
    }

    if ($existing) {
        $userId = (int) $existing['id'];
        $update = $db->prepare(
            'UPDATE users SET password_hash = :password_hash, '
            . 'email_verified_at = UTC_TIMESTAMP(), verification_code_hash = NULL, '
            . 'verification_expires_at = NULL, verification_attempts = 0 WHERE id = :user_id'
        );
        $update->execute([
            'password_hash' => $passwordHash,
            'user_id' => $userId,
        ]);
        return $userId;
    }

    $insert = $db->prepare(
        'INSERT INTO users (role, email, password_hash, email_verified_at) '
        . 'VALUES (:role, :email, :password_hash, UTC_TIMESTAMP())'
    );
    $insert->execute([
        'role' => $role,
        'email' => $email,
        'password_hash' => $passwordHash,
    ]);
    return (int) $db->lastInsertId();
}

function gc_seed_location(
    PDO $db,
    int $doctorId,
    string $name,
    string $address,
    string $timeZone,
    string $latitude,
    string $longitude
): int {
    $lookup = $db->prepare(
        'SELECT id FROM practice_locations WHERE owner_user_id = :doctor_id AND name = :name LIMIT 1'
    );
    $lookup->execute(['doctor_id' => $doctorId, 'name' => $name]);
    $existingId = $lookup->fetchColumn();
    if ($existingId !== false) {
        $locationId = (int) $existingId;
        $update = $db->prepare(
            'UPDATE practice_locations SET address = :address, time_zone = :time_zone, '
            . 'latitude = :latitude, longitude = :longitude, is_active = 1 WHERE id = :location_id'
        );
        $update->execute([
            'address' => $address,
            'time_zone' => $timeZone,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_id' => $locationId,
        ]);
        return $locationId;
    }

    $insert = $db->prepare(
        'INSERT INTO practice_locations (owner_user_id, name, address, time_zone, latitude, longitude) '
        . 'VALUES (:doctor_id, :name, :address, :time_zone, :latitude, :longitude)'
    );
    $insert->execute([
        'doctor_id' => $doctorId,
        'name' => $name,
        'address' => $address,
        'time_zone' => $timeZone,
        'latitude' => $latitude,
        'longitude' => $longitude,
    ]);
    return (int) $db->lastInsertId();
}

function gc_seed_slot(PDO $db, int $doctorId, int $locationId, DateTimeImmutable $start, DateTimeImmutable $end): void
{
    $statement = $db->prepare(
        'INSERT INTO appointment_slots (doctor_user_id, location_id, starts_at_utc, ends_at_utc, created_by) '
        . 'VALUES (:doctor_id, :location_id, :starts_at_utc, :ends_at_utc, :created_by) '
        . 'ON DUPLICATE KEY UPDATE id = id'
    );
    $statement->execute([
        'doctor_id' => $doctorId,
        'location_id' => $locationId,
        'starts_at_utc' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'ends_at_utc' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'created_by' => $doctorId,
    ]);
}

$db = gc_db();
$db->beginTransaction();
try {
    $patientId = gc_seed_user($db, 'patient', 'Sample Patient', 'sample.patient@gentlecare.local');
    $patientProfile = $db->prepare(
        'INSERT INTO patient_profiles (user_id, full_name, phone, date_of_birth, emergency_contact, address) '
        . 'VALUES (:user_id, :full_name, :phone, :date_of_birth, :emergency_contact, :address) '
        . 'ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone), '
        . 'date_of_birth = VALUES(date_of_birth), emergency_contact = VALUES(emergency_contact), address = VALUES(address)'
    );
    $patientProfile->execute([
        'user_id' => $patientId,
        'full_name' => 'Sample Patient',
        'phone' => '+639000000001',
        'date_of_birth' => '1990-01-15',
        'emergency_contact' => 'Sample Contact / +639000000001',
        'address' => 'Sample Street, Barangay 659, Ermita, Manila, Philippines',
    ]);

    $manilaDoctorId = gc_seed_user($db, 'doctor', 'Dr. Alex Santos', 'sample.doctor.manila@gentlecare.local');
    $pagadianDoctorId = gc_seed_user($db, 'doctor', 'Dr. Jamie Rivera', 'sample.doctor.pagadian@gentlecare.local');
    $doctorProfile = $db->prepare(
        'INSERT INTO doctor_profiles (user_id, full_name, phone, license_number, specialization, time_zone) '
        . 'VALUES (:user_id, :full_name, :phone, :license_number, :specialization, :time_zone) '
        . 'ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone), '
        . 'license_number = VALUES(license_number), specialization = VALUES(specialization), time_zone = VALUES(time_zone)'
    );
    $doctorProfile->execute([
        'user_id' => $manilaDoctorId,
        'full_name' => 'Dr. Alex Santos',
        'phone' => '+639000000002',
        'license_number' => 'SAMPLE-MNL-2026',
        'specialization' => 'Obstetrics and Gynecology',
        'time_zone' => 'Asia/Manila',
    ]);
    $doctorProfile->execute([
        'user_id' => $pagadianDoctorId,
        'full_name' => 'Dr. Jamie Rivera',
        'phone' => '+639000000003',
        'license_number' => 'SAMPLE-PAG-2026',
        'specialization' => 'Obstetrics and Gynecology',
        'time_zone' => 'Asia/Manila',
    ]);

    $clinicId = gc_seed_user($db, 'clinic', 'GentleCare Sample Clinic', 'sample.clinic@gentlecare.local');
    $clinicProfile = $db->prepare(
        'INSERT INTO clinic_profiles (user_id, clinic_name, phone, address, time_zone, latitude, longitude) '
        . 'VALUES (:user_id, :clinic_name, :phone, :address, :time_zone, :latitude, :longitude) '
        . 'ON DUPLICATE KEY UPDATE clinic_name = VALUES(clinic_name), phone = VALUES(phone), address = VALUES(address), '
        . 'time_zone = VALUES(time_zone), latitude = VALUES(latitude), longitude = VALUES(longitude)'
    );
    $clinicProfile->execute([
        'user_id' => $clinicId,
        'clinic_name' => 'GentleCare Sample Clinic',
        'phone' => '+639000000004',
        'address' => 'Sample Avenue, Ermita, Manila, Philippines',
        'time_zone' => 'Asia/Manila',
        'latitude' => '14.5995000',
        'longitude' => '120.9842000',
    ]);

    $manilaLocationId = gc_seed_location(
        $db,
        $manilaDoctorId,
        'GentleCare Manila Sample Practice',
        'Sample Avenue, Ermita, Manila, Philippines',
        'Asia/Manila',
        '14.5995000',
        '120.9842000'
    );
    $pagadianLocationId = gc_seed_location(
        $db,
        $pagadianDoctorId,
        'GentleCare Pagadian Sample Practice',
        'Sample Road, Pagadian City, Zamboanga del Sur, Philippines',
        'Asia/Manila',
        '7.8257000',
        '123.4370000'
    );

    $manilaTimeZone = new DateTimeZone('Asia/Manila');
    $firstSlotStart = (new DateTimeImmutable('now', $manilaTimeZone))->modify('+7 days')->setTime(9, 0);
    $secondSlotStart = $firstSlotStart->modify('+1 hour');
    $pagadianSlotStart = $firstSlotStart->setTime(14, 0);
    gc_seed_slot($db, $manilaDoctorId, $manilaLocationId, $firstSlotStart, $firstSlotStart->modify('+1 hour'));
    gc_seed_slot($db, $manilaDoctorId, $manilaLocationId, $secondSlotStart, $secondSlotStart->modify('+1 hour'));
    gc_seed_slot($db, $pagadianDoctorId, $pagadianLocationId, $pagadianSlotStart, $pagadianSlotStart->modify('+1 hour'));

    $db->commit();
    fwrite(STDOUT, "Seeded verified patient, two doctors, one clinic, locations, and future appointment slots.\n");
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Sample data seeding failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
