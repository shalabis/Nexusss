<?php
require_once __DIR__ . '/../bootstrap.php';
require_admin();

$pdo = get_pdo();

$search = trim($_GET['search'] ?? '');
$role = $_GET['role'] ?? '';
$department = $_GET['department'] ?? '';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(staff_id LIKE :search OR full_name LIKE :search OR department LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if (in_array($role, ['admin', 'it', 'user'], true)) {
    $where[] = "role = :role";
    $params['role'] = $role;
}

if ($department !== '') {
    $where[] = "department = :department";
    $params['department'] = $department;
}

$sql = "SELECT id, staff_id, full_name, department, role, created_at
        FROM users";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$departments = $pdo->query(
    "SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department <> '' ORDER BY department ASC"
)->fetchAll();

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nexus IT | Created Account</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide">
            <div class="header-row">
                <div>
                    <h1 class="brand">Nexus IT</h1>
                    <p class="subtitle">Created Account</p>
                </div>
                <div class="header-actions">
                    <a class="button ghost" href="/admin/index.php">Back to Dashboard</a>
                    <a class="button ghost" href="/logout.php">Log Out</a>
                </div>
            </div>

            <form method="GET" class="filter-bar">
                <input class="input" type="text" name="search" placeholder="Search name, staff ID, department..." value="<?php echo htmlspecialchars($search); ?>" />
                <select class="input" name="role">
                    <option value="">All Roles</option>
                    <option value="admin" <?php if ($role === 'admin') echo 'selected'; ?>>Admin</option>
                    <option value="it" <?php if ($role === 'it') echo 'selected'; ?>>IT Support</option>
                    <option value="user" <?php if ($role === 'user') echo 'selected'; ?>>Employee</option>
                </select>
                <select class="input" name="department">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                        <?php $deptName = $d['department']; ?>
                        <option value="<?php echo htmlspecialchars($deptName); ?>" <?php if ($department === $deptName) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($deptName); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="button" type="submit">Filter</button>
            </form>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Staff ID</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Role</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($u['id']); ?></td>
                                <td><?php echo htmlspecialchars($u['staff_id']); ?></td>
                                <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($u['department']); ?></td>
                                <td><?php echo htmlspecialchars(role_display_name((string) $u['role'])); ?></td>
                                <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                                <td class="actions">
                                    <a class="button ghost small" href="/admin/edit.php?id=<?php echo (int) $u['id']; ?>">Edit</a>
                                    <?php if (!empty($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] === (int)$u['id']): ?>
                                        <span class="hint">Current</span>
                                    <?php else: ?>
                                        <form method="POST" action="/admin/delete.php" onsubmit="return confirm('Delete this account?');">
                                            <input type="hidden" name="id" value="<?php echo (int) $u['id']; ?>" />
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />
                                            <button class="button danger small" type="submit">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
