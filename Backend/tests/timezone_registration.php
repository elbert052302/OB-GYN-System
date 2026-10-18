<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/bootstrap.php';

function gc_test_request(string $url, string $method, ?array $payload, string $cookie, string $csrfToken): array
{
    $headers = [
        'Accept: application/json',
        'Cookie: ' . $cookie,
        'X-CSRF-Token: ' . $csrfToken,
    ];
    $options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 10,
    ];
    if ($payload !== null) {
        $options['header'] .= "\r\nContent-Type: application/json";
        $options['content'] = json_encode($payload, JSON_THROW_ON_ERROR);
    }

    $context = stream_context_create(['http' => $options]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false || !isset($http_response_header[0])) {
        throw new RuntimeException('The auth endpoint could not be reached.');
    }

    if (!preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
        throw new RuntimeException('The auth endpoint returned an invalid HTTP status line.');
    }

    return [
        'status' => (int) $matches[1],
        'body' => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
        'headers' => $http_response_header,
    ];
}

function gc_test_register(string $url, array $payload, string $cookie, string $csrfToken): array
{
    return gc_test_request($url . '?action=register', 'POST', $payload, $cookie, $csrfToken);
}

$url = getenv('GENTLECARE_AUTH_TEST_URL') ?: 'http://127.0.0.1/OB-GYN/Backend/api/auth.php';
$csrfResponse = gc_test_request($url . '?action=csrf', 'GET', null, '', '');
$cookie = '';
foreach ($csrfResponse['headers'] as $header) {
    if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $matches)) {
        $cookie = $matches[1];
        break;
    }
}
if ($csrfResponse['status'] !== 200 || $cookie === '' || !isset($csrfResponse['body']['csrfToken'])) {
    throw new RuntimeException('Could not initialize an authenticated test session.');
}
$csrfToken = (string) $csrfResponse['body']['csrfToken'];
$db = gc_db();

$roles = [
    [
        'role' => 'doctor',
        'profile' => [
            'licenseNumber' => 'TEST-' . bin2hex(random_bytes(12)),
            'specialization' => 'Test',
        ],
        'table' => 'doctor_profiles',
        'phone' => null,
        'time_zone' => 'Asia/Calcutta',
    ],
    [
        'role' => 'clinic',
        'profile' => [
            'clinicName' => 'Timezone Test Clinic',
            'address' => 'Test address',
        ],
        'table' => 'clinic_profiles',
        'phone' => '+1' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT),
        'time_zone' => 'UTC',
    ],
];

foreach ($roles as $test) {
    $email = 'timezone-' . $test['role'] . '-' . bin2hex(random_bytes(12)) . '@example.test';
    $payload = [
        'role' => $test['role'],
        'name' => 'Timezone Test',
        'email' => $email,
        'password' => 'NotARealPassword!2026',
        'profile' => $test['profile'],
    ];
    if ($test['phone'] !== null) {
        $payload['phone'] = $test['phone'];
    }

    $missing = gc_test_register($url, $payload, $cookie, $csrfToken);
    if ($missing['status'] !== 400 || ($missing['body']['error'] ?? '') !== 'Missing or invalid time_zone.') {
        throw new RuntimeException($test['role'] . ' registration without time_zone did not return the expected HTTP 400 error.');
    }

    $invalidPayload = $payload;
    $invalidPayload['profile']['time_zone'] = 'Invalid/NotAZone';
    $invalid = gc_test_register($url, $invalidPayload, $cookie, $csrfToken);
    if ($invalid['status'] !== 400 || ($invalid['body']['error'] ?? '') !== 'Missing or invalid time_zone.') {
        throw new RuntimeException($test['role'] . ' registration with an invalid time_zone did not return the expected HTTP 400 error.');
    }

    $validPayload = $payload;
    $validPayload['profile']['time_zone'] = $test['time_zone'];
    $valid = gc_test_register($url, $validPayload, $cookie, $csrfToken);
    if ($valid['status'] !== 201) {
        throw new RuntimeException($test['role'] . ' registration with UTC failed with HTTP ' . $valid['status'] . '.');
    }

    try {
        $storedTimeZone = $db->prepare(
            'SELECT profile.time_zone FROM ' . $test['table'] . ' AS profile '
            . 'JOIN users AS user ON user.id = profile.user_id WHERE user.email = :email'
        );
        $storedTimeZone->execute(['email' => $email]);
        if ($storedTimeZone->fetchColumn() !== $test['time_zone']) {
            throw new RuntimeException($test['role'] . ' registration did not persist its timezone.');
        }
    } finally {
        $cleanup = $db->prepare('DELETE FROM users WHERE email = :email');
        $cleanup->execute(['email' => $email]);
    }

    printf(
        "PASS %s: missing=400, invalid=400, valid=201 (%s stored; test account removed)\n",
        $test['role'],
        $test['time_zone']
    );
}
