<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
gc_start_session();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET') {
    if ($action === 'available') {
        gc_api_require_user('patient');
        try {
            $statement = gc_db()->prepare(
                'SELECT s.id, s.doctor_user_id, doctor.full_name AS doctor_name, location.id AS location_id, '
                . 'location.name AS location_name, location.address, location.time_zone, '
                . 'DATE_FORMAT(s.starts_at_utc, "%Y-%m-%dT%H:%i:%sZ") AS starts_at_utc, '
                . 'DATE_FORMAT(s.ends_at_utc, "%Y-%m-%dT%H:%i:%sZ") AS ends_at_utc '
                . 'FROM appointment_slots AS s '
                . 'JOIN users AS doctor ON doctor.id = s.doctor_user_id AND doctor.role = "doctor" '
                . 'JOIN practice_locations AS location ON location.id = s.location_id AND location.is_active = 1 '
                . 'WHERE s.starts_at_utc > UTC_TIMESTAMP() '
                . 'AND NOT EXISTS (SELECT 1 FROM active_appointment_reservations AS reservation WHERE reservation.slot_id = s.id) '
                . 'ORDER BY s.starts_at_utc, s.id'
            );
            $statement->execute();
            $slots = [];
            foreach ($statement->fetchAll() as $slot) {
                $slots[] = [
                    'id' => (int) $slot['id'],
                    'doctor' => ['id' => (int) $slot['doctor_user_id'], 'name' => $slot['doctor_name']],
                    'location' => [
                        'id' => (int) $slot['location_id'],
                        'name' => $slot['location_name'],
                        'address' => $slot['address'],
                        'timeZone' => $slot['time_zone'],
                    ],
                    'startsAtUtc' => $slot['starts_at_utc'],
                    'endsAtUtc' => $slot['ends_at_utc'],
                ];
            }
            gc_api_respond(200, null, ['slots' => $slots]);
        } catch (Throwable $exception) {
            error_log('GentleCare available slots lookup failed: ' . $exception->getMessage());
            gc_api_respond(503, 'The service is temporarily unavailable.');
        }
    }

    if (in_array($action, ['mine', 'locations'], true)) {
        $user = gc_api_require_user('doctor');
        try {
            $db = gc_db();
            if ($action === 'locations') {
                $statement = $db->prepare(
                    'SELECT id, name, address, time_zone FROM practice_locations '
                    . 'WHERE owner_user_id = :doctor_id AND is_active = 1 ORDER BY id'
                );
                $statement->execute(['doctor_id' => $user['id']]);
                gc_api_respond(200, null, ['locations' => $statement->fetchAll()]);
            }

            $statement = $db->prepare(
                'SELECT s.id, s.location_id, location.name AS location_name, location.time_zone, '
                . 'DATE_FORMAT(s.starts_at_utc, "%Y-%m-%dT%H:%i:%sZ") AS starts_at_utc, '
                . 'DATE_FORMAT(s.ends_at_utc, "%Y-%m-%dT%H:%i:%sZ") AS ends_at_utc, '
                . 'EXISTS(SELECT 1 FROM active_appointment_reservations AS reservation WHERE reservation.slot_id = s.id) AS is_reserved '
                . 'FROM appointment_slots AS s '
                . 'JOIN practice_locations AS location ON location.id = s.location_id '
                . 'WHERE s.doctor_user_id = :doctor_id ORDER BY s.starts_at_utc, s.id'
            );
            $statement->execute(['doctor_id' => $user['id']]);
            $slots = [];
            foreach ($statement->fetchAll() as $slot) {
                $slots[] = [
                    'id' => (int) $slot['id'],
                    'location' => [
                        'id' => (int) $slot['location_id'],
                        'name' => $slot['location_name'],
                        'timeZone' => $slot['time_zone'],
                    ],
                    'startsAtUtc' => $slot['starts_at_utc'],
                    'endsAtUtc' => $slot['ends_at_utc'],
                    'reserved' => (bool) $slot['is_reserved'],
                ];
            }
            gc_api_respond(200, null, ['slots' => $slots]);
        } catch (Throwable $exception) {
            error_log('GentleCare doctor schedule lookup failed: ' . $exception->getMessage());
            gc_api_respond(503, 'The service is temporarily unavailable.');
        }
    }

    header('Allow: GET, POST');
    gc_api_respond(400, 'Unsupported slots action.');
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    gc_api_respond(405, 'Unsupported request method.');
}

$action = (string) ($_GET['action'] ?? '');
if (!in_array($action, ['create_location', 'create_slot'], true)) {
    gc_api_respond(400, 'Unsupported slots action.');
}
$user = gc_api_require_user('doctor');
gc_api_require_csrf();
$input = gc_api_input();

