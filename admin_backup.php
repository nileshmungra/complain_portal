<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin_navigation.php';
require_once __DIR__ . '/includes/portal_backup.php';

requireAdministrator($currentUser);

function formatBackupSize(int $bytes): string
{
    if ($bytes >= 1024 * 1024 * 1024) {
        return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1) . ' MB';
    }

    return number_format($bytes / 1024, 1) . ' KB';
}

$errorMessage = '';
$successMessage = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$csrfToken = getCsrfToken();

if (isset($_GET['download']) && is_string($_GET['download'])) {
    $filename = $_GET['download'];
    if (!preg_match('/\Afarmer-portal-(backup|pre-restore)-\d{8}-\d{6}-[a-f0-9]{8}\.zip\z/', $filename)) {
        http_response_code(404);
        exit('Backup not found.');
    }
    $backupPath = portalBackupDirectory() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($backupPath)) {
        http_response_code(404);
        exit('Backup not found.');
    }
    recordAuditEvent(
        $conn,
        (int) $currentUser['id'],
        'backup.downloaded',
        'backup',
        $filename,
        'A portal backup was downloaded.'
    );
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($backupPath));
    header('Cache-Control: private, no-store');
    if (readfile($backupPath) === false) {
        throw new RuntimeException('The backup could not be sent to the browser.');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif ($action === 'create_backup') {
        $backupCreated = false;
        try {
            $backup = createPortalBackup($conn);
            $backupCreated = true;
            recordAuditEvent(
                $conn,
                (int) $currentUser['id'],
                'backup.created',
                'backup',
                $backup['filename'],
                'A complete portal backup was created.',
                ['bytes' => $backup['size']]
            );
            $_SESSION['flash_success'] = 'Backup created: ' . $backup['filename'];
            header('Location: admin_backup.php');
            exit;
        } catch (RuntimeException $exception) {
            $errorMessage = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            error_log('Portal backup creation failed: ' . $exception->getMessage());
            $errorMessage = $backupCreated
                ? 'The backup was created, but its audit event could not be recorded.'
                : 'The backup could not be created because of a database error.';
        }
    } elseif ($action === 'restore_backup') {
        $upload = $_FILES['backup_file'] ?? null;
        $confirmPhrase = is_string($_POST['confirm_phrase'] ?? null) ? trim($_POST['confirm_phrase']) : '';
        if ($confirmPhrase !== 'RESTORE PORTAL') {
            $errorMessage = 'Type RESTORE PORTAL exactly to confirm this destructive action.';
        } elseif (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['name'], $upload['size'])) {
            $errorMessage = 'Choose a portal backup ZIP file.';
        } elseif ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = match ((int) $upload['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The backup upload exceeded the server limit. Increase upload_max_filesize and post_max_size in Laragon PHP settings.',
                UPLOAD_ERR_PARTIAL => 'The backup upload was incomplete. Please try again.',
                UPLOAD_ERR_NO_FILE => 'Choose a portal backup ZIP file.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded backup.',
                default => 'The backup could not be uploaded.'
            };
        } elseif (
            !is_string($upload['name'])
            || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'zip'
            || (int) $upload['size'] < 1
            || (int) $upload['size'] > 1024 * 1024 * 1024
            || !is_string($upload['tmp_name'])
            || !is_uploaded_file($upload['tmp_name'])
        ) {
            $errorMessage = 'Upload a valid portal backup ZIP up to 1 GB.';
        } else {
            $restoreCompleted = false;
            try {
                $restoreResult = restorePortalBackup(
                    $conn,
                    $upload['tmp_name'],
                    (int) $currentUser['id'],
                    (string) $_SESSION['portal_session_hash']
                );
                $restoreCompleted = true;
                $_SESSION['session_version'] = $restoreResult['session_version'];
                recordAuditEvent(
                    $conn,
                    (int) $currentUser['id'],
                    'backup.restored',
                    'backup',
                    basename($upload['name']),
                    'A portal backup was restored; a pre-restore safety backup was created.',
                    ['safety_backup' => $restoreResult['safety_backup']['filename']]
                );
                $_SESSION['flash_success'] = 'Backup restored. Safety copy: ' . $restoreResult['safety_backup']['filename'];
                header('Location: admin_backup.php');
                exit;
            } catch (RuntimeException $exception) {
                $errorMessage = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                error_log('Portal backup restore failed: ' . $exception->getMessage());
                $errorMessage = $restoreCompleted
                    ? 'The backup was restored, but its audit event could not be recorded. The safety copy remains available.'
                    : 'The restore failed because of a database error. The previous database and attachments were retained.';
            }
        }
    } else {
        $errorMessage = 'Choose a valid backup action.';
    }
}

