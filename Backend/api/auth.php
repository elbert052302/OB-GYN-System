<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
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
            $profile = $db->prepare('SELECT date_of_birth, emergency_contact FROM patient_profiles WHERE user_id = :user_id');
            $profile->execute(['user_id' => $user['id']]);
            $details = $profile->fetch();
            if ($details) {
                $user['dateOfBirth'] = $details['date_of_birth'];
                $user['emergencyContact'] = $details['emergency_contact'];
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
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
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
        $timeZone = $profile['time_zone'] ?? null;
        if (in_array($role, ['doctor', 'clinic'], true)
            && (!is_string($timeZone) || !in_array($timeZone, DateTimeZone::listIdentifiers(), true))) {
            gc_respond(400, ['error' => 'Missing or invalid time_zone.']);
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
        } elseif ($role === 'doctor') {
            $license = trim((string) ($profile['licenseNumber'] ?? ''));
            $specialty = trim((string) ($profile['specialization'] ?? ''));
            if ($license === '' || mb_strlen($license) > 80 || $specialty === '' || mb_strlen($specialty) > 120) {
                gc_respond(422, ['error' => 'Enter a valid license number and specialization.']);
            }
        } else {
            $clinicName = trim((string) ($profile['clinicName'] ?? ''));
            $address = trim((string) ($profile['address'] ?? ''));
            if ($phone === null || $clinicName === '' || mb_strlen($clinicName) > 160 || $address === '' || mb_strlen($address) > 255) {
                gc_respond(422, ['error' => 'Enter a valid clinic name, phone number, and address.']);
            }
        }

        $db = gc_db();
        $duplicate = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $duplicate->execute(['email' => $email]);
        if ($duplicate->fetch()) {
            gc_respond(409, ['error' => 'An account with this email already exists.']);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db->beginTransaction();
        try {
            $insertUser = $db->prepare(
                'INSERT INTO users (role, full_name, email, phone, password_hash, verification_code_hash, verification_expires_at, verification_attempts) '
                . 'VALUES (:role, :name, :email, :phone, :password_hash, :code_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), 0)'
            );
            $insertUser->execute([
                'role' => $role,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
                'code_hash' => password_hash($code, PASSWORD_ARGON2ID),
            ]);
            $userId = (int) $db->lastInsertId();

            if ($role === 'patient') {
                $statement = $db->prepare('INSERT INTO patient_profiles (user_id, date_of_birth, emergency_contact) VALUES (:user_id, :dob, :emergency)');
                $statement->execute(['user_id' => $userId, 'dob' => $dob, 'emergency' => $emergency]);
            } elseif ($role === 'doctor') {
                $statement = $db->prepare('INSERT INTO doctor_profiles (user_id, license_number, specialization, time_zone) VALUES (:user_id, :license, :specialization, :time_zone)');
                $statement->execute([
                    'user_id' => $userId,
                    'license' => $license,
                    'specialization' => $specialty,
                    'time_zone' => $timeZone,
                ]);
            } else {
                $statement = $db->prepare('INSERT INTO clinic_profiles (user_id, clinic_name, address, time_zone) VALUES (:user_id, :clinic_name, :address, :time_zone)');
                $statement->execute([
                    'user_id' => $userId,
                    'clinic_name' => $clinicName,
                    'address' => $address,
                    'time_zone' => $timeZone,
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
            'SELECT id, role, full_name, email, email_verified_at, verification_code_hash, verification_expires_at, verification_attempts '
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
        if ($identifier === '' || strlen($identifier) > 254 || $password === '' || strlen($password) > 128) {
            gc_respond(422, ['error' => 'Enter your email or phone number and password.']);
        }

        $db = gc_db();
        $email = strtolower($identifier);
        $phone = gc_normalize_phone($identifier) ?? $identifier;
        $statement = $db->prepare(
            'SELECT id, role, full_name, email, password_hash, email_verified_at FROM users '
            . 'WHERE email = :email OR phone = :phone LIMIT 1'
        );
        $statement->execute(['email' => $email, 'phone' => $phone]);
        $user = $statement->fetch();
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            gc_respond(401, ['error' => 'The email/phone or password is incorrect.']);
        }
        if ($user['email_verified_at'] === null) {
            gc_respond(403, ['error' => 'Verify your email address before logging in.']);
        }
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID)) {
            $updateHash = $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $updateHash->execute(['hash' => password_hash($password, PASSWORD_ARGON2ID), 'id' => $user['id']]);
        }
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
