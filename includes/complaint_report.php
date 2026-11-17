<?php

function fetchComplaintExportData(mysqli $conn, array $scope): array
{
    $complaints = fetchComplaintRows(
        $conn,
        "SELECT c.id, c.title, c.farmer_name, c.farmer_mobile, c.village, c.taluka, c.district,
                c.complaint_type, c.product, c.supply_date, c.quantity_supplied,
                c.quantity_unit, c.actual_product, c.actual_quantity_supplied, c.actual_quantity_unit,
                c.invoice_reference, c.wo_number, c.department, c.status, c.public_reference,
                c.details, c.created_at, c.resolved_at, u.full_name AS submitted_by,
                d.dealer_code, d.name AS selected_dealer_name,
                l.source_id AS legacy_source_id, l.dealer_name AS legacy_dealer_name,
                l.area_manager_name AS legacy_area_manager_name,
                l.created_by_name AS legacy_created_by_name,
                l.original_json AS legacy_original_json
         FROM complaints c
         INNER JOIN users u ON u.id = c.created_by
         LEFT JOIN dealers d ON d.id = c.dealer_id
         LEFT JOIN legacy_complaint_data l ON l.complaint_id = c.id
         WHERE {$scope['where']}
         ORDER BY c.created_at DESC, c.id DESC",
        $scope
    );

    if ($complaints === []) {
        return [];
    }

    $attachments = fetchComplaintRows(
        $conn,
        "SELECT a.complaint_id, a.category, a.original_name
         FROM complaint_attachments a
         INNER JOIN complaints c ON c.id = a.complaint_id
         WHERE {$scope['where']}
         ORDER BY a.id",
        $scope
    );
    $attachmentsByComplaintId = [];
    foreach ($attachments as $attachment) {
        $group = match ($attachment['category']) {
            'Image' => 'Images',
            'Video' => 'Videos',
            'Document' => 'Documents',
            default => 'Other files'
        };
        $attachmentsByComplaintId[(int) $attachment['complaint_id']][$group][] = $attachment['original_name'];
    }

    $materialEvents = fetchComplaintRows(
        $conn,
        "SELECT e.complaint_id, e.event_type, e.material, e.quantity, e.unit, e.received_on,
                e.received_from, e.received_by, e.remarks
         FROM complaint_material_events e
         INNER JOIN complaints c ON c.id = e.complaint_id
         WHERE {$scope['where']}
         ORDER BY e.received_on, e.id",
        $scope
    );
    $materialEventsByComplaintId = [];
    foreach ($materialEvents as $materialEvent) {
        $materialEventsByComplaintId[(int) $materialEvent['complaint_id']][] = $materialEvent;
    }

    foreach ($complaints as &$complaint) {
        $complaint['attachments'] = $attachmentsByComplaintId[(int) $complaint['id']] ?? [];
        $complaint['material_events'] = $materialEventsByComplaintId[(int) $complaint['id']] ?? [];
    }
    unset($complaint);

    return $complaints;
}

