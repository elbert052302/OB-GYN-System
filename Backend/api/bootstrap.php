<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
    ini_set('session.cookie_secure', '1');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

// Only send HSTS when the request is actually over HTTPS
if (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

function gc_respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function gc_api_respond(int $status, ?string $error, mixed $data = null): never
{
    http_response_code($status);
    echo json_encode([
        'ok' => $error === null,
        'error' => $error === null ? null : ['message' => $error],
        'data' => $data,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function gc_api_input(): array
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 16384) {
        gc_api_respond(413, 'Request body is too large.');
    }

    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        gc_api_respond(415, 'Content-Type must be application/json.');
    }

    $decoded = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($decoded)) {
        gc_api_respond(400, 'Request body must be a JSON object.');
    }
    return $decoded;
}

function gc_api_require_user(string $role): array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || !isset($user['id'], $user['role'])) {
        gc_api_respond(401, 'Please log in to continue.');
    }
    if ($user['role'] !== $role) {
        gc_api_respond(403, 'Your account cannot perform this action.');
    }
    return $user;
}

function gc_api_require_csrf(): void
{
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
    if ($provided === '' || $sessionToken === '' || !hash_equals($sessionToken, $provided)) {
        gc_api_respond(403, 'Your session expired. Refresh the page and try again.');
    }
}

function gc_valid_time_zone(mixed $timeZone): bool
{
    return is_string($timeZone)
        && in_array($timeZone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
}


function gc_country_codes(): array
{
    return explode(' ', 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW');
}

function gc_country_time_zones(string $countryCode): array
{
    if (!in_array($countryCode, gc_country_codes(), true)) {
        return [];
    }
    return DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $countryCode);
}

function gc_default_time_zone_for_country(mixed $countryCode): ?string
{
    if (!is_string($countryCode) || !preg_match('/^[A-Z]{2}$/', $countryCode)) {
        return null;
    }
    if (!in_array($countryCode, gc_country_codes(), true)) {
        return null;
    }

    $timeZones = gc_country_time_zones($countryCode);
    if ($timeZones === []) {
        return 'UTC';
    }

    $defaults = [
        'AR' => 'America/Argentina/Buenos_Aires',
        'AU' => 'Australia/Sydney',
        'BR' => 'America/Sao_Paulo',
        'CA' => 'America/Toronto',
        'CD' => 'Africa/Kinshasa',
        'CL' => 'America/Santiago',
        'CN' => 'Asia/Shanghai',
        'EC' => 'America/Guayaquil',
        'ES' => 'Europe/Madrid',
        'GL' => 'America/Nuuk',
        'ID' => 'Asia/Jakarta',
        'KZ' => 'Asia/Almaty',
        'MX' => 'America/Mexico_City',
        'NZ' => 'Pacific/Auckland',
        'PT' => 'Europe/Lisbon',
        'RU' => 'Europe/Moscow',
        'US' => 'America/New_York',
    ];
    $default = $defaults[$countryCode] ?? $timeZones[0];

    return in_array($default, $timeZones, true) ? $default : $timeZones[0];
}

function gc_coordinates(mixed $latitude, mixed $longitude): ?array
{
    $latitudeEmpty = $latitude === null || $latitude === '';
    $longitudeEmpty = $longitude === null || $longitude === '';
    if ($latitudeEmpty && $longitudeEmpty) {
        return ['latitude' => null, 'longitude' => null];
    }
    if ($latitudeEmpty || $longitudeEmpty || !is_numeric($latitude) || !is_numeric($longitude)) {
        return null;
    }

    $latitude = (float) $latitude;
    $longitude = (float) $longitude;
    if (!is_finite($latitude) || !is_finite($longitude)
        || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        return null;
    }

    return ['latitude' => $latitude, 'longitude' => $longitude];
}

function gc_local_datetime_to_utc(string $date, string $time, DateTimeZone $timeZone): ?DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || !preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $time)) {
        return null;
    }

    $localTime = strlen($time) === 5 ? $time . ':00' : $time;
    $wallClock = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $localTime, new DateTimeZone('UTC'));
    if (!$wallClock || $wallClock->format('Y-m-d H:i:s') !== $date . ' ' . $localTime) {
        return null;
    }

    $wallTimestamp = $wallClock->getTimestamp();
    $transitions = $timeZone->getTransitions($wallTimestamp - 172800, $wallTimestamp + 172800);
    $offsets = [];
    if (is_array($transitions)) {
        foreach ($transitions as $transition) {
            $offsets[(int) $transition['offset']] = true;
        }
    } else {
        $offsets[$timeZone->getOffset(new DateTimeImmutable('@' . $wallTimestamp))] = true;
    }

    $matches = [];
    foreach (array_keys($offsets) as $offset) {
        $candidate = (new DateTimeImmutable('@' . ($wallTimestamp - $offset)))->setTimezone($timeZone);
        if ($candidate->format('Y-m-d H:i:s') === $date . ' ' . $localTime) {
            $matches[$candidate->getTimestamp()] = $candidate->setTimezone(new DateTimeZone('UTC'));
        }
    }
    if (count($matches) !== 1) {
        return null;
    }

    return reset($matches);
}

