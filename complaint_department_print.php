<?php
require_once __DIR__ . '/includes/auth.php';

$complaintId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($complaintId === false || $complaintId === null || $complaintId < 1) {
    http_response_code(404);
    exit('Complaint not found.');
}

$scope = getComplaintScope($currentUser);
$complaints = fetchComplaintRows(
    $conn,
    "SELECT c.id, c.public_reference, c.title, c.farmer_name, c.farmer_mobile,
            c.village, c.taluka, c.district, c.complaint_type, c.product,
            c.supply_date, c.quantity_supplied, c.quantity_unit,
            c.actual_product, c.actual_quantity_supplied, c.actual_quantity_unit,
            c.invoice_reference, c.wo_number, c.department, c.details, c.status, c.created_at
     FROM complaints c
     WHERE c.id = {$complaintId} AND {$scope['where']}
     LIMIT 1",
    $scope
);
if ($complaints === []) {
    http_response_code(404);
    exit('Complaint not found.');
}
$complaint = $complaints[0];
$materialEvents = fetchComplaintRows(
    $conn,
    "SELECT e.id, e.event_type, e.material, e.quantity, e.unit, e.received_on,
            e.received_from, e.received_by, e.remarks
     FROM complaint_material_events e
     INNER JOIN complaints c ON c.id = e.complaint_id
     WHERE c.id = {$complaintId} AND {$scope['where']}
     ORDER BY e.received_on, e.id",
    $scope
);
$departmentEvidence = fetchComplaintRows(
    $conn,
    "SELECT a.id, a.purpose, a.original_name, a.mime_type, a.created_at,
            u.full_name AS uploaded_by_name
     FROM complaint_attachments a
     INNER JOIN complaints c ON c.id = a.complaint_id
     LEFT JOIN users u ON u.id = a.uploaded_by
     WHERE c.id = {$complaintId}
       AND a.purpose IN ('qc_test_report', 'material_supply_proof', 'department_evidence', 'erp_so', 'material_out_proof')
       AND {$scope['where']}
     ORDER BY a.created_at, a.id",
    $scope
);
$departmentEvidenceLabels = [
    'qc_test_report' => 'QC testing report',
    'material_supply_proof' => 'Material supply proof',
    'department_evidence' => 'Department evidence',
    'erp_so' => 'ERP SO copy',
    'material_out_proof' => 'Material-out proof'
];
$eventAttachments = fetchComplaintRows(
    $conn,
    "SELECT a.id, a.material_event_id, a.original_name, a.mime_type
     FROM complaint_attachments a
     INNER JOIN complaints c ON c.id = a.complaint_id
     INNER JOIN complaint_material_events e ON e.id = a.material_event_id
     WHERE c.id = {$complaintId}
       AND a.purpose IN ('sample', 'return')
       AND {$scope['where']}
     ORDER BY a.id",
    $scope
);
$attachmentsByEventId = [];
foreach ($eventAttachments as $attachment) {
    $attachmentsByEventId[(int) $attachment['material_event_id']][] = $attachment;
}
$quantity = $complaint['quantity_supplied'] !== null
    ? rtrim(rtrim((string) $complaint['quantity_supplied'], '0'), '.') . ' ' . $complaint['quantity_unit']
    : '';
