<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/complaint_filters.php';
require_once __DIR__ . '/includes/complaint_report.php';
require_once __DIR__ . '/includes/xlsx.php';

$filterState = getComplaintFilterState(getComplaintScope($currentUser), $_GET);
$complaints = fetchComplaintExportData($conn, $filterState['dashboardScope']);
$headers = [
    'COMPLAIN_ID',
    'REG / WO NO',
    'FARMER_NAME',
    'COMPLAINT_BRIEF',
    'QTY',
    'SUPPLY_DATE',
    'COMPLAIN_DATE',
    'AGE_OF_PRODUCT',
    'COMPLAIN_AGE',
    'SOLVE_DATE',
    'SOLVE_DAYS',
    'CLOSE_DATE',
    'CLOSE_DAYS',
    'COMPLAINT_TYPE',
    'SUPPLIER',
    'DEALER_NAME',
    'AREA_MANAGER',
    'STATUS',
    'SOLUTION_DESC_1',
    'SOLUTION_DESC_2',
    'DISPATCH_DATE',
    'FINAL_STATUS',
    'MATERIAL_RETURN'
];
$rows = array_map(
    static fn ($complaint) => array_values(complaintExcelFields($complaint)),
    $complaints
);
$columnWidths = [
    1 => 18, 2 => 24, 3 => 20, 4 => 48, 5 => 12, 6 => 14, 7 => 14, 8 => 20,
    9 => 14, 10 => 14, 11 => 12, 12 => 14, 13 => 12, 14 => 20, 15 => 20, 16 => 30,
    17 => 22, 18 => 14, 19 => 34, 20 => 34, 21 => 14, 22 => 16, 23 => 42
];
$wrapTextColumns = [2, 3, 4, 14, 15, 16, 17, 19, 20, 23];

sendXlsxDownload(
    'farmer-portal-complaints-' . date('Y-m-d') . '.xlsx',
    $headers,
    $rows,
    $columnWidths,
    $wrapTextColumns
);
