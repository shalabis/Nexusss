<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

if (($_SESSION['user']['role'] ?? '') !== 'it') {
    header('Location: /index.php');
    exit;
}

function history_period_from_request(array $query): array
{
    $mode = $query['mode'] ?? 'day';

    if ($mode === 'day') {
        $date = trim((string) ($query['date'] ?? ''));
        if ($date === '') {
            return ['mode' => 'day'];
        }

        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            throw new RuntimeException('Invalid date format.');
        }

        return [
            'mode' => 'day',
            'label' => $date,
            'download' => '/it/export_history.php?mode=day&date=' . urlencode($date),
            'excel_download' => '/it/export_history.php?format=excel&mode=day&date=' . urlencode($date),
            'filename' => 'complaints_' . $date . '.pdf',
            'excel_filename' => 'complaints_' . $date . '.csv',
            'where' => 'DATE(c.created_at) = :date',
            'params' => ['date' => $date],
        ];
    }

    if ($mode === 'month') {
        $month = trim((string) ($query['month'] ?? ''));
        $year = trim((string) ($query['year'] ?? ''));
        if ($month === '' || $year === '') {
            return ['mode' => 'month'];
        }

        $m = (int) $month;
        $y = (int) $year;
        if ($m < 1 || $m > 12 || $y < 2000 || $y > 2100) {
            throw new RuntimeException('Invalid month or year.');
        }

        return [
            'mode' => 'month',
            'label' => sprintf('%04d-%02d', $y, $m),
            'download' => "/it/export_history.php?mode=month&year={$y}&month={$m}",
            'excel_download' => "/it/export_history.php?format=excel&mode=month&year={$y}&month={$m}",
            'filename' => sprintf('complaints_%04d_%02d.pdf', $y, $m),
            'excel_filename' => sprintf('complaints_%04d_%02d.csv', $y, $m),
            'where' => 'YEAR(c.created_at) = :year AND MONTH(c.created_at) = :month',
            'params' => ['year' => $y, 'month' => $m],
        ];
    }

    throw new RuntimeException('Invalid report type.');
}

function history_fetch_rows(PDO $pdo, array $period): array
{
    $stmt = $pdo->prepare(
        "SELECT c.complaint_code, c.category, c.category_detail, c.category_sub_detail, c.category_detail_note,
                c.problem_location, c.details, c.file_name, c.status, c.created_at,
                u.staff_id, u.full_name, u.department
         FROM complaints c
         JOIN users u ON u.id = c.user_id
         WHERE {$period['where']}
         ORDER BY c.created_at DESC"
    );
    $stmt->execute($period['params']);

    return $stmt->fetchAll();
}

function history_status_summary(array $rows): array
{
    $summary = [
        'total' => count($rows),
        'pending' => 0,
        'in_progress' => 0,
        'done' => 0,
        'rejected' => 0,
    ];

    foreach ($rows as $row) {
        $status = (string) ($row['status'] ?? '');
        if (isset($summary[$status])) {
            $summary[$status]++;
        }
    }

    return $summary;
}

function history_group_counts(array $rows, string $field): array
{
    $counts = [];
    foreach ($rows as $row) {
        $label = trim((string) ($row[$field] ?? ''));
        if ($label === '') {
            $label = 'Unspecified';
        }
        if (!isset($counts[$label])) {
            $counts[$label] = 0;
        }
        $counts[$label]++;
    }

    arsort($counts);
    return $counts;
}

function history_excerpt(string $text, int $limit = 140): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if (strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(substr($text, 0, $limit - 3)) . '...';
}

$pdo = get_pdo();
$mode = $_GET['mode'] ?? 'day';
$date = $_GET['date'] ?? '';
$month = $_GET['month'] ?? '';
$year = $_GET['year'] ?? '';
$error = '';
$report = null;