function complaintExportFields(array $complaint): array
{
    $legacy = json_decode((string) ($complaint['legacy_original_json'] ?? ''), true);
    $legacyWoNumber = is_array($legacy)
        ? complaintLegacyValue($legacy, ['woNumber', 'regWoNo', 'regNo', 'workOrderNumber', 'workOrderNo'])
        : null;

    return [
        'Complaint ID' => formatComplaintReference((int) $complaint['id'], $complaint['public_reference']),
        'Reg / WO No.' => $complaint['wo_number'] ?? $legacyWoNumber,
        'Title' => $complaint['title'],
        'Status' => $complaint['status'],
        'Complaint Type' => $complaint['complaint_type'],
        'Product / Wrongly Dispatched Material' => $complaint['product'],
        'Farmer Name' => $complaint['farmer_name'],
        'Farmer Mobile' => $complaint['farmer_mobile'],
        'Village' => $complaint['village'],
        'Taluka' => $complaint['taluka'],
        'District' => $complaint['district'],
        'Assigned Department' => $complaint['department'],
        'Supply Date' => $complaint['supply_date'],
        'Quantity / Wrongly Dispatched Quantity' => $complaint['quantity_supplied'],
        'Quantity Unit / Wrong Quantity Unit' => $complaint['quantity_unit'],
        'Actually Supplied Material' => $complaint['actual_product'],
        'Actually Supplied Quantity' => $complaint['actual_quantity_supplied'],
        'Actual Quantity Unit' => $complaint['actual_quantity_unit'],
        'Invoice / Challan Reference' => $complaint['invoice_reference'],
        'Complaint Details' => $complaint['details'],
        'Submitted By' => $complaint['submitted_by'],
        'Legacy ID' => $complaint['legacy_source_id'],
        'Dealer' => $complaint['selected_dealer_name'] ?? $complaint['legacy_dealer_name'],
        'Dealer Code' => $complaint['dealer_code'] ?? null,
        'Area Manager' => $complaint['legacy_area_manager_name'],
        'Originally Entered By' => $complaint['legacy_created_by_name'],
        'Created At' => $complaint['created_at'],
        'Images' => implode('; ', $complaint['attachments']['Images'] ?? []),
        'Videos' => implode('; ', $complaint['attachments']['Videos'] ?? []),
        'Documents' => implode('; ', $complaint['attachments']['Documents'] ?? []),
        'Other Files' => implode('; ', $complaint['attachments']['Other files'] ?? [])
    ];
}

function complaintLegacyValue(array $legacyRecord, array $keys): ?string
{
    $normalizedKeys = [];
    foreach ($keys as $key) {
        $normalizedKeys[strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? '')] = true;
    }

    foreach ($legacyRecord as $key => $value) {
        $normalizedKey = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key) ?? '');
        if (!isset($normalizedKeys[$normalizedKey]) || (!is_string($value) && !is_int($value) && !is_float($value))) {
            continue;
        }
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }

    return null;
}

function complaintExcelDate(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format('d-m-Y');
    } catch (Exception) {
        return '';
    }
}

function complaintExcelDayDifference(?string $start, ?string $end): string
{
    if ($start === null || $end === null || trim($start) === '' || trim($end) === '') {
        return '';
    }

    try {
        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);
        if ($endDate < $startDate) {
            return '';
        }
        return (string) $startDate->diff($endDate)->days;
    } catch (Exception) {
        return '';
    }
}

function complaintExcelQuantity(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
}

function complaintExcelProductAge(?string $supplyDate, ?string $complaintDate, ?string $legacyAge): string
{
    if ($legacyAge !== null) {
        return $legacyAge;
    }
    if ($supplyDate === null || $complaintDate === null) {
        return '';
    }

    try {
        $startDate = new DateTimeImmutable($supplyDate);
        $endDate = new DateTimeImmutable($complaintDate);
        if ($endDate < $startDate) {
            return '';
        }
        $age = $startDate->diff($endDate);
        return $age->format('%y YEARS %m MONTHS');
    } catch (Exception) {
        return '';
    }
}

