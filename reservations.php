<?php
declare(strict_types=1);

session_start();

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? '')));
$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($action === '' && $requestMethod === 'GET') {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php?error=session');
        exit;
    }
    header('Location: dashboard.php#reservations');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (!in_array($action, ['create', 'cancel'], true)) {
    $respond(400, ['success' => false, 'message' => 'Choose a valid reservation action.']);
}
if ($requestMethod !== 'POST') {
    header('Allow: POST');
    $respond(405, [
        'success' => false,
        'message' => $action === 'create'
            ? 'Use POST to create a reservation.'
            : 'Use POST to cancel a reservation.'
    ]);
}
if (empty($_SESSION['user_id'])) {
    $respond(401, ['success' => false, 'message' => 'Please sign in before managing reservations.']);
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if ($userId === false || $userId === null || $userId < 1) {
    $respond(401, ['success' => false, 'message' => 'Your session is invalid. Please sign in again.']);
}
if (empty($_SESSION['reservation_csrf_token'])
    || !hash_equals((string) $_SESSION['reservation_csrf_token'], $csrfToken)) {
    $respond(403, ['success' => false, 'message' => 'Your session could not be verified. Reload the page and try again.']);
}

if ($action === 'create') {
    $medicineId = filter_var($_POST['medicine_id'] ?? null, FILTER_VALIDATE_INT);
    $pharmacyId = filter_var($_POST['pharmacy_id'] ?? null, FILTER_VALIDATE_INT);
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);

    if ($medicineId === false || $medicineId === null || $medicineId < 1
        || $pharmacyId === false || $pharmacyId === null || $pharmacyId < 1) {
        $respond(400, ['success' => false, 'message' => 'Select a valid medicine and pharmacy.']);
    }
    if ($quantity === false || $quantity === null || $quantity < 1) {
        $respond(400, ['success' => false, 'message' => 'Quantity must be a positive whole number.']);
    }
} else {
    $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
    if ($reservationId === false || $reservationId === null || $reservationId < 1) {
        $respond(400, ['success' => false, 'message' => 'Provide a valid reservation ID.']);
    }
}

