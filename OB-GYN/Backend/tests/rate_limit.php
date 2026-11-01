<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/api/rate_limit.php';

function gc_rate_limit_test_request(
    string $url,
    string $method,
    ?array $payload,
    string $cookie,
    string $csrfToken
): array {
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
    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    return [
        'status' => (int) $matches[1],
        'body' => $decoded,
        'headers' => $http_response_header,
    ];
}

function gc_rate_limit_test_clear(string $bucket, string $key): void
{
    $statement = gc_db()->prepare(
        'DELETE FROM auth_rate_limits WHERE bucket = :bucket AND limit_key = :limit_key'
    );
    $statement->execute(['bucket' => $bucket, 'limit_key' => $key]);
}

$base = rtrim(getenv('GENTLECARE_AUTH_TEST_URL') ?: 'http://127.0.0.1/IPT%20PROJECT/OB-GYN/Backend/api/auth.php', '?');
$testIp = getenv('GENTLECARE_RATE_LIMIT_TEST_IP') ?: '127.0.0.1';
$email = 'rate-limit-' . bin2hex(random_bytes(12)) . '@example.test';
$successEmail = 'rate-limit-success-' . bin2hex(random_bytes(12)) . '@example.test';
$loginKey = gc_rate_limit_key($email, $testIp);
$successLoginKey = gc_rate_limit_key($successEmail, $testIp);
$registerKey = gc_rate_limit_key($testIp);
gc_rate_limit_test_clear('login', $loginKey);
gc_rate_limit_test_clear('login', $successLoginKey);
gc_rate_limit_test_clear('register', $registerKey);
$insertUser = gc_db()->prepare(
    'INSERT INTO users (role, email, password_hash, email_verified_at) '
    . 'VALUES ("patient", :email, :password_hash, UTC_TIMESTAMP())'
);
$insertUser->execute([
    'email' => $successEmail,
    'password_hash' => password_hash('RateLimitPassword!2026', PASSWORD_ARGON2ID),
]);
$patientProfile = gc_db()->prepare(
    'INSERT INTO patient_profiles (user_id, full_name, phone, date_of_birth, emergency_contact, address) '
    . 'VALUES (:user_id, :full_name, :phone, :date_of_birth, :emergency_contact, :address) '
    . 'ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone)'
);
$patientProfile->execute([
    'user_id' => (int) gc_db()->lastInsertId(),
    'full_name' => 'Rate Limit Test',
    'phone' => null,
    'date_of_birth' => '1990-01-01',
    'emergency_contact' => 'Rate limit contact',
    'address' => 'Test address',
]);

try {
    $csrfResponse = gc_rate_limit_test_request($base . '?action=csrf', 'GET', null, '', '');
    $cookie = '';
    foreach ($csrfResponse['headers'] as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $matches)) {
            $cookie = $matches[1];
            break;
        }
    }
    if ($csrfResponse['status'] !== 200 || $cookie === '' || !isset($csrfResponse['body']['csrfToken'])) {
        throw new RuntimeException('Could not initialize the rate-limit test session.');
    }
    $csrfToken = (string) $csrfResponse['body']['csrfToken'];

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $failedLogin = gc_rate_limit_test_request(
            $base . '?action=login',
            'POST',
            ['identifier' => $successEmail, 'password' => 'WrongPassword!2026'],
            $cookie,
            $csrfToken
        );
        if ($failedLogin['status'] !== 401) {
            throw new RuntimeException('A wrong password was not rejected before the successful login.');
        }
    }
    $successfulLogin = gc_rate_limit_test_request(
        $base . '?action=login',
        'POST',
        ['identifier' => $successEmail, 'password' => 'RateLimitPassword!2026'],
        $cookie,
        $csrfToken
    );
    if ($successfulLogin['status'] !== 200) {
        throw new RuntimeException('A correct password was blocked by the login rate limit.');
    }
    foreach ($successfulLogin['headers'] as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $matches)) {
            $cookie = $matches[1];
            break;
        }
    }
    $csrfToken = (string) ($successfulLogin['body']['csrfToken'] ?? '');
    if ($cookie === '' || $csrfToken === '') {
        throw new RuntimeException('Successful login did not return a new session and CSRF token.');
    }
    $remainingAttempts = gc_db()->prepare(
        'SELECT COUNT(*) FROM auth_rate_limits WHERE bucket = :bucket AND limit_key = :limit_key'
    );
    $remainingAttempts->execute(['bucket' => 'login', 'limit_key' => $successLoginKey]);
    if ((int) $remainingAttempts->fetchColumn() !== 0) {
        throw new RuntimeException('A successful login did not clear its failed-password limit.');
    }

    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $login = gc_rate_limit_test_request(
            $base . '?action=login',
            'POST',
            ['identifier' => $email, 'password' => 'WrongPassword!2026'],
            $cookie,
            $csrfToken
        );
        $expectedStatus = $attempt <= 5 ? 401 : 429;
        if ($login['status'] !== $expectedStatus) {
            throw new RuntimeException('Login rate limit failed on attempt ' . $attempt . '.');
        }
    }

    for ($attempt = 1; $attempt <= 31; $attempt++) {
        $register = gc_rate_limit_test_request(
            $base . '?action=register',
            'POST',
            [],
            $cookie,
            $csrfToken
        );
        $expectedStatus = $attempt <= 30 ? 422 : 429;
        if ($register['status'] !== $expectedStatus) {
            throw new RuntimeException('Registration rate limit failed on attempt ' . $attempt . '.');
        }
    }

    printf("PASS rate limits: successful login clears failures; login=5 per 15 minutes; registration=30 per hour\n");
} finally {
    gc_rate_limit_test_clear('login', $loginKey);
    gc_rate_limit_test_clear('login', $successLoginKey);
    gc_rate_limit_test_clear('register', $registerKey);
    $deleteUser = gc_db()->prepare('DELETE FROM users WHERE email = :email');
    $deleteUser->execute(['email' => $successEmail]);
}
