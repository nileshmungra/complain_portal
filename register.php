<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/admin_features.php';

$success_msg = "";
$error_msg = "";
$allowedRoles = ['dealer', 'area_manager', 'department'];
if (empty($_SESSION['register_csrf_token'])) {
    $_SESSION['register_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
    $full_name = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
    $mobile = is_string($_POST['mobile'] ?? null) ? trim($_POST['mobile']) : '';
    $firm_name = is_string($_POST['firm_name'] ?? null) ? trim($_POST['firm_name']) : '';
    $address = is_string($_POST['address'] ?? null) ? trim($_POST['address']) : '';
    $assignedAreas = $_POST['assigned_area'] ?? [];
    $department = is_string($_POST['department'] ?? null) ? trim($_POST['department']) : '';
    $plainPassword = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!is_string($csrfToken) || !hash_equals($_SESSION['register_csrf_token'], $csrfToken)) {
        $error_msg = "Your registration form expired. Refresh the page and try again.";
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error_msg = "Please select a valid role.";
    } elseif (
        $full_name === ''
        || mb_strlen($full_name, 'UTF-8') > 100
        || preg_match('/\A[0-9+()\s-]{6,15}\z/', $mobile) !== 1
        || !isStrongPassword($plainPassword)
    ) {
        $error_msg = "Enter a valid name and mobile number, and use a password with 12–72 characters, letters, and numbers.";
    } elseif (
        !is_array($assignedAreas)
        || array_filter($assignedAreas, static fn ($area) => !is_string($area)) !== []
    ) {
        $error_msg = "Please select valid areas.";
    } else {
        $allowedAreas = ['Rajkot', 'Junagadh', 'Jamnagar', 'Amreli'];
        $assignedAreas = array_map('trim', $assignedAreas);
        if (array_diff($assignedAreas, $allowedAreas) !== []) {
            $error_msg = "Please select valid areas.";
        } elseif ($role === 'department' && !in_array($department, ['Dispatch', 'Store', 'QC', 'Production'], true)) {
            $error_msg = "Please select a valid department.";
        }
    }

    if ($error_msg === '') {
        $mobileStatement = $conn->prepare('SELECT id FROM users WHERE mobile = ? LIMIT 1');
        $mobileStatement->bind_param('s', $mobile);
        $mobileStatement->execute();
        if ($mobileStatement->get_result()->num_rows > 0) {
            $error_msg = "An account already uses this mobile number. Contact an administrator if you need help.";
        }
    }

    if ($error_msg === "") {
        $assigned_area = $role === 'area_manager' ? implode(',', $assignedAreas) : '';
        $departmentValue = $role === 'department' ? $department : null;
        $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);

        try {
            $stmt = $conn->prepare(
                "INSERT INTO users (role, full_name, mobile, firm_name, address, assigned_area, department, password)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "ssssssss",
                $role,
                $full_name,
                $mobile,
                $firm_name,
                $address,
                $assigned_area,
                $departmentValue,
                $passwordHash
            );
            $stmt->execute();
            $userId = (int) $conn->insert_id;
            recordAuditEvent($conn, null, 'user.registered', 'user', $userId, $full_name . ' created an account.', ['role' => $role]);
            $success_msg = "Your account has been created. You can now sign in.";
        } catch (mysqli_sql_exception $exception) {
            error_log('Public account registration failed: ' . $exception->getMessage());
            $error_msg = "Your account could not be created because of a database error.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Create an account | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/searchable-selects.js" defer></script>
</head>
<body>
    <main class="auth-layout">
        <aside class="auth-aside">
            <a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
            <div class="aside-copy">
                <p class="eyebrow">One connected community</p>
                <h2>Better support starts with a stronger network.</h2>
                <p>Create your account to join the farmer complaint management portal.</p>
            </div>
            <p class="aside-footnote">A simpler way to stay connected with your team.</p>
        </aside>

        <section class="auth-content auth-content--wide">
            <h1 class="page-heading">Create your account</h1>
            <p class="page-subtitle">Enter your details to get started with the portal.</p>

            <?php if ($success_msg !== ""): ?>
                <div class="notice notice--success" role="status"><?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($error_msg !== ""): ?>
                <div class="notice notice--error" role="alert"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['register_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-grid">
                    <div class="field field--full">
                        <label for="role">Account type</label>
                        <select name="role" id="role" required>
                            <option value="">Select your role</option>
                            <option value="dealer">Dealer</option>
                            <option value="area_manager">Area Manager</option>
                            <option value="department">Department</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="full_name">Full name</label>
                        <input id="full_name" type="text" name="full_name" autocomplete="name" placeholder="Your full name" required>
                    </div>
                    <div class="field">
                        <label for="mobile">Mobile number</label>
                        <input id="mobile" type="tel" name="mobile" maxlength="15" autocomplete="tel" placeholder="Your mobile number" required>
                    </div>
                    <div class="field field--full">
                        <label for="password">Password</label>
                        <input id="password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" placeholder="At least 12 characters with letters and numbers" required>
                        <p class="field-hint">Use 12–72 characters with at least one letter and one number.</p>
                    </div>
                </div>

                <section id="dealer_fields" class="form-section form-grid" hidden>
                    <h2 class="section-title field--full">Dealer details</h2>
                    <div class="field field--full">
                        <label for="firm_name">Business or firm name</label>
                        <input id="firm_name" type="text" name="firm_name" autocomplete="organization" placeholder="Your business name">
                    </div>
                    <div class="field field--full">
                        <label for="address">Business address</label>
                        <textarea id="address" name="address" autocomplete="street-address" placeholder="Street, city, and postal code"></textarea>
                    </div>
                </section>

                <section id="am_fields" class="form-section form-grid" hidden>
                    <h2 class="section-title field--full">Assigned areas</h2>
                    <div class="field field--full">
                        <label for="assigned_area">Districts</label>
                        <select id="assigned_area" name="assigned_area[]" multiple aria-describedby="area-hint">
                            <option value="Rajkot">Rajkot</option>
                            <option value="Junagadh">Junagadh</option>
                            <option value="Jamnagar">Jamnagar</option>
                            <option value="Amreli">Amreli</option>
                        </select>
                        <p id="area-hint" class="field-hint">Search and select one or more districts.</p>
                    </div>
                </section>

                <section id="dept_fields" class="form-section form-grid" hidden>
                    <h2 class="section-title field--full">Department details</h2>
                    <div class="field field--full">
                        <label for="department">Department</label>
                        <select id="department" name="department">
                            <option value="Dispatch">Dispatch</option>
                            <option value="Store">Store</option>
                            <option value="QC">Quality Control</option>
                            <option value="Production">Production</option>
                        </select>
                    </div>
                </section>

                <button type="submit" class="primary-button">Create account <span aria-hidden="true">→</span></button>
            </form>
            <p class="auth-switch">Already have an account? <a href="login.php">Sign in</a></p>
        </section>
    </main>
    <script>
        const roleSelect = document.getElementById('role');
        const roleSections = {
            dealer: document.getElementById('dealer_fields'),
            area_manager: document.getElementById('am_fields'),
            department: document.getElementById('dept_fields')
        };

        function updateRoleFields() {
            for (const [role, section] of Object.entries(roleSections)) {
                section.hidden = roleSelect.value !== role;
            }
        }

        roleSelect.addEventListener('change', updateRoleFields);
        updateRoleFields();
    </script>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>