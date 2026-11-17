<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/farmer.php';
require_once __DIR__ . '/includes/location_catalog.php';
require_once __DIR__ . '/includes/complaint_uploads.php';
require_once __DIR__ . '/includes/complaint_workflow.php';

requireAdministrator($currentUser);

$complaintId = filter_var($_GET['id'] ?? $_POST['complaint_id'] ?? null, FILTER_VALIDATE_INT);
if ($complaintId === false || $complaintId === null || $complaintId < 1) {
    http_response_code(404);
    exit('Complaint not found.');
}

$complaintStatement = $conn->prepare('SELECT * FROM complaints WHERE id = ? LIMIT 1');
$complaintStatement->bind_param('i', $complaintId);
$complaintStatement->execute();
$complaint = $complaintStatement->get_result()->fetch_assoc();
if (!$complaint) {
    http_response_code(404);
    exit('Complaint not found.');
}
$attachmentStatement = $conn->prepare(
    'SELECT id, category, original_name, created_at
     FROM complaint_attachments
     WHERE complaint_id = ?
     ORDER BY created_at, id'
);
$attachmentStatement->bind_param('i', $complaintId);
$attachmentStatement->execute();
$complaintAttachments = $attachmentStatement->get_result()->fetch_all(MYSQLI_ASSOC);
$selectedDealer = null;
if (!empty($complaint['dealer_id'])) {
    $dealerStatement = $conn->prepare('SELECT dealer_code, name FROM dealers WHERE id = ? LIMIT 1');
    $dealerStatement->bind_param('i', $complaint['dealer_id']);
    $dealerStatement->execute();
    $selectedDealer = $dealerStatement->get_result()->fetch_assoc();
}

