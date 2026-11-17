<?php
require_once __DIR__ . '/includes/auth.php';

notifyOverdueComplaints($conn);

$errorMessage = '';
$successMessage = '';
$csrfToken = getCsrfToken();
$userId = (int) $currentUser['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'mark_all_read') {
        $statement = $conn->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
        $statement->bind_param('i', $userId);
        $statement->execute();
        $successMessage = $statement->affected_rows . ' notification(s) marked as read.';
    } elseif (($_POST['action'] ?? '') === 'mark_read') {
        $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($notificationId) || $notificationId < 1) {
            $errorMessage = 'Select a valid notification.';
        } else {
            $statement = $conn->prepare(
                'UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL'
            );
            $statement->bind_param('ii', $notificationId, $userId);
            $statement->execute();
            $successMessage = 'Notification marked as read.';
        }
    } else {
        $errorMessage = 'Choose a valid notification action.';
    }
}

$statement = $conn->prepare(
    'SELECT id, notification_type, title, message, entity_type, entity_id, created_at, read_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC, id DESC
     LIMIT 100'
);
$statement->bind_param('i', $userId);
$statement->execute();
$notifications = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
$unreadCount = getUnreadNotificationCount($conn, $userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Notifications | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="dashboard-shell">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
        <div class="topbar-actions">
            <?php if ($currentUser['role'] === 'admin'): ?><a href="admin_settings.php" class="settings-button">Admin</a><?php endif; ?>
            <a href="dashboard.php" class="settings-button">Dashboard</a>
            <a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a>
        </div>
    </header>
    <section class="page-panel admin-settings-panel">
        <div class="page-panel-heading">
            <div><p class="eyebrow">Your workspace</p><h1 class="page-heading">Notifications</h1><p class="page-subtitle"><?php echo $unreadCount; ?> unread notification(s). Updates are available in the portal.</p></div>
            <?php if ($unreadCount > 0): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button class="secondary-button" type="submit">Mark all as read</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($errorMessage !== ''): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div><?php endif; ?>
        <?php if ($successMessage !== ''): ?><div class="notice notice--success" role="status"><?php echo escapeHtml($successMessage); ?></div><?php endif; ?>
        <?php if ($notifications === []): ?>
            <p class="empty-state">You are all caught up. New assignments and complaint updates will appear here.</p>
        <?php else: ?>
            <div class="notification-list">
                <?php foreach ($notifications as $notification): ?>
                    <?php
                    $isComplaint = $notification['entity_type'] === 'complaint' && ctype_digit((string) $notification['entity_id']);
                    $isApprovalNotification = $notification['notification_type'] === 'approval_required';
                    $complaintUrl = $isComplaint
                        ? ($isApprovalNotification
                            ? 'area_manager_approvals.php'
                            : 'dashboard.php?search=' . rawurlencode((string) $notification['entity_id']) . '#complaint-list')
                        : '';
                    ?>
                    <article class="notification-item<?php echo $notification['read_at'] === null ? ' is-unread' : ''; ?>">
                        <div>
                            <h3><?php echo escapeHtml($notification['title']); ?></h3>
                            <p><?php echo escapeHtml($notification['message']); ?></p>
                            <time datetime="<?php echo escapeHtml($notification['created_at']); ?>"><?php echo escapeHtml(date('M j, Y H:i', strtotime($notification['created_at']))); ?></time>
                            <?php if ($isComplaint): ?> · <a href="<?php echo escapeHtml($complaintUrl); ?>"><?php echo $isApprovalNotification ? 'Review approval' : 'View complaint'; ?></a><?php endif; ?>
                        </div>
                        <?php if ($notification['read_at'] === null): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>">
                                <button class="secondary-button" type="submit">Mark read</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
