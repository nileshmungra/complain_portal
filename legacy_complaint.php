<?php
require_once __DIR__ . '/includes/auth.php';

$complaintId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($complaintId === false || $complaintId === null || $complaintId < 1) {
    http_response_code(404);
    exit('Complaint not found.');
}

$scope = getComplaintScope($currentUser);
$records = fetchComplaintRows(
    $conn,
    "SELECT c.id, c.title, l.source_id, l.dealer_name, l.area_manager_name,
            l.created_by_name, l.original_json
     FROM complaints c
     INNER JOIN legacy_complaint_data l ON l.complaint_id = c.id
     WHERE c.id = {$complaintId} AND {$scope['where']}
     LIMIT 1",
    $scope
);

if ($records === []) {
    http_response_code(404);
    exit('Complaint not found.');
}

$record = $records[0];
$originalData = json_decode($record['original_json'], true);
if (!is_array($originalData)) {
    throw new UnexpectedValueException('The stored legacy complaint data is invalid.');
}
$formattedData = json_encode(
    $originalData,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);
if (!is_string($formattedData)) {
    throw new UnexpectedValueException('The legacy complaint data could not be formatted.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Legacy complaint | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main class="dashboard-shell">
        <header class="topbar">
            <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
            <a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a>
        </header>

        <section class="page-panel">
            <div class="page-panel-heading">
                <div>
                    <p class="eyebrow">Imported history · <?php echo escapeHtml($record['source_id']); ?></p>
                    <h1 class="page-heading"><?php echo escapeHtml($record['title']); ?></h1>
                    <p class="page-subtitle">Original complaint data from the previous portal.</p>
                </div>
                <a class="secondary-button" href="dashboard.php">Back to dashboard</a>
            </div>

            <dl class="legacy-meta">
                <?php if ($record['dealer_name'] !== null && $record['dealer_name'] !== ''): ?>
                    <div><dt>Dealer</dt><dd><?php echo escapeHtml($record['dealer_name']); ?></dd></div>
                <?php endif; ?>
                <?php if ($record['area_manager_name'] !== null && $record['area_manager_name'] !== ''): ?>
                    <div><dt>Area Manager</dt><dd><?php echo escapeHtml($record['area_manager_name']); ?></dd></div>
                <?php endif; ?>
                <?php if ($record['created_by_name'] !== null && $record['created_by_name'] !== ''): ?>
                    <div><dt>Originally entered by</dt><dd><?php echo escapeHtml($record['created_by_name']); ?></dd></div>
                <?php endif; ?>
            </dl>

            <section class="legacy-record">
                <h2>Full original record</h2>
                <p class="field-hint">Original fields and workflow details are preserved. The dashboard normalizes Open to Pending.</p>
                <pre class="legacy-json"><?php echo escapeHtml($formattedData); ?></pre>
            </section>
        </section>
    </main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
