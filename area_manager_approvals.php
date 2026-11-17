<?php
require_once __DIR__ . '/includes/auth.php';

if ($currentUser['role'] !== 'area_manager') {
    http_response_code(403);
    exit('Area Manager access required.');
}

$config = require __DIR__ . '/config/complaints.php';
$approvalScope = getComplaintScope($currentUser);
$errorMessage = '';
$successMessage = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$csrfToken = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $complaintId = filter_var($_POST['complaint_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['approval_action'] ?? '';

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif (!is_int($complaintId) || $complaintId < 1) {
        $errorMessage = 'Select a valid complaint.';
    } elseif (!in_array($action, ['approve', 'reject'], true)) {
        $errorMessage = 'Choose whether to approve or reject this complaint.';
    } else {
        $conn->begin_transaction();
        try {
            $selectStatement = $conn->prepare(
                "SELECT c.id, c.public_reference, c.title, c.created_by, c.department, c.district
                 FROM complaint_approvals ca
                 INNER JOIN complaints c ON c.id = ca.complaint_id
                 WHERE ca.complaint_id = ?
                   AND ca.status = 'Pending'
                   AND {$approvalScope['where']}
                 FOR UPDATE"
            );
            $bindTypes = 'i' . $approvalScope['types'];
            $values = array_merge([$complaintId], $approvalScope['values']);
            $parameters = array_merge([$bindTypes], $values);
            $references = [];
            foreach ($parameters as $key => &$parameter) {
                $references[$key] = &$parameter;
            }
            unset($parameter);
            call_user_func_array([$selectStatement, 'bind_param'], $references);
            $selectStatement->execute();
            $complaint = $selectStatement->get_result()->fetch_assoc();

            if (!$complaint) {
                $conn->rollback();
                $errorMessage = 'This complaint is no longer awaiting approval or is outside your assigned districts.';
            } else {
                $approvalStatus = $action === 'approve' ? 'Approved' : 'Rejected';
                $managerId = (int) $currentUser['id'];
                $updateApproval = $conn->prepare(
                    "UPDATE complaint_approvals
                     SET status = ?, decided_by = ?, decided_at = NOW()
                     WHERE complaint_id = ? AND status = 'Pending'"
                );
                $updateApproval->bind_param('sii', $approvalStatus, $managerId, $complaintId);
                $updateApproval->execute();
                if ($updateApproval->affected_rows !== 1) {
                    throw new RuntimeException('This complaint has already been reviewed.');
                }

                $reference = formatComplaintReference($complaintId, $complaint['public_reference']);
                if ($action === 'approve') {
                    $departments = array_values(array_unique(array_filter(
                        array_map('trim', explode(',', $complaint['department']))
                    )));
                    if ($departments === [] || array_diff($departments, $config['departments']) !== []) {
                        throw new RuntimeException('The complaint has an invalid department route. Contact an administrator.');
                    }

                    $assignmentStatement = $conn->prepare(
                        'INSERT IGNORE INTO complaint_department_assignments (complaint_id, department)
                         VALUES (?, ?)'
                    );
                    foreach ($departments as $department) {
                        $assignmentStatement->bind_param('is', $complaintId, $department);
                        $assignmentStatement->execute();
                    }
                    notifyComplaintDepartments(
                        $conn,
                        $departments,
                        'assignment',
                        'New complaint assigned',
                        $reference . ' is assigned to ' . implode(', ', $departments) . '.',
                        $complaintId
                    );

                    $slaDays = max(1, min(30, (int) getPortalSetting($conn, 'sla_business_days', '5')));
                    $dueAt = addBusinessDays(new DateTimeImmutable(), $slaDays)->format('Y-m-d H:i:s');
                    $updateDueAt = $conn->prepare('UPDATE complaints SET due_at = ? WHERE id = ?');
                    $updateDueAt->bind_param('si', $dueAt, $complaintId);
                    $updateDueAt->execute();
                } else {
                    $rejectStatement = $conn->prepare(
                        "UPDATE complaints SET status = 'Rejected', resolved_at = NULL WHERE id = ?"
                    );
                    $rejectStatement->bind_param('i', $complaintId);
                    $rejectStatement->execute();
                }

                $markApprovalNotificationsRead = $conn->prepare(
                    "UPDATE notifications
                     SET read_at = NOW()
                     WHERE notification_type = 'approval_required'
                       AND entity_type = 'complaint'
                       AND entity_id = ?
                       AND read_at IS NULL"
                );
                $complaintIdString = (string) $complaintId;
                $markApprovalNotificationsRead->bind_param('s', $complaintIdString);
                $markApprovalNotificationsRead->execute();

                $decision = $action === 'approve' ? 'approved' : 'rejected';
                notifyUserIds(
                    $conn,
                    [(int) $complaint['created_by']],
                    'complaint_' . $decision,
                    'Complaint ' . $decision,
                    $reference . ' was ' . $decision . ' by the Area Manager.',
                    $complaintId
                );
                recordAuditEvent(
                    $conn,
                    $managerId,
                    'complaint.area_manager_' . $decision,
                    'complaint',
                    $complaintId,
                    $reference . ' was ' . $decision . ' by the Area Manager.',
                    ['district' => $complaint['district']]
                );

                $conn->commit();
                $_SESSION['flash_success'] = $action === 'approve'
                    ? $reference . ' approved and assigned to ' . implode(', ', $departments) . '.'
                    : $reference . ' rejected.';
                header('Location: area_manager_approvals.php');
                exit;
            }
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}

$pendingApprovals = fetchComplaintRows(
    $conn,
    "SELECT c.id, c.public_reference, c.title, c.farmer_name, c.farmer_mobile,
            c.district, c.complaint_type, c.product, c.created_at, u.full_name AS submitted_by
     FROM complaint_approvals ca
     INNER JOIN complaints c ON c.id = ca.complaint_id
     INNER JOIN users u ON u.id = c.created_by
     WHERE ca.status = 'Pending' AND {$approvalScope['where']}
     ORDER BY ca.created_at DESC, c.id DESC",
    $approvalScope
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Complaint approvals | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="dashboard-shell">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
        <div class="topbar-actions">
            <a href="dashboard.php" class="settings-button">Dashboard</a>
            <?php renderNotificationLink($conn, (int) $currentUser['id']); ?>
            <a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a>
        </div>
    </header>
    <section class="page-panel">
        <div class="page-panel-heading">
            <div>
                <p class="eyebrow">Area Manager</p>
                <h1 class="page-heading">Complaint approvals</h1>
                <p class="page-subtitle">Review complaints submitted in your assigned districts. Approval sends them to their assigned departments.</p>
            </div>
        </div>
        <?php if ($errorMessage !== ''): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div><?php endif; ?>
        <?php if ($successMessage !== ''): ?><div class="notice notice--success" role="status"><?php echo escapeHtml($successMessage); ?></div><?php endif; ?>
        <?php if ($pendingApprovals === []): ?>
            <p class="empty-state">There are no complaints awaiting approval in your assigned districts.</p>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Complaint</th>
                            <th>Farmer</th>
                            <th>District</th>
                            <th>Type / product</th>
                            <th>Submitted by</th>
                            <th>Decision</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingApprovals as $complaint): ?>
                        <?php $reference = formatComplaintReference((int) $complaint['id'], $complaint['public_reference']); ?>
                        <tr>
                            <td>
                                <strong><?php echo escapeHtml($reference); ?></strong>
                                <br><?php echo escapeHtml($complaint['title']); ?>
                                <br><a href="dashboard.php?search=<?php echo rawurlencode((string) $complaint['id']); ?>#complaint-list">View details</a>
                            </td>
                            <td><?php echo escapeHtml($complaint['farmer_name']); ?><br><small><?php echo escapeHtml($complaint['farmer_mobile']); ?></small></td>
                            <td><?php echo escapeHtml($complaint['district']); ?></td>
                            <td><?php echo escapeHtml($complaint['complaint_type']); ?><br><small><?php echo escapeHtml($complaint['product']); ?></small></td>
                            <td><?php echo escapeHtml($complaint['submitted_by']); ?><br><small><?php echo escapeHtml(date('M j, Y H:i', strtotime($complaint['created_at']))); ?></small></td>
                            <td>
                                <div class="admin-inline-form">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                                        <input type="hidden" name="complaint_id" value="<?php echo (int) $complaint['id']; ?>">
                                        <input type="hidden" name="approval_action" value="approve">
                                        <button class="primary-button" type="submit">Approve</button>
                                    </form>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                                        <input type="hidden" name="complaint_id" value="<?php echo (int) $complaint['id']; ?>">
                                        <input type="hidden" name="approval_action" value="reject">
                                        <button class="danger-button" type="submit">Reject</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
