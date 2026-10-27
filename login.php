<?php
/* =================================================
   LOGIN.PHP
   Combined Login Page + Login Processing
   Database-backed login attempt / lockout system
   ================================================= */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

$registrationFlash = $_SESSION['registration_flash'] ?? [];
unset($_SESSION['registration_flash']);

$registrationErrors = $registrationFlash['errors'] ?? [];
$registrationValues = $registrationFlash['values'] ?? [];
$showRegister = isset($_GET['register']) || !empty($registrationErrors);

/* =================================================
   LOGIN LOCKOUT SETTINGS
   After 5 failed attempts, block for 30 seconds.
   ================================================= */

$maxAttempts    = 5;
$lockoutSeconds = 30;

$errorMessage = '';
$attemptsLeft = null;
$lockoutRemaining = 0;
/* =================================================
   PROCESS LOGIN FORM
   ================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    /* Empty fields */
    if ($username === '' || $password === '') {

        logSecurityActivity(
            $pdo,
            null,
            'LOGIN_FAILED',
            'A sign-in form submission was missing a username or password.',
            $username !== '' ? $username : null
        );
        $errorMessage = 'Please enter your username and password.';

    } else {

        /* -----------------------------------------
           LOOK UP USER
           ----------------------------------------- */

        $stmt = $pdo->prepare(
            'SELECT id, username, password_hash, is_active,
                    failed_attempts, locked_until, role
             FROM users
             WHERE username = :username
             LIMIT 1'
        );

        $stmt->execute([
            ':username' => $username
        ]);

        $user = $stmt->fetch();

        /* -----------------------------------------
           CHECK LOCKOUT
           ----------------------------------------- */

        if ($user && $user['locked_until'] !== null) {

            $lockedUntil = strtotime($user['locked_until']);

            if ($lockedUntil > time()) {

                $lockoutRemaining = max(
                    1,
                    $lockedUntil - time()
                );
                recordSecurityAttempt($pdo, (int) $user['id'], 'login_blocked');
                logSecurityActivity(
                    $pdo,
                    (int) $user['id'],
                    'LOGIN_BLOCKED',
                    'A sign-in attempt was blocked while the account lockout was active.',
                    (string) $user['username']
                );

                $errorMessage =
                    'Too many failed login attempts. Please try again in '
                    . $lockoutRemaining
                    . ' seconds.';

            } else {

                /* Lockout expired */

                $reset = $pdo->prepare(
                    'UPDATE users
                     SET failed_attempts = 0,
                         locked_until = NULL
                     WHERE id = :id'
                );

                $reset->execute([
                    ':id' => $user['id']
                ]);

                $user['failed_attempts'] = 0;
                $user['locked_until'] = null;
            }
        }

        /* -----------------------------------------
           ONLY VERIFY PASSWORD IF NOT LOCKED
           ----------------------------------------- */

        if (
            $errorMessage === '' &&
            (!$user || !password_verify(
                $password,
                $user['password_hash']
            ))
        ) {

            /* -------------------------------------
               USER EXISTS
               ------------------------------------- */

            if ($user) {

                $attempts =
                    (int)$user['failed_attempts'] + 1;

                /* -------------------------------
                   MAXIMUM ATTEMPTS REACHED
                   ------------------------------- */

                if ($attempts >= $maxAttempts) {

                    $lockUntil = date(
                        'Y-m-d H:i:s',
                        time() + $lockoutSeconds
                    );

                    $upd = $pdo->prepare(
                        'UPDATE users
                         SET failed_attempts = :attempts,
                             locked_until = :locked_until
                         WHERE id = :id'
                    );

                    $upd->execute([
                        ':attempts'     => $attempts,
                        ':locked_until' => $lockUntil,
                        ':id'           => $user['id']
                    ]);
                    recordSecurityAttempt($pdo, (int) $user['id'], 'login_failed');
                    recordSecurityAttempt($pdo, (int) $user['id'], 'account_locked');
                    logSecurityActivity(
                        $pdo,
                        (int) $user['id'],
                        'LOGIN_FAILED',
                        'A sign-in attempt used an incorrect password.',
                        (string) $user['username']
                    );
                    logSecurityActivity(
                        $pdo,
                        (int) $user['id'],
                        'ACCOUNT_LOCKED',
                        'The failed-login limit was reached and a temporary lockout was applied.',
                        (string) $user['username']
                    );

                    $lockoutRemaining = $lockoutSeconds;

                    $errorMessage =
                        'Too many failed login attempts. '
                        . 'Your account is temporarily locked for '
                        . $lockoutSeconds
                        . ' seconds.';

                } else {

                    /* -------------------------------
                       INCREMENT FAILED ATTEMPTS
                       ------------------------------- */

                    $attemptsLeft =
                        $maxAttempts - $attempts;

                    $upd = $pdo->prepare(
                        'UPDATE users
                         SET failed_attempts = :attempts
                         WHERE id = :id'
                    );

                    $upd->execute([
                        ':attempts' => $attempts,
                        ':id' => $user['id']
                    ]);
                    recordSecurityAttempt($pdo, (int) $user['id'], 'login_failed');
                    logSecurityActivity(
                        $pdo,
                        (int) $user['id'],
                        'LOGIN_FAILED',
                        'A sign-in attempt used an incorrect password.',
                        (string) $user['username']
                    );

                    $errorMessage =
                        'Invalid username or password. '
                        . $attemptsLeft
                        . ' attempt(s) remaining.';
                }

            } else {

                /* ---------------------------------
                   USER DOES NOT EXIST
                   --------------------------------- */

                logSecurityActivity(
                    $pdo,
                    null,
                    'LOGIN_FAILED',
                    'A sign-in attempt used an unknown username.',
                    $username
                );
                $attemptsLeft = $maxAttempts;

                $errorMessage =
                    'Invalid username or password.';
            }

        } elseif (
            $errorMessage === '' &&
            $user &&
            !password_verify(
                $password,
                $user['password_hash']
            )
        ) {

            $errorMessage =
                'Invalid username or password.';
        }

        /* -----------------------------------------
           CHECK ACCOUNT STATUS
           ----------------------------------------- */

        if (
            $errorMessage === '' &&
            $user &&
            password_verify(
                $password,
                $user['password_hash']
            )
        ) {

            if (!$user['is_active']) {

                logSecurityActivity(
                    $pdo,
                    (int) $user['id'],
                    'LOGIN_FAILED',
                    'A sign-in attempt was rejected because the account is inactive.',
                    (string) $user['username']
                );
                $errorMessage =
                    'Your account is currently inactive.';

            } else {

                /* ---------------------------------
                   LOGIN SUCCESS
                   --------------------------------- */

                $reset = $pdo->prepare(
                    'UPDATE users
                     SET failed_attempts = 0,
                         locked_until = NULL
                     WHERE id = :id'
                );

                $reset->execute([
                    ':id' => $user['id']
                ]);
                recordSecurityAttempt($pdo, (int) $user['id'], 'login_success');
                logSecurityActivity(
                    $pdo,
                    (int) $user['id'],
                    'LOGIN_SUCCESS',
                    'User signed in successfully.',
                    (string) $user['username']
                );

                /* Regenerate session ID */
                session_regenerate_id(true);

                $_SESSION['user_id'] =
                    $user['id'];

                $_SESSION['username'] =
                    $user['username'];
                $_SESSION['role'] = $user['role'];

                /* ---------------------------------
                   REMEMBER ME
                   --------------------------------- */

                if ($remember) {

                    $lifetime =
                        60 * 60 * 24 * 30;

                    setcookie(
                        session_name(),
                        session_id(),
                        time() + $lifetime,
                        '/',
                        '',
                        false,
                        true
                    );
                }

                /* ---------------------------------
                   REDIRECT TO DASHBOARD
                   --------------------------------- */

                header('Location: dashboard.php');
                exit;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Login</title>

    <!-- Google Font -->
    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <!-- Font Awesome -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <!-- CSS -->
    <link
        rel="stylesheet"
        href="css/auth.css"
    >

</head>


<body>

    <div
        class="auth-wrapper<?= $showRegister ? ' show-register' : '' ?>"
        id="authWrapper"
    >


        <!-- =========================================
             LEFT VISUAL PANEL
             ========================================= -->

        <div class="visual-panel">

            <div class="visual-content">

                <div class="infinity-logo">

                    <img
                        src="Images/logo.png"
                        alt="Logo"
                        class="main-logo"
                    >

                </div>


                <h2 id="visualTitle">
                    Welcome Back
                </h2>


                <p id="visualText">
                    Log in to find nearby pharmacies and
                    manage your reservations.
                </p>


                <div class="visual-circle">

                    <div class="orbit orbit-one"></div>

                    <div class="orbit orbit-two"></div>


                    <img
                        src="Images/hirono.png"
                        alt="Hirono The Healer"
                        class="hirono-figure"
                    >

                </div>


                <h3 id="visualSubtitle">
                    Your medicine is closer than you think.
                </h3>


                <p id="visualDescription">
                    Search &nbsp; Reserve &nbsp; Pickup.
                </p>

                <button
                    type="button"
                    class="visual-switch-button"
                    id="visualSwitchButton"
                    onclick="showRegister()"
                >
                    Create Account
                </button>

            </div>

        </div>


        <!-- =========================================
             LOGIN PANEL
             ========================================= -->

        <section class="form-panel login-panel">

            <div class="form-container">

                <h1>
                    Login
                </h1>


                <p class="form-intro">
                    Welcome back! Please enter your details.
                </p>


                <!-- LOGIN FORM -->

                <form
                    id="loginForm"
                    action="login.php"
                    method="POST"
                    novalidate
                >


                    <!-- Username -->

                    <div class="input-group">

                        <span class="input-icon">

                            <i class="fa-solid fa-user"></i>

                        </span>


                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Username"
                            autocomplete="username"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        >

                    </div>


                    <p
                        class="error-message"
                        id="usernameError"
                    ></p>


                    <!-- Password -->

                    <div class="input-group">

                        <span class="input-icon">

                            <i class="fa-solid fa-lock"></i>

                        </span>


                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Password"
                            autocomplete="current-password"
                        >


                        <button
                            type="button"
                            class="password-toggle"
                            onclick="togglePassword()"
                            aria-label="Show password"
                        >

                            <i
                                class="fa-solid fa-eye"
                                id="eyeIcon"
                            ></i>

                        </button>

                    </div>


                    <p
                        class="error-message"
                        id="passwordError"
                    ></p>


                    <!-- Remember / Forgot -->

                    <div class="meta-row">


                        <label class="remember-me">

                            <input
                                type="checkbox"
                                name="remember"
                                <?= isset($_POST['remember']) ? 'checked' : '' ?>
                            >

                            <span>
                                Remember me
                            </span>

                        </label>


                        <button
                            type="button"
                            class="forgot-link"
                            onclick="openForgotPassword()"
                        >
                            Forgot Password?
                        </button>


                    </div>


                    <!-- PHP LOGIN ERROR -->

                    <?php if ($errorMessage !== ''): ?>

                        <p
                            class="form-error"
                            id="loginError"
                        >
                            <?= htmlspecialchars($errorMessage) ?>
                        </p>

                    <?php else: ?>

                        <p
                            class="form-error"
                            id="loginError"
                        ></p>

                    <?php endif; ?>

                    <!-- Lockout Status -->

                    <div
                        id="lockoutStatus"
                        class="lockout-status"
                        <?= $lockoutRemaining > 0 ? '' : 'style="display: none;"' ?>
                    >

                        <?php if ($lockoutRemaining > 0): ?>

                            Account temporarily locked.
                            Please wait
                            <?= $lockoutRemaining ?>
                            seconds.

                        <?php endif; ?>

                    </div>


                    <!-- Login Button -->

                    <button
                        type="submit"
                        class="primary-button"
                        <?= $lockoutRemaining > 0 ? 'disabled' : '' ?>
                    >

                        <i class="fa-solid fa-right-to-bracket"></i>

                        Login

                    </button>


                    <!-- Security Notice -->

                    <div class="security-notice">

                        <i class="fa-solid fa-shield-halved"></i>


                        <div>

                            <strong>
                                Your Information Matters
                            </strong>


                            <p>
                                Your account information is treated
                                as private and should never be shared
                                with others.
                            </p>

                        </div>

                    </div>


                </form>

            </div>

        </section>


        <!-- =========================================
             REGISTRATION PANEL
             ========================================= -->

        <section class="form-panel register-panel">

            <div class="form-container register-container">

                <div class="register-header">
                    <h1>Create Account</h1>

                    <p>Sign up to reserve and collect your medicines.</p>

                </div>

                <?php if (!empty($registrationErrors)): ?>
                    <div class="form-error" id="registerError">
                        <?php foreach ($registrationErrors as $registrationError): ?>
                            <div><?= htmlspecialchars($registrationError) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="form-error" id="registerError"></p>
                <?php endif; ?>

                <form
                    id="registerForm"
                    action="register.php"
                    method="POST"
                    novalidate
                >

                    <div class="form-grid">

                        <div class="field">
                            <label for="fullname">Full Name</label>
                            <input
                                type="text"
                                id="fullname"
                                name="fullname"
                                placeholder="Your full name"
                                value="<?= htmlspecialchars($registrationValues['fullname'] ?? '') ?>"
                            >
                            <p class="error-message" id="fullnameError"></p>
                        </div>

                        <div class="field">
                            <label for="regUsername">Username</label>
                            <input
                                type="text"
                                id="regUsername"
                                name="username"
                                placeholder="Choose a username"
                                value="<?= htmlspecialchars($registrationValues['username'] ?? '') ?>"
                            >
                            <p class="error-message" id="regUsernameError"></p>
                        </div>

                        <div class="field">
                            <label for="email">Email</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                placeholder="you@example.com"
                                value="<?= htmlspecialchars($registrationValues['email'] ?? '') ?>"
                            >
                            <p class="error-message" id="emailError"></p>
                        </div>

                        <div class="field">
                            <label for="phone">Phone</label>
                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                placeholder="09xxxxxxxxx"
                                value="<?= htmlspecialchars($registrationValues['phone'] ?? '') ?>"
                            >
                            <p class="error-message" id="phoneError"></p>
                        </div>

                        <div class="field field-full">
                            <label for="regPassword">Password</label>
                            <div class="password-wrapper">
                                <i class="fa-solid fa-lock password-left-icon"></i>
                                <input
                                    type="password"
                                    id="regPassword"
                                    name="password"
                                    placeholder="Create password"
                                    autocomplete="new-password"
                                >
                                <button
                                    type="button"
                                    class="register-password-toggle"
                                    onclick="toggleRegisterPassword('regPassword', 'regPasswordIcon')"
                                    aria-label="Show password"
                                >
                                    <i class="fa-solid fa-eye" id="regPasswordIcon"></i>
                                </button>
                            </div>
                            <p class="error-message" id="regPasswordError"></p>
                        </div>

                        <div class="field field-full">
                            <label for="confirmPassword">Confirm Password</label>
                            <div class="password-wrapper">
                                <i class="fa-solid fa-lock password-left-icon"></i>
                                <input
                                    type="password"
                                    id="confirmPassword"
                                    name="confirm-password"
                                    placeholder="Confirm Password"
                                    autocomplete="new-password"
                                >
                                <button
                                    type="button"
                                    class="register-password-toggle"
                                    onclick="toggleRegisterPassword('confirmPassword', 'confirmPasswordIcon')"
                                    aria-label="Show password"
                                >
                                    <i class="fa-solid fa-eye" id="confirmPasswordIcon"></i>
                                </button>
                            </div>
                            <p class="error-message" id="confirmPasswordError"></p>
                        </div>

                    </div>

                    <label class="privacy-check">
                        <input
                            type="checkbox"
                            id="privacyAgreement"
                            name="privacyAgreement"
                            <?= !empty($registrationValues['privacyAgreement']) ? 'checked' : '' ?>
                        >
                        <span>I agree to the Privacy and Data Protection Notice.</span>
                    </label>
                    <p class="error-message" id="privacyError"></p>

                    <button type="submit" class="register-button">
                        Create Account
                    </button>

                </form>

            </div>

        </section>

    </div>


    <!-- =========================================
         FORGOT PASSWORD MODAL
         ========================================= -->

    <div
        class="forgot-overlay"
        id="forgotOverlay"
    >

        <div class="forgot-box">


            <button
                type="button"
                class="close-forgot"
                onclick="closeForgotPassword()"
                aria-label="Close"
            >

                <i class="fa-solid fa-xmark"></i>

            </button>


            <div class="forgot-icon">

                <i class="fa-solid fa-key"></i>

            </div>


            <h2>
                Forgot Password?
            </h2>


            <p class="forgot-description">

                Enter your email address to request
                a password reset.

            </p>


            <form
                id="forgotForm"
                novalidate
            >

                <div class="forgot-input">

                    <i class="fa-solid fa-envelope"></i>


                    <input
                        type="email"
                        id="resetEmail"
                        placeholder="Enter your email"
                    >

                </div>


                <p
                    class="error-message"
                    id="resetEmailError"
                ></p>


                <button
                    type="submit"
                    class="reset-button"
                >

                    Reset Password

                </button>

            </form>


            <p
                id="resetMessage"
                class="reset-message"
            ></p>


            <button
                type="button"
                class="back-to-login"
                onclick="closeForgotPassword()"
            >

                Back to Login

            </button>

        </div>

    </div>


    <!-- JavaScript -->

    <script src="js/login.js"></script>
    <script src="js/register.js"></script>

</body>

</html>
