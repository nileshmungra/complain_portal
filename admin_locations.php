<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin_navigation.php';
require_once __DIR__ . '/includes/location_catalog.php';
require_once __DIR__ . '/includes/location_import.php';
require_once __DIR__ . '/includes/xlsx.php';

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
    } elseif ($action === 'add_location') {
        $values = [];
        foreach (['state', 'district', 'taluka', 'village'] as $field) {
            $value = $_POST[$field] ?? '';
            $values[$field] = is_string($value) ? trim($value) : '';
        }

        try {
            $conn->begin_transaction();
            $savedPath = addLocationPath(
                $conn,
                $values['state'],
                $values['district'],
                $values['taluka'],
                $values['village']
            );
            $summary = 'Location option added: ' . implode(' / ', $savedPath) . '.';
            recordAuditEvent(
                $conn,
                (int) $currentUser['id'],
                'location.created',
                'location_catalog',
                null,
                $summary,
                ['path' => $savedPath]
            );
            $conn->commit();
            $_SESSION['flash_success'] = $summary;
            header('Location: admin_locations.php');
            exit;
        } catch (InvalidArgumentException $exception) {
            $conn->rollback();
            $errorMessage = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log('Location catalog update failed: ' . $exception->getMessage());
            $errorMessage = 'The location options could not be saved because of a database error.';
        }
    } elseif ($action === 'import_excel') {
        $upload = $_FILES['location_file'] ?? null;
        if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['name'], $upload['size'])) {
            $errorMessage = 'Choose a location .xlsx workbook to upload.';
        } elseif ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = match ((int) $upload['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The workbook must be 5 MB or smaller.',
                UPLOAD_ERR_PARTIAL => 'The workbook upload was incomplete. Please try again.',
                UPLOAD_ERR_NO_FILE => 'Choose a location .xlsx workbook to upload.',
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
                $import = parseLocationWorkbook($upload['tmp_name']);
                $importErrors = $import['errors'];
                if ($importErrors !== []) {
                    $errorMessage = 'No locations were imported. Correct the listed workbook rows and try again.';
                    $omittedErrorCount = max(0, count($importErrors) - 10);
                    $importErrors = array_slice($importErrors, 0, 10);
                } else {
                    $conn->begin_transaction();
                    $transactionStarted = true;
                    foreach ($import['rows'] as $location) {
                        addLocationPath(
                            $conn,
                            $location['state'],
                            $location['district'],
                            $location['taluka'],
                            $location['village']
                        );
                    }
                    $importedCount = count($import['rows']);
                    $summary = "{$importedCount} location row(s) imported or updated.";
                    recordAuditEvent(
                        $conn,
                        (int) $currentUser['id'],
                        'location.imported',
                        'location_catalog',
                        null,
                        $summary,
                        ['imported' => $importedCount]
                    );
                    $conn->commit();
                    $transactionStarted = false;
                    $_SESSION['flash_success'] = $summary;
                    header('Location: admin_locations.php');
                    exit;
                }
            } catch (mysqli_sql_exception $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                error_log('Location workbook import failed: ' . $exception->getMessage());
                $errorMessage = 'The location workbook could not be imported because of a database error.';
            } catch (InvalidArgumentException $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                $errorMessage = $exception->getMessage();
            } catch (RuntimeException $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                $errorMessage = $exception->getMessage();
            }
        }
    } else {
        $errorMessage = 'Choose a valid location action.';
    }
}

