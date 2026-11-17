<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin_navigation.php';
require_once __DIR__ . '/includes/complaint_filters.php';

requireAdministrator($currentUser);

$config = require __DIR__ . '/config/complaints.php';
$filterState = getComplaintFilterState(getComplaintScope($currentUser), $_GET);
$scope = $filterState['dashboardScope'];
$countRows = fetchComplaintRows(
    $conn,
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(c.status = 'Pending'), 0) AS pending,
        COALESCE(SUM(c.status IN ('Closed', 'Resolved')), 0) AS resolved,
        COALESCE(SUM(c.due_at IS NOT NULL AND c.due_at < NOW()
            AND c.status NOT IN ('Closed', 'Resolved', 'Rejected')), 0) AS overdue
     FROM complaints c WHERE {$scope['where']}",
    $scope
);
$metrics = $countRows[0];
$preview = fetchComplaintRows(
    $conn,
    "SELECT c.id, c.public_reference, c.title, c.complaint_type, c.product, c.district, c.status, c.created_at
     FROM complaints c
     WHERE {$scope['where']}
     ORDER BY c.created_at DESC, c.id DESC
     LIMIT 50",
    $scope
);
$exportQuery = http_build_query($filterState['activeFilters']);
$exportSuffix = $exportQuery !== '' ? '?' . $exportQuery : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Advanced reports | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/searchable-selects.js" defer></script>
</head>
<body>
<main class="dashboard-shell">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
        <div class="topbar-actions"><a href="dashboard.php" class="settings-button">Dashboard</a><?php renderNotificationLink($conn, (int) $currentUser['id']); ?><a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a></div>
    </header>
    <?php renderAdminNavigation('reports'); ?>
    <section class="page-panel admin-settings-panel">
        <div class="page-panel-heading"><div><p class="eyebrow">Administrator</p><h1 class="page-heading">Advanced reports</h1><p class="page-subtitle">Filters apply to the preview and to every record in the exports.</p></div></div>
        <?php if ($filterState['filterErrors'] !== []): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml(implode(' ', $filterState['filterErrors'])); ?></div><?php endif; ?>
        <form method="GET" class="admin-filters">
            <div class="field"><label for="report-search">Search</label><input id="report-search" type="search" name="search" maxlength="160" value="<?php echo escapeHtml($filterState['searchTerm']); ?>" placeholder="Search any complaint detail"></div>
            <div class="field"><label for="report-type">Complaint type</label><select id="report-type" name="type"><option value="">All types</option><?php foreach ($config['types'] as $type): ?><option value="<?php echo escapeHtml($type); ?>" <?php echo $filterState['typeFilter'] === $type ? 'selected' : ''; ?>><?php echo escapeHtml($type); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="report-product">Product (exact)</label><input id="report-product" name="product" maxlength="300" value="<?php echo escapeHtml($filterState['productFilter']); ?>" placeholder="Exact product name"></div>
            <div class="field"><label for="report-status">Status</label><select id="report-status" name="status"><option value="">All statuses</option><?php foreach (['Pending', 'In Progress', 'Resolved', 'Closed', 'Rejected'] as $status): ?><option value="<?php echo escapeHtml($status); ?>" <?php echo $filterState['statusFilter'] === $status ? 'selected' : ''; ?>><?php echo escapeHtml($status); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="report-district">District</label><select id="report-district" name="district"><option value="">All districts</option><?php foreach ($config['districts'] as $district): ?><option value="<?php echo escapeHtml($district); ?>" <?php echo $filterState['districtFilter'] === $district ? 'selected' : ''; ?>><?php echo escapeHtml($district); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="report-from">From</label><input id="report-from" type="date" name="from" value="<?php echo escapeHtml($filterState['fromFilter']); ?>"></div>
            <div class="field"><label for="report-to">To</label><input id="report-to" type="date" name="to" value="<?php echo escapeHtml($filterState['toFilter']); ?>"></div>
            <button type="submit" class="primary-button">Apply filters</button>
            <a class="secondary-button" href="admin_reports.php">Clear all</a>
        </form>
        <div class="admin-stat-grid">
            <div class="admin-stat-card"><span>Matching complaints</span><strong><?php echo (int) $metrics['total']; ?></strong></div>
            <div class="admin-stat-card"><span>Pending</span><strong><?php echo (int) $metrics['pending']; ?></strong></div>
            <div class="admin-stat-card"><span>Closed / resolved</span><strong><?php echo (int) $metrics['resolved']; ?></strong></div>
            <div class="admin-stat-card"><span>Overdue</span><strong><?php echo (int) $metrics['overdue']; ?></strong></div>
        </div>
        <div class="export-actions" aria-label="Export all matching complaints">
            <a class="secondary-button" href="export_complaints.php<?php echo escapeHtml($exportSuffix); ?>">Export all to Excel</a>
            <a class="secondary-button" href="export_complaints_pdf.php<?php echo escapeHtml($exportSuffix); ?>" target="_blank" rel="noopener noreferrer">Print / Save all as PDF</a>
        </div>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Matching complaint preview</h2><p>Showing up to 50 records; exports include all matches.</p></div></div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Complaint</th><th>Type</th><th>Product</th><th>District</th><th>Status</th><th>Created</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview as $complaint): ?>
                        <tr>
                            <td><a href="admin_edit_complaint.php?id=<?php echo (int) $complaint['id']; ?>"><?php echo escapeHtml(formatComplaintReference((int) $complaint['id'], $complaint['public_reference'])); ?></a><br><?php echo escapeHtml($complaint['title']); ?></td>
                            <td><?php echo escapeHtml($complaint['complaint_type']); ?></td>
                            <td><?php echo escapeHtml($complaint['product']); ?></td>
                            <td><?php echo escapeHtml($complaint['district']); ?></td>
                            <td><?php echo escapeHtml($complaint['status']); ?></td>
                            <td><?php echo $complaint['created_at'] ? escapeHtml(date('M j, Y', strtotime($complaint['created_at']))) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($preview === []): ?><tr><td colspan="6">No complaints match these filters.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
