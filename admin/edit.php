<?php
require_once __DIR__ . '/../bootstrap.php';
require_admin();

$pdo = get_pdo();
$id = (int)($_GET['id'] ?? 0);

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

$stmt = $pdo->prepare('SELECT id, staff_id, full_name, department, role FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo 'User not found.';
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staffId = trim($_POST['staff_id'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $role = $_POST['role'] ?? 'user';
    $password = $_POST['password'] ?? '';
    $csrf = $_POST['csrf'] ?? '';

    if (!csrf_check($csrf)) {
        $error = 'Invalid request.';
    } elseif (!in_array($role, ['admin', 'it', 'user'], true)) {
        $error = 'Invalid role.';
    } else {
        $department = normalize_department_for_role($role, $department);

        if ($staffId === '' || $fullName === '') {
            $error = 'Staff ID and name are required.';
        } elseif ($role === 'user' && $department === '') {
            $error = 'Please choose a department for user accounts.';
        }
    }

    if ($error === '') {
        $dup = $pdo->prepare('SELECT id FROM users WHERE staff_id = :staff_id AND id <> :id');
        $dup->execute([
            'staff_id' => $staffId,
            'id' => $id,
        ]);
        if ($dup->fetch()) {
            $error = 'Staff ID already exists.';
        } else {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $update = $pdo->prepare(
                    'UPDATE users SET staff_id = :staff_id, full_name = :full_name, department = :department, username = :username, role = :role, password_hash = :hash WHERE id = :id'
                );
                $update->execute([
                    'staff_id' => $staffId,
                    'full_name' => $fullName,
                    'department' => $department,
                    'username' => $staffId,
                    'role' => $role,
                    'hash' => $hash,
                    'id' => $id,
                ]);
            } else {
                $update = $pdo->prepare(
                    'UPDATE users SET staff_id = :staff_id, full_name = :full_name, department = :department, username = :username, role = :role WHERE id = :id'
                );
                $update->execute([
                    'staff_id' => $staffId,
                    'full_name' => $fullName,
                    'department' => $department,
                    'username' => $staffId,
                    'role' => $role,
                    'id' => $id,
                ]);
            }
            $message = 'Account updated successfully.';

            $stmt->execute(['id' => $id]);
            $user = $stmt->fetch();
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
    <title>Nexus IT | Edit Account</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide">
            <div class="header-row">
                <div>
                    <h1 class="brand">Nexus IT</h1>
                    <p class="subtitle">Edit Account</p>
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
                <input class="input" type="text" id="staff_id" name="staff_id" required value="<?php echo htmlspecialchars($user['staff_id']); ?>" />

                <label class="label" for="full_name">Name</label>
                <input class="input" type="text" id="full_name" name="full_name" required value="<?php echo htmlspecialchars($user['full_name']); ?>" />

                <label class="label" for="role">Role</label>
                <select class="input" id="role" name="role" required>
                    <option value="admin" <?php if ($user['role'] === 'admin') echo 'selected'; ?>>Admin</option>
                    <option value="it" <?php if ($user['role'] === 'it') echo 'selected'; ?>>IT Support</option>
                    <option value="user" <?php if ($user['role'] === 'user') echo 'selected'; ?>>Employee</option>
                </select>

                <div id="department-wrap">
                    <label class="label" for="department">Department</label>
                    <select class="input" id="department" name="department">
                        <option value="">Select department</option>
                        <option value="A1" <?php if ($user['department'] === 'A1') echo 'selected'; ?>>A1</option>
                        <option value="A2" <?php if ($user['department'] === 'A2') echo 'selected'; ?>>A2</option>
                        <option value="A3" <?php if ($user['department'] === 'A3') echo 'selected'; ?>>A3</option>
                        <option value="CME" <?php if ($user['department'] === 'CME') echo 'selected'; ?>>CME</option>
                        <option value="CMS" <?php if ($user['department'] === 'CMS') echo 'selected'; ?>>CMS</option>
                        <option value="PNC" <?php if ($user['department'] === 'PNC') echo 'selected'; ?>>PNC</option>
                        <option value="PPM" <?php if ($user['department'] === 'PPM') echo 'selected'; ?>>PPM</option>
                    </select>
                </div>

                <label class="label" for="password">New Password (leave blank to keep current)</label>
                <input class="input" type="password" id="password" name="password" />

                <button class="button" type="submit">Save Changes</button>
            </form>
        </section>
    </main>
    <script>
        (function () {
            const roleSelect = document.getElementById('role');
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
