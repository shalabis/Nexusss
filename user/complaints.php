<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

if (($_SESSION['user']['role'] ?? '') !== 'user') {
    header('Location: /index.php');
    exit;
}

$pdo = get_pdo();
$userId = (int) ($_SESSION['user']['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT id, complaint_code, category, category_detail, category_sub_detail, category_detail_note, problem_location, details, file_path, file_name, status, created_at
     FROM complaints
     WHERE user_id = :user_id
     ORDER BY created_at DESC"
);
$stmt->execute(['user_id' => $userId]);
$complaints = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nexus IT | My Complaints</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide">
            <div class="header-row">
                <div>
                    <h1 class="brand">Nexus IT</h1>
                    <p class="subtitle">My Complaints</p>
                </div>
                <div class="header-actions">
                    <a class="button ghost" href="/user/index.php">Back to Dashboard</a>
                    <a class="button ghost" href="/logout.php">Log Out</a>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Complaint Ref</th>
                            <th>Category</th>
                            <th>Details</th>
                            <th>File</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($complaints as $c): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['complaint_code']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars((string) ($c['category'] ?: 'Not set')); ?>
                                    <?php if (!empty($c['category_detail'])): ?>
                                        <br /><span class="hint"><?php echo htmlspecialchars((string) $c['category_detail']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($c['category_sub_detail'])): ?>
                                        <br /><span class="hint"><?php echo htmlspecialchars($c['category'] === 'Connectivity & Network' ? 'Issue' : 'Software'); ?>: <?php echo htmlspecialchars((string) $c['category_sub_detail']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($c['category_detail_note'])): ?>
                                        <br /><span class="hint"><?php echo htmlspecialchars($c['category'] === 'Software Support' ? 'Other Software' : 'Other'); ?>: <?php echo htmlspecialchars((string) $c['category_detail_note']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($c['problem_location'])): ?>
                                        <br /><span class="hint">Location: <?php echo htmlspecialchars((string) $c['problem_location']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="details"><?php echo nl2br(htmlspecialchars($c['details'])); ?></td>
                                <td>
                                    <?php if (!empty($c['file_path'])): ?>
                                        <a class="button ghost small" href="<?php echo htmlspecialchars(complaint_attachment_url((int) $c['id'])); ?>" target="_blank" rel="noopener">
                                            <?php echo htmlspecialchars($c['file_name'] ?? 'Download'); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="hint">None</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(strtoupper($c['status'])); ?></td>
                                <td><?php echo htmlspecialchars($c['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
