<?php
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php');
    exit;
}

$staffId = trim($_POST['staff_id'] ?? '');
$password = $_POST['password'] ?? '';

if ($staffId === '' || $password === '') {
    $_SESSION['login_error'] = 'Please enter both staff ID and password.';
    header('Location: /index.php');
    exit;
}

$rateLimitError = rate_limit_enforce(
    'login',
    $staffId,
    5,
    300,
    'Too many login attempts. Please wait a few minutes and try again.'
);
if ($rateLimitError !== null) {
    $_SESSION['login_error'] = $rateLimitError;
    header('Location: /index.php');
    exit;
}

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT id, staff_id, full_name, department, password_hash, role, email, email_verified_at FROM users WHERE staff_id = :staff_id LIMIT 1');
$stmt->execute(['staff_id' => $staffId]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    $_SESSION['login_error'] = 'Invalid staff ID or password.';
    header('Location: /index.php');
    exit;
}

session_regenerate_id(true);

$_SESSION['user'] = [
    'id' => $user['id'],
    'staff_id' => $user['staff_id'],
    'full_name' => $user['full_name'],
    'department' => $user['department'],
    'role' => $user['role'],
    'email' => $user['email'],
    'email_verified_at' => $user['email_verified_at'],
];

if (user_requires_email_verification($_SESSION['user'])) {
    header('Location: /email_verification.php');
    exit;
}

header('Location: ' . current_user_home($user['role']));
exit;
