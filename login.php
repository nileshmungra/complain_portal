<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/admin_features.php';

$error_msg = "";
if (empty($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $mobile = is_string($_POST['mobile'] ?? null) ? trim($_POST['mobile']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $csrfToken = $_POST['csrf_token'] ?? null;
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $identifierHash = hash('sha256', mb_strtolower($mobile, 'UTF-8') . '|' . (is_string($remoteAddress) ? $remoteAddress : ''));

    if (
        !is_string($csrfToken)
        || !hash_equals($_SESSION['login_csrf_token'], $csrfToken)
    ) {
        $error_msg = 'Your sign-in form expired. Refresh the page and try again.';
    } else {
        $attemptStatement = $conn->prepare(
            'SELECT COUNT(*) AS attempts
             FROM login_attempts
             WHERE identifier_hash = ? AND succeeded = 0
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $attemptStatement->bind_param('s', $identifierHash);
        $attemptStatement->execute();
        $recentAttempts = (int) $attemptStatement->get_result()->fetch_assoc()['attempts'];

        if ($recentAttempts >= 5) {
            $error_msg = 'Too many unsuccessful sign-in attempts. Please wait 15 minutes before trying again.';
        } else {
            $stmt = $conn->prepare(
                'SELECT id, role, full_name, password, is_active, must_change_password, session_version
                 FROM users WHERE mobile = ? LIMIT 1'
            );
            $stmt->bind_param('s', $mobile);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if (
                $user
                && (int) $user['is_active'] === 1
                && $user['password'] !== null
                && password_verify($password, $user['password'])
            ) {
                session_regenerate_id(true);
                $userId = (int) $user['id'];
                $sessionHash = hash('sha256', session_id());
                $ipAddress = is_string($remoteAddress) && filter_var($remoteAddress, FILTER_VALIDATE_IP)
                    ? $remoteAddress
                    : null;
                $userAgent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null)
                    ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255, 'UTF-8')
                    : null;
                $expiresAt = date('Y-m-d H:i:s', time() + 12 * 60 * 60);
                $sessionStatement = $conn->prepare(
                    'INSERT INTO user_sessions
                        (user_id, session_hash, ip_address, user_agent, expires_at)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $sessionStatement->bind_param('issss', $userId, $sessionHash, $ipAddress, $userAgent, $expiresAt);
                $sessionStatement->execute();

                $_SESSION['user_id'] = $userId;
                $_SESSION['session_version'] = (int) $user['session_version'];
                $_SESSION['portal_session_hash'] = $sessionHash;
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                $conn->query('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
                $clearAttempts = $conn->prepare('DELETE FROM login_attempts WHERE identifier_hash = ?');
                $clearAttempts->bind_param('s', $identifierHash);
                $clearAttempts->execute();
                recordAuditEvent($conn, $userId, 'login.success', 'user', $userId, 'User signed in.');

                header('Location: ' . ((int) $user['must_change_password'] === 1 ? 'change_password.php' : 'dashboard.php'));
                exit;
            }

            $failedAttempt = $conn->prepare('INSERT INTO login_attempts (identifier_hash) VALUES (?)');
            $failedAttempt->bind_param('s', $identifierHash);
            $failedAttempt->execute();
            recordAuditEvent($conn, $user ? (int) $user['id'] : null, 'login.failure', 'user', $user['id'] ?? null, 'Unsuccessful sign-in attempt.');
            $error_msg = 'The mobile number or password is incorrect.';
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
    <title>Sign in | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main class="auth-layout">
        <aside class="auth-aside">
            <a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
            <div class="aside-copy">
                <p class="eyebrow">Farmer Complaint Management</p>
                <h2>Good to have you back.</h2>
                <p>Sign in to stay connected with your team and your portal workspace.</p>
            </div>
            <p class="aside-footnote">One connected community, working together.</p>
        </aside>

        <section class="auth-content">
            <h1 class="page-heading">Welcome back</h1>
            <p class="page-subtitle">Sign in with the mobile number linked to your account.</p>
            <?php if (isset($_GET['expired'])): ?>
                <div class="notice notice--error" role="status">Your session ended or was revoked. Please sign in again.</div>
            <?php endif; ?>

            <?php if ($error_msg !== ""): ?>
                <div class="notice notice--error" role="alert"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['login_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="field">
                    <label for="mobile">Mobile number</label>
                    <input id="mobile" type="tel" name="mobile" maxlength="15" autocomplete="username" placeholder="Your mobile number" required>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                </div>
                <button type="submit" class="primary-button">Sign in <span aria-hidden="true">→</span></button>
            </form>

            <p class="auth-switch">New to the portal? <a href="register.php">Create an account</a></p>
        </section>
    </main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
