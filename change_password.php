<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail_helper.php';

require_login();

$userSession = $_SESSION['user'];
$pdo = get_pdo();

$stmt = $pdo->prepare('SELECT id, password_hash, role, full_name, email, email_verified_at FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userSession['id']]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo 'Account not found.';
    exit;
}

if (empty($user['email']) || empty($user['email_verified_at'])) {
    $_SESSION['login_error'] = 'Please verify your email before changing your password.';
    header('Location: /email_verification.php');
    exit;
}

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!csrf_check($csrf)) {
        $error = 'Invalid request.';
    } elseif ($action === 'send_otp') {
        $rateLimitError = rate_limit_enforce(
            'password_change_otp',
            (string) $user['id'],
            3,
            600,
            'Too many OTP requests. Please wait 10 minutes before trying again.'
        );
        if ($rateLimitError !== null) {
            $error = $rateLimitError;
        } else {
            try {
                $otp = set_password_reset_code((int) $user['id']);
                send_password_change_otp((string) $user['email'], (string) $user['full_name'], $otp);
                $notice = 'OTP sent to your verified personal email.';
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'update_password') {
        $otp = trim($_POST['otp'] ?? '');
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($otp === '' || $new === '' || $confirm === '') {
            $error = 'Please fill in all fields.';
        } elseif (!password_reset_otp_valid((int) $user['id'], $otp)) {
            $error = 'Invalid or expired OTP. Please request a new code.';
        } elseif ($new !== $confirm) {
            $error = 'New password and confirmation do not match.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $update->execute(['hash' => $hash, 'id' => $user['id']]);
            clear_password_reset_code((int) $user['id']);
            $notice = 'Password updated successfully.';
        }
    }
}

$role = $user['role'] ?? 'user';
$dashboardLink = '/user/index.php';
$dashboardLabel = 'Employee Dashboard';
if ($role === 'admin') {
    $dashboardLink = '/admin/index.php';
    $dashboardLabel = 'Admin Dashboard';
} elseif ($role === 'it') {
    $dashboardLink = '/it/index.php';
    $dashboardLabel = 'IT Support Dashboard';
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nexus IT | Change Password</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide dashboard">
            <div class="header-row">
                <div>
                    <p class="eyebrow">Nexus IT</p>
                    <h1 class="brand">Change Password</h1>
                    <p class="subtitle">Update your account password securely.</p>
                </div>
                <div class="header-actions">
                    <a class="button ghost" href="/account.php">Back to Account</a>
                    <a class="button ghost" href="<?php echo htmlspecialchars($dashboardLink); ?>">Back to <?php echo htmlspecialchars($dashboardLabel); ?></a>
                </div>
            </div>

            <?php if ($notice): ?>
                <div class="alert success" role="status"><?php echo htmlspecialchars($notice); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="form">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />
                <input type="hidden" name="action" value="send_otp" />

                <label class="label" for="email_display">Verified Personal Email</label>
                <input class="input" type="email" id="email_display" value="<?php echo htmlspecialchars((string) $user['email']); ?>" readonly />

                <button class="button ghost" type="submit">Send OTP to Email</button>
            </form>

            <form method="POST" class="form">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />
                <input type="hidden" name="action" value="update_password" />

                <label class="label" for="otp">Email OTP</label>
                <input class="input" type="text" id="otp" name="otp" inputmode="numeric" maxlength="6" required />

                <label class="label" for="new_password">New Password</label>
                <input class="input" type="password" id="new_password" name="new_password" required />

                <label class="label" for="confirm_password">Confirm New Password</label>
                <input class="input" type="password" id="confirm_password" name="confirm_password" required />

                <button class="button" type="submit">Update Password</button>
            </form>
        </section>
    </main>
</body>
</html>