$actualQuantity = $complaint['actual_quantity_supplied'] !== null
    ? rtrim(rtrim((string) $complaint['actual_quantity_supplied'], '0'), '.') . ' ' . $complaint['actual_quantity_unit']
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Department complaint sheet | Farmer Portal</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f6f3; color: #23332a; font: 13px Arial, sans-serif; }
        .print-shell { width: min(900px, calc(100% - 32px)); margin: 24px auto; }
        .print-toolbar { display: flex; justify-content: flex-end; gap: 10px; margin-bottom: 14px; }
        .print-toolbar button, .print-toolbar a { padding: 10px 14px; border: 1px solid #dce6dd; border-radius: 8px; background: #fff; color: #285d3b; font: inherit; text-decoration: none; cursor: pointer; }
        .print-card { margin-bottom: 14px; padding: 18px; border: 1px solid #dfe8e0; border-radius: 10px; background: #fff; break-inside: avoid; }
        .print-heading { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; border-bottom: 2px solid #285d3b; padding-bottom: 12px; }
        .print-heading h1 { margin: 0; font-size: 21px; }
        .print-heading p { margin: 5px 0 0; color: #627268; }
        .print-id { color: #285d3b; font-size: 16px; font-weight: 700; text-align: right; }
        .print-section h2 { margin: 0 0 12px; font-size: 15px; }
        .print-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px 16px; }
        .print-field--wide { grid-column: 1 / -1; }
        .print-field strong { display: block; margin-bottom: 4px; color: #718078; font-size: 10px; text-transform: uppercase; }
        .print-field span { display: block; overflow-wrap: anywhere; white-space: pre-wrap; line-height: 1.45; }
        .event-card { padding: 12px 0; border-top: 1px solid #e7eee8; break-inside: avoid; }
        .event-card:first-of-type { border-top: 0; padding-top: 0; }
        .event-card h3 { margin: 0 0 8px; font-size: 13px; }
        .event-meta { color: #536258; line-height: 1.55; }
        .event-files { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
        .event-files img { display: block; max-width: 240px; max-height: 220px; border: 1px solid #dfe8e0; object-fit: contain; }
        .event-files a { color: #285d3b; overflow-wrap: anywhere; }
        .evidence-entry { padding: 10px 0; border-top: 1px solid #e7eee8; break-inside: avoid; }
        .evidence-entry:first-of-type { border-top: 0; padding-top: 0; }
        .evidence-meta { margin: 4px 0; color: #536258; font-size: 11px; }
        .department-signoff { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 28px; }
        .signoff-line { padding-top: 28px; border-bottom: 1px solid #64746a; }
        .signoff-line span { display: block; padding-top: 6px; color: #64746a; font-size: 10px; }
        @media (max-width: 600px) {
            .print-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .print-heading { flex-direction: column; }
            .print-id { text-align: left; }
        }
        @media print {
            @page { size: A4; margin: 12mm; }
            body { background: #fff; font-size: 10px; }
            .print-shell { width: 100%; margin: 0; }
            .print-toolbar { display: none; }
            .print-card { padding: 12px; margin-bottom: 8px; border-color: #cfd8d0; }
            .print-heading h1 { font-size: 18px; }
            .event-files img { max-width: 190px; max-height: 170px; }
        }
    </style>
</head>
<body>
    <main class="print-shell">
        <nav class="print-toolbar" aria-label="Print actions">
            <a href="complaint_materials.php?id=<?php echo (int) $complaintId; ?>">Back to material records</a>
            <button type="button" onclick="window.print()">Print / Save as PDF</button>
        </nav>
        <section class="print-card">
            <header class="print-heading">
                <div>
                    <h1>Complaint — Department Sample Sheet</h1>
                    <p>Farmer Portal · Printed <?php echo escapeHtml(date('d-m-Y')); ?></p>
                </div>
                <div class="print-id"><?php echo escapeHtml(formatComplaintReference((int) $complaint['id'], $complaint['public_reference'])); ?><br><?php echo escapeHtml($complaint['status']); ?></div>
            </header>
            <div class="print-grid" style="margin-top: 14px">
                <div class="print-field"><strong>Farmer</strong><span><?php echo escapeHtml($complaint['farmer_name']); ?></span></div>
                <div class="print-field"><strong>Mobile</strong><span><?php echo escapeHtml($complaint['farmer_mobile'] ?: '—'); ?></span></div>
                <div class="print-field"><strong>Location</strong><span><?php echo escapeHtml(implode(', ', array_filter([$complaint['village'], $complaint['taluka'], $complaint['district']], static fn ($value) => $value !== null && $value !== ''))); ?></span></div>
                <div class="print-field"><strong>Complaint type</strong><span><?php echo escapeHtml($complaint['complaint_type']); ?></span></div>
                <div class="print-field"><strong>Material</strong><span><?php echo escapeHtml($complaint['product']); ?></span></div>
                <div class="print-field"><strong>Quantity</strong><span><?php echo escapeHtml($quantity ?: '—'); ?></span></div>
                <?php if ($complaint['complaint_type'] === 'Wrong Dispatch'): ?>
                    <div class="print-field"><strong>Actually supplied material</strong><span><?php echo escapeHtml($complaint['actual_product'] ?: '—'); ?></span></div>
                    <div class="print-field"><strong>Actually supplied quantity</strong><span><?php echo escapeHtml($actualQuantity ?: '—'); ?></span></div>
                <?php endif; ?>
                <div class="print-field"><strong>Supply date</strong><span><?php echo $complaint['supply_date'] ? escapeHtml(date('d-m-Y', strtotime($complaint['supply_date']))) : '—'; ?></span></div>
                <div class="print-field"><strong>Complaint date</strong><span><?php echo escapeHtml(date('d-m-Y', strtotime($complaint['created_at']))); ?></span></div>
                <div class="print-field"><strong>Department</strong><span><?php echo escapeHtml($complaint['department']); ?></span></div>
                <div class="print-field"><strong>Invoice / challan</strong><span><?php echo escapeHtml($complaint['invoice_reference'] ?: '—'); ?></span></div>
                <div class="print-field"><strong>Reg / WO No.</strong><span><?php echo escapeHtml($complaint['wo_number'] ?: '—'); ?></span></div>
                <div class="print-field print-field--wide"><strong>Complaint details</strong><span><?php echo escapeHtml($complaint['details'] ?: '—'); ?></span></div>
            </div>
            <div class="department-signoff">
                <div class="signoff-line"><span>Department received by / signature</span></div>
                <div class="signoff-line"><span>Received date</span></div>
            </div>
        </section>

        <section class="print-card">
            <h2>Department reports, ERP SO and dispatch proof</h2>
            <?php if ($departmentEvidence === []): ?>
                <p>No department, ERP SO or dispatch proof has been uploaded.</p>
            <?php else: ?>
                <?php foreach ($departmentEvidence as $evidence): ?>
                    <article class="evidence-entry">
                        <strong><?php echo escapeHtml($departmentEvidenceLabels[$evidence['purpose']] ?? 'Department evidence'); ?></strong>
                        <p class="evidence-meta">
                            Uploaded by <?php echo escapeHtml($evidence['uploaded_by_name'] ?? 'Not recorded'); ?>
                            · <?php echo escapeHtml(date('d-m-Y H:i', strtotime($evidence['created_at']))); ?>
                        </p>
                        <div class="event-files">
                            <?php if (str_starts_with($evidence['mime_type'], 'image/')): ?>
                                <img src="download_attachment.php?id=<?php echo (int) $evidence['id']; ?>" alt="<?php echo escapeHtml($evidence['original_name']); ?>">
                            <?php endif; ?>
                            <a href="download_attachment.php?id=<?php echo (int) $evidence['id']; ?>" target="_blank" rel="noopener noreferrer"><?php echo escapeHtml($evidence['original_name']); ?><?php echo str_starts_with($evidence['mime_type'], 'image/') ? '' : ' (download/print separately)'; ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <?php foreach ([
            'sample_received' => ['title' => 'Sample received', 'empty' => 'No sample receipt has been recorded.'],
            'faulty_material_returned' => ['title' => 'Faulty material return history', 'empty' => 'No faulty material return has been recorded.']
        ] as $eventType => $section): ?>
            <?php
            $matchingEvents = array_values(array_filter(
                $materialEvents,
                static fn ($event) => $event['event_type'] === $eventType
            ));
            ?>
            <section class="print-card">
                <h2><?php echo escapeHtml($section['title']); ?></h2>
                <?php if ($matchingEvents === []): ?>
                    <p><?php echo escapeHtml($section['empty']); ?></p>
                <?php else: ?>
                    <?php foreach ($matchingEvents as $event): ?>
                        <?php $eventFiles = $attachmentsByEventId[(int) $event['id']] ?? []; ?>
                        <article class="event-card">
                            <h3><?php echo escapeHtml($event['material'] . ' · ' . rtrim(rtrim((string) $event['quantity'], '0'), '.') . ' ' . $event['unit']); ?></h3>
                            <div class="event-meta">
                                <strong>Received:</strong> <?php echo escapeHtml(date('d-m-Y', strtotime($event['received_on']))); ?>
                                · <strong>From:</strong> <?php echo escapeHtml($event['received_from']); ?>
                                · <strong>By:</strong> <?php echo escapeHtml($event['received_by']); ?>
                                <?php if ($event['remarks'] !== null && $event['remarks'] !== ''): ?>
                                    <br><strong>Remarks:</strong> <?php echo nl2br(escapeHtml($event['remarks'])); ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($eventFiles !== []): ?>
                                <div class="event-files">
                                    <?php foreach ($eventFiles as $eventFile): ?>
                                        <?php if (str_starts_with($eventFile['mime_type'], 'image/')): ?>
                                            <img src="download_attachment.php?id=<?php echo (int) $eventFile['id']; ?>" alt="<?php echo escapeHtml($eventFile['original_name']); ?>">
                                        <?php else: ?>
                                            <a href="download_attachment.php?id=<?php echo (int) $eventFile['id']; ?>" target="_blank" rel="noopener noreferrer"><?php echo escapeHtml($eventFile['original_name']); ?> (download/print separately)</a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </main>
    <script>
        window.addEventListener('load', () => window.print());
    </script>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
