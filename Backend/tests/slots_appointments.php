<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/bootstrap.php';

function gc_workflow_request(string $url, string $method, ?array $payload, array &$session, bool $includeCsrf = true): array
{
    $headers = ['Accept: application/json'];
    if ($session['cookie'] !== '') {
        $headers[] = 'Cookie: ' . $session['cookie'];
    }
    if ($includeCsrf && $session['csrf'] !== '') {
        $headers[] = 'X-CSRF-Token: ' . $session['csrf'];
    }

    $options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20,
    ];
    if ($payload !== null) {
        $options['header'] .= "\r\nContent-Type: application/json";
        $options['content'] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    $context = stream_context_create(['http' => $options]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false || !isset($http_response_header[0])
        || !preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
        throw new RuntimeException('API request failed: ' . $url);
    }

    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $cookieMatch)) {
            $session['cookie'] = str_ends_with($cookieMatch[1], '=') ? '' : $cookieMatch[1];
        }
    }
    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (isset($decoded['csrfToken'])) {
        $session['csrf'] = (string) $decoded['csrfToken'];
    }
    return ['status' => (int) $matches[1], 'body' => $decoded];
}

function gc_workflow_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function gc_workflow_expect(array $response, int $status, bool $ok, string $test): void
{
    gc_workflow_assert(
        $response['status'] === $status
            && ($response['body']['ok'] ?? null) === $ok
            && array_key_exists('error', $response['body'])
            && array_key_exists('data', $response['body']),
            $test . ' returned an unexpected status or JSON envelope (HTTP '
                . $response['status'] . ': '
                . json_encode($response['body']['error'] ?? null, JSON_UNESCAPED_SLASHES) . ').'
    );
}

function gc_workflow_login(string $authUrl, string $email, string $password): array
{
    $session = ['cookie' => '', 'csrf' => ''];
    $csrf = gc_workflow_request($authUrl . '?action=csrf', 'GET', null, $session);
    gc_workflow_assert($csrf['status'] === 200 && $session['csrf'] !== '', 'Could not initialize fixture session.');
    $login = gc_workflow_request(
        $authUrl . '?action=login',
        'POST',
        ['identifier' => $email, 'password' => $password],
        $session
    );
    gc_workflow_assert($login['status'] === 200 && isset($login['body']['user']), 'Could not log in fixture account.');
    return $session;
}

function gc_workflow_create_slot(string $slotsUrl, int $locationId, string $date, string $start, string $end, array &$session): array
{
    $response = gc_workflow_request(
        $slotsUrl . '?action=create_slot',
        'POST',
        ['location_id' => $locationId, 'date' => $date, 'start_time' => $start, 'end_time' => $end],
        $session
    );
    return $response;
}

function gc_workflow_parallel_booking(array $fixtures, string $directory, array &$tempFiles): array
{
    $releasePath = $directory . DIRECTORY_SEPARATOR . 'release';
    $processes = [];
    foreach ($fixtures as $index => $fixture) {
        $fixturePath = $directory . DIRECTORY_SEPARATOR . 'worker-' . $index . '.json';
        $readyPath = $directory . DIRECTORY_SEPARATOR . 'ready-' . $index;
        file_put_contents($fixturePath, json_encode($fixture, JSON_THROW_ON_ERROR));
        $tempFiles[] = $fixturePath;
        $tempFiles[] = $readyPath;
        $command = [PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . 'booking_worker.php', $fixturePath, $readyPath, $releasePath];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start a concurrent booking worker.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $processes[] = ['process' => $process, 'pipes' => $pipes, 'ready' => $readyPath];
    }

    $deadline = microtime(true) + 10;
    do {
        $ready = true;
        foreach ($processes as $worker) {
            $ready = $ready && is_file($worker['ready']);
        }
        if (!$ready) {
            usleep(10000);
        }
    } while (!$ready && microtime(true) < $deadline);
    if (!$ready) {
        throw new RuntimeException('Concurrent booking workers did not reach the start barrier.');
    }

    $tempFiles[] = $releasePath;
    file_put_contents($releasePath, 'go');
    $results = [];
    foreach ($processes as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        if ($exitCode !== 0) {
            throw new RuntimeException('Concurrent booking worker failed: ' . trim($stderr));
        }
        $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }
    return $results;
}

