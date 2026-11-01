<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/bootstrap.php';

function gc_auth_http(string $url, string $method, ?array $payload, array &$session, bool $includeCsrf = true): array
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
        'timeout' => 15,
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

    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $cookieMatch)) {
            if (str_ends_with($cookieMatch[1], '=')) {
                $session['cookie'] = '';
            } else {
                $session['cookie'] = $cookieMatch[1];
            }
        }
    }

    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (isset($decoded['csrfToken'])) {
        $session['csrf'] = (string) $decoded['csrfToken'];
    }
    return ['status' => (int) $matches[1], 'body' => $decoded];
}

function gc_auth_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$authUrl = getenv('GENTLECARE_AUTH_TEST_URL') ?: 'http://127.0.0.1/IPT%20PROJECT/OB-GYN/Backend/api/auth.php';
$providersUrl = str_replace('auth.php', 'providers.php', $authUrl);
$config = require dirname(__DIR__) . '/config.php';
gc_auth_assert(
    ($config['development']['expose_verification_code_on_localhost'] ?? false) === true,
    'Local auth e2e requires the local development verification fallback to be enabled.'
);

$db = gc_db();
$testEmails = [];
try {
    $session = ['cookie' => '', 'csrf' => ''];
    $csrf = gc_auth_http($authUrl . '?action=csrf', 'GET', null, $session);
    gc_auth_assert($csrf['status'] === 200 && $session['csrf'] !== '', 'CSRF token initialization failed.');
    $missingCsrf = gc_auth_http(
        $authUrl . '?action=register',
        'POST',
        ['role' => 'patient'],
        $session,
        false
    );
    gc_auth_assert($missingCsrf['status'] === 403, 'A registration without CSRF did not return HTTP 403.');

    $roles = [
        [
            'role' => 'patient',
            'profile' => [
                'dateOfBirth' => '1990-01-01',
                'emergencyContact' => 'Test contact',
                'country' => 'PH',
                'provinceCode' => '0702200000',
                'cityCode' => '0702201000',
                'barangayCode' => '0702201001',
                'street' => 'Osmeña Street',
            ],
        ],
        [
            'role' => 'doctor',
            'profile' => [
                'licenseNumber' => 'AUTH-' . bin2hex(random_bytes(12)),
                'specialization' => 'Test',
                'time_zone' => 'UTC',
            ],
        ],
        [
            'role' => 'clinic',
            'phone' => '+1' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT),
            'profile' => [
                'clinicName' => 'Auth Test Clinic',
                'provinceCode' => '0702200000',
                'cityCode' => '0702201000',
                'barangayCode' => '0702201001',
                'street' => '',
                'country' => 'PH',
                'latitude' => 14.5995,
                'longitude' => 120.9842,
            ],
        ],
    ];

    foreach ($roles as $role) {
        $email = 'phase5-auth-' . $role['role'] . '-' . bin2hex(random_bytes(12)) . '@example.test';
        $testEmails[] = $email;
        $session = ['cookie' => '', 'csrf' => ''];
        $csrf = gc_auth_http($authUrl . '?action=csrf', 'GET', null, $session);
        gc_auth_assert($csrf['status'] === 200 && $session['csrf'] !== '', 'CSRF initialization failed for ' . $role['role'] . '.');

        $payload = [
            'role' => $role['role'],
            'name' => 'Phase Five ' . ucfirst($role['role']),
            'email' => $email,
            'password' => 'TestPassword!2026',
            'profile' => $role['profile'],
        ];
        if (isset($role['phone'])) {
            $payload['phone'] = $role['phone'];
        }
        $registered = gc_auth_http($authUrl . '?action=register', 'POST', $payload, $session);
        gc_auth_assert($registered['status'] === 201, $role['role'] . ' registration failed.');
        gc_auth_assert(
            ($registered['body']['emailSent'] ?? true) === false
                && isset($registered['body']['verificationCode']),
            $role['role'] . ' registration did not use the localhost-only development mail fallback.'
        );

        $unverifiedLogin = gc_auth_http(
            $authUrl . '?action=login',
            'POST',
            ['identifier' => $email, 'password' => $payload['password']],
            $session
        );
        gc_auth_assert($unverifiedLogin['status'] === 403, $role['role'] . ' unverified login was not blocked.');

        $verified = gc_auth_http(
            $authUrl . '?action=verify',
            'POST',
            ['email' => $email, 'code' => $registered['body']['verificationCode']],
            $session
        );
        gc_auth_assert($verified['status'] === 200, $role['role'] . ' email verification failed.');
        $me = gc_auth_http($authUrl . '?action=me', 'GET', null, $session);
        gc_auth_assert($me['status'] === 200 && ($me['body']['user']['role'] ?? '') === $role['role'], $role['role'] . ' session lookup after verification failed.');
        if ($role['role'] === 'patient') {
            gc_auth_assert(
                ($me['body']['user']['address'] ?? '') === 'Osmeña Street, Cabadiangan, Alcantara, Cebu, Philippines',
                'Patient address was not returned by the session profile endpoint.'
            );
        } elseif ($role['role'] === 'clinic') {
            $mapLocation = $db->prepare(
                'SELECT clinic.latitude, clinic.longitude FROM clinic_profiles AS clinic '
                . 'JOIN users AS user ON user.id = clinic.user_id WHERE user.email = :email'
            );
            $mapLocation->execute(['email' => $email]);
            $coordinates = $mapLocation->fetch();
            gc_auth_assert(
                $coordinates !== false
                    && (float) $coordinates['latitude'] === 14.5995
                    && (float) $coordinates['longitude'] === 120.9842,
                'Clinic map coordinates were not saved during registration.'
            );
            $clinicMap = gc_auth_http($providersUrl . '?action=mine', 'GET', null, $session);
            gc_auth_assert(
                $clinicMap['status'] === 200
                    && ($clinicMap['body']['data']['location']['latitude'] ?? null) === 14.5995
                    && ($clinicMap['body']['data']['location']['longitude'] ?? null) === 120.9842,
                'Clinic map coordinates were not available to the signed-in clinic.'
            );
            $updatedMapLocation = gc_auth_http(
                $providersUrl . '?action=update_location',
                'POST',
                ['latitude' => 14.6, 'longitude' => 120.99],
                $session
            );
            gc_auth_assert(
                $updatedMapLocation['status'] === 200
                    && ($updatedMapLocation['body']['data']['location']['latitude'] ?? null) === 14.6
                    && ($updatedMapLocation['body']['data']['location']['longitude'] ?? null) === 120.99,
                'Clinic map coordinates could not be updated.'
            );
        }

        $csrfFailure = gc_auth_http($authUrl . '?action=logout', 'POST', [], $session, false);
        gc_auth_assert($csrfFailure['status'] === 403, $role['role'] . ' logout without CSRF did not return HTTP 403.');

        $logout = gc_auth_http($authUrl . '?action=logout', 'POST', [], $session);
        gc_auth_assert($logout['status'] === 200, $role['role'] . ' logout failed.');
        $afterLogout = gc_auth_http($authUrl . '?action=me', 'GET', null, $session);
        gc_auth_assert($afterLogout['status'] === 401, $role['role'] . ' session remained authenticated after logout.');

        $newCsrf = gc_auth_http($authUrl . '?action=csrf', 'GET', null, $session);
        gc_auth_assert($newCsrf['status'] === 200 && $session['csrf'] !== '', $role['role'] . ' fresh login CSRF initialization failed.');
        $login = gc_auth_http(
            $authUrl . '?action=login',
            'POST',
            ['identifier' => $email, 'password' => $payload['password']],
            $session
        );
        gc_auth_assert($login['status'] === 200, $role['role'] . ' login failed after verification.');
        $sessionCheck = gc_auth_http($authUrl . '?action=me', 'GET', null, $session);
        gc_auth_assert($sessionCheck['status'] === 200, $role['role'] . ' session check after login failed.');
        $logout = gc_auth_http($authUrl . '?action=logout', 'POST', [], $session);
        gc_auth_assert($logout['status'] === 200, $role['role'] . ' final logout failed.');
        printf(
            "PASS auth %s: register=201, unverified-login=403, verify=200, session=200, missing-CSRF=403, logout=200/session-after-logout=401, login=200; fixture removed\n",
            $role['role']
        );
    }
} finally {
    foreach ($testEmails as $email) {
        $cleanup = $db->prepare('DELETE FROM users WHERE email = :email');
        $cleanup->execute(['email' => $email]);
    }
}
