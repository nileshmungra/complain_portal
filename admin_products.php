<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin_navigation.php';

requireAdministrator($currentUser);

$errorMessage = '';
$successMessage = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$csrfToken = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif ($action === 'create' || $action === 'update') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        if ($action === 'update' && (!is_int($productId) || $productId < 1)) {
            $errorMessage = 'Select a valid product.';
        } elseif ($name === '' || mb_strlen($name, 'UTF-8') > 300) {
            $errorMessage = 'Enter a product name of up to 300 characters.';
        } else {
            $duplicateStatement = $action === 'create'
                ? $conn->prepare('SELECT id, is_active FROM products WHERE name = ? LIMIT 1')
                : $conn->prepare('SELECT id FROM products WHERE name = ? AND id <> ? LIMIT 1');
            if ($action === 'create') {
                $duplicateStatement->bind_param('s', $name);
            } else {
                $duplicateStatement->bind_param('si', $name, $productId);
            }
            $duplicateStatement->execute();
            $duplicate = $duplicateStatement->get_result()->fetch_assoc();

            if ($duplicate && $action === 'update') {
                $errorMessage = 'Another product already uses that name.';
            } elseif ($duplicate && (int) $duplicate['is_active'] === 1) {
                $errorMessage = 'That product is already active in the catalog.';
            } else {
                $conn->begin_transaction();
                try {
                    if ($action === 'create' && $duplicate) {
                        $productId = (int) $duplicate['id'];
                        $statement = $conn->prepare('UPDATE products SET is_active = 1 WHERE id = ?');
                        $statement->bind_param('i', $productId);
                        $statement->execute();
                        $event = 'product.reactivated';
                        $summary = $name . ' was reactivated in the product catalog.';
                    } elseif ($action === 'create') {
                        $statement = $conn->prepare('INSERT INTO products (name) VALUES (?)');
                        $statement->bind_param('s', $name);
                        $statement->execute();
                        $productId = (int) $conn->insert_id;
                        $event = 'product.created';
                        $summary = $name . ' was added to the product catalog.';
                    } else {
                        $statement = $conn->prepare('UPDATE products SET name = ? WHERE id = ?');
                        $statement->bind_param('si', $name, $productId);
                        $statement->execute();
                        $event = 'product.renamed';
                        $summary = 'Product catalog entry ' . $productId . ' was renamed.';
                    }
                    recordAuditEvent(
                        $conn,
                        (int) $currentUser['id'],
                        $event,
                        'product',
                        $productId,
                        $summary,
                        ['name' => $name]
                    );
                    $conn->commit();
                    $_SESSION['flash_success'] = $summary;
                    header('Location: admin_products.php');
                    exit;
                } catch (mysqli_sql_exception $exception) {
                    $conn->rollback();
                    error_log('Product catalog update failed: ' . $exception->getMessage());
                    $errorMessage = 'The product catalog could not be updated because of a database error.';
                }
            }
        }
    } elseif ($action === 'toggle') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($productId) || $productId < 1) {
            $errorMessage = 'Select a valid product.';
        } else {
            $statement = $conn->prepare('SELECT name, is_active FROM products WHERE id = ? LIMIT 1');
            $statement->bind_param('i', $productId);
            $statement->execute();
            $product = $statement->get_result()->fetch_assoc();
            if (!$product) {
                $errorMessage = 'That product no longer exists.';
            } else {
                $isActive = (int) $product['is_active'] === 1 ? 0 : 1;
                $conn->begin_transaction();
                try {
                    $updateStatement = $conn->prepare('UPDATE products SET is_active = ? WHERE id = ?');
                    $updateStatement->bind_param('ii', $isActive, $productId);
                    $updateStatement->execute();
                    $event = $isActive === 1 ? 'product.reactivated' : 'product.archived';
                    recordAuditEvent(
                        $conn,
                        (int) $currentUser['id'],
                        $event,
                        'product',
                        $productId,
                        $product['name'] . ($isActive === 1 ? ' was restored to' : ' was archived from') . ' the catalog.'
                    );
                    $conn->commit();
                    $_SESSION['flash_success'] = 'Product ' . ($isActive === 1 ? 'restored' : 'archived') . '. Historical complaints were not changed.';
                    header('Location: admin_products.php');
                    exit;
                } catch (mysqli_sql_exception $exception) {
                    $conn->rollback();
                    error_log('Product archive action failed: ' . $exception->getMessage());
                    $errorMessage = 'The product could not be updated because of a database error.';
                }
            }
        }
    } else {
        $errorMessage = 'Choose a valid product action.';
    }
}

