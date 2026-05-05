<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

if (($_SESSION['user']['role'] ?? '') !== 'user') {
    header('Location: /index.php');
    exit;
}

$user = $_SESSION['user'];
$faqFile = __DIR__ . '/../data/faq.json';
$defaultFaq = [
    ['question' => 'What should I include in my complaint?', 'answer' => 'Describe the issue clearly, include error messages, and steps to reproduce.'],
    ['question' => 'Can I attach files?', 'answer' => 'Yes, screenshots or documents help speed up resolution.'],
    ['question' => 'How long does it take?', 'answer' => 'IT reviews complaints in order of urgency.'],
    ['question' => 'What happens next?', 'answer' => 'Your complaint is logged and assigned for review.'],
    ['question' => 'Do I need to submit multiple times?', 'answer' => 'No, one detailed report is enough.'],
    ['question' => 'Any tips for faster help?', 'answer' => 'Attach a screenshot and mention your department.'],
    ['question' => 'Is my data secure?', 'answer' => 'Access is restricted to IT staff.'],
    ['question' => 'What if I uploaded the wrong file?', 'answer' => 'Submit a follow-up complaint with corrected info.'],
    ['question' => 'Can I edit a complaint?', 'answer' => 'Not yet. Contact IT if you need to update details.'],
    ['question' => 'What issues are urgent?', 'answer' => 'System outages, security risks, and work stoppages.'],
    ['question' => 'Will I be notified?', 'answer' => 'IT will reach out if more information is needed.'],
];

$faqItems = $defaultFaq;
if (is_file($faqFile)) {
    $storedFaq = json_decode((string) file_get_contents($faqFile), true);
    if (is_array($storedFaq) && $storedFaq) {
        $faqItems = $storedFaq;
    }
}

$notifications = get_user_notifications((int) $user['id'], 5);
$unreadNotifications = get_unread_notification_count((int) $user['id']);
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nexus IT | Employee Dashboard</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide dashboard">
            <div class="header-row">
                <div>
                    <p class="eyebrow">Nexus IT</p>
                    <h1 class="brand">Employee Dashboard</h1>
                    <p class="subtitle">Welcome, <?php echo htmlspecialchars($user['full_name'] ?? $user['staff_id'] ?? ''); ?>.</p>
                </div>
                <div class="header-actions">
                    <a class="button ghost" href="/account.php">Account Details</a>
                    <a class="button ghost" href="/logout.php">Log Out</a>
                </div>
            </div>

            <div class="panel-grid">
                <div class="panel">
                    <h2 class="section-title">Quick Action</h2>
                    <p class="hint">Need help? Send your issue to the IT team.</p>
                    <div class="panel-actions">
                        <button class="button" type="button" id="open-faq">Submit Complaint</button>
                        <a class="button ghost" href="/user/complaints.php">My Complaints</a>
                    </div>
                </div>
                <div class="panel accent">
                    <h2 class="section-title">Guidelines</h2>
                    <ul class="rules-list">
                        <li>Be clear and specific in your complaint details.</li>
                        <li>You can attach a screenshot or file (optional).</li>
                        <li>IT will review your submission shortly.</li>
                    </ul>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h2 class="section-title">Notifications</h2>
                        <p class="hint">Status updates from the IT department will appear here.</p>
                    </div>
                    <div class="panel-actions">
                        <span class="status-pill"><?php echo (int) $unreadNotifications; ?> Unread</span>
                        <?php if ($unreadNotifications > 0): ?>
                            <form method="POST" action="/mark_notifications.php">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />
                                <button class="button ghost small" type="submit">Mark all as read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($notifications): ?>
                    <div class="notification-list">
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item<?php echo empty($notification['is_read']) ? ' unread' : ''; ?>">
                                <div class="notification-item-top">
                                    <strong><?php echo htmlspecialchars((string) $notification['title']); ?></strong>
                                    <span class="status-pill"><?php echo htmlspecialchars((string) $notification['created_at']); ?></span>
                                </div>
                                <p class="notification-text">
                                    <?php echo htmlspecialchars((string) $notification['message']); ?>
                                    <?php if (!empty($notification['complaint_code'])): ?>
                                        <span class="hint">Reference: <?php echo htmlspecialchars((string) $notification['complaint_code']); ?></span>
                                    <?php endif; ?>
                                </p>
                                <a class="button ghost small" href="/user/complaints.php">View My Complaints</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="hint">No notifications yet.</p>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <div class="modal-backdrop" id="faq-backdrop" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="faq-title">
            <div class="modal-header">
                <h2 id="faq-title">FAQ & Submission Guide</h2>
                <button class="button ghost small" type="button" id="close-faq">Close</button>
            </div>
            <div class="modal-body" id="faq-scroll">
                <?php foreach ($faqItems as $item): ?>
                    <p><strong>Q:</strong> <?php echo htmlspecialchars($item['question'] ?? ''); ?></p>
                    <p><strong>A:</strong> <?php echo htmlspecialchars($item['answer'] ?? ''); ?></p>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer">
                <label class="checkbox hidden" id="faq-check-wrap">
                    <input type="checkbox" id="faq-check" />
                    I have read the FAQ completely
                </label>
                <div class="modal-actions">
                    <span class="hint" id="faq-hint"></span>
                    <button class="button" type="button" id="confirm-continue">Continue</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const openBtn = document.getElementById('open-faq');
        const closeBtn = document.getElementById('close-faq');
        const backdrop = document.getElementById('faq-backdrop');
        const scrollBox = document.getElementById('faq-scroll');
        const checkBox = document.getElementById('faq-check');
        const checkWrap = document.getElementById('faq-check-wrap');
        const hint = document.getElementById('faq-hint');
        const confirmBtn = document.getElementById('confirm-continue');

        function openModal() {
            backdrop.classList.add('show');
            backdrop.setAttribute('aria-hidden', 'false');
            scrollBox.scrollTop = 0;
            checkBox.checked = false;
            checkWrap.classList.add('hidden');
            hint.textContent = '';
        }

        function closeModal() {
            backdrop.classList.remove('show');
            backdrop.setAttribute('aria-hidden', 'true');
        }

        openBtn.addEventListener('click', openModal);
        closeBtn.addEventListener('click', closeModal);
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) {
                closeModal();
            }
        });

        scrollBox.addEventListener('scroll', () => {
            const atBottom = scrollBox.scrollTop + scrollBox.clientHeight >= scrollBox.scrollHeight - 2;
            if (atBottom) {
                checkWrap.classList.remove('hidden');
            }
        });

        confirmBtn.addEventListener('click', () => {
            if (!checkBox.checked) {
                hint.textContent = 'Please read the FAQ and tick the box.';
                return;
            }
            window.location.href = '/user/complaint.php';
        });
    </script>
</body>
</html>
