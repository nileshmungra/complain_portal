<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/complaint_filters.php';

notifyOverdueComplaints($conn);
$dashboardOptions = require __DIR__ . '/config/complaints.php';

function buildDashboardUrl(array $filters): string
{
    $filters = array_filter($filters, static fn ($value) => $value !== '');
    $query = http_build_query($filters);

    return 'dashboard.php' . ($query !== '' ? '?' . $query : '') . '#complaint-list';
}

$scope = getComplaintScope($currentUser);
$filterState = getComplaintFilterState($scope, $_GET);
$searchTerm = $filterState['searchTerm'];
$searchTerms = $filterState['searchTerms'];
$typeFilter = $filterState['typeFilter'];
$productFilter = $filterState['productFilter'];
$statusFilter = $filterState['statusFilter'];
$districtFilter = $filterState['districtFilter'];
$fromFilter = $filterState['fromFilter'];
$toFilter = $filterState['toFilter'];
$filterErrors = $filterState['filterErrors'];
$activeFilters = $filterState['activeFilters'];
$dashboardScope = $filterState['dashboardScope'];
$typeChartScope = $filterState['typeChartScope'];
$productChartScope = $filterState['productChartScope'];
$hasActiveFilters = $typeFilter !== '' || $productFilter !== '' || $statusFilter !== ''
    || $districtFilter !== '' || $fromFilter !== '' || $toFilter !== '';
$hasAdvancedFilters = $statusFilter !== '' || $districtFilter !== '' || $fromFilter !== '' || $toFilter !== '';
$allStatusFilters = $activeFilters;
unset($allStatusFilters['status']);
$pendingFilters = $allStatusFilters;
$pendingFilters['status'] = 'Pending';
$clearFilterParams = $activeFilters;
unset(
    $clearFilterParams['type'],
    $clearFilterParams['product'],
    $clearFilterParams['status'],
    $clearFilterParams['district'],
    $clearFilterParams['from'],
    $clearFilterParams['to']
);
$clearSearchParams = $activeFilters;
unset($clearSearchParams['search']);
$exportQuery = http_build_query($activeFilters);
$exportSuffix = $exportQuery !== '' ? '?' . $exportQuery : '';
$roleLabels = [
    'dealer' => 'Dealer',
    'area_manager' => 'Area Manager',
    'department' => 'Department',
    'admin' => 'Administrator'
];
$roleName = $roleLabels[$currentUser['role']] ?? 'Team Member';
$successMessage = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

$metrics = fetchComplaintRows(
    $conn,
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(c.status = 'Pending'), 0) AS pending_count
     FROM complaints c
     WHERE {$dashboardScope['where']}",
    $dashboardScope
)[0];
$pendingApprovalCount = 0;
if ($currentUser['role'] === 'area_manager') {
    $pendingApprovalCount = (int) fetchComplaintRows(
        $conn,
        "SELECT COUNT(*) AS total
         FROM complaint_approvals ca
         INNER JOIN complaints c ON c.id = ca.complaint_id
         WHERE ca.status = 'Pending' AND {$scope['where']}",
        $scope
    )[0]['total'];
}
$caseKeyExpression = "CASE WHEN c.farmer_mobile IS NOT NULL AND c.farmer_mobile <> '' "
    . "THEN CONCAT('farmer:', c.farmer_mobile) ELSE CONCAT('complaint:', c.id) END";
