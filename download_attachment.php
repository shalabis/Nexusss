<?php
require_once __DIR__ . '/bootstrap.php';

$user = require_login();
$complaintId = (int) ($_GET['id'] ?? 0);

if ($complaintId <= 0) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$pdo = get_pdo();
$stmt = $pdo->prepare(
    'SELECT id, user_id, file_path, file_name
     FROM complaints
     WHERE id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $complaintId]);
$complaint = $stmt->fetch();

if (!$complaint || empty($complaint['file_path'])) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$role = (string) ($user['role'] ?? '');
$userId = (int) ($user['id'] ?? 0);
$ownerId = (int) $complaint['user_id'];

if ($role === 'user' && $userId !== $ownerId) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$storedPath = (string) $complaint['file_path'];
$fileBasename = basename($storedPath);
$uploadDir = realpath(__DIR__ . '/uploads');

if ($uploadDir === false) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$absolutePath = realpath($uploadDir . DIRECTORY_SEPARATOR . $fileBasename);
if ($absolutePath === false || strpos($absolutePath, $uploadDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($absolutePath)) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$downloadName = trim((string) ($complaint['file_name'] ?? 'attachment'));
if ($downloadName === '') {
    $downloadName = 'attachment';
}
$safeDownloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
if ($safeDownloadName === '' || $safeDownloadName === null) {
    $safeDownloadName = 'attachment';
}

$mimeType = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detectedType = finfo_file($finfo, $absolutePath);
        if (is_string($detectedType) && $detectedType !== '') {
            $mimeType = $detectedType;
        }
        finfo_close($finfo);
    }
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($absolutePath));
header('Content-Disposition: inline; filename="' . $safeDownloadName . '"');
header('X-Content-Type-Options: nosniff');
readfile($absolutePath);
exit;
