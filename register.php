<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php?register=1');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

$errors = [];
$fullname = trim($_POST['fullname'] ?? '');
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm-password'] ?? '';
$privacyAgreed = isset($_POST['privacyAgreement']) ? 1 : 0;

if ($fullname === '') {
    $errors[] = 'Please enter your full name.';
}

if ($username === '' || strlen($username) < 4) {
    $errors[] = 'Username must be at least 4 characters.';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
}

if (!preg_match('/^09\d{9}$/', $phone)) {
    $errors[] = 'Phone number must be in this format: 09xxxxxxxxx';
}

if ($password === '') {
    $errors[] = 'Please create a password.';
}

if ($password !== $confirmPassword) {
    $errors[] = 'Passwords do not match.';
}

if (!$privacyAgreed) {
    $errors[] = 'You must agree to the Privacy and Data Protection Notice.';
}

if (empty($errors)) {
    $stmt = $pdo->prepare(
        'SELECT id, username, email, phone
         FROM users
         WHERE username = :username
            OR email = :email
            OR phone = :phone
         LIMIT 1'
    );

    $stmt->execute([
        ':username' => $username,
        ':email'    => $email,
        ':phone'    => $phone
    ]);

    $existingUser = $stmt->fetch();

    if ($existingUser) {
        if ($existingUser['username'] === $username) {
            $errors[] = 'Username is already taken.';
        }

        if ($existingUser['email'] === $email) {
            $errors[] = 'Email address is already registered.';
        }

        if ($existingUser['phone'] === $phone) {
            $errors[] = 'Phone number is already registered.';
        }
    }
}

if (empty($errors)) {
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare(
            'INSERT INTO users
                (full_name, username, email, phone, password_hash, privacy_agreed)
             VALUES
                (:full_name, :username, :email, :phone, :password_hash, :privacy_agreed)'
        );

        $insert->execute([
            ':full_name'      => $fullname,
            ':username'       => $username,
            ':email'          => $email,
            ':phone'          => $phone,
            ':password_hash'  => $passwordHash,
            ':privacy_agreed' => $privacyAgreed
        ]);

        $newUserId = (int) $pdo->lastInsertId();
        logSecurityActivity(
            $pdo,
            $newUserId,
            'REGISTRATION',
            'A new user account was registered.',
            $username
        );
        $pdo->commit();

        header('Location: login.php?registered=1');
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('User registration failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong while creating your account. Please try again.';
    }
}

$_SESSION['registration_flash'] = [
    'errors' => $errors,
    'values' => [
        'fullname' => $fullname,
        'username' => $username,
        'email' => $email,
        'phone' => $phone,
        'privacyAgreement' => $privacyAgreed
    ]
];

header('Location: login.php?register=1');
exit;
