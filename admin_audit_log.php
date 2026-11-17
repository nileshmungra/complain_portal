<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin_navigation.php';

requireAdministrator($currentUser);

$searchTerm = isset($_GET['search']) && is_string($_GET['search'])
    ? mb_substr(trim($_GET['search']), 0, 120, 'UTF-8')
    : '';
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = is_int($page) && $page > 0 ? $page : 1;
$pageSize = 50;
$scope = ['where' => '1 = 1', 'types' => '', 'values' => []];
if ($searchTerm !== '') {
    $pattern = '%' . strtr($searchTerm, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $scope = [
        'where' => "(a.action LIKE ? ESCAPE '!' OR a.entity_type LIKE ? ESCAPE '!' OR a.entity_id LIKE ? ESCAPE '!' OR a.summary LIKE ? ESCAPE '!' OR u.full_name LIKE ? ESCAPE '!')",
        'types' => 'sssss',
        'values' => array_fill(0, 5, $pattern)
    ];
}
$countRows = fetchComplaintRows(
    $conn,
    "SELECT COUNT(*) AS total FROM admin_audit_log a LEFT JOIN users u ON u.id = a.actor_id WHERE {$scope['where']}",
    $scope
);
$totalRows = (int) $countRows[0]['total'];
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;
$entries = fetchComplaintRows(
    $conn,
    "SELECT a.id, a.action, a.entity_type, a.entity_id, a.summary, a.metadata_json,
            a.ip_address, a.created_at, u.full_name AS actor_name
     FROM admin_audit_log a
     LEFT JOIN users u ON u.id = a.actor_id
     WHERE {$scope['where']}
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT {$pageSize} OFFSET {$offset}",
    $scope
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Audit log | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="dashboard-shell">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
        <div class="topbar-actions"><a href="dashboard.php" class="settings-button">Dashboard</a><?php renderNotificationLink($conn, (int) $currentUser['id']); ?><a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a></div>
    </header>
    <?php renderAdminNavigation('audit'); ?>
    <section class="page-panel admin-settings-panel">
        <div class="page-panel-heading"><div><p class="eyebrow">Administrator</p><h1 class="page-heading">Audit log</h1><p class="page-subtitle">A read-only record of account, complaint, catalog, and security activity.</p></div></div>
        <div class="admin-stat-grid"><div class="admin-stat-card"><span>Matching events</span><strong><?php echo $totalRows; ?></strong></div><div class="admin-stat-card"><span>Page</span><strong><?php echo $page; ?> / <?php echo $totalPages; ?></strong></div></div>
        <form method="GET" class="admin-complaint-search" role="search">
            <label class="visually-hidden" for="audit-search">Search audit log</label>
            <input id="audit-search" type="search" name="search" maxlength="120" value="<?php echo escapeHtml($searchTerm); ?>" placeholder="Search action, user, complaint, or summary">
            <button type="submit" class="secondary-button">Search</button>
            <?php if ($searchTerm !== ''): ?><a href="admin_audit_log.php">Clear</a><?php endif; ?>
        </form>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Target</th><th>Summary</th><th>Details</th><th>IP address</th></tr></thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <?php $metadata = $entry['metadata_json'] ? json_decode($entry['metadata_json'], true) : null; ?>
                    <tr>
                        <td><time datetime="<?php echo escapeHtml($entry['created_at']); ?>"><?php echo escapeHtml(date('M j, Y H:i', strtotime($entry['created_at']))); ?></time></td>
                        <td><?php echo escapeHtml($entry['actor_name'] ?? 'System / removed user'); ?></td>
                        <td><span class="admin-status"><?php echo escapeHtml($entry['action']); ?></span></td>
                        <td><?php echo escapeHtml($entry['entity_type']); ?><?php echo $entry['entity_id'] !== null ? ' · ' . escapeHtml($entry['entity_id']) : ''; ?></td>
                        <td class="audit-summary"><?php echo escapeHtml($entry['summary']); ?></td>
                        <td>
                            <?php if (is_array($metadata) && $metadata !== []): ?>
                                <details><summary>View</summary><pre><?php echo escapeHtml(json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: ''); ?></pre></details>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?php echo escapeHtml($entry['ip_address']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($entries === []): ?><tr><td colspan="7">No audit events match the current search.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Audit log pages">
                <?php if ($page > 1): ?><a class="secondary-button" href="admin_audit_log.php?<?php echo http_build_query(['search' => $searchTerm, 'page' => $page - 1]); ?>">Previous</a><?php endif; ?>
                <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                <?php if ($page < $totalPages): ?><a class="secondary-button" href="admin_audit_log.php?<?php echo http_build_query(['search' => $searchTerm, 'page' => $page + 1]); ?>">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