$searchTerm = isset($_GET['search']) && is_string($_GET['search'])
    ? mb_substr(trim($_GET['search']), 0, 120, 'UTF-8')
    : '';
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = is_int($page) && $page > 0 ? $page : 1;
$pageSize = 50;
if ($searchTerm === '') {
    $totalProducts = (int) $conn->query('SELECT COUNT(*) FROM products')->fetch_row()[0];
} else {
    $countStatement = $conn->prepare("SELECT COUNT(*) FROM products WHERE name LIKE ? ESCAPE '!'");
    $pattern = '%' . strtr($searchTerm, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $countStatement->bind_param('s', $pattern);
    $countStatement->execute();
    $totalProducts = (int) $countStatement->get_result()->fetch_row()[0];
}
$totalPages = max(1, (int) ceil($totalProducts / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;
if ($searchTerm === '') {
    $products = $conn->query(
        'SELECT p.id, p.name, p.is_active, p.created_at,
                (SELECT COUNT(*) FROM complaints c WHERE c.product = p.name) AS complaint_count
         FROM products p ORDER BY p.is_active DESC, p.name
         LIMIT ' . $pageSize . ' OFFSET ' . $offset
    )->fetch_all(MYSQLI_ASSOC);
} else {
    $statement = $conn->prepare(
        "SELECT p.id, p.name, p.is_active, p.created_at,
                (SELECT COUNT(*) FROM complaints c WHERE c.product = p.name) AS complaint_count
         FROM products p WHERE p.name LIKE ? ESCAPE '!'
         ORDER BY p.is_active DESC, p.name
         LIMIT {$pageSize} OFFSET {$offset}"
    );
    $statement->bind_param('s', $pattern);
    $statement->execute();
    $products = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Product catalog | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="dashboard-shell">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
        <div class="topbar-actions"><a href="dashboard.php" class="settings-button">Dashboard</a><?php renderNotificationLink($conn, (int) $currentUser['id']); ?><a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a></div>
    </header>
    <?php renderAdminNavigation('products'); ?>
    <section class="page-panel admin-settings-panel">
        <div class="page-panel-heading"><div><p class="eyebrow">Administrator</p><h1 class="page-heading">Product catalog</h1><p class="page-subtitle">Archive products to hide them from new complaints; existing records remain unchanged.</p></div></div>
        <?php if ($successMessage !== ''): ?><div class="notice notice--success" role="status"><?php echo escapeHtml($successMessage); ?></div><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div><?php endif; ?>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Add product</h2><p>Names are unique. Adding an archived name restores it to active use.</p></div><a class="secondary-button" href="admin_settings.php#products">Import from Excel</a></div>
            <form method="POST" class="admin-inline-form">
                <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                <input type="hidden" name="action" value="create">
                <input type="text" name="name" maxlength="300" placeholder="Product name" aria-label="Product name" required>
                <button class="primary-button" type="submit">Add product</button>
            </form>
        </section>
        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Products</h2><p><?php echo $totalProducts; ?> matching catalog item(s) · page <?php echo $page; ?> of <?php echo $totalPages; ?></p></div></div>
            <form method="GET" class="admin-complaint-search" role="search">
                <label class="visually-hidden" for="product-search">Search products</label>
                <input id="product-search" type="search" name="search" maxlength="120" value="<?php echo escapeHtml($searchTerm); ?>" placeholder="Search product names">
                <button class="secondary-button" type="submit">Search</button>
                <?php if ($searchTerm !== ''): ?><a href="admin_products.php">Clear</a><?php endif; ?>
            </form>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Product</th><th>Status</th><th>Complaint history</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <form method="POST" class="admin-inline-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                                    <input type="text" name="name" maxlength="300" value="<?php echo escapeHtml($product['name']); ?>" aria-label="Product name" required>
                                    <button class="secondary-button" type="submit">Save name</button>
                                </form>
                            </td>
                            <td><span class="admin-status <?php echo (int) $product['is_active'] === 1 ? '' : 'admin-status--muted'; ?>"><?php echo (int) $product['is_active'] === 1 ? 'Active' : 'Archived'; ?></span></td>
                            <td><?php echo (int) $product['complaint_count']; ?> complaint(s)</td>
                            <td>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                                    <button class="secondary-button" type="submit"><?php echo (int) $product['is_active'] === 1 ? 'Archive' : 'Restore'; ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($products === []): ?><tr><td colspan="4">No products match this search.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Product catalog pages">
                    <?php if ($page > 1): ?><a class="secondary-button" href="admin_products.php?<?php echo http_build_query(['search' => $searchTerm, 'page' => $page - 1]); ?>">Previous</a><?php endif; ?>
                    <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?><a class="secondary-button" href="admin_products.php?<?php echo http_build_query(['search' => $searchTerm, 'page' => $page + 1]); ?>">Next</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
