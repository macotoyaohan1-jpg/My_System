<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    $respond(405, ['success' => false, 'message' => 'Use POST to record a pharmacy visit.']);
}
if (empty($_SESSION['user_id'])) {
    $respond(401, ['success' => false, 'message' => 'Please sign in to record a pharmacy visit.']);
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
$pharmacyId = filter_var($_POST['pharmacy_id'] ?? null, FILTER_VALIDATE_INT);
$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if ($userId === false || $userId === null || $userId < 1) {
    $respond(401, ['success' => false, 'message' => 'Your session is invalid. Please sign in again.']);
}
if (empty($_SESSION['pharmacy_visit_csrf_token'])
    || !hash_equals((string) $_SESSION['pharmacy_visit_csrf_token'], $csrfToken)) {
    $respond(403, ['success' => false, 'message' => 'Your session could not be verified. Reload the page and try again.']);
}
if ($pharmacyId === false || $pharmacyId === null || $pharmacyId < 1) {
    $respond(400, ['success' => false, 'message' => 'Select a valid pharmacy.']);
}

try {
    require_once __DIR__ . '/db.php';
    $pharmacyStatement = $pdo->prepare('SELECT id FROM pharmacies WHERE id = :pharmacy_id LIMIT 1');
    $pharmacyStatement->execute([':pharmacy_id' => $pharmacyId]);
    if (!$pharmacyStatement->fetchColumn()) {
        $respond(404, ['success' => false, 'message' => 'The selected pharmacy could not be found.']);
    }

    $visitStatement = $pdo->prepare(
        'INSERT INTO pharmacy_visits (user_id, pharmacy_id) VALUES (:user_id, :pharmacy_id)'
    );
    $visitStatement->execute([
        ':user_id' => $userId,
        ':pharmacy_id' => $pharmacyId
    ]);

    $visitedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
        ->format('M j, Y g:i A');
    $respond(201, ['success' => true, 'visited_at' => $visitedAt]);
} catch (PDOException $exception) {
    error_log('Pharmacy visit could not be recorded for user ' . $userId . ': ' . $exception->getMessage());
    $respond(503, ['success' => false, 'message' => 'The pharmacy visit could not be saved right now.']);
}
