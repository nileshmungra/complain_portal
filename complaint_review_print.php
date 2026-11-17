<?php
require_once __DIR__ . '/includes/auth.php';

$complaintId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($complaintId === false || $complaintId === null || $complaintId < 1) {
    http_response_code(404);
    exit('Complaint not found.');
}

$audienceLabels = [
    'management' => 'Management',
    'supplier' => 'Supplier',
    'department' => 'Department',
    'other' => 'Other'
];
$audience = is_string($_GET['audience'] ?? null) && isset($audienceLabels[$_GET['audience']])
    ? $_GET['audience']
    : 'management';
$purposes = [
    'review' => 'For Review',
    'approval' => 'For Approval'
];
$purpose = is_string($_GET['purpose'] ?? null) && isset($purposes[$_GET['purpose']])
    ? $_GET['purpose']
    : 'review';
$recipientName = is_string($_GET['recipient_name'] ?? null)
    ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', '', $_GET['recipient_name']) ?? ''), 0, 100, 'UTF-8')
    : '';

$scope = getComplaintScope($currentUser);
$complaints = fetchComplaintRows(
    $conn,
    "SELECT c.id, c.public_reference, c.title, c.farmer_name, c.farmer_mobile,
            c.state, c.district, c.taluka, c.village, c.complaint_type, c.product,
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
$attachmentPurposeLabels = [
    'complaint' => 'Complaint attachment',
    'sample' => 'Sample receipt proof',
    'return' => 'Faulty material return proof',
    'qc_test_report' => 'QC testing report',
    'material_supply_proof' => 'Material supply proof',
    'department_evidence' => 'Department evidence'
];
$attachments = fetchComplaintRows(
    $conn,
    "SELECT a.id, a.purpose, a.original_name, a.mime_type, a.created_at,
            u.full_name AS uploaded_by_name
     FROM complaint_attachments a
     INNER JOIN complaints c ON c.id = a.complaint_id
     LEFT JOIN users u ON u.id = a.uploaded_by
     WHERE c.id = {$complaintId}
       AND a.purpose IN ('complaint', 'sample', 'return', 'qc_test_report', 'material_supply_proof', 'department_evidence')
       AND {$scope['where']}
     ORDER BY a.id",
    $scope
);
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
    <title>Complaint review sheet | Farmer Portal</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f6f3; color: #23332a; font: 13px Arial, sans-serif; }
        .print-shell { width: min(900px, calc(100% - 32px)); margin: 24px auto; }
        .print-toolbar, .print-options { display: flex; flex-wrap: wrap; align-items: end; gap: 10px; margin-bottom: 14px; }
        .print-toolbar a, .print-toolbar button, .print-options button { padding: 10px 14px; border: 1px solid #dce6dd; border-radius: 8px; background: #fff; color: #285d3b; font: inherit; text-decoration: none; cursor: pointer; }
        .print-options { padding: 14px; border: 1px solid #dfe8e0; border-radius: 10px; background: #fff; }
        .print-options label { display: grid; gap: 5px; color: #56665c; font-size: 12px; }
        .print-options input, .print-options select { min-width: 150px; padding: 9px; border: 1px solid #dce6dd; border-radius: 6px; color: #23332a; font: inherit; }
        .print-card { margin-bottom: 14px; padding: 20px; border: 1px solid #dfe8e0; border-radius: 10px; background: #fff; break-inside: avoid; }
        .print-heading { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; border-bottom: 2px solid #285d3b; padding-bottom: 12px; }
        .print-heading h1 { margin: 0; font-size: 21px; }
        .print-heading p { margin: 5px 0 0; color: #627268; }
        .print-id { color: #285d3b; font-size: 16px; font-weight: 700; text-align: right; }
        .print-section { margin-top: 18px; }
        .print-section h2 { margin: 0 0 12px; font-size: 15px; }
        .print-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px 16px; }
        .print-field--wide { grid-column: 1 / -1; }
        .print-field strong { display: block; margin-bottom: 4px; color: #718078; font-size: 10px; text-transform: uppercase; }
        .print-field span { display: block; overflow-wrap: anywhere; white-space: pre-wrap; line-height: 1.45; }
        .attachment-grid { display: flex; flex-wrap: wrap; gap: 12px; }
        .attachment { max-width: 250px; overflow-wrap: anywhere; }
        .attachment img { display: block; max-width: 240px; max-height: 220px; margin-bottom: 5px; border: 1px solid #dfe8e0; object-fit: contain; }
        .attachment a { color: #285d3b; }
        .signoff { display: grid; grid-template-columns: 1fr 1fr; gap: 20px 30px; margin-top: 26px; }
        .signoff-line { min-height: 42px; border-bottom: 1px solid #64746a; }
        .signoff-line span { display: block; padding-top: 24px; color: #64746a; font-size: 10px; }
        @media (max-width: 600px) {
            .print-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .print-heading { flex-direction: column; }
            .print-id { text-align: left; }
        }
        @media print {
            @page { size: A4; margin: 12mm; }
            body { background: #fff; font-size: 10px; }
            .print-shell { width: 100%; margin: 0; }
            .print-toolbar, .print-options { display: none; }
            .print-card { padding: 12px; margin-bottom: 8px; border-color: #cfd8d0; }
            .print-heading h1 { font-size: 18px; }
            .attachment img { max-width: 190px; max-height: 170px; }
        }
    </style>
</head>
<body>
<main class="print-shell">
    <nav class="print-toolbar" aria-label="Print actions">
        <a href="dashboard.php">Back to dashboard</a>
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
    </nav>
    <form class="print-options" method="GET">
        <input type="hidden" name="id" value="<?php echo (int) $complaintId; ?>">
        <label>Send to
            <select name="audience">
                <?php foreach ($audienceLabels as $key => $label): ?>
                    <option value="<?php echo escapeHtml($key); ?>" <?php echo $audience === $key ? 'selected' : ''; ?>><?php echo escapeHtml($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Purpose
            <select name="purpose">
                <?php foreach ($purposes as $key => $label): ?>
                    <option value="<?php echo escapeHtml($key); ?>" <?php echo $purpose === $key ? 'selected' : ''; ?>><?php echo escapeHtml($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Recipient / supplier name (optional)
            <input name="recipient_name" maxlength="100" value="<?php echo escapeHtml($recipientName); ?>">
        </label>
        <button type="submit">Update sheet</button>
    </form>

    <section class="print-card">
        <header class="print-heading">
            <div>
                <h1>Complaint <?php echo escapeHtml($purposes[$purpose]); ?> Sheet</h1>
                <p>Farmer Portal · Printed <?php echo escapeHtml(date('d-m-Y')); ?></p>
            </div>
            <div class="print-id"><?php echo escapeHtml(formatComplaintReference((int) $complaint['id'], $complaint['public_reference'])); ?><br><?php echo escapeHtml($complaint['status']); ?></div>
        </header>
        <div class="print-grid" style="margin-top: 14px">
            <div class="print-field"><strong>Prepared for</strong><span><?php echo escapeHtml($audienceLabels[$audience]); ?></span></div>
            <div class="print-field"><strong>Recipient / supplier</strong><span><?php echo escapeHtml($recipientName !== '' ? $recipientName : '—'); ?></span></div>
            <div class="print-field"><strong>Assigned department</strong><span><?php echo escapeHtml($complaint['department']); ?></span></div>
            <div class="print-field"><strong>Complaint title</strong><span><?php echo escapeHtml($complaint['title']); ?></span></div>
            <div class="print-field"><strong>Complaint date</strong><span><?php echo escapeHtml(date('d-m-Y', strtotime($complaint['created_at']))); ?></span></div>
            <div class="print-field"><strong>Complaint type</strong><span><?php echo escapeHtml($complaint['complaint_type']); ?></span></div>
            <div class="print-field"><strong>Farmer</strong><span><?php echo escapeHtml($complaint['farmer_name']); ?></span></div>
            <div class="print-field"><strong>Mobile</strong><span><?php echo escapeHtml($complaint['farmer_mobile'] ?: '—'); ?></span></div>
            <div class="print-field"><strong>State / District</strong><span><?php echo escapeHtml(implode(', ', array_filter([$complaint['state'], $complaint['district']], static fn ($value) => $value !== null && $value !== ''))); ?></span></div>
            <div class="print-field"><strong>Taluka / Village</strong><span><?php echo escapeHtml(implode(', ', array_filter([$complaint['taluka'], $complaint['village']], static fn ($value) => $value !== null && $value !== ''))); ?></span></div>
            <div class="print-field"><strong>Product / material</strong><span><?php echo escapeHtml($complaint['product']); ?></span></div>
            <div class="print-field"><strong>Quantity</strong><span><?php echo escapeHtml($quantity ?: '—'); ?></span></div>
            <?php if ($complaint['complaint_type'] === 'Wrong Dispatch'): ?>
                <div class="print-field"><strong>Actually supplied material</strong><span><?php echo escapeHtml($complaint['actual_product'] ?: '—'); ?></span></div>
                <div class="print-field"><strong>Actually supplied quantity</strong><span><?php echo escapeHtml($actualQuantity ?: '—'); ?></span></div>
            <?php endif; ?>
            <div class="print-field"><strong>Supply date</strong><span><?php echo $complaint['supply_date'] ? escapeHtml(date('d-m-Y', strtotime($complaint['supply_date']))) : '—'; ?></span></div>
            <div class="print-field"><strong>Invoice / challan</strong><span><?php echo escapeHtml($complaint['invoice_reference'] ?: '—'); ?></span></div>
            <div class="print-field"><strong>Reg / WO No.</strong><span><?php echo escapeHtml($complaint['wo_number'] ?: '—'); ?></span></div>
            <div class="print-field print-field--wide"><strong>Complaint details</strong><span><?php echo escapeHtml($complaint['details'] ?: '—'); ?></span></div>
        </div>

        <section class="print-section">
            <h2>Complaint and department evidence</h2>
            <?php if ($attachments === []): ?>
                <p>No complaint or department evidence is available.</p>
            <?php else: ?>
                <div class="attachment-grid">
                    <?php foreach ($attachments as $attachment): ?>
                        <div class="attachment">
                            <strong><?php echo escapeHtml($attachmentPurposeLabels[$attachment['purpose']] ?? 'Supporting attachment'); ?></strong><br>
                            <?php if (str_starts_with($attachment['mime_type'], 'image/')): ?>
                                <img src="download_attachment.php?id=<?php echo (int) $attachment['id']; ?>" alt="<?php echo escapeHtml($attachment['original_name']); ?>">
                            <?php endif; ?>
                            <a href="download_attachment.php?id=<?php echo (int) $attachment['id']; ?>" target="_blank" rel="noopener noreferrer"><?php echo escapeHtml($attachment['original_name']); ?></a>
                            <?php if (!str_starts_with($attachment['mime_type'], 'image/')): ?><small> (download separately)</small><?php endif; ?>
                            <small><br>Uploaded by <?php echo escapeHtml($attachment['uploaded_by_name'] ?? 'Not recorded'); ?> · <?php echo escapeHtml(date('d-m-Y H:i', strtotime($attachment['created_at']))); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="print-section">
            <h2>Review / approval</h2>
            <div class="print-field print-field--wide"><strong>Decision / remarks</strong><span>&nbsp;</span></div>
            <div class="signoff">
                <div class="signoff-line"><span>Name / designation</span></div>
                <div class="signoff-line"><span>Signature / stamp</span></div>
                <div class="signoff-line"><span>Decision / comments</span></div>
                <div class="signoff-line"><span>Date</span></div>
            </div>
        </section>
    </section>
</main>
</body>
</html>