$caseMetrics = fetchComplaintRows(
    $conn,
    "SELECT COUNT(DISTINCT {$caseKeyExpression}) AS total
     FROM complaints c
     WHERE {$dashboardScope['where']}",
    $dashboardScope
)[0];
$totalFarmerEntries = (int) $caseMetrics['total'];
$pageSize = 20;
$requestedPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$currentPage = is_int($requestedPage) && $requestedPage > 0 ? $requestedPage : 1;
$totalPages = max(1, (int) ceil($totalFarmerEntries / $pageSize));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $pageSize;
$farmerCasePage = fetchComplaintRows(
    $conn,
    "SELECT {$caseKeyExpression} AS group_key, MAX(c.created_at) AS latest_created_at, MAX(c.id) AS latest_id
     FROM complaints c
     WHERE {$dashboardScope['where']}
     GROUP BY group_key
     ORDER BY latest_created_at DESC, latest_id DESC
     LIMIT {$pageSize} OFFSET {$offset}",
    $dashboardScope
);
$farmerMobiles = [];
$standaloneComplaintIds = [];
foreach ($farmerCasePage as $farmerCase) {
    $groupKey = (string) $farmerCase['group_key'];
    if (str_starts_with($groupKey, 'farmer:')) {
        $farmerMobiles[] = substr($groupKey, strlen('farmer:'));
    } elseif (str_starts_with($groupKey, 'complaint:')) {
        $standaloneComplaintIds[] = (int) substr($groupKey, strlen('complaint:'));
    }
}
$issueConditions = [];
$issueScope = $scope;
if ($farmerMobiles !== []) {
    $issueConditions[] = 'c.farmer_mobile IN (' . implode(', ', array_fill(0, count($farmerMobiles), '?')) . ')';
    $issueScope['types'] .= str_repeat('s', count($farmerMobiles));
    $issueScope['values'] = array_merge($issueScope['values'], $farmerMobiles);
}
if ($standaloneComplaintIds !== []) {
    $issueConditions[] = 'c.id IN (' . implode(', ', array_fill(0, count($standaloneComplaintIds), '?')) . ')';
    $issueScope['types'] .= str_repeat('i', count($standaloneComplaintIds));
    $issueScope['values'] = array_merge($issueScope['values'], $standaloneComplaintIds);
}
$issueWhere = implode(' OR ', $issueConditions);
$complaints = $issueConditions === []
    ? []
    : fetchComplaintRows(
        $conn,
        "SELECT c.id, c.public_reference, c.title, c.farmer_name, c.farmer_mobile, c.state, c.village, c.taluka,
            c.complaint_type, c.product, c.supply_date, c.quantity_supplied,
            c.quantity_unit, c.actual_product, c.actual_quantity_supplied, c.actual_quantity_unit,
            c.invoice_reference, c.wo_number, c.district, c.department, c.status,
            c.details,
            c.created_at, u.full_name AS submitted_by,
            d.dealer_code AS selected_dealer_code, d.name AS selected_dealer_name,
            l.source_id AS legacy_source_id, l.dealer_name AS legacy_dealer_name,
            l.area_manager_name AS legacy_area_manager_name,
            l.created_by_name AS legacy_created_by_name
     FROM complaints c
     INNER JOIN users u ON u.id = c.created_by
     LEFT JOIN dealers d ON d.id = c.dealer_id
     LEFT JOIN legacy_complaint_data l ON l.complaint_id = c.id
     WHERE ({$scope['where']}) AND ({$issueWhere})
     ORDER BY c.created_at DESC, c.id DESC",
        $issueScope
    );
$complaintGroups = [];
foreach ($complaints as $complaint) {
    $groupKey = is_string($complaint['farmer_mobile']) && $complaint['farmer_mobile'] !== ''
        ? 'farmer:' . $complaint['farmer_mobile']
        : 'complaint:' . $complaint['id'];
    $complaintGroups[$groupKey][] = $complaint;
}
$attachmentsByComplaintId = [];
if ($complaints !== []) {
    $complaintIds = array_map(static fn ($complaint) => (int) $complaint['id'], $complaints);
    $complaintIdList = implode(',', $complaintIds);
    $attachments = fetchComplaintRows(
        $conn,
        "SELECT a.id, c.id AS complaint_id, a.category, a.original_name, a.mime_type, a.file_size
         FROM complaint_attachments a
         INNER JOIN complaints c ON c.id = a.complaint_id
         WHERE c.id IN ({$complaintIdList}) AND {$scope['where']}
         ORDER BY a.id",
        $scope
    );
    foreach ($attachments as $attachment) {
        $attachmentsByComplaintId[(int) $attachment['complaint_id']][] = $attachment;
    }
}
$rawTypeCounts = fetchComplaintRows(
    $conn,
    "SELECT c.complaint_type AS label, COUNT(*) AS total
     FROM complaints c
     WHERE {$typeChartScope['where']}
     GROUP BY c.complaint_type
     ORDER BY label",
    $typeChartScope
);
$typeCountTotals = array_fill_keys($dashboardOptions['types'], 0);
foreach ($rawTypeCounts as $item) {
    $complaintTypes = [];
    foreach (explode(',', (string) $item['label']) as $rawType) {
        $type = match (mb_strtolower(trim($rawType), 'UTF-8')) {
            'material fault' => 'Material Fault',
            'short supply' => 'Short Supply',
            'wrong dispatch' => 'Wrong Dispatch',
            'exceed supply', 'excess material' => 'Exceed Supply',
            'other' => 'Other',
            default => 'Other'
        };
        $complaintTypes[$type] = true;
    }
    foreach (array_keys($complaintTypes) as $type) {
        $typeCountTotals[$type] += (int) $item['total'];
    }
}
$typeCounts = [];
foreach ($typeCountTotals as $label => $total) {
    if ($total > 0) {
        $typeCounts[] = ['label' => $label, 'total' => $total];
    }
}
usort($typeCounts, static fn ($left, $right) => $right['total'] <=> $left['total'] ?: strcmp($left['label'], $right['label']));
$productCounts = fetchComplaintRows(
    $conn,
    "SELECT c.product AS label, COUNT(*) AS total
     FROM complaints c
     WHERE {$productChartScope['where']}
     GROUP BY c.product
     ORDER BY total DESC, label
     LIMIT 8",
    $productChartScope
);

