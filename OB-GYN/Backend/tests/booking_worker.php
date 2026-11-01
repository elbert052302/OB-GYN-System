<?php
declare(strict_types=1);

$fixturePath = $argv[1] ?? '';
$readyPath = $argv[2] ?? '';
$releasePath = $argv[3] ?? '';
$fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($readyPath, 'ready');
$deadline = microtime(true) + 15;
while (!is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "release timeout\n");
        exit(2);
    }
    usleep(10000);
}

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Accept: application/json\r\n"
            . "Content-Type: application/json\r\n"
            . 'Cookie: ' . $fixture['cookie'] . "\r\n"
            . 'X-CSRF-Token: ' . $fixture['csrf'],
        'content' => json_encode([
            'slot_id' => $fixture['slot_id'],
            'purposes' => ['Concurrent test'],
        ], JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
        'timeout' => 20,
    ],
]);
$body = @file_get_contents($fixture['url'], false, $context);
if ($body === false || !isset($http_response_header[0])
    || !preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
    fwrite(STDERR, "booking endpoint unavailable\n");
    exit(3);
}
echo json_encode(['status' => (int) $matches[1], 'body' => json_decode($body, true)], JSON_THROW_ON_ERROR);
