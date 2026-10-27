<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?error=session');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

$userId = (int) $_SESSION['user_id'];
$username = (string) ($_SESSION['username'] ?? 'there');
$profilePhotoDirectory = __DIR__ . '/uploads/profile_photos';
$removeProfilePhotoFile = static function (?string $fileName) use ($profilePhotoDirectory): void {
    if ($fileName === null || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', $fileName)) {
        return;
    }

    $filePath = $profilePhotoDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (is_file($filePath) && !unlink($filePath)) {
        error_log('Unable to remove profile photo file ' . $fileName);
    }
};
$profileFlash = $_SESSION['profile_flash'] ?? [];
unset($_SESSION['profile_flash']);
$reservationFlash = $_SESSION['reservation_flash'] ?? [];
unset($_SESSION['reservation_flash']);
$profileErrors = $profileFlash['errors'] ?? [];
$profileNotice = $profileFlash['notice'] ?? '';
$profileValues = $profileFlash['values'] ?? [];
$settingsFlash = $_SESSION['settings_flash'] ?? [];
unset($_SESSION['settings_flash']);
$settingsErrors = $settingsFlash['errors'] ?? [];
$settingsNotice = $settingsFlash['notice'] ?? '';

if (empty($_SESSION['profile_csrf_token'])) {
    $_SESSION['profile_csrf_token'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['settings_csrf_token'])) {
    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['reservation_csrf_token'])) {
    $_SESSION['reservation_csrf_token'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['pharmacy_visit_csrf_token'])) {
    $_SESSION['pharmacy_visit_csrf_token'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['notifications_csrf_token'])) {
    $_SESSION['notifications_csrf_token'] = bin2hex(random_bytes(32));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && ($_POST['action'] ?? '') === 'remove_profile_photo') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['profile_csrf_token'], $csrfToken)) {
        $_SESSION['profile_flash'] = [
            'errors' => ['Your session could not be verified. Please reload the page and try again.']
        ];
        header('Location: dashboard.php#profile');
        exit;
    }

    try {
        $photoStatement = $pdo->prepare(
            'SELECT profile_photo FROM users WHERE id = :user_id LIMIT 1'
        );
        $photoStatement->execute([':user_id' => $userId]);
        $oldProfilePhoto = $photoStatement->fetchColumn();

        $removePhotoStatement = $pdo->prepare(
            'UPDATE users
             SET profile_photo = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :user_id'
        );
        $removePhotoStatement->execute([':user_id' => $userId]);
        $removeProfilePhotoFile(is_string($oldProfilePhoto) ? $oldProfilePhoto : null);
        $_SESSION['profile_flash'] = ['notice' => 'Your profile photo was removed.'];
    } catch (PDOException $exception) {
        error_log('Profile photo removal failed for user ' . $userId . ': ' . $exception->getMessage());
        $_SESSION['profile_flash'] = [
            'errors' => ['Your profile photo could not be removed right now. Please try again.']
        ];
    }

    header('Location: dashboard.php#profile');
    exit;
}

$notificationCountStatement = $pdo->prepare(
    'SELECT COUNT(*)
     FROM notifications
     WHERE user_id = :user_id
       AND is_read = 0'
);
$notificationCountStatement->execute([':user_id' => $userId]);
$notificationUnreadCount = (int) $notificationCountStatement->fetchColumn();

$notificationStatement = $pdo->prepare(
    'SELECT id, title, message, type, is_read, created_at
     FROM notifications
     WHERE user_id = :user_id
     ORDER BY created_at DESC, id DESC
     LIMIT 100'
);
$notificationStatement->execute([':user_id' => $userId]);
$notificationRows = $notificationStatement->fetchAll();
$notificationGroups = ['Today' => [], 'Earlier' => []];
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
foreach ($notificationRows as $notificationRow) {
    $createdTimestamp = strtotime((string) $notificationRow['created_at']);
    $notificationDate = $createdTimestamp === false
        ? null
        : (new DateTimeImmutable('@' . $createdTimestamp))
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format('Y-m-d');
    $groupName = $notificationDate === $today
        ? 'Today'
        : 'Earlier';
    $notificationGroups[$groupName][] = $notificationRow;
}
$formatNotificationAge = static function (string $createdAt): string {
    $createdTimestamp = strtotime($createdAt);
    if ($createdTimestamp === false) {
        return 'Date unavailable';
    }

    $elapsedSeconds = max(0, time() - $createdTimestamp);
    if ($elapsedSeconds < 60) {
        return 'Just now';
    }
    if ($elapsedSeconds < 3600) {
        $minutes = (int) floor($elapsedSeconds / 60);
        return $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes') . ' ago';
    }
    if ($elapsedSeconds < 86400) {
        $hours = (int) floor($elapsedSeconds / 3600);
        return $hours . ' ' . ($hours === 1 ? 'hour' : 'hours') . ' ago';
    }
    if ($elapsedSeconds < 604800) {
        $days = (int) floor($elapsedSeconds / 86400);
        return $days . ' ' . ($days === 1 ? 'day' : 'days') . ' ago';
    }

    return date('M j, Y', $createdTimestamp);
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $settingsErrors = [];

    if (!hash_equals((string) $_SESSION['settings_csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $settingsErrors[] = 'Your session could not be verified. Please reload the page and try again.';
    }
    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $settingsErrors[] = 'Complete all password fields.';
    }
    if ($newPassword !== '' && strlen($newPassword) < 8) {
        $settingsErrors[] = 'Your new password must be at least 8 characters.';
    }
    if ($newPassword !== $confirmPassword) {
        $settingsErrors[] = 'The new password and confirmation do not match.';
    }
    if ($currentPassword !== '' && $newPassword !== '' && hash_equals($currentPassword, $newPassword)) {
        $settingsErrors[] = 'Choose a new password that is different from your current password.';
    }

    if ($settingsErrors === []) {
        $passwordStatement = $pdo->prepare(
            'SELECT password_hash FROM users WHERE id = :user_id LIMIT 1'
        );
        $passwordStatement->execute([':user_id' => $userId]);
        $currentPasswordHash = $passwordStatement->fetchColumn();

        if (!is_string($currentPasswordHash) || !password_verify($currentPassword, $currentPasswordHash)) {
            $settingsErrors[] = 'The current password is incorrect.';
        }
    }

    if ($settingsErrors === []) {
        try {
            $pdo->beginTransaction();
            $passwordUpdate = $pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :user_id'
            );
            $passwordUpdate->execute([
                ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':user_id' => $userId
            ]);
            logSecurityActivity(
                $pdo,
                $userId,
                'PASSWORD_CHANGE',
                'User changed their account password.',
                (string) ($_SESSION['username'] ?? '')
            );
            $pdo->commit();
            session_regenerate_id(true);
            $_SESSION['settings_flash'] = ['notice' => 'Your password was changed successfully.'];
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Password change failed for user ' . $userId . ': ' . $exception->getMessage());
            $_SESSION['settings_flash'] = [
                'errors' => ['Your password could not be changed right now. Please try again.']
            ];
        }

        header('Location: dashboard.php#settings');
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $profileValues = [
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone
    ];
    $profileErrors = [];

    if (!hash_equals((string) $_SESSION['profile_csrf_token'], $csrfToken)) {
        $profileErrors[] = 'Your session could not be verified. Please reload the page and try again.';
    }
    if ($fullName === '' || strlen($fullName) > 150) {
        $profileErrors[] = 'Enter a full name of 1 to 150 characters.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $profileErrors[] = 'Enter a valid email address.';
    }
    if (!preg_match('/^09\d{9}$/', $phone)) {
        $profileErrors[] = 'Enter a phone number in this format: 09xxxxxxxxx.';
    }

    $uploadedPhoto = $_FILES['profile_photo'] ?? null;
    $photoMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];
    $photoMimeType = null;
    if (is_array($uploadedPhoto) && ($uploadedPhoto['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($uploadedPhoto['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !isset($uploadedPhoto['tmp_name'])
            || !is_uploaded_file((string) $uploadedPhoto['tmp_name'])) {
            $profileErrors[] = 'The profile photo upload failed. Please choose the image again.';
        } else {
            $temporaryPhotoPath = (string) $uploadedPhoto['tmp_name'];
            $photoSize = filesize($temporaryPhotoPath);
            $photoInfo = @getimagesize($temporaryPhotoPath);
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $photoMimeType = $fileInfo->file($temporaryPhotoPath);

            if ($photoSize === false || $photoSize > 5 * 1024 * 1024) {
                $profileErrors[] = 'Choose a profile photo smaller than 5 MB.';
            } elseif (!is_array($photoInfo)
                || !isset($photoInfo[0], $photoInfo[1], $photoInfo['mime'])
                || !is_string($photoMimeType)
                || !isset($photoMimeTypes[$photoMimeType])
                || $photoInfo['mime'] !== $photoMimeType) {
                $profileErrors[] = 'Use a valid JPG, PNG, or WebP image for your profile photo.';
            } elseif (
                $photoInfo[0] < 1 || $photoInfo[1] < 1
                || $photoInfo[0] > 6000 || $photoInfo[1] > 6000
                || $photoInfo[0] * $photoInfo[1] > 20000000
            ) {
                $profileErrors[] = 'The profile photo dimensions are too large.';
            }
        }
    }

    if ($profileErrors === []) {
        $duplicateStatement = $pdo->prepare(
            'SELECT username, email, phone
             FROM users
             WHERE id <> :user_id
               AND (email = :email OR phone = :phone)'
        );
        $duplicateStatement->execute([
            ':user_id' => $userId,
            ':email' => $email,
            ':phone' => $phone
        ]);
        foreach ($duplicateStatement->fetchAll() as $duplicateUser) {
            if ($duplicateUser['email'] === $email
                && !in_array('That email address is already in use.', $profileErrors, true)) {
                $profileErrors[] = 'That email address is already in use.';
            }
            if ($duplicateUser['phone'] === $phone
                && !in_array('That phone number is already in use.', $profileErrors, true)) {
                $profileErrors[] = 'That phone number is already in use.';
            }
        }
    }

    if ($profileErrors === []) {
        $newProfilePhoto = null;
        $newProfilePhotoPath = null;
        try {
            if (is_array($uploadedPhoto) && ($uploadedPhoto['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                if (!is_dir($profilePhotoDirectory)
                    && !mkdir($profilePhotoDirectory, 0750, true)
                    && !is_dir($profilePhotoDirectory)) {
                    throw new RuntimeException('Unable to create profile photo directory.');
                }

                $newProfilePhoto = bin2hex(random_bytes(16)) . '.' . $photoMimeTypes[$photoMimeType];
                $newProfilePhotoPath = $profilePhotoDirectory . DIRECTORY_SEPARATOR . $newProfilePhoto;
                if (!move_uploaded_file((string) $uploadedPhoto['tmp_name'], $newProfilePhotoPath)) {
                    throw new RuntimeException('Unable to store profile photo.');
                }
            }

            $currentPhotoStatement = $pdo->prepare(
                'SELECT profile_photo FROM users WHERE id = :user_id LIMIT 1'
            );
            $currentPhotoStatement->execute([':user_id' => $userId]);
            $oldProfilePhoto = $currentPhotoStatement->fetchColumn();

            $profileUpdateSql = 'UPDATE users
                 SET full_name = :full_name,
                     email = :email,
                     phone = :phone';
            if ($newProfilePhoto !== null) {
                $profileUpdateSql .= ',
                     profile_photo = :profile_photo';
            }
            $profileUpdateSql .= ',
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :user_id';
            $updateProfile = $pdo->prepare(
                $profileUpdateSql
            );
            $profileUpdateValues = [
                ':full_name' => $fullName,
                ':email' => $email,
                ':phone' => $phone,
                ':user_id' => $userId
            ];
            if ($newProfilePhoto !== null) {
                $profileUpdateValues[':profile_photo'] = $newProfilePhoto;
            }
            $updateProfile->execute($profileUpdateValues);
            if ($newProfilePhoto !== null && is_string($oldProfilePhoto)) {
                $removeProfilePhotoFile($oldProfilePhoto);
            }
            $_SESSION['profile_flash'] = ['notice' => 'Your profile was updated.'];
        } catch (PDOException | RuntimeException $exception) {
            if ($newProfilePhotoPath !== null && is_file($newProfilePhotoPath) && !unlink($newProfilePhotoPath)) {
                error_log('Unable to clean up failed profile photo upload for user ' . $userId);
            }
            error_log('Profile update failed for user ' . $userId . ': ' . $exception->getMessage());
            $_SESSION['profile_flash'] = [
                'errors' => ['Your profile could not be updated right now. Please try again.'],
                'values' => $profileValues
            ];
        }

        header('Location: dashboard.php#profile');
        exit;
    }
}

$accountStatement = $pdo->prepare(
    'SELECT full_name, username, email, phone, profile_photo, role, is_active, password_hash, failed_attempts, locked_until
     FROM users
     WHERE id = :user_id
     LIMIT 1'
);
$accountStatement->execute([':user_id' => $userId]);
$accountSecurity = $accountStatement->fetch() ?: [];
$currentUserRole = (string) ($accountSecurity['role'] ?? 'user');
$isAdmin = strtolower($currentUserRole) === 'admin';
$profileUser = $accountSecurity;
$profileName = (string) ($profileUser['full_name'] ?? $username);
$profileUsername = (string) ($profileUser['username'] ?? $username);
$profilePhotoFile = is_string($profileUser['profile_photo'] ?? null)
    && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', $profileUser['profile_photo'])
        ? $profileUser['profile_photo']
        : null;
$profilePhotoUrl = $profilePhotoFile === null
    ? null
    : 'uploads/profile_photos/' . rawurlencode($profilePhotoFile);
$profileEmail = (string) ($profileValues['email'] ?? $profileUser['email'] ?? '');
$profilePhone = (string) ($profileValues['phone'] ?? $profileUser['phone'] ?? '');
$profileDisplayName = (string) ($profileValues['full_name'] ?? $profileName);
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$locationIcon = static function (): string {
    return '<svg class="location-symbol-icon" viewBox="0 0 32 32" aria-hidden="true" focusable="false">'
        . '<circle class="location-icon-ring" cx="16" cy="16" r="14.5"></circle>'
        . '<path class="location-icon-pin" d="M16 5.5a6 6 0 0 0-6 6c0 4.4 6 10.8 6 10.8s6-6.4 6-10.8a6 6 0 0 0-6-6Z"></path>'
        . '<circle class="location-icon-center" cx="16" cy="11.5" r="2.1"></circle>'
        . '<ellipse class="location-icon-base" cx="16" cy="23.5" rx="7.2" ry="2.7"></ellipse>'
        . '</svg>';
};
$quoteIdentifier = static function (string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $identifier)) {
        throw new RuntimeException('Unexpected database identifier.');
    }

    return '`' . $identifier . '`';
};

