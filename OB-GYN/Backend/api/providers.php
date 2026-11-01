<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
gc_start_session();

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
    && (string) ($_GET['action'] ?? '') === 'mine') {
    gc_api_require_user('clinic');
    try {
        $statement = gc_db()->prepare(
            'SELECT latitude, longitude FROM clinic_profiles WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $_SESSION['user']['id']]);
        $location = $statement->fetch();
        if (!$location) {
            gc_api_respond(404, 'Clinic map location was not found.');
        }
        gc_api_respond(200, null, [
            'location' => [
                'latitude' => $location['latitude'] === null ? null : (float) $location['latitude'],
                'longitude' => $location['longitude'] === null ? null : (float) $location['longitude'],
            ],
        ]);
    } catch (Throwable $exception) {
        error_log('GentleCare clinic map location lookup failed: ' . $exception->getMessage());
        gc_api_respond(503, 'The clinic map location is temporarily unavailable.');
    }
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
    && (string) ($_GET['action'] ?? '') === 'update_location') {
    $user = gc_api_require_user('clinic');
    gc_api_require_csrf();
    $input = gc_api_input();
    $coordinates = gc_coordinates($input['latitude'] ?? null, $input['longitude'] ?? null);
    if ($coordinates === null) {
        gc_api_respond(422, 'Enter both valid map coordinates or leave both blank.');
    }
    try {
        $statement = gc_db()->prepare(
            'UPDATE clinic_profiles SET latitude = :latitude, longitude = :longitude WHERE user_id = :user_id'
        );
        $statement->execute([
            'latitude' => $coordinates['latitude'],
            'longitude' => $coordinates['longitude'],
            'user_id' => $user['id'],
        ]);
        gc_api_respond(200, null, ['location' => $coordinates]);
    } catch (Throwable $exception) {
        error_log('GentleCare clinic map location update failed: ' . $exception->getMessage());
        gc_api_respond(503, 'The clinic map location could not be saved.');
    }
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    gc_api_respond(405, 'Unsupported request method.');
}

gc_api_require_user('patient');

try {
    $db = gc_db();
    $doctors = $db->query(
        'SELECT doctor.user_id AS id, doctor.full_name AS name, doctor.specialization AS detail, '
        . 'location.name AS location_name, location.address, location.latitude, location.longitude '
        . 'FROM doctor_profiles AS doctor '
        . 'JOIN users AS user ON user.id = doctor.user_id AND user.role = "doctor" AND user.email_verified_at IS NOT NULL '
        . 'JOIN practice_locations AS location ON location.owner_user_id = doctor.user_id AND location.is_active = 1 '
        . 'ORDER BY doctor.full_name, location.name'
    )->fetchAll();
    $clinics = $db->query(
        'SELECT clinic.user_id AS id, clinic.clinic_name AS name, clinic.address AS location_name, '
        . 'clinic.address, clinic.latitude, clinic.longitude '
        . 'FROM clinic_profiles AS clinic '
        . 'JOIN users AS user ON user.id = clinic.user_id AND user.role = "clinic" AND user.email_verified_at IS NOT NULL '
        . 'ORDER BY clinic.clinic_name'
    )->fetchAll();

    $providers = [];
    foreach ($doctors as $doctor) {
        $providers[] = [
            'id' => 'doctor-' . (int) $doctor['id'],
            'userId' => (int) $doctor['id'],
            'type' => 'Doctor',
            'name' => $doctor['name'],
            'detail' => $doctor['detail'],
            'location' => $doctor['location_name'],
            'address' => $doctor['address'],
            'latitude' => $doctor['latitude'] === null ? null : (float) $doctor['latitude'],
            'longitude' => $doctor['longitude'] === null ? null : (float) $doctor['longitude'],
        ];
    }
    foreach ($clinics as $clinic) {
        $providers[] = [
            'id' => 'clinic-' . (int) $clinic['id'],
            'userId' => (int) $clinic['id'],
            'type' => 'Clinic',
            'name' => $clinic['name'],
            'detail' => '',
            'location' => $clinic['location_name'],
            'address' => $clinic['address'],
            'latitude' => $clinic['latitude'] === null ? null : (float) $clinic['latitude'],
            'longitude' => $clinic['longitude'] === null ? null : (float) $clinic['longitude'],
        ];
    }

    gc_api_respond(200, null, ['providers' => $providers]);
} catch (Throwable $exception) {
    error_log('GentleCare provider directory lookup failed: ' . $exception->getMessage());
    gc_api_respond(503, 'The provider directory is temporarily unavailable.');
}