$base = rtrim(getenv('GENTLECARE_API_BASE') ?: 'http://127.0.0.1/IPT%20PROJECT/OB-GYN/Backend/api', '/');
$authUrl = $base . '/auth.php';
$slotsUrl = $base . '/slots.php';
$providersUrl = $base . '/providers.php';
$appointmentsUrl = $base . '/appointments.php?action=book';
$manageAppointmentsUrl = $base . '/appointments_manage.php';
$db = gc_db();
$password = 'TestPassword!2026';
$userIds = [];
$emails = [];
$tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gentlecare-phase5-' . bin2hex(random_bytes(8));
$tempFiles = [];
$tempDirectories = [$tempDirectory];
if (!mkdir($tempDirectory, 0700)) {
    throw new RuntimeException('Could not create the concurrent-test temporary directory.');
}

try {
    $doctorUsers = [];
    for ($index = 1; $index <= 2; $index++) {
        $email = 'phase5-slots-doctor' . $index . '-' . bin2hex(random_bytes(8)) . '@example.test';
        $emails[] = $email;
        $statement = $db->prepare(
            'INSERT INTO users (role, email, password_hash, email_verified_at) '
            . 'VALUES ("doctor", :email, :password_hash, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
        ]);
        $doctorId = (int) $db->lastInsertId();
        $userIds[] = $doctorId;
        $doctorProfile = $db->prepare(
            'INSERT INTO doctor_profiles (user_id, full_name, phone, license_number, specialization, time_zone) '
            . 'VALUES (:user_id, :full_name, :phone, :license, "Test", "UTC")'
        );
        $doctorProfile->execute([
            'user_id' => $doctorId,
            'full_name' => 'Slot Test Doctor ' . $index,
            'phone' => null,
            'license' => 'TEST-' . bin2hex(random_bytes(12)),
        ]);
        $doctorUsers[] = ['id' => $doctorId, 'email' => $email];
    }

    $patients = [];
    for ($index = 1; $index <= 3; $index++) {
        $email = 'phase5-slots-patient' . $index . '-' . bin2hex(random_bytes(8)) . '@example.test';
        $emails[] = $email;
        $statement = $db->prepare(
            'INSERT INTO users (role, email, password_hash, email_verified_at) '
            . 'VALUES ("patient", :email, :password_hash, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
        ]);
        $patientId = (int) $db->lastInsertId();
        $userIds[] = $patientId;
        $profile = $db->prepare(
            'INSERT INTO patient_profiles (user_id, full_name, phone, date_of_birth, emergency_contact, address) '
            . 'VALUES (:user_id, :full_name, :phone, "1990-01-01", "Test contact", "Test address")'
        );
        $profile->execute([
            'user_id' => $patientId,
            'full_name' => 'Slot Test Patient ' . $index,
            'phone' => null,
        ]);
        $patients[] = ['id' => $patientId, 'email' => $email];
    }

    $doctor = gc_workflow_login($authUrl, $doctorUsers[0]['email'], $password);
    $otherDoctor = gc_workflow_login($authUrl, $doctorUsers[1]['email'], $password);
    $patient = gc_workflow_login($authUrl, $patients[0]['email'], $password);
    $otherPatient = gc_workflow_login($authUrl, $patients[1]['email'], $password);
    $thirdPatient = gc_workflow_login($authUrl, $patients[2]['email'], $password);
    $anonymous = ['cookie' => '', 'csrf' => ''];
    $anonymousCsrf = gc_workflow_request($authUrl . '?action=csrf', 'GET', null, $anonymous);
    $locationFields = [
        'country' => 'PH',
        'province_code' => '0702200000',
        'city_code' => '0702201000',
        'barangay_code' => '0702201001',
        'street' => 'Osmeña Street',
    ];

    $unauth = gc_workflow_request($slotsUrl . '?action=available', 'GET', null, $anonymous);
    gc_workflow_expect($unauth, 401, false, 'Unauthenticated available-slots request');
    $unauthPost = gc_workflow_request(
        $slotsUrl . '?action=create_location',
        'POST',
        array_merge(['name' => 'Unauthorized'], $locationFields),
        $anonymous
    );
    gc_workflow_expect($unauthPost, 401, false, 'Unauthenticated location creation');
    printf("PASS access: unauthenticated GET=401 and POST=401\n");

    $wrongRole = gc_workflow_request(
        $slotsUrl . '?action=create_location',
        'POST',
        array_merge(['name' => 'Wrong role'], $locationFields),
        $patient
    );
    gc_workflow_expect($wrongRole, 403, false, 'Patient publishing a location');
    $wrongBookRole = gc_workflow_request(
        $appointmentsUrl,
        'POST',
        ['slot_id' => 1, 'purposes' => ['Test']],
        $doctor
    );
    gc_workflow_expect($wrongBookRole, 403, false, 'Doctor booking as patient');
    printf("PASS role checks: patient publish=403 and doctor book=403\n");

    $missingCsrf = gc_workflow_request(
        $slotsUrl . '?action=create_location',
        'POST',
        array_merge(['name' => 'Missing CSRF'], $locationFields),
        $doctor,
        false
    );
    gc_workflow_expect($missingCsrf, 403, false, 'Missing CSRF token');
    $invalidGeography = gc_workflow_request(
        $slotsUrl . '?action=create_location',
        'POST',
        [
            'name' => 'Invalid geography',
            'country' => 'PH',
            'province_code' => '0702200000',
            'city_code' => '1380601000',
            'barangay_code' => '1380601001',
            'street' => '',
        ],
        $doctor
    );
    gc_workflow_expect($invalidGeography, 422, false, 'Mismatched PSGC hierarchy');
    $invalidLocationFields = gc_workflow_request(
        $slotsUrl . '?action=create_location',
        'POST',
        array_merge(['name' => ['not', 'text']], $locationFields),
        $doctor
    );
    gc_workflow_expect($invalidLocationFields, 422, false, 'Non-text location name');
    $invalidCoordinates = gc_workflow_request(
        $slotsUrl . '?action=create_location',
        'POST',
        array_merge(['name' => 'Invalid coordinates', 'latitude' => 91, 'longitude' => 123], $locationFields),
        $doctor
    );
    gc_workflow_expect($invalidCoordinates, 422, false, 'Out-of-range map coordinates');
    printf("PASS validation: missing CSRF=403, mismatched PSGC codes=422, non-text location name=422, out-of-range coordinates=422\n");

    $locationResponse = gc_workflow_request(
        $slotsUrl . '?action=create_location',
        'POST',
        array_merge(['name' => 'Test Location', 'latitude' => 10.1234, 'longitude' => 123.4567], $locationFields),
        $doctor
    );
    gc_workflow_expect($locationResponse, 201, true, 'Create practice location');
    gc_workflow_assert(
        $locationResponse['body']['data']['location']['address'] === 'Osmeña Street, Cabadiangan, Alcantara, Cebu, Philippines'
            && $locationResponse['body']['data']['location']['timeZone'] === 'Asia/Manila'
            && $locationResponse['body']['data']['location']['latitude'] === 10.1234
            && $locationResponse['body']['data']['location']['longitude'] === 123.4567,
        'Practice location did not return its canonical address, timezone, and map coordinates.'
    );
    $locationId = (int) $locationResponse['body']['data']['location']['id'];
    $ncrLocation = gc_workflow_request(
        $slotsUrl . '?action=create_location',
        'POST',
        [
            'name' => 'NCR Test Location',
            'country' => 'PH',
            'province_code' => 'NCR',
            'city_code' => '1380601000',
            'barangay_code' => '1380601001',
            'street' => '',
        ],
        $doctor
    );
    gc_workflow_expect($ncrLocation, 201, true, 'Create NCR sub-municipality location');
    gc_workflow_assert(
        $ncrLocation['body']['data']['location']['address'] === 'Barangay 1, Tondo I/II, City of Manila, Metro Manila (NCR), Philippines',
        'NCR sub-municipality address did not include its parent city.'
    );
    $ncrLocationId = (int) $ncrLocation['body']['data']['location']['id'];
    $foreignLocation = gc_workflow_create_slot(
        $slotsUrl,
        $locationId,
        gmdate('Y-m-d', time() + 172800),
        '10:00',
        '10:30',
        $otherDoctor
    );
    gc_workflow_expect($foreignLocation, 404, false, 'Create slot at another doctor location');

    $date = gmdate('Y-m-d', time() + 172800);
    $past = gc_workflow_create_slot($slotsUrl, $locationId, '2000-01-01', '10:00', '10:30', $doctor);
    gc_workflow_expect($past, 422, false, 'Past slot publication');
    $invalidInput = gc_workflow_create_slot($slotsUrl, $locationId, 'not-a-date', '25:00', '26:00', $doctor);
    gc_workflow_expect($invalidInput, 422, false, 'Invalid slot input');

    $slotA = gc_workflow_create_slot($slotsUrl, $locationId, $date, '10:00', '10:30', $doctor);
    gc_workflow_expect($slotA, 201, true, 'Create first future slot');
    $slotIdA = (int) $slotA['body']['data']['slot']['id'];
    gc_workflow_assert(
        $slotA['body']['data']['slot']['timeZone'] === 'Asia/Manila'
            && str_ends_with($slotA['body']['data']['slot']['startsAtUtc'], 'Z'),
        'Slot creation did not return the location timezone and UTC timestamp.'
    );
    $overlap = gc_workflow_create_slot($slotsUrl, $locationId, $date, '10:15', '10:45', $doctor);
    gc_workflow_expect($overlap, 409, false, 'Overlapping slot publication');
    $slotB = gc_workflow_create_slot($slotsUrl, $locationId, $date, '11:00', '11:30', $doctor);
    gc_workflow_expect($slotB, 201, true, 'Create second future slot');
    $slotIdB = (int) $slotB['body']['data']['slot']['id'];
    $raceSlot = gc_workflow_create_slot($slotsUrl, $locationId, $date, '12:00', '12:30', $doctor);
    gc_workflow_expect($raceSlot, 201, true, 'Create concurrent-booking slot');
    $raceSlotId = (int) $raceSlot['body']['data']['slot']['id'];
    $ncrSlot = gc_workflow_create_slot(
        $slotsUrl,
        $ncrLocationId,
        $date,
        '16:00',
        '16:30',
        $doctor
    );
    gc_workflow_expect($ncrSlot, 201, true, 'Create slot at NCR practice location');
    $expectedUtc = (new DateTimeImmutable($date . ' 16:00:00', new DateTimeZone('Asia/Manila')))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s\Z');
    gc_workflow_assert(
        $ncrSlot['body']['data']['slot']['timeZone'] === 'Asia/Manila'
            && $ncrSlot['body']['data']['slot']['startsAtUtc'] === $expectedUtc,
        'Slot local time was not converted using the Philippine timezone.'
    );

    $doctorSlots = gc_workflow_request($slotsUrl . '?action=mine', 'GET', null, $doctor);
    gc_workflow_expect($doctorSlots, 200, true, 'Doctor own slots');
    gc_workflow_assert(count($doctorSlots['body']['data']['slots']) === 4, 'Doctor own slot list included unexpected slots.');
    $otherDoctorSlots = gc_workflow_request($slotsUrl . '?action=mine', 'GET', null, $otherDoctor);
    gc_workflow_expect($otherDoctorSlots, 200, true, 'Other doctor own slots');
    gc_workflow_assert($otherDoctorSlots['body']['data']['slots'] === [], 'A doctor could see another doctor’s slots.');
    $available = gc_workflow_request($slotsUrl . '?action=available', 'GET', null, $patient);
    gc_workflow_expect($available, 200, true, 'Patient available slots');
    $doctorAvailable = array_filter(
        $available['body']['data']['slots'],
        fn (array $slot): bool => $slot['doctor']['id'] === $doctorUsers[0]['id']
    );
    gc_workflow_assert(count($doctorAvailable) === 4, 'Patient available slot list did not return all four test-doctor slots.');
    $providers = gc_workflow_request($providersUrl, 'GET', null, $patient);
    gc_workflow_expect($providers, 200, true, 'Patient provider directory');
    $doctorLocation = array_filter(
        $providers['body']['data']['providers'],
        fn (array $provider): bool => $provider['id'] === 'doctor-' . $doctorUsers[0]['id']
            && $provider['location'] === 'Test Location'
    );
    gc_workflow_assert(
        count($doctorLocation) === 1
            && array_values($doctorLocation)[0]['address'] === 'Osmeña Street, Cabadiangan, Alcantara, Cebu, Philippines',
        'Patient provider directory did not include the verified doctor address.'
    );
    printf("PASS slots: verified PSGC locations, Asia/Manila conversion, past/invalid=422, overlap=409, own-list scoped, available-list=4\n");

    $booking = gc_workflow_request(
        $appointmentsUrl,
        'POST',
        [
            'slot_id' => $slotIdA,
            'purposes' => ['Purpose A', 'Purpose B', 'Other'],
            'other_description' => 'Test-only description',
        ],
        $patient
    );
    gc_workflow_expect($booking, 201, true, 'Book appointment');
    gc_workflow_assert(
        ($booking['body']['data']['appointment']['status'] ?? '') === 'confirmed'
            && ($booking['body']['data']['appointment']['timeZone'] ?? '') === 'Asia/Manila',
        'Booking response did not report confirmed status and local timezone.'
    );
    $appointmentId = (int) $booking['body']['data']['appointment']['id'];
    $details = $db->prepare(
        'SELECT appointment.status, COUNT(DISTINCT purpose.purpose) AS purpose_count, '
        . 'COUNT(DISTINCT CASE WHEN history.action = "booked" THEN history.id END) AS booked_history_count '
        . 'FROM appointments AS appointment '
        . 'JOIN appointment_purposes AS purpose ON purpose.appointment_id = appointment.id '
        . 'JOIN appointment_history AS history ON history.appointment_id = appointment.id '
        . 'JOIN active_appointment_reservations AS reservation ON reservation.appointment_id = appointment.id '
        . 'WHERE appointment.id = :appointment_id GROUP BY appointment.id'
    );
    $details->execute(['appointment_id' => $appointmentId]);
    $bookingDetails = $details->fetch();
    gc_workflow_assert(
        $bookingDetails && $bookingDetails['status'] === 'confirmed'
            && (int) $bookingDetails['purpose_count'] === 3
            && (int) $bookingDetails['booked_history_count'] === 1,
        'Appointment, purposes, active reservation, and booking history were not stored atomically.'
    );

    $otherDescriptionMissing = gc_workflow_request(
        $appointmentsUrl,
        'POST',
        ['slot_id' => $slotIdB, 'purposes' => ['Other']],
        $patient
    );
    gc_workflow_expect($otherDescriptionMissing, 422, false, 'Other without description');
    $otherDescriptionInvalid = gc_workflow_request(
        $appointmentsUrl,
        'POST',
        ['slot_id' => $slotIdB, 'purposes' => ['Other'], 'other_description' => ['not', 'text']],
        $patient
    );
    gc_workflow_expect($otherDescriptionInvalid, 422, false, 'Non-text Other description');
    $doubleBooking = gc_workflow_request(
        $appointmentsUrl,
        'POST',
        ['slot_id' => $slotIdA, 'purposes' => ['Double-book test']],
        $otherPatient
    );
    gc_workflow_expect($doubleBooking, 409, false, 'Double-book reserved slot');
    $secondActive = gc_workflow_request(
        $appointmentsUrl,
        'POST',
        ['slot_id' => $slotIdB, 'purposes' => ['Second appointment test']],
        $patient
    );
    gc_workflow_expect($secondActive, 409, false, 'Second active appointment');
    printf("PASS booking: confirmed + multiple purposes + history; missing/non-text Other detail=422, double booking=409, second active=409\n");

    $temporary = $tempDirectory . DIRECTORY_SEPARATOR . 'fixture-' . bin2hex(random_bytes(4));
    if (!mkdir($temporary, 0700)) {
        throw new RuntimeException('Could not create a temporary directory for the booking race.');
    }
    $tempDirectories[] = $temporary;
    $concurrent = gc_workflow_parallel_booking([
        [
            'url' => $appointmentsUrl,
            'cookie' => $otherPatient['cookie'],
            'csrf' => $otherPatient['csrf'],
            'slot_id' => $raceSlotId,
        ],
        [
            'url' => $appointmentsUrl,
            'cookie' => $thirdPatient['cookie'],
            'csrf' => $thirdPatient['csrf'],
            'slot_id' => $raceSlotId,
        ],
    ], $temporary, $tempFiles);
    $statuses = array_column($concurrent, 'status');
    sort($statuses);
    gc_workflow_assert($statuses === [201, 409], 'Concurrent requests did not yield exactly one success and one conflict.');
    foreach ($concurrent as $result) {
        gc_workflow_assert(
            ($result['body']['ok'] ?? null) === ($result['status'] === 201),
            'Concurrent booking returned an inconsistent JSON envelope.'
        );
    }
    printf("PASS concurrent booking: parallel HTTP statuses=%d,%d (exactly one confirmed)\n", $statuses[0], $statuses[1]);

    $availableAfterBooking = gc_workflow_request($slotsUrl . '?action=available', 'GET', null, $patient);
    gc_workflow_expect($availableAfterBooking, 200, true, 'Available slots after booking');
    $doctorAvailableAfterBooking = array_filter(
        $availableAfterBooking['body']['data']['slots'],
        fn (array $slot): bool => $slot['doctor']['id'] === $doctorUsers[0]['id']
    );
    gc_workflow_assert(
        count($doctorAvailableAfterBooking) === 2,
        'Reserved slots remained in the test doctor’s patient-available results.'
    );

    $patientAppointments = gc_workflow_request(
        $manageAppointmentsUrl . '?action=mine',
        'GET',
        null,
        $patient
    );
    gc_workflow_expect($patientAppointments, 200, true, 'Patient appointment list');
    gc_workflow_assert(
        count($patientAppointments['body']['data']['appointments']) === 1
            && (int) $patientAppointments['body']['data']['appointments'][0]['id'] === $appointmentId
            && count($patientAppointments['body']['data']['appointments'][0]['purposes']) === 3,
        'Patient appointment list did not scope the appointment and its purposes.'
    );
    $doctorAppointments = gc_workflow_request(
        $manageAppointmentsUrl . '?action=mine',
        'GET',
        null,
        $doctor
    );
    gc_workflow_expect($doctorAppointments, 200, true, 'Doctor appointment list');
    gc_workflow_assert(
        count($doctorAppointments['body']['data']['appointments']) === 2,
        'Doctor appointment list did not include only the doctor’s two bookings.'
    );

    $cancelPatientAppointment = gc_workflow_request(
        $manageAppointmentsUrl . '?action=cancel',
        'POST',
        ['appointment_id' => $appointmentId, 'reason' => 'Test cancellation'],
        $patient
    );
    gc_workflow_expect($cancelPatientAppointment, 200, true, 'Patient appointment cancellation');
    $patientCancellation = $db->prepare(
        'SELECT appointment.status, appointment.ended_at, '
        . '(SELECT COUNT(*) FROM active_appointment_reservations WHERE appointment_id = appointment.id) AS active_count, '
        . '(SELECT COUNT(*) FROM appointment_history WHERE appointment_id = appointment.id AND actor_user_id = :actor_id '
        . 'AND action = "cancelled" AND reason = :reason) AS history_count, '
        . '(SELECT COUNT(*) FROM notifications WHERE appointment_id = appointment.id AND user_id = :other_user_id '
        . 'AND notification_type = "appointment_cancelled") AS notification_count '
        . 'FROM appointments AS appointment WHERE appointment.id = :appointment_id'
    );
    $patientCancellation->execute([
        'actor_id' => $patients[0]['id'],
        'reason' => 'Test cancellation',
        'other_user_id' => $doctorUsers[0]['id'],
        'appointment_id' => $appointmentId,
    ]);
    $patientCancellationState = $patientCancellation->fetch();
    gc_workflow_assert(
        $patientCancellationState && $patientCancellationState['status'] === 'cancelled'
            && $patientCancellationState['ended_at'] !== null
            && (int) $patientCancellationState['active_count'] === 0
            && (int) $patientCancellationState['history_count'] === 1
            && (int) $patientCancellationState['notification_count'] === 1,
        'Patient cancellation did not atomically update appointment, reservation, history, and notification.'
    );

    $raceAppointment = $db->prepare('SELECT id FROM appointments WHERE slot_id = :slot_id');
    $raceAppointment->execute(['slot_id' => $raceSlotId]);
    $raceAppointmentId = (int) $raceAppointment->fetchColumn();
    $cancelDoctorAppointment = gc_workflow_request(
        $manageAppointmentsUrl . '?action=cancel',
        'POST',
        ['appointment_id' => $raceAppointmentId],
        $doctor
    );
    gc_workflow_expect($cancelDoctorAppointment, 200, true, 'Doctor appointment cancellation');
    printf("PASS appointment management: patient/doctor lists, UTC timezone and purpose data; both roles cancel with reservation, history, and notification updates\n");

    $created = array_fill_keys($emails, true);
    $fixtureIds = $userIds;
    $placeholders = implode(',', array_fill(0, count($fixtureIds), '?'));
    $deleteHistory = $db->prepare(
        'DELETE FROM appointment_history WHERE actor_user_id IN (' . $placeholders . ') '
        . 'OR appointment_id IN (SELECT id FROM appointments WHERE patient_user_id IN (' . $placeholders . '))'
    );
    $deleteHistory->execute(array_merge($fixtureIds, $fixtureIds));
    $deleteNotifications = $db->prepare(
        'DELETE FROM notifications WHERE appointment_id IN '
        . '(SELECT id FROM appointments WHERE patient_user_id IN (' . $placeholders . '))'
    );
    $deleteNotifications->execute($fixtureIds);
    $deleteReservations = $db->prepare(
        'DELETE FROM active_appointment_reservations WHERE patient_user_id IN (' . $placeholders . ')'
    );
    $deleteReservations->execute($fixtureIds);
    $deletePurposes = $db->prepare(
        'DELETE FROM appointment_purposes WHERE appointment_id IN '
        . '(SELECT id FROM appointments WHERE patient_user_id IN (' . $placeholders . '))'
    );
    $deletePurposes->execute($fixtureIds);
    $deleteAppointments = $db->prepare(
        'DELETE FROM appointments WHERE patient_user_id IN (' . $placeholders . ')'
    );
    $deleteAppointments->execute($fixtureIds);
    $deleteSlots = $db->prepare('DELETE FROM appointment_slots WHERE doctor_user_id IN (' . $placeholders . ')');
    $deleteSlots->execute($fixtureIds);
    $deleteLocations = $db->prepare('DELETE FROM practice_locations WHERE owner_user_id IN (' . $placeholders . ')');
    $deleteLocations->execute($fixtureIds);
    $deleteDoctorProfiles = $db->prepare('DELETE FROM doctor_profiles WHERE user_id IN (' . $placeholders . ')');
    $deleteDoctorProfiles->execute($fixtureIds);
    $deletePatientProfiles = $db->prepare('DELETE FROM patient_profiles WHERE user_id IN (' . $placeholders . ')');
    $deletePatientProfiles->execute($fixtureIds);
    $deleteUsers = $db->prepare('DELETE FROM users WHERE id IN (' . $placeholders . ')');
    $deleteUsers->execute($fixtureIds);
    $userIds = [];
    $emails = [];
    printf("PASS fixture cleanup: %d test users and all associated schedules, reservations, appointments, and history removed\n", count($created));
} finally {
    if ($userIds !== []) {
        $fixtureIds = $userIds;
        $placeholders = implode(',', array_fill(0, count($fixtureIds), '?'));
        $deleteHistory = $db->prepare(
            'DELETE FROM appointment_history WHERE actor_user_id IN (' . $placeholders . ') '
            . 'OR appointment_id IN (SELECT id FROM appointments WHERE patient_user_id IN (' . $placeholders . '))'
        );
        $deleteHistory->execute(array_merge($fixtureIds, $fixtureIds));
        $deleteNotifications = $db->prepare(
            'DELETE FROM notifications WHERE appointment_id IN '
            . '(SELECT id FROM appointments WHERE patient_user_id IN (' . $placeholders . '))'
        );
        $deleteNotifications->execute($fixtureIds);
        $deleteReservations = $db->prepare(
            'DELETE FROM active_appointment_reservations WHERE patient_user_id IN (' . $placeholders . ')'
        );
        $deleteReservations->execute($fixtureIds);
        $deletePurposes = $db->prepare(
            'DELETE FROM appointment_purposes WHERE appointment_id IN '
            . '(SELECT id FROM appointments WHERE patient_user_id IN (' . $placeholders . '))'
        );
        $deletePurposes->execute($fixtureIds);
        $deleteAppointments = $db->prepare(
            'DELETE FROM appointments WHERE patient_user_id IN (' . $placeholders . ')'
        );
        $deleteAppointments->execute($fixtureIds);
        $deleteSlots = $db->prepare('DELETE FROM appointment_slots WHERE doctor_user_id IN (' . $placeholders . ')');
        $deleteSlots->execute($fixtureIds);
        $deleteLocations = $db->prepare('DELETE FROM practice_locations WHERE owner_user_id IN (' . $placeholders . ')');
        $deleteLocations->execute($fixtureIds);
        $deleteDoctorProfiles = $db->prepare('DELETE FROM doctor_profiles WHERE user_id IN (' . $placeholders . ')');
        $deleteDoctorProfiles->execute($fixtureIds);
        $deletePatientProfiles = $db->prepare('DELETE FROM patient_profiles WHERE user_id IN (' . $placeholders . ')');
        $deletePatientProfiles->execute($fixtureIds);
        $deleteUsers = $db->prepare('DELETE FROM users WHERE id IN (' . $placeholders . ')');
        $deleteUsers->execute($fixtureIds);
    }
    foreach ($tempFiles as $tempFile) {
        if (is_file($tempFile) && !unlink($tempFile)) {
            throw new RuntimeException('Could not remove a temporary booking test file.');
        }
    }
    foreach (array_reverse($tempDirectories) as $directory) {
        if (is_dir($directory) && !rmdir($directory)) {
            throw new RuntimeException('Could not remove a temporary booking test directory.');
        }
    }
}