function gc_start_session(): void
{
    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $projectPath = dirname(dirname(dirname($scriptPath)));
    $cookiePath = $projectPath === '.' || $projectPath === DIRECTORY_SEPARATOR ? '/' : $projectPath . '/';
    if (preg_match('/[\s,;\\t\\r\\n\\013\\014]/', $cookiePath) === 1) {
        $cookiePath = '/';
    }
    $https = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';

    session_name('gentlecare_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    // Session timeouts: 30 minutes idle, 8 hours absolute
    $idleLimit = 30 * 60;
    $absoluteLimit = 8 * 60 * 60;
    $now = time();

    if (isset($_SESSION['user'])) {
        $created = (int) ($_SESSION['created_at'] ?? $now);
        $last = (int) ($_SESSION['last_activity'] ?? $now);

        if (($now - $last) > $idleLimit || ($now - $created) > $absoluteLimit) {
            // Expired: wipe the session but keep it usable so login still works
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $_SESSION['created_at'] = $created;
            $_SESSION['last_activity'] = $now;
        }
    }
}

function gc_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require dirname(__DIR__) . '/config.php';
    $database = $config['database'] ?? null;
    if (!is_array($database)
        || !isset($database['host'], $database['port'], $database['name'], $database['username'], $database['charset'])
        || !array_key_exists('password', $database)) {
        throw new RuntimeException('Database configuration is invalid.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $database['host'],
        $database['port'],
        $database['name'],
        $database['charset']
    );
    $pdo = new PDO($dsn, $database['username'], $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function gc_can_expose_local_verification_code(): bool
{
    $config = require dirname(__DIR__) . '/config.php';
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return ($config['development']['expose_verification_code_on_localhost'] ?? false) === true
        && PHP_SAPI !== 'cli'
        && in_array($remoteAddress, ['127.0.0.1', '::1'], true);
}

function gc_json_input(): array
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 16384) {
        gc_respond(413, ['error' => 'Request body is too large.']);
    }

    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        gc_respond(415, ['error' => 'Content-Type must be application/json.']);
    }

    $decoded = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($decoded)) {
        gc_respond(400, ['error' => 'Request body must be a JSON object.']);
    }
    return $decoded;
}

function gc_require_csrf(array $input): void
{
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
    if ($provided === '' || $sessionToken === '' || !hash_equals($sessionToken, $provided)) {
        gc_respond(403, ['error' => 'Your session expired. Refresh the page and try again.']);
    }
}

function gc_csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function gc_normalize_phone(string $phone): ?string
{
    $phone = trim($phone);
    if ($phone === '') {
        return null;
    }
    if (!preg_match('/^\+?[0-9 ()-]{7,24}$/', $phone)) {
        return null;
    }
    $digits = preg_replace('/\D/', '', $phone);
    if (!is_string($digits) || strlen($digits) < 8 || strlen($digits) > 15) {
        return null;
    }
    return (str_starts_with($phone, '+') ? '+' : '') . $digits;
}

final class GcEmailDeliveryException extends RuntimeException
{
}