try {
    $db = gc_db();
    if ($action === 'create_location') {
        $rawName = $input['name'] ?? null;
        $rawAddress = $input['address'] ?? null;
        if (!is_string($rawName) || !is_string($rawAddress)) {
            gc_api_respond(422, 'Enter a valid location name and address.');
        }
        $name = trim($rawName);
        $address = trim($rawAddress);
        $timeZone = $input['time_zone'] ?? null;
        if ($name === '' || mb_strlen($name) > 160 || $address === '' || mb_strlen($address) > 255) {
            gc_api_respond(422, 'Enter a valid location name and address.');
        }
        if (!gc_valid_time_zone($timeZone)) {
            gc_api_respond(422, 'Missing or invalid time_zone.');
        }

        $statement = $db->prepare(
            'INSERT INTO practice_locations (owner_user_id, name, address, time_zone) '
            . 'VALUES (:doctor_id, :name, :address, :time_zone)'
        );
        $statement->execute([
            'doctor_id' => $user['id'],
            'name' => $name,
            'address' => $address,
            'time_zone' => $timeZone,
        ]);
        gc_api_respond(201, null, [
            'location' => [
                'id' => (int) $db->lastInsertId(),
                'name' => $name,
                'address' => $address,
                'timeZone' => $timeZone,
            ],
        ]);
    }

    $locationId = filter_var($input['location_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $date = $input['date'] ?? null;
    $startTime = $input['start_time'] ?? null;
    $endTime = $input['end_time'] ?? null;
    if ($locationId === false || !is_string($date) || !is_string($startTime) || !is_string($endTime)) {
        gc_api_respond(422, 'Provide a location_id, local date, start_time, and end_time.');
    }

    $locationStatement = $db->prepare(
        'SELECT id, time_zone FROM practice_locations '
        . 'WHERE id = :location_id AND owner_user_id = :doctor_id AND is_active = 1'
    );
    $locationStatement->execute(['location_id' => $locationId, 'doctor_id' => $user['id']]);
    $location = $locationStatement->fetch();
    if (!$location) {
        gc_api_respond(404, 'Practice location not found.');
    }
    $timeZone = new DateTimeZone((string) $location['time_zone']);
    $startsAt = gc_local_datetime_to_utc($date, $startTime, $timeZone);
    $endsAt = gc_local_datetime_to_utc($date, $endTime, $timeZone);
    if (!$startsAt || !$endsAt || $endsAt <= $startsAt) {
        gc_api_respond(422, 'Enter valid, unambiguous local start and end times on the same date.');
    }
    if ($startsAt->getTimestamp() <= time()) {
        gc_api_respond(422, 'A slot cannot start in the past.');
    }

    $db->beginTransaction();
    $doctorLock = $db->prepare('SELECT user_id FROM doctor_profiles WHERE user_id = :doctor_id FOR UPDATE');
    $doctorLock->execute(['doctor_id' => $user['id']]);
    $overlap = $db->prepare(
        'SELECT id FROM appointment_slots WHERE doctor_user_id = :doctor_id '
        . 'AND starts_at_utc < :ends_at_utc AND ends_at_utc > :starts_at_utc LIMIT 1'
    );
    $overlap->execute([
        'doctor_id' => $user['id'],
        'ends_at_utc' => $endsAt->format('Y-m-d H:i:s'),
        'starts_at_utc' => $startsAt->format('Y-m-d H:i:s'),
    ]);
    if ($overlap->fetch()) {
        $db->rollBack();
        gc_api_respond(409, 'The slot overlaps another slot in your schedule.');
    }

    $statement = $db->prepare(
        'INSERT INTO appointment_slots (doctor_user_id, location_id, starts_at_utc, ends_at_utc, created_by) '
        . 'VALUES (:doctor_id, :location_id, :starts_at_utc, :ends_at_utc, :created_by)'
    );
    $statement->execute([
        'doctor_id' => $user['id'],
        'location_id' => $locationId,
        'starts_at_utc' => $startsAt->format('Y-m-d H:i:s'),
        'ends_at_utc' => $endsAt->format('Y-m-d H:i:s'),
        'created_by' => $user['id'],
    ]);
    $slotId = (int) $db->lastInsertId();
    $db->commit();
    gc_api_respond(201, null, [
        'slot' => [
            'id' => $slotId,
            'locationId' => (int) $locationId,
            'timeZone' => $timeZone->getName(),
            'startsAtUtc' => $startsAt->format('Y-m-d\TH:i:s\Z'),
            'endsAtUtc' => $endsAt->format('Y-m-d\TH:i:s\Z'),
        ],
    ]);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('GentleCare slot write failed: ' . $exception->getMessage());
    if ($exception->getCode() === '23000') {
        gc_api_respond(409, 'The slot conflicts with an existing schedule entry.');
    }
    gc_api_respond(503, 'The service is temporarily unavailable.');
} catch (Throwable $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('GentleCare slot request failed: ' . $exception->getMessage());
    gc_api_respond(503, 'The service is temporarily unavailable.');
}
