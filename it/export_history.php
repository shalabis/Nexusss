<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

if (($_SESSION['user']['role'] ?? '') !== 'it') {
    header('Location: /index.php');
    exit;
}

function report_period_from_request(array $query): array
{
    $mode = $query['mode'] ?? 'day';

    if ($mode === 'day') {
        $date = trim((string) ($query['date'] ?? ''));
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            throw new RuntimeException('Invalid date.');
        }

        return [
            'mode' => 'day',
            'label' => $date,
            'filename' => 'complaints_' . $date . '.pdf',
            'excel_filename' => 'complaints_' . $date . '.csv',
            'where' => 'DATE(c.created_at) = :date',
            'params' => ['date' => $date],
        ];
    }

    if ($mode === 'month') {
        $month = (int) ($query['month'] ?? 0);
        $year = (int) ($query['year'] ?? 0);
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            throw new RuntimeException('Invalid month/year.');
        }

        return [
            'mode' => 'month',
            'label' => sprintf('%04d-%02d', $year, $month),
            'filename' => sprintf('complaints_%04d_%02d.pdf', $year, $month),
            'excel_filename' => sprintf('complaints_%04d_%02d.csv', $year, $month),
            'where' => 'YEAR(c.created_at) = :year AND MONTH(c.created_at) = :month',
            'params' => ['year' => $year, 'month' => $month],
        ];
    }

    throw new RuntimeException('Invalid mode.');
}

function report_fetch_complaints(PDO $pdo, array $period): array
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

function report_status_summary(array $rows): array
{
    $summary = [
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

function report_group_counts(array $rows, string $field): array
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

function pdf_escape_text(string $text): string
{
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace('(', '\\(', $text);
    $text = str_replace(')', '\\)', $text);
    $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text);

    return $text;
}

function pdf_wrap_line(string $text, int $maxChars = 92): array
{
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if ($text === '') {
        return ['-'];
    }

    $words = explode(' ', $text);
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        $candidate = $current === '' ? $word : $current . ' ' . $word;
        if (strlen($candidate) <= $maxChars) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        while (strlen($word) > $maxChars) {
            $lines[] = substr($word, 0, $maxChars);
            $word = substr($word, $maxChars);
        }
        $current = $word;
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return $lines ?: ['-'];
}

function pdf_build(array $pages): string
{
    $objects = [];

    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";

    $pageKids = [];
    $contentObjectNumbers = [];
    $pageObjectNumbers = [];
    $pageCount = count($pages);
    for ($i = 0; $i < $pageCount; $i++) {
        $contentObjectNumbers[$i] = 4 + ($i * 2);
        $pageObjectNumbers[$i] = 3 + ($i * 2);
        $pageKids[] = $pageObjectNumbers[$i] . ' 0 R';
    }

    $objects[] = "<< /Type /Pages /Count {$pageCount} /Kids [" . implode(' ', $pageKids) . "] >>";

    foreach ($pages as $index => $lines) {
        $content = "BT\n/F1 10 Tf\n14 TL\n40 800 Td\n";
        foreach ($lines as $line) {
            $content .= '(' . pdf_escape_text($line) . ") Tj\nT*\n";
        }
        $content .= "ET";

        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 " . ($contentObjectNumbers[$index] + 1) . " 0 R >> >> /Contents " . $contentObjectNumbers[$index] . " 0 R >>";
        $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    }

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1, $count = count($offsets); $i < $count; $i++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
    }

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

    return $pdf;
}

