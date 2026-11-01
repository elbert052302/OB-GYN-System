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

$url = getenv('GENTLECARE_AUTH_TEST_URL') ?: 'http://127.0.0.1/IPT%20PROJECT/OB-GYN/Backend/api/auth.php';
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
        'country' => 'PH',
        'time_zone' => 'Asia/Manila',
    ],
    [
        'role' => 'clinic',
        'profile' => [
            'clinicName' => 'Timezone Test Clinic',
            'provinceCode' => '0702200000',
            'cityCode' => '0702201000',
            'barangayCode' => '0702201001',
            'street' => 'Osmeña Street',
        ],
        'table' => 'clinic_profiles',
        'phone' => '+1' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT),
        'country' => 'PH',
        'time_zone' => 'Asia/Manila',
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
    if ($missing['status'] !== 400 || ($missing['body']['error'] ?? '') !== 'Missing or invalid country or time_zone.') {
        throw new RuntimeException($test['role'] . ' registration without country or time_zone did not return the expected HTTP 400 error.');
    }

    $invalidPayload = $payload;
    $invalidPayload['profile']['country'] = 'ZZ';
    $invalid = gc_test_register($url, $invalidPayload, $cookie, $csrfToken);
    if ($invalid['status'] !== 400 || ($invalid['body']['error'] ?? '') !== 'Missing or invalid country or time_zone.') {
        throw new RuntimeException($test['role'] . ' registration with an invalid country did not return the expected HTTP 400 error.');
    }

    $validPayload = $payload;
    $validPayload['profile']['country'] = $test['country'];
    $valid = gc_test_register($url, $validPayload, $cookie, $csrfToken);
    if ($valid['status'] !== 201) {
        throw new RuntimeException($test['role'] . ' registration with UTC failed with HTTP ' . $valid['status'] . '.');
    }

    try {
        $addressColumn = $test['role'] === 'clinic' ? ', profile.address' : '';
        $storedTimeZone = $db->prepare(
            'SELECT profile.time_zone' . $addressColumn . ' FROM ' . $test['table'] . ' AS profile '
            . 'JOIN users AS user ON user.id = profile.user_id WHERE user.email = :email'
        );
        $storedTimeZone->execute(['email' => $email]);
        $stored = $storedTimeZone->fetch();
        if ($stored['time_zone'] !== $test['time_zone']) {
            throw new RuntimeException($test['role'] . ' registration did not persist its timezone.');
        }
        if ($test['role'] === 'clinic'
            && $stored['address'] !== 'Osmeña Street, Cabadiangan, Alcantara, Cebu, Philippines') {
            throw new RuntimeException('Clinic registration did not persist its verified PSGC address.');
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
