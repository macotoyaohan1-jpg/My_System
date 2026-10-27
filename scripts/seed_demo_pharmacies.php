<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/db.php';

$applyChanges = in_array('--apply', $argv, true);
$locationLabel = 'Cabadbaran City, Agusan del Norte';
$pharmacyTargets = [
    [
        'name' => 'Rose Pharmacy',
        'latitude' => '9.12241140',
        'longitude' => '125.53427110',
        'stock' => [36, 24, 18, 25, 42],
    ],
    [
        'name' => 'Watsons Pharmacy',
        'latitude' => '9.12194400',
        'longitude' => '125.53472200',
        'stock' => [40, 22, 30, 20, 38],
    ],
    [
        'name' => 'Rojon Pharmacy',
        'latitude' => '9.12285300',
        'longitude' => '125.53394700',
        'stock' => [28, 18, 16, 24, 32],
    ],
];
$medicineTargets = [
    ['name' => 'Paracetamol', 'aliases' => ['paracetamol'], 'price' => '5.00'],
    ['name' => 'Cetirizine', 'aliases' => ['cetirizine'], 'price' => '10.00'],
    ['name' => 'Ibuprofen', 'aliases' => ['ibuprofen'], 'price' => '8.00'],
    [
        'name' => 'Oral Rehydration Salts',
        'aliases' => ['oral rehydration salts', 'oral rehydration salt', 'ors'],
        'price' => '18.00',
    ],
    ['name' => 'Vitamin C', 'aliases' => ['vitamin c', 'ascorbic acid'], 'price' => '6.00'],
];

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$findPharmacy = $pdo->prepare(
    'SELECT id, name, address, latitude, longitude, is_active
     FROM pharmacies
     WHERE LOWER(TRIM(name)) = LOWER(:name)
     ORDER BY id'
);
$resolveMedicine = static function (array $target) use ($pdo): ?array {
    $namePlaceholders = [];
    $genericPlaceholders = [];
    $parameters = [];
    foreach (array_values(array_unique($target['aliases'])) as $index => $alias) {
        $namePlaceholder = ':name' . $index;
        $genericPlaceholder = ':generic' . $index;
        $namePlaceholders[] = $namePlaceholder;
        $genericPlaceholders[] = $genericPlaceholder;
        $parameters[$namePlaceholder] = strtolower(trim($alias));
        $parameters[$genericPlaceholder] = strtolower(trim($alias));
    }
    $sql = 'SELECT id, name, generic_name, is_active
            FROM medicines
            WHERE LOWER(TRIM(name)) IN (' . implode(', ', $namePlaceholders) . ')
               OR LOWER(TRIM(COALESCE(generic_name, ""))) IN (' . implode(', ', $genericPlaceholders) . ')
            ORDER BY id';
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $matches = $statement->fetchAll();
    if (count($matches) > 1) {
        throw new RuntimeException(
            'More than one existing medicine matches ' . $target['name'] . '; resolve the duplicate records before seeding.'
        );
    }
    return $matches[0] ?? null;
};

