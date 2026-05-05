<?php
require_once __DIR__ . '/config.php';

function smtp_is_configured(): bool
{
    return SMTP_HOST !== '';
}

function smtp_missing_configuration_message(): string
{
    return 'Email is not configured yet. Set SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD, MAIL_FROM_EMAIL, and MAIL_FROM_NAME in config.php or environment variables.';
}

function smtp_read_response($socket): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }

    return $response;
}

function smtp_expect_ok(string $response, array $codes): void
{
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTP error: ' . trim($response));
    }
}

function smtp_send_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    $response = smtp_read_response($socket);
    smtp_expect_ok($response, $expectedCodes);

    return $response;
}

function send_mail_message(string $toEmail, string $subject, string $htmlBody, string $textBody): void
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid email address.');
    }

    if (smtp_is_configured()) {
        send_smtp_message($toEmail, $subject, $htmlBody, $textBody);
        return;
    }

    throw new RuntimeException(smtp_missing_configuration_message());
}

function send_smtp_message(string $toEmail, string $subject, string $htmlBody, string $textBody): void
{
    $transport = SMTP_ENCRYPTION === 'ssl' ? 'ssl://' : '';
    $socket = stream_socket_client(
        $transport . SMTP_HOST . ':' . SMTP_PORT,
        $errorCode,
        $errorMessage,
        15,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        throw new RuntimeException('SMTP connection failed: ' . $errorMessage);
    }

    stream_set_timeout($socket, 15);

    try {
        smtp_expect_ok(smtp_read_response($socket), [220]);
        smtp_send_command($socket, 'EHLO localhost', [250]);

        if (SMTP_ENCRYPTION === 'tls') {
            smtp_send_command($socket, 'STARTTLS', [220]);
            $cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                throw new RuntimeException('Unable to enable TLS for SMTP connection.');
            }
            smtp_send_command($socket, 'EHLO localhost', [250]);
        }

        if (SMTP_USERNAME !== '' || SMTP_PASSWORD !== '') {
            smtp_send_command($socket, 'AUTH LOGIN', [334]);
            smtp_send_command($socket, base64_encode(SMTP_USERNAME), [334]);
            smtp_send_command($socket, base64_encode(SMTP_PASSWORD), [235]);
        }
        smtp_send_command($socket, 'MAIL FROM:<' . MAIL_FROM_EMAIL . '>', [250]);
        smtp_send_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        smtp_send_command($socket, 'DATA', [354]);

        $boundary = 'nexus_' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_EMAIL . '>',
            'To: <' . $toEmail . '>',
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n";
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $message .= $textBody . "\r\n\r\n";
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $message .= $htmlBody . "\r\n\r\n";
        $message .= '--' . $boundary . "--\r\n.";

        fwrite($socket, $message . "\r\n");
        smtp_expect_ok(smtp_read_response($socket), [250]);
        smtp_send_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function send_email_verification_otp(string $toEmail, string $fullName, string $otp): void
{
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $subject = 'Your Nexus IT verification code';
    $textBody = "Hello {$fullName},\n\nYour Nexus IT verification code is: {$otp}\n\nThis code expires in 10 minutes.\n";
    $htmlBody = '<p>Hello ' . $safeName . ',</p>'
        . '<p>Your Nexus IT verification code is:</p>'
        . '<p style="font-size:24px;font-weight:bold;letter-spacing:4px;">' . $safeOtp . '</p>'
        . '<p>This code expires in 10 minutes.</p>';

    send_mail_message($toEmail, $subject, $htmlBody, $textBody);
}

function send_password_change_otp(string $toEmail, string $fullName, string $otp): void
{
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $subject = 'Your Nexus IT password change code';
    $textBody = "Hello {$fullName},\n\nYour Nexus IT password change OTP is: {$otp}\n\nThis code expires in 10 minutes.\n";
    $htmlBody = '<p>Hello ' . $safeName . ',</p>'
        . '<p>Your Nexus IT password change OTP is:</p>'
        . '<p style="font-size:24px;font-weight:bold;letter-spacing:4px;">' . $safeOtp . '</p>'
        . '<p>This code expires in 10 minutes.</p>';

    send_mail_message($toEmail, $subject, $htmlBody, $textBody);
}

function send_password_reset_otp(string $toEmail, string $fullName, string $otp): void
{
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $subject = 'Your Nexus IT password reset code';
    $textBody = "Hello {$fullName},\n\nYour Nexus IT password reset OTP is: {$otp}\n\nThis code expires in 10 minutes.\n";
    $htmlBody = '<p>Hello ' . $safeName . ',</p>'
        . '<p>Your Nexus IT password reset OTP is:</p>'
        . '<p style="font-size:24px;font-weight:bold;letter-spacing:4px;">' . $safeOtp . '</p>'
        . '<p>This code expires in 10 minutes.</p>';

    send_mail_message($toEmail, $subject, $htmlBody, $textBody);
}

function send_it_complaint_notification(
    string $toEmail,
    string $itName,
    string $complaintCode,
    string $submittedBy,
    string $submittedDepartment,
    string $category,
    string $details
): void {
    $safeItName = htmlspecialchars($itName, ENT_QUOTES, 'UTF-8');
    $safeComplaintCode = htmlspecialchars($complaintCode, ENT_QUOTES, 'UTF-8');
    $safeSubmittedBy = htmlspecialchars($submittedBy, ENT_QUOTES, 'UTF-8');
    $safeSubmittedDepartment = htmlspecialchars($submittedDepartment, ENT_QUOTES, 'UTF-8');
    $safeCategory = htmlspecialchars($category, ENT_QUOTES, 'UTF-8');
    $safeDetails = nl2br(htmlspecialchars($details, ENT_QUOTES, 'UTF-8'));

    $subject = 'New complaint submitted: ' . $complaintCode;
    $textBody = "Hello {$itName},\n\n"
        . "A new complaint has been submitted.\n\n"
        . "Complaint Ref: {$complaintCode}\n"
        . "Submitted By: {$submittedBy}\n"
        . "Department: {$submittedDepartment}\n"
        . "Category: {$category}\n"
        . "Details: {$details}\n";
    $htmlBody = '<p>Hello ' . $safeItName . ',</p>'
        . '<p>A new complaint has been submitted.</p>'
        . '<p><strong>Complaint Ref:</strong> ' . $safeComplaintCode . '<br />'
        . '<strong>Submitted By:</strong> ' . $safeSubmittedBy . '<br />'
        . '<strong>Department:</strong> ' . $safeSubmittedDepartment . '<br />'
        . '<strong>Category:</strong> ' . $safeCategory . '</p>'
        . '<p><strong>Details:</strong><br />' . $safeDetails . '</p>';

    send_mail_message($toEmail, $subject, $htmlBody, $textBody);
}

function send_user_complaint_confirmation(
    string $toEmail,
    string $fullName,
    string $complaintCode,
    string $category,
    string $details
): void {
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeComplaintCode = htmlspecialchars($complaintCode, ENT_QUOTES, 'UTF-8');
    $safeCategory = htmlspecialchars($category, ENT_QUOTES, 'UTF-8');
    $safeDetails = nl2br(htmlspecialchars($details, ENT_QUOTES, 'UTF-8'));

    $subject = 'Complaint received: ' . $complaintCode;
    $textBody = "Hello {$fullName},\n\n"
        . "Your complaint has been submitted successfully.\n\n"
        . "Complaint Ref: {$complaintCode}\n"
        . "Current Status: Pending\n"
        . "Category: {$category}\n"
        . "Details: {$details}\n\n"
        . "The IT department will review your complaint soon.\n";
    $htmlBody = '<p>Hello ' . $safeName . ',</p>'
        . '<p>Your complaint has been submitted successfully.</p>'
        . '<p><strong>Complaint Ref:</strong> ' . $safeComplaintCode . '<br />'
        . '<strong>Current Status:</strong> Pending<br />'
        . '<strong>Category:</strong> ' . $safeCategory . '</p>'
        . '<p><strong>Details:</strong><br />' . $safeDetails . '</p>'
        . '<p>The IT department will review your complaint soon.</p>';

    send_mail_message($toEmail, $subject, $htmlBody, $textBody);
}

function send_user_complaint_status_update(
    string $toEmail,
    string $fullName,
    string $complaintCode,
    string $previousStatus,
    string $nextStatus
): void {
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeComplaintCode = htmlspecialchars($complaintCode, ENT_QUOTES, 'UTF-8');
    $safePreviousStatus = htmlspecialchars(complaint_status_display($previousStatus), ENT_QUOTES, 'UTF-8');
    $safeNextStatus = htmlspecialchars(complaint_status_display($nextStatus), ENT_QUOTES, 'UTF-8');

    $subject = 'Complaint status updated: ' . $complaintCode;
    $textBody = "Hello {$fullName},\n\n"
        . "Your complaint status has been updated.\n\n"
        . "Complaint Ref: {$complaintCode}\n"
        . "Previous Status: " . complaint_status_display($previousStatus) . "\n"
        . "New Status: " . complaint_status_display($nextStatus) . "\n";
    $htmlBody = '<p>Hello ' . $safeName . ',</p>'
        . '<p>Your complaint status has been updated.</p>'
        . '<p><strong>Complaint Ref:</strong> ' . $safeComplaintCode . '<br />'
        . '<strong>Previous Status:</strong> ' . $safePreviousStatus . '<br />'
        . '<strong>New Status:</strong> ' . $safeNextStatus . '</p>';

    send_mail_message($toEmail, $subject, $htmlBody, $textBody);
}