function complaintExcelFields(array $complaint): array
{
    $legacy = json_decode((string) ($complaint['legacy_original_json'] ?? ''), true);
    if (!is_array($legacy)) {
        $legacy = [];
    }
    $complaintDate = complaintLegacyValue($legacy, ['complainDate', 'complaintDate', 'complaint_date', 'createdAt'])
        ?? $complaint['created_at'];
    $supplyDate = complaintLegacyValue($legacy, ['supplyDate', 'supply_date'])
        ?? $complaint['supply_date'];
    $solveDate = complaintLegacyValue($legacy, ['solveDate', 'solvedDate', 'solutionDate', 'solve_date'])
        ?? $complaint['resolved_at'];
    $closeDate = complaintLegacyValue($legacy, ['closeDate', 'closedDate', 'close_date']);
    if ($closeDate === null && $complaint['status'] === 'Closed') {
        $closeDate = $complaint['resolved_at'];
    }

    $quantity = complaintLegacyValue($legacy, ['qty', 'quantity'])
        ?? complaintExcelQuantity($complaint['quantity_supplied'] === null ? null : (string) $complaint['quantity_supplied']);
    $quantityUnit = complaintLegacyValue($legacy, ['qtyUnit', 'quantityUnit', 'unit'])
        ?? $complaint['quantity_unit'];
    $woNumber = $complaint['wo_number']
        ?? complaintLegacyValue($legacy, ['woNumber', 'regWoNo', 'regNo', 'workOrderNumber', 'workOrderNo']);
    if ($quantity !== null && $quantityUnit !== null && !str_contains(strtoupper($quantity), strtoupper($quantityUnit))) {
        $quantity .= ' ' . $quantityUnit;
    }
    $complaintBrief = complaintLegacyValue($legacy, ['complaintBrief', 'complainBrief', 'complaint_brief', 'detail'])
        ?? $complaint['details'];
    if (
        $complaint['legacy_source_id'] !== null
        && str_starts_with($complaintBrief, 'Imported from legacy complaint ')
    ) {
        $complaintBrief = '';
    }

    $materialReturn = [];
    foreach ($complaint['material_events'] ?? [] as $materialEvent) {
        if ($materialEvent['event_type'] !== 'faulty_material_returned') {
            continue;
        }
        $materialReturn[] = complaintExcelDate($materialEvent['received_on'])
            . ' — ' . $materialEvent['material']
            . ' ' . rtrim(rtrim((string) $materialEvent['quantity'], '0'), '.') . ' ' . $materialEvent['unit']
            . ' — From: ' . $materialEvent['received_from']
            . ' — By: ' . $materialEvent['received_by']
            . ($materialEvent['remarks'] !== null && $materialEvent['remarks'] !== ''
                ? ' — ' . $materialEvent['remarks']
                : '');
    }

    return [
        'COMPLAIN_ID' => $complaint['legacy_source_id'] ?: formatComplaintReference((int) $complaint['id'], $complaint['public_reference']),
        'REG / WO NO' => $woNumber,
        'FARMER_NAME' => $complaint['farmer_name'],
        'COMPLAINT_BRIEF' => $complaintBrief,
        'QTY' => $quantity,
        'SUPPLY_DATE' => complaintExcelDate($supplyDate),
        'COMPLAIN_DATE' => complaintExcelDate($complaintDate),
        'AGE_OF_PRODUCT' => complaintExcelProductAge(
            $supplyDate,
            $complaintDate,
            complaintLegacyValue($legacy, ['ageOfProduct', 'productAge'])
        ),
        'COMPLAIN_AGE' => complaintExcelDayDifference($complaintDate, date('Y-m-d')),
        'SOLVE_DATE' => complaintExcelDate($solveDate),
        'SOLVE_DAYS' => complaintLegacyValue($legacy, ['solveDays'])
            ?? complaintExcelDayDifference($complaintDate, $solveDate),
        'CLOSE_DATE' => complaintExcelDate($closeDate),
        'CLOSE_DAYS' => complaintLegacyValue($legacy, ['closeDays'])
            ?? complaintExcelDayDifference($complaintDate, $closeDate),
        'COMPLAINT_TYPE' => $complaint['complaint_type'],
        'SUPPLIER' => complaintLegacyValue($legacy, ['supplier', 'supplierName']),
        'DEALER_NAME' => $complaint['selected_dealer_name'] ?? $complaint['legacy_dealer_name'],
        'AREA_MANAGER' => $complaint['legacy_area_manager_name']
            ?? complaintLegacyValue($legacy, ['areaManager', 'areaManagerName']),
        'STATUS' => complaintLegacyValue($legacy, ['status']) ?? $complaint['status'],
        'SOLUTION_DESC_1' => complaintLegacyValue($legacy, ['solutionDesc1', 'solutionDescription1', 'solution1']),
        'SOLUTION_DESC_2' => complaintLegacyValue($legacy, ['solutionDesc2', 'solutionDescription2', 'solution2']),
        'DISPATCH_DATE' => complaintExcelDate(complaintLegacyValue($legacy, ['dispatchDate', 'dispatch_date'])),
        'FINAL_STATUS' => complaintLegacyValue($legacy, ['finalStatus', 'final_status']) ?? $complaint['status'],
        'MATERIAL_RETURN' => $materialReturn !== []
            ? implode("\n", $materialReturn)
            : (complaintLegacyValue($legacy, ['materialReturn', 'material_return']) ?? '')
    ];
}