$tableNames = $pdo->query(
    'SELECT TABLE_NAME
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()'
)->fetchAll(PDO::FETCH_COLUMN);
$tables = [];
foreach ($tableNames as $tableName) {
    $tables[strtolower($tableName)] = $tableName;
}

$findTable = static function (array $candidates) use ($tables): ?string {
    foreach ($candidates as $candidate) {
        if (isset($tables[strtolower($candidate)])) {
            return $tables[strtolower($candidate)];
        }
    }

    return null;
};
$getColumns = static function (?string $table) use ($pdo): array {
    if ($table === null) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name'
    );
    $statement->execute([':table_name' => $table]);

    $columns = [];
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[strtolower($column)] = $column;
    }

    return $columns;
};
$findColumn = static function (array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (isset($columns[strtolower($candidate)])) {
            return $columns[strtolower($candidate)];
        }
    }

    return null;
};
$countRows = static function (?string $table) use ($pdo, $quoteIdentifier): int {
    if ($table === null) {
        return 0;
    }

    return (int) $pdo->query(
        'SELECT COUNT(*) FROM ' . $quoteIdentifier($table)
    )->fetchColumn();
};

$pharmacyTable = $findTable(['pharmacies', 'pharmacy']);
$medicineTable = $findTable(['medicines', 'medicine', 'drugs']);
$pharmacyMedicineTable = $findTable(['pharmacy_medicines', 'pharmacy_medicine', 'pharmacy_inventory']);
$reservationTable = $findTable([
    'reservations',
    'reservation',
    'medicine_reservations',
    'medicine_reservation',
    'user_reservations'
]);
$pharmacyColumns = $getColumns($pharmacyTable);
$medicineColumns = $getColumns($medicineTable);
$pharmacyMedicineColumns = $getColumns($pharmacyMedicineTable);
$reservationColumns = $getColumns($reservationTable);
$securityLogsReady = false;
$securityEvents = [];
$recentFailedAttempts = 0;
try {
    $securityStatement = $pdo->prepare(
        'SELECT activity, description, ip_address, created_at
         FROM security_logs
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC
         LIMIT 10'
    );
    $securityStatement->execute([':user_id' => $userId]);
    $securityEvents = $securityStatement->fetchAll();
    $failedAttemptsStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM security_logs
         WHERE user_id = :user_id
           AND activity IN ('LOGIN_FAILED', 'LOGIN_BLOCKED')
           AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR)"
    );
    $failedAttemptsStatement->execute([':user_id' => $userId]);
    $recentFailedAttempts = (int) $failedAttemptsStatement->fetchColumn();
    $securityLogsReady = true;
} catch (PDOException $exception) {
    if (!str_contains($exception->getMessage(), 'security_logs')) {
        throw $exception;
    }
    error_log('Security activity could not be loaded for user ' . $userId . ': ' . $exception->getMessage());
}

$pharmacyCount = $countRows($pharmacyTable);
$medicineCount = $countRows($medicineTable);
$medicineMetric = $medicineCount;
$medicineMetricLabel = 'Medicine listings';
$reservationCount = 0;
$recentPharmacies = [];
$pharmacyDirectory = [];
$pharmacyInventory = [];
$hasPharmacyInventory = false;
$recentReservations = [];
$recentPharmacyVisits = [];

$medicineStockColumn = $findColumn(
    $medicineColumns,
    ['quantity', 'stock_quantity', 'stock', 'qty', 'available_quantity']
);
$medicineAvailabilityColumn = $findColumn(
    $medicineColumns,
    ['is_available', 'available', 'in_stock', 'availability', 'stock_status']
);
if ($medicineTable !== null && $medicineStockColumn !== null) {
    $medicineMetric = (int) $pdo->query(
        'SELECT COUNT(*) FROM ' . $quoteIdentifier($medicineTable)
        . ' WHERE ' . $quoteIdentifier($medicineStockColumn) . ' > 0'
    )->fetchColumn();
    $medicineMetricLabel = 'Available medicines';
} elseif ($medicineTable !== null && $medicineAvailabilityColumn !== null) {
    $medicineMetric = (int) $pdo->query(
        'SELECT COUNT(*) FROM ' . $quoteIdentifier($medicineTable)
        . ' WHERE LOWER(CAST(' . $quoteIdentifier($medicineAvailabilityColumn)
        . " AS CHAR)) IN ('1', 'yes', 'true', 'available', 'in stock', 'instock')"
    )->fetchColumn();
    $medicineMetricLabel = 'Available medicines';
}

$pharmacyNameColumn = $findColumn(
    $pharmacyColumns,
    ['name', 'pharmacy_name', 'store_name']
);
$pharmacyLocationColumn = $findColumn(
    $pharmacyColumns,
    ['address', 'location', 'city', 'branch_address']
);
$pharmacyOpenColumn = $findColumn(
    $pharmacyColumns,
    ['is_open', 'open_now', 'opening_status']
);

if ($pharmacyTable !== null && $pharmacyNameColumn !== null) {
    $select = [
        $quoteIdentifier($pharmacyNameColumn) . ' AS pharmacy_name'
    ];
    if ($pharmacyLocationColumn !== null) {
        $select[] = $quoteIdentifier($pharmacyLocationColumn) . ' AS pharmacy_location';
    }
    if ($pharmacyOpenColumn !== null) {
        $select[] = $quoteIdentifier($pharmacyOpenColumn) . ' AS pharmacy_open';
    }

    $recentPharmacies = $pdo->query(
        'SELECT ' . implode(', ', $select)
        . ' FROM ' . $quoteIdentifier($pharmacyTable)
        . ' ORDER BY ' . $quoteIdentifier($pharmacyNameColumn)
        . ' LIMIT 5'
    )->fetchAll();
}

$pharmacyIdColumn = $findColumn($pharmacyColumns, ['id', 'pharmacy_id']);
$pharmacyPhoneColumn = $findColumn($pharmacyColumns, ['phone', 'phone_number', 'contact_number']);
$pharmacyLatitudeColumn = $findColumn($pharmacyColumns, ['latitude', 'lat']);
$pharmacyLongitudeColumn = $findColumn($pharmacyColumns, ['longitude', 'lng', 'lon']);
$pharmacyActiveColumn = $findColumn($pharmacyColumns, ['is_active']);
$pharmacyDescriptionColumn = $findColumn($pharmacyColumns, ['description', 'about', 'details']);
$pharmacyHoursColumn = $findColumn($pharmacyColumns, ['opening_hours', 'business_hours', 'hours']);
$pharmacyVisitTable = $findTable(['pharmacy_visits']);
$pharmacyVisitColumns = $getColumns($pharmacyVisitTable);
$pharmacyVisitUserColumn = $findColumn($pharmacyVisitColumns, ['user_id']);
$pharmacyVisitPharmacyColumn = $findColumn($pharmacyVisitColumns, ['pharmacy_id']);
$pharmacyVisitDateColumn = $findColumn($pharmacyVisitColumns, ['visited_at', 'created_at']);

if (
    $pharmacyVisitTable !== null
    && $pharmacyVisitUserColumn !== null
    && $pharmacyVisitPharmacyColumn !== null
    && $pharmacyVisitDateColumn !== null
    && $pharmacyIdColumn !== null
    && $pharmacyNameColumn !== null
) {
    $visitSelect = [
        'p.' . $quoteIdentifier($pharmacyIdColumn) . ' AS `pharmacy_id`',
        'p.' . $quoteIdentifier($pharmacyNameColumn) . ' AS `pharmacy_name`',
        'MAX(pv.' . $quoteIdentifier($pharmacyVisitDateColumn) . ') AS `visited_at`'
    ];
    $visitGroupBy = [
        'pv.' . $quoteIdentifier($pharmacyVisitPharmacyColumn),
        'p.' . $quoteIdentifier($pharmacyIdColumn),
        'p.' . $quoteIdentifier($pharmacyNameColumn)
    ];
    if ($pharmacyLocationColumn !== null) {
        $visitSelect[] = 'p.' . $quoteIdentifier($pharmacyLocationColumn) . ' AS `pharmacy_location`';
        $visitGroupBy[] = 'p.' . $quoteIdentifier($pharmacyLocationColumn);
    }

    $visitStatement = $pdo->prepare(
        'SELECT ' . implode(', ', $visitSelect)
        . ' FROM ' . $quoteIdentifier($pharmacyVisitTable) . ' pv'
        . ' INNER JOIN ' . $quoteIdentifier($pharmacyTable) . ' p'
        . ' ON p.' . $quoteIdentifier($pharmacyIdColumn)
        . ' = pv.' . $quoteIdentifier($pharmacyVisitPharmacyColumn)
        . ' WHERE pv.' . $quoteIdentifier($pharmacyVisitUserColumn) . ' = :user_id'
        . ' GROUP BY ' . implode(', ', $visitGroupBy)
        . ' ORDER BY MAX(pv.' . $quoteIdentifier($pharmacyVisitDateColumn) . ') DESC LIMIT 5'
    );
    $visitStatement->execute([':user_id' => $userId]);
    $recentPharmacyVisits = $visitStatement->fetchAll();
}

if ($pharmacyTable !== null && $pharmacyIdColumn !== null && $pharmacyNameColumn !== null) {
    $select = [
        $quoteIdentifier($pharmacyIdColumn) . ' AS `pharmacy_id`',
        $quoteIdentifier($pharmacyNameColumn) . ' AS `pharmacy_name`'
    ];
    foreach ([
        'pharmacy_location' => $pharmacyLocationColumn,
        'pharmacy_phone' => $pharmacyPhoneColumn,
        'latitude' => $pharmacyLatitudeColumn,
        'longitude' => $pharmacyLongitudeColumn,
        'description' => $pharmacyDescriptionColumn,
        'hours' => $pharmacyHoursColumn
    ] as $alias => $column) {
        if ($column !== null) {
            $select[] = $quoteIdentifier($column) . ' AS ' . $quoteIdentifier($alias);
        }
    }

    $where = '';
    if ($pharmacyActiveColumn !== null) {
        $where = ' WHERE LOWER(CAST(' . $quoteIdentifier($pharmacyActiveColumn)
            . " AS CHAR)) IN ('1', 'yes', 'true', 'active')";
    }

    $pharmacyDirectory = $pdo->query(
        'SELECT ' . implode(', ', $select)
        . ' FROM ' . $quoteIdentifier($pharmacyTable)
        . $where
        . ' ORDER BY ' . $quoteIdentifier($pharmacyNameColumn)
    )->fetchAll();
}

$pharmacyMedicinePharmacyColumn = $findColumn($pharmacyMedicineColumns, ['pharmacy_id']);
$pharmacyMedicineMedicineColumn = $findColumn($pharmacyMedicineColumns, ['medicine_id']);
$pharmacyMedicineQuantityColumn = $findColumn(
    $pharmacyMedicineColumns,
    ['quantity', 'stock_quantity', 'stock', 'qty', 'available_quantity']
);
$pharmacyMedicinePriceColumn = $findColumn($pharmacyMedicineColumns, ['price', 'unit_price']);
$pharmacyMedicineAvailabilityColumn = $findColumn(
    $pharmacyMedicineColumns,
    ['availability', 'stock_status', 'status', 'is_available', 'available']
);
$medicineIdColumn = $findColumn($medicineColumns, ['id', 'medicine_id']);
$medicineNameColumn = $findColumn($medicineColumns, ['name', 'medicine_name', 'drug_name']);
$medicineActiveColumn = $findColumn($medicineColumns, ['is_active']);

