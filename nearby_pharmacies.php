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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, ['success' => false, 'message' => 'Use POST to search nearby pharmacies.']);
}

if (empty($_SESSION['user_id'])) {
    $respond(401, ['success' => false, 'message' => 'Please sign in to find nearby pharmacies.']);
}

$latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
if ($latitude === false || $longitude === false
    || !is_finite((float) $latitude) || !is_finite((float) $longitude)
    || $latitude < -90 || $latitude > 90
    || $longitude < -180 || $longitude > 180) {
    $respond(400, ['success' => false, 'message' => 'Provide valid latitude and longitude coordinates.']);
}

try {
    require_once __DIR__ . '/db.php';

    $statement = $pdo->prepare(
        'SELECT
            p.id,
            p.name,
            p.address,
            p.phone,
            p.latitude,
            p.longitude,
            6371 * 2 * ASIN(SQRT(LEAST(1,
                POWER(SIN(RADIANS(p.latitude - user_location.latitude) / 2), 2)
                + COS(RADIANS(user_location.latitude)) * COS(RADIANS(p.latitude))
                * POWER(SIN(RADIANS(p.longitude - user_location.longitude) / 2), 2)
            ))) AS distance_km
         FROM pharmacies AS p
         CROSS JOIN (
            SELECT
                CAST(:latitude AS DECIMAL(10, 8)) AS latitude,
                CAST(:longitude AS DECIMAL(11, 8)) AS longitude
         ) AS user_location
         WHERE p.is_active = 1
           AND p.latitude BETWEEN -90 AND 90
           AND p.longitude BETWEEN -180 AND 180
         ORDER BY distance_km ASC, p.name ASC'
    );
    $statement->execute([
        ':latitude' => (float) $latitude,
        ':longitude' => (float) $longitude
    ]);

    $pharmacies = [];
    foreach ($statement->fetchAll() as $pharmacy) {
        $pharmacies[] = [
            'id' => (int) $pharmacy['id'],
            'name' => (string) $pharmacy['name'],
            'address' => (string) $pharmacy['address'],
            'phone' => (string) ($pharmacy['phone'] ?? ''),
            'latitude' => (float) $pharmacy['latitude'],
            'longitude' => (float) $pharmacy['longitude'],
            'distance_km' => (float) $pharmacy['distance_km']
        ];
    }

    $respond(200, ['success' => true, 'pharmacies' => $pharmacies]);
} catch (PDOException $exception) {
    error_log('Nearby pharmacy search failed: ' . $exception->getMessage());
    $respond(500, ['success' => false, 'message' => 'Nearby pharmacies could not be loaded right now.']);
}
