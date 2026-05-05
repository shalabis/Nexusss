<?php
$documentRoot = '/var/www/html';
$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

$isProtectedPath = static function (string $path): bool {
    if (preg_match('#(^|/)\.#', $path)) {
        return true;
    }

    foreach (['/data', '/deploy', '/uploads'] as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            return true;
        }
    }

    return false;
};

if ($isProtectedPath($requestPath)) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}

$fullPath = realpath($documentRoot . $requestPath);
if ($fullPath !== false && is_file($fullPath) && str_starts_with($fullPath, $documentRoot . DIRECTORY_SEPARATOR)) {
    return false;
}

if ($requestPath === '/' || $requestPath === '') {
    require $documentRoot . '/index.php';
    return true;
}

http_response_code(404);
echo 'Not Found';
return true;