if (
    $pharmacyMedicineTable !== null
    && $pharmacyIdColumn !== null
    && $pharmacyMedicinePharmacyColumn !== null
    && $pharmacyMedicineMedicineColumn !== null
    && $medicineTable !== null
    && $medicineIdColumn !== null
    && $medicineNameColumn !== null
) {
    $inventorySelect = [
        'pm.' . $quoteIdentifier($pharmacyMedicinePharmacyColumn) . ' AS `pharmacy_id`',
        'm.' . $quoteIdentifier($medicineIdColumn) . ' AS `medicine_id`',
        'm.' . $quoteIdentifier($medicineNameColumn) . ' AS `medicine_name`'
    ];
    if ($pharmacyMedicineQuantityColumn !== null) {
        $inventorySelect[] = 'pm.' . $quoteIdentifier($pharmacyMedicineQuantityColumn) . ' AS `quantity`';
    }
    if ($pharmacyMedicinePriceColumn !== null) {
        $inventorySelect[] = 'pm.' . $quoteIdentifier($pharmacyMedicinePriceColumn) . ' AS `price`';
    }
    if ($pharmacyMedicineAvailabilityColumn !== null) {
        $inventorySelect[] = 'pm.' . $quoteIdentifier($pharmacyMedicineAvailabilityColumn) . ' AS `availability`';
    }

    $inventoryWhere = '';
    if ($medicineActiveColumn !== null) {
        $inventoryWhere = ' WHERE LOWER(CAST(m.' . $quoteIdentifier($medicineActiveColumn)
            . " AS CHAR)) IN ('1', 'yes', 'true', 'active')";
    }

    $inventoryStatement = $pdo->query(
        'SELECT ' . implode(', ', $inventorySelect)
        . ' FROM ' . $quoteIdentifier($pharmacyMedicineTable) . ' pm'
        . ' INNER JOIN ' . $quoteIdentifier($medicineTable) . ' m'
        . ' ON m.' . $quoteIdentifier($medicineIdColumn)
        . ' = pm.' . $quoteIdentifier($pharmacyMedicineMedicineColumn)
        . $inventoryWhere
        . ' ORDER BY m.' . $quoteIdentifier($medicineNameColumn)
    );
    foreach ($inventoryStatement->fetchAll() as $item) {
        $quantity = isset($item['quantity']) && is_numeric($item['quantity'])
            ? (int) $item['quantity']
            : null;
        $availability = strtolower(trim((string) ($item['availability'] ?? '')));
        if (in_array($availability, ['out_of_stock', 'unavailable', 'out of stock'], true) || $quantity === 0) {
            $status = 'unavailable';
        } elseif (in_array($availability, ['low_stock', 'limited', 'low stock'], true)) {
            $status = 'limited';
        } elseif (
            in_array($availability, ['available', 'in stock', 'instock', '1', 'yes', 'true'], true)
            || ($quantity !== null && $quantity > 0)
        ) {
            $status = 'available';
        } else {
            $status = 'unavailable';
        }

        $pharmacyId = (string) $item['pharmacy_id'];
        $pharmacyInventory[$pharmacyId][] = [
            'id' => (string) $item['medicine_id'],
            'name' => (string) $item['medicine_name'],
            'status' => $status,
            'quantity' => $quantity,
            'price' => isset($item['price']) && is_numeric($item['price'])
                ? (float) $item['price']
                : null
        ];
    }
    $hasPharmacyInventory = true;
}

$recommendedPharmacies = $pharmacyDirectory;
foreach ($recommendedPharmacies as &$recommendedPharmacy) {
    $recommendedId = (string) ($recommendedPharmacy['pharmacy_id'] ?? '');
    $recommendedPharmacy['available_count'] = count(array_filter(
        $pharmacyInventory[$recommendedId] ?? [],
        static fn (array $item): bool => ($item['status'] ?? '') === 'available'
    ));
}
unset($recommendedPharmacy);
usort($recommendedPharmacies, static function (array $left, array $right): int {
    $availabilityOrder = ($right['available_count'] ?? 0) <=> ($left['available_count'] ?? 0);
    return $availabilityOrder !== 0
        ? $availabilityOrder
        : strcasecmp((string) ($left['pharmacy_name'] ?? ''), (string) ($right['pharmacy_name'] ?? ''));
});
$recommendedPharmacies = array_slice($recommendedPharmacies, 0, 3);

$reservationUserColumn = $findColumn(
    $reservationColumns,
    ['user_id', 'customer_id', 'account_id']
);
$reservationItemColumn = $findColumn(
    $reservationColumns,
    ['medicine_name', 'medicine', 'drug_name', 'item_name', 'medicine_id']
);
$reservationPharmacyColumn = $findColumn(
    $reservationColumns,
    ['pharmacy_name', 'store_name', 'pharmacy_id']
);
$reservationStatusColumn = $findColumn(
    $reservationColumns,
    ['status', 'reservation_status']
);
$reservationDateColumn = $findColumn(
    $reservationColumns,
    ['created_at', 'reservation_date', 'reserved_at']
);
$reservationIdColumn = $findColumn($reservationColumns, ['reservation_id', 'id']);
$reservationQuantityColumn = $findColumn($reservationColumns, ['quantity', 'qty']);
$reservationPriceColumn = $findColumn($reservationColumns, ['price_at_reservation', 'unit_price', 'price']);
$reservationExpiryColumn = $findColumn($reservationColumns, ['expires_at', 'expiry_at', 'expiration_at']);
$reservationMedicineIdColumn = $findColumn($reservationColumns, ['medicine_id']);
$reservationPharmacyIdColumn = $findColumn($reservationColumns, ['pharmacy_id']);
$userReservations = [];

if ($reservationTable !== null && $reservationUserColumn !== null) {
    $where = $quoteIdentifier($reservationUserColumn) . ' = :user_id';
    $countStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM ' . $quoteIdentifier($reservationTable)
        . ' WHERE ' . $where
    );
    $countStatement->execute([':user_id' => $userId]);
    $reservationCount = (int) $countStatement->fetchColumn();

    $select = [];
    foreach ([
        'item' => $reservationItemColumn,
        'pharmacy' => $reservationPharmacyColumn,
        'status' => $reservationStatusColumn,
        'created' => $reservationDateColumn
    ] as $alias => $column) {
        if ($column !== null) {
            $select[] = $quoteIdentifier($column) . ' AS ' . $quoteIdentifier($alias);
        }
    }
    if ($reservationIdColumn !== null) {
        $select[] = $quoteIdentifier($reservationIdColumn) . ' AS `reservation_id`';
    }
    if ($select === []) {
        $select[] = $quoteIdentifier($reservationUserColumn) . ' AS `reservation_owner`';
    }

    if ($select !== []) {
        $orderBy = $reservationDateColumn !== null
            ? ' ORDER BY ' . $quoteIdentifier($reservationDateColumn) . ' DESC'
            : '';
        $reservationStatement = $pdo->prepare(
            'SELECT ' . implode(', ', $select)
            . ' FROM ' . $quoteIdentifier($reservationTable)
            . ' WHERE ' . $where . $orderBy . ' LIMIT 5'
        );
        $reservationStatement->execute([':user_id' => $userId]);
        $recentReservations = $reservationStatement->fetchAll();
    }

    $reservationSelect = [
        $reservationIdColumn === null
            ? 'NULL AS `reservation_id`'
            : 'r.' . $quoteIdentifier($reservationIdColumn) . ' AS `reservation_id`',
        $reservationStatusColumn === null
            ? "'Unknown' AS `status`"
            : 'r.' . $quoteIdentifier($reservationStatusColumn) . ' AS `status`',
        $reservationDateColumn === null
            ? 'NULL AS `created`'
            : 'r.' . $quoteIdentifier($reservationDateColumn) . ' AS `created`',
        $reservationQuantityColumn === null
            ? 'NULL AS `quantity`'
            : 'r.' . $quoteIdentifier($reservationQuantityColumn) . ' AS `quantity`',
        $reservationPriceColumn === null
            ? 'NULL AS `price`'
            : 'r.' . $quoteIdentifier($reservationPriceColumn) . ' AS `price`',
        $reservationExpiryColumn === null
            ? 'NULL AS `expires`'
            : 'r.' . $quoteIdentifier($reservationExpiryColumn) . ' AS `expires`',
        $reservationExpiryColumn === null
            ? '0 AS `is_expired`'
            : '(r.' . $quoteIdentifier($reservationExpiryColumn) . ' <= CURRENT_TIMESTAMP) AS `is_expired`'
    ];
    $reservationJoins = '';

    $reservationItemIsId = $reservationMedicineIdColumn !== null
        && $reservationItemColumn === $reservationMedicineIdColumn;
    $joinMedicine = $reservationItemIsId
        && $medicineTable !== null
        && $medicineIdColumn !== null
        && $medicineNameColumn !== null;
    if ($joinMedicine) {
        $reservationSelect[] = 'm.' . $quoteIdentifier($medicineNameColumn) . ' AS `item`';
        $medicineDosageColumn = $findColumn($medicineColumns, ['dosage', 'strength']);
        $reservationSelect[] = $medicineDosageColumn === null
            ? "'' AS `dosage`"
            : 'm.' . $quoteIdentifier($medicineDosageColumn) . ' AS `dosage`';
        $reservationJoins .= ' LEFT JOIN ' . $quoteIdentifier($medicineTable) . ' m'
            . ' ON m.' . $quoteIdentifier($medicineIdColumn)
            . ' = r.' . $quoteIdentifier($reservationMedicineIdColumn);
    } elseif ($reservationItemIsId) {
        $reservationSelect[] = "CONCAT('Medicine #', r."
            . $quoteIdentifier($reservationMedicineIdColumn) . ') AS `item`';
        $reservationSelect[] = "'' AS `dosage`";
    } elseif ($reservationItemColumn !== null) {
        $reservationSelect[] = 'r.' . $quoteIdentifier($reservationItemColumn) . ' AS `item`';
        $reservationSelect[] = "'' AS `dosage`";
    } else {
        $reservationSelect[] = "'Medicine not specified' AS `item`";
        $reservationSelect[] = "'' AS `dosage`";
    }

    $reservationPharmacyIsId = $reservationPharmacyIdColumn !== null
        && $reservationPharmacyColumn === $reservationPharmacyIdColumn;
    $joinPharmacy = $reservationPharmacyIsId
        && $pharmacyTable !== null
        && $pharmacyIdColumn !== null
        && $pharmacyNameColumn !== null;
    if ($joinPharmacy) {
        $reservationSelect[] = 'p.' . $quoteIdentifier($pharmacyNameColumn) . ' AS `pharmacy`';
        $reservationSelect[] = $pharmacyLocationColumn === null
            ? "'' AS `location`"
            : 'p.' . $quoteIdentifier($pharmacyLocationColumn) . ' AS `location`';
        $reservationJoins .= ' LEFT JOIN ' . $quoteIdentifier($pharmacyTable) . ' p'
            . ' ON p.' . $quoteIdentifier($pharmacyIdColumn)
            . ' = r.' . $quoteIdentifier($reservationPharmacyIdColumn);
    } elseif ($reservationPharmacyIsId) {
        $reservationSelect[] = "CONCAT('Pharmacy #', r."
            . $quoteIdentifier($reservationPharmacyIdColumn) . ') AS `pharmacy`';
        $reservationSelect[] = "'' AS `location`";
    } elseif ($reservationPharmacyColumn !== null) {
        $reservationSelect[] = 'r.' . $quoteIdentifier($reservationPharmacyColumn) . ' AS `pharmacy`';
        $reservationSelect[] = "'' AS `location`";
    } else {
        $reservationSelect[] = "'Pharmacy not specified' AS `pharmacy`";
        $reservationSelect[] = "'' AS `location`";
    }

    $reservationOrder = $reservationDateColumn !== null
        ? ' ORDER BY r.' . $quoteIdentifier($reservationDateColumn) . ' DESC'
        : ($reservationIdColumn === null
            ? ''
            : ' ORDER BY r.' . $quoteIdentifier($reservationIdColumn) . ' DESC');
    $allReservationsStatement = $pdo->prepare(
        'SELECT ' . implode(', ', $reservationSelect)
        . ' FROM ' . $quoteIdentifier($reservationTable) . ' r'
        . $reservationJoins
        . ' WHERE r.' . $quoteIdentifier($reservationUserColumn) . ' = :user_id'
        . $reservationOrder
    );
    $allReservationsStatement->execute([':user_id' => $userId]);
    $userReservations = $allReservationsStatement->fetchAll();
}

