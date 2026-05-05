<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$databaseReachable = false;

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 3,
    ]);
    $pdo->query('SELECT 1');
    $databaseReachable = true;
} catch (Throwable $e) {
    $databaseReachable = false;
}

// Railway should treat the service as healthy once PHP/Apache are responding.
http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'database' => $databaseReachable ? 'reachable' : 'unreachable',
]);
