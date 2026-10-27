<?php
declare(strict_types=1);

function logSecurityActivity(
    PDO $pdo,
    ?int $userId,
    string $activity,
    string $description,
    ?string $username = null
): void {
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $username = $username === null ? null : substr(trim($username), 0, 100);
    $description = substr(trim($description), 0, 500);
    $ipAddress = is_string($ipAddress) ? substr($ipAddress, 0, 45) : null;
    $userAgent = is_string($userAgent) ? substr($userAgent, 0, 500) : null;

    $statement = $pdo->prepare(
        'INSERT INTO security_logs
            (user_id, username, activity, description, ip_address, user_agent)
         VALUES
            (:user_id, :username, :activity, :description, :ip_address, :user_agent)'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':username' => $username,
        ':activity' => strtoupper(substr(trim($activity), 0, 40)),
        ':description' => $description,
        ':ip_address' => $ipAddress,
        ':user_agent' => $userAgent
    ]);
}

function recordSecurityAttempt(PDO $pdo, int $userId, string $eventType): void
{
    $statement = $pdo->prepare(
        'INSERT INTO security_activity (user_id, event_type)
         VALUES (:user_id, :event_type)'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':event_type' => $eventType
    ]);
}

function renderAdminSecurityLogs(): void
{
    session_start();
    require_once __DIR__ . '/db.php';

    if (empty($_SESSION['user_id'])) {
        logSecurityActivity(
            $pdo,
            null,
            'UNAUTHORIZED_ACCESS',
            'Unauthenticated request to the system security logs.'
        );
        header('Location: login.php?error=session');
        exit;
    }

    $userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
    if ($userId === false || $userId === null || $userId < 1) {
        http_response_code(401);
        exit('Please sign in again.');
    }

    $roleStatement = $pdo->prepare('SELECT username, role FROM users WHERE id = :user_id LIMIT 1');
    $roleStatement->execute([':user_id' => $userId]);
    $currentUser = $roleStatement->fetch();

    if (!$currentUser || strtolower((string) $currentUser['role']) !== 'admin') {
        logSecurityActivity(
            $pdo,
            $userId,
            'UNAUTHORIZED_ACCESS',
            'User attempted to access the admin security logs.',
            is_array($currentUser) ? (string) $currentUser['username'] : null
        );
        http_response_code(403);
        exit('Access denied. Administrator permission is required.');
    }

    logSecurityActivity(
        $pdo,
        $userId,
        'ADMIN_ACCESS',
        'Administrator viewed the system security logs.',
        (string) $currentUser['username']
    );

    $allowedActivities = [
        'LOGIN_SUCCESS',
        'LOGIN_FAILED',
        'ACCOUNT_LOCKED',
        'LOGIN_BLOCKED',
        'LOGOUT',
        'REGISTRATION',
        'PASSWORD_CHANGE',
        'UNAUTHORIZED_ACCESS',
        'ADMIN_ACCESS'
    ];
    $selectedActivity = strtoupper(trim((string) ($_GET['activity'] ?? '')));
    if (!in_array($selectedActivity, $allowedActivities, true)) {
        $selectedActivity = '';
    }

    $sql = 'SELECT
                sl.username,
                COALESCE(u.username, sl.username, \'Deleted user\') AS display_username,
                sl.activity,
                sl.description,
                sl.ip_address,
                sl.user_agent,
                sl.created_at
            FROM security_logs AS sl
            LEFT JOIN users AS u ON u.id = sl.user_id';
    $parameters = [];
    if ($selectedActivity !== '') {
        $sql .= ' WHERE sl.activity = :activity';
        $parameters[':activity'] = $selectedActivity;
    }
    $sql .= ' ORDER BY sl.created_at DESC, sl.id DESC LIMIT 500';
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $logs = $statement->fetchAll();

    $escape = static fn ($value): string => htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Admin Security Logs | MediFind</title>
        <link rel="stylesheet" href="css/dashboard.css?v=57">
        <style>
            body { margin: 0; padding: 28px; background: #f4f7fb; color: #344052; font: 14px Arial, sans-serif; }
            main { max-width: 1200px; margin: auto; }
            .log-header, .log-filters { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
            .log-table-wrap { overflow-x: auto; margin-top: 20px; border: 1px solid #e8edf3; border-radius: 12px; background: #fff; }
            table { width: 100%; border-collapse: collapse; text-align: left; }
            th, td { padding: 12px; border-bottom: 1px solid #edf0f4; vertical-align: top; }
            th { background: #f8fafc; }
            td { overflow-wrap: anywhere; }
            select, .back-link { min-height: 38px; padding: 8px 12px; border: 1px solid #dce3eb; border-radius: 8px; background: #fff; color: inherit; }
            .log-note { color: #748094; }
        </style>
    </head>
    <body>
        <main>
            <header class="log-header">
                <div>
                    <p class="eyebrow">ADMINISTRATION</p>
                    <h1>System security logs</h1>
                    <p class="log-note">Showing up to 500 recent security events.</p>
                </div>
                <a class="back-link" href="dashboard.php#security">Back to dashboard</a>
            </header>

            <form class="log-filters" method="get">
                <label for="activityFilter">Filter activity</label>
                <select id="activityFilter" name="activity" onchange="this.form.submit()">
                    <option value="">All activities</option>
                    <?php foreach ($allowedActivities as $activity): ?>
                        <option value="<?= $escape($activity) ?>"<?= $selectedActivity === $activity ? ' selected' : '' ?>>
                            <?= $escape(str_replace('_', ' ', $activity)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div class="log-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Activity</th>
                            <th>Description</th>
                            <th>IP address</th>
                            <th>User agent</th>
                            <th>Date/time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($logs === []): ?>
                            <tr><td colspan="6">No security logs match this filter.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= $escape($log['display_username']) ?></td>
                                    <td><?= $escape($log['activity']) ?></td>
                                    <td><?= $escape($log['description']) ?></td>
                                    <td><?= $escape($log['ip_address'] ?? 'Unavailable') ?></td>
                                    <td><?= $escape($log['user_agent'] ?? 'Unavailable') ?></td>
                                    <td><?= $escape($log['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </body>
    </html>
    <?php
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if (($_GET['view'] ?? '') === 'activity') {
        session_start();
        if (empty($_SESSION['user_id'])) {
            header('Location: login.php?error=session');
            exit;
        }
        header('Location: dashboard.php#security');
        exit;
    }

    renderAdminSecurityLogs();
}
