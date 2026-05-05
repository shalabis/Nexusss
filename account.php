<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail_helper.php';

$userSession = require_login(true);
$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT id, staff_id, full_name, department, role, email, email_verified_at, created_at FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userSession['id']]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo 'Account not found.';
    exit;
}

$message = '';
$error = '';
$employeeDepartments = ['A1', 'A2', 'A3', 'CME', 'CMS', 'PNC', 'PPM'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staffId = trim((string) ($_POST['staff_id'] ?? ''));
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $department = trim((string) ($_POST['department'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $csrf = $_POST['csrf'] ?? '';

    if (!csrf_check($csrf)) {
        $error = 'Invalid request.';
    } elseif ($staffId === '' || $fullName === '') {
        $error = 'Staff ID and full name are required.';
    } elseif ($user['role'] === 'user' && !in_array($department, $employeeDepartments, true)) {
        $error = 'Please choose a valid department.';
    } elseif ($email === '') {
        $error = 'Personal email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        if ($user['role'] === 'admin') {
            $department = 'Administration';
        } elseif ($user['role'] === 'it') {
            $department = 'IT Support';
        }

        $staffDup = $pdo->prepare('SELECT id FROM users WHERE staff_id = :staff_id AND id <> :id LIMIT 1');
        $staffDup->execute([
            'staff_id' => $staffId,
            'id' => $user['id'],
        ]);

        $emailDup = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $emailDup->execute([
            'email' => $email,
            'id' => $user['id'],
        ]);

        if ($staffDup->fetch()) {
            $error = 'That staff ID is already being used by another account.';
        } elseif ($emailDup->fetch()) {
            $error = 'That email address is already being used by another account.';
        } else {
            $update = $pdo->prepare(
                'UPDATE users
                 SET staff_id = :staff_id,
                     full_name = :full_name,
                     department = :department,
                     username = :username
                 WHERE id = :id'
            );
            $update->execute([
                'staff_id' => $staffId,
                'full_name' => $fullName,
                'department' => $department,
                'username' => $staffId,
                'id' => $user['id'],
            ]);

            $emailChanged = !hash_equals(strtolower((string) ($user['email'] ?? '')), $email);
            if ($emailChanged) {
                try {
                    $otp = set_email_verification_code((int) $user['id'], $email);
                    send_email_verification_otp($email, $fullName, $otp);
                    header('Location: /email_verification.php');
                    exit;
                } catch (Throwable $exception) {
                    $error = $exception->getMessage();
                }
            } else {
                refresh_session_user();
                $message = 'Account updated successfully.';
            }

            $stmt->execute(['id' => $user['id']]);
            $user = $stmt->fetch();
        }
    }
}

$role = $user['role'] ?? 'user';
$dashboardLink = current_user_home($role);
$dashboardLabel = $role === 'admin' ? 'Admin Dashboard' : ($role === 'it' ? 'IT Support Dashboard' : 'Employee Dashboard');
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nexus IT | Account Details</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide dashboard">
            <div class="header-row">
                <div>
                    <p class="eyebrow">Nexus IT</p>
                    <h1 class="brand">Account Details</h1>
                    <p class="subtitle">Your profile and access information.</p>
                </div>
                <div class="header-actions">
                    <a class="button ghost" href="<?php echo htmlspecialchars($dashboardLink); ?>">Back to <?php echo htmlspecialchars($dashboardLabel); ?></a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert success" role="status"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="panel-grid">
                <div class="panel">
                    <h2 class="section-title">Edit Profile</h2>
                    <form method="POST" class="form">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />

                        <label class="label" for="full_name">Full Name</label>
                        <input class="input" type="text" id="full_name" name="full_name" required value="<?php echo htmlspecialchars((string) $user['full_name']); ?>" />

                        <label class="label" for="staff_id">Staff ID</label>
                        <input class="input" type="text" id="staff_id" name="staff_id" required value="<?php echo htmlspecialchars((string) $user['staff_id']); ?>" />

                        <label class="label" for="department">Department</label>
                        <?php if ($user['role'] === 'user'): ?>
                            <select class="input" id="department" name="department" required>
                                <?php foreach ($employeeDepartments as $departmentOption): ?>
                                    <option value="<?php echo htmlspecialchars($departmentOption); ?>" <?php echo $user['department'] === $departmentOption ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($departmentOption); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input class="input" type="text" id="department" name="department" value="<?php echo htmlspecialchars((string) $user['department']); ?>" readonly />
                        <?php endif; ?>

                        <label class="label" for="email">Personal Email</label>
                        <input class="input" type="email" id="email" name="email" required value="<?php echo htmlspecialchars((string) ($user['email'] ?? '')); ?>" />

                        <?php if (!empty($user['email_verified_at'])): ?>
                            <p class="hint">If you change your email, we will send a new OTP and ask you to verify it again.</p>
                        <?php endif; ?>

                        <div class="panel-actions">
                            <button class="button" type="submit">Save Changes</button>
                        </div>
                    </form>
                </div>
                <div class="panel accent">
                    <h2 class="section-title">Access</h2>
                    <ul class="rules-list">
                        <li><strong>Role:</strong> <?php echo htmlspecialchars(role_display_name((string) $user['role'])); ?></li>
                        <li><strong>Email Verified:</strong> <?php echo !empty($user['email_verified_at']) ? 'Yes' : 'No'; ?></li>
                        <li><strong>Joined:</strong> <?php echo htmlspecialchars(date('F j, Y', strtotime($user['created_at']))); ?></li>
                    </ul>
                    <div class="panel-actions">
                        <a class="button" href="/change_password.php">Change Password</a>
                    </div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
