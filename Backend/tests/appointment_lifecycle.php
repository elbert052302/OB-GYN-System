<?php
declare(strict_types=1);

/**
 * Regression test: a finished appointment must never block a new booking.
 *
 * This reproduces the defect found in audit (appointment 37 held a reservation
 * forever, so patient 174 could never book again) and proves it cannot come back.
 *
 * Scenario
 *   1. Patient books slot A                    -> 201
 *   2. Patient tries slot B while A is active  -> 409 (one active appointment)
 *   3. Slot A is moved into the past
 *   4. Patient books slot B                    -> 201  <-- the regression
 *   5. Doctor marks B completed                -> 200
 *   6. Patient books slot C                    -> 201  <-- released by completion
 *
 * Usage:
 *   C:\xampp\php\php.exe Backend\tests\appointment_lifecycle.php
 *   set GENTLECARE_API_BASE=http://127.0.0.1/OB-GYN/Backend/api   (optional override)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/api/bootstrap.php';

$base = rtrim(getenv('GENTLECARE_API_BASE') ?: 'http://127.0.0.1/OB-GYN/Backend/api', '/');
$password = 'Life-' . bin2hex(random_bytes(16)) . '!9aA';
$suffix = bin2hex(random_bytes(4));
$failures = 0;
$createdUserIds = [];

function lc_request(string $url, string $method, ?array $payload, array &$session): array
{
    $headers = ['Accept: application/json'];
    if ($session['cookie'] !== '') {
        $headers[] = 'Cookie: ' . $session['cookie'];
    }
    if ($session['csrf'] !== '') {
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
    $body = @file_get_contents($url, false, stream_context_create(['http' => $options]));
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

function lc_login(string $authUrl, string $email, string $password): array
{
    $session = ['cookie' => '', 'csrf' => ''];
    $csrf = lc_request($authUrl . '?action=csrf', 'GET', null, $session);
    if ($csrf['status'] !== 200 || $session['csrf'] === '') {
        throw new RuntimeException('Could not start a session.');
    }
    $login = lc_request($authUrl . '?action=login', 'POST', [
        'identifier' => $email,
        'password' => $password,
    ], $session);
    if ($login['status'] !== 200 || !isset($login['body']['user'])) {
        throw new RuntimeException('Could not log in ' . $email . ' (HTTP ' . $login['status'] . ').');
    }
    return $session;
}

function lc_check(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        printf("PASS %s\n", $label);
        return;
    }
    $failures++;
    printf("FAIL %s\n", $label);
}

$db = gc_db();

try {
    // ---------- fixtures ----------
    $insertUser = $db->prepare(
        'INSERT INTO users (role, email, password_hash, email_verified_at) '
        . 'VALUES (:role, :email, :password_hash, UTC_TIMESTAMP())'
    );
    $hash = password_hash($password, PASSWORD_ARGON2ID);

    $insertUser->execute(['role' => 'doctor', 'email' => 'lc-doctor-' . $suffix . '@example.test', 'password_hash' => $hash]);
    $doctorId = (int) $db->lastInsertId();
    $createdUserIds[] = $doctorId;

    $insertUser->execute(['role' => 'patient', 'email' => 'lc-patient-' . $suffix . '@example.test', 'password_hash' => $hash]);
    $patientId = (int) $db->lastInsertId();
    $createdUserIds[] = $patientId;

    $db->prepare(
        'INSERT INTO doctor_profiles (user_id, full_name, phone, license_number, specialization, time_zone) '
        . 'VALUES (:user_id, :full_name, NULL, :license, :specialization, "Asia/Manila")'
    )->execute([
        'user_id' => $doctorId,
        'full_name' => 'Lifecycle Doctor ' . $suffix,
        'license' => 'LC-' . $suffix,
        'specialization' => 'Obstetrics and Gynecology',
    ]);

    $db->prepare(
        'INSERT INTO patient_profiles (user_id, full_name, phone, date_of_birth, emergency_contact, address) '
        . 'VALUES (:user_id, :full_name, NULL, :dob, :emergency, NULL)'
    )->execute([
        'user_id' => $patientId,
        'full_name' => 'Lifecycle Patient ' . $suffix,
        'dob' => '1990-01-01',
        'emergency' => 'Not provided',
    ]);

    $db->prepare(
        'INSERT INTO practice_locations (owner_user_id, name, address, time_zone, is_active, latitude, longitude) '
        . 'VALUES (:owner, :name, :address, "Asia/Manila", 1, 14.5995, 120.9842)'
    )->execute([
        'owner' => $doctorId,
        'name' => 'Lifecycle Clinic ' . $suffix,
        'address' => 'Manila, Philippines',
    ]);
    $locationId = (int) $db->lastInsertId();

    $insertSlot = $db->prepare(
        'INSERT INTO appointment_slots (doctor_user_id, location_id, starts_at_utc, ends_at_utc, created_by) '
        . 'VALUES (:doctor_id, :location_id, :starts_at, :ends_at, :created_by)'
    );
    $slotIds = [];
    foreach ([2, 4, 6] as $offsetHours) {
        $start = new DateTimeImmutable('+' . $offsetHours . ' hours', new DateTimeZone('UTC'));
        $insertSlot->execute([
            'doctor_id' => $doctorId,
            'location_id' => $locationId,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
            'created_by' => $doctorId,
        ]);
        $slotIds[] = (int) $db->lastInsertId();
    }
    [$slotA, $slotB, $slotC] = $slotIds;

    // ---------- scenario ----------
    $patient = lc_login($base . '/auth.php', 'lc-patient-' . $suffix . '@example.test', $password);
    $doctor = lc_login($base . '/auth.php', 'lc-doctor-' . $suffix . '@example.test', $password);

    $first = lc_request($base . '/appointments.php?action=book', 'POST', [
        'slot_id' => $slotA,
        'purposes' => ['Lifecycle regression'],
    ], $patient);
    lc_check($first['status'] === 201, 'patient books slot A (HTTP ' . $first['status'] . ')');
    $firstId = (int) ($first['body']['data']['appointment']['id'] ?? 0);

    $blocked = lc_request($base . '/appointments.php?action=book', 'POST', [
        'slot_id' => $slotB,
        'purposes' => ['Lifecycle regression'],
    ], $patient);
    lc_check($blocked['status'] === 409, 'second active appointment is still blocked (HTTP ' . $blocked['status'] . ')');

    // Move slot A (and its appointment) into the past, as time would.
    $db->prepare(
        'UPDATE appointment_slots SET starts_at_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 HOUR), '
        . 'ends_at_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 150 MINUTE) WHERE id = :slot_id'
    )->execute(['slot_id' => $slotA]);

    // No manual sweep here: this is exactly what used to return 409 forever.
    $second = lc_request($base . '/appointments.php?action=book', 'POST', [
        'slot_id' => $slotB,
        'purposes' => ['Lifecycle regression'],
    ], $patient);
    lc_check(
        $second['status'] === 201,
        'finished appointment no longer blocks booking (HTTP ' . $second['status'] . ')'
    );
    $secondId = (int) ($second['body']['data']['appointment']['id'] ?? 0);

    $completed = lc_request($base . '/appointments_manage.php?action=complete', 'POST', [
        'appointment_id' => $secondId,
    ], $doctor);
    lc_check($completed['status'] === 200, 'doctor marks appointment completed (HTTP ' . $completed['status'] . ')');

    $third = lc_request($base . '/appointments.php?action=book', 'POST', [
        'slot_id' => $slotC,
        'purposes' => ['Lifecycle regression'],
    ], $patient);
    lc_check($third['status'] === 201, 'completion releases the reservation (HTTP ' . $third['status'] . ')');

    $stale = (int) $db->query(
        'SELECT COUNT(*) FROM active_appointment_reservations WHERE patient_user_id = ' . $patientId
    )->fetchColumn();
    lc_check($stale === 1, 'patient holds exactly one active reservation (found ' . $stale . ')');

    $status = (string) $db->query(
        'SELECT status FROM appointments WHERE id = ' . (int) ($first['body']['data']['appointment']['id'] ?? 0)
    )->fetchColumn();
    lc_check($status === 'completed', 'the overdue appointment was closed as completed (status: ' . $status . ')');
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, 'ERROR ' . $exception->getMessage() . PHP_EOL);
} finally {
    // ---------- cleanup (FK order) ----------
    if ($createdUserIds !== []) {
        $in = implode(',', array_fill(0, count($createdUserIds), '?'));
        $db->prepare('DELETE FROM appointment_history WHERE appointment_id IN (SELECT id FROM appointments WHERE patient_user_id IN (' . $in . ') OR doctor_user_id IN (' . $in . '))')
        ->execute(array_merge($createdUserIds, $createdUserIds));
        $db->prepare('DELETE FROM notifications WHERE user_id IN (' . $in . ')')->execute($createdUserIds);
        $db->prepare('DELETE FROM appointment_purposes WHERE appointment_id IN (SELECT id FROM appointments WHERE patient_user_id IN (' . $in . '))')
        ->execute($createdUserIds);
        $db->prepare('DELETE FROM active_appointment_reservations WHERE patient_user_id IN (' . $in . ')')->execute($createdUserIds);
        $db->prepare('DELETE FROM appointments WHERE patient_user_id IN (' . $in . ')')->execute($createdUserIds);
        $db->prepare('DELETE FROM appointment_slots WHERE doctor_user_id IN (' . $in . ')')->execute($createdUserIds);
        $db->prepare('DELETE FROM practice_locations WHERE owner_user_id IN (' . $in . ')')->execute($createdUserIds);
        $db->prepare('DELETE FROM doctor_profiles WHERE user_id IN (' . $in . ')')->execute($createdUserIds);
        $db->prepare('DELETE FROM patient_profiles WHERE user_id IN (' . $in . ')')->execute($createdUserIds);
        $db->prepare('DELETE FROM users WHERE id IN (' . $in . ')')->execute($createdUserIds);
    }
}

echo $failures === 0
    ? "RESULT: all lifecycle checks passed\n"
    : "RESULT: {$failures} lifecycle check(s) failed\n";
exit($failures === 0 ? 0 : 1);