try {
    require_once __DIR__ . '/db.php';
    $pdo->beginTransaction();

    if ($action === 'create') {
        $inventoryStatement = $pdo->prepare(
            'SELECT
                pm.quantity,
                pm.availability,
                pm.price,
                m.name AS medicine_name,
                p.name AS pharmacy_name
             FROM pharmacy_medicines AS pm
             INNER JOIN medicines AS m ON m.id = pm.medicine_id
             INNER JOIN pharmacies AS p ON p.id = pm.pharmacy_id
             WHERE pm.medicine_id = :medicine_id
               AND pm.pharmacy_id = :pharmacy_id
               AND m.is_active = 1
               AND p.is_active = 1
             LIMIT 1
             FOR UPDATE'
        );
        $inventoryStatement->execute([
            ':medicine_id' => $medicineId,
            ':pharmacy_id' => $pharmacyId
        ]);
        $inventory = $inventoryStatement->fetch();

        if (!$inventory) {
            $pdo->rollBack();
            $respond(404, ['success' => false, 'message' => 'This pharmacy no longer lists the selected medicine.']);
        }
        if ($inventory['availability'] === 'out_of_stock' || (int) $inventory['quantity'] < 1) {
            $pdo->rollBack();
            $respond(409, ['success' => false, 'message' => 'This medicine is currently out of stock at this pharmacy.']);
        }

        $holdsStatement = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity), 0)
             FROM reservations
             WHERE pharmacy_id = :pharmacy_id
               AND medicine_id = :medicine_id
               AND status = 'pending'
               AND expires_at > CURRENT_TIMESTAMP"
        );
        $holdsStatement->execute([
            ':pharmacy_id' => $pharmacyId,
            ':medicine_id' => $medicineId
        ]);
        $heldQuantity = (int) $holdsStatement->fetchColumn();
        $remainingQuantity = max(0, (int) $inventory['quantity'] - $heldQuantity);
        if ($quantity > $remainingQuantity) {
            $pdo->rollBack();
            $respond(409, [
                'success' => false,
                'message' => 'Only ' . $remainingQuantity . ' item(s) are currently available to reserve.'
            ]);
        }

        $insertStatement = $pdo->prepare(
            "INSERT INTO reservations (
                user_id, pharmacy_id, medicine_id, quantity, price_at_reservation, status, expires_at
             ) VALUES (
                :user_id, :pharmacy_id, :medicine_id, :quantity, :price, 'pending',
                DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR)
             )"
        );
        $insertStatement->execute([
            ':user_id' => $userId,
            ':pharmacy_id' => $pharmacyId,
            ':medicine_id' => $medicineId,
            ':quantity' => $quantity,
            ':price' => $inventory['price']
        ]);
        $reservationId = (int) $pdo->lastInsertId();

        $notificationStatement = $pdo->prepare(
            "INSERT INTO notifications (user_id, title, message, type)
             VALUES (:user_id, 'Reservation created', :message, 'reservation_created')"
        );
        $notificationStatement->execute([
            ':user_id' => $userId,
            ':message' => 'Your reservation for ' . $inventory['medicine_name']
                . ' at ' . $inventory['pharmacy_name'] . ' is pending.'
        ]);

        $pdo->commit();
        $_SESSION['reservation_flash'] = [
            'message' => 'Reservation successful! Your medicine is held for 24 hours.'
        ];
        $respond(201, [
            'success' => true,
            'message' => 'Reservation created successfully.',
            'reservation' => [
                'id' => $reservationId,
                'medicine' => (string) $inventory['medicine_name'],
                'pharmacy' => (string) $inventory['pharmacy_name'],
                'quantity' => $quantity,
                'price' => $inventory['price'] === null ? null : (float) $inventory['price'],
                'status' => 'pending',
                'expires_in_hours' => 24
            ]
        ]);
    }

    $reservationStatement = $pdo->prepare(
        'SELECT
            r.id,
            r.status,
            r.expires_at > CURRENT_TIMESTAMP AS is_active,
            m.name AS medicine_name,
            p.name AS pharmacy_name
         FROM reservations AS r
         INNER JOIN medicines AS m ON m.id = r.medicine_id
         INNER JOIN pharmacies AS p ON p.id = r.pharmacy_id
         WHERE r.id = :reservation_id
           AND r.user_id = :user_id
         LIMIT 1
         FOR UPDATE'
    );
    $reservationStatement->execute([
        ':reservation_id' => $reservationId,
        ':user_id' => $userId
    ]);
    $reservation = $reservationStatement->fetch();

    if (!$reservation) {
        $pdo->rollBack();
        $respond(404, ['success' => false, 'message' => 'Reservation not found.']);
    }
    if ($reservation['status'] !== 'pending') {
        $pdo->rollBack();
        $respond(409, ['success' => false, 'message' => 'Only pending reservations can be cancelled.']);
    }
    if (!(bool) $reservation['is_active']) {
        $pdo->rollBack();
        $respond(409, ['success' => false, 'message' => 'This reservation has expired and can no longer be cancelled.']);
    }

    $cancelStatement = $pdo->prepare(
        "UPDATE reservations
         SET status = 'cancelled'
         WHERE id = :reservation_id
           AND user_id = :user_id
           AND status = 'pending'
           AND expires_at > CURRENT_TIMESTAMP"
    );
    $cancelStatement->execute([
        ':reservation_id' => $reservationId,
        ':user_id' => $userId
    ]);
    if ($cancelStatement->rowCount() !== 1) {
        $pdo->rollBack();
        $respond(409, ['success' => false, 'message' => 'This reservation could not be cancelled. It may have expired.']);
    }

    $notificationStatement = $pdo->prepare(
        "INSERT INTO notifications (user_id, title, message, type)
         VALUES (:user_id, 'Reservation cancelled', :message, 'reservation_cancelled')"
    );
    $notificationStatement->execute([
        ':user_id' => $userId,
        ':message' => 'Your reservation for ' . $reservation['medicine_name']
            . ' at ' . $reservation['pharmacy_name'] . ' was cancelled.'
    ]);

    $pdo->commit();
    $_SESSION['reservation_flash'] = [
        'message' => 'Your reservation has been cancelled and its stock hold released.'
    ];
    $respond(200, ['success' => true, 'message' => 'Reservation cancelled successfully.']);
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Reservation ' . $action . ' failed for user ' . $userId . ': ' . $exception->getMessage());
    $respond(500, [
        'success' => false,
        'message' => $action === 'create'
            ? 'Your reservation could not be created right now.'
            : 'The reservation could not be cancelled right now.'
    ]);
}
