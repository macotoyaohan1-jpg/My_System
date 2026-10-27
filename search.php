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

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    $respond(405, ['success' => false, 'message' => 'Use GET to search medicines and availability.']);
}

if (empty($_SESSION['user_id'])) {
    $respond(401, ['success' => false, 'message' => 'Please sign in to search medicines.']);
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId < 1) {
    $respond(401, ['success' => false, 'message' => 'Your session is invalid. Please sign in again.']);
}

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
if (!in_array($action, ['medicines', 'availability'], true)) {
    $respond(400, ['success' => false, 'message' => 'Choose a valid search action.']);
}

try {
    require_once __DIR__ . '/db.php';

    if ($action === 'medicines') {
        $search = trim((string) ($_GET['search'] ?? ''));
        if ($search === '') {
            $respond(200, ['success' => true, 'results' => []]);
        }
        if (strlen($search) > 100) {
            $respond(400, ['success' => false, 'message' => 'Search text must be 100 characters or fewer.']);
        }

        $statement = $pdo->prepare(
            'SELECT id, name, generic_name, brand_name, dosage, form, description
             FROM medicines
             WHERE is_active = 1
               AND (
                   name LIKE :search_name
                   OR generic_name LIKE :search_generic
                   OR brand_name LIKE :search_brand
               )
             ORDER BY name ASC
             LIMIT 20'
        );
        $term = '%' . $search . '%';
        $statement->execute([
            ':search_name' => $term,
            ':search_generic' => $term,
            ':search_brand' => $term
        ]);
        $respond(200, ['success' => true, 'results' => $statement->fetchAll()]);
    }

    $medicineId = filter_var($_GET['medicine_id'] ?? null, FILTER_VALIDATE_INT);
    if ($medicineId === false || $medicineId === null || $medicineId < 1) {
        $respond(400, ['success' => false, 'message' => 'Provide a valid medicine ID.']);
    }

    $statement = $pdo->prepare(
        "SELECT
            m.id AS medicine_id,
            m.name AS medicine_name,
            p.id AS pharmacy_id,
            p.name AS pharmacy_name,
            p.address,
            p.phone,
            p.latitude,
            p.longitude,
            GREATEST(pm.quantity - COALESCE((
                SELECT SUM(r.quantity)
                FROM reservations AS r
                WHERE r.pharmacy_id = p.id
                  AND r.medicine_id = m.id
                  AND r.status = 'pending'
                  AND r.expires_at > CURRENT_TIMESTAMP
            ), 0), 0) AS quantity,
            pm.price,
            pm.availability,
            pm.updated_at
         FROM medicines AS m
         INNER JOIN pharmacy_medicines AS pm
            ON pm.medicine_id = m.id
         INNER JOIN pharmacies AS p
            ON p.id = pm.pharmacy_id
         WHERE m.id = :medicine_id
           AND m.is_active = 1
           AND p.is_active = 1
         ORDER BY
            CASE pm.availability
                WHEN 'available' THEN 1
                WHEN 'low_stock' THEN 2
                WHEN 'out_of_stock' THEN 3
                ELSE 4
            END,
            p.name ASC"
    );
    $statement->execute([':medicine_id' => $medicineId]);
    $rows = $statement->fetchAll();

    $medicineName = null;
    $pharmacies = [];
    foreach ($rows as $row) {
        $medicineName = (string) $row['medicine_name'];
        $pharmacies[] = [
            'pharmacy_id' => (int) $row['pharmacy_id'],
            'pharmacy_name' => (string) $row['pharmacy_name'],
            'address' => (string) $row['address'],
            'phone' => $row['phone'] === null ? null : (string) $row['phone'],
            'latitude' => $row['latitude'] === null ? null : (float) $row['latitude'],
            'longitude' => $row['longitude'] === null ? null : (float) $row['longitude'],
            'quantity' => (int) $row['quantity'],
            'price' => $row['price'] === null ? null : (float) $row['price'],
            'availability' => (int) $row['quantity'] < 1
                ? 'out_of_stock'
                : (string) $row['availability'],
            'updated_at' => (string) $row['updated_at']
        ];
    }

    if ($medicineName === null) {
        $medicineStatement = $pdo->prepare(
            'SELECT name FROM medicines WHERE id = :medicine_id AND is_active = 1 LIMIT 1'
        );
        $medicineStatement->execute([':medicine_id' => $medicineId]);
        $medicineName = $medicineStatement->fetchColumn();
        if (!is_string($medicineName)) {
            $respond(404, ['success' => false, 'message' => 'The medicine was not found or is inactive.']);
        }
    }

    $respond(200, [
        'success' => true,
        'medicine' => [
            'id' => (int) $medicineId,
            'name' => $medicineName
        ],
        'results' => $pharmacies
    ]);
} catch (PDOException $exception) {
    error_log('Medicine search failed for user ' . $userId . ': ' . $exception->getMessage());
    $respond(500, [
        'success' => false,
        'message' => $action === 'medicines'
            ? 'Unable to search medicines.'
            : 'Medicine availability could not be loaded right now.'
    ]);
}