function gc_send_verification_code(string $email, string $name, string $code): bool
{
    $config = require __DIR__ . '/../config.php';
    $brevo = $config['brevo'] ?? [];
    $apiKey = trim((string) ($brevo['api_key'] ?? ''));
    $senderEmail = trim((string) ($brevo['sender_email'] ?? ''));
    $senderName = trim((string) ($brevo['sender_name'] ?? 'GentleCare'));

    if ($apiKey === '' || filter_var($senderEmail, FILTER_VALIDATE_EMAIL) === false) {
        error_log('GentleCare Brevo is not configured.');
        throw new GcEmailDeliveryException(
            'Email settings are missing or invalid. Check the Brevo API key and verified sender address in the local secrets file.'
        );
    }

    $payload = json_encode([
        'sender' => ['name' => $senderName, 'email' => $senderEmail],
        'to' => [['email' => $email, 'name' => $name]],
        'subject' => 'Verify your GentleCare email address',
        'textContent' => "Hello {$name},\n\nYour GentleCare verification code is {$code}. "
            . "It expires in 15 minutes. If you did not create this account, you can ignore this message.\n",
    ]);
    if (!is_string($payload)) {
        throw new GcEmailDeliveryException('The verification email could not be prepared. No account was created.');
    }

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    if ($ch === false) {
        throw new GcEmailDeliveryException('The email service is unavailable. No account was created.');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('GentleCare Brevo connection failed. ' . $curlError);
        throw new GcEmailDeliveryException(
            'Could not connect to Brevo to send the verification email. Check the server network and TLS configuration. No account was created.'
        );
    }
    $providerError = json_decode((string) $response, true);
    $providerMessage = is_array($providerError) && isset($providerError['message'])
        && is_string($providerError['message'])
        ? $providerError['message']
        : '';
    if ($status === 401 && preg_match('/unrecogni[sz]ed IP address/i', $providerMessage) === 1) {
        error_log('GentleCare Brevo rejected the request because the server IP is not authorized.');
        throw new GcEmailDeliveryException(
            'Brevo blocked this server IP. Add the outbound IP shown in Brevo’s error to its Authorized IPs list at https://app.brevo.com/security/authorised_ips. No account was created.'
        );
    }
    if ($status === 401) {
        error_log('GentleCare Brevo rejected the API credentials. HTTP 401.');
        throw new GcEmailDeliveryException(
            'Brevo rejected the API key. Confirm it is active and copied completely into C:\\xampp\\secrets\\gentlecare.php. No account was created.'
        );
    }
    if ($status === 403) {
        error_log('GentleCare Brevo denied the email request. HTTP 403.');
        throw new GcEmailDeliveryException(
            'Brevo denied the email request. Check the API key permissions and account status. No account was created.'
        );
    }
    if ($status < 200 || $status >= 300) {
        error_log('GentleCare Brevo send failed. HTTP ' . $status . '.');
        throw new GcEmailDeliveryException(
            'Brevo rejected the verification email (HTTP ' . $status . '). Check that the sender is verified in Brevo. No account was created.'
        );
    }

    return true;
}

function gc_public_user(array $user): array
{
    $role = (string) ($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? 0);
    $fallbackName = (string) ($user['name'] ?? $user['full_name'] ?? '');
    $name = $fallbackName;

    if ($userId > 0 && $name === '') {
        try {
            $db = gc_db();
            $query = null;
            if ($role === 'patient') {
                $query = 'SELECT full_name FROM patient_profiles WHERE user_id = :user_id LIMIT 1';
            } elseif ($role === 'doctor') {
                $query = 'SELECT full_name FROM doctor_profiles WHERE user_id = :user_id LIMIT 1';
            } elseif ($role === 'clinic') {
                $query = 'SELECT clinic_name AS full_name FROM clinic_profiles WHERE user_id = :user_id LIMIT 1';
            }
            if ($query !== null) {
                $statement = $db->prepare($query);
                $statement->execute(['user_id' => $userId]);
                $row = $statement->fetch();
                if (is_array($row) && isset($row['full_name'])) {
                    $name = (string) $row['full_name'];
                }
            }
        } catch (Throwable $exception) {
            error_log('GentleCare public user lookup failed: ' . $exception->getMessage());
        }
    }

    return [
        'id' => (int) ($user['id'] ?? 0),
        'role' => $role,
        'name' => $name,
        'email' => (string) ($user['email'] ?? ''),
    ];
}

function gc_set_authenticated_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = gc_public_user($user);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['created_at'] = time();
    $_SESSION['last_activity'] = time();
}

function gc_audit(string $action, string $result, ?int $userId = null): void
{
    try {
        if ($userId === null && isset($_SESSION['user']['id'])) {
            $userId = (int) $_SESSION['user']['id'];
        }
        $statement = gc_db()->prepare(
            'INSERT INTO audit_log (user_id, action, result, ip) VALUES (:user_id, :action, :result, :ip)'
        );
        $statement->execute([
            'user_id' => $userId,
            'action' => $action,
            'result' => $result,
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    } catch (Throwable $exception) {
        // Logging must never break the app
        error_log('GentleCare audit log failed: ' . $exception->getMessage());
    }
}