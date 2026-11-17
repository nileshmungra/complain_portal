<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin_navigation.php';

requireAdministrator($currentUser);

$errorMessage = '';
$successMessage = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$csrfToken = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif ($action === 'revoke_session') {
        $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($sessionId) || $sessionId < 1) {
            $errorMessage = 'Select a valid session.';
        } else {
            $statement = $conn->prepare(
                'SELECT s.id, s.user_id, s.session_hash, u.full_name
                 FROM user_sessions s INNER JOIN users u ON u.id = s.user_id
                 WHERE s.id = ? AND s.revoked_at IS NULL AND s.expires_at > NOW()
                 LIMIT 1'
            );
            $statement->bind_param('i', $sessionId);
            $statement->execute();
            $session = $statement->get_result()->fetch_assoc();
            if (!$session) {
                $errorMessage = 'That session is no longer active.';
            } elseif (hash_equals((string) $_SESSION['portal_session_hash'], (string) $session['session_hash'])) {
                $errorMessage = 'You cannot revoke the current browser session here. Use Sign out to end it.';
            } else {
                $updateStatement = $conn->prepare(
                    'UPDATE user_sessions SET revoked_at = NOW(), revoked_by = ?
                     WHERE id = ? AND revoked_at IS NULL'
                );
                $actorId = (int) $currentUser['id'];
                $updateStatement->bind_param('ii', $actorId, $sessionId);
                $updateStatement->execute();
                recordAuditEvent(
                    $conn,
                    $actorId,
                    'security.session_revoked',
                    'user_session',
                    $sessionId,
                    'A session for ' . $session['full_name'] . ' was revoked.'
                );
                $_SESSION['flash_success'] = 'Session revoked.';
                header('Location: admin_security.php');
                exit;
            }
        }
    } elseif ($action === 'revoke_user_sessions') {
        $targetUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($targetUserId) || $targetUserId < 1) {
            $errorMessage = 'Select a valid user account.';
        } else {
            $exceptHash = $targetUserId === (int) $currentUser['id']
                ? (string) $_SESSION['portal_session_hash']
                : null;
            $revokedCount = revokeUserSessions($conn, $targetUserId, (int) $currentUser['id'], $exceptHash);
            recordAuditEvent(
                $conn,
                (int) $currentUser['id'],
                'security.sessions_revoked',
                'user',
                $targetUserId,
                $revokedCount . ' session(s) were revoked.',
                ['revoked' => $revokedCount]
            );
            $_SESSION['flash_success'] = $revokedCount . ' session(s) revoked.';
            header('Location: admin_security.php');
            exit;
        }
    } else {
        $errorMessage = 'Choose a valid security action.';
    }
}

$loginFailures = (int) $conn->query(
    'SELECT COUNT(*) FROM login_attempts
     WHERE succeeded = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
)->fetch_row()[0];
$sessions = $conn->query(
    'SELECT s.id, s.user_id, s.session_hash, s.ip_address, s.user_agent,
            s.created_at, s.last_seen_at, s.expires_at, s.revoked_at,
            u.full_name, u.role
     FROM user_sessions s
     INNER JOIN users u ON u.id = s.user_id
     ORDER BY (s.revoked_at IS NULL AND s.expires_at > NOW()) DESC, s.last_seen_at DESC
     LIMIT 150'
)->fetch_all(MYSQLI_ASSOC);
$activeSessionCount = (int) $conn->query(
    'SELECT COUNT(*) FROM user_sessions WHERE revoked_at IS NULL AND expires_at > NOW()'
)->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Security controls | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="dashboard-shell">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
        <div class="topbar-actions"><a href="dashboard.php" class="settings-button">Dashboard</a><?php renderNotificationLink($conn, (int) $currentUser['id']); ?><a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a></div>
    </header>
    <?php renderAdminNavigation('security'); ?>
    <section class="page-panel admin-settings-panel">
        <div class="page-panel-heading"><div><p class="eyebrow">Administrator</p><h1 class="page-heading">Security controls</h1><p class="page-subtitle">Review active sessions and account protection settings.</p></div></div>
        <?php if ($successMessage !== ''): ?><div class="notice notice--success" role="status"><?php echo escapeHtml($successMessage); ?></div><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div><?php endif; ?>
        <div class="admin-stat-grid">
            <div class="admin-stat-card"><span>Active sessions</span><strong><?php echo $activeSessionCount; ?></strong></div>
            <div class="admin-stat-card"><span>Unsuccessful sign-ins (24h)</span><strong><?php echo $loginFailures; ?></strong></div>
            <div class="admin-stat-card"><span>Password policy</span><strong>12+ chars</strong></div>
        </div>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Account protection</h2><p>Passwords require at least 12 characters with letters and numbers. Temporary passwords must be changed at first sign-in. Five failed attempts per account/device combination trigger a 15-minute wait.</p></div></div>
            <a class="secondary-button" href="change_password.php">Change my password</a>
            <div class="notice" role="note">Authenticator-based MFA is not enabled in this release. Use unique passwords and revoke sessions you do not recognize.</div>
        </section>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Recent sessions</h2><p>Showing up to 150 recent sessions. The current browser session cannot be revoked from this page.</p></div></div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>User</th><th>Device</th><th>IP</th><th>Last seen</th><th>Expires</th><th>State</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($sessions as $session): ?>
                        <?php
                        $isCurrent = hash_equals((string) $_SESSION['portal_session_hash'], (string) $session['session_hash']);
                        $isActive = $session['revoked_at'] === null && strtotime($session['expires_at']) > time();
                        ?>
                        <tr>
                            <td><?php echo escapeHtml($session['full_name']); ?><br><small><?php echo escapeHtml($session['role']); ?></small><?php echo $isCurrent ? '<br><span class="admin-status">This browser</span>' : ''; ?></td>
                            <td><?php echo escapeHtml($session['user_agent']); ?></td>
                            <td><?php echo escapeHtml($session['ip_address']); ?></td>
                            <td><?php echo escapeHtml(date('M j, Y H:i', strtotime($session['last_seen_at']))); ?></td>
                            <td><?php echo escapeHtml(date('M j, Y H:i', strtotime($session['expires_at']))); ?></td>
                            <td><span class="admin-status <?php echo $isActive ? '' : 'admin-status--muted'; ?>"><?php echo $isActive ? 'Active' : ($session['revoked_at'] !== null ? 'Revoked' : 'Expired'); ?></span></td>
                            <td>
                                <?php if ($isActive && !$isCurrent): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                                        <input type="hidden" name="action" value="revoke_session">
                                        <input type="hidden" name="session_id" value="<?php echo (int) $session['id']; ?>">
                                        <button class="danger-button" type="submit">Revoke</button>
                                    </form>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($sessions === []): ?><tr><td colspan="7">No portal sessions have been recorded yet. Sign out and sign in once to start session tracking.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