try {
    $resolvedMedicines = [];
    foreach ($medicineTargets as $target) {
        $resolvedMedicines[] = $resolveMedicine($target);
    }

    $resolvedPharmacies = [];
    foreach ($pharmacyTargets as $target) {
        $findPharmacy->execute([':name' => $target['name']]);
        $matches = $findPharmacy->fetchAll();
        if (count($matches) > 1) {
            throw new RuntimeException(
                'More than one pharmacy matches ' . $target['name'] . '; resolve the duplicate records before seeding.'
            );
        }
        $resolvedPharmacies[] = $matches[0] ?? null;
    }

    if (!$applyChanges) {
        echo "READ-ONLY PREVIEW — no records were changed.\n";
        foreach ($pharmacyTargets as $pharmacyIndex => $target) {
            $existing = $resolvedPharmacies[$pharmacyIndex];
            echo sprintf(
                "%s pharmacy: %s; address: %s; coordinates to save: %s, %s\n",
                $existing === null ? 'Create' : 'Update coordinates/activate',
                $target['name'],
                $existing === null || trim((string) $existing['address']) === ''
                    ? $locationLabel
                    : (string) $existing['address'],
                $target['latitude'],
                $target['longitude']
            );
        }
        foreach ($medicineTargets as $index => $target) {
            $existing = $resolvedMedicines[$index];
            echo sprintf(
                "%s medicine record: %s%s\n",
                $existing === null ? 'Create' : 'Reuse',
                $target['name'],
                $existing === null ? '' : ' (ID ' . $existing['id'] . ')'
            );
        }
        echo "DEMONSTRATION INVENTORY SCHEDULE (not verified pharmacy stock/prices):\n";
        foreach ($pharmacyTargets as $pharmacyTarget) {
            foreach ($medicineTargets as $medicineIndex => $medicineTarget) {
                echo sprintf(
                    "  %s — %s: sample quantity %d, sample price PHP %s\n",
                    $pharmacyTarget['name'],
                    $medicineTarget['name'],
                    $pharmacyTarget['stock'][$medicineIndex],
                    $medicineTarget['price']
                );
            }
        }
        echo "Apply mode updates only these three pharmacy records and these fifteen pharmacy-medicine pairs; unrelated records are untouched.\n";
        echo "To apply, run: php scripts/seed_demo_pharmacies.php --apply\n";
        exit(0);
    }

    $lockStatement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lockStatement->execute([':lock_name' => 'medifind_demo_pharmacy_seed']);
    if ((int) $lockStatement->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the database seed lock. No changes were made.');
    }

    $pdo->beginTransaction();

    $findPharmacyForUpdate = $pdo->prepare(
        'SELECT id, address FROM pharmacies WHERE LOWER(TRIM(name)) = LOWER(:name) ORDER BY id FOR UPDATE'
    );
    $insertPharmacy = $pdo->prepare(
        'INSERT INTO pharmacies (name, address, latitude, longitude, is_active)
         VALUES (:name, :address, :latitude, :longitude, 1)'
    );
    $updatePharmacy = $pdo->prepare(
        'UPDATE pharmacies
         SET address = CASE WHEN TRIM(address) = "" THEN :address ELSE address END,
             latitude = :latitude,
             longitude = :longitude,
             is_active = 1
         WHERE id = :id'
    );
    $insertMedicine = $pdo->prepare(
        'INSERT INTO medicines (name, generic_name, is_active)
         VALUES (:name, :generic_name, 1)'
    );
    $activateMedicine = $pdo->prepare('UPDATE medicines SET is_active = 1 WHERE id = :id');
    $upsertInventory = $pdo->prepare(
        'INSERT INTO pharmacy_medicines
            (pharmacy_id, medicine_id, quantity, price, availability, updated_at)
         VALUES (:pharmacy_id, :medicine_id, :quantity, :price, "available", CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            quantity = VALUES(quantity),
            price = VALUES(price),
            availability = VALUES(availability),
            updated_at = CURRENT_TIMESTAMP'
    );

    $medicineIds = [];
    foreach ($medicineTargets as $index => $target) {
        $medicine = $resolveMedicine($target);
        if ($medicine === null) {
            $insertMedicine->execute([
                ':name' => $target['name'],
                ':generic_name' => $target['name'],
            ]);
            $medicineIds[] = (int) $pdo->lastInsertId();
        } else {
            if ((int) $medicine['is_active'] !== 1) {
                $activateMedicine->execute([':id' => (int) $medicine['id']]);
            }
            $medicineIds[] = (int) $medicine['id'];
        }
    }

    $inventoryCount = 0;
    foreach ($pharmacyTargets as $target) {
        $findPharmacyForUpdate->execute([':name' => $target['name']]);
        $pharmacy = $findPharmacyForUpdate->fetch();
        if ($pharmacy === false) {
            $insertPharmacy->execute([
                ':name' => $target['name'],
                ':address' => $locationLabel,
                ':latitude' => $target['latitude'],
                ':longitude' => $target['longitude'],
            ]);
            $pharmacyId = (int) $pdo->lastInsertId();
        } else {
            $pharmacyId = (int) $pharmacy['id'];
            $updatePharmacy->execute([
                ':address' => $locationLabel,
                ':latitude' => $target['latitude'],
                ':longitude' => $target['longitude'],
                ':id' => $pharmacyId,
            ]);
        }

        foreach ($medicineTargets as $medicineIndex => $medicineTarget) {
            $upsertInventory->execute([
                ':pharmacy_id' => $pharmacyId,
                ':medicine_id' => $medicineIds[$medicineIndex],
                ':quantity' => $target['stock'][$medicineIndex],
                ':price' => $medicineTarget['price'],
            ]);
            $inventoryCount++;
        }
    }

    $pdo->commit();
    echo sprintf(
        "Committed successfully: %d pharmacies updated/created and %d demonstration inventory rows inserted/updated.\n",
        count($pharmacyTargets),
        $inventoryCount
    );
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed stopped: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($applyChanges && isset($lockStatement)) {
        $releaseLock = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $releaseLock->execute([':lock_name' => 'medifind_demo_pharmacy_seed']);
    }
}
