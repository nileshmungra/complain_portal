<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin_navigation.php';
require_once __DIR__ . '/includes/complaint_filters.php';

requireAdministrator($currentUser);

$config = require __DIR__ . '/config/complaints.php';
$statusOptions = ['Pending', 'In Progress', 'Resolved', 'Closed', 'Rejected'];
$errorMessage = '';
$successMessage = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$csrfToken = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedIds = $_POST['complaint_ids'] ?? [];
    $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
    $replaceDepartments = ($_POST['replace_departments'] ?? '') === '1';
    $departments = $_POST['departments'] ?? [];
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif (
        !is_array($postedIds)
        || $postedIds === []
        || count($postedIds) > 100
        || array_filter($postedIds, static fn ($id) => filter_var($id, FILTER_VALIDATE_INT) === false) !== []
    ) {
        $errorMessage = 'Select between 1 and 100 complaints on this page.';
    } elseif ($status !== '' && !in_array($status, $statusOptions, true)) {
        $errorMessage = 'Select a valid complaint status.';
    } elseif (
        !is_array($departments)
        || array_filter($departments, static fn ($department) => !is_string($department)) !== []
        || array_diff($departments, $config['departments']) !== []
        || ($replaceDepartments && $departments === [])
    ) {
        $errorMessage = 'Select at least one valid department when replacing assignments.';
    } elseif ($status === '' && !$replaceDepartments) {
        $errorMessage = 'Choose a status change, a department replacement, or both.';
    } else {
        $complaintIds = array_values(array_unique(array_map('intval', $postedIds)));
        $placeholders = implode(',', array_fill(0, count($complaintIds), '?'));
        $selectStatement = $conn->prepare(
            "SELECT id, public_reference, created_by, title, status, department
             FROM complaints WHERE id IN ({$placeholders}) FOR UPDATE"
        );
        $parameters = array_merge([str_repeat('i', count($complaintIds))], $complaintIds);
        $references = [];
        foreach ($parameters as $key => &$parameter) {
            $references[$key] = &$parameter;
        }
        unset($parameter);
        call_user_func_array([$selectStatement, 'bind_param'], $references);

        $conn->begin_transaction();
        try {
            $selectStatement->execute();
            $selectedComplaints = $selectStatement->get_result()->fetch_all(MYSQLI_ASSOC);
            if (count($selectedComplaints) !== count($complaintIds)) {
                throw new RuntimeException('One or more selected complaints no longer exist. Refresh and try again.');
            }

            $statusStatement = $status !== ''
                ? $conn->prepare(
                    "UPDATE complaints
                     SET status = ?,
                         resolved_at = IF(? IN ('Closed', 'Resolved'), COALESCE(resolved_at, NOW()), NULL)
                     WHERE id = ?"
                )
                : null;
            $deleteAssignments = $replaceDepartments
                ? $conn->prepare('DELETE FROM complaint_department_assignments WHERE complaint_id = ?')
                : null;
            $insertAssignment = $replaceDepartments
                ? $conn->prepare('INSERT INTO complaint_department_assignments (complaint_id, department) VALUES (?, ?)')
                : null;
            $statusChangedCount = 0;
            foreach ($selectedComplaints as $complaint) {
                $complaintId = (int) $complaint['id'];
                $beforeDepartments = array_values(array_filter(array_map('trim', explode(',', $complaint['department']))));
                $statusChanged = $status !== '' && $status !== $complaint['status'];
                $newDepartments = $replaceDepartments ? array_values(array_unique($departments)) : $beforeDepartments;
                $departmentsChanged = $replaceDepartments
                    && (
                        array_diff($newDepartments, $beforeDepartments) !== []
                        || array_diff($beforeDepartments, $newDepartments) !== []
                    );

                if ($status !== '') {
                    $statusStatement->bind_param('ssi', $status, $status, $complaintId);
                    $statusStatement->execute();
                }
                if ($replaceDepartments) {
                    $deleteAssignments->bind_param('i', $complaintId);
                    $deleteAssignments->execute();
                    foreach ($newDepartments as $department) {
                        $insertAssignment->bind_param('is', $complaintId, $department);
                        $insertAssignment->execute();
                    }
                    $departmentRoute = implode(', ', $newDepartments);
                    $updateRoute = $conn->prepare('UPDATE complaints SET department = ? WHERE id = ?');
                    $updateRoute->bind_param('si', $departmentRoute, $complaintId);
                    $updateRoute->execute();
                }

                if ($statusChanged) {
                    $statusChangedCount++;
                    notifyUserIds(
                        $conn,
                        [(int) $complaint['created_by']],
                        'status',
                        'Complaint status updated',
                        formatComplaintReference($complaintId, $complaint['public_reference']) . ' is now ' . $status . '.',
                        $complaintId
                    );
                }
                if ($departmentsChanged) {
                    notifyComplaintDepartments(
                        $conn,
                        array_values(array_diff($newDepartments, $beforeDepartments)),
                        'assignment',
                        'Complaint assigned to your department',
                        formatComplaintReference($complaintId, $complaint['public_reference']) . ' has been assigned to ' . implode(', ', $newDepartments) . '.',
                        $complaintId
                    );
                }
                if ($statusChanged || $departmentsChanged) {
                    recordAuditEvent(
                        $conn,
                        (int) $currentUser['id'],
                        'complaint.bulk_updated',
                        'complaint',
                        $complaintId,
                        formatComplaintReference($complaintId, $complaint['public_reference']) . ' was updated through bulk actions.',
                        [
                            'before' => ['status' => $complaint['status'], 'departments' => $beforeDepartments],
                            'after' => [
                                'status' => $status !== '' ? $status : $complaint['status'],
                                'departments' => $newDepartments
                            ]
                        ]
                    );
                }
            }
            $conn->commit();
            $updatedCount = count($selectedComplaints);
            $_SESSION['flash_success'] = "{$updatedCount} complaint(s) processed; {$statusChangedCount} status(es) changed.";
            header('Location: admin_bulk_complaints.php?' . http_build_query($_GET) . '#complaints');
            exit;
        } catch (RuntimeException $exception) {
            $conn->rollback();
            $errorMessage = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log('Bulk complaint action failed: ' . $exception->getMessage());
            $errorMessage = 'The selected complaints could not be updated because of a database error.';
        }
    }
}

