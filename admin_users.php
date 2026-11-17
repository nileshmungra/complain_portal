<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin_navigation.php';
require_once __DIR__ . '/includes/location_catalog.php';

requireAdministrator($currentUser);

$config = require __DIR__ . '/config/complaints.php';
$districtOptions = array_values(array_unique(array_merge(
    $config['districts'],
    getLocationNamesByDepth($conn)['districts']
)));
$roleLabels = [
    'admin' => 'Administrator',
    'dealer' => 'Dealer',
    'area_manager' => 'Area Manager',
    'department' => 'Department'
];
$errorMessage = '';
$successMessage = $_SESSION['flash_success'] ?? '';
$temporaryPassword = $_SESSION['flash_temp_password'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_temp_password']);
$csrfToken = getCsrfToken();

function isValidMobileNumber(string $mobile): bool
{
    return preg_match('/\A[0-9+()\s-]{6,15}\z/', $mobile) === 1;
}

function hasDuplicateMobile(mysqli $conn, string $mobile, ?int $exceptUserId = null): bool
{
    if ($exceptUserId === null) {
        $statement = $conn->prepare('SELECT id FROM users WHERE mobile = ? LIMIT 1');
        $statement->bind_param('s', $mobile);
    } else {
        $existingStatement = $conn->prepare('SELECT mobile FROM users WHERE id = ? LIMIT 1');
        $existingStatement->bind_param('i', $exceptUserId);
        $existingStatement->execute();
        $existingUser = $existingStatement->get_result()->fetch_assoc();
        if ($existingUser && $existingUser['mobile'] === $mobile) {
            return false;
        }

        $statement = $conn->prepare('SELECT id FROM users WHERE mobile = ? AND id <> ? LIMIT 1');
        $statement->bind_param('si', $mobile, $exceptUserId);
    }
    $statement->execute();

    return $statement->get_result()->num_rows > 0;
}

function activeAdministratorCount(mysqli $conn): int
{
    return (int) $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND is_active = 1")
        ->fetch_assoc()['total'];
}

function adminUsersRedirect(string $message, ?string $temporaryPassword = null): never
{
    $_SESSION['flash_success'] = $message;
    if ($temporaryPassword !== null) {
        $_SESSION['flash_temp_password'] = $temporaryPassword;
    }
    header('Location: admin_users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif ($action === 'create_user') {
        $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
        $fullName = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
        $mobile = is_string($_POST['mobile'] ?? null) ? trim($_POST['mobile']) : '';
        $firmName = is_string($_POST['firm_name'] ?? null) ? trim($_POST['firm_name']) : '';
        $address = is_string($_POST['address'] ?? null) ? trim($_POST['address']) : '';
        $assignedAreas = $_POST['assigned_area'] ?? [];
        $department = is_string($_POST['department'] ?? null) ? $_POST['department'] : '';

        if (!isset($roleLabels[$role])) {
            $errorMessage = 'Select a valid account role.';
        } elseif ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 100) {
            $errorMessage = 'Enter a name of up to 100 characters.';
        } elseif (!isValidMobileNumber($mobile)) {
            $errorMessage = 'Enter a valid mobile number using up to 15 characters.';
        } elseif (hasDuplicateMobile($conn, $mobile)) {
            $errorMessage = 'Another account already uses this mobile number.';
        } elseif (
            !is_array($assignedAreas)
            || array_filter($assignedAreas, static fn ($area) => !is_string($area)) !== []
            || array_diff($assignedAreas, $districtOptions) !== []
        ) {
            $errorMessage = 'Select valid assigned districts.';
        } elseif ($role === 'department' && !in_array($department, $config['departments'], true)) {
            $errorMessage = 'Select a valid department.';
        } else {
            $temporaryPassword = bin2hex(random_bytes(10));
            $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
            $assignedArea = implode(',', array_values(array_unique($assignedAreas)));
            $departmentValue = $role === 'department' ? $department : null;
            $firmValue = $role === 'dealer' && $firmName !== '' ? $firmName : null;
            $addressValue = $role === 'dealer' && $address !== '' ? $address : null;
            $conn->begin_transaction();
            try {
                $statement = $conn->prepare(
                    'INSERT INTO users
                        (role, full_name, mobile, firm_name, address, assigned_area, department, password, must_change_password)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
                );
                $statement->bind_param(
                    'ssssssss',
                    $role,
                    $fullName,
                    $mobile,
                    $firmValue,
                    $addressValue,
                    $assignedArea,
                    $departmentValue,
                    $passwordHash
                );
                $statement->execute();
                $newUserId = (int) $conn->insert_id;
                recordAuditEvent(
                    $conn,
                    (int) $currentUser['id'],
                    'user.created',
                    'user',
                    $newUserId,
                    $fullName . ' was added as ' . $roleLabels[$role] . '.',
                    ['role' => $role, 'mobile' => $mobile]
                );
                $conn->commit();
                adminUsersRedirect('Account created. Share the temporary password securely; the user must change it at first sign-in.', $temporaryPassword);
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                error_log('Admin user creation failed: ' . $exception->getMessage());
                $errorMessage = 'The account could not be created because of a database error.';
            }
        }
    } elseif ($action === 'update_user') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
        $fullName = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
        $mobile = is_string($_POST['mobile'] ?? null) ? trim($_POST['mobile']) : '';
        $firmName = is_string($_POST['firm_name'] ?? null) ? trim($_POST['firm_name']) : '';
        $address = is_string($_POST['address'] ?? null) ? trim($_POST['address']) : '';
        $assignedAreas = $_POST['assigned_area'] ?? [];
        $department = is_string($_POST['department'] ?? null) ? $_POST['department'] : '';
        $activeValue = is_string($_POST['is_active'] ?? null) ? $_POST['is_active'] : '';
        $isActive = $activeValue === '1' ? 1 : 0;

        if (!is_int($userId) || $userId < 1) {
            $errorMessage = 'Select a valid user account.';
        } elseif (!in_array($activeValue, ['0', '1'], true)) {
            $errorMessage = 'Select a valid account access status.';
        } elseif (!isset($roleLabels[$role]) || $fullName === '' || mb_strlen($fullName, 'UTF-8') > 100) {
            $errorMessage = 'Enter a valid name and account role.';
        } elseif (!isValidMobileNumber($mobile)) {
            $errorMessage = 'Enter a valid mobile number using up to 15 characters.';
        } elseif (hasDuplicateMobile($conn, $mobile, $userId)) {
            $errorMessage = 'Another account already uses this mobile number.';
        } elseif (
            !is_array($assignedAreas)
            || array_filter($assignedAreas, static fn ($area) => !is_string($area)) !== []
            || array_diff($assignedAreas, $districtOptions) !== []
        ) {
            $errorMessage = 'Select valid assigned districts.';
        } elseif ($role === 'department' && !in_array($department, $config['departments'], true)) {
            $errorMessage = 'Select a valid department.';
        } else {
            $oldStatement = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $oldStatement->bind_param('i', $userId);
            $oldStatement->execute();
            $oldUser = $oldStatement->get_result()->fetch_assoc();
            if (!$oldUser) {
                $errorMessage = 'That account no longer exists.';
            } elseif (
                $userId === (int) $currentUser['id']
                && ($isActive !== 1 || $role !== 'admin')
            ) {
                $errorMessage = 'You cannot deactivate your own administrator account or remove its administrator role.';
            } elseif (
                (int) $oldUser['is_active'] === 1
                && $oldUser['role'] === 'admin'
                && ($isActive !== 1 || $role !== 'admin')
                && activeAdministratorCount($conn) <= 1
            ) {
                $errorMessage = 'The last active administrator cannot be deactivated or demoted.';
            } else {
                $assignedArea = implode(',', array_values(array_unique($assignedAreas)));
                $departmentValue = $role === 'department' ? $department : null;
                $firmValue = $role === 'dealer' && $firmName !== '' ? $firmName : null;
                $addressValue = $role === 'dealer' && $address !== '' ? $address : null;
                $accessChanged = (int) $oldUser['is_active'] !== $isActive || $oldUser['role'] !== $role;
                $conn->begin_transaction();
                try {
                    $statement = $conn->prepare(
                        'UPDATE users
                         SET role = ?, full_name = ?, mobile = ?, firm_name = ?, address = ?,
                             assigned_area = ?, department = ?, is_active = ?,
                             session_version = session_version + ?
                         WHERE id = ?'
                    );
                    $versionIncrement = $accessChanged ? 1 : 0;
                    $statement->bind_param(
                        'sssssssiii',
                        $role,
                        $fullName,
                        $mobile,
                        $firmValue,
                        $addressValue,
                        $assignedArea,
                        $departmentValue,
                        $isActive,
                        $versionIncrement,
                        $userId
                    );
                    $statement->execute();
                    if ($accessChanged) {
                        revokeUserSessions($conn, $userId, (int) $currentUser['id']);
                    }
                    recordAuditEvent(
                        $conn,
                        (int) $currentUser['id'],
                        'user.updated',
                        'user',
                        $userId,
                        $fullName . '\'s account settings were updated.',
                        [
                            'before' => [
                                'role' => $oldUser['role'],
                                'is_active' => (int) $oldUser['is_active']
                            ],
                            'after' => ['role' => $role, 'is_active' => $isActive]
                        ]
                    );
                    $conn->commit();
                    adminUsersRedirect('Account settings saved.');
                } catch (mysqli_sql_exception $exception) {
                    $conn->rollback();
                    error_log('Admin user update failed: ' . $exception->getMessage());
                    $errorMessage = 'The account could not be updated because of a database error.';
                }
            }
        }
    } elseif ($action === 'reset_password' || $action === 'toggle_status' || $action === 'revoke_sessions') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($userId) || $userId < 1) {
            $errorMessage = 'Select a valid user account.';
        } else {
            $userStatement = $conn->prepare('SELECT id, full_name, role, is_active FROM users WHERE id = ? LIMIT 1');
            $userStatement->bind_param('i', $userId);
            $userStatement->execute();
            $targetUser = $userStatement->get_result()->fetch_assoc();
            if (!$targetUser) {
                $errorMessage = 'That account no longer exists.';
            } elseif ($action === 'toggle_status') {
                $willActivate = (int) $targetUser['is_active'] !== 1;
                if ($userId === (int) $currentUser['id'] && !$willActivate) {
                    $errorMessage = 'You cannot deactivate your own administrator account.';
                } elseif (
                    !$willActivate
                    && $targetUser['role'] === 'admin'
                    && activeAdministratorCount($conn) <= 1
                ) {
                    $errorMessage = 'The last active administrator cannot be deactivated.';
                } else {
                    $newActive = $willActivate ? 1 : 0;
                    $conn->begin_transaction();
                    try {
                        $statusStatement = $conn->prepare(
                            'UPDATE users SET is_active = ?, session_version = session_version + 1 WHERE id = ?'
                        );
                        $statusStatement->bind_param('ii', $newActive, $userId);
                        $statusStatement->execute();
                        if (!$willActivate) {
                            revokeUserSessions($conn, $userId, (int) $currentUser['id']);
                        }
                        recordAuditEvent(
                            $conn,
                            (int) $currentUser['id'],
                            $willActivate ? 'user.activated' : 'user.deactivated',
                            'user',
                            $userId,
                            $targetUser['full_name'] . '\'s account was ' . ($willActivate ? 'activated' : 'deactivated') . '.'
                        );
                        $conn->commit();
                        adminUsersRedirect('Account ' . ($willActivate ? 'activated' : 'deactivated') . '.');
                    } catch (mysqli_sql_exception $exception) {
                        $conn->rollback();
                        error_log('Admin user status change failed: ' . $exception->getMessage());
                        $errorMessage = 'The account status could not be changed because of a database error.';
                    }
                }
            } elseif ($action === 'reset_password') {
                if ($userId === (int) $currentUser['id']) {
                    $errorMessage = 'Use your own security page to change your password.';
                } else {
                    $temporaryPassword = bin2hex(random_bytes(10));
                    $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
                    $conn->begin_transaction();
                    try {
                        $resetStatement = $conn->prepare(
                            'UPDATE users
                             SET password = ?, must_change_password = 1, session_version = session_version + 1
                             WHERE id = ?'
                        );
                        $resetStatement->bind_param('si', $passwordHash, $userId);
                        $resetStatement->execute();
                        revokeUserSessions($conn, $userId, (int) $currentUser['id']);
                        recordAuditEvent(
                            $conn,
                            (int) $currentUser['id'],
                            'user.password_reset',
                            'user',
                            $userId,
                            'A temporary password was issued for ' . $targetUser['full_name'] . '.'
                        );
                        $conn->commit();
                        adminUsersRedirect('Temporary password generated. Share it securely; the user must change it at sign-in.', $temporaryPassword);
                    } catch (mysqli_sql_exception $exception) {
                        $conn->rollback();
                        error_log('Admin password reset failed: ' . $exception->getMessage());
                        $errorMessage = 'The password could not be reset because of a database error.';
                    }
                }
            } else {
                $exceptHash = $userId === (int) $currentUser['id']
                    ? (string) $_SESSION['portal_session_hash']
                    : null;
                $revokedCount = revokeUserSessions($conn, $userId, (int) $currentUser['id'], $exceptHash);
                recordAuditEvent(
                    $conn,
                    (int) $currentUser['id'],
                    'user.sessions_revoked',
                    'user',
                    $userId,
                    $revokedCount . ' session(s) were revoked for ' . $targetUser['full_name'] . '.',
                    ['revoked' => $revokedCount]
                );
                adminUsersRedirect($revokedCount . ' session(s) revoked.');
            }
        }
    } else {
        $errorMessage = 'Choose a valid user management action.';
    }
}