$currentHour = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('G');
$greeting = $currentHour < 12
    ? 'Good morning'
    : ($currentHour < 18 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f7fb">
    <title>Dashboard | MediFind</title>
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin=""
    >
    <link rel="stylesheet" href="css/dashboard.css?v=88">
</head>
<body>
    <div class="dashboard-shell" id="dashboard" data-active-section="dashboard" data-pharmacy-visit-csrf="<?= $escape($_SESSION['pharmacy_visit_csrf_token']) ?>">
        <aside class="sidebar" aria-label="Main navigation">
            <span class="nav-active-indicator" aria-hidden="true"></span>
            <a class="brand" href="#dashboard" aria-label="MediFind home">
                <img class="brand-logo" src="Images/logo.png" alt="">
                <span class="brand-name">MediFind</span>
            </a>

            <p class="nav-label">Main</p>
            <nav class="side-nav">
                <a class="nav-link" data-section="dashboard" href="#dashboard" aria-label="Dashboard"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3.5 10 8.5-7 8.5 7"></path><path d="M5.5 9v11h5.2v-6h2.6v6h5.2V9"></svg></span><span class="nav-tooltip">Dashboard</span></a>

                <a class="nav-link" data-section="pharmacies" href="#pharmacies" aria-label="Pharmacies"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 21h18"></path><path d="M5 21V7l7-4 7 4v14"></path><path d="M9 21v-5h6v5"></path><path d="M9 9h.01M15 9h.01"></path></svg></span><span class="nav-tooltip">Pharmacies</span></a>
                <a class="nav-link" data-section="reservations" href="#reservations" aria-label="My Reservations"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 4H5a2 2 0 0 0-2 2v14h18V6a2 2 0 0 0-2-2h-3"></path><path d="M8 2h8v5H8zM7 12h10M7 16h10"></path></svg></span><span class="nav-tooltip">My Reservations</span></a>
            </nav>

            <p class="nav-label nav-label-spaced">Account</p>
            <nav class="side-nav">
                <a class="nav-link" data-section="security" href="#security" aria-label="Security Activity"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"></path><path d="m9 12 2 2 4-4"></path></svg></span><span class="nav-tooltip">Security Activity</span></a>
                <a class="nav-link" data-section="profile" href="#profile" aria-label="Profile"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg></span><span class="nav-tooltip">Profile</span></a>
                <a class="nav-link" data-section="settings" href="#settings" aria-label="Settings"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="m19.4 15 .1.1 1.4 1.1-1.5 2.6-1.7-.7a8 8 0 0 1-1.8 1l-.3 1.9h-3l-.3-1.9a8 8 0 0 1-1.8-1l-1.7.7-1.5-2.6 1.4-1.1a7 7 0 0 1 0-2l-1.4-1.1 1.5-2.6 1.7.7a8 8 0 0 1 1.8-1L12.6 7h3l.3 1.9a8 8 0 0 1 1.8 1l1.7-.7 1.5 2.6-1.4 1.1a7 7 0 0 1-.1 2.1z"></path></svg></span><span class="nav-tooltip">Settings</span></a>
            </nav>

            <div class="sidebar-bottom">
                <a class="nav-link logout-link" href="logout.php" aria-label="Logout"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 3h8v18H5a2 2 0 0 1-2-2V3Z" fill="currentColor" stroke="none"></path><path d="M11 4h5a3 3 0 0 1 3 3v2M19 15v2a3 3 0 0 1-3 3h-5"></path><path d="M9 12h12m-4-4 4 4-4 4"></path></svg></span><span class="nav-tooltip">Logout</span></a>
            </div>
        </aside>

        <main class="main-content">
            <header class="topbar">
                <div class="breadcrumb">Workspace <span>/</span> Dashboard</div>
                <div class="topbar-actions">
                    <div class="medicine-search-container">
                        <label class="medicine-search">
                            <svg aria-hidden="true" viewBox="0 0 20 20" focusable="false">
                                <circle cx="8.8" cy="8.8" r="5.8"></circle>
                                <path d="m13.2 13.2 4 4"></path>
                            </svg>
                            <input
                                id="medicineSearch"
                                type="search"
                                name="medicine-search"
                                placeholder="Search Medicine"
                                aria-label="Search Medicine"
                                aria-controls="medicineResults"
                                aria-expanded="false"
                                autocomplete="off"
                            >
                        </label>
                        <div id="medicineResults" class="medicine-search-results" role="listbox" aria-label="Medicine search results" hidden></div>
                    </div>
                    <div class="notification-preview-wrap">
                        <button class="header-notification-link" id="notificationPreviewToggle" type="button" popovertarget="notificationPreview" popovertargetaction="toggle" aria-label="Notifications<?= $notificationUnreadCount > 0 ? ' (' . $notificationUnreadCount . ')' : '' ?>" aria-expanded="false" aria-controls="notificationPreview" title="Notifications">
                            <svg class="header-notification-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
                            <span id="notificationUnreadCount" class="notification-count" aria-live="polite"><?= $notificationUnreadCount > 0 ? $notificationUnreadCount : '' ?></span>
                        </button>

                    
                        
                    </div>
                    <a class="account-menu" href="#profile" data-section="profile" data-dashboard-profile-link aria-label="Open profile" title="Profile">
                        <span class="avatar" aria-hidden="true">
                            <?php if ($profilePhotoUrl !== null): ?>
                                <img src="<?= $escape($profilePhotoUrl) ?>" alt="">
                            <?php else: ?>
                                <?= $escape(strtoupper(substr($username, 0, 1))) ?>
                            <?php endif; ?>
                        </span>
                        <span class="account-name"><?= $escape($username) ?></span>
                    </a>
                </div>
            </header>

            

            <div class="page-content">
                <div class="dashboard-overview" data-feature-panel="dashboard">
                <section class="welcome-section">
                    <div>
                        <p class="eyebrow">YOUR HEALTH, CLOSE TO HOME</p>
                        <h1><span id="time-greeting"><?= $escape($greeting) ?></span>, <?= $escape($username) ?>!</h1>
                        <p class="welcome-copy">What are you looking for today?</p>
                    </div>
                    <a class="primary-button" href="#pharmacies"><span class="location-action-icon"><?= $locationIcon() ?></span> Explore pharmacies</a>
                </section>

                <div class="dashboard-spotlight-grid">
                <section class="welcome-feature" aria-label="Your MediFind pharmacy companion">
                    <div class="welcome-feature-media">
                        <video
                            class="welcome-feature-video"
                            autoplay
                            muted
                            loop
                            playsinline
                            preload="metadata"
                            aria-hidden="true"
                        >
                            <source src="Images/animation.mp4" type="video/mp4">
                        </video>
                    </div>
                    <div class="welcome-feature-copy">
                        <p class="eyebrow">A LITTLE HELP, WHEN YOU NEED IT</p>
                        <h2>Medicine is closer than you think.</h2>
                        <p>Discover nearby pharmacies with ease.</p>
                    </div>
                </section>

                <section class="stats-grid" aria-label="Dashboard summary">
                    <article class="stat-card">
                        <span class="stat-icon pharmacy-icon" aria-hidden="true">
                            <svg class="line-icon" viewBox="0 0 24 24" focusable="false">
                                <path d="M4 20V9l8-5 8 5v11"></path>
                                <path d="M8 20v-5h8v5M12 7v4m-2-2h4M3 20h18"></path>
                            </svg>
                        </span>
                        <p class="stat-label">Total pharmacies</p>
                        <p class="stat-value"><?= $pharmacyCount ?></p>
                        <p class="stat-note">In the pharmacy directory</p>
                    </article>
                    <article class="stat-card" id="medicines">
                        <span class="stat-icon medicine-icon" aria-hidden="true">
                            <svg class="line-icon" viewBox="0 0 24 24" focusable="false">
                                <path d="M7.2 4.5a4.8 4.8 0 0 1 6.8 0l5.5 5.5a4.8 4.8 0 0 1-6.8 6.8l-5.5-5.5a4.8 4.8 0 0 1 0-6.8Z"></path>
                                <path d="m9.6 13.7 4.8-4.8"></path>
                            </svg>
                        </span>
                        <p class="stat-label"><?= $escape($medicineMetricLabel) ?></p>
                        <p class="stat-value"><?= $medicineMetric ?></p>
                        <p class="stat-note"><?= $medicineMetricLabel === 'Available medicines' ? 'Currently in stock' : 'In the medicine catalog' ?></p>
                    </article>
                    <article class="stat-card">
                        <span class="stat-icon reservation-icon" aria-hidden="true">▤</span>
                        <p class="stat-label">My reservations</p>
                        <p class="stat-value"><?= $reservationCount ?></p>
                        <p class="stat-note">Linked to your account</p>
                    </article>
                </section>
                </div>

                <div class="dashboard-content-grid">
                    <section class="panel pharmacy-panel recommendation-panel" id="dashboard-pharmacy-list">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow"><?= $hasPharmacyInventory ? 'BASED ON AVAILABLE MEDICINES' : 'PHARMACY DIRECTORY' ?></p>
                                <h2>Recommended for You</h2>
                            </div>
                            <a class="activity-view-all" href="#pharmacies" data-section="pharmacies">View all</a>
                        </div>

                        <?php if ($recommendedPharmacies === []): ?>
                            <div class="empty-state compact-empty">
                                <span class="empty-icon" aria-hidden="true">
                                    <svg class="line-icon" viewBox="0 0 24 24" focusable="false">
                                        <path d="M4 20V9l8-5 8 5v11"></path>
                                        <path d="M8 20v-5h8v5M12 7v4m-2-2h4M3 20h18"></path>
                                    </svg>
                                </span>
                                <h3>No pharmacy listings yet</h3>
                                <p>Pharmacy information will appear here when it is added to the directory.</p>
                            </div>
                        <?php else: ?>
                            <div class="pharmacy-list">
                                <?php foreach ($recommendedPharmacies as $pharmacy): ?>
                                    <article class="pharmacy-row">
                                        <span class="pharmacy-symbol" aria-hidden="true">
                                            <svg class="line-icon" viewBox="0 0 24 24" focusable="false">
                                                <path d="M4 20V9l8-5 8 5v11"></path>
                                                <path d="M8 20v-5h8v5M12 7v4m-2-2h4M3 20h18"></path>
                                            </svg>
                                        </span>
                                        <div class="pharmacy-info">
                                            <h3><?= $escape($pharmacy['pharmacy_name']) ?></h3>
                                            <p><?= $escape($pharmacy['pharmacy_location'] ?? 'Location not provided') ?></p>
                                            <p class="recommendation-stock-count">
                                                <?= (int) ($pharmacy['available_count'] ?? 0) ?> medicines in stock
                                            </p>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <p class="list-footnote">
                                <?= $hasPharmacyInventory
                                    ? 'Ranked by available medicines. Open the pharmacy feed to see every store.'
                                    : 'Open the pharmacy feed to see every store and its medicine listings.' ?>
                            </p>
                        <?php endif; ?>
                    </section>

                    <section class="panel activity-panel">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">YOUR ACTIVITY</p>
                                <h2>Your activity</h2>
                            </div>
                        </div>

                        <div class="activity-summary-grid">
                        <section class="activity-summary-block reservation-panel" id="dashboard-reservation-list">
                            <div class="activity-summary-heading">
                                <h3>Recent reservations</h3>
                                <span class="result-count"><?= $reservationCount ?> total</span>
                            </div>

                        <?php if ($recentReservations === []): ?>
                            <div class="empty-state compact-empty">
                                <span class="empty-icon" aria-hidden="true">▤</span>
                                <h3>No reservations yet</h3>
                                <p>Your recent medicine reservations will show here.</p>
                            </div>
                        <?php else: ?>
                            <div class="reservation-list">
                                <?php foreach ($recentReservations as $reservation): ?>
                                    <?php
                                    $reservationItem = $reservation['item'] ?? null;
                                    if ($reservationItem !== null && strtolower((string) $reservationItemColumn) === 'medicine_id') {
                                        $reservationItem = 'Medicine #' . $reservationItem;
                                    } elseif ($reservationItem === null && isset($reservation['reservation_id'])) {
                                        $reservationItem = 'Reservation #' . $reservation['reservation_id'];
                                    }
                                    $reservationPharmacy = $reservation['pharmacy'] ?? null;
                                    if ($reservationPharmacy !== null && strtolower((string) $reservationPharmacyColumn) === 'pharmacy_id') {
                                        $reservationPharmacy = 'Pharmacy #' . $reservationPharmacy;
                                    }
                                    $reservedAt = '';
                                    if (!empty($reservation['created'])) {
                                        $timestamp = strtotime((string) $reservation['created']);
                                        $reservedAt = $timestamp === false
                                            ? (string) $reservation['created']
                                            : date('M j, Y', $timestamp);
                                    }
                                    ?>
                                    <article class="reservation-row">
                                        <span class="reservation-symbol" aria-hidden="true">
                                            <svg class="line-icon" viewBox="0 0 24 24" focusable="false">
                                                <path d="M7.2 4.5a4.8 4.8 0 0 1 6.8 0l5.5 5.5a4.8 4.8 0 0 1-6.8 6.8l-5.5-5.5a4.8 4.8 0 0 1 0-6.8Z"></path>
                                                <path d="m9.6 13.7 4.8-4.8"></path>
                                            </svg>
                                        </span>
                                        <div class="reservation-info">
                                            <h3><?= $escape($reservationItem ?? 'Medicine reservation') ?></h3>
                                            <p><?= $escape($reservationPharmacy ?? 'Pharmacy not specified') ?></p>
                                            <?php if ($reservedAt !== ''): ?>
                                                <p class="reservation-date"><?= $escape($reservedAt) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <span class="status-badge"><?= $escape($reservation['status'] ?? 'Reserved') ?></span>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                        <section class="activity-summary-block pharmacy-visits-panel" aria-labelledby="pharmacyVisitsHeading">
                            <div class="activity-summary-heading">
                                <h3 id="pharmacyVisitsHeading">Recent pharmacy visits</h3>
                                <a class="activity-view-all" href="#pharmacies" data-section="pharmacies">View all</a>
                            </div>

                        <?php if ($recentPharmacyVisits === []): ?>
                            <div class="empty-state compact-empty" id="pharmacyVisitsEmpty">
                                <span class="empty-icon" aria-hidden="true"><?= $locationIcon() ?></span>
                                <h3>No pharmacy visits yet</h3>
                                <p>Pharmacies you open from the pharmacy feed will appear here.</p>
                            </div>
                            <div class="reservation-list" id="pharmacyVisitsList" hidden></div>
                        <?php else: ?>
                            <div class="reservation-list" id="pharmacyVisitsList">
                                <?php foreach ($recentPharmacyVisits as $visit): ?>
                                    <?php
                                    $visitTimestamp = strtotime((string) ($visit['visited_at'] ?? ''));
                                    $visitDateLabel = $visitTimestamp === false
                                        ? ''
                                        : date('M j, Y g:i A', $visitTimestamp);
                                    ?>
                                    <article class="pharmacy-row" data-pharmacy-visit-id="<?= $escape($visit['pharmacy_id']) ?>">
                                        <span class="pharmacy-symbol" aria-hidden="true"><?= $locationIcon() ?></span>
                                        <div class="pharmacy-info">
                                            <h3><?= $escape($visit['pharmacy_name']) ?></h3>
                                            <p><?= $escape($visit['pharmacy_location'] ?? 'Location not provided') ?></p>
                                            <?php if ($visitDateLabel !== ''): ?>
                                                <p class="reservation-date"><?= $escape($visitDateLabel) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        </section>
                        </div>
                    </section>
                </div>
                <section class="panel price-compare-panel" aria-labelledby="priceCompareHeading">
                    <div>
                        <p class="eyebrow">SHOP WITH CONFIDENCE</p>
                        <h2 id="priceCompareHeading">Compare Medicine Prices</h2>
                        <p>Compare the same medicine across pharmacies. See the pharmacy, price, stock, and location for each matching listing.</p>
                    </div>
                    <button class="primary-button price-compare-button" id="compareMedicinePricesButton" type="button">Search medicine</button>
                </section>
                <aside class="dashboard-before-visit" aria-label="Before you visit">
                    <strong>Before you visit</strong>
                    <p>Medicine availability and prices may change. Contact the pharmacy to confirm before traveling.</p>
                </aside>
                </div>

                <section class="panel feature-panel pharmacy-feature" id="pharmacies" data-feature-panel="pharmacies" hidden>
                    <header class="pharmacy-page-heading">
                        <p class="eyebrow">PHARMACIES</p>
                        <h1>Find Nearby Pharmacies</h1>
                        <p>Discover nearby pharmacies and explore the medicines they offer.</p>
                    </header>

                    <div class="pharmacy-toolbar">
                        <div class="pharmacy-search-container">
                            <label class="pharmacy-search">
                                <svg aria-hidden="true" viewBox="0 0 20 20" focusable="false">
                                    <circle cx="8.8" cy="8.8" r="5.8"></circle>
                                    <path d="m13.2 13.2 4 4"></path>
                                </svg>
                                <input
                                    id="pharmacySearch"
                                    type="search"
                                    placeholder="Search pharmacy..."
                                    aria-label="Search pharmacy"
                                    autocomplete="off"
                                >
                            </label>
                        </div>
                        <div class="pharmacy-filters" role="group" aria-label="Filter pharmacies">
                            <button class="pharmacy-filter is-active" type="button" data-pharmacy-filter="all" aria-pressed="true">All</button>
                            <button class="pharmacy-filter" type="button" data-pharmacy-filter="nearest" aria-pressed="false">Nearest</button>
                            <button class="pharmacy-filter" type="button" data-pharmacy-filter="with-medicine" aria-pressed="false">With Medicine</button>
                        </div>
                    </div>
                    <p class="pharmacy-location-status" id="pharmacyLocationStatus" role="status" aria-live="polite" hidden></p>

                    <dialog id="medicineAvailability" class="medicine-availability" aria-labelledby="selectedMedicineName">
                        <header class="medicine-availability-heading">
                            <div class="medicine-availability-heading-copy">
                                <p class="eyebrow">MEDICINE AVAILABILITY</p>
                                <h2 id="selectedMedicineName"></h2>
                                <p id="medicineAvailabilityStatus" role="status" aria-live="polite"></p>
                            </div>
                            <button class="pharmacy-location-close" id="medicineAvailabilityBack" type="button" aria-label="Close and return to pharmacy feed">
                                <span aria-hidden="true">×</span>
                            </button>
                        </header>
                        <div class="medicine-availability-filters" role="group" aria-label="Filter pharmacies with this medicine">
                            <button class="pharmacy-filter is-active" type="button" data-pharmacy-filter="all" aria-pressed="true">All</button>
                            <button class="pharmacy-filter" type="button" data-pharmacy-filter="nearest" aria-pressed="false">Nearest</button>
                        </div>
                        <div id="medicineAvailabilityResults" class="medicine-availability-results"></div>
                    </dialog>

                    <div class="pharmacy-explorer">
                        <div class="pharmacy-results">
                            <p class="pharmacy-results-count" id="pharmacyResultsCount" aria-live="polite">
                                <?= count($pharmacyDirectory) ?> pharmacies
                            </p>
                            <?php if ($pharmacyDirectory === []): ?>
                                <div class="empty-state">
                                    <span class="empty-icon" aria-hidden="true">
                                        <svg class="line-icon" viewBox="0 0 24 24" focusable="false">
                                            <path d="M4 20V9l8-5 8 5v11"></path>
                                            <path d="M8 20v-5h8v5M12 7v4m-2-2h4M3 20h18"></path>
                                        </svg>
                                    </span>
                                    <h2>No pharmacy listings yet</h2>
                                    <p>Pharmacy information will appear here when it is added to the directory.</p>
                                </div>
                            <?php else: ?>
                                <div class="pharmacy-cards" id="pharmacyCards">
                                    <?php foreach ($pharmacyDirectory as $pharmacy): ?>
                                        <?php
                                        $pharmacyId = (string) $pharmacy['pharmacy_id'];
                                        $isDemoPharmacy = in_array(
                                            strtolower(trim((string) $pharmacy['pharmacy_name'])),
                                            ['rose pharmacy', 'watsons pharmacy', 'rojon pharmacy'],
                                            true
                                        );
                                        $inventory = $pharmacyInventory[$pharmacyId] ?? [];
                                        $availableCount = count(array_filter(
                                            $inventory,
                                            static fn (array $medicine): bool => in_array($medicine['status'], ['available', 'limited'], true)
                                        ));
                                        $hasCoordinates = isset($pharmacy['latitude'], $pharmacy['longitude'])
                                            && is_numeric($pharmacy['latitude'])
                                            && is_numeric($pharmacy['longitude'])
                                            && (float) $pharmacy['latitude'] >= -90
                                            && (float) $pharmacy['latitude'] <= 90
                                            && (float) $pharmacy['longitude'] >= -180
                                            && (float) $pharmacy['longitude'] <= 180;
                                        $searchTerms = [
                                            $pharmacy['pharmacy_name'],
                                            $pharmacy['pharmacy_location'] ?? ''
                                        ];
                                        $pharmacyImagesByBrand = [
                                            'rose' => 'Images/Rose-Pharmacy-1.jpg',
                                            'watsons' => 'Images/watsons.jpeg',
                                            'rojon' => 'Images/rojon.jpg',
                                            'healthplus' => 'Images/HealthPlus.jpg',
                                            'medicare' => 'Images/MediCare.jpg',
                                            'wellness' => 'Images/Wellness.jpg'
                                        ];
                                        $pharmacyName = strtolower(trim((string) $pharmacy['pharmacy_name']));
                                        $pharmacyImage = null;
                                        foreach ($pharmacyImagesByBrand as $brand => $imagePath) {
                                            if (preg_match('/\b' . preg_quote($brand, '/') . '\b/i', $pharmacyName)) {
                                                $pharmacyImage = $imagePath;
                                                break;
                                            }
                                        }
                                        if ($pharmacyImage !== null && !is_file(__DIR__ . '/' . $pharmacyImage)) {
                                            $pharmacyImage = null;
                                        }
                                        if ($pharmacyImage === null) {
                                            foreach (['jpg', 'jpeg', 'png', 'webp'] as $imageExtension) {
                                                if (!preg_match('/\A[a-zA-Z0-9_-]+\z/D', $pharmacyId)) {
                                                    break;
                                                }
                                                $imageCandidate = 'Images/pharmacies/' . rawurlencode($pharmacyId) . '.' . $imageExtension;
                                                if (is_file(__DIR__ . '/Images/pharmacies/' . $pharmacyId . '.' . $imageExtension)) {
                                                    $pharmacyImage = $imageCandidate;
                                                    break;
                                                }
                                            }
                                        }
                                        ?>
                                        <article
                                            class="pharmacy-card"
                                            id="pharmacy-card-<?= $escape($pharmacyId) ?>"
                                            data-pharmacy-card
                                            data-pharmacy-id="<?= $escape($pharmacyId) ?>"
                                            data-pharmacy-name="<?= $escape($pharmacy['pharmacy_name']) ?>"
                                            data-has-medicine="<?= $availableCount > 0 ? 'true' : 'false' ?>"
                                            data-search="<?= $escape(implode(' ', $searchTerms)) ?>"
                                            data-has-coordinates="<?= $hasCoordinates ? 'true' : 'false' ?>"
                                            <?php if ($hasCoordinates): ?>
                                                data-latitude="<?= $escape($pharmacy['latitude']) ?>"
                                                data-longitude="<?= $escape($pharmacy['longitude']) ?>"
                                            <?php endif; ?>
                                        >
                                            <div class="pharmacy-card-banner">
                                                <?php if ($pharmacyImage !== null): ?>
                                                    <img src="<?= $escape($pharmacyImage) ?>" alt="<?= $escape($pharmacy['pharmacy_name']) ?>" loading="lazy">
                                                <?php else: ?>
                                                    <span class="pharmacy-card-symbol" aria-hidden="true">
                                                        <svg viewBox="0 0 24 24" focusable="false"><path d="M4 20V9l8-5 8 5v11M8 20v-5h8v5M12 7v4m-2-2h4M3 20h18"></path></svg>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="pharmacy-card-content">
                                                <div class="pharmacy-card-title">
                                                    <strong><?= $escape($pharmacy['pharmacy_name']) ?></strong>
                                                    <span><?= $escape($pharmacy['pharmacy_location'] ?? 'Location not provided') ?></span>
                                                </div>
                                                <?php if (!empty($pharmacy['description'])): ?>
                                                    <p class="pharmacy-card-description"><?= $escape($pharmacy['description']) ?></p>
                                                <?php endif; ?>
                                                <div class="pharmacy-card-facts">
                                                    <span data-pharmacy-distance>Distance unavailable</span>
                                                    <span class="pharmacy-availability <?= $availableCount > 0 ? 'has-stock' : '' ?>">
                                                        <?= $hasPharmacyInventory ? $availableCount . ' available medicines' : 'Inventory unavailable' ?>
                                                    </span>
                                                    <?php if (!empty($pharmacy['hours'])): ?><span><?= $escape($pharmacy['hours']) ?></span><?php endif; ?>
                                                </div>
                                                <div class="pharmacy-card-actions">
                                                    <button
                                                        type="button"
                                                        class="pharmacy-action-button"
                                                        data-view-pharmacy
                                                    >View Pharmacy</button>
                                                    <button
                                                        type="button"
                                                        class="pharmacy-action-button is-secondary"
                                                        data-view-location
                                                        data-pharmacy-name="<?= $escape($pharmacy['pharmacy_name']) ?>"
                                                        data-pharmacy-address="<?= $escape($pharmacy['pharmacy_location'] ?? 'Location not provided') ?>"
                                                        data-pharmacy-latitude="<?= $hasCoordinates ? $escape($pharmacy['latitude']) : '' ?>"
                                                        data-pharmacy-longitude="<?= $hasCoordinates ? $escape($pharmacy['longitude']) : '' ?>"
                                                    >View Location</button>
                                                </div>
                                            </div>
                                            <div class="pharmacy-card-details" data-pharmacy-details hidden>
                                                <div class="pharmacy-contact-row">
                                                    <span class="pharmacy-address"><?= $locationIcon() ?><?= $escape($pharmacy['pharmacy_location'] ?? 'Location not provided') ?></span>
                                                    <span class="pharmacy-distance">Distance unavailable</span>
                                                    <?php if (!empty($pharmacy['pharmacy_phone'])): ?>
                                                        <a href="tel:<?= $escape($pharmacy['pharmacy_phone']) ?>">☎ <?= $escape($pharmacy['pharmacy_phone']) ?></a>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="pharmacy-medicine-heading">
                                                    <h2>Medicine Availability</h2>
                                                    <span>
                                                        <?= $hasPharmacyInventory
                                                            ? count($inventory) . ' medicines listed'
                                                            : 'Availability not provided' ?>
                                                    </span>
                                                </div>
                                                <?php if ($isDemoPharmacy): ?>
                                                    <p class="pharmacy-demo-note">Sample quantities and prices are for demonstration only and are not verified pharmacy information.</p>
                                                <?php endif; ?>
                                                <?php if (!$hasPharmacyInventory): ?>
                                                    <p class="pharmacy-inventory-note">Medicine availability is not configured for this pharmacy.</p>
                                                <?php elseif ($inventory === []): ?>
                                                    <p class="pharmacy-inventory-note">No medicine listings are available for this pharmacy.</p>
                                                <?php else: ?>
                                                    <label class="pharmacy-medicine-search">
                                                        <span>Search medicines at this pharmacy</span>
                                                        <input
                                                            type="search"
                                                            placeholder="Search medicine..."
                                                            aria-label="Search medicines at <?= $escape($pharmacy['pharmacy_name']) ?>"
                                                            data-pharmacy-medicine-search
                                                            autocomplete="off"
                                                        >
                                                    </label>
                                                    <ul class="pharmacy-medicine-list" data-pharmacy-medicine-results hidden>
                                                        <?php foreach ($inventory as $medicine): ?>
                                                            <li
                                                                class="pharmacy-medicine-item"
                                                                data-pharmacy-medicine-item
                                                                data-medicine-name="<?= $escape($medicine['name']) ?>"
                                                            >
                                                                <div class="pharmacy-medicine-item-info">
                                                                    <span class="pharmacy-medicine-name">
                                                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                                            <path d="M7.2 4.5a4.8 4.8 0 0 1 6.8 0l5.5 5.5a4.8 4.8 0 0 1-6.8 6.8L7.2 11.3a4.8 4.8 0 0 1 0-6.8Z"></path>
                                                                            <path d="m9.6 13.7 4.8-4.8"></path>
                                                                        </svg>
                                                                        <?= $escape($medicine['name']) ?>
                                                                    </span>
                                                                    <span class="medicine-status status-<?= $escape($medicine['status']) ?>">
                                                                        <?= $medicine['status'] === 'limited'
                                                                            ? 'Limited'
                                                                            : ($medicine['status'] === 'unavailable' ? 'Out of stock' : 'Available') ?>
                                                                    </span>
                                                                    <?php if ($medicine['quantity'] !== null || $medicine['price'] !== null): ?>
                                                                        <small class="pharmacy-medicine-meta">
                                                                            <?php if ($medicine['quantity'] !== null): ?>
                                                                                <?= $isDemoPharmacy ? 'Sample stock' : 'Stock' ?>: <?= $escape((string) $medicine['quantity']) ?>
                                                                            <?php endif; ?>
                                                                            <?php if ($medicine['price'] !== null): ?>
                                                                                <?= $medicine['quantity'] !== null ? ' · ' : '' ?><?= $isDemoPharmacy ? 'Sample price' : 'Price' ?>: ₱<?= $escape(number_format($medicine['price'], 2)) ?>
                                                                            <?php endif; ?>
                                                                        </small>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <?php if (
                                                                    in_array($medicine['status'], ['available', 'limited'], true)
                                                                    && is_int($medicine['quantity'])
                                                                    && $medicine['quantity'] > 0
                                                                ): ?>
                                                                    <div class="pharmacy-medicine-reservation">
                                                                        <label>
                                                                            <span class="visually-hidden">Quantity for <?= $escape($medicine['name']) ?></span>
                                                                            <span class="pharmacy-quantity-stepper">
                                                                                <button type="button" data-quantity-step="-1" aria-label="Decrease quantity of <?= $escape($medicine['name']) ?>">−</button>
                                                                                <input
                                                                                    type="number"
                                                                                    min="1"
                                                                                    max="<?= $escape((string) $medicine['quantity']) ?>"
                                                                                    value="1"
                                                                                    inputmode="numeric"
                                                                                    aria-label="Quantity of <?= $escape($medicine['name']) ?> to reserve"
                                                                                >
                                                                                <button type="button" data-quantity-step="1" aria-label="Increase quantity of <?= $escape($medicine['name']) ?>">+</button>
                                                                            </span>
                                                                        </label>
                                                                        <button
                                                                            class="reserve-medicine-button"
                                                                            type="button"
                                                                            data-reserve-medicine="<?= $escape($medicine['id']) ?>"
                                                                            data-reserve-pharmacy="<?= $escape($pharmacyId) ?>"
                                                                        >Reserve</button>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                    <p class="pharmacy-inventory-note" data-pharmacy-medicine-no-results hidden>
                                                        No matching medicines at this pharmacy.
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                                <p class="pharmacy-no-results" id="pharmacyNoResults" hidden>No pharmacies match your search or filter.</p>
                            <?php endif; ?>
                        </div>

                    </div>
                    <dialog class="pharmacy-location-dialog" id="pharmacyLocationDialog" aria-labelledby="pharmacyLocationTitle">
                        <div class="pharmacy-location-dialog-header">
                            <div><p class="eyebrow">PHARMACY LOCATION</p><h2 id="pharmacyLocationTitle"></h2><p id="pharmacyLocationAddress"></p></div>
                            <button type="button" class="pharmacy-location-close" data-close-pharmacy-location aria-label="Back to pharmacies">×</button>
                        </div>
                        <div class="pharmacy-map-controls" aria-label="Map controls">
                            <button type="button" class="pharmacy-map-control-button" id="pharmacyLiveLocationToggle" aria-pressed="false">Start live location</button>
                            <button type="button" class="pharmacy-map-control-button is-secondary" id="pharmacyMapRecenter">Recenter map</button>
                            <p id="pharmacyLiveLocationStatus" role="status" aria-live="polite">Map centered on the selected pharmacy.</p>
                        </div>
                        <div id="pharmacyLocationMap" class="pharmacy-map" role="application" aria-label="Selected pharmacy location"></div>
                        <p class="pharmacy-map-status" id="pharmacyLocationMapStatus" role="status" hidden></p>
                    </dialog>
                    <dialog class="pharmacy-details-dialog" id="pharmacyDetailsDialog" aria-labelledby="pharmacyDetailsTitle">
                        <div class="pharmacy-details-dialog-header">
                            <div>
                                <p class="eyebrow">PHARMACY DETAILS</p>
                                <h2 id="pharmacyDetailsTitle"></h2>
                                <p id="pharmacyDetailsAddress"></p>
                            </div>
                            <button type="button" class="pharmacy-location-close" data-close-pharmacy-details aria-label="Close pharmacy details">Ã—</button>
                        </div>
                        <div class="pharmacy-details-dialog-content">
                            <div class="pharmacy-details-dialog-image" id="pharmacyDetailsImage"></div>
                            <div class="pharmacy-details-dialog-copy">
                                <p id="pharmacyDetailsDescription" hidden></p>
                                <div class="pharmacy-details-dialog-facts" id="pharmacyDetailsFacts"></div>
                                <div class="pharmacy-details-dialog-contact" id="pharmacyDetailsContact" hidden></div>
                                <div class="pharmacy-details-dialog-actions">
                                    <button type="button" class="pharmacy-action-button" data-details-view-location>View Location</button>
                                </div>
                            </div>
                        </div>
                        <div class="pharmacy-details-dialog-inventory" id="pharmacyDetailsInventory"></div>
                    </dialog>
                </section>

                <section class="panel feature-panel reservation-feature" id="reservations" data-feature-panel="reservations" hidden>
                    <header class="reservation-page-heading">
                        <p class="eyebrow">MY RESERVATIONS</p>
                        <h1>Track your medicine reservations</h1>
                        <p>Keep track of your reserved medicines and their status.</p>
                    </header>
                    <input type="hidden" id="reservationCsrfToken" value="<?= $escape($_SESSION['reservation_csrf_token']) ?>">
                    <?php if (!empty($reservationFlash['message'])): ?>
                        <p class="reservation-action-message is-success" role="status"><?= $escape($reservationFlash['message']) ?></p>
                    <?php endif; ?>

                    <?php if ($userReservations === []): ?>
                        <div class="reservation-empty-state">
                            <span class="reservation-empty-icon" aria-hidden="true">▤</span>
                            <h2>No reservations yet</h2>
                            <p>Reserve medicines from nearby pharmacies and they’ll appear here.</p>
                            <a class="reservation-explore-button" href="#pharmacies">Explore Pharmacies</a>
                        </div>
                    <?php else: ?>
                        <div class="reservation-filters" role="group" aria-label="Filter reservations">
                            <button class="reservation-filter is-active" type="button" data-reservation-filter="all" aria-pressed="true">All</button>
                            <button class="reservation-filter" type="button" data-reservation-filter="pending" aria-pressed="false">Pending</button>
                            <button class="reservation-filter" type="button" data-reservation-filter="confirmed" aria-pressed="false">Confirmed</button>
                            <button class="reservation-filter" type="button" data-reservation-filter="ready" aria-pressed="false">Ready</button>
                            <button class="reservation-filter" type="button" data-reservation-filter="completed" aria-pressed="false">Completed</button>
                        </div>
                        <p class="reservation-results-count" id="reservationResultsCount" aria-live="polite">
                            <?= count($userReservations) ?> reservations
                        </p>
                        <div class="reservation-cards" id="reservationCards">
                            <?php foreach ($userReservations as $reservation): ?>
                                <?php
                                $rawStatus = strtolower(trim((string) ($reservation['status'] ?? '')));
                                $normalizedStatus = str_replace(['_', '-'], ' ', $rawStatus);
                                if (
                                    $normalizedStatus === 'pending'
                                    && !empty($reservation['is_expired'])
                                ) {
                                    $normalizedStatus = 'expired';
                                }
                                $statusMap = [
                                    'reserved' => 'pending',
                                    'pending' => 'pending',
                                    'confirmed' => 'confirmed',
                                    'accepted' => 'confirmed',
                                    'ready' => 'ready',
                                    'ready for pickup' => 'ready',
                                    'completed' => 'completed',
                                    'picked up' => 'completed',
                                    'cancelled' => 'cancelled',
                                    'canceled' => 'cancelled',
                                    'expired' => 'expired'
                                ];
                                $statusKey = $statusMap[$normalizedStatus] ?? 'unknown';
                                $statusLabels = [
                                    'pending' => 'Pending',
                                    'confirmed' => 'Confirmed',
                                    'ready' => 'Ready for pickup',
                                    'completed' => 'Completed',
                                    'cancelled' => 'Cancelled',
                                    'expired' => 'Expired',
                                    'unknown' => $reservation['status'] ?? 'Unknown'
                                ];
                                $reservationIdentifier = (string) ($reservation['reservation_id'] ?? '');
                                $reservationDateLabel = 'Date unavailable';
                                if (!empty($reservation['created'])) {
                                    $reservationTimestamp = strtotime((string) $reservation['created']);
                                    $reservationDateLabel = $reservationTimestamp === false
                                        ? (string) $reservation['created']
                                        : date('F j, Y', $reservationTimestamp);
                                }
                                $reservationReference = $reservationIdentifier === ''
                                    ? 'Reference unavailable'
                                    : (ctype_digit($reservationIdentifier)
                                        ? 'MF-' . str_pad($reservationIdentifier, 5, '0', STR_PAD_LEFT)
                                        : $reservationIdentifier);
                                $statusStep = [
                                    'pending' => 0,
                                    'confirmed' => 1,
                                    'ready' => 2,
                                    'completed' => 3
                                ][$statusKey] ?? -1;
                                ?>
                                <details
                                    class="reservation-card"
                                    data-reservation-card
                                    data-reservation-status="<?= $escape($statusKey) ?>"
                                >
                                    <summary class="reservation-card-summary">
                                        <span class="reservation-card-icon" aria-hidden="true">
                                            <svg class="line-icon" viewBox="0 0 24 24" focusable="false">
                                                <path d="M7.2 4.5a4.8 4.8 0 0 1 6.8 0l5.5 5.5a4.8 4.8 0 0 1-6.8 6.8l-5.5-5.5a4.8 4.8 0 0 1 0-6.8Z"></path>
                                                <path d="m9.6 13.7 4.8-4.8"></path>
                                            </svg>
                                        </span>
                                        <span class="reservation-card-title">
                                            <strong><?= $escape(trim(($reservation['item'] ?? 'Medicine not specified') . ' ' . ($reservation['dosage'] ?? ''))) ?></strong>
                                            <span><?= $escape($reservation['pharmacy'] ?? 'Pharmacy not specified') ?></span>
                                            <span><?= $escape($reservation['location'] ?? 'Location not provided') ?></span>
                                        </span>
                                        <span class="reservation-status status-<?= $escape($statusKey) ?>">
                                            <?= $escape($statusLabels[$statusKey] ?? $statusLabels['unknown']) ?>
                                        </span>
                                        <span class="reservation-view-details">View Details</span>
                                    </summary>
                                    <div class="reservation-card-details">
                                        <dl class="reservation-meta">
                                            <div>
                                                <dt>Reservation</dt>
                                                <dd><?= $escape($reservationReference) ?></dd>
                                            </div>
                                            <div>
                                                <dt>Quantity</dt>
                                                <dd><?= isset($reservation['quantity']) && $reservation['quantity'] !== ''
                                                    ? $escape($reservation['quantity'])
                                                    : 'Not provided' ?></dd>
                                            </div>
                                            <div>
                                                <dt>Reserved</dt>
                                                <dd><?= $escape($reservationDateLabel) ?></dd>
                                            </div>
                                            <?php if (isset($reservation['price']) && $reservation['price'] !== ''): ?>
                                                <div>
                                                    <dt>Unit price</dt>
                                                    <dd>₱<?= $escape(number_format((float) $reservation['price'], 2)) ?></dd>
                                                </div>
                                            <?php endif; ?>
                                        </dl>
                                        <?php if ($statusKey === 'pending'): ?>
                                            <button
                                                class="reservation-cancel-button"
                                                type="button"
                                                data-cancel-reservation="<?= $escape($reservationIdentifier) ?>"
                                            >Cancel reservation</button>
                                        <?php endif; ?>
                                        <?php if ($statusStep >= 0): ?>
                                            <h2 class="reservation-timeline-heading">Reservation progress</h2>
                                            <ol class="reservation-timeline" aria-label="Reservation progress">
                                                <?php foreach ([
                                                    'Reserved',
                                                    'Confirmed',
                                                    'Ready for pickup',
                                                    'Completed'
                                                ] as $stepIndex => $stepLabel): ?>
                                                    <li class="<?= $stepIndex < $statusStep ? 'is-complete' : '' ?> <?= $stepIndex === $statusStep ? 'is-current' : '' ?>">
                                                        <span aria-hidden="true"></span>
                                                        <strong><?= $escape($stepLabel) ?></strong>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ol>
                                        <?php elseif ($statusKey === 'cancelled'): ?>
                                            <p class="reservation-cancelled-note">This reservation was cancelled.</p>
                                        <?php else: ?>
                                            <p class="reservation-cancelled-note">A progress timeline is unavailable for this status.</p>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </div>
                        <p class="reservation-no-results" id="reservationNoResults" hidden>No reservations match this status.</p>
                    <?php endif; ?>
                </section>

                <section class="panel feature-panel" id="notifications" data-feature-panel="notifications" hidden>
                    <input type="hidden" id="notificationsCsrfToken" value="<?= $escape($_SESSION['notifications_csrf_token']) ?>">
                    <header class="notification-page-heading">
                        <div class="notification-page-title">
                            <a class="notification-back-link" href="#dashboard" data-section="dashboard" data-notification-back aria-label="Back to dashboard">
                                <svg aria-hidden="true" width="13" height="13" viewBox="0 0 20 20" fill="none" stroke="#748094" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"><path d="M12.5 4.5 7 10l5.5 5.5M7.5 10h9"></path></svg>
                                <span>Back to dashboard</span>
                            </a>
                            <p class="eyebrow">NOTIFICATIONS</p>
                            <h1>Stay up to date</h1>
                            <p>Review updates about your reservations.</p>
                        </div>
                        <?php if ($notificationUnreadCount > 0): ?>
                            <button class="mark-notifications-read" id="markNotificationsRead" type="button">
                                Mark all as read
                            </button>
                        <?php endif; ?>
                    </header>
                    <p id="notificationActionStatus" class="notification-action-status" role="status" aria-live="polite"></p>

                    <?php if ($notificationRows === []): ?>
                        <div class="security-history-empty">
                            <span class="security-history-empty-icon" aria-hidden="true">✓</span>
                            <div>
                                <h3>No notifications yet</h3>
                                <p>Reservation updates will appear here.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notificationGroups as $groupName => $groupNotifications): ?>
                            <?php if ($groupNotifications !== []): ?>
                                <section class="notification-group" aria-label="<?= $escape($groupName) ?> notifications">
                                    <h3 class="notification-group-title"><?= $escape($groupName) ?></h3>
                                    <div class="notification-list">
                                        <?php foreach ($groupNotifications as $notification): ?>
                                            <?php
                                            $isUnread = (int) $notification['is_read'] === 0;
                                            $notificationType = (string) $notification['type'];
                                            $notificationIcon = $notificationType === 'reservation_cancelled' ? '×' : '✓';
                                            ?>
                                            <article
                                                class="notification-item<?= $isUnread ? ' is-unread' : '' ?>"
                                                data-notification-item
                                                data-notification-id="<?= (int) $notification['id'] ?>"
                                                data-notification-unread="<?= $isUnread ? 'true' : 'false' ?>"
                                            >
                                                <span class="notification-unread-dot"<?= $isUnread ? ' aria-label="Unread"' : ' aria-hidden="true"' ?>></span>
                                                <span class="notification-icon reservation-notification" aria-hidden="true"><?= $notificationIcon ?></span>
                                                <div class="notification-content">
                                                    <h4><?= $escape($notification['title']) ?></h4>
                                                    <p><?= $escape($notification['message']) ?></p>
                                                    <?php if ($isUnread): ?>
                                                        <button class="notification-read-button" type="button" data-mark-notification-read>
                                                            Mark as read
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="notification-read-label">Read</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php $notificationTimestamp = strtotime((string) $notification['created_at']); ?>
                                                <time class="notification-time" datetime="<?= $notificationTimestamp === false ? '' : $escape(date(DATE_ATOM, $notificationTimestamp)) ?>">
                                                    <?= $escape($formatNotificationAge((string) $notification['created_at'])) ?>
                                                </time>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <section class="panel feature-panel security-feature" id="security" data-feature-panel="security" hidden>
                    <header class="security-page-heading">
                        <p class="eyebrow">SECURITY ACTIVITY</p>
                        <h1>Keep your account secure</h1>
                        <p>Monitor recent login activity and review the protections on your MediFind account.</p>
                    </header>

                    <div class="security-summary-grid">
                        <article class="security-summary-card">
                            <span class="security-summary-icon" aria-hidden="true"><svg class="security-shield-icon" viewBox="0 0 180 180" focusable="false"><path class="security-shield-outline" d="M90 15c18 13 37 20 57 21 2 43-7 91-57 123C40 127 31 79 33 36c20-1 39-8 57-21Z"/><path class="security-shield-fill" d="M90 35c14 10 29 15 43 17 0 34-7 69-43 94-36-25-43-60-43-94 14-2 29-7 43-17Z"/><path class="security-shield-check" d="m66 87 17 17 33-35"/></svg></span>
                            <div>
                                <p class="eyebrow">ACCOUNT STATUS</p>
                                <?php
                                $accountIsActive = !empty($accountSecurity['is_active']);
                                $hasPasswordHash = !empty($accountSecurity['password_hash'])
                                    && password_get_info((string) $accountSecurity['password_hash'])['algo'] !== null;
                                $accountSecure = $accountIsActive && $hasPasswordHash;
                                ?>
                                <h2 class="<?= $accountSecure ? 'security-state-secure' : 'security-state-warning' ?>">
                                    <?= $accountSecure ? 'Secure' : 'Needs attention' ?>
                                </h2>
                                <p><?= $accountIsActive ? 'Account is active' : 'Account is inactive' ?></p>
                            </div>
                        </article>
                        <article class="security-summary-card">
                            <span class="security-summary-icon" aria-hidden="true"><svg class="security-shield-icon" viewBox="0 0 180 180" focusable="false"><path class="security-shield-outline" d="M90 15c18 13 37 20 57 21 2 43-7 91-57 123C40 127 31 79 33 36c20-1 39-8 57-21Z"/><path class="security-shield-fill" d="M90 35c14 10 29 15 43 17 0 34-7 69-43 94-36-25-43-60-43-94 14-2 29-7 43-17Z"/><path class="security-shield-check" d="m66 87 17 17 33-35"/></svg></span>
                            <div>
                                <p class="eyebrow">LOGIN PROTECTION</p>
                                <h2 class="security-state-secure">Active</h2>
                                <p>Failed-attempt monitoring and temporary lockout</p>
                            </div>
                        </article>
                    </div>

                    <section class="security-activity-section" aria-labelledby="recentSecurityActivity">
                        <div class="security-section-heading">
                            <div>
                                <p class="eyebrow">ACCOUNT MONITORING</p>
                                <h2 id="recentSecurityActivity">Recent security activity</h2>
                            </div>
                            <?php if ($isAdmin): ?>
                                <a class="security-activity-count" href="security.php">View system security logs</a>
                            <?php endif; ?>
                            <?php if ($securityLogsReady): ?>
                                <span class="security-activity-count">
                                    <?= $recentFailedAttempts ?> unsuccessful <?= $recentFailedAttempts === 1 ? 'attempt' : 'attempts' ?> in the last 24 hours
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!$securityLogsReady): ?>
                            <div class="security-history-notice" role="status">
                                <strong>Security log history is not configured yet.</strong>
                                <span>Apply <code>sql/create_security_logs.sql</code> to record future security events.</span>
                            </div>
                        <?php elseif ($securityEvents === []): ?>
                            <div class="security-history-empty">
                                <span class="security-history-empty-icon" aria-hidden="true">✓</span>
                                <div>
                                    <h3>No security activity recorded yet</h3>
                                    <p>Login events will appear here after your next sign-in.</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="security-event-list">
                                <?php foreach ($securityEvents as $securityEvent): ?>
                                    <?php
                                    $securityEventDetails = [
                                        'LOGIN_SUCCESS' => [
                                            'title' => 'Successful login',
                                            'icon' => '✓',
                                            'style' => 'success'
                                        ],
                                        'LOGIN_FAILED' => [
                                            'title' => 'Failed login attempt',
                                            'icon' => '!',
                                            'style' => 'warning'
                                        ],
                                        'ACCOUNT_LOCKED' => [
                                            'title' => 'Account temporarily locked',
                                            'icon' => '×',
                                            'style' => 'danger'
                                        ],
                                        'LOGIN_BLOCKED' => [
                                            'title' => 'Login blocked',
                                            'icon' => '!',
                                            'style' => 'warning'
                                        ],
                                        'LOGOUT' => ['title' => 'Logout', 'icon' => '↗', 'style' => 'neutral'],
                                        'REGISTRATION' => ['title' => 'Registration', 'icon' => '+', 'style' => 'success'],
                                        'PASSWORD_CHANGE' => ['title' => 'Password changed', 'icon' => '✓', 'style' => 'success'],
                                        'UNAUTHORIZED_ACCESS' => ['title' => 'Unauthorized access attempt', 'icon' => '!', 'style' => 'danger'],
                                        'ADMIN_ACCESS' => ['title' => 'Admin access', 'icon' => '◇', 'style' => 'neutral']
                                    ];
                                    $eventType = (string) ($securityEvent['activity'] ?? '');
                                    $eventDetails = $securityEventDetails[$eventType] ?? [
                                        'title' => 'Security event',
                                        'icon' => '•',
                                        'style' => 'neutral'
                                    ];
                                    $eventTimestamp = strtotime((string) ($securityEvent['created_at'] ?? ''));
                                    ?>
                                    <article class="security-event">
                                        <span class="security-event-icon is-<?= $escape($eventDetails['style']) ?>" aria-hidden="true">
                                            <?= $escape($eventDetails['icon']) ?>
                                        </span>
                                        <div class="security-event-copy">
                                            <h3><?= $escape($eventDetails['title']) ?></h3>
                                            <p><?= $escape($securityEvent['description'] ?? 'Security event recorded.') ?></p>
                                            <?php if (!empty($securityEvent['ip_address'])): ?>
                                                <p>IP address: <?= $escape($securityEvent['ip_address']) ?></p>
                                            <?php endif; ?>
                                            <?php if ($eventTimestamp !== false): ?>
                                                <time datetime="<?= $escape(date(DATE_ATOM, $eventTimestamp)) ?>">
                                                    <?= $escape(date('F j, Y \a\t g:i A', $eventTimestamp)) ?>
                                                </time>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="security-protection-section" aria-labelledby="loginProtectionDetails">
                        <div class="security-section-heading">
                            <div>
                                <p class="eyebrow">PROTECTION</p>
                                <h2 id="loginProtectionDetails">Login protection</h2>
                            </div>
                        </div>
                        <div class="security-protection-list">
                            <div><span aria-hidden="true">✓</span><p>Password is stored using a one-way hash</p></div>
                            <div><span aria-hidden="true">✓</span><p>Login attempt monitoring is enabled</p></div>
                            <div><span aria-hidden="true">✓</span><p>Temporary account lockout is enabled</p></div>
                        </div>
                        <?php if (!empty($accountSecurity['locked_until']) && strtotime((string) $accountSecurity['locked_until']) > time()): ?>
                            <p class="security-lockout-warning">
                                Your account is temporarily locked until <?= $escape(date('F j, Y \a\t g:i A', strtotime((string) $accountSecurity['locked_until']))) ?>.
                            </p>
                        <?php endif; ?>
                    </section>
                </section>

                <section class="panel feature-panel" id="profile" data-feature-panel="profile" hidden>
                    <header class="profile-page-heading">
    <div class="profile-page-heading-copy">
        <p class="eyebrow">MY PROFILE</p>
        <h1>Personal information and account</h1>
        <p>Manage your personal information and review your account security.</p>
    </div>
    <button class="profile-edit-button" id="editProfileButton" type="button" aria-expanded="<?= $profileErrors === [] ? 'false' : 'true' ?>" aria-controls="profileEditModal">Edit Profile</button>
