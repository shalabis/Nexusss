<?php
require_once __DIR__ . '/bootstrap.php';

if (!empty($_SESSION['user'])) {
    $user = refresh_session_user();
    if ($user) {
        if (user_requires_email_verification($user)) {
            header('Location: /email_verification.php');
            exit;
        }
        header('Location: ' . current_user_home($user['role'] ?? 'user'));
        exit;
    }
}

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nexus IT | Login</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card">
            <h1 class="brand">Nexus IT</h1>
            <p class="subtitle">Secure access for your team</p>

            <?php if ($error): ?>
                <div class="alert" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="/login.php" class="form">
                <label class="label" for="staff_id">Staff ID</label>
                <input class="input" type="text" id="staff_id" name="staff_id" required autocomplete="username" />

                <label class="label" for="password">Password</label>
                <input class="input" type="password" id="password" name="password" required autocomplete="current-password" />

                <button class="button" type="submit">Log In</button>
            </form>
            <a class="button ghost small" href="/reset_password.php">Forgot / Reset Password</a>
            <p class="hint">No public sign-ups. Admin creates accounts.</p>
        </section>
    </main>
</body>
</html>
