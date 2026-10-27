<?php
session_start();

if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/security.php';

    $userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
    if ($userId !== false && $userId !== null && $userId > 0) {
        logSecurityActivity(
            $pdo,
            $userId,
            'LOGOUT',
            'User signed out.',
            isset($_SESSION['username']) ? (string) $_SESSION['username'] : null
        );
    }
}

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