function renderComplaintChart(array $items, string $filter, array $activeFilters): void
{
    if ($items === []) {
        echo '<p class="empty-state">Complaint data will appear here once a complaint is submitted.</p>';
        return;
    }

    $maxCount = max(array_map(static fn ($item) => (int) $item['total'], $items));
    echo '<ul class="chart-list">';
    foreach ($items as $item) {
        $label = (string) $item['label'];
        $count = (int) $item['total'];
        $width = $maxCount > 0 ? max(5, (int) round(($count / $maxCount) * 100)) : 0;
        $isSelected = ($activeFilters[$filter] ?? '') === $label;
        $linkFilters = $activeFilters;
        if ($isSelected) {
            unset($linkFilters[$filter]);
        } else {
            $linkFilters[$filter] = $label;
        }
        $url = buildDashboardUrl($linkFilters);

        echo '<li class="chart-item">';
        echo '<a class="chart-filter-link' . ($isSelected ? ' is-active' : '') . '" href="' . escapeHtml($url) . '" aria-label="Show ' . $count . ' complaints for ' . escapeHtml($label) . '"' . ($isSelected ? ' aria-current="true"' : '') . '>';
        echo '<div class="chart-label"><span>' . escapeHtml($label) . '</span><strong>' . $count . '</strong></div>';
        echo '<div class="chart-track" aria-hidden="true"><span class="chart-fill" style="width: ' . $width . '%"></span></div>';
        echo '</a>';
        echo '</li>';
    }
    echo '</ul>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Dashboard | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/searchable-selects.js" defer></script>
    <script src="assets/js/dashboard-tabs.js" defer></script>
    <script src="assets/js/complaint-details.js" defer></script>
</head>
<body>
    <main class="dashboard-shell">
        <header class="topbar">
            <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
            <div class="topbar-actions">
                <span class="topbar-user"><?php echo escapeHtml($currentUser['full_name']); ?></span>
                <?php if ($currentUser['role'] === 'admin'): ?>
                    <a href="admin_settings.php" class="settings-button">Settings</a>
                <?php endif; ?>
                <?php if ($currentUser['role'] === 'area_manager'): ?>
                    <a href="area_manager_approvals.php" class="settings-button notification-link" aria-label="Complaint approvals<?php echo $pendingApprovalCount > 0 ? ', ' . $pendingApprovalCount . ' pending' : ''; ?>">
                        Approvals
                        <?php if ($pendingApprovalCount > 0): ?><span class="notification-badge"><?php echo min($pendingApprovalCount, 99); ?><?php echo $pendingApprovalCount > 99 ? '+' : ''; ?></span><?php endif; ?>
                    </a>
                <?php endif; ?>
                <?php renderNotificationLink($conn, (int) $currentUser['id']); ?>
                <a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a>
            </div>
        </header>

        <section class="dashboard-hero dashboard-hero--row">
            <div>
                <p class="eyebrow">Your workspace</p>
                <h1 class="dashboard-title">Complaint dashboard</h1>
                <p class="dashboard-greeting">Welcome back, <?php echo escapeHtml($currentUser['full_name']); ?>. Here is your complaint overview.</p>
            </div>
            <a href="create_complaint.php" class="primary-button primary-button--light">＋ New complaint</a>
        </section>

        <?php if ($successMessage !== ''): ?>
            <div class="notice notice--success dashboard-notice" role="status"><?php echo escapeHtml($successMessage); ?></div>
        <?php endif; ?>

        <div class="dashboard-tabs" role="tablist" aria-label="Dashboard views" data-dashboard-tabs>
            <button
                id="complaints-tab"
                class="dashboard-tab"
                type="button"
                role="tab"
                aria-selected="true"
                aria-controls="complaints-panel"
                tabindex="0"
            >Complaint list</button>
            <button
                id="insights-tab"
                class="dashboard-tab"
                type="button"
                role="tab"
                aria-selected="false"
                aria-controls="insights-panel"
                tabindex="-1"
            >Insights</button>
        </div>
        <section id="complaints-panel" class="dashboard-tab-panel" role="tabpanel" aria-labelledby="complaints-tab" tabindex="0">
            <?php if ($filterErrors !== []): ?>
                <div class="notice notice--error" role="alert"><?php echo escapeHtml(implode(' ', $filterErrors)); ?></div>
            <?php endif; ?>

        <section class="section-heading complaints-heading" id="complaint-list">
            <div>
                <p class="eyebrow">Searchable history</p>
                <h2>Complaint list</h2>
            </div>
            <?php
            $rangeStart = $farmerCasePage === [] ? 0 : $offset + 1;
            $rangeEnd = $offset + count($farmerCasePage);
            ?>
            <div class="complaint-list-actions">
                <div class="export-actions" aria-label="Export filtered complaints">
                    <a class="secondary-button" href="export_complaints.php<?php echo escapeHtml($exportSuffix); ?>">Export Excel</a>
                    <a class="secondary-button" href="export_complaints_pdf.php<?php echo escapeHtml($exportSuffix); ?>" target="_blank" rel="noopener noreferrer">PDF / Print</a>
                </div>
                <span class="results-count"><?php echo $rangeStart; ?>–<?php echo $rangeEnd; ?> of <?php echo $totalFarmerEntries; ?> farmer entries</span>
            </div>
        </section>
        <?php if ($hasActiveFilters): ?>
            <div class="complaint-filter-summary" role="status">
                <span>Farmer entries with matching complaints:</span>
                <?php if ($typeFilter !== ''): ?><span class="filter-chip">Type: <?php echo escapeHtml($typeFilter); ?></span><?php endif; ?>
                <?php if ($productFilter !== ''): ?><span class="filter-chip">Product: <?php echo escapeHtml($productFilter); ?></span><?php endif; ?>
                <?php if ($statusFilter !== ''): ?><span class="filter-chip">Status: <?php echo escapeHtml($statusFilter); ?></span><?php endif; ?>
                <?php if ($districtFilter !== ''): ?><span class="filter-chip">District: <?php echo escapeHtml($districtFilter); ?></span><?php endif; ?>
                <?php if ($fromFilter !== ''): ?><span class="filter-chip">From: <?php echo escapeHtml($fromFilter); ?></span><?php endif; ?>
                <?php if ($toFilter !== ''): ?><span class="filter-chip">To: <?php echo escapeHtml($toFilter); ?></span><?php endif; ?>
                <a href="<?php echo escapeHtml(buildDashboardUrl($clearFilterParams)); ?>">Clear filters</a>
            </div>
        <?php endif; ?>
        <form class="dashboard-card complaint-search" method="GET" action="dashboard.php" role="search">
            <label for="complaint-search">Search complaints and attachments</label>
            <div class="complaint-search-controls">
                <span class="search-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="10.8" cy="10.8" r="6.8"></circle>
                        <path d="m16 16 4.5 4.5"></path>
                    </svg>
                </span>
                <input
                    id="complaint-search"
                    type="search"
                    name="search"
                    value="<?php echo escapeHtml($searchTerm); ?>"
                    maxlength="160"
                    placeholder="Search name, farmer mobile, ID, WO No., product, village, status, file name..."
                    autocomplete="off"
                >
                <?php foreach (['type', 'product', 'status', 'district', 'from', 'to'] as $filterKey): ?>
                    <?php if (($activeFilters[$filterKey] ?? '') !== ''): ?>
                        <input type="hidden" name="<?php echo $filterKey; ?>" value="<?php echo escapeHtml($activeFilters[$filterKey]); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <button class="primary-button" type="submit">Search</button>
            </div>
            <div class="complaint-search-footer">
                <p>Search up to 8 words; each can match a different field, including legacy history and attachment names.</p>
                <?php if ($searchTerm !== ''): ?>
                    <a href="<?php echo escapeHtml(buildDashboardUrl($clearSearchParams)); ?>">Clear search</a>
                <?php endif; ?>
            </div>
        </form>
        <details class="dashboard-card advanced-filter-panel" <?php echo $hasAdvancedFilters ? 'open' : ''; ?>>
            <summary class="advanced-filter-summary">
                <span>
                    <strong>Advanced filters</strong>
                    <small>Filter by complaint status and submission date.</small>
                </span>
                <span class="advanced-filter-chevron" aria-hidden="true">⌄</span>
            </summary>
            <form method="GET" action="dashboard.php">
                <?php foreach (['search', 'type', 'product'] as $filterKey): ?>
                    <?php if (($activeFilters[$filterKey] ?? '') !== ''): ?>
                        <input type="hidden" name="<?php echo $filterKey; ?>" value="<?php echo escapeHtml($activeFilters[$filterKey]); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="admin-filters">
                    <div class="field">
                        <label for="filter-status">Status</label>
                        <select id="filter-status" name="status">
                            <option value="">All statuses</option>
                            <?php foreach (['Pending', 'In Progress', 'Resolved', 'Closed', 'Rejected'] as $status): ?>
                                <option value="<?php echo escapeHtml($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo escapeHtml($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label for="filter-from">From</label><input id="filter-from" type="date" name="from" value="<?php echo escapeHtml($fromFilter); ?>"></div>
                    <div class="field"><label for="filter-to">To</label><input id="filter-to" type="date" name="to" value="<?php echo escapeHtml($toFilter); ?>"></div>
                    <button class="primary-button" type="submit">Apply filters</button>
                </div>
            </form>
        </details>

        <section class="dashboard-card complaint-list-panel">
            <?php if ($complaints === []): ?>
                <div class="table-empty">
                    <span class="empty-icon" aria-hidden="true">▤</span>
                    <?php if ($searchTerm !== '' || $hasActiveFilters): ?>
                        <h3>No matching complaints</h3>
                        <p>Try a different search or remove the selected filters. Results are limited to complaints visible to your account.</p>
                        <?php if ($hasActiveFilters): ?>
                            <a href="<?php echo escapeHtml(buildDashboardUrl($clearFilterParams)); ?>" class="secondary-button">Clear filters</a>
                        <?php endif; ?>
                        <?php if ($searchTerm !== ''): ?>
                            <a href="<?php echo escapeHtml(buildDashboardUrl($clearSearchParams)); ?>" class="secondary-button">Clear search</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <h3>No complaints yet</h3>
                        <p>Create a complaint to see it listed here and included in your analysis.</p>
                        <a href="create_complaint.php" class="secondary-button">Create your first complaint</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="complaint-table-wrap">
                    <table class="complaint-table">
                        <thead>
                            <tr>
                                <th scope="col">Complaint ID</th>
                                <th scope="col">Farmer</th>
                                <th scope="col">Complaint</th>
                                <th scope="col">District</th>
                                <th scope="col">Status</th>
                                <th scope="col">Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                    <?php foreach ($complaintGroups as $groupComplaints): ?>
                    <?php foreach ($groupComplaints as $complaint): ?>
                        <?php
                        $statusClass = match ($complaint['status']) {
                            'Open', 'Pending' => 'status-open',
                            'In Progress' => 'status-progress',
                            'Resolved', 'Closed' => 'status-resolved',
                            'Rejected' => 'status-rejected',
                            default => 'status-open'
                        };
                        $complaintAttachments = $attachmentsByComplaintId[(int) $complaint['id']] ?? [];
                        ?>
                        <tr class="complaint-table-row">
                            <td data-label="Complaint ID">
                                <button
                                    class="complaint-table-id"
                                    type="button"
                                    data-complaint-details-trigger="complaint-details-template-<?php echo (int) $complaint['id']; ?>"
                                    aria-haspopup="dialog"
                                    aria-controls="complaint-details-dialog"
                                    aria-expanded="false"
                                ><?php echo escapeHtml(formatComplaintReference((int) $complaint['id'], $complaint['public_reference'])); ?></button>
                            </td>
                            <td data-label="Farmer">
                                <strong><?php echo escapeHtml($complaint['farmer_name']); ?></strong>
                                <small><?php echo escapeHtml($complaint['farmer_mobile'] ?: 'Mobile unavailable'); ?></small>
                            </td>
                            <td data-label="Complaint">
                                <strong><?php echo escapeHtml($complaint['title']); ?></strong>
                                <small><?php echo escapeHtml($complaint['complaint_type']); ?> · <?php echo escapeHtml($complaint['product']); ?></small>
                                <?php if ($complaint['wo_number']): ?>
                                    <small>Reg / WO No.: <?php echo escapeHtml($complaint['wo_number']); ?></small>
                                <?php endif; ?>
                                <?php if ($complaint['complaint_type'] === 'Wrong Dispatch' && $complaint['actual_product']): ?>
                                    <small>Actually supplied: <?php echo escapeHtml($complaint['actual_product']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td data-label="District"><?php echo escapeHtml($complaint['district']); ?></td>
                            <td data-label="Status"><span class="status-pill <?php echo $statusClass; ?>"><?php echo escapeHtml($complaint['status']); ?></span></td>
                            <td data-label="Submitted">
                                <?php if (is_string($complaint['created_at']) && $complaint['created_at'] !== ''): ?>
                                    <?php echo escapeHtml(date('M j, Y', strtotime($complaint['created_at']))); ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr class="complaint-details-row" hidden>
                            <td colspan="6">
                                <template id="complaint-details-template-<?php echo (int) $complaint['id']; ?>">
                        <article class="complaint-card">
                            <header class="complaint-card-header">
                                <div class="complaint-card-heading">
                                    <span class="complaint-number"><?php echo escapeHtml(formatComplaintReference((int) $complaint['id'], $complaint['public_reference'])); ?></span>
                                    <h3 class="complaint-card-title"><?php echo escapeHtml($complaint['title']); ?></h3>
                                    <?php if ($complaint['legacy_source_id'] !== null): ?>
                                        <a class="legacy-record-link" href="legacy_complaint.php?id=<?php echo (int) $complaint['id']; ?>">Legacy ID: <?php echo escapeHtml($complaint['legacy_source_id']); ?> · view original</a>
                                    <?php endif; ?>
                                </div>
                                <div class="complaint-card-status">
                                    <span class="status-pill <?php echo $statusClass; ?>"><?php echo escapeHtml($complaint['status']); ?></span>
                                    <?php if (is_string($complaint['created_at']) && $complaint['created_at'] !== ''): ?>
                                        <time datetime="<?php echo escapeHtml($complaint['created_at']); ?>"><?php echo date('M j, Y', strtotime($complaint['created_at'])); ?></time>
                                    <?php else: ?>
                                        <span>Date unavailable</span>
                                    <?php endif; ?>
                                </div>
                            </header>

                            <section class="complaint-detail-section">
                                <h4 class="complaint-detail-section-title">Farmer &amp; location</h4>
                                <dl class="complaint-detail-grid">
                                    <div class="complaint-detail"><dt>Farmer</dt><dd><?php echo escapeHtml($complaint['farmer_name']); ?></dd></div>
                                    <div class="complaint-detail"><dt>Mobile</dt><dd><?php echo escapeHtml($complaint['farmer_mobile'] ?: '—'); ?></dd></div>
                                    <div class="complaint-detail"><dt>Village / Taluka / District / State</dt><dd><?php echo escapeHtml(implode(', ', array_filter([$complaint['village'], $complaint['taluka'], $complaint['district'], $complaint['state'] ?? null], static fn ($value) => $value !== null && $value !== ''))); ?></dd></div>
                                </dl>
                            </section>

                            <section class="complaint-detail-section">
                                <h4 class="complaint-detail-section-title">Complaint &amp; material</h4>
                                <dl class="complaint-detail-grid">
                                    <div class="complaint-detail"><dt>Complaint type</dt><dd><?php echo escapeHtml($complaint['complaint_type']); ?></dd></div>
                                    <div class="complaint-detail">
                                        <dt><?php echo $complaint['complaint_type'] === 'Wrong Dispatch' ? 'Wrongly dispatched material' : 'Product'; ?></dt>
                                        <dd><?php echo escapeHtml($complaint['product']); ?></dd>
                                    </div>
                                    <?php if ($complaint['complaint_type'] === 'Wrong Dispatch'): ?>
                                        <div class="complaint-detail"><dt>Actually supplied material</dt><dd><?php echo escapeHtml($complaint['actual_product'] ?: '—'); ?></dd></div>
                                    <?php endif; ?>
                                    <div class="complaint-detail">
                                        <dt><?php echo $complaint['complaint_type'] === 'Wrong Dispatch' ? 'Wrongly dispatched quantity' : 'Quantity supplied'; ?></dt>
                                        <dd>
                                            <?php if ($complaint['quantity_supplied'] !== null): ?>
                                                <?php echo escapeHtml(rtrim(rtrim(number_format((float) $complaint['quantity_supplied'], 3, '.', ''), '0'), '.')); ?>
                                                <?php echo escapeHtml($complaint['quantity_unit']); ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </dd>
                                    </div>
                                    <?php if ($complaint['complaint_type'] === 'Wrong Dispatch'): ?>
                                        <div class="complaint-detail">
                                            <dt>Actual supplied quantity</dt>
                                            <dd>
                                                <?php if ($complaint['actual_quantity_supplied'] !== null): ?>
                                                    <?php echo escapeHtml(rtrim(rtrim(number_format((float) $complaint['actual_quantity_supplied'], 3, '.', ''), '0'), '.')); ?>
                                                    <?php echo escapeHtml($complaint['actual_quantity_unit']); ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </dd>
                                        </div>
                                    <?php endif; ?>
                                </dl>
                            </section>

                            <section class="complaint-detail-section">
                                <h4 class="complaint-detail-section-title">Dealer &amp; assignment</h4>
                                <dl class="complaint-detail-grid">
                                    <?php if ($complaint['legacy_source_id'] !== null): ?>
                                        <?php if ($complaint['legacy_dealer_name'] !== null && $complaint['legacy_dealer_name'] !== ''): ?>
                                            <div class="complaint-detail"><dt>Dealer</dt><dd><?php echo escapeHtml($complaint['legacy_dealer_name']); ?></dd></div>
                                        <?php endif; ?>
                                        <?php if ($complaint['legacy_area_manager_name'] !== null && $complaint['legacy_area_manager_name'] !== ''): ?>
                                            <div class="complaint-detail"><dt>Area Manager</dt><dd><?php echo escapeHtml($complaint['legacy_area_manager_name']); ?></dd></div>
                                        <?php endif; ?>
                                        <?php if ($complaint['legacy_created_by_name'] !== null && $complaint['legacy_created_by_name'] !== ''): ?>
                                            <div class="complaint-detail"><dt>Originally entered by</dt><dd><?php echo escapeHtml($complaint['legacy_created_by_name']); ?></dd></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="complaint-detail"><dt>Submitted by</dt><dd><?php echo escapeHtml($complaint['submitted_by']); ?></dd></div>
                                    <?php endif; ?>
                                    <?php if ($complaint['selected_dealer_name'] !== null): ?>
                                        <div class="complaint-detail"><dt>Selected dealer</dt><dd><?php echo escapeHtml($complaint['selected_dealer_code'] . ' — ' . $complaint['selected_dealer_name']); ?></dd></div>
                                    <?php endif; ?>
                                    <div class="complaint-detail"><dt>Assigned to</dt><dd><?php echo escapeHtml($complaint['department']); ?></dd></div>
                                </dl>
                            </section>

                            <section class="complaint-detail-section">
                                <h4 class="complaint-detail-section-title">Supply &amp; reference</h4>
                                <dl class="complaint-detail-grid">
                                    <div class="complaint-detail"><dt>Supply date</dt><dd><?php echo $complaint['supply_date'] ? date('M j, Y', strtotime($complaint['supply_date'])) : '—'; ?></dd></div>
                                    <div class="complaint-detail"><dt>Invoice / challan reference</dt><dd><?php echo escapeHtml($complaint['invoice_reference'] ?: '—'); ?></dd></div>
                                    <div class="complaint-detail"><dt>Reg / WO No.</dt><dd><?php echo escapeHtml($complaint['wo_number'] ?: '—'); ?></dd></div>
                                </dl>
                            </section>

                            <section class="complaint-detail-section complaint-attachment-section">
                                <header class="complaint-detail-section-heading">
                                    <h4 class="complaint-detail-section-title">Attachments</h4>
                                    <span class="complaint-attachment-count"><?php echo count($complaintAttachments); ?></span>
                                </header>
                                <?php if ($complaintAttachments === []): ?>
                                    <p class="complaint-empty-value">No files attached</p>
                                <?php else: ?>
                                    <?php
                                    $attachmentGroups = [
                                        'Image' => [],
                                        'Video' => [],
                                        'Document' => [],
                                        'Other' => []
                                    ];
                                    foreach ($complaintAttachments as $attachment) {
                                        $group = isset($attachmentGroups[$attachment['category']])
                                            ? $attachment['category']
                                            : 'Other';
                                        $attachmentGroups[$group][] = $attachment;
                                    }
                                    $attachmentGroupLabels = [
                                        'Image' => 'Images',
                                        'Video' => 'Videos',
                                        'Document' => 'Documents',
                                        'Other' => 'Other files'
                                    ];
                                    $attachmentGroupKinds = [
                                        'Image' => 'photo',
                                        'Video' => 'video',
                                        'Document' => 'document',
                                        'Other' => 'file'
                                    ];
                                    ?>
                                    <div class="attachment-groups">
                                        <?php foreach ($attachmentGroups as $groupName => $groupAttachments): ?>
                                            <?php if ($groupAttachments === []) { continue; } ?>
                                            <?php $attachmentKind = $attachmentGroupKinds[$groupName]; ?>
                                            <div class="attachment-group">
                                                <div class="attachment-group-heading">
                                                    <span><?php echo $attachmentGroupLabels[$groupName]; ?></span>
                                                    <span class="attachment-group-count"><?php echo count($groupAttachments); ?></span>
                                                </div>
                                                <div class="attachment-icons">
                                                    <?php foreach ($groupAttachments as $attachment): ?>
                                                        <?php
                                                        $canPreviewAttachment = in_array($attachment['mime_type'], [
                                                            'image/jpeg',
                                                            'image/png',
                                                            'image/webp',
                                                            'application/pdf',
                                                            'video/mp4',
                                                            'video/webm'
                                                        ], true);
                                                        ?>
                                                        <a
                                                            class="attachment-icon attachment-icon--<?php echo $attachmentKind; ?>"
                                                            href="download_attachment.php?id=<?php echo (int) $attachment['id']; ?>"
                                                            title="<?php echo escapeHtml($attachment['original_name']); ?>"
                                                            aria-label="<?php echo $canPreviewAttachment ? 'Open preview of ' : 'Download '; ?><?php echo escapeHtml($attachmentKind . ': ' . $attachment['original_name']); ?>"
                                                            <?php if ($canPreviewAttachment): ?>target="_blank" rel="noopener noreferrer"<?php endif; ?>
                                                        >
                                                            <?php if ($attachmentKind === 'photo'): ?>
                                                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><path d="m21 15-5-5L5 21"></path></svg>
                                                            <?php elseif ($attachmentKind === 'video'): ?>
                                                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"></rect><path d="m10 9 5 3-5 3z"></path></svg>
                                                            <?php elseif ($attachmentKind === 'document'): ?>
                                                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 3h7l5 5v13H7z"></path><path d="M14 3v5h5M10 13h6M10 17h6"></path></svg>
                                                            <?php else: ?>
                                                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 12.5 5.2-5.2a3.2 3.2 0 0 1 4.5 4.5l-7.1 7.1a5 5 0 0 1-7.1-7.1l7.1-7.1"></path></svg>
                                                            <?php endif; ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </section>

                            <?php if ($complaint['details'] !== ''): ?>
                                <section class="complaint-detail-section">
                                    <h4 class="complaint-detail-section-title">Complaint details</h4>
                                    <details class="complaint-description">
                                        <summary>View description</summary>
                                        <div><?php echo nl2br(escapeHtml($complaint['details'])); ?></div>
                                    </details>
                                </section>
                            <?php endif; ?>
                            <div class="complaint-material-actions">
                                <a class="secondary-button" href="complaint_materials.php?id=<?php echo (int) $complaint['id']; ?>">Record sample / faulty return</a>
                                <a class="secondary-button" href="complaint_review_print.php?id=<?php echo (int) $complaint['id']; ?>" target="_blank" rel="noopener noreferrer">Print for review / approval</a>
                                <a class="secondary-button" href="complaint_department_print.php?id=<?php echo (int) $complaint['id']; ?>" target="_blank" rel="noopener noreferrer">Department print / PDF</a>
                            </div>
                        </article>
                                </template>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <dialog id="complaint-details-dialog" class="complaint-details-dialog" aria-labelledby="complaint-details-dialog-heading">
                    <div class="complaint-details-dialog-panel">
                        <header class="complaint-details-dialog-toolbar">
                            <div>
                                <h2 id="complaint-details-dialog-heading">Complaint details</h2>
                                <p>Full record and attachments</p>
                            </div>
                            <button class="secondary-button" type="button" data-close-complaint-details>Close</button>
                        </header>
                        <div id="complaint-details-dialog-content"></div>
                    </div>
                </dialog>
                <p class="table-footnote">
                    Showing complaints from farmer entries <?php echo $rangeStart; ?>–<?php echo $rangeEnd; ?> of <?php echo $totalFarmerEntries; ?> in your account scope. Select a complaint ID to view its full details.
                    <?php if ($searchTerms !== []): ?> Search is applied to the summary and charts too.<?php endif; ?>
                </p>
                <?php if ($totalPages > 1): ?>
                    <nav class="pagination" aria-label="Complaint list pages">
                        <?php if ($currentPage > 1): ?>
                            <?php $previousPageFilters = $activeFilters; $previousPageFilters['page'] = $currentPage - 1; ?>
                            <a class="secondary-button" href="<?php echo escapeHtml(buildDashboardUrl($previousPageFilters)); ?>">Previous</a>
                        <?php else: ?>
                            <span class="secondary-button is-disabled" aria-disabled="true">Previous</span>
                        <?php endif; ?>
                        <span>Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                        <?php if ($currentPage < $totalPages): ?>
                            <?php $nextPageFilters = $activeFilters; $nextPageFilters['page'] = $currentPage + 1; ?>
                            <a class="secondary-button" href="<?php echo escapeHtml(buildDashboardUrl($nextPageFilters)); ?>">Next</a>
                        <?php else: ?>
                            <span class="secondary-button is-disabled" aria-disabled="true">Next</span>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        </section>

        <section id="insights-panel" class="dashboard-tab-panel" role="tabpanel" aria-labelledby="insights-tab" tabindex="0" hidden>
            <section class="summary-grid" aria-label="Complaint summary">
                <a class="summary-card summary-card--link" href="<?php echo escapeHtml(buildDashboardUrl($allStatusFilters)); ?>">
                    <span class="summary-icon" aria-hidden="true">▤</span>
                    <div><p>Total complaints</p><strong><?php echo (int) $metrics['total']; ?></strong></div>
                </a>
                <a class="summary-card summary-card--link" href="<?php echo escapeHtml(buildDashboardUrl($pendingFilters)); ?>">
                    <span class="summary-icon summary-icon--amber" aria-hidden="true">◷</span>
                    <div><p>Pending complaints</p><strong><?php echo (int) $metrics['pending_count']; ?></strong></div>
                </a>
                <article class="summary-card summary-card--role">
                    <span class="summary-icon summary-icon--blue" aria-hidden="true">◎</span>
                    <div><p>Signed in as</p><strong><?php echo escapeHtml($roleName); ?></strong></div>
                </article>
            </section>
            <section class="section-heading">
                <div>
                    <p class="eyebrow">Insights</p>
                    <h2>Complaint analysis</h2>
                </div>
                <p>Click a category to filter the complaint list. Click it again to remove that filter.</p>
            </section>
            <section class="analysis-grid" aria-label="Complaint analysis charts">
                <article class="dashboard-card analysis-card">
                    <h3>By complaint type</h3>
                    <p>Number of complaints in each category</p>
                    <?php renderComplaintChart($typeCounts, 'type', $activeFilters); ?>
                </article>
                <article class="dashboard-card analysis-card">
                    <h3>By product</h3>
                    <p>Products most frequently reported</p>
                    <?php renderComplaintChart($productCounts, 'product', $activeFilters); ?>
                </article>
            </section>
        </section>
    </main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
