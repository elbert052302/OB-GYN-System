<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
gc_start_session();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string) ($_GET['action'] ?? '');

function gc_appointment_manage_user(): array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || !isset($user['id'], $user['role'])) {
        gc_api_respond(401, 'Please log in to continue.');
    }
    if (!in_array($user['role'], ['patient', 'doctor'], true)) {
        gc_api_respond(403, 'Your account cannot perform this action.');
    }
    return $user;
}

if ($method === 'GET' && $action === 'mine') {
    $user = gc_appointment_manage_user();
    $ownerColumn = $user['role'] === 'patient' ? 'appointment.patient_user_id' : 'appointment.doctor_user_id';
    try {
        $statement = gc_db()->prepare(
            'SELECT appointment.id, appointment.status, '
            . 'DATE_FORMAT(slot.starts_at_utc, "%Y-%m-%dT%H:%i:%sZ") AS starts_at_utc, '
            . 'DATE_FORMAT(slot.ends_at_utc, "%Y-%m-%dT%H:%i:%sZ") AS ends_at_utc, '
            . 'appointment.patient_user_id, patient.full_name AS patient_name, '
            . 'appointment.doctor_user_id, doctor.full_name AS doctor_name, '
            . 'appointment.location_id, location.name AS location_name, location.address AS location_address, '
            . 'location.time_zone, purpose.purpose, purpose.other_description '
            . 'FROM appointments AS appointment '
            . 'JOIN appointment_slots AS slot ON slot.id = appointment.slot_id '
            . 'JOIN practice_locations AS location ON location.id = appointment.location_id '
            . 'JOIN patient_profiles AS patient ON patient.user_id = appointment.patient_user_id '
            . 'JOIN doctor_profiles AS doctor ON doctor.user_id = appointment.doctor_user_id '
            . 'LEFT JOIN appointment_purposes AS purpose ON purpose.appointment_id = appointment.id '
            . 'WHERE ' . $ownerColumn . ' = :user_id '
            . 'ORDER BY slot.starts_at_utc DESC, appointment.id DESC, purpose.purpose'
        );
        $statement->execute(['user_id' => $user['id']]);

        $appointments = [];
        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['id'];
            if (!isset($appointments[$id])) {
                $appointments[$id] = [
                    'id' => $id,
                    'status' => $row['status'],
                    'startsAtUtc' => $row['starts_at_utc'],
                    'endsAtUtc' => $row['ends_at_utc'],
                    'timeZone' => $row['time_zone'],
                    'location' => [
                        'id' => (int) $row['location_id'],
                        'name' => $row['location_name'],
                        'address' => $row['location_address'],
                    ],
                    'doctor' => [
                        'id' => (int) $row['doctor_user_id'],
                        'name' => $row['doctor_name'],
                    ],
                    'patient' => [
                        'id' => (int) $row['patient_user_id'],
                        'name' => $row['patient_name'],
                    ],
                    'purposes' => [],
                ];
            }
            if ($row['purpose'] !== null) {
                $appointments[$id]['purposes'][] = [
                    'name' => $row['purpose'],
                    'otherDescription' => $row['other_description'],
                ];
            }
        }

        gc_api_respond(200, null, ['appointments' => array_values($appointments)]);
    } catch (Throwable $exception) {
        error_log('GentleCare appointment lookup failed: ' . $exception->getMessage());
        gc_api_respond(503, 'The service is temporarily unavailable.');
    }
}

if ($method === 'GET') {
    gc_api_respond(400, 'Unsupported appointments action.');
}
if ($method !== 'POST') {
    header('Allow: GET, POST');
    gc_api_respond(405, 'Unsupported request method.');
}
if ($action !== 'cancel') {
    gc_api_respond(400, 'Unsupported appointments action.');
}

$user = gc_appointment_manage_user();
gc_api_require_csrf();
$input = gc_api_input();
$appointmentId = filter_var(
    $input['appointment_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$reason = $input['reason'] ?? '';
if ($appointmentId === false || !is_string($reason) || mb_strlen(trim($reason)) > 500) {
    gc_api_respond(422, 'Provide a valid appointment_id and an optional reason up to 500 characters.');
}
$reason = trim($reason);
$reason = $reason === '' ? null : $reason;

$db = null;
try {
    $db = gc_db();
    $db->beginTransaction();
    $appointmentQuery = $db->prepare(
        'SELECT appointment.id, appointment.status, appointment.patient_user_id, appointment.doctor_user_id, '
        . 'patient.full_name AS patient_name, doctor.full_name AS doctor_name '
        . 'FROM appointments AS appointment '
        . 'JOIN patient_profiles AS patient ON patient.user_id = appointment.patient_user_id '
        . 'JOIN doctor_profiles AS doctor ON doctor.user_id = appointment.doctor_user_id '
        . 'WHERE appointment.id = :appointment_id FOR UPDATE'
    );
    $appointmentQuery->execute(['appointment_id' => $appointmentId]);
    $appointment = $appointmentQuery->fetch();
    $ownerId = $user['role'] === 'patient'
        ? (int) ($appointment['patient_user_id'] ?? 0)
        : (int) ($appointment['doctor_user_id'] ?? 0);
    if (!$appointment || $ownerId !== (int) $user['id']) {
        $db->rollBack();
        gc_api_respond(404, 'Appointment not found.');
    }
    if ($appointment['status'] !== 'confirmed') {
        $db->rollBack();
        gc_api_respond(409, 'Only confirmed appointments can be cancelled.');
    }

    $cancel = $db->prepare(
        'UPDATE appointments SET status = "cancelled", ended_at = UTC_TIMESTAMP() '
        . 'WHERE id = :appointment_id AND status = "confirmed"'
    );
    $cancel->execute(['appointment_id' => $appointmentId]);
    if ($cancel->rowCount() !== 1) {
        $db->rollBack();
        gc_api_respond(409, 'This appointment can no longer be cancelled.');
    }

    $reservation = $db->prepare(
        'DELETE FROM active_appointment_reservations WHERE appointment_id = :appointment_id'
    );
    $reservation->execute(['appointment_id' => $appointmentId]);

    $history = $db->prepare(
        'INSERT INTO appointment_history (appointment_id, actor_user_id, action, reason) '
        . 'VALUES (:appointment_id, :actor_user_id, "cancelled", :reason)'
    );
    $history->execute([
        'appointment_id' => $appointmentId,
        'actor_user_id' => $user['id'],
        'reason' => $reason,
    ]);

    $otherUserId = (int) ($user['role'] === 'patient'
        ? $appointment['doctor_user_id']
        : $appointment['patient_user_id']);
    $otherName = $user['role'] === 'patient' ? $appointment['patient_name'] : $appointment['doctor_name'];
    $notification = $db->prepare(
        'INSERT INTO notifications (user_id, appointment_id, notification_type, message, created_at) '
        . 'VALUES (:user_id, :appointment_id, "appointment_cancelled", :message, UTC_TIMESTAMP())'
    );
    $notification->execute([
        'user_id' => $otherUserId,
        'appointment_id' => $appointmentId,
        'message' => 'An appointment with ' . $otherName . ' was cancelled.',
    ]);

    $db->commit();
    gc_api_respond(200, null, [
        'appointment' => [
            'id' => (int) $appointmentId,
            'status' => 'cancelled',
        ],
    ]);
} catch (Throwable $exception) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('GentleCare appointment cancellation failed: ' . $exception->getMessage());
    gc_api_respond(503, 'The service is temporarily unavailable.');
}