$searchTerm = isset($_GET['search']) && is_string($_GET['search'])
    ? mb_substr(trim($_GET['search']), 0, 100, 'UTF-8')
    : '';
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editUser = null;
if (is_int($editId) && $editId > 0) {
    $editStatement = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $editStatement->bind_param('i', $editId);
    $editStatement->execute();
    $editUser = $editStatement->get_result()->fetch_assoc();
}
$users = [];
if ($searchTerm === '') {
    $users = $conn->query(
        'SELECT u.*,
                (SELECT COUNT(*) FROM user_sessions s
                 WHERE s.user_id = u.id AND s.revoked_at IS NULL AND s.expires_at > NOW()) AS active_sessions
         FROM users u ORDER BY u.is_active DESC, u.full_name, u.id'
    )->fetch_all(MYSQLI_ASSOC);
} else {
    $pattern = '%' . strtr($searchTerm, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $searchStatement = $conn->prepare(
        "SELECT u.*,
                (SELECT COUNT(*) FROM user_sessions s
                 WHERE s.user_id = u.id AND s.revoked_at IS NULL AND s.expires_at > NOW()) AS active_sessions
         FROM users u
         WHERE u.full_name LIKE ? ESCAPE '!' OR u.mobile LIKE ? ESCAPE '!' OR u.role LIKE ? ESCAPE '!'
         ORDER BY u.is_active DESC, u.full_name, u.id"
    );
    $searchStatement->bind_param('sss', $pattern, $pattern, $pattern);
    $searchStatement->execute();
    $users = $searchStatement->get_result()->fetch_all(MYSQLI_ASSOC);
}
$editAreas = $editUser
    ? array_filter(array_map('trim', explode(',', (string) $editUser['assigned_area'])))
    : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>User management | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/searchable-selects.js" defer></script>
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
    <?php renderAdminNavigation('users'); ?>
    <section class="page-panel admin-settings-panel">
        <div class="page-panel-heading">
            <div><p class="eyebrow">Administrator</p><h1 class="page-heading">User management</h1><p class="page-subtitle">Manage account roles and access without deleting complaint history.</p></div>
        </div>
        <?php if ($successMessage !== ''): ?><div class="notice notice--success" role="status"><?php echo escapeHtml($successMessage); ?></div><?php endif; ?>
        <?php if ($temporaryPassword !== ''): ?>
            <div class="notice notice--success" role="status"><strong>Temporary password:</strong> <code><?php echo escapeHtml($temporaryPassword); ?></code><br>Copy it now and share it securely. It will not be shown again.</div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div><?php endif; ?>

        <?php if ($editUser): ?>
            <section class="admin-settings-section">
                <div class="admin-section-heading"><div><h2>Edit <?php echo escapeHtml($editUser['full_name']); ?></h2><p>Changes to role or access revoke that account's existing sessions.</p></div></div>
                <form method="POST" class="form-stack">
                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" value="<?php echo (int) $editUser['id']; ?>">
                    <div class="admin-form-grid">
                        <div class="field"><label for="edit-name">Full name</label><input id="edit-name" name="full_name" maxlength="100" value="<?php echo escapeHtml($editUser['full_name']); ?>" required></div>
                        <div class="field"><label for="edit-mobile">Mobile number</label><input id="edit-mobile" name="mobile" maxlength="15" value="<?php echo escapeHtml($editUser['mobile']); ?>" required></div>
                        <div class="field"><label for="edit-role">Role</label><select id="edit-role" name="role" required><?php foreach ($roleLabels as $role => $label): ?><option value="<?php echo escapeHtml($role); ?>" <?php echo $editUser['role'] === $role ? 'selected' : ''; ?>><?php echo escapeHtml($label); ?></option><?php endforeach; ?></select></div>
                        <div class="field"><label for="edit-active">Account access</label><select id="edit-active" name="is_active"><option value="1" <?php echo (int) $editUser['is_active'] === 1 ? 'selected' : ''; ?>>Active</option><option value="0" <?php echo (int) $editUser['is_active'] !== 1 ? 'selected' : ''; ?>>Suspended</option></select></div>
                        <div class="field"><label for="edit-firm">Business / firm name</label><input id="edit-firm" name="firm_name" maxlength="100" value="<?php echo escapeHtml($editUser['firm_name']); ?>"></div>
                        <div class="field" id="edit-department-field" <?php echo $editUser['role'] !== 'department' ? 'hidden' : ''; ?>><label for="edit-department">Department</label><select id="edit-department" name="department" <?php echo $editUser['role'] === 'department' ? 'required' : ''; ?>><option value="">Select a department</option><?php foreach ($config['departments'] as $department): ?><option value="<?php echo escapeHtml($department); ?>" <?php echo $editUser['department'] === $department ? 'selected' : ''; ?>><?php echo escapeHtml($department); ?></option><?php endforeach; ?></select></div>
                        <div class="field field--full"><label for="edit-address">Address</label><textarea id="edit-address" name="address"><?php echo escapeHtml($editUser['address']); ?></textarea></div>
                        <div class="field field--full"><span class="field-label">Assigned districts</span><div class="admin-check-list"><?php foreach ($districtOptions as $district): ?><label><input type="checkbox" name="assigned_area[]" value="<?php echo escapeHtml($district); ?>" <?php echo in_array($district, $editAreas, true) ? 'checked' : ''; ?>><?php echo escapeHtml($district); ?></label><?php endforeach; ?></div></div>
                    </div>
                    <div class="form-actions"><a class="secondary-button" href="admin_users.php">Cancel</a><button class="primary-button" type="submit">Save account</button></div>
                </form>
            </section>
        <?php else: ?>
            <section class="admin-settings-section">
                <details>
                    <summary class="admin-feature-card"><strong>Create account</strong><span>New users receive a one-time temporary password and must change it on first sign-in.</span></summary>
                    <form method="POST" class="form-stack" style="margin-top:14px">
                        <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                        <input type="hidden" name="action" value="create_user">
                        <div class="admin-form-grid">
                            <div class="field"><label for="new-name">Full name</label><input id="new-name" name="full_name" maxlength="100" required></div>
                            <div class="field"><label for="new-mobile">Mobile number</label><input id="new-mobile" name="mobile" maxlength="15" required></div>
                            <div class="field"><label for="new-role">Role</label><select id="new-role" name="role" required><?php foreach ($roleLabels as $role => $label): ?><option value="<?php echo escapeHtml($role); ?>"><?php echo escapeHtml($label); ?></option><?php endforeach; ?></select></div>
                            <div class="field" id="new-department-field" hidden><label for="new-department">Department</label><select id="new-department" name="department"><option value="">Select a department</option><?php foreach ($config['departments'] as $department): ?><option value="<?php echo escapeHtml($department); ?>"><?php echo escapeHtml($department); ?></option><?php endforeach; ?></select></div>
                            <div class="field"><label for="new-firm">Business / firm name</label><input id="new-firm" name="firm_name" maxlength="100"></div>
                            <div class="field field--full"><label for="new-address">Address</label><textarea id="new-address" name="address"></textarea></div>
                            <div class="field field--full"><span class="field-label">Assigned districts</span><div class="admin-check-list"><?php foreach ($districtOptions as $district): ?><label><input type="checkbox" name="assigned_area[]" value="<?php echo escapeHtml($district); ?>"><?php echo escapeHtml($district); ?></label><?php endforeach; ?></div></div>
                        </div>
                        <button class="primary-button" type="submit">Create account</button>
                    </form>
                </details>
            </section>
        <?php endif; ?>

        <section class="admin-settings-section">
            <div class="admin-section-heading"><div><h2>Accounts</h2><p><?php echo count($users); ?> account(s) shown. Deactivation preserves their complaint history.</p></div></div>
            <form method="GET" class="admin-complaint-search" role="search">
                <label class="visually-hidden" for="user-search">Search users</label>
                <input id="user-search" type="search" name="search" value="<?php echo escapeHtml($searchTerm); ?>" placeholder="Search name, mobile, or role">
                <button class="secondary-button" type="submit">Search</button>
                <?php if ($searchTerm !== ''): ?><a href="admin_users.php">Clear</a><?php endif; ?>
            </form>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>User</th><th>Role / assignment</th><th>Access</th><th>Sessions</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><strong><?php echo escapeHtml($user['full_name']); ?></strong><br><?php echo escapeHtml($user['mobile']); ?><br><small><?php echo escapeHtml($user['firm_name']); ?></small></td>
                            <td><?php echo escapeHtml($roleLabels[$user['role']] ?? $user['role']); ?><br><small><?php echo escapeHtml($user['role'] === 'department' ? $user['department'] : $user['assigned_area']); ?></small></td>
                            <td><span class="admin-status <?php echo (int) $user['is_active'] === 1 ? '' : 'admin-status--muted'; ?>"><?php echo (int) $user['is_active'] === 1 ? 'Active' : 'Suspended'; ?></span></td>
                            <td><?php echo (int) $user['active_sessions']; ?></td>
                            <td>
                                <div class="admin-inline-form">
                                    <a class="secondary-button" href="admin_users.php?edit=<?php echo (int) $user['id']; ?>">Edit</a>
                                    <form method="POST" onsubmit="return confirm('Issue a new temporary password and revoke this user’s sessions?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                        <button class="secondary-button" type="submit" <?php echo (int) $user['id'] === (int) $currentUser['id'] ? 'disabled' : ''; ?>>Reset password</button>
                                    </form>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                        <button class="secondary-button" type="submit" <?php echo (int) $user['id'] === (int) $currentUser['id'] ? 'disabled' : ''; ?>><?php echo (int) $user['is_active'] === 1 ? 'Suspend' : 'Activate'; ?></button>
                                    </form>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                                        <input type="hidden" name="action" value="revoke_sessions">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                        <button class="secondary-button" type="submit">Revoke sessions</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($users === []): ?><tr><td colspan="5">No users match this search.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
<script>
    function bindDepartmentRole(roleId, departmentFieldId, departmentSelectId) {
        const role = document.getElementById(roleId);
        const departmentField = document.getElementById(departmentFieldId);
        const departmentSelect = document.getElementById(departmentSelectId);
        if (!role || !departmentField || !departmentSelect) {
            return;
        }

        const updateDepartmentField = () => {
            const requiresDepartment = role.value === 'department';
            departmentField.hidden = !requiresDepartment;
            departmentSelect.required = requiresDepartment;
            departmentSelect.dataset.searchableRequired = String(requiresDepartment);
            if (!requiresDepartment) {
                departmentSelect.value = '';
                departmentSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };
        role.addEventListener('change', updateDepartmentField);
        updateDepartmentField();
    }

    bindDepartmentRole('new-role', 'new-department-field', 'new-department');
    bindDepartmentRole('edit-role', 'edit-department-field', 'edit-department');
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
