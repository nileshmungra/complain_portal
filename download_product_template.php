<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/xlsx.php';

requireAdministrator($currentUser);
sendXlsxDownload('product-upload-template.xlsx', ['Product Name'], []);
