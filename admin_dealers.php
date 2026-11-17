<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin_navigation.php';
require_once __DIR__ . '/includes/dealer_import.php';
require_once __DIR__ . '/includes/xlsx.php';
require_once __DIR__ . '/includes/location_catalog.php';

requireAdministrator($currentUser);

$errorMessage = '';
$successMessage = $_SESSION['flash_success'] ?? '';
$importErrors = $_SESSION['flash_import_errors'] ?? [];
$omittedErrorCount = (int) ($_SESSION['flash_omitted_error_count'] ?? 0);
unset($_SESSION['flash_success'], $_SESSION['flash_import_errors'], $_SESSION['flash_omitted_error_count']);
$csrfToken = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif ($action !== 'import_dealers') {
        $errorMessage = 'Choose a valid dealer action.';
    } else {
        $upload = $_FILES['dealer_file'] ?? null;
        if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['name'], $upload['size'])) {
            $errorMessage = 'Choose a dealer .xlsx workbook to upload.';
        } elseif ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = match ((int) $upload['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The workbook must be 5 MB or smaller.',
                UPLOAD_ERR_PARTIAL => 'The workbook upload was incomplete. Please try again.',
                UPLOAD_ERR_NO_FILE => 'Choose a dealer .xlsx workbook to upload.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not save the workbook.',
                default => 'The workbook could not be uploaded.'
            };
        } elseif (
            !is_string($upload['name'])
            || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'xlsx'
            || (int) $upload['size'] < 1
            || (int) $upload['size'] > 5 * 1024 * 1024
            || !is_string($upload['tmp_name'])
            || !is_uploaded_file($upload['tmp_name'])
        ) {
            $errorMessage = 'Upload a valid .xlsx workbook up to 5 MB.';
        } else {
            $transactionStarted = false;
            try {
                $import = parseDealerWorkbook($upload['tmp_name']);
                if ($import['rows'] === []) {
                    $errorMessage = 'No valid dealer rows were found. Correct the workbook errors and try again.';
                    $importErrors = $import['errors'];
                    $omittedErrorCount = max(0, count($importErrors) - 10);
                    $importErrors = array_slice($importErrors, 0, 10);
                } else {
                    $conn->begin_transaction();
                    $transactionStarted = true;
                    $importedCount = importDealerRows($conn, $import['rows'], (int) $currentUser['id']);
                    foreach ($import['rows'] as $dealerRow) {
                        $state = trim((string) ($dealerRow['state'] ?? ''));
                        $district = trim((string) ($dealerRow['district'] ?? ''));
                        $taluka = trim((string) ($dealerRow['taluka'] ?? ''));
                        $village = trim((string) ($dealerRow['village'] ?? ''));
                        if ($state === '') {
                            continue;
                        }
                        if ($district === '') {
                            addLocationPath($conn, $state);
                        } elseif ($taluka === '') {
                            addLocationPath($conn, $state, $district);
                        } else {
                            addLocationPath($conn, $state, $district, $taluka, $village);
                        }
                    }
                    recordAuditEvent(
                        $conn,
                        (int) $currentUser['id'],
                        'dealer.imported',
                        'dealer_catalog',
                        null,
                        "{$importedCount} dealer row(s) were imported or updated.",
                        ['imported' => $importedCount, 'skipped' => count($import['errors'])]
                    );
                    $conn->commit();
                    $transactionStarted = false;
                    $_SESSION['flash_success'] = "{$importedCount} dealer row(s) imported or updated; " . count($import['errors']) . ' invalid row(s) skipped.';
                    $_SESSION['flash_import_errors'] = array_slice($import['errors'], 0, 10);
                    $_SESSION['flash_omitted_error_count'] = max(0, count($import['errors']) - 10);
                    header('Location: admin_dealers.php');
                    exit;
                }
            } catch (RuntimeException $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                $errorMessage = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                error_log('Dealer workbook import failed: ' . $exception->getMessage());
                $errorMessage = 'The dealer workbook could not be imported because of a database error.';
            }
        }
    }
}

$searchTerm = isset($_GET['search']) && is_string($_GET['search'])
    ? mb_substr(trim($_GET['search']), 0, 120, 'UTF-8')
    : '';
$statusFilter = isset($_GET['status']) && is_string($_GET['status']) && in_array($_GET['status'], ['active', 'inactive'], true)
    ? $_GET['status']
    : '';
