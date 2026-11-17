<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/complaint_uploads.php';
require_once __DIR__ . '/includes/complaint_drafts.php';
require_once __DIR__ . '/includes/location_catalog.php';
require_once __DIR__ . '/includes/farmer.php';
$options = require __DIR__ . '/config/complaints.php';
$locationNames = getLocationNamesByDepth($conn);
$locationNames['districts'] = array_values(array_unique(array_merge(
    $options['districts'],
    $locationNames['districts']
)));
$productResult = $conn->query('SELECT name FROM products WHERE is_active = 1 ORDER BY name');
$options['products'] = array_column($productResult->fetch_all(MYSQLI_ASSOC), 'name');

$assignedAreas = array_values(array_filter(
    array_filter(array_map('trim', explode(',', $currentUser['assigned_area'] ?? ''))),
    static fn ($assignedArea) => in_array(
        mb_strtolower($assignedArea, 'UTF-8'),
        array_map(
            static fn ($district) => mb_strtolower($district, 'UTF-8'),
            $locationNames['districts']
        ),
        true
    )
));
$availableDistricts = $currentUser['role'] === 'area_manager'
    ? $assignedAreas
    : $options['districts'];

$draft = loadComplaintDraft($conn, (int) $currentUser['id']);
$form = [
    'dealer_id' => '',
    'title' => '',
    'farmer_name' => '',
    'farmer_mobile' => '',
    'state' => '',
    'village' => '',
    'taluka' => '',
    'district' => '',
    'complaint_type' => '',
    'product' => '',
    'supply_date' => '',
    'quantity_supplied' => '',
    'quantity_unit' => '',
    'actual_product' => '',
    'actual_quantity_supplied' => '',
    'actual_quantity_unit' => '',
    'wo_number' => '',
    'invoice_reference' => '',
    'details' => ''
];
$itemFields = [
    'complaint_type',
    'product',
    'quantity_supplied',
    'quantity_unit',
    'actual_product',
    'actual_quantity_supplied',
    'actual_quantity_unit',
    'details'
];
$itemDefaults = array_fill_keys($itemFields, '');
$items = [$itemDefaults];
if ($draft) {
    foreach ($form as $key => $default) {
        $savedValue = $draft['form_data'][$key] ?? null;
        if (is_string($savedValue)) {
            $form[$key] = $savedValue;
        }
    }
    if (isset($draft['form_data']['items']) && is_array($draft['form_data']['items'])) {
        $items = [];
        foreach (array_slice($draft['form_data']['items'], 0, 10) as $savedItem) {
            if (!is_array($savedItem)) {
                continue;
            }
            $item = $itemDefaults;
            foreach ($itemFields as $field) {
                if (is_string($savedItem[$field] ?? null)) {
                    $item[$field] = $savedItem[$field];
                }
            }
            $items[] = $item;
        }
        if ($items === []) {
            $items[] = $itemDefaults;
        }
        $legacyItems = array_values($draft['form_data']['items']);
        $legacyFirstItem = $legacyItems[0] ?? null;
        foreach (['supply_date', 'wo_number'] as $sharedField) {
            $legacyValue = is_array($legacyFirstItem) ? ($legacyFirstItem[$sharedField] ?? null) : null;
            if ($form[$sharedField] === '' && is_string($legacyValue)) {
                $form[$sharedField] = $legacyValue;
            }
        }
    } else {
        foreach ($itemFields as $field) {
            $items[0][$field] = $form[$field];
        }
    }
}
$canSelectDealer = in_array($currentUser['role'], ['admin', 'area_manager'], true);
if (!$canSelectDealer) {
    $form['dealer_id'] = '';
}
$draftAttachments = $draft ? loadComplaintDraftAttachments($conn, $draft['id']) : [];
$selectedDealer = null;
$activeDealers = [];
if ($canSelectDealer) {
    if ($currentUser['role'] === 'admin') {
        $activeDealers = $conn->query(
            'SELECT id, dealer_code, name, state, village, taluka, district
             FROM dealers WHERE is_active = 1 ORDER BY name, dealer_code'
        )->fetch_all(MYSQLI_ASSOC);
    } elseif ($assignedAreas !== []) {
        $districtPlaceholders = implode(', ', array_fill(0, count($assignedAreas), '?'));
        $dealerStatement = $conn->prepare(
            "SELECT id, dealer_code, name, state, village, taluka, district
             FROM dealers
             WHERE is_active = 1 AND district IN ({$districtPlaceholders})
             ORDER BY name, dealer_code"
        );
        $dealerAreaValues = $assignedAreas;
        $dealerBindings = [str_repeat('s', count($dealerAreaValues))];
        foreach ($dealerAreaValues as &$assignedArea) {
            $dealerBindings[] = &$assignedArea;
        }
        unset($assignedArea);
        call_user_func_array([$dealerStatement, 'bind_param'], $dealerBindings);
        $dealerStatement->execute();
        $activeDealers = $dealerStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    if ($currentUser['role'] === 'admin') {
        $availableDistricts = array_values(array_unique(array_filter(array_merge(
            $availableDistricts,
            array_column($activeDealers, 'district')
        ), static fn ($district) => is_string($district) && $district !== '')));
    }
    if ($currentUser['role'] === 'area_manager') {
        $locationNames['districts'] = $availableDistricts;
    }
    if (
        $form['dealer_id'] !== ''
        && !in_array((int) $form['dealer_id'], array_map('intval', array_column($activeDealers, 'id')), true)
    ) {
        $form['dealer_id'] = '';
    }
}
if ($form['dealer_id'] !== '') {
    foreach ($activeDealers as $dealer) {
        if ((int) $dealer['id'] !== (int) $form['dealer_id']) {
            continue;
        }
        foreach (['state', 'district', 'taluka', 'village'] as $locationField) {
            if (is_string($dealer[$locationField] ?? null) && $dealer[$locationField] !== '') {
                $form[$locationField] = $dealer[$locationField];
            }
        }
        break;
    }
}
if ($currentUser['role'] === 'area_manager' && $form['district'] === '' && $assignedAreas !== []) {
    $form['district'] = $assignedAreas[0];
}
if ($form['state'] === '' && $form['district'] !== '') {
    $form['state'] = getStateForDistrict($conn, $form['district']) ?? '';
}
$errorMessage = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $default) {
        $postedValue = $_POST[$key] ?? $default;
        $form[$key] = is_string($postedValue) ? trim($postedValue) : $default;
    }
    $items = [];
    $postedItems = $_POST['items'] ?? null;
    $tooManyItems = is_array($postedItems) && count($postedItems) > 10;
    if (is_array($postedItems) && count($postedItems) <= 10) {
        foreach (array_values($postedItems) as $postedItem) {
            if (!is_array($postedItem)) {
                $items[] = $itemDefaults;
                continue;
            }
            $item = $itemDefaults;
            foreach ($itemFields as $field) {
                $value = $postedItem[$field] ?? '';
                $item[$field] = is_string($value) ? trim($value) : '';
            }
            $items[] = $item;
        }
    }
    if ($items === []) {
        $items[] = array_intersect_key($form, $itemDefaults);
    }
    foreach ($itemFields as $field) {
        $form[$field] = $items[0][$field];
    }
    foreach ($items as &$item) {
        $item['supply_date'] = $form['supply_date'];
        $item['wo_number'] = $form['wo_number'];
    }
    unset($item);

    $postedToken = $_POST['csrf_token'] ?? '';
    $csrfValid = is_string($postedToken) && hash_equals($_SESSION['csrf_token'], $postedToken);
    $dealerSelectionError = '';
    if ($csrfValid && $canSelectDealer && ($form['dealer_id'] !== '' || $currentUser['role'] === 'area_manager')) {
        if ($currentUser['role'] === 'area_manager' && $form['dealer_id'] === '') {
            $dealerSelectionError = 'Select a dealer from your assigned districts.';
        } else {
            $dealerId = filter_var($form['dealer_id'], FILTER_VALIDATE_INT);
            if (!is_int($dealerId) || $dealerId < 1) {
                $dealerSelectionError = 'Select a valid active dealer.';
            } else {
                $dealerStatement = $conn->prepare(
                    'SELECT id, village, taluka, district, state FROM dealers WHERE id = ? AND is_active = 1 LIMIT 1'
                );
                $dealerStatement->bind_param('i', $dealerId);
                $dealerStatement->execute();
                $selectedDealer = $dealerStatement->get_result()->fetch_assoc();
                if (!$selectedDealer) {
                    $dealerSelectionError = 'The selected dealer is no longer active. Choose another dealer.';
                } elseif (
                    $currentUser['role'] === 'area_manager'
                    && !in_array($selectedDealer['district'], $assignedAreas, true)
                ) {
                    $dealerSelectionError = 'The selected dealer is outside your assigned districts.';
                } else {
                    $form['state'] = (string) ($selectedDealer['state'] ?? '');
                    $form['village'] = (string) ($selectedDealer['village'] ?? '');
                    $form['taluka'] = (string) ($selectedDealer['taluka'] ?? '');
                    $form['district'] = (string) ($selectedDealer['district'] ?? '');
                }
            }
        }
    }
    $normalizedFarmerMobile = normalizeFarmerMobile($form['farmer_mobile']);
    if ($normalizedFarmerMobile !== null) {
        $form['farmer_mobile'] = $normalizedFarmerMobile;
    }

    if (!$csrfValid) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif ($tooManyItems) {
        $errorMessage = 'A submission can contain up to 10 complaints.';
    } elseif (!$canSelectDealer && $form['dealer_id'] !== '') {
        $errorMessage = 'Dealer selection is available to administrators and assigned area managers only.';
    } elseif ($dealerSelectionError !== '') {
        $errorMessage = $dealerSelectionError;
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
    } elseif ($normalizedFarmerMobile === null) {
        $errorMessage = 'Enter a valid 10-digit Indian farmer mobile number.';
    } elseif (
        $currentUser['role'] === 'area_manager'
        && !in_array(mb_strtolower($form['district'], 'UTF-8'), array_map(
            static fn ($district) => mb_strtolower($district, 'UTF-8'),
            $availableDistricts
        ), true)
    ) {
        $errorMessage = $currentUser['role'] === 'area_manager'
            ? 'Please select a district assigned to your account.'
            : 'Please select a valid district.';
    } elseif (strlen($form['invoice_reference']) > 100) {
        $errorMessage = 'The invoice or challan reference must be 100 characters or fewer.';
    } elseif (
        $form['supply_date'] !== ''
        && (
            !($supplyDateCheck = DateTimeImmutable::createFromFormat('!Y-m-d', $form['supply_date']))
            || $supplyDateCheck->format('Y-m-d') !== $form['supply_date']
        )
    ) {
        $errorMessage = 'Enter a valid product supply date.';
    } elseif (strlen($form['wo_number']) > 100) {
        $errorMessage = 'Reg / WO No. must be 100 characters or fewer.';
    } else {
        if (count($items) > 10) {
            $errorMessage = 'A submission can contain up to 10 complaints.';
        }
        foreach ($items as $index => $item) {
            if ($errorMessage !== '') {
                break;
            }
            if (!in_array($item['complaint_type'], $options['types'], true)) {
                $errorMessage = 'Select a valid complaint type for complaint ' . ($index + 1) . '.';
            } elseif (!in_array($item['product'], $options['products'], true)) {
                $errorMessage = 'Select a product from the catalog for complaint ' . ($index + 1) . '.';
            } elseif (($item['quantity_supplied'] === '') !== ($item['quantity_unit'] === '')) {
                $errorMessage = 'Enter both the quantity and its unit, or leave both blank, for complaint ' . ($index + 1) . '.';
            } elseif (
                $item['quantity_supplied'] !== ''
                && (
                    !in_array($item['quantity_unit'], $options['quantity_units'], true)
                    || !preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $item['quantity_supplied'])
                    || (float) $item['quantity_supplied'] <= 0
                )
            ) {
                $errorMessage = 'Enter a valid quantity and unit for complaint ' . ($index + 1) . '.';
            } elseif (
                $item['actual_product'] !== ''
                && !in_array($item['actual_product'], $options['products'], true)
            ) {
                $errorMessage = 'Select the actual supplied material from the catalog for complaint ' . ($index + 1) . '.';
            } elseif (($item['actual_quantity_supplied'] === '') !== ($item['actual_quantity_unit'] === '')) {
                $errorMessage = 'Enter both the actual supplied quantity and its unit, or leave both blank, for complaint ' . ($index + 1) . '.';
            } elseif (
                $item['actual_quantity_supplied'] !== ''
                && (
                    !in_array($item['actual_quantity_unit'], $options['quantity_units'], true)
                    || !preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $item['actual_quantity_supplied'])
                    || (float) $item['actual_quantity_supplied'] <= 0
                )
            ) {
                $errorMessage = 'Enter a valid actual supplied quantity and unit for complaint ' . ($index + 1) . '.';
            } elseif (
                $item['complaint_type'] === 'Wrong Dispatch'
                && (
                    $item['quantity_supplied'] === ''
                    || $item['actual_product'] === ''
                    || $item['actual_quantity_supplied'] === ''
                )
            ) {
                $errorMessage = 'For Wrong Dispatch, enter the wrongly dispatched material and quantity, and the actual supplied material and quantity.';
            } elseif ($item['details'] === '' || strlen($item['details']) > 10000) {
                $errorMessage = 'Enter complaint details of up to 10,000 characters for complaint ' . ($index + 1) . '.';
            }
        }
    }
    if ($errorMessage === '') {
        $uploadResult = validateComplaintUploads($_FILES);
        $errorMessage = $uploadResult['error'];
        $draftAttachmentBytes = array_sum(array_map(
            static fn ($attachment) => (int) $attachment['file_size'],
            $draftAttachments
        ));
        if (
            $errorMessage === ''
            && (
                count($draftAttachments) + count($uploadResult['files']) > 5
                || $draftAttachmentBytes + array_sum(array_column($uploadResult['files'], 'size')) > 50 * 1024 * 1024
            )
        ) {
            $errorMessage = 'A complaint can contain up to 5 attachments and 50 MB in total.';
        }
        if ($errorMessage === '') {
            foreach ($draftAttachments as $attachment) {
                $storedName = $attachment['stored_name'];
                $storedPath = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'complaints' . DIRECTORY_SEPARATOR . $storedName;
                if (
                    !is_string($storedName)
                    || !preg_match(complaintDraftAttachmentPattern(), $storedName)
                    || !is_file($storedPath)
                    || filesize($storedPath) !== (int) $attachment['file_size']
                ) {
                    $errorMessage = 'A saved draft attachment is missing or changed. Remove it and upload it again.';
                    break;
                }
            }
        }
    }

    if ($errorMessage === '') {
        try {
            $uploadDirectory = $uploadResult['files'] !== [] || $draftAttachments !== []
                ? ensurePrivateComplaintStorage()
                : __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'complaints';
        } catch (RuntimeException $exception) {
            $errorMessage = $exception->getMessage();
        }
        if ($errorMessage === '' && ($uploadResult['files'] !== [] || $draftAttachments !== []) && !is_writable($uploadDirectory)) {
            $errorMessage = 'Secure file storage is not writable. Please contact an administrator.';
        }
        if ($errorMessage === '') {
            $savedPaths = [];
            $storageFailure = false;
            $conn->begin_transaction();

            try {
                $creatorId = (int) $currentUser['id'];
                $dealerId = $canSelectDealer && $form['dealer_id'] !== ''
                    ? (int) $form['dealer_id']
                    : null;
                $invoiceReference = $form['invoice_reference'] !== '' ? $form['invoice_reference'] : null;
                $statement = $conn->prepare(
                    "INSERT INTO complaints
                        (created_by, dealer_id, title, farmer_name, farmer_mobile, state, village, taluka, complaint_type,
                         product, supply_date, quantity_supplied, quantity_unit,
                         actual_product, actual_quantity_supplied, actual_quantity_unit,
                         invoice_reference, wo_number, district, department, details)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $assignmentStatement = $conn->prepare(
                    "INSERT INTO complaint_department_assignments (complaint_id, department)
                     VALUES (?, ?)"
                );
                $attachmentStatement = $conn->prepare(
                    "INSERT INTO complaint_attachments
                        (complaint_id, category, stored_name, original_name, mime_type, file_size)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $attachmentsToLink = [];
                foreach ($draftAttachments as $attachment) {
                    $attachmentsToLink[] = [
                        'category' => $attachment['category'],
                        'stored_name' => $attachment['stored_name'],
                        'extension' => pathinfo($attachment['stored_name'], PATHINFO_EXTENSION),
                        'source_path' => $uploadDirectory . DIRECTORY_SEPARATOR . $attachment['stored_name'],
                        'temporary_path' => null,
                        'is_upload' => false,
                        'original_name' => $attachment['original_name'],
                        'mime_type' => $attachment['mime_type'],
                        'file_size' => (int) $attachment['file_size']
                    ];
                }
                foreach ($uploadResult['files'] as $file) {
                    $attachmentsToLink[] = [
                        'category' => $file['category'],
                        'stored_name' => '',
                        'extension' => $file['extension'],
                        'source_path' => '',
                        'temporary_path' => $file['temporary_path'],
                        'is_upload' => true,
                        'original_name' => $file['original_name'],
                        'mime_type' => $file['mime_type'],
                        'file_size' => (int) $file['size']
                    ];
                }
                $firstComplaintAttachmentPaths = [];
                $slaDays = max(1, min(30, (int) getPortalSetting($conn, 'sla_business_days', '5')));
                $approvalManagerIds = $currentUser['role'] === 'dealer'
                    ? getAreaManagerIdsForDistrict($conn, $form['district'])
                    : [];
                $requiresManagerApproval = $approvalManagerIds !== [];
                $approvalStatement = $requiresManagerApproval
                    ? $conn->prepare(
                        "INSERT INTO complaint_approvals (complaint_id, status)
                         VALUES (?, 'Pending')"
                    )
                    : null;
                foreach ($items as $itemIndex => $item) {
                    $supplyDate = $item['supply_date'] !== '' ? $item['supply_date'] : null;
                    $quantity = $item['quantity_supplied'] !== '' ? $item['quantity_supplied'] : null;
                    $quantityUnit = $item['quantity_unit'] !== '' ? $item['quantity_unit'] : null;
                    $actualProduct = $item['complaint_type'] === 'Wrong Dispatch' ? $item['actual_product'] : null;
                    $actualQuantity = $item['complaint_type'] === 'Wrong Dispatch' && $item['actual_quantity_supplied'] !== ''
                        ? $item['actual_quantity_supplied']
                        : null;
                    $actualQuantityUnit = $item['complaint_type'] === 'Wrong Dispatch' ? $item['actual_quantity_unit'] : null;
                    $woNumber = $item['wo_number'] !== '' ? $item['wo_number'] : null;
                    $assignedDepartments = $options['routing'][$item['complaint_type']];
                    $departmentRoute = implode(', ', $assignedDepartments);
                    $statement->bind_param(
                        'ii' . str_repeat('s', 19),
                        $creatorId,
                        $dealerId,
                        $form['title'],
                        $form['farmer_name'],
                        $form['farmer_mobile'],
                        $form['state'],
                        $form['village'],
                        $form['taluka'],
                        $item['complaint_type'],
                        $item['product'],
                        $supplyDate,
                        $quantity,
                        $quantityUnit,
                        $actualProduct,
                        $actualQuantity,
                        $actualQuantityUnit,
                        $invoiceReference,
                        $woNumber,
                        $form['district'],
                        $departmentRoute,
                        $item['details']
                    );
                    $statement->execute();
                    $complaintId = (int) $conn->insert_id;
                    $locationPath = addLocationPath(
                        $conn,
                        $form['state'],
                        $form['district'],
                        $form['taluka'],
                        $form['village']
                    );
                    [$form['state'], $form['district'], $form['taluka'], $form['village']] = $locationPath;
                    $locationStatement = $conn->prepare(
                        'UPDATE complaints SET state = ?, district = ?, taluka = ?, village = ? WHERE id = ?'
                    );
                    $locationStatement->bind_param(
                        'ssssi',
                        $form['state'],
                        $form['district'],
                        $form['taluka'],
                        $form['village'],
                        $complaintId
                    );
                    $locationStatement->execute();
                    $stateCode = getComplaintStateCode($form['state'], $form['district']);
                    if ($stateCode === null) {
                        throw new RuntimeException('Unable to determine the complaint state. Select a dealer with a valid state or contact an administrator.');
                    }
                    $sequence = getNextComplaintSequence($conn, $stateCode);
                    $complaintReference = buildComplaintReference($stateCode, date('dm'), $sequence);
                    $referenceStatement = $conn->prepare('UPDATE complaints SET public_reference = ? WHERE id = ?');
                    $referenceStatement->bind_param('si', $complaintReference, $complaintId);
                    $referenceStatement->execute();
                    $dueAt = addBusinessDays(new DateTimeImmutable(), $slaDays)->format('Y-m-d H:i:s');
                    $dueAtStatement = $conn->prepare('UPDATE complaints SET due_at = ? WHERE id = ?');
                    $dueAtStatement->bind_param('si', $dueAt, $complaintId);
                    $dueAtStatement->execute();
                    if ($requiresManagerApproval) {
                        $approvalStatement->bind_param('i', $complaintId);
                        $approvalStatement->execute();
                        notifyUserIds(
                            $conn,
                            $approvalManagerIds,
                            'approval_required',
                            'Complaint approval required',
                            $complaintReference . ' requires your approval.',
                            $complaintId
                        );
                    } else {
                        foreach ($assignedDepartments as $assignedDepartment) {
                            $assignmentStatement->bind_param('is', $complaintId, $assignedDepartment);
                            $assignmentStatement->execute();
                        }
                        notifyComplaintDepartments(
                            $conn,
                            $assignedDepartments,
                            'assignment',
                            'New complaint assigned',
                            $complaintReference . ' is assigned to ' . implode(', ', $assignedDepartments) . '.',
                            $complaintId
                        );
                    }
                    recordAuditEvent(
                        $conn,
                        $creatorId,
                        'complaint.created',
                        'complaint',
                        $complaintId,
                        $complaintReference . ' was submitted.',
                        [
                            'complaint_type' => $item['complaint_type'],
                            'product' => $item['product'],
                            'district' => $form['district'],
                            'dealer_id' => $dealerId,
                            'departments' => $assignedDepartments,
                            'area_manager_approval_required' => $requiresManagerApproval
                        ]
                    );
                    try {
                        $complaintAttachmentDirectory = $attachmentsToLink !== []
                            ? ensureComplaintAttachmentStorage($complaintId)
                            : '';
                    } catch (RuntimeException $exception) {
                        $storageFailure = true;
                        throw $exception;
                    }
                    foreach ($attachmentsToLink as $attachmentIndex => $attachment) {
                        $storedName = bin2hex(random_bytes(16)) . '.' . $attachment['extension'];
                        $destination = $complaintAttachmentDirectory . DIRECTORY_SEPARATOR . $storedName;
                        if ($attachment['is_upload'] && $itemIndex === 0) {
                            if (!move_uploaded_file($attachment['temporary_path'], $destination)) {
                                $storageFailure = true;
                                throw new RuntimeException('Unable to move an uploaded file into private storage.');
                            }
                            $firstComplaintAttachmentPaths[$attachmentIndex] = $destination;
                        } else {
                            $sourcePath = $attachment['is_upload']
                                ? $firstComplaintAttachmentPaths[$attachmentIndex]
                                : $attachment['source_path'];
                            if (!copy($sourcePath, $destination)) {
                                $storageFailure = true;
                                throw new RuntimeException('Unable to copy an attachment into complaint storage.');
                            }
                        }
                        $savedPaths[] = $destination;
                        $attachmentStatement->bind_param(
                            'issssi',
                            $complaintId,
                            $attachment['category'],
                            $storedName,
                            $attachment['original_name'],
                            $attachment['mime_type'],
                            $attachment['file_size']
                        );
                        $attachmentStatement->execute();
                    }
                }

                if ($draft) {
                    $deleteDraftStatement = $conn->prepare('DELETE FROM complaint_drafts WHERE id = ? AND user_id = ?');
                    $deleteDraftStatement->bind_param('ii', $draft['id'], $creatorId);
                    $deleteDraftStatement->execute();
                }

                $conn->commit();
                foreach ($draftAttachments as $attachment) {
                    $draftPath = $uploadDirectory . DIRECTORY_SEPARATOR . $attachment['stored_name'];
                    if (is_file($draftPath) && !unlink($draftPath)) {
                        error_log('Unable to remove a draft attachment after complaint submission.');
                    }
                }
                $_SESSION['flash_success'] = $requiresManagerApproval
                    ? (count($items) === 1
                        ? 'Complaint submitted and sent to the Area Manager for approval.'
                        : count($items) . ' complaints submitted and sent to the Area Manager for approval.')
                    : (count($items) === 1
                        ? 'Complaint submitted successfully.'
                        : count($items) . ' complaints submitted successfully.');
                header('Location: dashboard.php');
                exit;
            } catch (Throwable $exception) {
                $conn->rollback();
                foreach ($savedPaths as $savedPath) {
                    if (is_file($savedPath)) {
                        unlink($savedPath);
                    }
                }
                if (!$storageFailure) {
                    throw $exception;
                }
                $errorMessage = 'The server could not save an attachment. Please try again.';
            }
        }
    }
}

$roleLabels = [
    'dealer' => 'Dealer',
    'area_manager' => 'Area Manager',
    'department' => 'Department',
    'admin' => 'Administrator'
];
$routingJson = json_encode(
    $options['routing'],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>New complaint | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/searchable-selects.js" defer></script>
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
                    <p class="eyebrow">Complaint management</p>
                    <h1 class="page-heading">Create a new complaint</h1>
                    <p class="page-subtitle">Submit one or more issues for a farmer. Each complaint is tracked separately.</p>
                </div>
                <span class="role-badge"><?php echo escapeHtml($roleLabels[$currentUser['role']] ?? 'Team Member'); ?></span>
            </div>

            <?php if ($errorMessage !== ''): ?>
                <div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div>
            <?php endif; ?>
            <?php if ($currentUser['role'] === 'area_manager' && $availableDistricts === []): ?>
                <div class="notice notice--error" role="alert">No districts are assigned to your account. Contact an administrator before submitting a complaint.</div>
            <?php else: ?>
                <form method="POST" enctype="multipart/form-data" class="form-stack" id="complaint-form">
                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($_SESSION['csrf_token']); ?>">
                    <p id="draft-save-status" class="field-hint" role="status"><?php echo $draft ? 'Saved draft restored. Changes save automatically.' : 'Your complaint draft saves automatically as you type.'; ?></p>
                    <section class="complaint-form-section">
                        <header class="form-section-heading">
                            <span class="section-number">01</span>
                            <div><h2>Farmer and location</h2><p>Who is affected, and where did it happen?</p></div>
                        </header>
                        <div class="form-grid">
                            <?php if ($canSelectDealer): ?>
                                <div class="field field--full">
                                    <label id="dealer-label" for="dealer_id">Dealer <span class="optional-label"><?php echo $currentUser['role'] === 'area_manager' ? 'Required' : 'Optional'; ?></span></label>
                                    <?php if ($activeDealers !== []): ?>
                                        <div id="dealer-combobox" class="dealer-combobox" hidden>
                                            <div class="dealer-combobox-control">
                                                <input
                                                    id="dealer-picker"
                                                    type="text"
                                                    role="combobox"
                                                    aria-labelledby="dealer-label"
                                                    aria-autocomplete="list"
                                                    aria-haspopup="listbox"
                                                    aria-controls="dealer-option-list"
                                                    aria-expanded="false"
                                                    placeholder="Select or search a dealer"
                                                    autocomplete="off"
                                                >
                                                <button type="button" class="dealer-combobox-toggle" aria-label="Show dealer options" aria-expanded="false">
                                                    <span aria-hidden="true">⌄</span>
                                                </button>
                                            </div>
                                            <div id="dealer-option-list" class="dealer-option-list" role="listbox" aria-labelledby="dealer-label" hidden></div>
                                        </div>
                                    <?php endif; ?>
                                    <select
                                        id="dealer_id"
                                        name="dealer_id"
                                        <?php echo $activeDealers !== [] ? 'data-searchable="true"' : ''; ?>
                                        <?php echo $currentUser['role'] === 'area_manager' ? 'data-required="true" required' : ''; ?>
                                    >
                                        <option value=""><?php echo $currentUser['role'] === 'area_manager' ? 'Select a dealer' : 'Select a dealer (optional)'; ?></option>
                                        <?php foreach ($activeDealers as $dealer): ?>
                                            <option
                                                value="<?php echo (int) $dealer['id']; ?>"
                                                data-state="<?php echo escapeHtml($dealer['state']); ?>"
                                                data-village="<?php echo escapeHtml($dealer['village']); ?>"
                                                data-taluka="<?php echo escapeHtml($dealer['taluka']); ?>"
                                                data-district="<?php echo escapeHtml($dealer['district']); ?>"
                                                <?php echo $form['dealer_id'] === (string) $dealer['id'] ? 'selected' : ''; ?>
                                            ><?php echo escapeHtml($dealer['dealer_code'] . ' — ' . $dealer['name'] . ($dealer['district'] ? ' — ' . $dealer['district'] : '')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="field-hint">
                                        <?php if ($activeDealers === []): ?>
                                            <?php if ($currentUser['role'] === 'area_manager'): ?>
                                                No active dealers are available in your assigned districts. Contact an administrator.
                                            <?php else: ?>
                                                No active dealers are available. <a href="admin_dealers.php">Import the dealer list</a> first.
                                            <?php endif; ?>
                                        <?php else: ?>
                                            Selecting a dealer fills its State, District, Taluka, and Village. Enter the farmer's name separately.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                            <div class="field">
                                <label for="farmer_name">Farmer name</label>
                                <input id="farmer_name" type="text" name="farmer_name" maxlength="100" value="<?php echo escapeHtml($form['farmer_name']); ?>" autocomplete="name" placeholder="Farmer's full name" required>
                            </div>
                            <div class="field">
                                <label for="farmer_mobile">Farmer mobile number</label>
                                <input id="farmer_mobile" type="tel" name="farmer_mobile" maxlength="20" value="<?php echo escapeHtml($form['farmer_mobile']); ?>" autocomplete="tel" placeholder="10-digit mobile number" required>
                                <p class="field-hint">Use the same mobile number on each complaint to find this farmer's complaint history.</p>
                            </div>
                            <div class="field">
                                <label for="state">State</label>
                                <input id="state" type="text" name="state" maxlength="100" list="<?php echo escapeHtml(locationCatalogDatalistId('state')); ?>" value="<?php echo escapeHtml($form['state']); ?>" placeholder="Select or type a State" required>
                            </div>
                            <div class="field">
                                <label for="district">District</label>
                                <input id="district" type="text" name="district" maxlength="100" list="<?php echo escapeHtml(locationCatalogDatalistId('district')); ?>" value="<?php echo escapeHtml($form['district']); ?>" placeholder="Select or type a District" required>
                            </div>
                            <div class="field">
                                <label for="taluka">Taluka</label>
                                <input id="taluka" type="text" name="taluka" maxlength="100" list="<?php echo escapeHtml(locationCatalogDatalistId('taluka')); ?>" value="<?php echo escapeHtml($form['taluka']); ?>" placeholder="Select or type a Taluka" required>
                            </div>
                            <div class="field">
                                <label for="village">Village</label>
                                <input id="village" type="text" name="village" maxlength="100" list="<?php echo escapeHtml(locationCatalogDatalistId('village')); ?>" value="<?php echo escapeHtml($form['village']); ?>" placeholder="Select or type a Village" required>
                            </div>
                            <div class="field">
                                <label for="supply_date">Product supply date</label>
                                <input id="supply_date" type="date" name="supply_date" value="<?php echo escapeHtml($form['supply_date']); ?>">
                                <p class="field-hint">This date applies to all complaint items below.</p>
                            </div>
                            <div class="field">
                                <label for="wo_number">Government Reg / WO No. <span class="optional-label">Optional</span></label>
                                <input id="wo_number" type="text" name="wo_number" maxlength="100" value="<?php echo escapeHtml($form['wo_number']); ?>" placeholder="e.g. 2526-VTH-12536">
                                <p class="field-hint">Applies to all complaint items; leave blank for open supply.</p>
                            </div>
                        </div>
                    </section>

                    <section class="complaint-form-section">
                        <header class="form-section-heading">
                            <span class="section-number">02</span>
                            <div><h2>Complaint items</h2><p>Add up to 10 issues. Each complaint is tracked and routed separately.</p></div>
                            <span class="complaint-count" id="complaint-count" role="status" aria-live="polite"><?php echo count($items); ?> / 10 complaints</span>
                        </header>
                        <div class="field field--full">
                            <label for="title">Complaint title (shared)</label>
                            <input id="title" type="text" name="title" maxlength="180" value="<?php echo escapeHtml($form['title']); ?>" placeholder="Briefly summarize the issues" required>
                        </div>
                        <div id="complaint-items" class="complaint-items-list">
                            <?php foreach ($items as $itemIndex => $item): ?>
                            <article class="complaint-item" data-complaint-item>
                                <div class="complaint-item-heading">
                                    <div class="complaint-item-heading-copy">
                                        <h3>Complaint <?php echo $itemIndex + 1; ?></h3>
                                        <p class="complaint-item-summary" data-item-summary></p>
                                    </div>
                                    <div class="complaint-item-heading-actions">
                                        <button type="button" class="secondary-button complaint-item-toggle" data-toggle-complaint-item aria-expanded="true" disabled>Collapse</button>
                                        <?php if ($itemIndex > 0): ?>
                                            <button type="button" class="secondary-button complaint-remove-button" data-remove-complaint>Remove</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="form-grid" data-complaint-item-fields>
                            <div class="field">
                                <label for="item-<?php echo $itemIndex; ?>-type">Complaint type</label>
                                <select id="item-<?php echo $itemIndex; ?>-type" name="items[<?php echo $itemIndex; ?>][complaint_type]" data-item-field="complaint_type" required>
                                    <option value="">Select a type</option>
                                    <?php foreach ($options['types'] as $type): ?>
                                        <option value="<?php echo escapeHtml($type); ?>" <?php echo $item['complaint_type'] === $type ? 'selected' : ''; ?>><?php echo escapeHtml($type); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="field-hint" data-routing-hint aria-live="polite">The complaint will be routed automatically based on its type.</p>
                            </div>
                            <div class="field">
                                <label data-item-label="product" for="item-<?php echo $itemIndex; ?>-product"><?php echo $item['complaint_type'] === 'Wrong Dispatch' ? 'Wrongly dispatched material' : 'Product'; ?></label>
                                <select id="item-<?php echo $itemIndex; ?>-product" name="items[<?php echo $itemIndex; ?>][product]" data-item-field="product" required>
                                    <option value="">Select a product</option>
                                    <?php foreach ($options['products'] as $product): ?>
                                        <option value="<?php echo escapeHtml($product); ?>" <?php echo $item['product'] === $product ? 'selected' : ''; ?>><?php echo escapeHtml($product); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label data-item-label="quantity_supplied" for="item-<?php echo $itemIndex; ?>-quantity"><?php echo $item['complaint_type'] === 'Wrong Dispatch' ? 'Wrongly dispatched quantity' : 'Quantity'; ?></label>
                                <input id="item-<?php echo $itemIndex; ?>-quantity" type="number" name="items[<?php echo $itemIndex; ?>][quantity_supplied]" data-item-field="quantity_supplied" min="0.001" max="999999999.999" step="0.001" value="<?php echo escapeHtml($item['quantity_supplied']); ?>" placeholder="e.g. 1" <?php echo $item['complaint_type'] === 'Wrong Dispatch' ? 'required' : ''; ?>>
                            </div>
                            <div class="field">
                                <label data-item-label="quantity_unit" for="item-<?php echo $itemIndex; ?>-unit"><?php echo $item['complaint_type'] === 'Wrong Dispatch' ? 'Wrong quantity unit' : 'Quantity unit'; ?></label>
                                <select id="item-<?php echo $itemIndex; ?>-unit" name="items[<?php echo $itemIndex; ?>][quantity_unit]" data-item-field="quantity_unit" <?php echo $item['complaint_type'] === 'Wrong Dispatch' ? 'required' : ''; ?>>
                                    <option value="">Select a unit</option>
                                    <?php foreach ($options['quantity_units'] as $unit): ?>
                                        <option value="<?php echo escapeHtml($unit); ?>" <?php echo $item['quantity_unit'] === $unit ? 'selected' : ''; ?>><?php echo escapeHtml($unit); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field field--full" data-actual-supply-field <?php echo $item['complaint_type'] === 'Wrong Dispatch' ? '' : 'hidden'; ?>>
                                <label for="item-<?php echo $itemIndex; ?>-actual-product">Actually supplied material</label>
                                <select id="item-<?php echo $itemIndex; ?>-actual-product" name="items[<?php echo $itemIndex; ?>][actual_product]" data-item-field="actual_product" <?php echo $item['complaint_type'] === 'Wrong Dispatch' ? 'required' : 'disabled'; ?>>
                                    <option value="">Select a product</option>
                                    <?php foreach ($options['products'] as $product): ?>
                                        <option value="<?php echo escapeHtml($product); ?>" <?php echo $item['actual_product'] === $product ? 'selected' : ''; ?>><?php echo escapeHtml($product); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field" data-actual-supply-field <?php echo $item['complaint_type'] === 'Wrong Dispatch' ? '' : 'hidden'; ?>>
                                <label for="item-<?php echo $itemIndex; ?>-actual-quantity">Actual supplied quantity</label>
                                <input id="item-<?php echo $itemIndex; ?>-actual-quantity" type="number" name="items[<?php echo $itemIndex; ?>][actual_quantity_supplied]" data-item-field="actual_quantity_supplied" min="0.001" max="999999999.999" step="0.001" value="<?php echo escapeHtml($item['actual_quantity_supplied']); ?>" placeholder="e.g. 1" <?php echo $item['complaint_type'] === 'Wrong Dispatch' ? 'required' : 'disabled'; ?>>
                            </div>
                            <div class="field" data-actual-supply-field <?php echo $item['complaint_type'] === 'Wrong Dispatch' ? '' : 'hidden'; ?>>
                                <label for="item-<?php echo $itemIndex; ?>-actual-unit">Actual quantity unit</label>
                                <select id="item-<?php echo $itemIndex; ?>-actual-unit" name="items[<?php echo $itemIndex; ?>][actual_quantity_unit]" data-item-field="actual_quantity_unit" <?php echo $item['complaint_type'] === 'Wrong Dispatch' ? 'required' : 'disabled'; ?>>
                                    <option value="">Select a unit</option>
                                    <?php foreach ($options['quantity_units'] as $unit): ?>
                                        <option value="<?php echo escapeHtml($unit); ?>" <?php echo $item['actual_quantity_unit'] === $unit ? 'selected' : ''; ?>><?php echo escapeHtml($unit); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field field--full">
                                <label for="item-<?php echo $itemIndex; ?>-details">Details for this complaint</label>
                                <textarea id="item-<?php echo $itemIndex; ?>-details" name="items[<?php echo $itemIndex; ?>][details]" data-item-field="details" maxlength="10000" placeholder="Describe this issue, e.g. 75mm was dispatched instead of 90mm" required><?php echo escapeHtml($item['details']); ?></textarea>
                            </div>
                                </div>
                            </article>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="secondary-button add-complaint-button" id="add-complaint">+ Add another complaint</button>
                        <template id="complaint-item-template">
                            <article class="complaint-item" data-complaint-item>
                                <div class="complaint-item-heading">
                                    <div class="complaint-item-heading-copy">
                                        <h3>Complaint</h3>
                                        <p class="complaint-item-summary" data-item-summary></p>
                                    </div>
                                    <div class="complaint-item-heading-actions">
                                        <button type="button" class="secondary-button complaint-item-toggle" data-toggle-complaint-item aria-expanded="true" disabled>Collapse</button>
                                        <button type="button" class="secondary-button complaint-remove-button" data-remove-complaint>Remove</button>
                                    </div>
                                </div>
                                <div class="form-grid" data-complaint-item-fields>
                                    <div class="field">
                                        <label for="item-__INDEX__-type">Complaint type</label>
                                        <select id="item-__INDEX__-type" name="items[__INDEX__][complaint_type]" data-item-field="complaint_type" required>
                                            <option value="">Select a type</option>
                                            <?php foreach ($options['types'] as $type): ?>
                                                <option value="<?php echo escapeHtml($type); ?>"><?php echo escapeHtml($type); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="field-hint" data-routing-hint aria-live="polite">The complaint will be routed automatically based on its type.</p>
                                    </div>
                                    <div class="field">
                                        <label data-item-label="product" for="item-__INDEX__-product">Product</label>
                                        <select id="item-__INDEX__-product" name="items[__INDEX__][product]" data-item-field="product" required>
                                            <option value="">Select a product</option>
                                            <?php foreach ($options['products'] as $product): ?>
                                                <option value="<?php echo escapeHtml($product); ?>"><?php echo escapeHtml($product); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label data-item-label="quantity_supplied" for="item-__INDEX__-quantity">Quantity</label>
                                        <input id="item-__INDEX__-quantity" type="number" name="items[__INDEX__][quantity_supplied]" data-item-field="quantity_supplied" min="0.001" max="999999999.999" step="0.001" placeholder="e.g. 1">
                                    </div>
                                    <div class="field">
                                        <label data-item-label="quantity_unit" for="item-__INDEX__-unit">Quantity unit</label>
                                        <select id="item-__INDEX__-unit" name="items[__INDEX__][quantity_unit]" data-item-field="quantity_unit">
                                            <option value="">Select a unit</option>
                                            <?php foreach ($options['quantity_units'] as $unit): ?>
                                                <option value="<?php echo escapeHtml($unit); ?>"><?php echo escapeHtml($unit); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field field--full" data-actual-supply-field hidden>
                                        <label for="item-__INDEX__-actual-product">Actually supplied material</label>
                                        <select id="item-__INDEX__-actual-product" name="items[__INDEX__][actual_product]" data-item-field="actual_product" disabled>
                                            <option value="">Select a product</option>
                                            <?php foreach ($options['products'] as $product): ?>
                                                <option value="<?php echo escapeHtml($product); ?>"><?php echo escapeHtml($product); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field" data-actual-supply-field hidden>
                                        <label for="item-__INDEX__-actual-quantity">Actual supplied quantity</label>
                                        <input id="item-__INDEX__-actual-quantity" type="number" name="items[__INDEX__][actual_quantity_supplied]" data-item-field="actual_quantity_supplied" min="0.001" max="999999999.999" step="0.001" placeholder="e.g. 1" disabled>
                                    </div>
                                    <div class="field" data-actual-supply-field hidden>
                                        <label for="item-__INDEX__-actual-unit">Actual quantity unit</label>
                                        <select id="item-__INDEX__-actual-unit" name="items[__INDEX__][actual_quantity_unit]" data-item-field="actual_quantity_unit" disabled>
                                            <option value="">Select a unit</option>
                                            <?php foreach ($options['quantity_units'] as $unit): ?>
                                                <option value="<?php echo escapeHtml($unit); ?>"><?php echo escapeHtml($unit); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field field--full">
                                        <label for="item-__INDEX__-details">Details for this complaint</label>
                                        <textarea id="item-__INDEX__-details" name="items[__INDEX__][details]" data-item-field="details" maxlength="10000" placeholder="Describe this issue, e.g. 75mm was dispatched instead of 90mm" required></textarea>
                                    </div>
                                </div>
                            </article>
                        </template>
                    </section>

                    <section class="complaint-form-section">
                        <header class="form-section-heading">
                            <span class="section-number">03</span>
                            <div><h2>Product supply reference <span class="optional-label">Optional</span></h2><p>This invoice or challan reference applies to all complaints below.</p></div>
                        </header>
                        <div class="form-grid">
                            <div class="field">
                                <label for="invoice_reference">Invoice / challan reference</label>
                                <input id="invoice_reference" type="text" name="invoice_reference" maxlength="100" value="<?php echo escapeHtml($form['invoice_reference']); ?>" placeholder="Optional reference number">
                            </div>
                        </div>
                    </section>

                    <section class="complaint-form-section">
                        <header class="form-section-heading">
                            <span class="section-number">04</span>
                            <div><h2>Evidence and attachments</h2><p>Attach supporting files for these complaints.</p></div>
                        </header>
                        <div class="form-stack">
                            <div class="upload-categories">
                                <div class="upload-category">
                                    <label for="images">Photos</label>
                                    <input id="images" type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp" multiple>
                                    <p class="field-hint">JPG, PNG, or WebP</p>
                                </div>
                                <div class="upload-category">
                                    <label for="documents">Documents</label>
                                    <input id="documents" type="file" name="documents[]" accept=".pdf,.docx,.xlsx" multiple>
                                    <p class="field-hint">PDF, DOCX, or XLSX</p>
                                </div>
                                <div class="upload-category">
                                    <label for="videos">Videos</label>
                                    <input id="videos" type="file" name="videos[]" accept=".mp4,.webm" multiple>
                                    <p class="field-hint">MP4 or WebM</p>
                                </div>
                            </div>
                            <p class="field-hint">Attach up to 5 files in total, with a maximum of 10 MB per file and 50 MB combined. Photos over 10 MB are automatically compressed in your browser; documents and videos must be under 10 MB.</p>
                            <p id="image-compression-status" class="field-hint" aria-live="polite"></p>
                            <div class="field">
                                <label>Saved draft attachments</label>
                                <ul id="draft-attachment-list" class="draft-attachment-list">
                                    <?php foreach ($draftAttachments as $attachment): ?>
                                        <li data-draft-attachment-id="<?php echo (int) $attachment['id']; ?>">
                                            <span><?php echo escapeHtml($attachment['original_name']); ?> · <?php echo escapeHtml($attachment['category']); ?> · <?php echo number_format((int) $attachment['file_size'] / 1024, 1); ?> KB</span>
                                            <button type="button" class="secondary-button draft-attachment-remove" data-attachment-id="<?php echo (int) $attachment['id']; ?>">Remove</button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if ($draftAttachments === []): ?><p id="draft-attachment-empty" class="field-hint">No attachments saved yet.</p><?php endif; ?>
                            </div>
                        </div>
                    </section>
                    <div class="form-actions">
                        <a class="secondary-button" href="dashboard.php">Cancel</a>
                        <button type="button" class="secondary-button" id="discard-draft">Discard draft</button>
                        <button type="submit" class="primary-button">Submit complaint <span aria-hidden="true">→</span></button>
                    </div>
                </form>
                <?php renderLocationDatalists($locationNames); ?>
            <?php endif; ?>
        </section>
    </main>
    <script>
        const complaintRoutes = <?php echo $routingJson; ?>;
        const complaintForm = document.querySelector('form');
        const complaintItems = document.getElementById('complaint-items');
        const complaintItemTemplate = document.getElementById('complaint-item-template');
        const addComplaintButton = document.getElementById('add-complaint');
        const complaintCount = document.getElementById('complaint-count');
        const imageInput = document.getElementById('images');
        const compressionStatus = document.getElementById('image-compression-status');
        const submitButton = complaintForm?.querySelector('button[type="submit"]');
        const maxImageBytes = 10 * 1024 * 1024;
        const targetImageBytes = 9 * 1024 * 1024;

        function updateRoutingHint(item) {
            const typeSelect = item.querySelector('[data-item-field="complaint_type"]');
            const routingHint = item.querySelector('[data-routing-hint]');
            const departments = complaintRoutes[typeSelect.value];
            routingHint.textContent = departments
                ? `Automatically assigned to: ${departments.join(', ')}.`
                : 'The complaint will be routed automatically based on its type.';
        }

        function updateItemSupplyFields(item) {
            const isWrongDispatch = item.querySelector('[data-item-field="complaint_type"]').value === 'Wrong Dispatch';
            const labelText = {
                product: isWrongDispatch ? 'Wrongly dispatched material' : 'Product',
                quantity_supplied: isWrongDispatch ? 'Wrongly dispatched quantity' : 'Quantity',
                quantity_unit: isWrongDispatch ? 'Wrong quantity unit' : 'Quantity unit'
            };

            for (const [field, text] of Object.entries(labelText)) {
                item.querySelector(`[data-item-label="${field}"]`).textContent = text;
            }
            for (const field of item.querySelectorAll('[data-actual-supply-field]')) {
                field.hidden = !isWrongDispatch;
                const control = field.querySelector('[data-item-field]');
                control.disabled = !isWrongDispatch;
                control.required = isWrongDispatch;
                if (control instanceof HTMLSelectElement) {
                    control.dataset.searchableRequired = String(isWrongDispatch);
                }
            }
            item.querySelector('[data-item-field="quantity_supplied"]').required = isWrongDispatch;
            item.querySelector('[data-item-field="quantity_unit"]').required = isWrongDispatch;
        }

        function updateComplaintItemSummary(item) {
            const typeSelect = item.querySelector('[data-item-field="complaint_type"]');
            const productSelect = item.querySelector('[data-item-field="product"]');
            const quantity = item.querySelector('[data-item-field="quantity_supplied"]').value.trim();
            const quantityUnit = item.querySelector('[data-item-field="quantity_unit"]').value;
            const summary = item.querySelector('[data-item-summary]');
            const parts = [
                typeSelect.value,
                productSelect.value,
                quantity ? `${quantity}${quantityUnit ? ` ${quantityUnit}` : ''}` : ''
            ].filter(Boolean);

            if (typeSelect.value === 'Wrong Dispatch') {
                const actualProduct = item.querySelector('[data-item-field="actual_product"]').value;
                if (actualProduct) {
                    parts.push(`Actually supplied: ${actualProduct}`);
                }
            }

            summary.textContent = parts.length ? parts.join(' · ') : 'Choose a complaint type and product.';

            const toggleButton = item.querySelector('[data-toggle-complaint-item]');
            const isCollapsed = item.classList.contains('is-collapsed');
            const fieldsAreValid = Array.from(item.querySelectorAll('[data-item-field]:not(:disabled)'))
                .every(control => control.checkValidity()
                    && (!(control instanceof HTMLTextAreaElement) || !control.required || control.value.trim() !== ''));
            toggleButton.disabled = !isCollapsed && !fieldsAreValid;
            toggleButton.setAttribute('aria-expanded', String(!isCollapsed));
            toggleButton.textContent = isCollapsed ? 'Edit' : 'Collapse';
        }

        if (complaintItems && complaintItemTemplate && addComplaintButton) {
            function updateComplaintHeadings() {
                const items = Array.from(complaintItems.querySelectorAll('[data-complaint-item]'));
                items.forEach((item, index) => {
                    item.querySelector('h3').textContent = `Complaint ${index + 1}`;
                    const removeButton = item.querySelector('[data-remove-complaint]');
                    if (removeButton) {
                        removeButton.hidden = items.length === 1;
                    }
                    updateRoutingHint(item);
                    updateItemSupplyFields(item);
                    updateComplaintItemSummary(item);
                });
                complaintCount.textContent = `${items.length} / 10 complaints`;
                addComplaintButton.disabled = items.length >= 10;
            }
            updateComplaintHeadings();

            complaintItems.addEventListener('input', event => {
                const item = event.target.closest('[data-complaint-item]');
                if (item && event.target.matches('[data-item-field]')) {
                    updateComplaintItemSummary(item);
                }
            });
            complaintItems.addEventListener('change', event => {
                const item = event.target.closest('[data-complaint-item]');
                if (item && event.target.matches('[data-item-field="complaint_type"]')) {
                    updateRoutingHint(item);
                    updateItemSupplyFields(item);
                }
                if (item && event.target.matches('[data-item-field]')) {
                    updateComplaintItemSummary(item);
                }
            });
            complaintItems.addEventListener('click', event => {
                const toggleButton = event.target.closest('[data-toggle-complaint-item]');
                if (toggleButton) {
                    const item = toggleButton.closest('[data-complaint-item]');
                    if (!item || (!item.classList.contains('is-collapsed') && toggleButton.disabled)) {
                        return;
                    }
                    item.classList.toggle('is-collapsed');
                    updateComplaintItemSummary(item);
                    return;
                }
                const removeButton = event.target.closest('[data-remove-complaint]');
                if (!removeButton) {
                    return;
                }
                removeButton.closest('[data-complaint-item]').remove();
                updateComplaintHeadings();
                scheduleDraftSave();
            });
            let nextComplaintIndex = complaintItems.querySelectorAll('[data-complaint-item]').length;
            addComplaintButton.addEventListener('click', () => {
                const count = complaintItems.querySelectorAll('[data-complaint-item]').length;
                if (count >= 10) {
                    return;
                }
                for (const item of complaintItems.querySelectorAll('[data-complaint-item]')) {
                    const toggleButton = item.querySelector('[data-toggle-complaint-item]');
                    if (!toggleButton.disabled) {
                        item.classList.add('is-collapsed');
                        updateComplaintItemSummary(item);
                    }
                }
                const markup = complaintItemTemplate.innerHTML.replaceAll('__INDEX__', String(nextComplaintIndex++));
                complaintItems.insertAdjacentHTML('beforeend', markup);
                updateComplaintHeadings();
                scheduleDraftSave();
            });
        }

        async function compressImage(file) {
            let image;
            try {
                image = await createImageBitmap(file);
            } catch {
                throw new Error(`Could not read "${file.name}". Please choose another image.`);
            }

            try {
                const maxDimension = 3000;
                let scale = Math.min(1, maxDimension / Math.max(image.width, image.height));
                let quality = 0.82;
                const canvas = document.createElement('canvas');

                for (let attempt = 0; attempt < 12; attempt++) {
                    canvas.width = Math.max(1, Math.round(image.width * scale));
                    canvas.height = Math.max(1, Math.round(image.height * scale));
                    const context = canvas.getContext('2d');
                    if (!context) {
                        throw new Error('Image compression is unavailable in this browser.');
                    }
                    context.drawImage(image, 0, 0, canvas.width, canvas.height);

                    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/webp', quality));
                    if (!blob || blob.type !== 'image/webp') {
                        throw new Error('This browser cannot compress images. Please use a smaller image or a supported browser.');
                    }
                    if (blob.size <= targetImageBytes) {
                        const baseName = file.name.replace(/\.[^.]+$/, '') || 'photo';
                        return new File([blob], `${baseName}.webp`, {
                            type: 'image/webp',
                            lastModified: file.lastModified
                        });
                    }

                    if (quality > 0.5) {
                        quality = Math.max(0.45, quality - 0.12);
                    } else {
                        scale *= 0.8;
                        quality = 0.82;
                    }
                }

                throw new Error(`"${file.name}" could not be reduced below 10 MB. Please choose a smaller image.`);
            } finally {
                image.close();
            }
        }

        let isPreparingImages = false;
        let submitAfterCompression = false;

        if (complaintForm && imageInput && compressionStatus && submitButton) {
            complaintForm.addEventListener('submit', async event => {
                if (submitAfterCompression) {
                    submitAfterCompression = false;
                    return;
                }

                const selectedImages = Array.from(imageInput.files);
                const oversizedImages = selectedImages.filter(file => file.size > maxImageBytes);
                if (oversizedImages.length === 0) {
                    return;
                }

                event.preventDefault();
                if (isPreparingImages) {
                    return;
                }

                isPreparingImages = true;
                submitButton.disabled = true;
                compressionStatus.textContent = `Compressing ${oversizedImages.length} large photo${oversizedImages.length === 1 ? '' : 's'}...`;

                try {
                    const transfer = new DataTransfer();
                    for (const file of selectedImages) {
                        transfer.items.add(file.size > maxImageBytes ? await compressImage(file) : file);
                    }
                    imageInput.files = transfer.files;
                    compressionStatus.textContent = 'Photo compression complete. Submitting your complaint...';
                    submitAfterCompression = true;
                    complaintForm.requestSubmit();
                } catch (error) {
                    compressionStatus.textContent = error instanceof Error
                        ? error.message
                        : 'Photo compression failed. Please choose a smaller image and try again.';
                    submitButton.disabled = false;
                } finally {
                    isPreparingImages = false;
                }
            });
        }

        const draftStatus = document.getElementById('draft-save-status');
        const dealerSelect = document.getElementById('dealer_id');
        const dealerCombobox = document.getElementById('dealer-combobox');
        const dealerPicker = document.getElementById('dealer-picker');
        const dealerOptionList = document.getElementById('dealer-option-list');
        const dealerComboboxToggle = document.querySelector('.dealer-combobox-toggle');
        const attachmentList = document.getElementById('draft-attachment-list');
        const emptyAttachmentHint = document.getElementById('draft-attachment-empty');
        const discardDraftButton = document.getElementById('discard-draft');
        const fileInputs = ['images', 'documents', 'videos']
            .map(id => document.getElementById(id))
            .filter(Boolean);
        const draftFields = [
            'dealer_id', 'title', 'farmer_name', 'farmer_mobile', 'state', 'village', 'taluka', 'district',
            'supply_date', 'wo_number', 'invoice_reference'
        ];
        let saveTimer = null;
        let saveQueue = Promise.resolve();
        let attachmentQueue = Promise.resolve();
        let uploadingDraftFiles = false;
        let submittingComplaint = false;
        let allowFinalSubmit = false;

        function collectDraftFields() {
            const fields = {};
            for (const element of complaintForm.elements) {
                if (draftFields.includes(element.name) && typeof element.value === 'string') {
                    fields[element.name] = element.value;
                }
            }
            fields.items = Array.from(complaintItems.querySelectorAll('[data-complaint-item]'), item => {
                const values = {};
                for (const control of item.querySelectorAll('[data-item-field]')) {
                    values[control.dataset.itemField] = control.value;
                }
                return values;
            });
            return fields;
        }

        async function sendDraftSave(fields) {
            if (draftStatus) {
                draftStatus.textContent = 'Saving draft...';
            }
            const response = await fetch('save_complaint_draft.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': complaintForm.elements.csrf_token.value
                },
                body: JSON.stringify({ csrf_token: complaintForm.elements.csrf_token.value, fields }),
                keepalive: true
            });
            const result = await response.json();
            if (!response.ok) {
                throw new Error(result.error || 'The complaint draft could not be saved.');
            }
            if (draftStatus) {
                const savedTime = new Date(result.updatedAt);
                draftStatus.textContent = Number.isNaN(savedTime.getTime())
                    ? 'Draft saved automatically.'
                    : `Draft saved at ${savedTime.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}.`;
            }
        }

        function queueDraftSave() {
            const fields = collectDraftFields();
            saveQueue = saveQueue.catch(() => {}).then(() => sendDraftSave(fields));
            saveQueue.catch(error => {
                if (draftStatus) {
                    draftStatus.textContent = error instanceof Error ? error.message : 'Draft save failed.';
                }
            });
            return saveQueue;
        }

        function scheduleDraftSave() {
            window.clearTimeout(saveTimer);
            saveTimer = window.setTimeout(() => {
                queueDraftSave().catch(() => {});
            }, 700);
        }

        function addSavedAttachment(attachment) {
            const item = document.createElement('li');
            item.dataset.draftAttachmentId = String(attachment.id);
            const label = document.createElement('span');
            label.textContent = `${attachment.name} · ${attachment.category}`;
            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'secondary-button draft-attachment-remove';
            removeButton.dataset.attachmentId = String(attachment.id);
            removeButton.textContent = 'Remove';
            item.append(label, removeButton);
            attachmentList.append(item);
            emptyAttachmentHint?.remove();
        }

        async function compressSelectedImages() {
            if (!imageInput) {
                return;
            }
            const selectedImages = Array.from(imageInput.files);
            const oversizedImages = selectedImages.filter(file => file.size > maxImageBytes);
            if (oversizedImages.length === 0) {
                return;
            }
            if (compressionStatus) {
                compressionStatus.textContent = `Compressing ${oversizedImages.length} large photo${oversizedImages.length === 1 ? '' : 's'}...`;
            }
            const transfer = new DataTransfer();
            for (const file of selectedImages) {
                transfer.items.add(file.size > maxImageBytes ? await compressImage(file) : file);
            }
            imageInput.files = transfer.files;
            if (compressionStatus) {
                compressionStatus.textContent = 'Photo compression complete.';
            }
        }

        async function uploadSelectedDraftFiles() {
            if (uploadingDraftFiles) {
                return;
            }
            const selectedInputs = fileInputs.filter(input => input.files.length > 0);
            if (selectedInputs.length === 0) {
                return;
            }
            uploadingDraftFiles = true;
            fileInputs.forEach(input => { input.disabled = true; });
            try {
                await compressSelectedImages();
                await queueDraftSave();
                const uploadData = new FormData();
                uploadData.append('csrf_token', complaintForm.elements.csrf_token.value);
                uploadData.append('action', 'upload');
                for (const input of selectedInputs) {
                    for (const file of input.files) {
                        uploadData.append(`${input.name}`, file);
                    }
                }
                const response = await fetch('manage_complaint_draft_attachments.php', {
                    method: 'POST',
                    body: uploadData
                });
                const result = await response.json();
                if (!response.ok) {
                    throw new Error(result.error || 'Draft attachments could not be saved.');
                }
                result.attachments.forEach(addSavedAttachment);
                selectedInputs.forEach(input => { input.value = ''; });
                if (draftStatus) {
                    draftStatus.textContent = 'Draft and attachments saved automatically.';
                }
            } catch (error) {
                if (draftStatus) {
                    draftStatus.textContent = error instanceof Error ? error.message : 'Draft attachment save failed.';
                }
            } finally {
                fileInputs.forEach(input => { input.disabled = false; });
                uploadingDraftFiles = false;
            }
        }

        function queueDraftAttachmentUpload() {
            attachmentQueue = attachmentQueue.catch(() => {}).then(uploadSelectedDraftFiles);
            return attachmentQueue;
        }

        if (complaintForm && draftStatus) {
            complaintForm.addEventListener('input', event => {
                if (event.target instanceof HTMLInputElement && (event.target.type === 'file' || event.target.classList.contains('searchable-select__search'))) {
                    return;
                }
                scheduleDraftSave();
            });
            complaintForm.addEventListener('change', event => {
                if (event.target instanceof HTMLInputElement && event.target.type === 'file') {
                    queueDraftAttachmentUpload();
                } else if (event.target instanceof HTMLInputElement && event.target.classList.contains('searchable-select__search')) {
                    return;
                } else {
                    scheduleDraftSave();
                }
            });
        }

        if (dealerSelect) {
            dealerSelect.addEventListener('change', () => {
                const option = dealerSelect.selectedOptions[0];
                if (option?.value) {
                    document.getElementById('state').value = option.dataset.state || '';
                    document.getElementById('village').value = option.dataset.village || '';
                    document.getElementById('taluka').value = option.dataset.taluka || '';
                    const districtInput = document.getElementById('district');
                    districtInput.value = option.dataset.district || '';
                    districtInput.dispatchEvent(new Event('change', { bubbles: true }));
                    scheduleDraftSave();
                }
            });
        }
        if (dealerSelect?.dataset.searchable === 'true' && dealerCombobox && dealerPicker && dealerOptionList && dealerComboboxToggle) {
            const dealerOptions = Array.from(dealerSelect.options).filter(option => option.value !== '');
            let filteredDealerOptions = dealerOptions;
            let activeDealerIndex = -1;

            function selectedDealerLabel() {
                return dealerSelect.value === '' ? '' : dealerSelect.selectedOptions[0]?.textContent.trim() || '';
            }

            const dealerRequired = dealerSelect.dataset.required === 'true';
            function validateDealerSelection() {
                const hasSelectedDealer = dealerSelect.value !== ''
                    && dealerPicker.value.trim() === selectedDealerLabel();
                dealerPicker.setCustomValidity(
                    dealerRequired && !hasSelectedDealer
                        ? 'Select an active dealer from the list.'
                        : ''
                );
            }

            function renderDealerOptions(query = '') {
                const normalizedQuery = query.trim().toLocaleLowerCase();
                filteredDealerOptions = dealerOptions.filter(option =>
                    option.textContent.trim().toLocaleLowerCase().includes(normalizedQuery)
                );
                dealerOptionList.replaceChildren();
                activeDealerIndex = -1;
                dealerPicker.removeAttribute('aria-activedescendant');

                if (filteredDealerOptions.length === 0) {
                    const emptyOption = document.createElement('div');
                    emptyOption.className = 'dealer-option dealer-option--empty';
                    emptyOption.setAttribute('role', 'option');
                    emptyOption.setAttribute('aria-disabled', 'true');
                    emptyOption.textContent = 'No dealers found';
                    dealerOptionList.append(emptyOption);
                    return;
                }

                filteredDealerOptions.forEach((option, index) => {
                    const item = document.createElement('div');
                    item.id = `dealer-option-${index}`;
                    item.className = 'dealer-option';
                    item.setAttribute('role', 'option');
                    item.setAttribute('aria-selected', option.value === dealerSelect.value ? 'true' : 'false');
                    item.textContent = option.textContent.trim();
                    item.addEventListener('pointerdown', event => event.preventDefault());
                    item.addEventListener('click', () => {
                        dealerSelect.value = option.value;
                        dealerPicker.value = option.textContent.trim();
                        validateDealerSelection();
                        dealerSelect.dispatchEvent(new Event('change', { bubbles: true }));
                        closeDealerOptions();
                    });
                    dealerOptionList.append(item);
                });
            }

            function openDealerOptions() {
                dealerOptionList.hidden = false;
                dealerPicker.setAttribute('aria-expanded', 'true');
                dealerComboboxToggle.setAttribute('aria-expanded', 'true');
                renderDealerOptions();
            }

            function closeDealerOptions() {
                dealerOptionList.hidden = true;
                dealerPicker.setAttribute('aria-expanded', 'false');
                dealerPicker.removeAttribute('aria-activedescendant');
                dealerComboboxToggle.setAttribute('aria-expanded', 'false');
                dealerPicker.value = selectedDealerLabel();
                validateDealerSelection();
                activeDealerIndex = -1;
            }

            function activateDealerOption(index) {
                if (filteredDealerOptions.length === 0) {
                    return;
                }
                activeDealerIndex = Math.max(0, Math.min(index, filteredDealerOptions.length - 1));
                const activeItem = dealerOptionList.children[activeDealerIndex];
                Array.from(dealerOptionList.children).forEach(item => item.classList.remove('is-active'));
                activeItem.classList.add('is-active');
                dealerPicker.setAttribute('aria-activedescendant', activeItem.id);
                activeItem.scrollIntoView({ block: 'nearest' });
            }

            dealerPicker.value = selectedDealerLabel();
            dealerPicker.required = dealerRequired;
            dealerSelect.required = false;
            validateDealerSelection();
            dealerSelect.hidden = true;
            dealerCombobox.hidden = false;

            dealerPicker.addEventListener('focus', () => {
                openDealerOptions();
                dealerPicker.select();
            });
            dealerPicker.addEventListener('input', () => {
                if (dealerOptionList.hidden) {
                    openDealerOptions();
                }
                if (dealerPicker.value.trim() !== selectedDealerLabel()) {
                    dealerSelect.value = '';
                }
                validateDealerSelection();
                renderDealerOptions(dealerPicker.value);
            });
            dealerPicker.addEventListener('keydown', event => {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    if (dealerOptionList.hidden) {
                        openDealerOptions();
                    }
                    activateDealerOption(activeDealerIndex + 1);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    if (dealerOptionList.hidden) {
                        openDealerOptions();
                        activateDealerOption(filteredDealerOptions.length - 1);
                    } else {
                        activateDealerOption(activeDealerIndex < 0 ? filteredDealerOptions.length - 1 : activeDealerIndex - 1);
                    }
                } else if (event.key === 'Enter' && !dealerOptionList.hidden) {
                    event.preventDefault();
                    if (activeDealerIndex >= 0) {
                        filteredDealerOptions[activeDealerIndex].click();
                    } else if (filteredDealerOptions.length === 1) {
                        filteredDealerOptions[0].click();
                    }
                } else if (event.key === 'Escape' && !dealerOptionList.hidden) {
                    event.preventDefault();
                    closeDealerOptions();
                } else if (event.key === 'Tab') {
                    closeDealerOptions();
                }
            });
            dealerComboboxToggle.addEventListener('click', () => {
                if (dealerOptionList.hidden) {
                    dealerPicker.focus();
                    openDealerOptions();
                } else {
                    closeDealerOptions();
                }
            });
            document.addEventListener('pointerdown', event => {
                if (!dealerCombobox.contains(event.target) && !dealerOptionList.hidden) {
                    closeDealerOptions();
                }
            });
        }

        attachmentList?.addEventListener('click', async event => {
            const button = event.target.closest('.draft-attachment-remove');
            if (!button) {
                return;
            }
            button.disabled = true;
            const data = new FormData();
            data.append('csrf_token', complaintForm.elements.csrf_token.value);
            data.append('action', 'remove');
            data.append('attachment_id', button.dataset.attachmentId);
            try {
                const response = await fetch('manage_complaint_draft_attachments.php', { method: 'POST', body: data });
                const result = await response.json();
                if (!response.ok) {
                    throw new Error(result.error || 'The attachment could not be removed.');
                }
                button.closest('li').remove();
                if (attachmentList.children.length === 0) {
                    const hint = document.createElement('p');
                    hint.id = 'draft-attachment-empty';
                    hint.className = 'field-hint';
                    hint.textContent = 'No attachments saved yet.';
                    attachmentList.after(hint);
                }
                if (draftStatus) {
                    draftStatus.textContent = 'Draft attachment removed.';
                }
            } catch (error) {
                button.disabled = false;
                if (draftStatus) {
                    draftStatus.textContent = error instanceof Error ? error.message : 'The attachment could not be removed.';
                }
            }
        });

        discardDraftButton?.addEventListener('click', async () => {
            if (!confirm('Discard this saved complaint draft and its attachments?')) {
                return;
            }
            discardDraftButton.disabled = true;
            const data = new FormData();
            data.append('csrf_token', complaintForm.elements.csrf_token.value);
            data.append('action', 'discard');
            try {
                const response = await fetch('manage_complaint_draft_attachments.php', { method: 'POST', body: data });
                const result = await response.json();
                if (!response.ok) {
                    throw new Error(result.error || 'The complaint draft could not be discarded.');
                }
                window.location.reload();
            } catch (error) {
                discardDraftButton.disabled = false;
                if (draftStatus) {
                    draftStatus.textContent = error instanceof Error ? error.message : 'The complaint draft could not be discarded.';
                }
            }
        });

        complaintForm?.addEventListener('submit', async event => {
            if (allowFinalSubmit) {
                allowFinalSubmit = false;
                return;
            }
            event.preventDefault();
            if (submittingComplaint) {
                return;
            }
            submittingComplaint = true;
            submitButton.disabled = true;
            window.clearTimeout(saveTimer);
            try {
                await queueDraftSave();
                await attachmentQueue;
                allowFinalSubmit = true;
                submitButton.disabled = false;
                complaintForm.requestSubmit();
            } catch (error) {
                submittingComplaint = false;
                submitButton.disabled = false;
                if (draftStatus) {
                    draftStatus.textContent = error instanceof Error ? error.message : 'Wait for the draft to save before submitting.';
                }
            }
        });

        window.addEventListener('pagehide', () => {
            if (!complaintForm || submittingComplaint) {
                return;
            }
            window.clearTimeout(saveTimer);
            const fields = collectDraftFields();
            navigator.sendBeacon(
                'save_complaint_draft.php',
                new Blob([JSON.stringify({ csrf_token: complaintForm.elements.csrf_token.value, fields })], { type: 'application/json' })
            );
        });
    </script>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
