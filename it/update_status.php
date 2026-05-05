<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../mail_helper.php';
require_login();

if (($_SESSION['user']['role'] ?? '') !== 'it') {
    header('Location: /index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /it/index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';
$csrf = $_POST['csrf'] ?? '';

if (!csrf_check($csrf)) {
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

$nextStatus = null;
$requiredCurrent = null;

if ($action === 'accept') {
    $nextStatus = 'in_progress';
    $requiredCurrent = 'pending';
} elseif ($action === 'reject') {
    $nextStatus = 'rejected';
    $requiredCurrent = 'pending';
} elseif ($action === 'done') {
    $nextStatus = 'done';
    $requiredCurrent = 'in_progress';
}

if ($nextStatus === null) {
    http_response_code(400);
    echo 'Invalid action.';
    exit;
}

$pdo = get_pdo();
$complaintStmt = $pdo->prepare(
    'SELECT c.id, c.user_id, c.complaint_code, c.status,
            u.full_name, u.staff_id, u.email, u.email_verified_at
     FROM complaints c
     JOIN users u ON u.id = c.user_id
     WHERE c.id = :id
     LIMIT 1'
);
$complaintStmt->execute(['id' => $id]);
$complaint = $complaintStmt->fetch();

if ($complaint) {
    $stmt = $pdo->prepare('UPDATE complaints SET status = :status WHERE id = :id AND status = :current');
    $stmt->execute([
        'status' => $nextStatus,
        'id' => $id,
        'current' => $requiredCurrent,
    ]);

    if ($stmt->rowCount() > 0) {
        $complaintCode = trim((string) ($complaint['complaint_code'] ?? ''));
        if ($complaintCode === '') {
            $complaintCode = 'Complaint #' . $id;
        }

        create_notification(
            (int) $complaint['user_id'],
            'Complaint status updated',
            $complaintCode . ' is now ' . complaint_status_display($nextStatus) . '.',
            $id
        );

        $userEmail = trim((string) ($complaint['email'] ?? ''));
        $userFullName = trim((string) ($complaint['full_name'] ?? $complaint['staff_id'] ?? 'User'));
        if ($userEmail !== '' && !empty($complaint['email_verified_at'])) {
            try {
                send_user_complaint_status_update(
                    $userEmail,
                    $userFullName,
                    $complaintCode,
                    (string) $complaint['status'],
                    $nextStatus
                );
            } catch (Throwable $exception) {
                // Keep status updates working even if the email fails.
            }
        }
    }
}

$redirect = $_SERVER['HTTP_REFERER'] ?? '/it/complaints.php';
header('Location: ' . $redirect);
exit;
