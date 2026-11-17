<?php
require_once __DIR__ . '/includes/auth.php';

$errorMessage = '';
$isForcedChange = (int) $currentUser['must_change_password'] === 1;
$csrfToken = getCsrfToken();
$passwordStatement = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
$passwordUserId = (int) $currentUser['id'];
$passwordStatement->bind_param('i', $passwordUserId);
$passwordStatement->execute();
$currentPasswordHash = (string) ($passwordStatement->get_result()->fetch_assoc()['password'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif (!$isForcedChange && !password_verify($currentPassword, $currentPasswordHash)) {
        $errorMessage = 'Your current password is incorrect.';
    } elseif (!isStrongPassword($newPassword)) {
        $errorMessage = 'Use 12–72 characters with at least one letter and one number.';
    } elseif ($newPassword !== $confirmPassword) {
        $errorMessage = 'The new passwords do not match.';
    } elseif (!$isForcedChange && password_verify($newPassword, $currentPasswordHash)) {
        $errorMessage = 'Choose a password different from your current password.';
    } else {
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $newSessionVersion = (int) $currentUser['session_version'] + 1;
        $oldSessionHash = (string) $_SESSION['portal_session_hash'];
        $conn->begin_transaction();
        try {
            $updateStatement = $conn->prepare(
                'UPDATE users
                 SET password = ?, must_change_password = 0, session_version = ?
                 WHERE id = ?'
            );
            $userId = (int) $currentUser['id'];
            $updateStatement->bind_param('sii', $passwordHash, $newSessionVersion, $userId);
            $updateStatement->execute();
            recordAuditEvent(
                $conn,
                $userId,
                'user.password_changed',
                'user',
                $userId,
                'The account password was changed.'
            );
            $conn->commit();
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log('Password change failed: ' . $exception->getMessage());
            $errorMessage = 'Your password could not be changed because of a database error.';
        }

        if ($errorMessage === '') {
            session_regenerate_id(true);
            $newSessionHash = hash('sha256', session_id());
            $sessionStatement = $conn->prepare(
                'UPDATE user_sessions SET session_hash = ?, last_seen_at = NOW()
                 WHERE user_id = ? AND session_hash = ? AND revoked_at IS NULL'
            );
            $sessionStatement->bind_param('sis', $newSessionHash, $userId, $oldSessionHash);
            $sessionStatement->execute();
            if ($sessionStatement->affected_rows !== 1) {
                error_log('Password changed but the active session could not be rotated for user ' . $userId . '.');
                $_SESSION = [];
                session_destroy();
                header('Location: login.php?expired=1');
                exit;
            }

            $_SESSION['portal_session_hash'] = $newSessionHash;
            $_SESSION['session_version'] = $newSessionVersion;
            revokeUserSessions($conn, $userId, $userId, $newSessionHash);
            $_SESSION['flash_success'] = 'Your password was changed successfully.';
            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Change password | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="auth-layout">
    <aside class="auth-aside">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
        <div class="aside-copy"><p class="eyebrow">Account security</p><h2><?php echo $isForcedChange ? 'Set a new password.' : 'Keep your account secure.'; ?></h2><p>Passwords must be at least 12 characters and include letters and numbers.</p></div>
    </aside>
    <section class="auth-content">
        <h1 class="page-heading">Change password</h1>
        <p class="page-subtitle"><?php echo $isForcedChange ? 'A password change is required before continuing.' : 'Choose a password you have not used before.'; ?></p>
        <?php if ($errorMessage !== ''): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div><?php endif; ?>
        <form method="POST" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
            <?php if (!$isForcedChange): ?>
                <div class="field"><label for="current-password">Current password</label><input id="current-password" type="password" name="current_password" autocomplete="current-password" required></div>
            <?php endif; ?>
            <div class="field"><label for="new-password">New password</label><input id="new-password" type="password" name="new_password" minlength="12" maxlength="72" autocomplete="new-password" required></div>
            <div class="field"><label for="confirm-password">Confirm new password</label><input id="confirm-password" type="password" name="confirm_password" minlength="12" maxlength="72" autocomplete="new-password" required></div>
            <button type="submit" class="primary-button">Update password</button>
        </form>
        <?php if (!$isForcedChange): ?><p class="auth-switch"><a href="dashboard.php">Cancel</a></p><?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
