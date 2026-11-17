<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/complaint_filters.php';
require_once __DIR__ . '/includes/complaint_report.php';

$filterState = getComplaintFilterState(getComplaintScope($currentUser), $_GET);
$complaints = fetchComplaintExportData($conn, $filterState['dashboardScope']);
$appliedFilters = [];
foreach ([
    'search' => 'Search',
    'type' => 'Complaint type',
    'product' => 'Product',
    'status' => 'Status',
    'district' => 'District',
    'from' => 'From',
    'to' => 'To'
] as $key => $label) {
    if (($filterState['activeFilters'][$key] ?? '') !== '') {
        $appliedFilters[] = $label . ': ' . $filterState['activeFilters'][$key];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Complaint report | Farmer Portal</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f6f3; color: #23332a; font: 13px Arial, sans-serif; }
        .report-shell { width: min(1050px, calc(100% - 32px)); margin: 24px auto; }
        .report-toolbar { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
        .report-toolbar a, .report-toolbar button { padding: 10px 14px; border: 1px solid #dce6dd; border-radius: 8px; background: #fff; color: #285d3b; font: inherit; text-decoration: none; cursor: pointer; }
        .report-heading { margin-bottom: 18px; }
        .report-heading h1 { margin: 0; font-size: 24px; }
        .report-heading p { margin: 8px 0 0; color: #66756a; }
        .report-card { margin: 0 0 14px; padding: 18px; border: 1px solid #e0e8e1; border-radius: 10px; background: #fff; break-inside: avoid; }
        .report-card h2 { margin: 0 0 12px; font-size: 16px; }
        .report-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px 16px; margin: 0; }
        .report-field { min-width: 0; }
        .report-field--wide { grid-column: 1 / -1; }
        .report-field dt { margin-bottom: 4px; color: #718078; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .report-field dd { margin: 0; overflow-wrap: anywhere; white-space: pre-wrap; }
        .report-attachments { margin-top: 12px; padding-top: 10px; border-top: 1px solid #e8eee8; }
        .report-attachments p { margin: 5px 0; overflow-wrap: anywhere; }
        .empty-report { padding: 30px; border: 1px solid #e0e8e1; border-radius: 10px; background: #fff; }
        @media (max-width: 600px) {
            .report-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .report-toolbar { flex-direction: column; }
        }
        @media print {
            @page { size: A4; margin: 12mm; }
            body { background: #fff; font-size: 10px; }
            .report-shell { width: 100%; margin: 0; }
            .report-toolbar { display: none; }
            .report-heading { margin-bottom: 12px; }
            .report-heading h1 { font-size: 18px; }
            .report-card { margin-bottom: 8px; padding: 10px; border-color: #cfd8d0; }
            .report-card h2 { font-size: 12px; }
            .report-grid { gap: 6px 10px; }
            .report-field dt { font-size: 8px; }
            .report-attachments { margin-top: 8px; padding-top: 6px; }
            .empty-report { padding: 12px; }
        }
    </style>
</head>
<body>
    <main class="report-shell">
        <nav class="report-toolbar" aria-label="Report actions">
            <a href="dashboard.php">Back to dashboard</a>
            <button type="button" onclick="window.print()">Print / Save as PDF</button>
        </nav>
        <header class="report-heading">
            <h1>Farmer Portal — Complaint Report</h1>
            <p>Generated <?php echo date('M j, Y H:i'); ?> · <?php echo count($complaints); ?> matching complaint(s)</p>
            <?php if ($appliedFilters !== []): ?>
                <p><?php echo escapeHtml(implode(' · ', $appliedFilters)); ?></p>
            <?php endif; ?>
        </header>
        <?php if ($complaints === []): ?>
            <p class="empty-report">No complaints match the current filters.</p>
        <?php else: ?>
            <?php foreach ($complaints as $complaint): ?>
                <?php $fields = complaintExportFields($complaint); ?>
                <article class="report-card">
                    <h2><?php echo escapeHtml($fields['Complaint ID'] . ' — ' . $complaint['title']); ?></h2>
                    <dl class="report-grid">
                        <?php foreach ($fields as $label => $value): ?>
                            <?php if (in_array($label, ['Title', 'Images', 'Videos', 'Documents', 'Other Files'], true) || $value === null || $value === '') { continue; } ?>
                            <div class="report-field<?php echo $label === 'Complaint Details' ? ' report-field--wide' : ''; ?>">
                                <dt><?php echo escapeHtml($label); ?></dt>
                                <dd><?php echo nl2br(escapeHtml((string) $value)); ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                    <?php
                    $attachmentGroups = [
                        'Images' => $complaint['attachments']['Images'] ?? [],
                        'Videos' => $complaint['attachments']['Videos'] ?? [],
                        'Documents' => $complaint['attachments']['Documents'] ?? [],
                        'Other files' => $complaint['attachments']['Other files'] ?? []
                    ];
                    ?>
                    <?php if (array_filter($attachmentGroups) !== []): ?>
                        <div class="report-attachments">
                            <?php foreach ($attachmentGroups as $group => $names): ?>
                                <?php if ($names === []) { continue; } ?>
                                <p><strong><?php echo escapeHtml($group); ?>:</strong> <?php echo escapeHtml(implode('; ', $names)); ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
    <script>
        window.addEventListener('load', () => window.print());
    </script>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
