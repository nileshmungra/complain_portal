<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/xlsx.php';

requireAdministrator($currentUser);

sendXlsxDownload(
    'dealer-import-template.xlsx',
    ['Dealer Code', 'Dealer', 'Address', 'Village', 'Pin Code', 'Taluka', 'District', 'State', 'Country', 'Mobile', 'Email', 'Status'],
    [['D001', 'Sample Dealer', 'Main Road', 'Sample Village', '123456', 'Sample Taluka', 'Sample District', 'Sample State', 'India', '9876543210', 'dealer@example.com', 'Active']]
);