try {
    $period = history_period_from_request($_GET);
    if (isset($period['where'])) {
        $rows = history_fetch_rows($pdo, $period);
        $report = [
            'label' => $period['label'],
            'download' => $period['download'],
            'excel_download' => $period['excel_download'],
            'filename' => $period['filename'],
            'excel_filename' => $period['excel_filename'],
            'rows' => $rows,
            'status_summary' => history_status_summary($rows),
            'department_summary' => history_group_counts($rows, 'department'),
            'category_summary' => history_group_counts($rows, 'category'),
        ];
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nexus IT | History Report</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide dashboard">
            <div class="header-row">
                <div>
                    <p class="eyebrow">Nexus IT</p>
                    <h1 class="brand">History Report</h1>
                    <p class="subtitle">Review complaint history by day or month, then export a full PDF report with summaries and detailed complaint records.</p>
                </div>
                <div class="header-actions">
                    <a class="button ghost" href="/it/index.php">Back to Dashboard</a>
                    <a class="button ghost" href="/logout.php">Log Out</a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="panel">
                <h2 class="section-title">Generate Report</h2>
                <form method="GET" class="filter-bar">
                    <select class="input" id="mode" name="mode" onchange="this.form.submit()">
                        <option value="day" <?php if ($mode === 'day') echo 'selected'; ?>>By Date</option>
                        <option value="month" <?php if ($mode === 'month') echo 'selected'; ?>>By Month</option>
                    </select>

                    <?php if ($mode === 'day'): ?>
                        <input class="input" type="date" id="date" name="date" value="<?php echo htmlspecialchars((string) $date); ?>" required />
                    <?php else: ?>
                        <select class="input" id="month" name="month" required>
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php if ((int) $month === $i) echo 'selected'; ?>><?php echo sprintf('Month %02d', $i); ?></option>
                            <?php endfor; ?>
                        </select>
                        <input class="input" type="number" id="year" name="year" min="2000" max="2100" value="<?php echo htmlspecialchars((string) $year); ?>" required />
                    <?php endif; ?>

                    <button class="button" type="submit">View Report</button>
                </form>
            </div>

            <?php if ($report): ?>
                <div class="stats-grid" style="margin-top: 20px;">
                    <div class="stat-card featured">
                        <p class="stat-label">Period</p>
                        <p class="stat-value"><?php echo htmlspecialchars($report['label']); ?></p>
                    </div>
                    <div class="stat-card">
                        <p class="stat-label">Total</p>
                        <p class="stat-value"><?php echo (int) $report['status_summary']['total']; ?></p>
                    </div>
                    <div class="stat-card">
                        <p class="stat-label">Pending</p>
                        <p class="stat-value"><?php echo (int) $report['status_summary']['pending']; ?></p>
                    </div>
                    <div class="stat-card">
                        <p class="stat-label">In Progress</p>
                        <p class="stat-value"><?php echo (int) $report['status_summary']['in_progress']; ?></p>
                    </div>
                    <div class="stat-card">
                        <p class="stat-label">Done</p>
                        <p class="stat-value"><?php echo (int) $report['status_summary']['done']; ?></p>
                    </div>
                    <div class="stat-card">
                        <p class="stat-label">Rejected</p>
                        <p class="stat-value"><?php echo (int) $report['status_summary']['rejected']; ?></p>
                    </div>
                </div>

                <div class="panel" style="margin-top: 20px;">
                    <div class="panel-header">
                        <div>
                            <h2 class="section-title">Export Report</h2>
                            <p class="hint">Download the current history report in your preferred format.</p>
                        </div>
                        <div class="header-actions">
                            <a
                                class="button"
                                href="<?php echo htmlspecialchars($report['download']); ?>"
                                download="<?php echo htmlspecialchars((string) $report['filename']); ?>"
                            >Download PDF</a>
                            <a
                                class="button ghost"
                                href="<?php echo htmlspecialchars($report['excel_download']); ?>"
                                download="<?php echo htmlspecialchars((string) $report['excel_filename']); ?>"
                            >Download Excel</a>
                        </div>
                    </div>
                </div>

                <div class="panel-grid" style="margin-top: 20px;">
                    <div class="panel">
                        <div class="panel-header">
                            <div>
                                <h2 class="section-title">Department Breakdown</h2>
                                <p class="hint">Complaint count by employee department.</p>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th>Total Complaints</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report['department_summary'] as $departmentName => $count): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($departmentName); ?></td>
                                            <td><?php echo (int) $count; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="panel accent">
                        <div class="panel-header">
                            <div>
                                <h2 class="section-title">Category Breakdown</h2>
                                <p class="hint">Complaint count by issue category.</p>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th>Total Complaints</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report['category_summary'] as $categoryName => $count): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($categoryName); ?></td>
                                            <td><?php echo (int) $count; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="panel complaints-panel">
                    <div class="complaints-panel-header">
                        <div>
                            <h2 class="section-title">Detailed Complaint Records</h2>
                            <p class="hint">Every complaint for the selected period, including employee info, full problem info, status, and file attachment details.</p>
                        </div>
                        <div class="complaints-panel-stats">
                            <span class="status-pill"><?php echo (int) count($report['rows']); ?> Records</span>
                        </div>
                    </div>

                    <?php if ($report['rows']): ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Complaint Ref</th>
                                        <th>Employee</th>
                                        <th>Category</th>
                                        <th>Details</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report['rows'] as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string) $row['complaint_code']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars((string) $row['full_name']); ?><br />
                                                <span class="hint">Staff ID: <?php echo htmlspecialchars((string) $row['staff_id']); ?></span><br />
                                                <span class="hint">Department: <?php echo htmlspecialchars((string) $row['department']); ?></span>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars((string) ($row['category'] ?: 'Not set')); ?><br />
                                                <span class="hint">Specific: <?php echo htmlspecialchars((string) ($row['category_detail'] ?: '-')); ?></span><br />
                                                <span class="hint">Sub Detail: <?php echo htmlspecialchars((string) ($row['category_sub_detail'] ?: '-')); ?></span><br />
                                                <span class="hint">Other Name: <?php echo htmlspecialchars((string) ($row['category_detail_note'] ?: '-')); ?></span><br />
                                                <span class="hint">Location: <?php echo htmlspecialchars((string) ($row['problem_location'] ?: '-')); ?></span>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars(history_excerpt((string) $row['details'])); ?><br />
                                                <span class="hint">Attachment: <?php echo htmlspecialchars((string) ($row['file_name'] ?: 'None')); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars(complaint_status_display((string) $row['status'])); ?></td>
                                            <td><?php echo htmlspecialchars((string) $row['created_at']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="hint">No complaints were found for this period.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
