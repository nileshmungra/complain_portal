<?php

function recordAuditEvent(
    mysqli $conn,
    ?int $actorId,
    string $action,
    string $entityType,
    string|int|null $entityId,
    string $summary,
    array $metadata = []
): void {
    $metadataJson = $metadata === []
        ? null
        : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    if (!is_string($ipAddress) || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
        $ipAddress = null;
    }
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $userAgent = is_string($userAgent) ? mb_substr($userAgent, 0, 255, 'UTF-8') : null;
    $entityId = $entityId === null ? null : (string) $entityId;

    $statement = $conn->prepare(
        'INSERT INTO admin_audit_log
            (actor_id, action, entity_type, entity_id, summary, metadata_json, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param(
        'isssssss',
        $actorId,
        $action,
        $entityType,
        $entityId,
        $summary,
        $metadataJson,
        $ipAddress,
        $userAgent
    );
    $statement->execute();
}

function getPortalSetting(mysqli $conn, string $key, string $defaultValue): string
{
    $statement = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
    $statement->bind_param('s', $key);
    $statement->execute();
    $setting = $statement->get_result()->fetch_assoc();

    return is_array($setting) ? (string) $setting['setting_value'] : $defaultValue;
}

function savePortalSetting(mysqli $conn, string $key, string $value, ?int $actorId): void
{
    $statement = $conn->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
    );
    $statement->bind_param('ssi', $key, $value, $actorId);
    $statement->execute();
}

function addBusinessDays(DateTimeImmutable $start, int $businessDays): DateTimeImmutable
{
    $date = $start;
    while ($businessDays > 0) {
        $date = $date->modify('+1 day');
        if ((int) $date->format('N') < 6) {
            $businessDays--;
        }
    }

    return $date;
}

function isStrongPassword(string $password): bool
{
    return strlen($password) >= 12
        && strlen($password) <= 72
        && preg_match('/[A-Za-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1;
}

function createUserNotification(
    mysqli $conn,
    int $userId,
    string $type,
    string $title,
    string $message,
    string|int|null $entityId = null,
    string $entityType = 'complaint',
    ?string $dedupeKey = null
): void {
    $entityId = $entityId === null ? null : (string) $entityId;
    $statement = $conn->prepare(
        'INSERT IGNORE INTO notifications
            (user_id, notification_type, title, message, entity_type, entity_id, dedupe_key)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param(
        'issssss',
        $userId,
        $type,
        $title,
        $message,
        $entityType,
        $entityId,
        $dedupeKey
    );
    $statement->execute();
}

function notifyUserIds(
    mysqli $conn,
    array $userIds,
    string $type,
    string $title,
    string $message,
    string|int|null $entityId = null,
    string $entityType = 'complaint'
): void {
    foreach (array_unique(array_filter(array_map('intval', $userIds), static fn ($id) => $id > 0)) as $userId) {
        createUserNotification($conn, $userId, $type, $title, $message, $entityId, $entityType);
    }
}

function getAreaManagerIdsForDistrict(mysqli $conn, string $district): array
{
    $statement = $conn->prepare(
        "SELECT id
         FROM users
         WHERE role = 'area_manager'
           AND is_active = 1
           AND FIND_IN_SET(?, REPLACE(assigned_area, ', ', ',')) > 0"
    );
    $statement->bind_param('s', $district);
    $statement->execute();

    return array_map(
        'intval',
        array_column($statement->get_result()->fetch_all(MYSQLI_ASSOC), 'id')
    );
}

function notifyComplaintDepartments(
    mysqli $conn,
    array $departments,
    string $type,
    string $title,
    string $message,
    int $complaintId
): void {
    $departments = array_values(array_unique(array_filter(
        $departments,
        static fn ($department) => is_string($department) && $department !== ''
    )));
    if ($departments === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($departments), '?'));
    $statement = $conn->prepare(
        "SELECT id FROM users
         WHERE is_active = 1
           AND (role = 'admin' OR (role = 'department' AND department IN ({$placeholders})))"
    );
    $types = str_repeat('s', count($departments));
    $parameters = array_merge([$types], $departments);
    $references = [];
    foreach ($parameters as $key => &$parameter) {
        $references[$key] = &$parameter;
    }
    unset($parameter);
    call_user_func_array([$statement, 'bind_param'], $references);
    $statement->execute();
    $userIds = array_column($statement->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
    notifyUserIds($conn, $userIds, $type, $title, $message, $complaintId);
}

function notifyOverdueComplaints(mysqli $conn): int
{
    $conn->query(
        "INSERT IGNORE INTO notifications
            (user_id, notification_type, title, message, entity_type, entity_id, dedupe_key)
         SELECT DISTINCT
            u.id,
            'overdue',
            'Complaint overdue',
            CONCAT('Complaint ', COALESCE(c.public_reference, CONCAT('CMP-', LPAD(c.id, 6, '0'))), ' is past its response deadline.'),
            'complaint',
            CAST(c.id AS CHAR),
            CONCAT('overdue:', c.id, ':', u.id, ':', DATE_FORMAT(c.due_at, '%Y%m%d%H%i%s'))
         FROM complaints c
         INNER JOIN users u ON u.is_active = 1
         WHERE c.due_at < NOW()
           AND c.status NOT IN ('Closed', 'Resolved', 'Rejected')
           AND NOT EXISTS (
               SELECT 1
               FROM complaint_approvals ca
               WHERE ca.complaint_id = c.id AND ca.status = 'Pending'
           )
           AND (
                u.role = 'admin'
                OR (
                    u.role = 'department'
                    AND EXISTS (
                        SELECT 1 FROM complaint_department_assignments cda
                        WHERE cda.complaint_id = c.id AND cda.department = u.department
                    )
                )
           )"
    );

    return $conn->affected_rows;
}

function getUnreadNotificationCount(mysqli $conn, int $userId): int
{
    $statement = $conn->prepare('SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $statement->bind_param('i', $userId);
    $statement->execute();

    return (int) $statement->get_result()->fetch_assoc()['total'];
}

function renderNotificationLink(mysqli $conn, int $userId): void
{
    $count = getUnreadNotificationCount($conn, $userId);
    echo '<a class="settings-button notification-link" href="notifications.php" aria-label="Notifications'
        . ($count > 0 ? ', ' . $count . ' unread' : '')
        . '">Notifications';
    if ($count > 0) {
        echo '<span class="notification-badge">' . min($count, 99) . ($count > 99 ? '+' : '') . '</span>';
    }
    echo '</a>';
}

function revokeUserSessions(mysqli $conn, int $userId, ?int $actorId, ?string $exceptSessionHash = null): int
{
    $statement = $conn->prepare(
        'UPDATE user_sessions
         SET revoked_at = NOW(), revoked_by = ?
         WHERE user_id = ? AND revoked_at IS NULL
           AND (? IS NULL OR session_hash <> ?)'
    );
    $statement->bind_param('iiss', $actorId, $userId, $exceptSessionHash, $exceptSessionHash);
    $statement->execute();

    return $statement->affected_rows;
}
