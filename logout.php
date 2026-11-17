<?php
session_start();

$sessionHash = $_SESSION['portal_session_hash'] ?? null;
$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if (
    is_string($sessionHash)
    && preg_match('/\A[a-f0-9]{64}\z/', $sessionHash)
    && $userId !== false
    && $userId !== null
) {
    require_once __DIR__ . '/config/db.php';
    require_once __DIR__ . '/includes/admin_features.php';
    $revokeStatement = $conn->prepare(
        'UPDATE user_sessions SET revoked_at = NOW()
         WHERE user_id = ? AND session_hash = ? AND revoked_at IS NULL'
    );
    $revokeStatement->bind_param('is', $userId, $sessionHash);
    $revokeStatement->execute();
    recordAuditEvent($conn, (int) $userId, 'logout', 'user', (int) $userId, 'User signed out.');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $cookie['path'],
        $cookie['domain'],
        $cookie['secure'],
        $cookie['httponly']
    );
}

session_destroy();
header('Location: login.php');
exit;
