<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    gc_db()->query('SELECT 1');
    gc_respond(200, ['status' => 'ok', 'database' => 'connected']);
} catch (Throwable $exception) {
    error_log('GentleCare health check failed: ' . $exception->getMessage());
    gc_respond(503, ['status' => 'error', 'database' => 'unavailable']);
}
