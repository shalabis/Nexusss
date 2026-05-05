<?php
require_once __DIR__ . '/config.php';

function sms_is_configured(): bool
{
    return SMS_WEBHOOK_URL !== '';
}

function sms_missing_configuration_message(): string
{
    return 'SMS is not configured yet. Set SMS_WEBHOOK_URL and, if needed, SMS_WEBHOOK_TOKEN in config.php or environment variables.';
}

function send_sms_message(string $toPhone, string $message): void
{
    if (!sms_is_configured()) {
        throw new RuntimeException(sms_missing_configuration_message());
    }

    $payload = json_encode([
        'to' => $toPhone,
        'message' => $message,
        'sender' => SMS_SENDER_ID,
    ]);

    if ($payload === false) {
        throw new RuntimeException('Unable to encode SMS payload.');
    }

    $headers = [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
    ];

    if (SMS_WEBHOOK_TOKEN !== '') {
        $headers[] = 'Authorization: Bearer ' . SMS_WEBHOOK_TOKEN;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents(SMS_WEBHOOK_URL, false, $context);
    $statusLine = $http_response_header[0] ?? '';

    if ($response === false) {
        throw new RuntimeException('Unable to reach the SMS gateway.');
    }

    if (!preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
        throw new RuntimeException('Unexpected SMS gateway response.');
    }

    $statusCode = (int) $matches[1];
    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('SMS gateway rejected the request with status ' . $statusCode . '.');
    }
}

function send_password_reset_otp_sms(string $toPhone, string $fullName, string $otp): void
{
    $message = 'Hello ' . $fullName . '. Your Nexus IT password reset OTP is ' . $otp . '. It expires in 10 minutes.';
    send_sms_message($toPhone, $message);
}