</header>

                    <?php if ($profileNotice !== ''): ?>
                        <p class="profile-feedback is-success" role="status"><?= $escape($profileNotice) ?></p>
                    <?php endif; ?>
                    <?php if ($profileErrors !== []): ?>
                        <div class="profile-feedback is-error" role="alert">
                            <strong>Please review the following:</strong>
                            <ul>
                                <?php foreach ($profileErrors as $profileError): ?>
                                    <li><?= $escape($profileError) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <section class="profile-hero" aria-label="Profile summary">
                        <span class="profile-hero-avatar" aria-hidden="true">
                            <?php if ($profilePhotoUrl !== null): ?>
                                <img src="<?= $escape($profilePhotoUrl) ?>" alt="">
                            <?php else: ?>
                                <?= $escape(strtoupper(substr($profileName, 0, 1))) ?>
                            <?php endif; ?>
                        </span>
                        <div class="profile-hero-copy">
                            <h2><?= $escape($profileName) ?></h2>
                            <p>@<?= $escape($profileUsername) ?></p>
                            <span class="profile-account-status <?= !empty($profileUser['is_active']) ? 'is-active' : 'is-inactive' ?>">
                                <i aria-hidden="true"></i>
                                <?= !empty($profileUser['is_active']) ? 'Active account' : 'Inactive account' ?>
                            </span>
                        </div>
                    </section>
