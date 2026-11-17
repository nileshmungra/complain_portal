<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/complaint_uploads.php';
require_once __DIR__ . '/includes/xlsx.php';
require_once __DIR__ . '/includes/admin_navigation.php';
require_once __DIR__ . '/includes/complaint_workflow.php';

requireAdministrator($currentUser);

function adminSettingsUrl(array $parameters = []): string
{
    $query = http_build_query(array_filter($parameters, static fn ($value) => $value !== ''));

    return 'admin_settings.php' . ($query !== '' ? '?' . $query : '') . '#complaints';
}

$errorMessage = '';
$successMessage = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$csrfToken = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif ($action === 'import_products') {
        $upload = $_FILES['product_file'] ?? null;
        if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['name'], $upload['size'])) {
            $errorMessage = 'Choose an .xlsx product workbook to upload.';
        } elseif ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = match ((int) $upload['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The workbook must be 5 MB or smaller.',
                UPLOAD_ERR_PARTIAL => 'The workbook upload was incomplete. Please try again.',
                UPLOAD_ERR_NO_FILE => 'Choose an .xlsx product workbook to upload.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded workbook.',
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
            $productTransactionStarted = false;
            try {
                $productNames = readXlsxProductNames($upload['tmp_name']);
                $conn->begin_transaction();
                $productTransactionStarted = true;
                $productStatement = $conn->prepare('INSERT IGNORE INTO products (name) VALUES (?)');
                $insertedCount = 0;
                foreach ($productNames as $productName) {
                    $productStatement->bind_param('s', $productName);
                    $productStatement->execute();
                    $insertedCount += $productStatement->affected_rows === 1 ? 1 : 0;
                }
                $skippedCount = count($productNames) - $insertedCount;
                recordAuditEvent(
                    $conn,
                    (int) $currentUser['id'],
                    'product.imported',
                    'product_catalog',
                    null,
                    "Imported {$insertedCount} product(s); skipped {$skippedCount} duplicate(s).",
                    ['added' => $insertedCount, 'skipped' => $skippedCount]
                );
                $conn->commit();
                $productTransactionStarted = false;
                $_SESSION['flash_success'] = "Product import complete: {$insertedCount} added, {$skippedCount} duplicate(s) skipped.";
                header('Location: admin_settings.php#products');
                exit;
            } catch (RuntimeException $exception) {
                if ($productTransactionStarted) {
                    $conn->rollback();
                }
                $errorMessage = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                if ($productTransactionStarted) {
                    $conn->rollback();
                }
                error_log('Product import database error: ' . $exception->getMessage());
                $errorMessage = 'The product workbook could not be imported because of a database error.';
            }
        }
    } elseif ($action === 'delete_complaint') {
        $complaintId = filter_var($_POST['complaint_id'] ?? null, FILTER_VALIDATE_INT);
        if ($complaintId === false || $complaintId === null || $complaintId < 1) {
            $errorMessage = 'Select a valid complaint to delete.';
        } else {
            $transactionStarted = false;
            try {
                $referenceStatement = $conn->prepare('SELECT public_reference FROM complaints WHERE id = ? LIMIT 1');
                $referenceStatement->bind_param('i', $complaintId);
                $referenceStatement->execute();
                $complaintRow = $referenceStatement->get_result()->fetch_assoc();
                $attachmentStatement = $conn->prepare('SELECT stored_name FROM complaint_attachments WHERE complaint_id = ?');
                $attachmentStatement->bind_param('i', $complaintId);
                $attachmentStatement->execute();
                $attachments = $attachmentStatement->get_result()->fetch_all(MYSQLI_ASSOC);

                $conn->begin_transaction();
                $transactionStarted = true;
                $deleteStatement = $conn->prepare('DELETE FROM complaints WHERE id = ?');
                $deleteStatement->bind_param('i', $complaintId);
                $deleteStatement->execute();
                if ($deleteStatement->affected_rows !== 1) {
                    throw new RuntimeException('That complaint no longer exists.');
                }
                $complaintReference = formatComplaintReference($complaintId, $complaintRow['public_reference'] ?? null);
                recordAuditEvent(
                    $conn,
                    (int) $currentUser['id'],
                    'complaint.deleted',
                    'complaint',
                    $complaintId,
                    $complaintReference . ' was permanently deleted.'
                );
                $conn->commit();
                $transactionStarted = false;

                $cleanupFailed = false;
                foreach ($attachments as $attachment) {
                    if (!preg_match('/\A[a-f0-9]{32}\.(jpg|jpeg|png|webp|pdf|docx|xlsx|mp4|webm|zip|ogg)\z/', $attachment['stored_name'])) {
                        error_log('Invalid attachment path found while deleting complaint ' . $complaintId . '.');
                        $cleanupFailed = true;
                        continue;
                    }

                    $filePath = complaintAttachmentFilePath($complaintId, $attachment['stored_name']);
                    if (is_file($filePath) && !unlink($filePath)) {
                        error_log('Unable to remove attachment for deleted complaint ' . $complaintId . '.');
                        $cleanupFailed = true;
                    }
                }
                $complaintAttachmentDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'storage'
                    . DIRECTORY_SEPARATOR . 'complaints' . DIRECTORY_SEPARATOR . $complaintId;
                if (is_dir($complaintAttachmentDirectory) && !rmdir($complaintAttachmentDirectory)) {
                    error_log('Unable to remove the attachment folder for deleted complaint ' . $complaintId . '.');
                    $cleanupFailed = true;
                }

                $_SESSION[$cleanupFailed ? 'flash_error' : 'flash_success'] = $cleanupFailed
                    ? 'The complaint was deleted, but one or more attachment files could not be removed. Check the server log.'
                    : 'Complaint and its attachments were permanently deleted.';
                header('Location: admin_settings.php#complaints');
                exit;
            } catch (RuntimeException $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                $errorMessage = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                error_log('Complaint deletion database error: ' . $exception->getMessage());
                $errorMessage = 'The complaint could not be deleted because of a database error.';
            }
        }
    } else {
        $errorMessage = 'Choose a valid administrator action.';
    }
}

