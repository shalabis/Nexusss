<?php
require_once __DIR__ . '/../bootstrap.php';
require_admin();

$pdo = get_pdo();
$message = '';
$error = '';

function normalize_department_for_role(string $role, string $department): string
{
    if ($role === 'admin') {
        return 'Administration';
    }

    if ($role === 'it') {
        return 'IT Support';
    }

    return $department;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    $staffId = trim($_POST['staff_id'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $newRole = $_POST['new_role'] ?? 'user';

    $allowedRoles = ['admin', 'it', 'user'];
    if (!in_array($newRole, $allowedRoles, true)) {
        $newRole = 'user';
    }

    $department = normalize_department_for_role($newRole, $department);

    if (!csrf_check($csrf)) {
        $error = 'Invalid request.';
    } elseif ($staffId === '' || $fullName === '' || $newPassword === '') {
        $error = 'Staff ID, name, role, and password are required.';
    } elseif ($newRole === 'user' && $department === '') {
        $error = 'Please choose a department for user accounts.';
    } else {
        $check = $pdo->prepare('SELECT id FROM users WHERE staff_id = :staff_id LIMIT 1');
        $check->execute([
            'staff_id' => $staffId,
        ]);
        if ($check->fetch()) {
            $error = 'That staff ID already exists.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $insert = $pdo->prepare('INSERT INTO users (staff_id, full_name, department, username, password_hash, role) VALUES (:staff_id, :full_name, :department, :username, :hash, :role)');
            $insert->execute([
                'staff_id' => $staffId,
                'full_name' => $fullName,
                'department' => $department,
                'username' => $staffId,
                'hash' => $hash,
                'role' => $newRole,
            ]);
            $message = 'Account created successfully.';
        }
    }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nexus IT | Create Account</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide">
            <div class="header-row">
                <div>
                    <h1 class="brand">Nexus IT</h1>
                    <p class="subtitle">Create Account</p>
                </div>
                <div class="header-actions">
                    <a class="button ghost" href="/admin/index.php">Back to Dashboard</a>
                    <a class="button ghost" href="/logout.php">Log Out</a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert success" role="status"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="form">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />
                <label class="label" for="staff_id">Staff ID</label>
                <input class="input" type="text" id="staff_id" name="staff_id" required />

                <label class="label" for="full_name">Name</label>
                <input class="input" type="text" id="full_name" name="full_name" required />

                <label class="label" for="new_role">Role</label>
                <select class="input" id="new_role" name="new_role" required>
                    <option value="admin">Admin</option>
                    <option value="it">IT Support</option>
                    <option value="user" selected>Employee</option>
                </select>

                <div id="department-wrap">
                    <label class="label" for="department">Department</label>
                    <select class="input" id="department" name="department">
                        <option value="" disabled selected>Select department</option>
                        <option value="A1">A1</option>
                        <option value="A2">A2</option>
                        <option value="A3">A3</option>
                        <option value="CME">CME</option>
                        <option value="CMS">CMS</option>
                        <option value="PNC">PNC</option>
                        <option value="PPM">PPM</option>
                    </select>
                </div>

                <label class="label" for="new_password">Temporary Password</label>
                <input class="input" type="password" id="new_password" name="new_password" required />

                <button class="button" type="submit">Create Account</button>
            </form>
        </section>
    </main>
    <script>
        (function () {
            const roleSelect = document.getElementById('new_role');
            const departmentWrap = document.getElementById('department-wrap');
            const departmentSelect = document.getElementById('department');

            function syncDepartmentField() {
                const needsDepartment = roleSelect.value === 'user';
                departmentWrap.style.display = needsDepartment ? '' : 'none';
                departmentSelect.required = needsDepartment;

                if (!needsDepartment) {
                    departmentSelect.value = '';
                }
            }

            syncDepartmentField();
            roleSelect.addEventListener('change', syncDepartmentField);
        })();
    </script>
</body>
</html>
