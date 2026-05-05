<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail_helper.php';

if (!empty($_SESSION['user'])) {
    $user = refresh_session_user();
    if ($user) {
        header('Location: ' . current_user_home($user['role'] ?? 'user'));
        exit;
    }
}

$pdo = get_pdo();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrf = $_POST['csrf'] ?? '';

    if (!csrf_check($csrf)) {
        $error = 'Invalid request.';
    } elseif ($action === 'send_otp') {
        $staffId = trim($_POST['staff_id'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));

        if ($staffId === '' || $email === '') {
            $error = 'Please enter your staff ID and verified personal email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, full_name, email, email_verified_at
                 FROM users
                 WHERE staff_id = :staff_id
                 LIMIT 1'
            );
            $stmt->execute(['staff_id' => $staffId]);
            $user = $stmt->fetch();

            if (
                !$user
                || empty($user['email_verified_at'])
                || !hash_equals(strtolower((string) $user['email']), $email)
            ) {
                $error = 'The verified email does not match this account.';
            } else {
                $rateLimitError = rate_limit_enforce(
                    'password_reset_otp',
                    $staffId . '|' . $email,
                    3,
                    600,
                    'Too many OTP requests. Please wait 10 minutes before trying again.'
                );
                if ($rateLimitError !== null) {
                    $error = $rateLimitError;
                } else {
                    try {
                        $otp = set_password_reset_code((int) $user['id']);
                        send_password_reset_otp((string) $user['email'], (string) $user['full_name'], $otp);
                        $notice = 'OTP sent to your verified personal email.';
                    } catch (Throwable $exception) {
                        $error = $exception->getMessage();
                    }
                }
            }
        }
    } elseif ($action === 'reset_password') {
        $staffId = trim($_POST['staff_id'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $otp = trim($_POST['otp'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($staffId === '' || $email === '' || $otp === '' || $newPassword === '' || $confirmPassword === '') {
            $error = 'Please fill in all fields.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New password and confirmation do not match.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, email, email_verified_at
                 FROM users
                 WHERE staff_id = :staff_id
                 LIMIT 1'
            );
            $stmt->execute(['staff_id' => $staffId]);
            $user = $stmt->fetch();

            if (
                !$user
                || empty($user['email_verified_at'])
                || !hash_equals(strtolower((string) $user['email']), $email)
            ) {
                $error = 'The verified email does not match this account.';
            } elseif (!password_reset_otp_valid((int) $user['id'], $otp)) {
                $error = 'Invalid or expired OTP. Please request a new code.';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                $update->execute([
                    'hash' => $hash,
                    'id' => $user['id'],
                ]);
                clear_password_reset_code((int) $user['id']);
                $notice = 'Password reset successfully. You can now log in.';
            }
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
    <title>Nexus IT | Reset Password</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide dashboard">
            <div class="header-row">
                <div>
                    <p class="eyebrow">Nexus IT</p>
                    <h1 class="brand">Reset Password</h1>
                    <p class="subtitle">Reset your password using your verified personal email.</p>
                </div>
                <div class="header-actions">
                    <a class="button ghost" href="/index.php">Back to Login</a>
                </div>
            </div>

            <?php if ($notice): ?>
                <div class="alert success" role="status"><?php echo htmlspecialchars($notice); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="panel-grid">
                <div class="panel">
                    <h2 class="section-title">Step 1</h2>
                    <p class="hint">Enter your staff ID and the personal email you already verified on your account.</p>

                    <form method="POST" class="form">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />
                        <input type="hidden" name="action" value="send_otp" />

                        <label class="label" for="staff_id">Staff ID</label>
                        <input class="input" type="text" id="staff_id" name="staff_id" required value="<?php echo htmlspecialchars($_POST['staff_id'] ?? ''); ?>" />

                        <label class="label" for="email">Verified Personal Email</label>
                        <input class="input" type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" />

                        <button class="button ghost" type="submit">Send OTP to Email</button>
                    </form>
                </div>

                <div class="panel accent">
                    <h2 class="section-title">Step 2</h2>
                    <p class="hint">After you receive the OTP, enter it below with your new password.</p>

                    <form method="POST" class="form">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />
                        <input type="hidden" name="action" value="reset_password" />

                        <label class="label" for="reset_staff_id">Staff ID</label>
                        <input class="input" type="text" id="reset_staff_id" name="staff_id" required value="<?php echo htmlspecialchars($_POST['staff_id'] ?? ''); ?>" />

                        <label class="label" for="reset_email">Verified Personal Email</label>
                        <input class="input" type="email" id="reset_email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" />

                        <label class="label" for="otp">Email OTP</label>
                        <input class="input" type="text" id="otp" name="otp" inputmode="numeric" maxlength="6" required />

                        <label class="label" for="new_password">New Password</label>
                        <input class="input" type="password" id="new_password" name="new_password" required />

                        <label class="label" for="confirm_password">Confirm New Password</label>
                        <input class="input" type="password" id="confirm_password" name="confirm_password" required />

                        <button class="button" type="submit">Reset Password</button>
                    </form>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
