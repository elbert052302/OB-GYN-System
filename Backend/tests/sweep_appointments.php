<?php
declare(strict_types=1);

/**
 * Closes appointments whose slot has already ended and releases their booking
 * reservations.
 *
 * Run it from Task Scheduler (Windows) or cron so reservations are released even
 * when nobody opens the app. The API also calls the same routine lazily, so the
 * system stays correct without the scheduled job.
 *
 * Usage: C:\xampp\php\php.exe Backend\tests\sweep_appointments.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/api/bootstrap.php';

$completed = gc_release_finished_appointments();

if ($completed === 0) {
    echo 'No finished appointments to close.' . PHP_EOL;
    exit(0);
}

echo sprintf(
    'Closed %d appointment%s and released the matching reservation%s.' . PHP_EOL,
    $completed,
    $completed === 1 ? '' : 's',
    $completed === 1 ? '' : 's'
);
exit(0);
