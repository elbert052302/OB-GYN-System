<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

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

function gc_send_verification_code(string $email, string $name, string $code): bool
{
    $from = getenv('GENTLECARE_MAIL_FROM');
    if ($from === false || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }

    $subject = 'Verify your GentleCare email address';
    $message = "Hello {$name},\r\n\r\nYour GentleCare verification code is {$code}. "
        . "It expires in 15 minutes. If you did not create this account, you can ignore this message.\r\n";
    $headers = [
        'From: GentleCare <' . $from . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: GentleCare',
    ];

    return mail($email, $subject, $message, implode("\r\n", $headers));
}

function gc_public_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'role' => (string) $user['role'],
        'name' => (string) $user['full_name'],
        'email' => (string) $user['email'],
    ];
}

function gc_set_authenticated_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = gc_public_user($user);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