function report_pdf_pages(array $period, array $rows): array
{
    $statusSummary = report_status_summary($rows);
    $departmentSummary = report_group_counts($rows, 'department');
    $categorySummary = report_group_counts($rows, 'category');

    $pages = [];
    $lines = [
        'Nexus IT - Complaint History Report',
        'Period: ' . $period['label'],
        'Generated: ' . date('Y-m-d H:i:s'),
        '',
        'Summary',
        'Total complaints: ' . count($rows),
        'Pending: ' . $statusSummary['pending'],
        'In Progress: ' . $statusSummary['in_progress'],
        'Done: ' . $statusSummary['done'],
        'Rejected: ' . $statusSummary['rejected'],
        '',
        'Department Breakdown',
    ];

    foreach ($departmentSummary as $department => $count) {
        $lines[] = '- ' . $department . ': ' . $count;
    }

    $lines[] = '';
    $lines[] = 'Category Breakdown';
    foreach ($categorySummary as $category => $count) {
        $lines[] = '- ' . $category . ': ' . $count;
    }

    $lines[] = '';
    $lines[] = 'Complaint Details';

    $lineCount = count($lines);
    foreach ($rows as $index => $row) {
        $detailLines = [
            '',
            'Complaint ' . ($index + 1),
            'Ref: ' . (string) ($row['complaint_code'] ?: '-'),
            'Employee: ' . (string) ($row['full_name'] ?: '-') . ' | Staff ID: ' . (string) ($row['staff_id'] ?: '-'),
            'Department: ' . (string) ($row['department'] ?: '-'),
            'Status: ' . complaint_status_display((string) ($row['status'] ?? '')),
            'Created: ' . (string) ($row['created_at'] ?: '-'),
            'Category: ' . (string) ($row['category'] ?: '-'),
            'Specific Problem: ' . (string) ($row['category_detail'] ?: '-'),
            'Sub Detail: ' . (string) ($row['category_sub_detail'] ?: '-'),
            'Other Name: ' . (string) ($row['category_detail_note'] ?: '-'),
            'Location: ' . (string) ($row['problem_location'] ?: '-'),
            'Attachment: ' . (string) ($row['file_name'] ?: 'None'),
            'Details:',
        ];

        foreach (pdf_wrap_line((string) ($row['details'] ?? '-')) as $wrappedLine) {
            $detailLines[] = '  ' . $wrappedLine;
        }

        if ($lineCount + count($detailLines) > 52) {
            $pages[] = $lines;
            $lines = [
                'Nexus IT - Complaint History Report',
                'Period: ' . $period['label'],
                'Generated: ' . date('Y-m-d H:i:s'),
                '',
                'Complaint Details (continued)',
            ];
            $lineCount = count($lines);
        }

        foreach ($detailLines as $detailLine) {
            $lines[] = $detailLine;
        }
        $lineCount = count($lines);
    }

    $pages[] = $lines;
    return $pages;
}

function report_excel_download(array $period, array $rows): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $period['excel_filename'] . '"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Nexus IT Complaint History Report']);
    fputcsv($out, ['Period', $period['label']]);
    fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
    fputcsv($out, []);
    fputcsv($out, [
        'Complaint Ref',
        'Employee Name',
        'Staff ID',
        'Department',
        'Category',
        'Specific Problem',
        'Sub Detail',
        'Other Name',
        'Location',
        'Complaint Details',
        'Attachment',
        'Status',
        'Created At',
    ]);

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['complaint_code'],
            $row['full_name'],
            $row['staff_id'],
            $row['department'],
            $row['category'],
            $row['category_detail'],
            $row['category_sub_detail'],
            $row['category_detail_note'],
            $row['problem_location'],
            $row['details'],
            $row['file_name'],
            complaint_status_display((string) $row['status']),
            $row['created_at'],
        ]);
    }

    fclose($out);
    exit;
}

try {
    $period = report_period_from_request($_GET);
    $pdo = get_pdo();
    $rows = report_fetch_complaints($pdo, $period);
    $format = trim((string) ($_GET['format'] ?? 'pdf'));

    if ($format === 'excel') {
        report_excel_download($period, $rows);
    }

    $pdf = pdf_build(report_pdf_pages($period, $rows));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $period['filename'] . '"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
} catch (Throwable $exception) {
    http_response_code(400);
    echo $exception->getMessage();
    exit;
}
