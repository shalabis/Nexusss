<?php
function nexus_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false && array_key_exists($key, $_ENV)) {
        $value = $_ENV[$key];
    }
    if ($value === false && array_key_exists($key, $_SERVER)) {
        $value = $_SERVER[$key];
    }
    if ($value === false) {
        return trim($default);
    }

    return trim($value);
}

function nexus_env_bool(string $key, bool $default = false): bool
{
    $value = getenv($key);
    if ($value === false && array_key_exists($key, $_ENV)) {
        $value = $_ENV[$key];
    }
    if ($value === false && array_key_exists($key, $_SERVER)) {
        $value = $_SERVER[$key];
    }
    if ($value === false) {
        return $default;
    }

    $normalized = strtolower(trim((string) $value));

    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function nexus_env_int(string $key, int $default): int
{
    $value = getenv($key);
    if ($value === false && array_key_exists($key, $_ENV)) {
        $value = $_ENV[$key];
    }
    if ($value === false && array_key_exists($key, $_SERVER)) {
        $value = $_SERVER[$key];
    }
    if ($value === false) {
        return $default;
    }

    $normalized = filter_var(trim((string) $value), FILTER_VALIDATE_INT);
    if ($normalized === false || $normalized < 0) {
        return $default;
    }

    return (int) $normalized;
}

// Local development defaults.
// For hosting, set secrets with environment variables instead of editing this file.
$localConfig = [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'nexus_it',
    'DB_USER' => 'nexus_user',
    'DB_PASS' => 'nexus_pass_123',
    'ADMIN_PASSWORD_PLAIN' => '',
    'ADMIN_STAFF_ID' => '',
    'ADMIN_FULL_NAME' => '',
    'ADMIN_DEPARTMENT' => 'Administration',
    'ADMIN_RESET_SECRET' => '',
    'SMS_WEBHOOK_URL' => '',
    'SMS_WEBHOOK_TOKEN' => '',
    'SMS_SENDER_ID' => 'Nexus IT',
    'SMTP_HOST' => '',
    'SMTP_PORT' => '587',
    'SMTP_USERNAME' => '',
    'SMTP_PASSWORD' => '',
    'SMTP_ENCRYPTION' => 'tls',
    'MAIL_FROM_EMAIL' => '',
    'MAIL_FROM_NAME' => 'Nexus IT',
    'AUTO_SCHEMA_MIGRATE' => '1',
    'ENABLE_ADMIN_BOOTSTRAP' => '0',
    'SESSION_SECURE_COOKIE' => '0',
    'SESSION_LIFETIME_SECONDS' => '604800',
];

define('DB_HOST', nexus_env('DB_HOST', $localConfig['DB_HOST']));
define('DB_NAME', nexus_env('DB_NAME', $localConfig['DB_NAME']));
define('DB_USER', nexus_env('DB_USER', $localConfig['DB_USER']));
define('DB_PASS', nexus_env('DB_PASS', $localConfig['DB_PASS']));

// Admin bootstrap credentials (auto-created if missing)
define('ADMIN_PASSWORD_PLAIN', nexus_env('ADMIN_PASSWORD_PLAIN', $localConfig['ADMIN_PASSWORD_PLAIN']));
define('ADMIN_STAFF_ID', nexus_env('ADMIN_STAFF_ID', $localConfig['ADMIN_STAFF_ID']));
define('ADMIN_FULL_NAME', nexus_env('ADMIN_FULL_NAME', $localConfig['ADMIN_FULL_NAME']));
define('ADMIN_DEPARTMENT', nexus_env('ADMIN_DEPARTMENT', $localConfig['ADMIN_DEPARTMENT']));

// Admin-only hourly reset code secret (change this to a long random string).
define('ADMIN_RESET_SECRET', nexus_env('ADMIN_RESET_SECRET', $localConfig['ADMIN_RESET_SECRET']));

// SMS webhook settings for password reset OTP delivery.
// The webhook should accept a JSON POST body with: to, message, and sender.
define('SMS_WEBHOOK_URL', nexus_env('SMS_WEBHOOK_URL', $localConfig['SMS_WEBHOOK_URL']));
define('SMS_WEBHOOK_TOKEN', nexus_env('SMS_WEBHOOK_TOKEN', $localConfig['SMS_WEBHOOK_TOKEN']));
define('SMS_SENDER_ID', nexus_env('SMS_SENDER_ID', $localConfig['SMS_SENDER_ID']));

// SMTP settings for email OTP verification.
define('SMTP_HOST', nexus_env('SMTP_HOST', $localConfig['SMTP_HOST']));
define('SMTP_PORT', nexus_env('SMTP_PORT', $localConfig['SMTP_PORT']));
define('SMTP_USERNAME', nexus_env('SMTP_USERNAME', $localConfig['SMTP_USERNAME']));
define('SMTP_PASSWORD', nexus_env('SMTP_PASSWORD', $localConfig['SMTP_PASSWORD']));
define('SMTP_ENCRYPTION', strtolower(nexus_env('SMTP_ENCRYPTION', $localConfig['SMTP_ENCRYPTION'])));
define('MAIL_FROM_EMAIL', nexus_env('MAIL_FROM_EMAIL', $localConfig['MAIL_FROM_EMAIL']));
define('MAIL_FROM_NAME', nexus_env('MAIL_FROM_NAME', $localConfig['MAIL_FROM_NAME']));

// Runtime behavior flags.
define('AUTO_SCHEMA_MIGRATE', nexus_env_bool('AUTO_SCHEMA_MIGRATE', $localConfig['AUTO_SCHEMA_MIGRATE'] === '1'));
define('ENABLE_ADMIN_BOOTSTRAP', nexus_env_bool('ENABLE_ADMIN_BOOTSTRAP', $localConfig['ENABLE_ADMIN_BOOTSTRAP'] === '1'));
define('SESSION_SECURE_COOKIE', nexus_env_bool('SESSION_SECURE_COOKIE', $localConfig['SESSION_SECURE_COOKIE'] === '1'));
define('SESSION_LIFETIME_SECONDS', nexus_env_int('SESSION_LIFETIME_SECONDS', (int) $localConfig['SESSION_LIFETIME_SECONDS']));
