<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/complaint_uploads.php';

$complaintId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($complaintId === false || $complaintId === null || $complaintId < 1) {
    http_response_code(404);
    exit('Complaint not found.');
}

$scope = getComplaintScope($currentUser);
$complaints = fetchComplaintRows(
    $conn,
    "SELECT c.id, c.public_reference, c.title, c.farmer_name, c.complaint_type, c.product,
            c.quantity_supplied, c.quantity_unit, c.status
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
$departmentEvidenceOptions = [];
$hasDepartmentAssignment = false;
if ($currentUser['role'] === 'admin') {
    $departmentEvidenceOptions = [
        'qc_test_report' => 'QC testing report',
        'material_supply_proof' => 'Material supply proof',
        'department_evidence' => 'Other department evidence'
    ];
} elseif ($currentUser['role'] === 'department') {
    $assignmentStatement = $conn->prepare(
        'SELECT 1 FROM complaint_department_assignments
         WHERE complaint_id = ? AND department = ? LIMIT 1'
    );
    $assignmentStatement->bind_param('is', $complaintId, $currentUser['department']);
    $assignmentStatement->execute();
    $hasDepartmentAssignment = $assignmentStatement->get_result()->num_rows === 1;
    if ($hasDepartmentAssignment) {
        $departmentEvidenceOptions = match (mb_strtolower((string) $currentUser['department'], 'UTF-8')) {
            'qc' => ['qc_test_report' => 'QC testing report'],
            'store', 'dispatch' => ['material_supply_proof' => 'Material supply proof'],
            default => ['department_evidence' => 'Department evidence']
        };
    }
}
$canUploadDepartmentEvidence = $departmentEvidenceOptions !== [];
$departmentEvidencePurpose = array_key_first($departmentEvidenceOptions) ?: '';

$errorMessage = '';
$successMessage = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$csrfToken = getCsrfToken();
$form = [
    'record_type' => '',
    'material' => '',
    'quantity' => '',
    'unit' => '',
    'received_on' => date('Y-m-d'),
    'received_from' => '',
    'received_by' => $currentUser['full_name'],
    'remarks' => ''
];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') !== 'upload_department_evidence'
) {
    foreach ($form as $key => $default) {
        $postedValue = $_POST[$key] ?? $default;
        $form[$key] = is_string($postedValue) ? trim($postedValue) : $default;
    }

    $eventTypes = [
        'sample_received' => 'sample',
        'faulty_material_returned' => 'return'
    ];
    $eventType = $form['record_type'];
    $uploadResult = ['files' => [], 'error' => ''];
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif (!isset($eventTypes[$eventType])) {
        $errorMessage = 'Select a valid material record type.';
    } elseif ($form['material'] === '' || strlen($form['material']) > 300) {
        $errorMessage = 'Enter a material name of up to 300 characters.';
    } elseif (
        !preg_match('/\A\d{1,9}(?:\.\d{1,3})?\z/', $form['quantity'])
        || (float) $form['quantity'] <= 0
    ) {
        $errorMessage = 'Enter a valid quantity greater than zero.';
    } elseif ($form['unit'] === '' || strlen($form['unit']) > 30) {
        $errorMessage = 'Enter a unit of up to 30 characters.';
    } elseif (
        !($receivedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $form['received_on']))
        || $receivedDate->format('Y-m-d') !== $form['received_on']
    ) {
        $errorMessage = 'Enter a valid received date.';
    } elseif ($form['received_from'] === '' || strlen($form['received_from']) > 150) {
        $errorMessage = 'Enter who provided or returned the material (up to 150 characters).';
    } elseif ($form['received_by'] === '' || strlen($form['received_by']) > 150) {
        $errorMessage = 'Enter who received the material (up to 150 characters).';
    } elseif (strlen($form['remarks']) > 2000) {
        $errorMessage = 'Remarks must be 2,000 characters or fewer.';
    } else {
        $uploadResult = validateComplaintUploads($_FILES);
        $errorMessage = $uploadResult['error'];
    }

    if ($errorMessage === '') {
        $uploadDirectory = '';
        try {
            if ($uploadResult['files'] !== []) {
                $uploadDirectory = ensureComplaintAttachmentStorage($complaintId);
            }

            $savedPaths = [];
            $conn->begin_transaction();
            try {
                $remarks = $form['remarks'] !== '' ? $form['remarks'] : null;
                $recordedBy = (int) $currentUser['id'];
                $eventStatement = $conn->prepare(
                    'INSERT INTO complaint_material_events
                        (complaint_id, event_type, material, quantity, unit, received_on,
                         received_from, received_by, remarks, recorded_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $eventStatement->bind_param(
                    'issssssssi',
                    $complaintId,
                    $eventType,
                    $form['material'],
                    $form['quantity'],
                    $form['unit'],
                    $form['received_on'],
                    $form['received_from'],
                    $form['received_by'],
                    $remarks,
                    $recordedBy
                );
                $eventStatement->execute();
                $materialEventId = (int) $conn->insert_id;

                $attachmentStatement = $conn->prepare(
                    'INSERT INTO complaint_attachments
                        (complaint_id, category, purpose, material_event_id, stored_name,
                         original_name, mime_type, file_size)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($uploadResult['files'] as $file) {
                    $storedName = bin2hex(random_bytes(16)) . '.' . $file['extension'];
                    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;
                    if (!move_uploaded_file($file['temporary_path'], $destination)) {
                        throw new RuntimeException('An uploaded file could not be saved securely.');
                    }
                    $savedPaths[] = $destination;
                    $category = $file['category'];
                    $purpose = $eventTypes[$eventType];
                    $fileSize = (int) $file['size'];
                    $attachmentStatement->bind_param(
                        'ississsi',
                        $complaintId,
                        $category,
                        $purpose,
                        $materialEventId,
                        $storedName,
                        $file['original_name'],
                        $file['mime_type'],
                        $fileSize
                    );
                    $attachmentStatement->execute();
                }

                $conn->commit();
                $_SESSION['flash_success'] = $eventType === 'sample_received'
                    ? 'Sample receipt and its files were saved against this complaint.'
                    : 'Faulty material return and its proof were saved against this complaint.';
                header('Location: complaint_materials.php?id=' . $complaintId . '#material-history');
                exit;
            } catch (Throwable $exception) {
                $conn->rollback();
                foreach ($savedPaths as $savedPath) {
                    if (is_file($savedPath) && !unlink($savedPath)) {
                        error_log('Unable to remove a material attachment after a failed save.');
                    }
                }
                throw $exception;
            }
        } catch (mysqli_sql_exception $exception) {
            error_log('Complaint material record database error: ' . $exception->getMessage());
            $errorMessage = 'The material record could not be saved because of a database error.';
        } catch (RuntimeException $exception) {
            $errorMessage = $exception->getMessage();
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'upload_department_evidence'
) {
    $postedPurpose = is_string($_POST['evidence_purpose'] ?? null)
        ? $_POST['evidence_purpose']
        : '';
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif (!$canUploadDepartmentEvidence || !isset($departmentEvidenceOptions[$postedPurpose])) {
        $errorMessage = 'You are not allowed to upload department evidence for this complaint.';
    } else {
        $uploadResult = validateComplaintUploads($_FILES);
        $errorMessage = $uploadResult['error'];
        if ($errorMessage === '' && $uploadResult['files'] === []) {
            $errorMessage = 'Choose at least one report, photo, video, or document to upload.';
        }

        if ($errorMessage === '') {
            try {
                $uploadDirectory = ensureComplaintAttachmentStorage($complaintId);
                $savedPaths = [];
                $transactionStarted = false;
                try {
                    $conn->begin_transaction();
                    $transactionStarted = true;
                    $attachmentStatement = $conn->prepare(
                        'INSERT INTO complaint_attachments
                            (complaint_id, category, purpose, stored_name, original_name,
                             mime_type, file_size, uploaded_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $uploadedBy = (int) $currentUser['id'];
                    foreach ($uploadResult['files'] as $file) {
                        $storedName = bin2hex(random_bytes(16)) . '.' . $file['extension'];
                        $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;
                        if (!move_uploaded_file($file['temporary_path'], $destination)) {
                            throw new RuntimeException('An uploaded department file could not be saved securely.');
                        }
                        $savedPaths[] = $destination;
                        $category = $file['category'];
                        $fileSize = (int) $file['size'];
                        $attachmentStatement->bind_param(
                            'isssssii',
                            $complaintId,
                            $category,
                            $postedPurpose,
                            $storedName,
                            $file['original_name'],
                            $file['mime_type'],
                            $fileSize,
                            $uploadedBy
                        );
                        $attachmentStatement->execute();
                    }
                    $conn->commit();
                    $transactionStarted = false;
                } catch (Throwable $exception) {
                    if ($transactionStarted) {
                        $conn->rollback();
                    }
                    foreach ($savedPaths as $savedPath) {
                        if (is_file($savedPath) && !unlink($savedPath)) {
                            error_log('Unable to remove a department evidence file after a failed save.');
                        }
                    }
                    throw $exception;
                }

                $_SESSION['flash_success'] = $departmentEvidenceOptions[$postedPurpose] . ' and its files were uploaded.';
                header('Location: complaint_materials.php?id=' . $complaintId . '#department-evidence');
                exit;
            } catch (mysqli_sql_exception $exception) {
                error_log('Department evidence upload database error: ' . $exception->getMessage());
                $errorMessage = 'The department files could not be uploaded because of a database error.';
            } catch (RuntimeException $exception) {
                $errorMessage = $exception->getMessage();
            }
        }
    }
}

$departmentEvidence = fetchComplaintRows(
    $conn,
    "SELECT a.id, a.purpose, a.category, a.original_name, a.mime_type, a.created_at,
            u.full_name AS uploaded_by_name
     FROM complaint_attachments a
     INNER JOIN complaints c ON c.id = a.complaint_id
     LEFT JOIN users u ON u.id = a.uploaded_by
     WHERE c.id = {$complaintId}
       AND a.purpose IN ('qc_test_report', 'material_supply_proof', 'department_evidence')
       AND {$scope['where']}
     ORDER BY a.created_at DESC, a.id DESC",
    $scope
);
$departmentEvidenceLabels = [
    'qc_test_report' => 'QC testing report',
    'material_supply_proof' => 'Material supply proof',
    'department_evidence' => 'Department evidence'
];

$materialEvents = fetchComplaintRows(
    $conn,
    "SELECT e.id, e.event_type, e.material, e.quantity, e.unit, e.received_on,
            e.received_from, e.received_by, e.remarks, e.created_at, u.full_name AS recorded_by_name
     FROM complaint_material_events e
     INNER JOIN complaints c ON c.id = e.complaint_id
     LEFT JOIN users u ON u.id = e.recorded_by
     WHERE c.id = {$complaintId} AND {$scope['where']}
     ORDER BY e.received_on DESC, e.id DESC",
    $scope
);
$attachmentsByEventId = [];
if ($materialEvents !== []) {
    $eventIds = implode(',', array_map(static fn ($event) => (int) $event['id'], $materialEvents));
    $eventAttachments = fetchComplaintRows(
        $conn,
        "SELECT a.id, a.material_event_id, a.category, a.original_name, a.mime_type
         FROM complaint_attachments a
         INNER JOIN complaints c ON c.id = a.complaint_id
         WHERE a.material_event_id IN ({$eventIds}) AND c.id = {$complaintId} AND {$scope['where']}
         ORDER BY a.id",
        $scope
    );
    foreach ($eventAttachments as $attachment) {
        $attachmentsByEventId[(int) $attachment['material_event_id']][] = $attachment;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Sample &amp; return records | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main class="dashboard-shell">
        <header class="topbar">
            <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
            <div class="topbar-actions">
                <?php renderNotificationLink($conn, (int) $currentUser['id']); ?>
                <a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a>
            </div>
        </header>

        <section class="page-panel">
            <div class="page-panel-heading">
                <div>
                    <p class="eyebrow">Complaint <?php echo escapeHtml(formatComplaintReference((int) $complaint['id'], $complaint['public_reference'])); ?></p>
                    <h1 class="page-heading">Sample &amp; faulty material records</h1>
                    <p class="page-subtitle"><?php echo escapeHtml($complaint['farmer_name'] . ' · ' . $complaint['title']); ?></p>
                </div>
                <div class="complaint-list-actions">
                    <a class="secondary-button" href="complaint_department_print.php?id=<?php echo (int) $complaintId; ?>" target="_blank" rel="noopener noreferrer">Department print / PDF</a>
                    <a class="secondary-button" href="dashboard.php#complaint-list">Back to complaints</a>
                </div>
            </div>

            <?php if ($errorMessage !== ''): ?>
                <div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div>
            <?php elseif ($successMessage !== ''): ?>
                <div class="notice notice--success" role="status"><?php echo escapeHtml($successMessage); ?></div>
            <?php endif; ?>

            <div class="dashboard-card material-record-intro">
                <strong><?php echo escapeHtml($complaint['complaint_type'] . ' · ' . $complaint['product']); ?></strong>
                <span>Status: <?php echo escapeHtml($complaint['status']); ?></span>
                <?php if ($complaint['quantity_supplied'] !== null): ?>
                    <span>Complaint quantity: <?php echo escapeHtml(rtrim(rtrim((string) $complaint['quantity_supplied'], '0'), '.') . ' ' . $complaint['quantity_unit']); ?></span>
                <?php endif; ?>
            </div>

            <section id="department-evidence" class="dashboard-card material-history">
                <div class="admin-section-heading">
                    <div><h2>Department reports and supply proof</h2><p>QC can add testing reports; Store and Dispatch can attach material supply proof. Files stay with this complaint.</p></div>
                </div>
                <?php if ($departmentEvidence === []): ?>
                    <p class="field-hint">No department reports or supply proof have been uploaded.</p>
                <?php else: ?>
                    <div class="material-history-list">
                        <?php foreach ($departmentEvidence as $evidence): ?>
                            <article class="material-history-entry">
                                <div class="material-history-heading">
                                    <div>
                                        <span class="eyebrow"><?php echo escapeHtml($departmentEvidenceLabels[$evidence['purpose']] ?? 'Department evidence'); ?></span>
                                        <h3><?php echo escapeHtml($evidence['original_name']); ?></h3>
                                    </div>
                                    <time datetime="<?php echo escapeHtml($evidence['created_at']); ?>"><?php echo escapeHtml(date('d-m-Y H:i', strtotime($evidence['created_at']))); ?></time>
                                </div>
                                <p class="field-hint">Uploaded by <?php echo escapeHtml($evidence['uploaded_by_name'] ?? 'Not recorded'); ?></p>
                                <div class="material-proof-files">
                                    <?php if (str_starts_with($evidence['mime_type'], 'image/')): ?>
                                        <a href="download_attachment.php?id=<?php echo (int) $evidence['id']; ?>" target="_blank" rel="noopener noreferrer">
                                            <img src="download_attachment.php?id=<?php echo (int) $evidence['id']; ?>" alt="<?php echo escapeHtml($evidence['original_name']); ?>" loading="lazy">
                                        </a>
                                    <?php else: ?>
                                        <a href="download_attachment.php?id=<?php echo (int) $evidence['id']; ?>" target="_blank" rel="noopener noreferrer">Open / download file</a>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($canUploadDepartmentEvidence): ?>
                    <form method="POST" action="complaint_materials.php?id=<?php echo (int) $complaintId; ?>#department-evidence" enctype="multipart/form-data" class="form-stack">
                        <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                        <input type="hidden" name="action" value="upload_department_evidence">
                        <?php if ($currentUser['role'] === 'admin'): ?>
                            <div class="field">
                                <label for="evidence-purpose">Evidence type</label>
                                <select id="evidence-purpose" name="evidence_purpose" required>
                                    <?php foreach ($departmentEvidenceOptions as $purposeKey => $purposeLabel): ?>
                                        <option value="<?php echo escapeHtml($purposeKey); ?>"><?php echo escapeHtml($purposeLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="evidence_purpose" value="<?php echo escapeHtml($departmentEvidencePurpose); ?>">
                        <?php endif; ?>
                        <div class="field field--full">
                            <label for="department-evidence-images">Reports, photos, videos, or documents</label>
                            <input id="department-evidence-images" type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple>
                            <input type="file" name="documents[]" accept=".pdf,.docx,.xlsx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" multiple aria-label="Department reports and documents">
                            <input type="file" name="videos[]" accept=".mp4,.webm,video/mp4,video/webm" multiple aria-label="Department videos">
                            <p class="field-hint">Up to 5 files per upload, 10 MB per file. PDF and office documents can be downloaded separately.</p>
                        </div>
                        <div class="form-actions"><button class="primary-button" type="submit">Upload department evidence</button></div>
                    </form>
                <?php endif; ?>
            </section>

            <div class="material-record-forms">
                <?php foreach ([
                    'sample_received' => [
                        'title' => 'Record sample received',
                        'description' => 'Add the sample receipt details and attach sample photos or documents. Sample photos appear in the department print sheet.',
                        'button' => 'Save sample receipt'
                    ],
                    'faulty_material_returned' => [
                        'title' => 'Record faulty material returned',
                        'description' => 'Create a separate entry for each full or partial return. Add the return receipt, photo, or other proof.',
                        'button' => 'Save return record'
                    ]
                ] as $recordType => $copy): ?>
                    <section class="dashboard-card material-record-card">
                        <div class="admin-section-heading">
                            <div><h2><?php echo escapeHtml($copy['title']); ?></h2><p><?php echo escapeHtml($copy['description']); ?></p></div>
                        </div>
                        <form method="POST" action="complaint_materials.php?id=<?php echo (int) $complaintId; ?>#material-history" enctype="multipart/form-data" class="form-stack">
                            <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                            <input type="hidden" name="record_type" value="<?php echo escapeHtml($recordType); ?>">
                            <div class="form-grid">
                                <div class="field field--full">
                                    <label for="<?php echo $recordType; ?>-material">Material name</label>
                                    <input id="<?php echo $recordType; ?>-material" name="material" maxlength="300" required value="<?php echo $form['record_type'] === $recordType ? escapeHtml($form['material']) : escapeHtml($complaint['product']); ?>">
                                </div>
                                <div class="field">
                                    <label for="<?php echo $recordType; ?>-quantity">Quantity</label>
                                    <input id="<?php echo $recordType; ?>-quantity" type="number" name="quantity" min="0.001" max="999999999.999" step="0.001" required value="<?php echo $form['record_type'] === $recordType ? escapeHtml($form['quantity']) : ''; ?>">
                                </div>
                                <div class="field">
                                    <label for="<?php echo $recordType; ?>-unit">Unit</label>
                                    <input id="<?php echo $recordType; ?>-unit" name="unit" maxlength="30" required placeholder="e.g. Nos" value="<?php echo $form['record_type'] === $recordType ? escapeHtml($form['unit']) : escapeHtml($complaint['quantity_unit'] ?? ''); ?>">
                                </div>
                                <div class="field">
                                    <label for="<?php echo $recordType; ?>-received-on">Received date</label>
                                    <input id="<?php echo $recordType; ?>-received-on" type="date" name="received_on" required value="<?php echo $form['record_type'] === $recordType ? escapeHtml($form['received_on']) : date('Y-m-d'); ?>">
                                </div>
                                <div class="field">
                                    <label for="<?php echo $recordType; ?>-received-from">Received from</label>
                                    <input id="<?php echo $recordType; ?>-received-from" name="received_from" maxlength="150" required value="<?php echo $form['record_type'] === $recordType ? escapeHtml($form['received_from']) : ''; ?>">
                                </div>
                                <div class="field">
                                    <label for="<?php echo $recordType; ?>-received-by">Received by</label>
                                    <input id="<?php echo $recordType; ?>-received-by" name="received_by" maxlength="150" required value="<?php echo $form['record_type'] === $recordType ? escapeHtml($form['received_by']) : escapeHtml($currentUser['full_name']); ?>">
                                </div>
                                <div class="field field--full">
                                    <label for="<?php echo $recordType; ?>-remarks">Remarks</label>
                                    <textarea id="<?php echo $recordType; ?>-remarks" name="remarks" maxlength="2000"><?php echo $form['record_type'] === $recordType ? escapeHtml($form['remarks']) : ''; ?></textarea>
                                </div>
                                <div class="field field--full">
                                    <label for="<?php echo $recordType; ?>-images">Photos, PDF, or supporting documents</label>
                                    <input id="<?php echo $recordType; ?>-images" type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple>
                                    <input type="file" name="documents[]" accept=".pdf,.docx,.xlsx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" multiple aria-label="Supporting documents">
                                    <input type="file" name="videos[]" accept=".mp4,.webm,video/mp4,video/webm" multiple aria-label="Supporting videos">
                                    <p class="field-hint">Up to 5 files per entry, 10 MB per file. Images print on the department sheet; PDF and office documents can be downloaded separately.</p>
                                </div>
                            </div>
                            <div class="form-actions"><button class="primary-button" type="submit"><?php echo escapeHtml($copy['button']); ?></button></div>
                        </form>
                    </section>
                <?php endforeach; ?>
            </div>

            <section id="material-history" class="dashboard-card material-history">
                <div class="admin-section-heading"><div><h2>Material history</h2><p>Each sample receipt and partial/full faulty-material return is preserved separately.</p></div></div>
                <?php if ($materialEvents === []): ?>
                    <p class="field-hint">No sample or faulty-material return records have been added to this complaint.</p>
                <?php else: ?>
                    <div class="material-history-list">
                        <?php foreach ($materialEvents as $event): ?>
                            <?php $eventFiles = $attachmentsByEventId[(int) $event['id']] ?? []; ?>
                            <article class="material-history-entry">
                                <div class="material-history-heading">
                                    <div>
                                        <span class="eyebrow"><?php echo $event['event_type'] === 'sample_received' ? 'Sample received' : 'Faulty material returned'; ?></span>
                                        <h3><?php echo escapeHtml($event['material']); ?> · <?php echo escapeHtml(rtrim(rtrim((string) $event['quantity'], '0'), '.') . ' ' . $event['unit']); ?></h3>
                                    </div>
                                    <time datetime="<?php echo escapeHtml($event['received_on']); ?>"><?php echo escapeHtml(date('d-m-Y', strtotime($event['received_on']))); ?></time>
                                </div>
                                <dl class="material-history-details">
                                    <div><dt>Received from</dt><dd><?php echo escapeHtml($event['received_from']); ?></dd></div>
                                    <div><dt>Received by</dt><dd><?php echo escapeHtml($event['received_by']); ?></dd></div>
                                    <?php if ($event['remarks'] !== null && $event['remarks'] !== ''): ?>
                                        <div class="material-history-details--wide"><dt>Remarks</dt><dd><?php echo nl2br(escapeHtml($event['remarks'])); ?></dd></div>
                                    <?php endif; ?>
                                    <div><dt>Recorded by</dt><dd><?php echo escapeHtml($event['recorded_by_name'] ?? 'User account removed'); ?></dd></div>
                                    <?php if ($eventFiles !== []): ?>
                                        <div class="material-history-details--wide">
                                            <dt>Proof files</dt>
                                            <dd class="material-proof-files">
                                                <?php foreach ($eventFiles as $eventFile): ?>
                                                    <a href="download_attachment.php?id=<?php echo (int) $eventFile['id']; ?>" target="_blank" rel="noopener noreferrer"><?php echo escapeHtml($eventFile['original_name']); ?></a>
                                                <?php endforeach; ?>
                                            </dd>
                                        </div>
                                    <?php endif; ?>
                                </dl>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </section>
    </main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
