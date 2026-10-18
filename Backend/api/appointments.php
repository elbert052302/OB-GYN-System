<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
gc_start_session();

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    gc_api_respond(405, 'Unsupported request method.');
}
if ((string) ($_GET['action'] ?? '') !== 'book') {
    gc_api_respond(400, 'Unsupported appointments action.');
}
$user = gc_api_require_user('patient');
gc_api_require_csrf();
$input = gc_api_input();
$slotId = filter_var($input['slot_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$purposes = $input['purposes'] ?? null;
$otherDescription = trim((string) ($input['other_description'] ?? ''));
if ($slotId === false) {
    gc_api_respond(422, 'Provide a valid slot_id.');
}
if (!is_array($purposes) || $purposes === [] || count($purposes) > 12) {
    gc_api_respond(422, 'Provide one or more appointment purposes.');
}

$normalizedPurposes = [];
foreach ($purposes as $purpose) {
    if (!is_string($purpose)) {
        gc_api_respond(422, 'Appointment purposes must be text values.');
    }
    $purpose = trim($purpose);
    if ($purpose === '' || mb_strlen($purpose) > 80 || in_array($purpose, $normalizedPurposes, true)) {
        gc_api_respond(422, 'Appointment purposes must be unique, non-empty values up to 80 characters.');
    }
    $normalizedPurposes[] = $purpose;
}
$hasOther = in_array('Other', $normalizedPurposes, true);
if (($hasOther && ($otherDescription === '' || mb_strlen($otherDescription) > 255))
    || (!$hasOther && $otherDescription !== '')) {
    gc_api_respond(422, 'Provide an other_description up to 255 characters when Other is selected.');
}

$db = null;
try {
    $db = gc_db();
    $db->beginTransaction();

    $slotStatement = $db->prepare(
        'SELECT slot.id, slot.doctor_user_id, slot.location_id, slot.starts_at_utc, slot.ends_at_utc, location.time_zone '
        . 'FROM appointment_slots AS slot JOIN practice_locations AS location ON location.id = slot.location_id '
        . 'WHERE slot.id = :slot_id FOR UPDATE'
    );
    $slotStatement->execute(['slot_id' => $slotId]);
    $slot = $slotStatement->fetch();
    if (!$slot) {
        $db->rollBack();
        gc_api_respond(404, 'Appointment slot not found.');
    }
    if (strtotime((string) $slot['starts_at_utc'] . ' UTC') <= time()) {
        $db->rollBack();
        gc_api_respond(409, 'This appointment slot is no longer available.');
    }

    $patientLock = $db->prepare('SELECT user_id FROM patient_profiles WHERE user_id = :patient_id FOR UPDATE');
    $patientLock->execute(['patient_id' => $user['id']]);
    if (!$patientLock->fetch()) {
        $db->rollBack();
        gc_api_respond(403, 'A patient profile is required to book a slot.');
    }

    $reservation = $db->prepare(
        'SELECT appointment_id FROM active_appointment_reservations '
        . 'WHERE slot_id = :slot_id OR patient_user_id = :patient_id LIMIT 1'
    );
    $reservation->execute(['slot_id' => $slotId, 'patient_id' => $user['id']]);
    if ($reservation->fetch()) {
        $db->rollBack();
        gc_api_respond(409, 'This slot is taken or you already have an active appointment.');
    }

    $appointment = $db->prepare(
        'INSERT INTO appointments (patient_user_id, doctor_user_id, location_id, slot_id, status, created_by) '
        . 'VALUES (:patient_id, :doctor_id, :location_id, :slot_id, "confirmed", :created_by)'
    );
    $appointment->execute([
        'patient_id' => $user['id'],
        'doctor_id' => $slot['doctor_user_id'],
        'location_id' => $slot['location_id'],
        'slot_id' => $slotId,
        'created_by' => $user['id'],
    ]);
    $appointmentId = (int) $db->lastInsertId();

    $purposeStatement = $db->prepare(
        'INSERT INTO appointment_purposes (appointment_id, purpose, other_description) '
        . 'VALUES (:appointment_id, :purpose, :other_description)'
    );
    foreach ($normalizedPurposes as $purpose) {
        $purposeStatement->execute([
            'appointment_id' => $appointmentId,
            'purpose' => $purpose,
            'other_description' => $purpose === 'Other' ? $otherDescription : null,
        ]);
    }

    $reservationInsert = $db->prepare(
        'INSERT INTO active_appointment_reservations (appointment_id, patient_user_id, slot_id) '
        . 'VALUES (:appointment_id, :patient_id, :slot_id)'
    );
    $reservationInsert->execute([
        'appointment_id' => $appointmentId,
        'patient_id' => $user['id'],
        'slot_id' => $slotId,
    ]);

    $history = $db->prepare(
        'INSERT INTO appointment_history (appointment_id, actor_user_id, action) '
        . 'VALUES (:appointment_id, :actor_id, "booked")'
    );
    $history->execute(['appointment_id' => $appointmentId, 'actor_id' => $user['id']]);
    $db->commit();

    gc_api_respond(201, null, [
        'appointment' => [
            'id' => $appointmentId,
            'status' => 'confirmed',
            'slotId' => (int) $slotId,
            'startsAtUtc' => str_replace(' ', 'T', (string) $slot['starts_at_utc']) . 'Z',
            'endsAtUtc' => str_replace(' ', 'T', (string) $slot['ends_at_utc']) . 'Z',
            'timeZone' => (string) $slot['time_zone'],
            'purposes' => $normalizedPurposes,
            'otherDescription' => $hasOther ? $otherDescription : null,
        ],
    ]);
} catch (PDOException $exception) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('GentleCare appointment booking failed: ' . $exception->getMessage());
    if ($exception->getCode() === '23000') {
        gc_api_respond(409, 'This slot is taken or you already have an active appointment.');
    }
    gc_api_respond(503, 'The service is temporarily unavailable.');
} catch (Throwable $exception) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('GentleCare appointment request failed: ' . $exception->getMessage());
    gc_api_respond(503, 'The service is temporarily unavailable.');
}
