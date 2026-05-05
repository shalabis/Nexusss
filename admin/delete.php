<?php
require_once __DIR__ . '/../bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$csrf = $_POST['csrf'] ?? '';

if (!csrf_check($csrf)) {
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

$pdo = get_pdo();

// Prevent deleting the currently logged-in admin.
if (!empty($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] === $id) {
    header('Location: /admin/index.php');
    exit;
}

$stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);

header('Location: /admin/index.php');
exit;
