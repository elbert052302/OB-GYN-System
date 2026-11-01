<?php
declare(strict_types=1);

function gc_rate_limit_key(string ...$parts): string
{
    $value = '';
    foreach ($parts as $part) {
        $value .= strlen($part) . ':' . $part;
    }
    return hash('sha256', $value);
}

function gc_rate_limit_check(string $bucket, string $limitKey, int $limit, int $windowMinutes): void
{
    gc_rate_limit_validate($bucket, $limitKey, $limit, $windowMinutes);

    $statement = gc_db()->prepare(
        'SELECT COUNT(*) FROM auth_rate_limits '
        . 'WHERE bucket = :bucket AND limit_key = :limit_key '
        . 'AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :window_minutes MINUTE)'
    );
    $statement->execute([
        'bucket' => $bucket,
        'limit_key' => $limitKey,
        'window_minutes' => $windowMinutes,
    ]);

    if ((int) $statement->fetchColumn() >= $limit) {
        gc_respond(429, ['error' => 'Too many attempts. Please try again later.']);
    }
}

function gc_rate_limit_hit(string $bucket, string $limitKey, int $windowMinutes): void
{
    gc_rate_limit_validate($bucket, $limitKey, 1, $windowMinutes);
    $db = gc_db();
    $deleteExpired = $db->prepare(
        'DELETE FROM auth_rate_limits '
        . 'WHERE bucket = :bucket AND limit_key = :limit_key '
        . 'AND attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :window_minutes MINUTE)'
    );
    $deleteExpired->execute([
        'bucket' => $bucket,
        'limit_key' => $limitKey,
        'window_minutes' => $windowMinutes,
    ]);

    $insert = $db->prepare(
        'INSERT INTO auth_rate_limits (bucket, limit_key, attempted_at) '
        . 'VALUES (:bucket, :limit_key, UTC_TIMESTAMP())'
    );
    $insert->execute(['bucket' => $bucket, 'limit_key' => $limitKey]);
}

function gc_rate_limit_clear(string $bucket, string $limitKey): void
{
    if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $bucket) || !preg_match('/^[a-f0-9]{64}$/', $limitKey)) {
        throw new InvalidArgumentException('Invalid rate limit bucket or key.');
    }

    $statement = gc_db()->prepare(
        'DELETE FROM auth_rate_limits WHERE bucket = :bucket AND limit_key = :limit_key'
    );
    $statement->execute(['bucket' => $bucket, 'limit_key' => $limitKey]);
}

function gc_rate_limit_validate(string $bucket, string $limitKey, int $limit, int $windowMinutes): void
{
    if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $bucket)
        || !preg_match('/^[a-f0-9]{64}$/', $limitKey)
        || $limit < 1
        || $windowMinutes < 1) {
        throw new InvalidArgumentException('Invalid rate limit configuration.');
    }
}