<section class="profile-section" aria-labelledby="personalInformationHeading">
                        <div class="profile-section-heading">
                            <div>
                                <p class="eyebrow">YOUR DETAILS</p>
                                <h2 id="personalInformationHeading">Personal information</h2>
                            </div>
                        </div>
                        <dl class="profile-information-grid">
                            <div>
                                <dt>Full name</dt>
                                <dd><?= $escape($profileName) ?></dd>
                            </div>
                            <div>
                                <dt>Username</dt>
                                <dd><?= $escape($profileUsername) ?></dd>
                            </div>
                            <div>
                                <dt>Email</dt>
                                <dd><?= $escape($profileUser['email'] ?? 'Not provided') ?></dd>
                            </div>
                            <div>
                                <dt>Phone number</dt>
                                <dd><?= $escape($profileUser['phone'] ?? 'Not provided') ?></dd>
                            </div>
                        </dl>

                        <div class="profile-edit-modal" id="profileEditModal" <?= $profileErrors === [] ? 'hidden' : '' ?>>
    <div class="profile-edit-modal-panel" role="dialog" aria-modal="true" aria-labelledby="editProfileHeading">
    <aside class="profile-edit-photo-panel">
    <div class="profile-edit-photo">
        <button class="profile-edit-photo-trigger" id="profilePhotoActionsToggle" type="button" aria-expanded="false" aria-controls="profilePhotoActions" aria-label="Photo options">
            <span class="profile-edit-photo-image">
                <?php if ($profilePhotoUrl !== null): ?>
                    <img src="<?= $escape($profilePhotoUrl) ?>" alt="">
                <?php else: ?>
                    <span><?= $escape(strtoupper(substr($profileName, 0, 1))) ?></span>
                <?php endif; ?>
            </span>
            <span class="profile-edit-photo-overlay">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 7h3l1.5-2h7L17 7h3v12H4z"></path><circle cx="12" cy="13" r="3.5"></circle></svg>
                <span>Photo options</span>
            </span>
        </button>
        <div class="profile-edit-photo-menu" id="profilePhotoActions" hidden>
            <label for="profilePhotoInput">Change photo</label>
            <?php if ($profilePhotoFile !== null): ?>
                <form class="profile-photo-remove-form" method="post" action="dashboard.php#profile">
                    <input type="hidden" name="action" value="remove_profile_photo">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['profile_csrf_token']) ?>">
                    <button type="submit">Remove photo</button>
                </form>
            <?php endif; ?>
        </div>
        <input id="profilePhotoInput" class="profile-edit-photo-input" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" form="editProfileForm">
    </div>
