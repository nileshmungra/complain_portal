<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/complaint_reference.php';

$userStatement = $conn->prepare(
    "SELECT id, role, full_name, assigned_area, department, is_active,
            must_change_password, session_version
     FROM users
     WHERE id = ?
     LIMIT 1"
);
$userId = (int) $_SESSION['user_id'];
$userStatement->bind_param("i", $userId);
$userStatement->execute();
$currentUser = $userStatement->get_result()->fetch_assoc();

if (!$currentUser || (int) $currentUser['is_active'] !== 1) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$sessionHash = $_SESSION['portal_session_hash'] ?? '';
$sessionVersion = filter_var($_SESSION['session_version'] ?? null, FILTER_VALIDATE_INT);
if (
    !is_string($sessionHash)
    || !preg_match('/\A[a-f0-9]{64}\z/', $sessionHash)
    || $sessionVersion === false
    || $sessionVersion === null
    || $sessionVersion !== (int) $currentUser['session_version']
) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}

$sessionStatement = $conn->prepare(
    'SELECT id, last_seen_at
     FROM user_sessions
     WHERE user_id = ? AND session_hash = ? AND revoked_at IS NULL AND expires_at > NOW()
     LIMIT 1'
);
$sessionStatement->bind_param('is', $userId, $sessionHash);
$sessionStatement->execute();
$portalSession = $sessionStatement->get_result()->fetch_assoc();
if (!$portalSession) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}
if (strtotime($portalSession['last_seen_at']) < time() - 300) {
    $sessionId = (int) $portalSession['id'];
    $touchSession = $conn->prepare('UPDATE user_sessions SET last_seen_at = NOW() WHERE id = ?');
    $touchSession->bind_param('i', $sessionId);
    $touchSession->execute();
}

function escapeHtml(string|null $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function requireAdministrator(array $user): void
{
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Administrator access required.');
    }
}

function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function isValidCsrfToken(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function getComplaintScope(array $user): array
{
    if ($user['role'] === 'admin') {
        return ['where' => '1 = 1', 'types' => '', 'values' => []];
    }

    if ($user['role'] === 'area_manager') {
        $areas = array_filter(array_map('trim', explode(',', $user['assigned_area'] ?? '')));
        if ($areas === []) {
            return ['where' => '1 = 0', 'types' => '', 'values' => []];
        }

        return [
            'where' => 'c.district IN (' . implode(',', array_fill(0, count($areas), '?')) . ')',
            'types' => str_repeat('s', count($areas)),
            'values' => array_values($areas)
        ];
    }

    if ($user['role'] === 'department') {
        return [
            'where' => '(
                EXISTS (
                    SELECT 1
                    FROM complaint_department_assignments cda
                    WHERE cda.complaint_id = c.id AND cda.department = ?
                )
                OR c.created_by = ?
            )',
            'types' => 'si',
            'values' => [$user['department'] ?? '', (int) $user['id']]
        ];
    }

    return [
        'where' => 'c.created_by = ?',
        'types' => 'i',
        'values' => [(int) $user['id']]
    ];
}

function fetchComplaintRows(mysqli $conn, string $query, array $scope): array
{
    $statement = $conn->prepare($query);

    if ($scope['types'] !== '') {
        $parameters = array_merge([$scope['types']], $scope['values']);
        $references = [];
        foreach ($parameters as $key => &$parameter) {
            $references[$key] = &$parameter;
        }
        unset($parameter);
        call_user_func_array([$statement, 'bind_param'], $references);
    }

    $statement->execute();
    $result = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    return $result;
}

require_once __DIR__ . '/admin_features.php';

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
if ((int) $currentUser['must_change_password'] === 1 && $currentPage !== 'change_password.php') {
    header('Location: change_password.php');
    exit;
}