$filterState = getComplaintFilterState(getComplaintScope($currentUser), $_GET);
$scope = $filterState['dashboardScope'];
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = is_int($page) && $page > 0 ? $page : 1;
$pageSize = 100;
$countRows = fetchComplaintRows($conn, "SELECT COUNT(*) AS total FROM complaints c WHERE {$scope['where']}", $scope);
$totalRows = (int) $countRows[0]['total'];
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;
$complaints = fetchComplaintRows(
    $conn,
    "SELECT c.id, c.public_reference, c.title, c.farmer_name, c.district, c.department, c.status, c.created_at
     FROM complaints c
     WHERE {$scope['where']}
     ORDER BY c.created_at DESC, c.id DESC
     LIMIT {$pageSize} OFFSET {$offset}",
    $scope
);
$filterQuery = $filterState['activeFilters'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Bulk complaint actions | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/searchable-selects.js" defer></script>
</head>
<body>
<main class="dashboard-shell">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
        <div class="topbar-actions"><a href="dashboard.php" class="settings-button">Dashboard</a><?php renderNotificationLink($conn, (int) $currentUser['id']); ?><a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a></div>
    </header>
    <?php renderAdminNavigation('bulk'); ?>
    <section class="page-panel admin-settings-panel" id="complaints">
        <div class="page-panel-heading"><div><p class="eyebrow">Administrator</p><h1 class="page-heading">Bulk complaint actions</h1><p class="page-subtitle">Select up to 100 complaints from the current page. Individual complaint history is retained.</p></div></div>
        <?php if ($successMessage !== ''): ?><div class="notice notice--success" role="status"><?php echo escapeHtml($successMessage); ?></div><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div><?php endif; ?>
        <form method="GET" class="admin-filters" role="search">
            <div class="field"><label for="bulk-search">Search</label><input id="bulk-search" name="search" value="<?php echo escapeHtml($filterState['searchTerm']); ?>" maxlength="160" placeholder="ID, farmer, product, or title"></div>
            <div class="field"><label for="bulk-status">Status</label><select id="bulk-status" name="status"><option value="">All statuses</option><?php foreach ($statusOptions as $status): ?><option value="<?php echo escapeHtml($status); ?>" <?php echo ($filterState['statusFilter'] ?? '') === $status ? 'selected' : ''; ?>><?php echo escapeHtml($status); ?></option><?php endforeach; ?></select></div>
            <button class="secondary-button" type="submit">Filter</button>
        </form>
        <form method="POST" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
            <?php foreach ($filterQuery as $key => $value): ?><input type="hidden" name="return_<?php echo escapeHtml($key); ?>" value="<?php echo escapeHtml($value); ?>"><?php endforeach; ?>
            <div class="admin-section-heading"><div><h2><?php echo $totalRows; ?> matching complaint(s)</h2><p>Page <?php echo $page; ?> of <?php echo $totalPages; ?>. Select all on this page or choose individual records.</p></div></div>
            <label class="admin-select-row"><input id="select-all-complaints" type="checkbox">Select all complaints on this page</label>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Select</th><th>Complaint</th><th>Farmer / district</th><th>Departments</th><th>Status</th><th>Created</th></tr></thead>
                    <tbody>
                    <?php foreach ($complaints as $complaint): ?>
                        <tr>
                            <td><input class="bulk-complaint-checkbox" type="checkbox" name="complaint_ids[]" value="<?php echo (int) $complaint['id']; ?>" aria-label="Select complaint <?php echo escapeHtml(formatComplaintReference((int) $complaint['id'], $complaint['public_reference'])); ?>"></td>
                            <td><a href="admin_edit_complaint.php?id=<?php echo (int) $complaint['id']; ?>"><?php echo escapeHtml(formatComplaintReference((int) $complaint['id'], $complaint['public_reference'])); ?></a><br><?php echo escapeHtml($complaint['title']); ?></td>
                            <td><?php echo escapeHtml($complaint['farmer_name']); ?><br><small><?php echo escapeHtml($complaint['district']); ?></small></td>
                            <td><?php echo escapeHtml($complaint['department']); ?></td>
                            <td><?php echo escapeHtml($complaint['status']); ?></td>
                            <td><?php echo $complaint['created_at'] ? escapeHtml(date('M j, Y', strtotime($complaint['created_at']))) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($complaints === []): ?><tr><td colspan="6">No complaints match these filters.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($complaints !== []): ?>
                <section class="admin-settings-section">
                    <h2>Apply actions</h2>
                    <div class="admin-filters">
                        <div class="field"><label for="bulk-new-status">Set status (optional)</label><select id="bulk-new-status" name="status"><option value="">Do not change status</option><?php foreach ($statusOptions as $status): ?><option value="<?php echo escapeHtml($status); ?>"><?php echo escapeHtml($status); ?></option><?php endforeach; ?></select></div>
                    </div>
                    <label class="admin-select-row"><input type="checkbox" name="replace_departments" value="1" id="replace-departments">Replace department assignments</label>
                    <div class="admin-check-list"><?php foreach ($config['departments'] as $department): ?><label><input type="checkbox" name="departments[]" value="<?php echo escapeHtml($department); ?>"><?php echo escapeHtml($department); ?></label><?php endforeach; ?></div>
                    <button class="primary-button" type="submit" onclick="return confirm('Apply these changes to the selected complaints?');">Apply to selected</button>
                </section>
            <?php endif; ?>
        </form>
        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Complaint selection pages">
                <?php if ($page > 1): ?><a class="secondary-button" href="admin_bulk_complaints.php?<?php echo http_build_query($filterQuery + ['page' => $page - 1]); ?>">Previous</a><?php endif; ?>
                <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                <?php if ($page < $totalPages): ?><a class="secondary-button" href="admin_bulk_complaints.php?<?php echo http_build_query($filterQuery + ['page' => $page + 1]); ?>">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
<script>
    const selectAll = document.getElementById('select-all-complaints');
    selectAll?.addEventListener('change', () => {
        document.querySelectorAll('.bulk-complaint-checkbox').forEach((checkbox) => {
            checkbox.checked = selectAll.checked;
        });
    });
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