$options = require __DIR__ . '/config/complaints.php';
$locationNames = getLocationNamesByDepth($conn);
$locationNames['districts'] = array_values(array_unique(array_merge(
    $options['districts'],
    $locationNames['districts']
)));
$normalizeType = static fn (string $type): string => trim(preg_replace('/\s+/u', ' ', $type) ?? $type);
$productRows = $conn->query('SELECT name FROM products WHERE is_active = 1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$productOptions = array_column($productRows, 'name');
$productOptions = array_values(array_unique(array_filter(array_merge(
    $productOptions,
    [$complaint['product'], $complaint['actual_product'] ?? '']
))));
$typeOptions = array_values(array_unique(array_merge($options['types'], [$normalizeType($complaint['complaint_type'])])));
$statusOptions = array_values(array_unique(array_merge(
    ['Pending', 'In Progress', 'Closed', 'Rejected', 'Resolved'],
    [$complaint['status']]
)));
$unitOptions = array_values(array_unique(array_merge(
    $options['quantity_units'],
    [$complaint['quantity_unit'] ?? '', $complaint['actual_quantity_unit'] ?? '']
)));
$departmentStatement = $conn->prepare(
    'SELECT department FROM complaint_department_assignments WHERE complaint_id = ? ORDER BY department'
);
$departmentStatement->bind_param('i', $complaintId);
$departmentStatement->execute();
$assignedDepartments = array_column($departmentStatement->get_result()->fetch_all(MYSQLI_ASSOC), 'department');
$existingAssignmentDepartments = $assignedDepartments;
if ($assignedDepartments === []) {
    $assignedDepartments = array_values(array_filter(array_map('trim', explode(',', $complaint['department']))));
}
$departmentOptions = array_values(array_unique(array_merge(
    $options['departments'],
    ['Admin'],
    $assignedDepartments
)));
$errorMessage = '';
$successMessage = '';
$csrfToken = getCsrfToken();

$form = [
    'title' => $complaint['title'],
    'farmer_name' => $complaint['farmer_name'] ?? '',
    'farmer_mobile' => $complaint['farmer_mobile'] ?? '',
    'state' => $complaint['state'] ?? '',
    'village' => $complaint['village'] ?? '',
    'taluka' => $complaint['taluka'] ?? '',
    'district' => $complaint['district'],
    'complaint_type' => $normalizeType($complaint['complaint_type']),
    'product' => $complaint['product'],
    'supply_date' => $complaint['supply_date'] ?? '',
    'quantity_supplied' => $complaint['quantity_supplied'] ?? '',
    'quantity_unit' => $complaint['quantity_unit'] ?? '',
    'actual_product' => $complaint['actual_product'] ?? '',
    'actual_quantity_supplied' => $complaint['actual_quantity_supplied'] ?? '',
    'actual_quantity_unit' => $complaint['actual_quantity_unit'] ?? '',
    'wo_number' => $complaint['wo_number'] ?? '',
    'invoice_reference' => $complaint['invoice_reference'] ?? '',
    'details' => $complaint['details'],
    'status' => $complaint['status']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadResult = validateComplaintUploads($_FILES);
    foreach ($form as $key => $default) {
        $postedValue = $_POST[$key] ?? $default;
        $form[$key] = is_string($postedValue) ? trim($postedValue) : $default;
    }
    $normalizedFarmerMobile = $form['farmer_mobile'] === ''
        ? null
        : normalizeFarmerMobile($form['farmer_mobile']);
    if ($normalizedFarmerMobile !== null) {
        $form['farmer_mobile'] = $normalizedFarmerMobile;
    }
    $postedDepartments = $_POST['departments'] ?? [];
    if (!is_array($postedDepartments)) {
        $postedDepartments = [];
    }
    $previousDepartments = $assignedDepartments;
    $postedDepartments = array_values(array_unique(array_filter(
        $postedDepartments,
        static fn ($value) => is_string($value) && $value !== ''
    )));
    $assignedDepartments = $postedDepartments;

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif (
        $form['title'] === ''
        || strlen($form['title']) > 180
        || $form['farmer_name'] === ''
        || strlen($form['farmer_name']) > 100
        || $form['state'] === ''
        || mb_strlen($form['state'], 'UTF-8') > 100
        || $form['district'] === ''
        || mb_strlen($form['district'], 'UTF-8') > 100
        || $form['village'] === ''
        || mb_strlen($form['village'], 'UTF-8') > 100
        || $form['taluka'] === ''
        || mb_strlen($form['taluka'], 'UTF-8') > 100
    ) {
        $errorMessage = 'Enter a complaint title and valid farmer, State, District, Taluka, and Village details.';
    } elseif ($form['farmer_mobile'] !== '' && $normalizedFarmerMobile === null) {
        $errorMessage = 'Enter a valid 10-digit Indian farmer mobile number, or leave it blank for an older complaint.';
    } elseif (!in_array($form['complaint_type'], $typeOptions, true)) {
        $errorMessage = 'Select a valid complaint type.';
    } elseif (!in_array($form['product'], $productOptions, true)) {
        $errorMessage = 'Select a product from the catalog.';
    } elseif (
        $form['actual_product'] !== ''
        && !in_array($form['actual_product'], $productOptions, true)
    ) {
        $errorMessage = 'Select the actual supplied material from the catalog.';
    } elseif (!in_array($form['status'], $statusOptions, true)) {
        $errorMessage = 'Select a valid complaint status.';
    } elseif (strlen($form['details']) > 10000) {
        $errorMessage = 'Complaint details must be 10,000 characters or fewer.';
    } elseif (
        $form['supply_date'] !== ''
        && (
            !($supplyDateCheck = DateTimeImmutable::createFromFormat('!Y-m-d', $form['supply_date']))
            || $supplyDateCheck->format('Y-m-d') !== $form['supply_date']
        )
    ) {
        $errorMessage = 'Enter a valid product supply date.';
    } elseif (($form['quantity_supplied'] === '') !== ($form['quantity_unit'] === '')) {
        $errorMessage = 'Enter both the quantity and its unit, or leave both blank.';
    } elseif (
        $form['quantity_supplied'] !== ''
        && (
            !in_array($form['quantity_unit'], $unitOptions, true)
            || !preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $form['quantity_supplied'])
            || (float) $form['quantity_supplied'] <= 0
        )
    ) {
        $errorMessage = 'Enter a valid quantity and unit.';
    } elseif (($form['actual_quantity_supplied'] === '') !== ($form['actual_quantity_unit'] === '')) {
        $errorMessage = 'Enter both the actual supplied quantity and its unit, or leave both blank.';
    } elseif (
        $form['actual_quantity_supplied'] !== ''
        && (
            !in_array($form['actual_quantity_unit'], $unitOptions, true)
            || !preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $form['actual_quantity_supplied'])
            || (float) $form['actual_quantity_supplied'] <= 0
        )
    ) {
        $errorMessage = 'Enter a valid actual supplied quantity and unit.';
    } elseif (
        $form['complaint_type'] === 'Wrong Dispatch'
        && (
            $form['quantity_supplied'] === ''
            || $form['actual_product'] === ''
            || $form['actual_quantity_supplied'] === ''
        )
    ) {
        $errorMessage = 'For Wrong Dispatch, enter the wrongly dispatched material and quantity, and the actual supplied material and quantity.';
    } elseif (strlen($form['invoice_reference']) > 100) {
        $errorMessage = 'The invoice or challan reference must be 100 characters or fewer.';
    } elseif (strlen($form['wo_number']) > 100) {
        $errorMessage = 'Reg / WO No. must be 100 characters or fewer.';
    } elseif (
        $postedDepartments === []
        || array_diff($postedDepartments, $departmentOptions) !== []
    ) {
        $errorMessage = 'Select at least one valid assigned department.';
    } elseif ($uploadResult['error'] !== '') {
        $errorMessage = $uploadResult['error'];
    } else {
        $supplyDate = $form['supply_date'] !== '' ? $form['supply_date'] : null;
        $farmerMobile = $form['farmer_mobile'] !== '' ? $form['farmer_mobile'] : null;
        $quantity = $form['quantity_supplied'] !== '' ? $form['quantity_supplied'] : null;
        $quantityUnit = $form['quantity_unit'] !== '' ? $form['quantity_unit'] : null;
        $actualProduct = $form['complaint_type'] === 'Wrong Dispatch' ? $form['actual_product'] : null;
        $actualQuantity = $form['complaint_type'] === 'Wrong Dispatch' && $form['actual_quantity_supplied'] !== ''
            ? $form['actual_quantity_supplied']
            : null;
        $actualQuantityUnit = $form['complaint_type'] === 'Wrong Dispatch' ? $form['actual_quantity_unit'] : null;
        $invoiceReference = $form['invoice_reference'] !== '' ? $form['invoice_reference'] : null;
        $woNumber = $form['wo_number'] !== '' ? $form['wo_number'] : null;
        $departmentRoute = implode(', ', $postedDepartments);
        $savedAttachmentPaths = [];
        try {
            $uploadDirectory = $uploadResult['files'] !== []
                ? ensureComplaintAttachmentStorage($complaintId)
                : '';
            $conn->begin_transaction();
            try {
                [$form['state'], $form['district'], $form['taluka'], $form['village']] = addLocationPath(
                    $conn,
                    $form['state'],
                    $form['district'],
                    $form['taluka'],
                    $form['village']
                );
                $updateStatement = $conn->prepare(
                    'UPDATE complaints
                     SET title = ?, farmer_name = ?, farmer_mobile = ?, state = ?, village = ?, taluka = ?, district = ?,
                         complaint_type = ?, product = ?, supply_date = ?, quantity_supplied = ?,
                         quantity_unit = ?, actual_product = ?, actual_quantity_supplied = ?, actual_quantity_unit = ?,
                         invoice_reference = ?, wo_number = ?, department = ?, details = ?, status = ?
                     WHERE id = ?'
                );
                $types = str_repeat('s', 20) . 'i';
                $updateStatement->bind_param(
                    $types,
                    $form['title'],
                    $form['farmer_name'],
                    $farmerMobile,
                    $form['state'],
                    $form['village'],
                    $form['taluka'],
                    $form['district'],
                    $form['complaint_type'],
                    $form['product'],
                    $supplyDate,
                    $quantity,
                    $quantityUnit,
                    $actualProduct,
                    $actualQuantity,
                    $actualQuantityUnit,
                    $invoiceReference,
                    $woNumber,
                    $departmentRoute,
                    $form['details'],
                    $form['status'],
                    $complaintId
                );
                $updateStatement->execute();

                $resolvedAt = in_array($form['status'], ['Closed', 'Resolved'], true)
                    ? ($complaint['resolved_at'] ?? date('Y-m-d H:i:s'))
                    : null;
                $resolutionStatement = $conn->prepare('UPDATE complaints SET resolved_at = ? WHERE id = ?');
                $resolutionStatement->bind_param('si', $resolvedAt, $complaintId);
                $resolutionStatement->execute();

                $deleteAssignment = $conn->prepare(
                    'DELETE FROM complaint_department_assignments
                     WHERE complaint_id = ? AND department = ?'
                );
                foreach (array_diff($existingAssignmentDepartments, $postedDepartments) as $department) {
                    $deleteAssignment->bind_param('is', $complaintId, $department);
                    $deleteAssignment->execute();
                }
                $insertAssignment = $conn->prepare(
                    'INSERT IGNORE INTO complaint_department_assignments (complaint_id, department) VALUES (?, ?)'
                );
                foreach (array_diff($postedDepartments, $existingAssignmentDepartments) as $department) {
                    $insertAssignment->bind_param('is', $complaintId, $department);
                    $insertAssignment->execute();
                }

                if ($uploadResult['files'] !== []) {
                    $savedAttachmentPaths = insertComplaintWorkflowAttachments(
                        $conn,
                        $complaintId,
                        $uploadResult['files'],
                        'complaint',
                        (int) $currentUser['id'],
                        $uploadDirectory
                    );
                }

                $statusChanged = $complaint['status'] !== $form['status'];
                $newlyAssignedDepartments = array_values(array_unique(array_merge(
                    array_diff($postedDepartments, $previousDepartments),
                    array_diff($postedDepartments, $existingAssignmentDepartments)
                )));
                $departmentsChanged = $newlyAssignedDepartments !== []
                    || array_diff($previousDepartments, $postedDepartments) !== []
                    || array_diff($existingAssignmentDepartments, $postedDepartments) !== [];
                $complaintReference = formatComplaintReference($complaintId, $complaint['public_reference']);
                if ($statusChanged) {
                    notifyUserIds(
                        $conn,
                        [(int) $complaint['created_by']],
                        'status',
                        'Complaint status updated',
                        $complaintReference . ' is now ' . $form['status'] . '.',
                        $complaintId
                    );
                    notifyComplaintDepartments(
                        $conn,
                        $postedDepartments,
                        'status',
                        'Complaint status updated',
                        $complaintReference . ' is now ' . $form['status'] . '.',
                        $complaintId
                    );
                }
                if ($newlyAssignedDepartments !== []) {
                    notifyComplaintDepartments(
                        $conn,
                        $newlyAssignedDepartments,
                        'assignment',
                        'Complaint assigned to your department',
                        $complaintReference . ' has been assigned to ' . implode(', ', $newlyAssignedDepartments) . '.',
                        $complaintId
                    );
                }
                recordAuditEvent(
                    $conn,
                    (int) $currentUser['id'],
                    'complaint.updated',
                    'complaint',
                    $complaintId,
                    $complaintReference . ' was updated.',
                    [
                        'before' => [
                            'status' => $complaint['status'],
                            'product' => $complaint['product'],
                            'department' => $complaint['department']
                        ],
                        'after' => [
                            'status' => $form['status'],
                            'product' => $form['product'],
                            'department' => $departmentRoute
                        ],
                        'attachments_added' => count($uploadResult['files'])
                    ]
                );
                $conn->commit();
            } catch (Throwable $exception) {
                $conn->rollback();
                removeComplaintWorkflowAttachments($savedAttachmentPaths);
                throw $exception;
            }
            $_SESSION['flash_success'] = 'Complaint updated successfully.';
            header('Location: admin_settings.php#complaints');
            exit;
        } catch (mysqli_sql_exception $exception) {
            error_log('Complaint update database error: ' . $exception->getMessage());
            $errorMessage = 'The complaint could not be updated because of a database error.';
        } catch (RuntimeException $exception) {
            $errorMessage = $exception->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Edit complaint | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/searchable-selects.js" defer></script>
</head>
<body>
    <main class="dashboard-shell">
        <header class="topbar">
            <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
            <div class="topbar-actions">
                <a href="admin_settings.php#complaints" class="settings-button">Settings</a>
                <?php renderNotificationLink($conn, (int) $currentUser['id']); ?>
                <a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a>
            </div>
        </header>

        <section class="page-panel">
            <div class="page-panel-heading">
                <div>
                    <p class="eyebrow">Administrator</p>
                    <h1 class="page-heading">Edit complaint <?php echo escapeHtml(formatComplaintReference($complaintId, $complaint['public_reference'])); ?></h1>
                    <p class="page-subtitle">Update complaint details, status, or department assignment.</p>
                    <?php if ($selectedDealer): ?><p class="page-subtitle">Linked dealer: <?php echo escapeHtml($selectedDealer['dealer_code'] . ' — ' . $selectedDealer['name']); ?></p><?php endif; ?>
                </div>
            </div>
            <?php if ($errorMessage !== ''): ?>
                <div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div>
            <?php endif; ?>
            <form method="POST" action="admin_edit_complaint.php?id=<?php echo (int) $complaintId; ?>" enctype="multipart/form-data" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                <input type="hidden" name="complaint_id" value="<?php echo (int) $complaintId; ?>">
                <section class="complaint-form-section">
                    <header class="form-section-heading">
                        <span class="section-number">01</span>
                        <div><h2>Farmer and location</h2><p>Update who is affected and where.</p></div>
                    </header>
                    <div class="form-grid">
                        <div class="field"><label for="farmer_name">Farmer name</label><input id="farmer_name" name="farmer_name" maxlength="100" value="<?php echo escapeHtml($form['farmer_name']); ?>" required></div>
                        <div class="field"><label for="farmer_mobile">Farmer mobile number</label><input id="farmer_mobile" type="tel" name="farmer_mobile" maxlength="20" value="<?php echo escapeHtml($form['farmer_mobile']); ?>" autocomplete="tel" placeholder="10-digit mobile number"></div>
                        <div class="field"><label for="state">State</label><input id="state" name="state" maxlength="100" list="<?php echo escapeHtml(locationCatalogDatalistId('state')); ?>" value="<?php echo escapeHtml($form['state']); ?>" required></div>
                        <div class="field"><label for="district">District</label><input id="district" name="district" maxlength="100" list="<?php echo escapeHtml(locationCatalogDatalistId('district')); ?>" value="<?php echo escapeHtml($form['district']); ?>" required></div>
                        <div class="field"><label for="taluka">Taluka</label><input id="taluka" name="taluka" maxlength="100" list="<?php echo escapeHtml(locationCatalogDatalistId('taluka')); ?>" value="<?php echo escapeHtml($form['taluka']); ?>" required></div>
                        <div class="field"><label for="village">Village</label><input id="village" name="village" maxlength="100" list="<?php echo escapeHtml(locationCatalogDatalistId('village')); ?>" value="<?php echo escapeHtml($form['village']); ?>" required></div>
                        <div class="field"><label for="supply_date">Product supply date</label><input id="supply_date" type="date" name="supply_date" value="<?php echo escapeHtml($form['supply_date']); ?>"></div>
                        <div class="field"><label for="wo_number">Government Reg / WO No. (optional)</label><input id="wo_number" name="wo_number" maxlength="100" value="<?php echo escapeHtml($form['wo_number']); ?>" placeholder="e.g. 2526-VTH-12536"><p class="field-hint">Leave blank for open supply.</p></div>
                    </div>
                </section>
                <section class="complaint-form-section">
                    <header class="form-section-heading">
                        <span class="section-number">02</span>
                        <div><h2>Complaint information</h2><p>Update type, product, status, and assignment.</p></div>
                    </header>
                    <div class="form-grid">
                        <div class="field field--full"><label for="title">Complaint title</label><input id="title" name="title" maxlength="180" value="<?php echo escapeHtml($form['title']); ?>" required></div>
                        <div class="field">
                            <label for="complaint_type">Complaint type</label>
                            <select id="complaint_type" name="complaint_type" required>
                                <?php foreach ($typeOptions as $type): ?>
                                    <option value="<?php echo escapeHtml($type); ?>" <?php echo $form['complaint_type'] === $type ? 'selected' : ''; ?>><?php echo escapeHtml($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label data-edit-item-label="product" for="product"><?php echo $form['complaint_type'] === 'Wrong Dispatch' ? 'Wrongly dispatched material' : 'Product'; ?></label>
                            <select id="product" name="product" required>
                                <?php foreach ($productOptions as $product): ?>
                                    <option value="<?php echo escapeHtml($product); ?>" <?php echo $form['product'] === $product ? 'selected' : ''; ?>><?php echo escapeHtml($product); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="status">Status</label>
                            <select id="status" name="status" required>
                                <?php foreach ($statusOptions as $status): ?>
                                    <option value="<?php echo escapeHtml($status); ?>" <?php echo $form['status'] === $status ? 'selected' : ''; ?>><?php echo escapeHtml($status); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="departments">Assigned departments</label>
                            <select id="departments" name="departments[]" multiple required size="<?php echo min(6, max(3, count($departmentOptions))); ?>">
                                <?php foreach ($departmentOptions as $department): ?>
                                    <option value="<?php echo escapeHtml($department); ?>" <?php echo in_array($department, $assignedDepartments, true) ? 'selected' : ''; ?>><?php echo escapeHtml($department); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="field-hint">Search and select one or more departments.</p>
                        </div>
                    </div>
                </section>
                <section class="complaint-form-section">
                    <header class="form-section-heading">
                        <span class="section-number">03</span>
                        <div><h2>Supply details</h2><p>Invoice and quantity are optional.</p></div>
                    </header>
                    <div class="form-grid">
                        <div class="field"><label data-edit-item-label="quantity_supplied" for="quantity_supplied"><?php echo $form['complaint_type'] === 'Wrong Dispatch' ? 'Wrongly dispatched quantity' : 'Quantity supplied'; ?></label><input id="quantity_supplied" type="number" name="quantity_supplied" min="0.001" max="999999999.999" step="0.001" value="<?php echo escapeHtml((string) $form['quantity_supplied']); ?>" <?php echo $form['complaint_type'] === 'Wrong Dispatch' ? 'required' : ''; ?>></div>
                        <div class="field">
                            <label data-edit-item-label="quantity_unit" for="quantity_unit"><?php echo $form['complaint_type'] === 'Wrong Dispatch' ? 'Wrong quantity unit' : 'Quantity unit'; ?></label>
                            <select id="quantity_unit" name="quantity_unit" <?php echo $form['complaint_type'] === 'Wrong Dispatch' ? 'required' : ''; ?>>
                                <option value="">Select a unit</option>
                                <?php foreach ($unitOptions as $unit): ?>
                                    <?php if ($unit === '') { continue; } ?>
                                    <option value="<?php echo escapeHtml($unit); ?>" <?php echo $form['quantity_unit'] === $unit ? 'selected' : ''; ?>><?php echo escapeHtml($unit); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field field--full" data-actual-supply-field <?php echo $form['complaint_type'] === 'Wrong Dispatch' ? '' : 'hidden'; ?>>
                            <label for="actual_product">Actually supplied material</label>
                            <select id="actual_product" name="actual_product" <?php echo $form['complaint_type'] === 'Wrong Dispatch' ? 'required' : 'disabled'; ?>>
                                <option value="">Select a product</option>
                                <?php foreach ($productOptions as $product): ?>
                                    <option value="<?php echo escapeHtml($product); ?>" <?php echo $form['actual_product'] === $product ? 'selected' : ''; ?>><?php echo escapeHtml($product); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field" data-actual-supply-field <?php echo $form['complaint_type'] === 'Wrong Dispatch' ? '' : 'hidden'; ?>>
                            <label for="actual_quantity_supplied">Actual supplied quantity</label>
                            <input id="actual_quantity_supplied" type="number" name="actual_quantity_supplied" min="0.001" max="999999999.999" step="0.001" value="<?php echo escapeHtml((string) $form['actual_quantity_supplied']); ?>" <?php echo $form['complaint_type'] === 'Wrong Dispatch' ? 'required' : 'disabled'; ?>>
                        </div>
                        <div class="field" data-actual-supply-field <?php echo $form['complaint_type'] === 'Wrong Dispatch' ? '' : 'hidden'; ?>>
                            <label for="actual_quantity_unit">Actual quantity unit</label>
                            <select id="actual_quantity_unit" name="actual_quantity_unit" <?php echo $form['complaint_type'] === 'Wrong Dispatch' ? 'required' : 'disabled'; ?>>
                                <option value="">Select a unit</option>
                                <?php foreach ($unitOptions as $unit): ?>
                                    <?php if ($unit === '') { continue; } ?>
                                    <option value="<?php echo escapeHtml($unit); ?>" <?php echo $form['actual_quantity_unit'] === $unit ? 'selected' : ''; ?>><?php echo escapeHtml($unit); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field"><label for="invoice_reference">Invoice / challan reference</label><input id="invoice_reference" name="invoice_reference" maxlength="100" value="<?php echo escapeHtml($form['invoice_reference']); ?>"></div>
                        <div class="field field--full"><label for="details">Complaint details</label><textarea id="details" name="details" maxlength="10000"><?php echo escapeHtml($form['details']); ?></textarea></div>
                    </div>
                </section>
                <section class="complaint-form-section">
                    <header class="form-section-heading">
                        <span class="section-number">04</span>
                        <div><h2>Attachments</h2><p>Add photos, videos, or documents to this complaint. Existing attachments will be kept.</p></div>
                    </header>
                    <?php if ($complaintAttachments !== []): ?>
                        <div class="material-history-list">
                            <?php foreach ($complaintAttachments as $attachment): ?>
                                <article class="material-history-entry">
                                    <span class="eyebrow"><?php echo escapeHtml($attachment['category']); ?></span>
                                    <h3><?php echo escapeHtml($attachment['original_name']); ?></h3>
                                    <p class="field-hint"><?php echo escapeHtml(date('d-m-Y H:i', strtotime($attachment['created_at']))); ?></p>
                                    <a href="download_attachment.php?id=<?php echo (int) $attachment['id']; ?>" target="_blank" rel="noopener noreferrer">Open / download</a>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="field-hint">No attachments have been added to this complaint.</p>
                    <?php endif; ?>
                    <div class="form-grid">
                        <div class="field field--full">
                            <label for="edit-complaint-images">Add images</label>
                            <input id="edit-complaint-images" type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple>
                        </div>
                        <div class="field field--full">
                            <label for="edit-complaint-documents">Add documents</label>
                            <input id="edit-complaint-documents" type="file" name="documents[]" accept=".pdf,.docx,.xlsx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" multiple>
                        </div>
                        <div class="field field--full">
                            <label for="edit-complaint-videos">Add videos</label>
                            <input id="edit-complaint-videos" type="file" name="videos[]" accept=".mp4,.webm,video/mp4,video/webm" multiple>
                            <p class="field-hint">You can add up to 5 files per save, up to 10 MB each. Supported: JPG, PNG, WEBP, PDF, DOCX, XLSX, MP4, and WEBM.</p>
                        </div>
                    </div>
                </section>
                <div class="form-actions">
                    <a class="secondary-button" href="admin_settings.php#complaints">Cancel</a>
                    <button class="primary-button" type="submit">Save changes</button>
                </div>
            </form>
            <?php renderLocationDatalists($locationNames); ?>
        </section>
    </main>
    <script>
        const complaintType = document.getElementById('complaint_type');
        function updateWrongDispatchFields() {
            const isWrongDispatch = complaintType.value === 'Wrong Dispatch';
            const labelText = {
                product: isWrongDispatch ? 'Wrongly dispatched material' : 'Product',
                quantity_supplied: isWrongDispatch ? 'Wrongly dispatched quantity' : 'Quantity supplied',
                quantity_unit: isWrongDispatch ? 'Wrong quantity unit' : 'Quantity unit'
            };
            for (const [field, text] of Object.entries(labelText)) {
                document.querySelector(`[data-edit-item-label="${field}"]`).textContent = text;
            }
            for (const field of document.querySelectorAll('[data-actual-supply-field]')) {
                field.hidden = !isWrongDispatch;
                const control = field.querySelector('input, select');
                control.disabled = !isWrongDispatch;
                control.required = isWrongDispatch;
                if (control instanceof HTMLSelectElement) {
                    control.dataset.searchableRequired = String(isWrongDispatch);
                }
            }
            document.getElementById('quantity_supplied').required = isWrongDispatch;
            document.getElementById('quantity_unit').required = isWrongDispatch;
        }
        complaintType.addEventListener('change', updateWrongDispatchFields);
    </script>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