$locations = $conn->query(
    "SELECT s.name AS state, NULL AS district, NULL AS taluka, NULL AS village
     FROM location_options s WHERE s.depth = 1
     UNION ALL
     SELECT p.name, n.name, NULL, NULL
     FROM location_options n
     INNER JOIN location_options p ON p.id = n.parent_id
     WHERE n.depth = 2
     UNION ALL
     SELECT gp.name, p.name, n.name, NULL
     FROM location_options n
     INNER JOIN location_options p ON p.id = n.parent_id
     INNER JOIN location_options gp ON gp.id = p.parent_id
     WHERE n.depth = 3
     UNION ALL
     SELECT ggp.name, gp.name, p.name, n.name
     FROM location_options n
     INNER JOIN location_options p ON p.id = n.parent_id
     INNER JOIN location_options gp ON gp.id = p.parent_id
     INNER JOIN location_options ggp ON ggp.id = gp.parent_id
     WHERE n.depth = 4
     ORDER BY state, district, taluka, village"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Location options | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="dashboard-shell">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
        <div class="topbar-actions"><a href="dashboard.php" class="settings-button">Dashboard</a><?php renderNotificationLink($conn, (int) $currentUser['id']); ?><a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a></div>
    </header>
    <?php renderAdminNavigation('locations'); ?>
    <section class="page-panel admin-settings-panel">
        <div class="page-panel-heading"><div><p class="eyebrow">Administrator</p><h1 class="page-heading">Location options</h1><p class="page-subtitle">Maintain the State, District, Taluka, and Village suggestions used in complaint forms.</p></div></div>
        <?php if ($successMessage !== ''): ?><div class="notice notice--success" role="status"><?php echo escapeHtml($successMessage); ?></div><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div><?php endif; ?>
        <?php foreach ($importErrors as $importError): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml($importError); ?></div><?php endforeach; ?>
        <?php if ($omittedErrorCount > 0): ?><p class="field-hint"><?php echo $omittedErrorCount; ?> more invalid row(s) were found.</p><?php endif; ?>
        <?php if (!class_exists(ZipArchive::class)): ?><div class="notice notice--error" role="alert">Excel import needs PHP's ZIP extension. Enable <code>extension=zip</code> in Laragon's PHP configuration and restart Laragon.</div><?php endif; ?>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Import locations from Excel</h2><p>Fill all four columns in the first worksheet: State, District, Taluka, and Village. Maximum 10,000 rows and 5 MB per file. Existing names under the same parent are reused. This updates location suggestions, not existing complaint or dealer records.</p></div><a class="secondary-button" href="download_location_template.php">Download template</a></div>
            <form method="POST" enctype="multipart/form-data" class="admin-inline-form">
                <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                <input type="hidden" name="action" value="import_excel">
                <input type="file" name="location_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                <button class="primary-button" type="submit" <?php echo class_exists(ZipArchive::class) ? '' : 'disabled'; ?>>Import locations</button>
            </form>
        </section>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Add location</h2><p>Enter a State and any lower levels you want to add. Add levels in order; an existing name under the same parent is reused.</p></div></div>
            <form method="POST" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                <input type="hidden" name="action" value="add_location">
                <div class="admin-form-grid">
                    <div class="field"><label for="state">State</label><input id="state" name="state" maxlength="100" required></div>
                    <div class="field"><label for="district">District</label><input id="district" name="district" maxlength="100"></div>
                    <div class="field"><label for="taluka">Taluka</label><input id="taluka" name="taluka" maxlength="100"></div>
                    <div class="field"><label for="village">Village</label><input id="village" name="village" maxlength="100"></div>
                </div>
                <div class="form-actions"><button class="primary-button" type="submit">Add location options</button></div>
            </form>
        </section>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Saved locations</h2><p><?php echo count($locations); ?> location path(s). Locations already used by complaints are kept for historical consistency.</p></div></div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>State</th><th>District</th><th>Taluka</th><th>Village</th></tr></thead>
                    <tbody>
                    <?php foreach ($locations as $location): ?>
                        <tr>
                            <td><?php echo escapeHtml($location['state'] ?? ''); ?></td>
                            <td><?php echo escapeHtml($location['district'] ?? ''); ?></td>
                            <td><?php echo escapeHtml($location['taluka'] ?? ''); ?></td>
                            <td><?php echo escapeHtml($location['village'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($locations === []): ?><tr><td colspan="4">No location options yet. Add the first location above.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