try {
    $backups = listPortalBackups();
} catch (RuntimeException $exception) {
    $backups = [];
    $errorMessage = $errorMessage !== '' ? $errorMessage : $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Backup and restore | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="dashboard-shell">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
        <div class="topbar-actions"><a href="dashboard.php" class="settings-button">Dashboard</a><?php renderNotificationLink($conn, (int) $currentUser['id']); ?><a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a></div>
    </header>
    <?php renderAdminNavigation('backup'); ?>
    <section class="page-panel admin-settings-panel">
        <div class="page-panel-heading"><div><p class="eyebrow">Administrator</p><h1 class="page-heading">Backup &amp; restore</h1><p class="page-subtitle">Backups contain portal database records and complaint attachments.</p></div></div>
        <?php if ($successMessage !== ''): ?><div class="notice notice--success" role="status"><?php echo escapeHtml($successMessage); ?></div><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div><?php endif; ?>
        <?php if (!class_exists(ZipArchive::class)): ?><div class="notice notice--error" role="alert">Enable PHP's ZIP extension and restart Laragon to use backup and restore.</div><?php endif; ?>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Create a full backup</h2><p>Includes users, complaints, product catalog, assignments, notifications, audit history, and private attachments. Backup files are stored outside the web project.</p></div></div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                <input type="hidden" name="action" value="create_backup">
                <button class="primary-button" type="submit" <?php echo class_exists(ZipArchive::class) ? '' : 'disabled'; ?>>Create backup</button>
            </form>
        </section>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Restore a backup</h2><p>Restoring replaces current portal records and attachments. A private safety backup is created automatically before any changes.</p></div></div>
            <div class="admin-danger-zone">
                <h2>Destructive action</h2>
                <p>Only restore a backup produced by this portal version. Current signed-in administrator must exist and remain active in that backup. Existing sessions and failed-login counters are cleared.</p>
                <p>For large restores, set PHP <code>upload_max_filesize</code> and <code>post_max_size</code> to at least 1G, then restart Laragon.</p>
                <form method="POST" enctype="multipart/form-data" class="form-stack" onsubmit="return confirm('This replaces current portal data and attachments. A safety backup will be created first. Continue?');">
                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="restore_backup">
                    <div class="field"><label for="backup-file">Portal backup (.zip, up to 1 GB)</label><input id="backup-file" type="file" name="backup_file" accept=".zip,application/zip" required></div>
                    <div class="field"><label for="restore-confirm">Type RESTORE PORTAL</label><input id="restore-confirm" name="confirm_phrase" autocomplete="off" required></div>
                    <button class="danger-button" type="submit" <?php echo class_exists(ZipArchive::class) ? '' : 'disabled'; ?>>Restore backup</button>
                </form>
            </div>
        </section>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Private backups</h2><p><?php echo count($backups); ?> backup file(s) available.</p></div></div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Backup</th><th>Created</th><th>Size</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><?php echo escapeHtml($backup['filename']); ?></td>
                            <td><?php echo escapeHtml(date('M j, Y H:i', strtotime($backup['modified_at']))); ?></td>
                            <td><?php echo escapeHtml(formatBackupSize($backup['size'])); ?></td>
                            <td><a class="secondary-button" href="admin_backup.php?download=<?php echo rawurlencode($backup['filename']); ?>">Download</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($backups === []): ?><tr><td colspan="4">No backups have been created yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
