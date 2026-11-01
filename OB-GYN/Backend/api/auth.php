<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/geography_data.php';
require_once __DIR__ . '/rate_limit.php';
gc_start_session();

$action = (string) ($_GET['action'] ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($action === 'csrf' && $method === 'GET') {
    gc_respond(200, ['csrfToken' => gc_csrf_token()]);
}

if ($action === 'me' && $method === 'GET') {
    if (!isset($_SESSION['user'])) {
        gc_respond(401, ['error' => 'Please log in to continue.']);
    }
    try {
        $user = $_SESSION['user'];
        $db = gc_db();
        if ($user['role'] === 'patient') {
            $profile = $db->prepare('SELECT date_of_birth, emergency_contact, address FROM patient_profiles WHERE user_id = :user_id');
            $profile->execute(['user_id' => $user['id']]);
            $details = $profile->fetch();
            if ($details) {
                $user['dateOfBirth'] = $details['date_of_birth'];
                $user['emergencyContact'] = $details['emergency_contact'];
                $user['address'] = $details['address'];
            }
        } elseif ($user['role'] === 'clinic') {
            $profile = $db->prepare('SELECT clinic_name FROM clinic_profiles WHERE user_id = :user_id');
            $profile->execute(['user_id' => $user['id']]);
            $details = $profile->fetch();
            if ($details) {
                $user['clinicName'] = $details['clinic_name'];
            }
        }
        gc_respond(200, ['user' => $user]);
    } catch (Throwable $exception) {
        error_log('GentleCare session lookup failed: ' . $exception->getMessage());
        gc_respond(503, ['error' => 'The service is temporarily unavailable. Please try again later.']);
    }
}

if (!in_array($action, ['register', 'verify', 'login', 'logout'], true) || $method !== 'POST') {
    header('Allow: GET, POST');
    gc_respond(405, ['error' => 'Unsupported request.']);
}

$input = gc_json_input();
gc_require_csrf($input);

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        $cookiePath = $params['path'] ?? '/';
        if (preg_match('/[\s,;\\t\\r\\n\\013\\014]/', $cookiePath) === 1) {
            $cookiePath = '/';
        }
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookiePath,
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }
    session_destroy();
    gc_respond(200, ['message' => 'You are signed out.']);
}

