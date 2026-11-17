<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin_navigation.php';
require_once __DIR__ . '/includes/complaint_workflow.php';

requireAdministrator($currentUser);

$workflowTargets = getComplaintWorkflowTargets($conn);
$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$filterErrors = [];
$conditions = ['1 = 1'];
$types = '';
$values = [];
foreach (['from' => $from, 'to' => $to] as $filterName => $filterValue) {
    if ($filterValue === '') {
        continue;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filterValue);
    if (!$date || $date->format('Y-m-d') !== $filterValue) {
        $filterErrors[] = 'Enter valid dates for the report range.';
        continue;
    }
    if ($filterName === 'from') {
        $conditions[] = 'c.created_at >= ?';
    } else {
        $conditions[] = 'c.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    }
    $types .= 's';
    $values[] = $filterValue;
}
if ($from !== '' && $to !== '' && $from > $to) {
    $filterErrors[] = '"From" date must not be after "To" date.';
}
if ($filterErrors !== []) {
    $conditions = ['1 = 0'];
    $types = '';
    $values = [];
}
$reportScope = [
    'where' => implode(' AND ', $conditions),
    'types' => $types,
    'values' => $values
];
$complaints = fetchComplaintRows(
    $conn,
    "SELECT c.id, c.public_reference, c.title, c.product, c.department, c.status, c.created_at,
            ca.status AS approval_status, ca.decided_at AS area_manager_decided_at,
            ca.decision_reason AS area_manager_reason,
            w.so_number, w.so_created_at, w.so_creation_delay_reason,
            w.dispatch_sent_at, w.dispatch_delay_reason,
            w.material_out_at, w.material_out_delay_reason
     FROM complaints c
     LEFT JOIN complaint_approvals ca ON ca.complaint_id = c.id
     LEFT JOIN complaint_workflow w ON w.complaint_id = c.id
     WHERE {$reportScope['where']}
     ORDER BY c.created_at DESC, c.id DESC",
    $reportScope
);
$assignmentsByComplaintId = [];
if ($complaints !== []) {
    $complaintIds = implode(',', array_map(static fn ($complaint) => (int) $complaint['id'], $complaints));
    $assignments = fetchComplaintRows(
        $conn,
        "SELECT a.complaint_id, a.department, a.assigned_at, a.approval_required,
                a.approved_at, a.approval_reason, u.full_name AS approved_by_name
         FROM complaint_department_assignments a
         INNER JOIN complaints c ON c.id = a.complaint_id
         LEFT JOIN users u ON u.id = a.approved_by
         WHERE c.id IN ({$complaintIds}) AND {$reportScope['where']}
         ORDER BY a.complaint_id, a.department",
        $reportScope
    );
    foreach ($assignments as $assignment) {
        $assignmentsByComplaintId[(int) $assignment['complaint_id']][] = $assignment;
    }
}
$databaseNow = $conn->query('SELECT NOW() AS db_now')->fetch_assoc()['db_now'];
$renderStage = static function (array $stage): void {
    if ($stage['days'] === null) {
        echo '<span>Not started</span>';
        return;
    }
    echo '<strong>' . (int) $stage['days'] . ' calendar day(s)' . ($stage['complete'] ? '' : ' elapsed') . '</strong><br>';
    echo '<small>' . escapeHtml(date('d-m-Y H:i', strtotime($stage['start'])))
        . ' → ' . escapeHtml($stage['complete'] ? date('d-m-Y H:i', strtotime($stage['end'])) : 'Now')
        . '</small><br>';
    echo '<small>' . escapeHtml($stage['status']) . ' · Target: '
        . ($stage['target'] > 0 ? (int) $stage['target'] . ' day(s)' : 'Not set')
        . '</small>';
    if ($stage['reason'] !== null && $stage['reason'] !== '') {
        echo '<p class="workflow-report-reason"><strong>Reason:</strong> '
            . nl2br(escapeHtml($stage['reason'])) . '</p>';
    } elseif ($stage['overdue']) {
        echo '<p class="workflow-report-reason"><strong>'
            . ($stage['complete'] ? 'Reason not recorded' : 'Reason required when stage completes')
            . '</strong></p>';
    }
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Complaint stage time report | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .workflow-report-table { min-width: 1250px; }
        .workflow-report-table td { min-width: 145px; vertical-align: top; white-space: normal; }
        .workflow-report-table td:first-child { min-width: 210px; }
        .workflow-report-reason { margin: 6px 0 0; color: #536258; white-space: pre-wrap; overflow-wrap: anywhere; }
        .workflow-report-reason strong { color: #36473b; }
    </style>
</head>
<body>
<main class="dashboard-shell">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
        <div class="topbar-actions"><a href="dashboard.php" class="settings-button">Dashboard</a><?php renderNotificationLink($conn, (int) $currentUser['id']); ?><a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a></div>
    </header>
    <?php renderAdminNavigation('workflow_report'); ?>
    <section class="page-panel admin-settings-panel">
        <div class="page-panel-heading">
            <div><p class="eyebrow">Administrator</p><h1 class="page-heading">Complaint stage time report</h1><p class="page-subtitle">Calendar-day duration, current bottleneck, configured target, and recorded delay reason for each stage.</p></div>
        </div>
        <?php if ($filterErrors !== []): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml(implode(' ', $filterErrors)); ?></div><?php endif; ?>
        <form method="GET" class="admin-filters">
            <div class="field"><label for="workflow-from">Complaint registered from</label><input id="workflow-from" type="date" name="from" value="<?php echo escapeHtml($from); ?>"></div>
            <div class="field"><label for="workflow-to">Complaint registered to</label><input id="workflow-to" type="date" name="to" value="<?php echo escapeHtml($to); ?>"></div>
            <button type="submit" class="primary-button">Apply dates</button>
            <a class="secondary-button" href="admin_workflow_report.php">Clear</a>
        </form>
        <div class="admin-stat-grid">
            <div class="admin-stat-card"><span>Matching complaints</span><strong><?php echo count($complaints); ?></strong></div>
            <div class="admin-stat-card"><span>AM approval target</span><strong><?php echo (int) $workflowTargets['area_manager_approval']; ?> days</strong></div>
            <div class="admin-stat-card"><span>Department target</span><strong><?php echo (int) $workflowTargets['department_approval']; ?> days</strong></div>
            <div class="admin-stat-card"><span>SO creation target</span><strong><?php echo (int) $workflowTargets['so_creation']; ?> days</strong></div>
            <div class="admin-stat-card"><span>Dispatch / material-out targets</span><strong><?php echo $workflowTargets['dispatch_handoff'] > 0 ? (int) $workflowTargets['dispatch_handoff'] . ' / ' : 'Not set / '; ?><?php echo (int) $workflowTargets['material_out']; ?> days</strong></div>
        </div>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Stage-by-stage elapsed time</h2><p>Incomplete stages show elapsed days to now. Legacy department approvals are marked as not tracked rather than guessed.</p></div></div>
            <div class="admin-table-wrap">
                <table class="admin-table workflow-report-table">
                    <thead>
                        <tr>
                            <th>Complaint / current stage</th>
                            <th>Registration → AM approval</th>
                            <th>Department approvals</th>
                            <th>Department → SO creation</th>
                            <th>SO → Dispatch handoff</th>
                            <th>Dispatch → Material out</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($complaints as $complaint): ?>
                        <?php
                        $complaintId = (int) $complaint['id'];
                        $departmentRows = $assignmentsByComplaintId[$complaintId] ?? [];
                        $trackedDepartmentRows = array_values(array_filter(
                            $departmentRows,
                            static fn ($assignment) => (int) $assignment['approval_required'] === 1
                        ));
                        $departmentsPending = count(array_filter(
                            $trackedDepartmentRows,
                            static fn ($assignment) => $assignment['approved_at'] === null
                        )) > 0;
                        $lastDepartmentApproval = null;
                        foreach ($trackedDepartmentRows as $assignment) {
                            if ($assignment['approved_at'] !== null && (
                                $lastDepartmentApproval === null
                                || $assignment['approved_at'] > $lastDepartmentApproval
                            )) {
                                $lastDepartmentApproval = $assignment['approved_at'];
                            }
                        }
                        $soCreationStart = $complaint['approval_status'] === 'Pending' || $departmentsPending
                            ? null
                            : ($trackedDepartmentRows !== []
                                ? $lastDepartmentApproval
                                : ($complaint['area_manager_decided_at'] ?? $complaint['created_at']));
                        $isRejected = $complaint['status'] === 'Rejected' || $complaint['approval_status'] === 'Rejected';
                        $currentStage = complaintWorkflowStageLabel(
                            [
                                'status' => $complaint['status'],
                                'approval_status' => $complaint['approval_status'],
                                'so_created_at' => $complaint['so_created_at'],
                                'dispatch_sent_at' => $complaint['dispatch_sent_at'],
                                'material_out_at' => $complaint['material_out_at']
                            ],
                            $departmentRows
                        );
                        $amStage = $complaint['approval_status'] === null
                            ? null
                            : complaintWorkflowStageReport(
                                $complaint['created_at'],
                                $complaint['area_manager_decided_at'],
                                $workflowTargets['area_manager_approval'],
                                $complaint['area_manager_reason'],
                                $databaseNow
                            );
                        $soStage = $isRejected
                            ? null
                            : complaintWorkflowStageReport(
                                $soCreationStart,
                                $complaint['so_created_at'],
                                $workflowTargets['so_creation'],
                                $complaint['so_creation_delay_reason'],
                                $databaseNow
                            );
                        $dispatchStage = $isRejected
                            ? null
                            : complaintWorkflowStageReport(
                                $complaint['so_created_at'],
                                $complaint['dispatch_sent_at'],
                                $workflowTargets['dispatch_handoff'],
                                $complaint['dispatch_delay_reason'],
                                $databaseNow
                            );
                        $materialOutStage = $isRejected
                            ? null
                            : complaintWorkflowStageReport(
                                $complaint['dispatch_sent_at'],
                                $complaint['material_out_at'],
                                $workflowTargets['material_out'],
                                $complaint['material_out_delay_reason'],
                                $databaseNow
                            );
                        ?>
                        <tr>
                            <td>
                                <a href="complaint_workflow.php?id=<?php echo $complaintId; ?>"><?php echo escapeHtml(formatComplaintReference($complaintId, $complaint['public_reference'])); ?></a>
                                <br><?php echo escapeHtml($complaint['title']); ?>
                                <?php if ($complaint['so_number'] !== null): ?><br><small>SO: <?php echo escapeHtml($complaint['so_number']); ?></small><?php endif; ?>
                                <br><strong><?php echo escapeHtml($currentStage); ?></strong>
                            </td>
                            <td><?php if ($amStage !== null) { $renderStage($amStage); } else { echo 'Not applicable'; } ?></td>
                            <td>
                                <?php if ($departmentRows === []): ?>
                                    Not assigned
                                <?php else: ?>
                                    <?php foreach ($departmentRows as $assignment): ?>
                                        <?php if ((int) $assignment['approval_required'] === 0): ?>
                                            <div><strong><?php echo escapeHtml($assignment['department']); ?></strong><br><small>Legacy — approval not tracked</small></div>
                                        <?php else: ?>
                                            <?php $departmentStage = complaintWorkflowStageReport($assignment['assigned_at'], $assignment['approved_at'], $workflowTargets['department_approval'], $assignment['approval_reason'], $databaseNow); ?>
                                            <div><strong><?php echo escapeHtml($assignment['department']); ?></strong><br><?php $renderStage($departmentStage); ?></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php if ($isRejected) { echo 'Not applicable'; } elseif ($complaint['approval_status'] === 'Pending' || $departmentsPending) { echo 'Waiting for previous approvals'; } else { $renderStage($soStage); } ?></td>
                            <td><?php if ($isRejected) { echo 'Not applicable'; } elseif ($dispatchStage !== null) { $renderStage($dispatchStage); } else { echo 'Not started'; } ?></td>
                            <td><?php if ($isRejected) { echo 'Not applicable'; } elseif ($materialOutStage !== null) { $renderStage($materialOutStage); } else { echo 'Not started'; } ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($complaints === []): ?><tr><td colspan="6">No complaints match this date range.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
