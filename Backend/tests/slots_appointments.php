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

$base = rtrim(getenv('GENTLECARE_API_BASE') ?: 'http://127.0.0.1/OB-GYN/Backend/api', '/');
$authUrl = $base . '/auth.php';
$slotsUrl = $base . '/slots.php';
$appointmentsUrl = $base . '/appointments.php?action=book';
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
            'INSERT INTO users (role, full_name, email, password_hash, email_verified_at) '
            . 'VALUES ("doctor", :name, :email, :password_hash, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'name' => 'Slot Test Doctor ' . $index,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
        ]);
        $doctorId = (int) $db->lastInsertId();
        $userIds[] = $doctorId;
        $doctorProfile = $db->prepare(
            'INSERT INTO doctor_profiles (user_id, license_number, specialization, time_zone) '
            . 'VALUES (:user_id, :license, "Test", "UTC")'
        );
        $doctorProfile->execute([
            'user_id' => $doctorId,
            'license' => 'TEST-' . bin2hex(random_bytes(12)),
        ]);
        $doctorUsers[] = ['id' => $doctorId, 'email' => $email];
    }

    $patients = [];
    for ($index = 1; $index <= 3; $index++) {
        $email = 'phase5-slots-patient' . $index . '-' . bin2hex(random_bytes(8)) . '@example.test';
        $emails[] = $email;
        $statement = $db->prepare(
            'INSERT INTO users (role, full_name, email, password_hash, email_verified_at) '
            . 'VALUES ("patient", :name, :email, :password_hash, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'name' => 'Slot Test Patient ' . $index,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
        ]);
        $patientId = (int) $db->lastInsertId();
        $userIds[] = $patientId;
        $profile = $db->prepare(
            'INSERT INTO patient_profiles (user_id, date_of_birth, emergency_contact) '
            . 'VALUES (:user_id, "1990-01-01", "Test contact")'
        );
        $profile->execute(['user_id' => $patientId]);
        $patients[] = ['id' => $patientId, 'email' => $email];
    }

    $doctor = gc_workflow_login($authUrl, $doctorUsers[0]['email'], $password);
    $otherDoctor = gc_workflow_login($authUrl, $doctorUsers[1]['email'], $password);
    $patient = gc_workflow_login($authUrl, $patients[0]['email'], $password);
    $otherPatient = gc_workflow_login($authUrl, $patients[1]['email'], $password);
    $thirdPatient = gc_workflow_login($authUrl, $patients[2]['email'], $password);
    $anonymous = ['cookie' => '', 'csrf' => ''];
    $anonymousCsrf = gc_workflow_request($authUrl . '?action=csrf', 'GET', null, $anonymous);

    $unauth = gc_workflow_request($slotsUrl . '?action=available', 'GET', null, $anonymous);
    gc_workflow_expect($unauth, 401, false, 'Unauthenticated available-slots request');
    $unauthPost = gc_workflow_request(
        $slotsUrl . '?action=create_location',
        'POST',
        ['name' => 'Unauthorized', 'address' => 'Test', 'time_zone' => 'UTC'],
        $anonymous
    );
    gc_workflow_expect($unauthPost, 401, false, 'Unauthenticated location creation');
    printf("PASS access: unauthenticated GET=401 and POST=401\n");

    $wrongRole = gc_workflow_request(
        $slotsUrl . '?action=create_location',
        'POST',
        ['name' => 'Wrong role', 'address' => 'Test', 'time_zone' => 'UTC'],
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
        ['name' => 'Missing CSRF', 'address' => 'Test', 'time_zone' => 'UTC'],
        $doctor,
        false
    );
    gc_workflow_expect($missingCsrf, 403, false, 'Missing CSRF token');
    $invalidTimeZone = gc_workflow_request(
        $slotsUrl . '?action=create_location',
        'POST',
        ['name' => 'Invalid Zone', 'address' => 'Test', 'time_zone' => 'Invalid/Zone'],
        $doctor
    );
    gc_workflow_expect($invalidTimeZone, 422, false, 'Invalid location timezone');
    $invalidLocationFields = gc_workflow_request(
        $slotsUrl . '?action=create_location',
        'POST',
        ['name' => ['not', 'text'], 'address' => 'Test', 'time_zone' => 'UTC'],
        $doctor
    );
    gc_workflow_expect($invalidLocationFields, 422, false, 'Non-text location name');
    printf("PASS validation: missing CSRF=403, invalid timezone=422, non-text location field=422\n");

    $locationResponse = gc_workflow_request(
        $slotsUrl . '?action=create_location',
        'POST',
        ['name' => 'Test Location', 'address' => 'Test address', 'time_zone' => 'UTC'],
        $doctor
    );
    gc_workflow_expect($locationResponse, 201, true, 'Create practice location');
    $locationId = (int) $locationResponse['body']['data']['location']['id'];
    $newYorkLocation = gc_workflow_request(
        $slotsUrl . '?action=create_location',
        'POST',
        ['name' => 'Timezone Test Location', 'address' => 'Test address', 'time_zone' => 'America/New_York'],
        $doctor
    );
    gc_workflow_expect($newYorkLocation, 201, true, 'Create non-UTC practice location');
    $newYorkLocationId = (int) $newYorkLocation['body']['data']['location']['id'];
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
        $slotA['body']['data']['slot']['timeZone'] === 'UTC'
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
    $daylightSavingGap = gc_workflow_create_slot(
        $slotsUrl,
        $newYorkLocationId,
        '2027-03-14',
        '02:30',
        '03:30',
        $doctor
    );
    gc_workflow_expect($daylightSavingGap, 422, false, 'Nonexistent local daylight-saving time');
    $newYorkSlot = gc_workflow_create_slot(
        $slotsUrl,
        $newYorkLocationId,
        $date,
        '16:00',
        '16:30',
        $doctor
    );
    gc_workflow_expect($newYorkSlot, 201, true, 'Create slot in non-UTC location timezone');
    $expectedUtc = (new DateTimeImmutable($date . ' 16:00:00', new DateTimeZone('America/New_York')))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s\Z');
    gc_workflow_assert(
        $newYorkSlot['body']['data']['slot']['timeZone'] === 'America/New_York'
            && $newYorkSlot['body']['data']['slot']['startsAtUtc'] === $expectedUtc,
        'Slot local time was not converted using the selected location timezone.'
    );

    $doctorSlots = gc_workflow_request($slotsUrl . '?action=mine', 'GET', null, $doctor);
    gc_workflow_expect($doctorSlots, 200, true, 'Doctor own slots');
    gc_workflow_assert(count($doctorSlots['body']['data']['slots']) === 4, 'Doctor own slot list included unexpected slots.');
    $otherDoctorSlots = gc_workflow_request($slotsUrl . '?action=mine', 'GET', null, $otherDoctor);
    gc_workflow_expect($otherDoctorSlots, 200, true, 'Other doctor own slots');
    gc_workflow_assert($otherDoctorSlots['body']['data']['slots'] === [], 'A doctor could see another doctor’s slots.');
    $available = gc_workflow_request($slotsUrl . '?action=available', 'GET', null, $patient);
    gc_workflow_expect($available, 200, true, 'Patient available slots');
    gc_workflow_assert(count($available['body']['data']['slots']) === 4, 'Patient available slot list did not return all four published future slots.');
    printf("PASS slots: location=201, UTC and America/New_York conversion, DST gap/past/invalid=422, overlap=409, own-list scoped, available-list=4\n");

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
            && ($booking['body']['data']['appointment']['timeZone'] ?? '') === 'UTC',
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
    gc_workflow_assert(count($availableAfterBooking['body']['data']['slots']) === 2, 'Reserved slots remained in patient available results.');

    $created = array_fill_keys($emails, true);
    $fixtureIds = $userIds;
    $placeholders = implode(',', array_fill(0, count($fixtureIds), '?'));
    $deleteHistory = $db->prepare(
        'DELETE FROM appointment_history WHERE actor_user_id IN (' . $placeholders . ') '
        . 'OR appointment_id IN (SELECT id FROM appointments WHERE patient_user_id IN (' . $placeholders . '))'
    );
    $deleteHistory->execute(array_merge($fixtureIds, $fixtureIds));
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