try {
    if ($action === 'register') {
        $registerLimitKey = gc_rate_limit_key((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        gc_rate_limit_check('register', $registerLimitKey, 30, 60);
        gc_rate_limit_hit('register', $registerLimitKey, 60);

        $role = (string) ($input['role'] ?? '');
        $name = trim((string) ($input['name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $phoneInput = (string) ($input['phone'] ?? '');
        $phone = gc_normalize_phone($phoneInput);

        if (!in_array($role, ['patient', 'doctor', 'clinic'], true)) {
            gc_respond(422, ['error' => 'Choose a valid account type.']);
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            gc_respond(422, ['error' => 'Enter a name between 2 and 120 characters.']);
        }
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            gc_respond(422, ['error' => 'Enter a valid email address.']);
        }
        if (strlen($password) < 12 || strlen($password) > 128) {
            gc_respond(422, ['error' => 'Use a password between 12 and 128 characters.']);
        }
        if ($phoneInput !== '' && $phone === null) {
            gc_respond(422, ['error' => 'Enter a valid phone number.']);
        }

        $profile = $input['profile'] ?? [];
        if (!is_array($profile)) {
            gc_respond(422, ['error' => 'Registration details are invalid.']);
        }
        $timeZone = array_key_exists('country', $profile)
            ? gc_default_time_zone_for_country($profile['country'])
            : ($profile['time_zone'] ?? null);
        if (in_array($role, ['doctor', 'clinic'], true)
            && (!is_string($timeZone)
                || !in_array($timeZone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true))) {
            gc_respond(400, ['error' => 'Missing or invalid country or time_zone.']);
        }
        if ($role === 'patient') {
            $dob = (string) ($profile['dateOfBirth'] ?? '');
            $emergency = trim((string) ($profile['emergencyContact'] ?? ''));
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
            if (!$date || $date->format('Y-m-d') !== $dob || $date >= new DateTimeImmutable('today')) {
                gc_respond(422, ['error' => 'Enter a valid date of birth in the past.']);
            }
            if ($emergency === '' || mb_strlen($emergency) > 160) {
                gc_respond(422, ['error' => 'Enter an emergency contact (up to 160 characters).']);
            }
            $address = gc_philippine_address(
                $profile['country'] ?? null,
                $profile['provinceCode'] ?? null,
                $profile['cityCode'] ?? null,
                $profile['barangayCode'] ?? null,
                $profile['street'] ?? ''
            );
            if ($address === null) {
                gc_respond(422, ['error' => 'Choose a valid Philippine province or region, city or municipality, and barangay.']);
            }
        } elseif ($role === 'doctor') {
            $license = trim((string) ($profile['licenseNumber'] ?? ''));
            $specialty = trim((string) ($profile['specialization'] ?? ''));
            if ($license === '' || mb_strlen($license) > 80 || $specialty === '' || mb_strlen($specialty) > 120) {
                gc_respond(422, ['error' => 'Enter a valid license number and specialization.']);
            }
        } else {
            $clinicName = trim((string) ($profile['clinicName'] ?? ''));
            if ($phone === null || $clinicName === '' || mb_strlen($clinicName) > 160) {
                gc_respond(422, ['error' => 'Enter a valid clinic name and phone number.']);
            }
            $clinicCoordinates = gc_coordinates($profile['latitude'] ?? null, $profile['longitude'] ?? null);
            if ($clinicCoordinates === null) {
                gc_respond(422, ['error' => 'Enter both valid map coordinates or leave both blank.']);
            }
            $address = gc_philippine_address(
                $profile['country'] ?? null,
                $profile['provinceCode'] ?? null,
                $profile['cityCode'] ?? null,
                $profile['barangayCode'] ?? null,
                $profile['street'] ?? ''
            );
            if ($address === null) {
                gc_respond(422, ['error' => 'Choose a valid Philippine province, city or municipality, and barangay.']);
            }
        }

        $db = gc_db();
        $expiredAccounts = $db->prepare(
            'DELETE FROM users WHERE email_verified_at IS NULL '
            . 'AND verification_expires_at < UTC_TIMESTAMP()'
        );
        $expiredAccounts->execute();

        $duplicate = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $duplicate->execute(['email' => $email]);
        if ($duplicate->fetch()) {
            gc_respond(409, ['error' => 'An account with this email already exists.']);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db->beginTransaction();
        try {
            $insertUser = $db->prepare(
                  'INSERT INTO users (role, email, password_hash, verification_code_hash, verification_expires_at, verification_attempts) '
                . 'VALUES (:role, :email, :password_hash, :code_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), 0)'
            );
            $insertUser->execute([
                'role' => $role,
                  'email' => $email,
                  'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
                  'code_hash' => password_hash($code, PASSWORD_ARGON2ID),
            ]);
            $userId = (int) $db->lastInsertId();

            if ($role === 'patient') {
                  $statement = $db->prepare(
                      'INSERT INTO patient_profiles (user_id, full_name, phone, date_of_birth, emergency_contact, address) '
                      . 'VALUES (:user_id, :full_name, :phone, :dob, :emergency, :address)'
                  );
                  $statement->execute([
                      'user_id' => $userId,
                      'full_name' => $name,
                      'phone' => $phone,
                      'dob' => $dob,
                      'emergency' => $emergency,
                      'address' => $address,
                  ]);
            } elseif ($role === 'doctor') {
                  $statement = $db->prepare(
                      'INSERT INTO doctor_profiles (user_id, full_name, phone, license_number, specialization, time_zone) '
                      . 'VALUES (:user_id, :full_name, :phone, :license, :specialization, :time_zone)'
                  );
                  $statement->execute([
                      'user_id' => $userId,
                      'full_name' => $name,
                      'phone' => $phone,
                      'license' => $license,
                      'specialization' => $specialty,
                      'time_zone' => $timeZone,
                  ]);
            } else {
                  $statement = $db->prepare(
                      'INSERT INTO clinic_profiles (user_id, clinic_name, phone, address, time_zone, latitude, longitude) '
                      . 'VALUES (:user_id, :clinic_name, :phone, :address, :time_zone, :latitude, :longitude)'
                  );
                  $statement->execute([
                      'user_id' => $userId,
                      'clinic_name' => $clinicName,
                      'phone' => $phone,
                      'address' => $address,
                      'time_zone' => $timeZone,
                      'latitude' => $clinicCoordinates['latitude'],
                      'longitude' => $clinicCoordinates['longitude'],
                  ]);
            }

            $emailSent = false;
            try {
                $emailSent = gc_send_verification_code($email, $name, $code);
            } catch (Throwable $mailException) {
                error_log('GentleCare verification email delivery failed.');
            }
            $localCodeEnabled = !$emailSent && gc_can_expose_local_verification_code();
            if (!$emailSent && !$localCodeEnabled) {
                throw new RuntimeException('Verification email could not be sent.');
            }
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }

        if (!$emailSent) {
            error_log('GentleCare verification email delivery unavailable for a locally registered account.');
        }
        $response = [
            'message' => $emailSent
                ? 'Check your email for a verification code.'
                : 'Email delivery is unavailable. Use the local development code to verify this account.',
            'emailSent' => $emailSent,
        ];
        if ($localCodeEnabled) {
            $response['verificationCode'] = $code;
        }
        gc_respond(201, $response);
    }

    if ($action === 'verify') {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $code = trim((string) ($input['code'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || !preg_match('/^\d{6}$/', $code)) {
            gc_respond(422, ['error' => 'Enter the email address and six-digit code from the message.']);
        }

        $db = gc_db();
        $db->beginTransaction();
        $statement = $db->prepare(
            'SELECT id, role, email, email_verified_at, verification_code_hash, verification_expires_at, verification_attempts '
            . 'FROM users WHERE email = :email FOR UPDATE'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        if (!$user || $user['email_verified_at'] !== null || $user['verification_code_hash'] === null
            || (int) $user['verification_attempts'] >= 5
            || strtotime((string) $user['verification_expires_at'] . ' UTC') < time()) {
            $db->commit();
            gc_respond(422, ['error' => 'This verification code is invalid or expired. Request a new registration code.']);
        }

        $attempts = (int) $user['verification_attempts'] + 1;
        $updateAttempts = $db->prepare('UPDATE users SET verification_attempts = :attempts WHERE id = :id');
        $updateAttempts->execute(['attempts' => $attempts, 'id' => $user['id']]);
        if (!password_verify($code, (string) $user['verification_code_hash'])) {
            $db->commit();
            gc_respond(422, ['error' => $attempts >= 5 ? 'Too many attempts. Request a new registration code.' : 'The verification code is incorrect.']);
        }

        $verifyUser = $db->prepare(
            'UPDATE users SET email_verified_at = UTC_TIMESTAMP(), verification_code_hash = NULL, verification_expires_at = NULL, verification_attempts = 0 WHERE id = :id'
        );
        $verifyUser->execute(['id' => $user['id']]);
        $db->commit();
        gc_set_authenticated_session($user);
        gc_respond(200, ['user' => gc_public_user($user), 'csrfToken' => gc_csrf_token()]);
    }

    if ($action === 'login') {
        $identifier = trim((string) ($input['identifier'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $email = strtolower($identifier);
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $password === '' || strlen($password) > 128) {
            gc_respond(422, ['error' => 'Enter your email address and password.']);
        }

        $loginLimitKey = gc_rate_limit_key(
            $email,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '')
        );
        gc_rate_limit_check('login', $loginLimitKey, 5, 15);

        $db = gc_db();
        $statement = $db->prepare(
            'SELECT id, role, email, password_hash, email_verified_at FROM users '
            . 'WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            gc_rate_limit_hit('login', $loginLimitKey, 15);
            gc_respond(401, ['error' => 'The email or password is incorrect.']);
        }
        if ($user['email_verified_at'] === null) {
            gc_respond(403, ['error' => 'Verify your email address before logging in.']);
        }
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID)) {
            $updateHash = $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $updateHash->execute(['hash' => password_hash($password, PASSWORD_ARGON2ID), 'id' => $user['id']]);
        }
        gc_rate_limit_clear('login', $loginLimitKey);
        gc_set_authenticated_session($user);
        gc_respond(200, ['user' => gc_public_user($user), 'csrfToken' => gc_csrf_token()]);
    }

} catch (Throwable $exception) {
    error_log('GentleCare auth request failed: ' . $exception->getMessage());
    if ($exception instanceof RuntimeException && $exception->getMessage() === 'Verification email could not be sent.') {
        gc_respond(503, ['error' => 'Email verification is not configured. The account was not created.']);
    }
    if ($exception instanceof PDOException && $exception->getCode() === '23000') {
        gc_respond(409, ['error' => 'The account details conflict with an existing account.']);
    }
    gc_respond(503, ['error' => 'The service is temporarily unavailable. Please try again later.']);
}

gc_respond(400, ['error' => 'Unsupported request.']);
