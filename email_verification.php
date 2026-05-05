<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail_helper.php';

$user = require_login(true);
$pdo = get_pdo();
$notice = '';
$error = '';

$stmt = $pdo->prepare(
    'SELECT id, full_name, role, email, email_verified_at, email_verification_expires_at
     FROM users
     WHERE id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $user['id']]);
$currentUser = $stmt->fetch();

if (!$currentUser) {
    $_SESSION = [];
    session_destroy();
    header('Location: /index.php');
    exit;
}

if (!user_requires_email_verification($currentUser)) {
    header('Location: ' . current_user_home($currentUser['role'] ?? 'user'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrf = $_POST['csrf'] ?? '';

    if (!csrf_check($csrf)) {
        $error = 'Invalid request.';
    } elseif ($action === 'send_otp') {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if ($email === '') {
            $error = 'Please enter your personal email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $duplicateCheck = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
            $duplicateCheck->execute([
                'email' => $email,
                'id' => $currentUser['id'],
            ]);

            if ($duplicateCheck->fetch()) {
                $error = 'That email address is already being used by another account.';
            } else {
                $rateLimitError = rate_limit_enforce(
                    'email_verification_otp',
                    (string) $currentUser['id'],
                    3,
                    600,
                    'Too many OTP requests. Please wait 10 minutes before trying again.'
                );
                if ($rateLimitError !== null) {
                    $error = $rateLimitError;
                } else {
                    try {
                        $otp = set_email_verification_code((int) $currentUser['id'], $email);
                        send_email_verification_otp($email, $currentUser['full_name'], $otp);
                        $notice = 'OTP sent. Please check your email and enter the 6-digit code below.';
                        $stmt->execute(['id' => $currentUser['id']]);
                        $currentUser = $stmt->fetch();
                    } catch (Throwable $exception) {
                        $error = $exception->getMessage();
                    }
                }
            }
        }
    } elseif ($action === 'verify_otp') {
        $otp = trim($_POST['otp'] ?? '');

        if ($otp === '') {
            $error = 'Please enter the OTP from your email.';
        } elseif (!email_verification_otp_valid((int) $currentUser['id'], $otp)) {
            $error = 'Invalid or expired OTP. Please request a new code.';
        } else {
            mark_email_verified((int) $currentUser['id']);
            header('Location: ' . current_user_home($currentUser['role'] ?? 'user'));
            exit;
        }
    } elseif ($action === 'change_email') {
        clear_email_verification_code((int) $currentUser['id']);
        $stmt->execute(['id' => $currentUser['id']]);
        $currentUser = $stmt->fetch();
        $notice = 'You can now enter a different email address.';
    }
}

$hasPendingEmail = !empty($currentUser['email']);
$csrf = csrf_token();
$otpExpiryText = '';
if (!empty($currentUser['email_verification_expires_at'])) {
    $expiryTimestamp = strtotime((string) $currentUser['email_verification_expires_at']);
    if ($expiryTimestamp !== false) {
        $otpExpiryText = date('F j, Y g:i A', $expiryTimestamp);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nexus IT | Email Verification</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide">
            <div class="header-row">
                <div>
                    <h1 class="brand">Nexus IT</h1>
                    <p class="subtitle">First login email verification</p>
                </div>
                <div class="header-actions">
                    <a class="button ghost" href="/logout.php">Log Out</a>
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
                    <p class="hint">Enter your personal email address. We will send a 6-digit OTP for verification.</p>

                    <form method="POST" class="form">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />
                        <input type="hidden" name="action" value="send_otp" />

                        <label class="label" for="email">Personal Email</label>
                        <input
                            class="input"
                            type="email"
                            id="email"
                            name="email"
                            required
                            value="<?php echo htmlspecialchars((string) ($currentUser['email'] ?? '')); ?>"
                        />

                        <button class="button" type="submit"><?php echo $hasPendingEmail ? 'Resend OTP' : 'Send OTP'; ?></button>
                    </form>
                </div>

                <div class="panel accent">
                    <h2 class="section-title">Step 2</h2>
                    <p class="hint">Enter the OTP sent to <?php echo htmlspecialchars((string) ($currentUser['email'] ?: 'your email')); ?>.</p>
                    <?php if ($otpExpiryText): ?>
                        <p class="hint">Current OTP expires on <?php echo htmlspecialchars($otpExpiryText); ?>.</p>
                    <?php endif; ?>

                    <form method="POST" class="form">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />
                        <input type="hidden" name="action" value="verify_otp" />

                        <label class="label" for="otp">Email OTP</label>
                        <input class="input" type="text" id="otp" name="otp" inputmode="numeric" maxlength="6" required />

                        <button class="button" type="submit">Verify Email</button>
                    </form>

                    <?php if ($hasPendingEmail): ?>
                        <form method="POST" class="form">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />
                            <input type="hidden" name="action" value="change_email" />
                            <button class="button ghost small" type="submit">Use Different Email</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