$conditions = [];
$parameters = [];
$types = '';
if ($searchTerm !== '') {
    $conditions[] = "(dealer_code LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!' OR village LIKE ? ESCAPE '!' OR taluka LIKE ? ESCAPE '!' OR district LIKE ? ESCAPE '!' OR mobile LIKE ? ESCAPE '!')";
    $pattern = '%' . strtr($searchTerm, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    array_push($parameters, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern);
    $types .= 'ssssss';
}
if ($statusFilter !== '') {
    $conditions[] = 'is_active = ?';
    $parameters[] = $statusFilter === 'active' ? 1 : 0;
    $types .= 'i';
}
$where = $conditions === [] ? '1 = 1' : implode(' AND ', $conditions);
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = is_int($page) && $page > 0 ? $page : 1;
$pageSize = 50;
$countStatement = $conn->prepare("SELECT COUNT(*) AS total FROM dealers WHERE {$where}");
if ($parameters !== []) {
    $bindings = [$types];
    foreach ($parameters as &$parameter) {
        $bindings[] = &$parameter;
    }
    unset($parameter);
    call_user_func_array([$countStatement, 'bind_param'], $bindings);
}
$countStatement->execute();
$totalRows = (int) $countStatement->get_result()->fetch_assoc()['total'];
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;
$listStatement = $conn->prepare(
    "SELECT id, dealer_code, name, address, village, pin_code, taluka, district, state, country, mobile, email, is_active
     FROM dealers WHERE {$where}
     ORDER BY is_active DESC, name, dealer_code
     LIMIT {$pageSize} OFFSET {$offset}"
);
if ($parameters !== []) {
    $bindings = [$types];
    foreach ($parameters as &$parameter) {
        $bindings[] = &$parameter;
    }
    unset($parameter);
    call_user_func_array([$listStatement, 'bind_param'], $bindings);
}
$listStatement->execute();
$dealers = $listStatement->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Dealer directory | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/searchable-selects.js" defer></script>
</head>
<body>
<main class="dashboard-shell">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
        <div class="topbar-actions"><a href="dashboard.php" class="settings-button">Dashboard</a><?php renderNotificationLink($conn, (int) $currentUser['id']); ?><a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a></div>
    </header>
    <?php renderAdminNavigation('dealers'); ?>
    <section class="page-panel admin-settings-panel">
        <div class="page-panel-heading"><div><p class="eyebrow">Administrator</p><h1 class="page-heading">Dealer directory</h1><p class="page-subtitle">Import and search operational dealer details. Existing dealer codes are updated from the workbook.</p></div></div>
        <?php if ($successMessage !== ''): ?><div class="notice notice--success" role="status"><?php echo escapeHtml($successMessage); ?></div><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div><?php endif; ?>
        <?php foreach ($importErrors as $importError): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml($importError); ?></div><?php endforeach; ?>
        <?php if ($omittedErrorCount > 0): ?><p class="field-hint"><?php echo $omittedErrorCount; ?> more invalid row(s) were skipped.</p><?php endif; ?>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Import dealer workbook</h2><p>Upload .xlsx, up to 5 MB. Dealer Code and Dealer are required. Rows with an existing code update that dealer.</p></div><a class="secondary-button" href="download_dealer_template.php">Download template</a></div>
            <p class="field-hint">Only operational fields are imported. PAN, GSTIN, Aadhaar, agreement documents, and audit columns are ignored.</p>
            <form method="POST" enctype="multipart/form-data" class="admin-inline-form">
                <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                <input type="hidden" name="action" value="import_dealers">
                <input type="file" name="dealer_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                <button class="primary-button" type="submit">Import dealers</button>
            </form>
        </section>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Dealers</h2><p><?php echo $totalRows; ?> matching dealer(s) · page <?php echo $page; ?> of <?php echo $totalPages; ?></p></div></div>
            <form method="GET" class="admin-filters" role="search">
                <div class="field"><label for="dealer-search">Search</label><input id="dealer-search" type="search" name="search" maxlength="120" value="<?php echo escapeHtml($searchTerm); ?>" placeholder="Code, dealer, location, or mobile"></div>
                <div class="field"><label for="dealer-status">Status</label><select id="dealer-status" name="status"><option value="">All statuses</option><option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option><option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Deactive</option></select></div>
                <button class="secondary-button" type="submit">Search</button>
                <?php if ($searchTerm !== '' || $statusFilter !== ''): ?><a class="secondary-button" href="admin_dealers.php">Clear</a><?php endif; ?>
            </form>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Dealer</th><th>Code</th><th>Location</th><th>Address / PIN</th><th>Contact</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($dealers as $dealer): ?>
                        <tr>
                            <td><strong><?php echo escapeHtml($dealer['name']); ?></strong></td>
                            <td><?php echo escapeHtml($dealer['dealer_code']); ?></td>
                            <td><?php echo escapeHtml(implode(', ', array_filter([$dealer['village'], $dealer['taluka'], $dealer['district'], $dealer['state'], $dealer['country']]))); ?></td>
                            <td><?php echo escapeHtml($dealer['address']); ?><?php echo $dealer['pin_code'] !== null ? '<br>PIN ' . escapeHtml($dealer['pin_code']) : ''; ?></td>
                            <td><?php echo escapeHtml($dealer['mobile']); ?><?php echo $dealer['email'] !== null ? '<br>' . escapeHtml($dealer['email']) : ''; ?></td>
                            <td><span class="admin-status <?php echo (int) $dealer['is_active'] === 1 ? '' : 'admin-status--muted'; ?>"><?php echo (int) $dealer['is_active'] === 1 ? 'Active' : 'Deactive'; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($dealers === []): ?><tr><td colspan="6">No dealers match this search.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Dealer directory pages">
                    <?php if ($page > 1): ?><a class="secondary-button" href="admin_dealers.php?<?php echo http_build_query(['search' => $searchTerm, 'status' => $statusFilter, 'page' => $page - 1]); ?>">Previous</a><?php endif; ?>
                    <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?><a class="secondary-button" href="admin_dealers.php?<?php echo http_build_query(['search' => $searchTerm, 'status' => $statusFilter, 'page' => $page + 1]); ?>">Next</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
