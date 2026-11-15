<?php
declare(strict_types=1);

/**
 * Creates upcoming demo slots so the patient booking flow has something to show
 * during a demo or a user-acceptance session.
 *
 * Only verified doctors that already have an active practice location with map
 * coordinates get slots. Idempotent: running it twice does not duplicate slots.
 *
 * Usage:
 *   C:\xampp\php\php.exe Backend\tests\prepare_demo.php
 *   C:\xampp\php\php.exe Backend\tests\prepare_demo.php 14   (days ahead, default 7)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/api/bootstrap.php';

$days = isset($argv[1]) ? max(1, min(60, (int) $argv[1])) : 7;
$timeZone = new DateTimeZone('Asia/Manila');
$utc = new DateTimeZone('UTC');
$db = gc_db();

$doctors = $db->query(
    'SELECT doctor.user_id, doctor.full_name, location.id AS location_id '
    . 'FROM doctor_profiles AS doctor '
    . 'JOIN users AS user ON user.id = doctor.user_id AND user.email_verified_at IS NOT NULL '
    . 'JOIN practice_locations AS location ON location.owner_user_id = doctor.user_id AND location.is_active = 1 '
    . 'WHERE location.latitude IS NOT NULL AND location.longitude IS NOT NULL '
    . 'GROUP BY doctor.user_id, doctor.full_name, location.id '
    . 'ORDER BY doctor.user_id, location.id'
)->fetchAll();

if ($doctors === []) {
    fwrite(STDERR, "No verified doctor with an active mapped practice location was found.\n");
    exit(1);
}

$times = [['09:00', '30'], ['10:30', '30'], ['14:00', '30']];
$insert = $db->prepare(
    'INSERT INTO appointment_slots (doctor_user_id, location_id, starts_at_utc, ends_at_utc, created_by) '
    . 'VALUES (:doctor_id, :location_id, :starts_at, :ends_at, :created_by)'
);

$created = 0;
$skipped = 0;
$today = new DateTimeImmutable('today', $timeZone);

for ($offset = 1; $offset <= $days; $offset++) {
    $day = $today->modify('+' . $offset . ' day');
    // Keep the demo schedule on weekdays.
    if ((int) $day->format('N') >= 6) {
        continue;
    }
    foreach ($doctors as $doctor) {
        foreach ($times as [$startTime, $minutes]) {
            $start = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $startTime, $timeZone);
            $end = $start->modify('+' . $minutes . ' minutes');
            try {
                $insert->execute([
                    'doctor_id' => (int) $doctor['user_id'],
                    'location_id' => (int) $doctor['location_id'],
                    'starts_at' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
                    'ends_at' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
                    'created_by' => (int) $doctor['user_id'],
                ]);
                $created++;
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }
                $skipped++;
            }
        }
    }
}

printf(
    "Doctors: %d, days ahead: %d, slots created: %d, already present: %d\n",
    count($doctors),
    $days,
    $created,
    $skipped
);
foreach ($doctors as $doctor) {
    printf("  - %s (location %d)\n", $doctor['full_name'], (int) $doctor['location_id']);
}
