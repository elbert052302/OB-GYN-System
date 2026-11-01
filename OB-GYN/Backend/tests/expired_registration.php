<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/bootstrap.php';

function gc_expired_registration_request(string $url, string $method, ?array $payload, string $cookie, string $csrfToken): array
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
    if ($body === false || !isset($http_response_header[0])
        || !preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
        throw new RuntimeException('The auth endpoint could not be reached.');
    }
    return [
        'status' => (int) $matches[1],
        'body' => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
        'headers' => $http_response_header,
    ];
}

$url = getenv('GENTLECARE_AUTH_TEST_URL') ?: 'http://127.0.0.1/IPT%20PROJECT/OB-GYN/Backend/api/auth.php';
$email = 'expired-registration-' . bin2hex(random_bytes(12)) . '@example.test';
$phone = '+639' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$license = 'EXPIRED-' . bin2hex(random_bytes(12));
$db = gc_db();
$insertUser = $db->prepare(
    'INSERT INTO users (role, email, password_hash, verification_code_hash, verification_expires_at) '
    . 'VALUES ("doctor", :email, :password_hash, :code_hash, '
    . 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE))'
);
$insertUser->execute([
    'email' => $email,
    'password_hash' => password_hash('ExpiredPassword!2026', PASSWORD_ARGON2ID),
    'code_hash' => password_hash('123456', PASSWORD_ARGON2ID),
]);
$expiredUserId = (int) $db->lastInsertId();
$insertProfile = $db->prepare(
    'INSERT INTO doctor_profiles (user_id, full_name, phone, license_number, specialization, time_zone) '
    . 'VALUES (:user_id, :full_name, :phone, :license_number, :specialization, :time_zone)'
);
$insertProfile->execute([
    'user_id' => $expiredUserId,
    'full_name' => 'Expired Test Doctor',
    'phone' => $phone,
    'license_number' => $license,
    'specialization' => 'Test',
    'time_zone' => 'Asia/Manila',
]);

try {
    $csrf = gc_expired_registration_request($url . '?action=csrf', 'GET', null, '', '');
    $cookie = '';
    foreach ($csrf['headers'] as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $matches)) {
            $cookie = $matches[1];
            break;
        }
    }
    if ($csrf['status'] !== 200 || $cookie === '' || !isset($csrf['body']['csrfToken'])) {
        throw new RuntimeException('Could not initialize the registration test session.');
    }

    $registration = gc_expired_registration_request(
        $url . '?action=register',
        'POST',
        [
            'role' => 'doctor',
            'name' => 'Replacement Test Doctor',
            'email' => $email,
            'phone' => $phone,
            'password' => 'ReplacementPassword!2026',
            'profile' => [
                'licenseNumber' => $license,
                'specialization' => 'Test',
                'country' => 'PH',
            ],
        ],
        $cookie,
        (string) $csrf['body']['csrfToken']
    );
    if ($registration['status'] !== 201) {
        throw new RuntimeException('Expired unverified account did not release its email, phone, and license number.');
    }

    $replacement = $db->prepare(
        'SELECT user.id, profile.phone, profile.license_number FROM users AS user '
        . 'JOIN doctor_profiles AS profile ON profile.user_id = user.id WHERE user.email = :email'
    );
    $replacement->execute(['email' => $email]);
    $account = $replacement->fetch();
    if (!$account || $account['phone'] !== $phone || $account['license_number'] !== $license
        || (int) $account['id'] === $expiredUserId) {
        throw new RuntimeException('Registration did not replace the expired account with its original identifiers.');
    }

    printf("PASS expired registration: email, phone, and license released for re-registration; fixture removed\n");
} finally {
    $cleanup = $db->prepare('DELETE FROM users WHERE email = :email');
    $cleanup->execute(['email' => $email]);
}
