<?php
declare(strict_types=1);

session_start();

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? '')));
$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($action === '') {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php?error=session');
        exit;
    }

    header('Location: dashboard.php#notifications');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

$methodAllowed = match ($action) {
    'count' => $requestMethod === 'GET',
    'mark_read' => $requestMethod === 'POST',
    default => false,
};

if (!$methodAllowed) {
    if (in_array($action, ['count', 'mark_read'], true)) {
        header('Allow: ' . ($action === 'count' ? 'GET' : 'POST'));
        $respond(405, [
            'success' => false,
            'message' => $action === 'count'
                ? 'Use GET to load notification count.'
                : 'Use POST to update notifications.'
        ]);
    }

    $respond(400, ['success' => false, 'message' => 'Unknown notification action.']);
}

if (empty($_SESSION['user_id'])) {
    $respond(401, ['success' => false, 'message' => 'Please sign in to load notifications.']);
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId < 1) {
    $respond(401, ['success' => false, 'message' => 'Your session is invalid. Please sign in again.']);
}

$notificationId = 0;
$markAll = false;

if ($action === 'mark_read') {
    $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
    $markAll = ($_POST['mark_all'] ?? '') === '1';
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!$markAll && ($notificationId === false || $notificationId === null || $notificationId < 1)) {
        $respond(400, ['success' => false, 'message' => 'Provide a valid notification ID.']);
    }
    if (empty($_SESSION['notifications_csrf_token'])
        || !hash_equals((string) $_SESSION['notifications_csrf_token'], $csrfToken)) {
        $respond(403, ['success' => false, 'message' => 'Your session could not be verified. Reload the page and try again.']);
    }
}

try {
    require_once __DIR__ . '/db.php';

    if ($action === 'count') {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM notifications
             WHERE user_id = :user_id
               AND is_read = 0'
        );
        $statement->execute([':user_id' => $userId]);
        $respond(200, ['success' => true, 'unread_count' => (int) $statement->fetchColumn()]);
    }

    if ($markAll) {
        $statement = $pdo->prepare(
            'UPDATE notifications
             SET is_read = 1
             WHERE user_id = :user_id
               AND is_read = 0'
        );
        $statement->execute([':user_id' => $userId]);
        $respond(200, ['success' => true, 'message' => 'All notifications marked as read.']);
    }

    $statement = $pdo->prepare(
        'UPDATE notifications
         SET is_read = 1
         WHERE id = :notification_id
           AND user_id = :user_id'
    );
    $statement->execute([
        ':notification_id' => $notificationId,
        ':user_id' => $userId
    ]);

    if ($statement->rowCount() === 0) {
        $ownedStatement = $pdo->prepare(
            'SELECT 1
             FROM notifications
             WHERE id = :notification_id
               AND user_id = :user_id
             LIMIT 1'
        );
        $ownedStatement->execute([
            ':notification_id' => $notificationId,
            ':user_id' => $userId
        ]);
        if ($ownedStatement->fetchColumn() === false) {
            $respond(404, ['success' => false, 'message' => 'Notification not found.']);
        }
    }

    $respond(200, ['success' => true, 'message' => 'Notification marked as read.']);
} catch (PDOException $exception) {
    error_log('Notification request failed for user ' . $userId . ': ' . $exception->getMessage());
    $respond(500, [
        'success' => false,
        'message' => $action === 'count'
            ? 'Notification count could not be loaded right now.'
            : 'Notification could not be updated right now.'
    ]);
}
