<?php
require_once __DIR__ . '/bootstrap.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . current_user_home((string) ($user['role'] ?? 'user')));
    exit;
}

$csrf = $_POST['csrf'] ?? '';
if (!csrf_check($csrf)) {
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

mark_all_notifications_read((int) $user['id']);

$redirect = $_SERVER['HTTP_REFERER'] ?? current_user_home((string) ($user['role'] ?? 'user'));
header('Location: ' . $redirect);
exit;
