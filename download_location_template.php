<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/xlsx.php';

requireAdministrator($currentUser);

sendXlsxDownload(
    'location-import-template.xlsx',
    ['State', 'District', 'Taluka', 'Village'],
    [
        ['Gujarat', 'Rajkot', 'Rajkot', 'Sample Village'],
        ['Gujarat', 'Junagadh', 'Junagadh', 'Sample Village']
    ]
);