</aside>
    <div class="profile-edit-modal-main">
        <header class="profile-edit-modal-heading">
            <div><p class="eyebrow">MY PROFILE</p><h2 id="editProfileHeading">Account Details</h2></div>
            <button class="profile-edit-modal-close" id="closeProfileEdit" type="button" aria-label="Close edit profile">&times;</button>
        </header>
        <form class="profile-edit-form" id="editProfileForm" method="post" enctype="multipart/form-data" action="dashboard.php#profile">
                            <input type="hidden" name="action" value="update_profile">
                            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['profile_csrf_token']) ?>">
                            <div class="profile-form-grid">
                                <label>
                                    Full name
                                    <input type="text" name="full_name" maxlength="150" autocomplete="name" required value="<?= $escape($profileDisplayName) ?>">
                                </label>
                                <label>
                                    Username
                                    <input type="text" value="<?= $escape($profileUsername) ?>" autocomplete="username" readonly aria-describedby="profileUsernameNote">
                                    <small id="profileUsernameNote">Username cannot be changed here.</small>
                                </label>
                                <label>
                                    Email
                                    <input type="email" name="email" maxlength="254" autocomplete="email" required value="<?= $escape($profileEmail) ?>">
                                </label>
                                <label>
                                    Phone number
                                    <input type="tel" name="phone" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" autocomplete="tel" required value="<?= $escape($profilePhone) ?>">
                                </label>
                            </div>
                            <div class="profile-form-actions">
                                <button class="profile-cancel-button" id="cancelProfileEdit" type="button">Cancel</button>
                                <button class="profile-edit-button" type="submit">Save changes</button>
                            </div>
                        </form>
                        
                    </div>
                    </div>
                </div>
                    </section>

                    <section class="profile-section profile-security-section" aria-labelledby="profileSecurityHeading">
                        <div class="profile-section-heading">
                            <div>
                                <p class="eyebrow">ACCOUNT &amp; SECURITY</p>
                                <h2 id="profileSecurityHeading">Account protection</h2>
                            </div>
                        </div>
                        <div class="profile-security-row">
                            <span class="profile-security-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path class="profile-shield-outline" d="M12 2.5c2.7 2 5.7 3 8.3 3.3 0 8-1.9 13-8.3 16-6.4-3-8.3-8-8.3-16 2.6-.3 5.6-1.3 8.3-3.3Z"></path><path class="profile-lock-shackle" d="M10 10V8.5a2 2 0 0 1 4 0V10"></path><rect class="profile-lock-body" x="8.5" y="10" width="7" height="6.5" rx="1.1"></rect><circle class="profile-lock-keyhole" cx="12" cy="12.4" r="0.7"></circle><path class="profile-lock-keyhole" d="M12 13.1v1.4"></path></svg></span>
                            <div>
                                <h3>Password protection</h3>
                                <p>Your password is stored as a one-way hash.</p>
                            </div>
                            <a href="#security" class="profile-security-link" data-profile-security-link>View security activity</a>
                        </div>
                    </section>
                </section>

                <section class="panel feature-panel settings-feature" id="settings" data-feature-panel="settings" data-settings-user-id="<?= $userId ?>" hidden>
                    <header class="settings-page-heading">
                        <p class="eyebrow">SETTINGS</p>
                        <h1>Manage your preferences</h1>
                        <p>Choose how MediFind looks, handles location access, and protects your account.</p>
                    </header>

                    <?php if ($settingsNotice !== ''): ?>
                        <p class="settings-feedback is-success" role="status"><?= $escape($settingsNotice) ?></p>
                    <?php endif; ?>
                    <?php if ($settingsErrors !== []): ?>
                        <div class="settings-feedback is-error" role="alert">
                            <strong>Please review the following:</strong>
                            <ul>
                                <?php foreach ($settingsErrors as $settingsError): ?>
                                    <li><?= $escape($settingsError) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="settings-layout">
                        <section class="settings-card" aria-labelledby="appearanceHeading">
                            <div class="settings-card-heading">
                                <span class="settings-card-icon" aria-hidden="true">◐</span>
                                <div>
                                    <p class="eyebrow">DISPLAY</p>
                                    <h2 id="appearanceHeading">Appearance</h2>
                                </div>
                            </div>
                            <label class="settings-control-row" for="themePreference">
                                <span><strong>Theme</strong><small>Choose a light or dark appearance.</small></span>
                                <select id="themePreference" class="settings-select" data-setting="theme">
                                    <option value="light">Light</option>
                                    <option value="dark">Dark</option>
                                    <option value="system">System default</option>
                                </select>
                            </label>
                            <label class="settings-control-row settings-control-row-stacked" for="defaultPharmacySortPreference">
                                <span><strong>Default pharmacy order</strong><small>Choose how pharmacies are ordered when you open the Pharmacy page.</small></span>
                                <select id="defaultPharmacySortPreference" class="settings-select settings-select-wide" data-setting="pharmacySort">
                                    <option value="nearest">Nearest first</option>
                                    <option value="available">Pharmacies with available medicines</option>
                                    <option value="alphabetical">Alphabetical order</option>
                                </select>
                            </label>
                            <label class="settings-control-row" for="languagePreference">
                                <span><strong>Language</strong><small>Choose your preferred language.</small></span>
                                <select id="languagePreference" class="settings-select" data-setting="language">
                                    <option value="en">English</option>
                                    <option value="fil">Filipino (Tagalog)</option>
                                </select>
                            </label>
                            <p class="settings-save-status" id="settingsSaveStatus" role="status" aria-live="polite"></p>
                        </section>

                        <section class="settings-card" aria-labelledby="notificationPreferencesHeading">
                            <div class="settings-card-heading">
                                <span class="settings-card-icon" aria-hidden="true">♧</span>
                                <div>
                                    <p class="eyebrow">UPDATES</p>
                                    <h2 id="notificationPreferencesHeading">Notification preferences</h2>
                                </div>
                            </div>
                            <div class="settings-toggle-list">
                                <?php foreach ([
                                    'medicineAlerts' => ['Medicine availability alerts', 'Updates when a medicine becomes available.'],
                                    'pharmacyUpdates' => ['Pharmacy updates', 'Updates about nearby pharmacy listings.'],
                                    'reservationUpdates' => ['Reservation updates', 'Updates about your medicine reservations.']
                                ] as $settingKey => [$settingLabel, $settingDescription]): ?>
                                    <label class="settings-toggle-row">
                                        <span><strong><?= $escape($settingLabel) ?></strong><small><?= $escape($settingDescription) ?></small></span>
                                        <input type="checkbox" role="switch" data-setting="<?= $escape($settingKey) ?>">
                                    </label>
                                <?php endforeach; ?>
                                <div class="settings-toggle-row settings-toggle-fixed">
                                    <span><strong>Security alerts</strong><small>Important account protection notices.</small></span>
                                    <span class="settings-always-on">Always on</span>
                                </div>
                            </div>
                            <p class="settings-footnote">Notification preferences are saved on this device. Automated alert delivery is not currently connected.</p>
                        </section>

                        <section class="settings-card" aria-labelledby="locationSettingsHeading">
                            <div class="settings-card-heading">
                                <span class="settings-card-icon location-settings-icon" aria-hidden="true"><?= $locationIcon() ?></span>
                                <div>
                                    <p class="eyebrow">NEARBY PHARMACIES</p>
                                    <h2 id="locationSettingsHeading">Location</h2>
                                </div>
                            </div>
                            <label class="settings-toggle-row">
                                <span><strong>Location services</strong><small>Allow a browser location request when you ask to find nearby pharmacies.</small></span>
                                <input id="locationPreference" type="checkbox" role="switch" data-setting="location">
                            </label>
                            <button class="settings-location-button" id="useCurrentLocation" type="button">
                                <span class="location-action-icon" aria-hidden="true"><?= $locationIcon() ?></span> Use current location
                            </button>
                            <p class="settings-location-status" id="locationStatus" role="status" aria-live="polite"></p>
                            <h3 class="nearby-pharmacies-heading">Nearby Pharmacies</h3>
                            <div id="map" class="nearby-pharmacies-map" role="application" aria-label="Map showing your current location and nearby pharmacies"></div>
                        </section>

                        <section class="settings-card" aria-labelledby="accountSettingsHeading">
                            <div class="settings-card-heading">
                                <span class="settings-card-icon" aria-hidden="true">⚿</span>
                                <div>
                                    <p class="eyebrow">ACCOUNT</p>
                                    <h2 id="accountSettingsHeading">Account</h2>
                                </div>
                            </div>
                            <button class="settings-account-row" id="changePasswordButton" type="button" aria-expanded="<?= $settingsErrors === [] ? 'false' : 'true' ?>" aria-controls="changePasswordForm">
                                <span><strong>Change password</strong><small>Update your account password.</small></span>
                                <span aria-hidden="true">›</span>
                            </button>
                            <form class="settings-password-form" id="changePasswordForm" method="post" action="dashboard.php#settings" <?= $settingsErrors === [] ? 'hidden' : '' ?>>
                                <input type="hidden" name="action" value="change_password">
                                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['settings_csrf_token']) ?>">
                                <label>
                                    Current password
                                    <input type="password" name="current_password" autocomplete="current-password" required>
                                </label>
                                <label>
                                    New password
                                    <input type="password" name="new_password" minlength="8" autocomplete="new-password" required>
                                </label>
                                <label>
                                    Confirm new password
                                    <input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                                </label>
                                <p class="settings-password-hint">Use at least 8 characters. Your password is stored as a one-way hash.</p>
                                <div class="settings-password-actions">
                                    <button class="profile-cancel-button" id="cancelPasswordChange" type="button">Cancel</button>
                                    <button class="profile-edit-button" type="submit">Save password</button>
                                </div>
                            </form>
                            <a class="settings-account-row settings-logout-row" href="logout.php">
                                <span><strong>Log out</strong><small>End your current MediFind session.</small></span>
                                <span aria-hidden="true">›</span>
                            </a>
                        </section>
                    </div>
                </section>

                <footer class="page-footer">MediFind <span>·</span> Pharmacy locator</footer>
            </div>
        </main>

        <section class="notification-preview" id="notificationPreview" popover="auto" aria-label="Recent notifications">
    <header class="notification-preview-heading">
        <h2>Notifications</h2>
        <button class="notification-preview-mark-all" id="markPreviewNotificationsRead" type="button"<?= $notificationUnreadCount === 0 ? ' disabled' : '' ?>>Mark all as read</button>
    </header>
    <div class="notification-preview-tabs" role="tablist" aria-label="Filter notifications">
        <button class="notification-preview-tab is-active" id="notificationTabAll" type="button" role="tab" aria-selected="true" data-notification-filter="all">All Notifications</button>
        <button class="notification-preview-tab" id="notificationTabUnread" type="button" role="tab" aria-selected="false" data-notification-filter="unread">Unread <span id="notificationPreviewUnreadCount"><?= $notificationUnreadCount ?></span></button>
    </div>
    <p class="notification-preview-status" id="notificationPreviewStatus" role="status" aria-live="polite"></p>
    <div class="notification-preview-list" id="notificationPreviewList">
        <?php if ($notificationRows === []): ?>
            <p class="notification-preview-empty" id="notificationPreviewEmpty">You're all caught up.</p>
        <?php else: ?>
            <?php foreach ($notificationRows as $previewNotification): ?>
                <?php
                $previewIsUnread = (int) $previewNotification['is_read'] === 0;
                $previewType = (string) $previewNotification['type'];
                $previewIcon = match ($previewType) {
                    'reservation_created' => '+',
                    'reservation_cancelled', 'reservation_expired' => '&#215;',
                    'reservation_confirmed', 'reservation_ready' => '&#10003;',
                    default => '&#8226;',
                };
                ?>
                <article class="notification-preview-item<?= $previewIsUnread ? ' is-unread' : '' ?>" data-notification-preview-id="<?= (int) $previewNotification['id'] ?>" data-notification-unread="<?= $previewIsUnread ? 'true' : 'false' ?>">
                    <span class="notification-preview-dot" aria-hidden="true"></span>
                    <span class="notification-preview-icon reservation-notification" aria-hidden="true"><?= $previewIcon ?></span>
                    <div class="notification-preview-copy">
                        <h3><?= $escape($previewNotification['title']) ?></h3>
                        <p><?= $escape($previewNotification['message']) ?></p>
                    </div>
                    <time><?= $escape($formatNotificationAge((string) $previewNotification['created_at'])) ?></time>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <p class="notification-preview-empty" id="notificationPreviewNoUnread" hidden>No unread notifications.</p>
    <a class="notification-preview-all" href="#notifications" data-section="notifications" data-notification-see-all>See all notifications</a>
</section>
    </div>
    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""
        defer
    ></script>
    <script src="js/location.js?v=5" defer></script>
    <script src="js/medicine_search.js?v=12" defer></script>
    <script src="js/dashboard.js?v=54" defer></script>
    <script src="js/reservation.js?v=3" defer></script>
    <script src="js/notifications.js?v=13" defer></script>
</body>
</html>












