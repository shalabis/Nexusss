<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

if (($_SESSION['user']['role'] ?? '') !== 'it') {
    header('Location: /index.php');
    exit;
}

$user = $_SESSION['user'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nexus IT | IT Support</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide dashboard">
            <div class="header-row">
                <div>
                    <p class="eyebrow">Nexus IT</p>
                    <h1 class="brand">IT Support</h1>
                    <p class="subtitle">Welcome, <?php echo htmlspecialchars($user['full_name'] ?? $user['staff_id'] ?? ''); ?>.</p>
                </div>
                <div class="header-actions">
                    <a class="button ghost" href="/account.php">Account Details</a>
                    <a class="button ghost" href="/logout.php">Log Out</a>
                </div>
            </div>

            <div class="panel-grid">
                <div class="panel">
                    <h2 class="section-title">Incoming</h2>
                    <p class="hint">Review and triage new complaints.</p>
                    <div class="panel-actions">
                        <a class="button" href="/it/complaints.php">View Complaints</a>
                        <a class="button ghost" href="/it/status.php">Update Status</a>
                        <a class="button ghost" href="/it/history.php">History Report</a>
                    </div>
                </div>
                <div class="panel accent">
                    <h2 class="section-title">Workflow</h2>
                    <ul class="rules-list">
                        <li>Check complaint details and attached files.</li>
                        <li>Prioritize urgent issues first.</li>
                        <li>Follow up with users as needed.</li>
                    </ul>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
