<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
gc_start_session();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string) ($_GET['action'] ?? 'list');

$user = $_SESSION['user'] ?? null;
if (!is_array($user) || !isset($user['id'], $user['role'])) {
    gc_api_respond(401, 'Please log in to continue.');
}
$userId = (int) $user['id'];

function gc_notification_rows(PDO $db, int $userId): array
{
    $statement = $db->prepare(
        'SELECT id, appointment_id, notification_type, message, '
        . 'DATE_FORMAT(created_at, "%Y-%m-%dT%H:%i:%sZ") AS created_at, '
        . 'DATE_FORMAT(read_at, "%Y-%m-%dT%H:%i:%sZ") AS read_at '
        . 'FROM notifications WHERE user_id = :user_id '
        . 'ORDER BY created_at DESC, id DESC LIMIT 20'
    );
    $statement->execute(['user_id' => $userId]);

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[] = [
            'id' => (int) $row['id'],
            'appointmentId' => $row['appointment_id'] === null ? null : (int) $row['appointment_id'],
            'type' => $row['notification_type'],
            'message' => $row['message'],
            'createdAt' => $row['created_at'],
            'readAt' => $row['read_at'],
        ];
    }
    return $rows;
}

function gc_notification_unread_count(PDO $db, int $userId): int
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL'
    );
    $statement->execute(['user_id' => $userId]);
    return (int) $statement->fetchColumn();
}

if ($method === 'GET' && $action === 'list') {
    try {
        $db = gc_db();
        gc_api_respond(200, null, [
            'notifications' => gc_notification_rows($db, $userId),
            'unreadCount' => gc_notification_unread_count($db, $userId),
        ]);
    } catch (Throwable $exception) {
        error_log('GentleCare notification lookup failed: ' . $exception->getMessage());
        gc_api_respond(503, 'The service is temporarily unavailable.');
    }
}

if ($method === 'POST' && $action === 'read') {
    gc_api_require_csrf();
    $input = gc_api_input();
    $notificationId = null;
    if (isset($input['notification_id'])) {
        $notificationId = filter_var(
            $input['notification_id'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($notificationId === false) {
            gc_api_respond(422, 'Provide a valid notification_id.');
        }
    }

    try {
        $db = gc_db();
        if ($notificationId === null) {
            $statement = $db->prepare(
                'UPDATE notifications SET read_at = UTC_TIMESTAMP() '
                . 'WHERE user_id = :user_id AND read_at IS NULL'
            );
            $statement->execute(['user_id' => $userId]);
        } else {
            $statement = $db->prepare(
                'UPDATE notifications SET read_at = UTC_TIMESTAMP() '
                . 'WHERE id = :notification_id AND user_id = :user_id AND read_at IS NULL'
            );
            $statement->execute(['notification_id' => $notificationId, 'user_id' => $userId]);
        }
        gc_api_respond(200, null, [
            'notifications' => gc_notification_rows($db, $userId),
            'unreadCount' => gc_notification_unread_count($db, $userId),
        ]);
    } catch (Throwable $exception) {
        error_log('GentleCare notification update failed: ' . $exception->getMessage());
        gc_api_respond(503, 'The service is temporarily unavailable.');
    }
}

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    gc_api_respond(405, 'Unsupported request method.');
}
gc_api_respond(400, 'Unsupported notification action.');