$searchTerm = isset($_GET['search']) && is_string($_GET['search'])
    ? mb_substr(trim($_GET['search']), 0, 120, 'UTF-8')
    : '';
$currentPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$currentPage = is_int($currentPage) && $currentPage > 0 ? $currentPage : 1;
$pageSize = 25;
$managementScope = ['where' => '1 = 1', 'types' => '', 'values' => []];
if ($searchTerm !== '') {
    $pattern = '%' . strtr($searchTerm, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $managementScope = [
        'where' => "(CAST(c.id AS CHAR) LIKE ? ESCAPE '!' OR COALESCE(c.public_reference, CONCAT('CMP-', LPAD(c.id, 6, '0'))) LIKE ? ESCAPE '!' OR c.title LIKE ? ESCAPE '!' OR c.farmer_name LIKE ? ESCAPE '!' OR c.farmer_mobile LIKE ? ESCAPE '!' OR c.product LIKE ? ESCAPE '!' OR c.actual_product LIKE ? ESCAPE '!' OR c.wo_number LIKE ? ESCAPE '!')",
        'types' => 'ssssssss',
        'values' => [$pattern, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern]
    ];
}
$totalComplaints = (int) fetchComplaintRows(
    $conn,
    "SELECT COUNT(*) AS total FROM complaints c WHERE {$managementScope['where']}",
    $managementScope
)[0]['total'];
$totalPages = max(1, (int) ceil($totalComplaints / $pageSize));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $pageSize;
$managedComplaints = fetchComplaintRows(
    $conn,
    "SELECT c.id, c.public_reference, c.title, c.farmer_name, c.farmer_mobile, c.product, c.wo_number, c.status, c.created_at
     FROM complaints c
     WHERE {$managementScope['where']}
     ORDER BY c.created_at DESC, c.id DESC
     LIMIT {$pageSize} OFFSET {$offset}",
    $managementScope
);
$workflowStageLabels = getComplaintWorkflowStageLabels($conn, $managedComplaints);
$activeProductCount = (int) $conn->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetch_row()[0];
$rangeStart = $managedComplaints === [] ? 0 : $offset + 1;
$rangeEnd = $offset + count($managedComplaints);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Administrator settings | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main class="dashboard-shell">
        <header class="topbar">
            <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
            <div class="topbar-actions">
                <a href="dashboard.php" class="settings-button">Dashboard</a>
                <?php renderNotificationLink($conn, (int) $currentUser['id']); ?>
                <a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a>
            </div>
        </header>
        <?php renderAdminNavigation('overview'); ?>

        <section class="page-panel admin-settings-panel">
            <div class="page-panel-heading">
                <div>
                    <p class="eyebrow">Administrator</p>
                    <h1 class="page-heading">Settings</h1>
                    <p class="page-subtitle">A central workspace for users, complaints, operations, and system controls.</p>
                </div>
            </div>

            <section class="admin-feature-grid" aria-label="Administrator tools">
                <a class="admin-feature-card" href="admin_users.php"><strong>User management</strong><span>Manage roles, access, passwords, and account status.</span></a>
                <a class="admin-feature-card" href="admin_locations.php"><strong>Location options</strong><span>Manage State, District, Taluka, and Village suggestions.</span></a>
                <a class="admin-feature-card" href="admin_bulk_complaints.php"><strong>Bulk complaint actions</strong><span>Update status and department assignments for selected complaints.</span></a>
                <a class="admin-feature-card" href="admin_workflow.php"><strong>Workflow &amp; SLA</strong><span>Set response targets and review overdue complaints.</span></a>
                <a class="admin-feature-card" href="admin_products.php"><strong>Product catalog</strong><span>Add, rename, archive, and search products.</span></a>
                <a class="admin-feature-card" href="admin_reports.php"><strong>Advanced reports</strong><span>Filter and export complaint history by date and district.</span></a>
                <a class="admin-feature-card" href="admin_audit_log.php"><strong>Audit log</strong><span>Review administrator and account activity.</span></a>
                <a class="admin-feature-card" href="admin_backup.php"><strong>Backup &amp; restore</strong><span>Create full backups and safely restore a portal snapshot.</span></a>
                <a class="admin-feature-card" href="admin_security.php"><strong>Security</strong><span>Review active sessions, login throttling, and security settings.</span></a>
            </section>

            <?php if ($successMessage !== ''): ?>
                <div class="notice notice--success" role="status"><?php echo escapeHtml($successMessage); ?></div>
            <?php endif; ?>
            <?php if ($flashError !== ''): ?>
                <div class="notice notice--error" role="alert"><?php echo escapeHtml($flashError); ?></div>
            <?php endif; ?>
            <?php if ($errorMessage !== ''): ?>
                <div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div>
            <?php endif; ?>
            <?php if (!class_exists(ZipArchive::class)): ?>
                <div class="notice notice--error" role="alert">XLSX import and export need PHP's ZIP extension. Enable <code>extension=zip</code> in Laragon's PHP configuration and restart Laragon.</div>
            <?php endif; ?>

            <section class="admin-settings-section" id="products">
                <div class="admin-section-heading">
                    <div>
                        <h2>Product catalog</h2>
                        <p><?php echo $activeProductCount; ?> active products available in complaint forms.</p>
                    </div>
                    <div class="admin-inline-form">
                        <a href="admin_products.php" class="secondary-button">Manage catalog</a>
                        <a href="download_product_template.php" class="secondary-button">Download XLSX template</a>
                    </div>
                </div>
                <form class="product-import-form" method="POST" action="admin_settings.php#products" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="import_products">
                    <div class="product-import-instructions">
                        <strong>Upload products from Excel</strong>
                        <p>Use the first worksheet with a <strong>Product Name</strong> column. New products are added; existing duplicates are skipped. Maximum 10,000 rows and 5 MB per file.</p>
                    </div>
                    <label class="product-file-label" for="product-file">Product workbook (.xlsx)</label>
                    <input id="product-file" name="product_file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    <button class="primary-button" type="submit">Import products</button>
                </form>
            </section>

            <section class="admin-settings-section" id="complaints">
                <div class="admin-section-heading">
                    <div>
                        <h2>Manage complaints</h2>
                        <p><?php echo $totalComplaints; ?> complaint(s) · showing <?php echo $rangeStart; ?>–<?php echo $rangeEnd; ?></p>
                    </div>
                </div>
                <form class="admin-complaint-search" method="GET" action="admin_settings.php" role="search">
                    <label class="visually-hidden" for="admin-complaint-search">Search complaints</label>
                    <input id="admin-complaint-search" type="search" name="search" value="<?php echo escapeHtml($searchTerm); ?>" maxlength="120" placeholder="Search ID, WO No., title, farmer, or product">
                    <button class="secondary-button" type="submit">Search</button>
                    <?php if ($searchTerm !== ''): ?><a href="admin_settings.php#complaints">Clear</a><?php endif; ?>
                </form>
                <?php if ($managedComplaints === []): ?>
                    <p class="empty-state">No complaints match this search.</p>
                <?php else: ?>
                    <div class="admin-complaint-list">
                        <?php foreach ($managedComplaints as $complaint): ?>
                            <article class="admin-complaint-row">
                                <div class="admin-complaint-info">
                                    <span class="complaint-number"><?php echo escapeHtml(formatComplaintReference((int) $complaint['id'], $complaint['public_reference'])); ?></span>
                                    <h3><?php echo escapeHtml($complaint['title']); ?></h3>
                                    <p><?php echo escapeHtml(implode(' · ', array_filter([$complaint['farmer_name'], $complaint['farmer_mobile'], $complaint['product'], $complaint['wo_number'] !== null ? 'Reg / WO No.: ' . $complaint['wo_number'] : null, $complaint['status']], static fn ($value) => $value !== null && $value !== ''))); ?></p>
                                    <p class="workflow-stage-label">Stage: <?php echo escapeHtml($workflowStageLabels[(int) $complaint['id']] ?? 'Workflow stage unavailable'); ?></p>
                                </div>
                                <div class="admin-complaint-actions">
                                    <a class="secondary-button" href="admin_edit_complaint.php?id=<?php echo (int) $complaint['id']; ?>">Edit</a>
                                    <form method="POST" action="admin_settings.php#complaints" onsubmit="return confirm('Permanently delete this complaint and all its attachments? This cannot be undone.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                                        <input type="hidden" name="action" value="delete_complaint">
                                        <input type="hidden" name="complaint_id" value="<?php echo (int) $complaint['id']; ?>">
                                        <button class="danger-button" type="submit">Delete</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <nav class="pagination" aria-label="Complaint management pages">
                            <?php if ($currentPage > 1): ?>
                                <a class="secondary-button" href="<?php echo escapeHtml(adminSettingsUrl(['search' => $searchTerm, 'page' => $currentPage - 1])); ?>">Previous</a>
                            <?php else: ?>
                                <span class="secondary-button is-disabled" aria-disabled="true">Previous</span>
                            <?php endif; ?>
                            <span>Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                            <?php if ($currentPage < $totalPages): ?>
                                <a class="secondary-button" href="<?php echo escapeHtml(adminSettingsUrl(['search' => $searchTerm, 'page' => $currentPage + 1])); ?>">Next</a>
                            <?php else: ?>
                                <span class="secondary-button is-disabled" aria-disabled="true">Next</span>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </section>
    </main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
