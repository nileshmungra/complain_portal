<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin_navigation.php';

requireAdministrator($currentUser);
notifyOverdueComplaints($conn);

$errorMessage = '';
$successMessage = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$csrfToken = getCsrfToken();
$slaDays = (int) getPortalSetting($conn, 'sla_business_days', '5');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newSlaDays = filter_var($_POST['sla_business_days'] ?? null, FILTER_VALIDATE_INT);
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif (!is_int($newSlaDays) || $newSlaDays < 1 || $newSlaDays > 30) {
        $errorMessage = 'Set the response target to a whole number from 1 to 30 business days.';
    } else {
        $conn->begin_transaction();
        try {
            savePortalSetting($conn, 'sla_business_days', (string) $newSlaDays, (int) $currentUser['id']);
            recordAuditEvent(
                $conn,
                (int) $currentUser['id'],
                'settings.sla_updated',
                'system_settings',
                'sla_business_days',
                'The default complaint response target changed from ' . $slaDays . ' to ' . $newSlaDays . ' business days.',
                ['before' => $slaDays, 'after' => $newSlaDays]
            );
            $conn->commit();
            $_SESSION['flash_success'] = 'SLA setting saved. It will apply to new complaints; existing due dates were preserved.';
            header('Location: admin_workflow.php');
            exit;
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log('SLA setting update failed: ' . $exception->getMessage());
            $errorMessage = 'The response target could not be saved because of a database error.';
        }
    }
}

$counts = $conn->query(
    "SELECT
        SUM(due_at IS NOT NULL AND due_at < NOW() AND status NOT IN ('Closed', 'Resolved', 'Rejected')) AS overdue,
        SUM(due_at IS NOT NULL AND due_at >= NOW() AND due_at < DATE_ADD(NOW(), INTERVAL 2 DAY)
            AND status NOT IN ('Closed', 'Resolved', 'Rejected')) AS due_soon,
        SUM(due_at IS NULL AND status NOT IN ('Closed', 'Resolved', 'Rejected')) AS no_deadline
     FROM complaints"
)->fetch_assoc();
$overdueComplaints = $conn->query(
    "SELECT id, public_reference, title, district, department, status, due_at
     FROM complaints
     WHERE due_at IS NOT NULL AND due_at < NOW()
       AND status NOT IN ('Closed', 'Resolved', 'Rejected')
     ORDER BY due_at ASC, id ASC
     LIMIT 100"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Workflow and SLA | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="dashboard-shell">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
        <div class="topbar-actions"><a href="dashboard.php" class="settings-button">Dashboard</a><?php renderNotificationLink($conn, (int) $currentUser['id']); ?><a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a></div>
    </header>
    <?php renderAdminNavigation('workflow'); ?>
    <section class="page-panel admin-settings-panel">
        <div class="page-panel-heading"><div><p class="eyebrow">Administrator</p><h1 class="page-heading">Workflow &amp; SLA</h1><p class="page-subtitle">Response targets apply to new complaints. Historical records without a reliable date are not marked overdue.</p></div></div>
        <?php if ($successMessage !== ''): ?><div class="notice notice--success" role="status"><?php echo escapeHtml($successMessage); ?></div><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div><?php endif; ?>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Default response target</h2><p>Due dates skip Saturday and Sunday. Closed, resolved, and rejected complaints are excluded from overdue counts.</p></div></div>
            <form method="POST" class="admin-inline-form">
                <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                <label for="sla-days">Business days</label>
                <input id="sla-days" type="number" name="sla_business_days" min="1" max="30" value="<?php echo $slaDays; ?>" required>
                <button class="primary-button" type="submit">Save SLA</button>
            </form>
        </section>
        <div class="admin-stat-grid">
            <div class="admin-stat-card"><span>Overdue</span><strong><?php echo (int) $counts['overdue']; ?></strong></div>
            <div class="admin-stat-card"><span>Due in the next 2 days</span><strong><?php echo (int) $counts['due_soon']; ?></strong></div>
            <div class="admin-stat-card"><span>No reliable due date</span><strong><?php echo (int) $counts['no_deadline']; ?></strong></div>
        </div>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Overdue complaints</h2><p>In-app alerts are created once per user and deadline.</p></div></div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Complaint</th><th>District</th><th>Department</th><th>Status</th><th>Due</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($overdueComplaints as $complaint): ?>
                        <tr>
                            <td><strong><?php echo escapeHtml(formatComplaintReference((int) $complaint['id'], $complaint['public_reference'])); ?></strong><br><?php echo escapeHtml($complaint['title']); ?></td>
                            <td><?php echo escapeHtml($complaint['district']); ?></td>
                            <td><?php echo escapeHtml($complaint['department']); ?></td>
                            <td><span class="admin-status admin-status--danger"><?php echo escapeHtml($complaint['status']); ?></span></td>
                            <td><?php echo escapeHtml(date('M j, Y', strtotime($complaint['due_at']))); ?></td>
                            <td><a class="secondary-button" href="admin_edit_complaint.php?id=<?php echo (int) $complaint['id']; ?>">Review</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($overdueComplaints === []): ?><tr><td colspan="6">There are no overdue complaints.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
