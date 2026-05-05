<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

if (($_SESSION['user']['role'] ?? '') !== 'it') {
    header('Location: /index.php');
    exit;
}

$pdo = get_pdo();

$complaints = $pdo->query(
    "SELECT c.id, c.complaint_code, c.category, c.category_detail, c.category_sub_detail, c.category_detail_note, c.problem_location, c.details, c.file_path, c.file_name, c.status, c.created_at,
            u.staff_id, u.full_name, u.department
     FROM complaints c
     JOIN users u ON u.id = c.user_id
     WHERE c.status = 'in_progress'
     ORDER BY u.department ASC, c.created_at DESC"
)->fetchAll();

$departmentSummary = [];
foreach ($complaints as $complaint) {
    $department = trim((string) ($complaint['department'] ?? ''));
    if ($department === '') {
        $department = 'Unassigned';
    }

    if (!isset($departmentSummary[$department])) {
        $departmentSummary[$department] = [
            'total' => 0,
        ];
    }

    $departmentSummary[$department]['total']++;
}

$departments = array_keys($departmentSummary);
sort($departments);

$selectedDepartment = trim((string) ($_GET['department'] ?? ''));
if ($selectedDepartment === '' || !isset($departmentSummary[$selectedDepartment])) {
    $selectedDepartment = $departments[0] ?? '';
}

$filteredComplaints = array_values(array_filter(
    $complaints,
    static function (array $complaint) use ($selectedDepartment): bool {
        $department = trim((string) ($complaint['department'] ?? ''));
        if ($department === '') {
            $department = 'Unassigned';
        }

        return $selectedDepartment !== '' && $department === $selectedDepartment;
    }
));

$csrf = csrf_token();

function status_page_label(string $status): string
{
    return strtoupper(str_replace('_', ' ', $status));
}

function status_page_excerpt(string $details, int $limit = 120): string
{
    $details = trim(preg_replace('/\s+/', ' ', $details));
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($details) <= $limit) {
            return $details;
        }

        return rtrim(mb_substr($details, 0, $limit - 3)) . '...';
    }

    if (strlen($details) <= $limit) {
        return $details;
    }

    return rtrim(substr($details, 0, $limit - 3)) . '...';
}

function status_page_meta_parts(array $complaint): array
{
    $parts = [];
    $category = (string) ($complaint['category'] ?? '');
    $detail = trim((string) ($complaint['category_detail'] ?? ''));
    $subDetail = trim((string) ($complaint['category_sub_detail'] ?? ''));
    $detailNote = trim((string) ($complaint['category_detail_note'] ?? ''));
    $location = trim((string) ($complaint['problem_location'] ?? ''));

    if ($detail !== '') {
        if ($category === 'Hardware') {
            $parts[] = 'Hardware: ' . $detail;
        } elseif ($category === 'Software Support') {
            $parts[] = 'Request: ' . $detail;
        } elseif ($category === 'Connectivity & Network') {
            $parts[] = 'Connection: ' . $detail;
        } else {
            $parts[] = $detail;
        }
    }

    if ($subDetail !== '') {
        $parts[] = ($category === 'Connectivity & Network' ? 'Issue: ' : 'Software: ') . $subDetail;
    }

    if ($detailNote !== '') {
        if ($category === 'Software Support') {
            $parts[] = 'Other Software: ' . $detailNote;
        } elseif ($category === 'Hardware') {
            $parts[] = 'Other Device: ' . $detailNote;
        } else {
            $parts[] = 'Other: ' . $detailNote;
        }
    }

    if ($location !== '') {
        $parts[] = 'Location: ' . $location;
    }

    return $parts;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nexus IT | Update Status</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide dashboard">
            <div class="header-row">
                <div>
                    <p class="eyebrow">Nexus IT</p>
                    <h1 class="brand">Update Status</h1>
                    <p class="subtitle">Choose a department, review active work, and mark complaints as done when resolved.</p>
                </div>
                <div class="header-actions">
                    <a class="button ghost" href="/it/index.php">Back to Dashboard</a>
                    <a class="button ghost" href="/logout.php">Log Out</a>
                </div>
            </div>

            <div class="panel">
                <h2 class="section-title">Choose Department</h2>
                <p class="hint">Only complaints currently in progress are shown here.</p>

                <?php if ($departments): ?>
                    <div class="department-grid">
                        <?php foreach ($departments as $department): ?>
                            <?php $isActive = $department === $selectedDepartment; ?>
                            <a
                                class="department-card<?php echo $isActive ? ' active' : ''; ?>"
                                href="/it/status.php?department=<?php echo urlencode($department); ?>"
                            >
                                <p class="department-name"><?php echo htmlspecialchars($department); ?></p>
                                <p class="department-meta"><?php echo (int) $departmentSummary[$department]['total']; ?> in progress</p>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="hint">There are no complaints in progress right now.</p>
                <?php endif; ?>
            </div>

            <?php if ($selectedDepartment !== ''): ?>
                <div class="panel complaints-panel">
                    <div class="complaints-panel-header">
                        <div>
                            <h2 class="section-title"><?php echo htmlspecialchars($selectedDepartment); ?> In Progress</h2>
                            <p class="hint">Open any complaint card to view the full details and update its final status.</p>
                        </div>
                        <div class="complaints-panel-stats">
                            <span class="status-pill"><?php echo (int) count($filteredComplaints); ?> Active</span>
                        </div>
                    </div>

                    <?php if ($filteredComplaints): ?>
                        <div class="complaint-card-list">
                            <?php foreach ($filteredComplaints as $c): ?>
                                <?php
                                $modalDetails = htmlspecialchars($c['details'], ENT_QUOTES);
                                $modalFilePath = !empty($c['file_path'])
                                    ? htmlspecialchars(complaint_attachment_url((int) $c['id']), ENT_QUOTES)
                                    : '';
                                $modalFileName = htmlspecialchars((string) ($c['file_name'] ?? ''), ENT_QUOTES);
                                $subtitleParts = [
                                    htmlspecialchars($c['full_name']),
                                    htmlspecialchars($c['staff_id']),
                                    htmlspecialchars((string) ($c['category'] ?: 'Not set')),
                                ];
                                foreach (status_page_meta_parts($c) as $metaPart) {
                                    $subtitleParts[] = htmlspecialchars($metaPart);
                                }
                                ?>
                                <button
                                    class="complaint-card-button"
                                    type="button"
                                    data-modal-open="status-modal"
                                    data-id="<?php echo (int) $c['id']; ?>"
                                    data-code="<?php echo htmlspecialchars($c['complaint_code'], ENT_QUOTES); ?>"
                                    data-staff-id="<?php echo htmlspecialchars($c['staff_id'], ENT_QUOTES); ?>"
                                    data-full-name="<?php echo htmlspecialchars($c['full_name'], ENT_QUOTES); ?>"
                                    data-department="<?php echo htmlspecialchars($c['department'], ENT_QUOTES); ?>"
                                    data-category="<?php echo htmlspecialchars((string) ($c['category'] ?? ''), ENT_QUOTES); ?>"
                                    data-category-detail="<?php echo htmlspecialchars((string) ($c['category_detail'] ?? ''), ENT_QUOTES); ?>"
                                    data-category-sub-detail="<?php echo htmlspecialchars((string) ($c['category_sub_detail'] ?? ''), ENT_QUOTES); ?>"
                                    data-category-detail-note="<?php echo htmlspecialchars((string) ($c['category_detail_note'] ?? ''), ENT_QUOTES); ?>"
                                    data-problem-location="<?php echo htmlspecialchars((string) ($c['problem_location'] ?? ''), ENT_QUOTES); ?>"
                                    data-status="<?php echo htmlspecialchars(status_page_label($c['status']), ENT_QUOTES); ?>"
                                    data-created="<?php echo htmlspecialchars($c['created_at'], ENT_QUOTES); ?>"
                                    data-details="<?php echo $modalDetails; ?>"
                                    data-file-path="<?php echo $modalFilePath; ?>"
                                    data-file-name="<?php echo $modalFileName; ?>"
                                >
                                    <span class="complaint-card">
                                        <span class="complaint-card-top">
                                            <span>
                                                <span class="complaint-card-title"><?php echo htmlspecialchars($c['complaint_code']); ?></span>
                                                <span class="complaint-card-subtitle"><?php echo implode(' • ', $subtitleParts); ?></span>
                                            </span>
                                            <span class="status-pill status-<?php echo htmlspecialchars($c['status']); ?>"><?php echo htmlspecialchars(status_page_label($c['status'])); ?></span>
                                        </span>
                                        <span class="complaint-card-body"><?php echo htmlspecialchars(status_page_excerpt($c['details'])); ?></span>
                                        <span class="complaint-card-footer">
                                            <span>Staff ID: <?php echo htmlspecialchars($c['staff_id']); ?></span>
                                            <span><?php echo htmlspecialchars($c['created_at']); ?></span>
                                        </span>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="hint">No in-progress complaints found for this department.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <div class="modal-backdrop" id="status-modal" aria-hidden="true">
        <div class="modal complaint-modal">
            <div class="modal-header">
                <div>
                    <p class="mini-label">Status Review</p>
                    <h2 class="section-title modal-title">Complaint</h2>
                </div>
                <button class="button ghost small" type="button" data-modal-close>Close</button>
            </div>

            <div class="modal-body complaint-modal-body">
                <div class="complaint-modal-grid">
                    <div class="detail-pair">
                        <span class="detail-label">Complaint Ref</span>
                        <span class="detail-value" data-field="id">-</span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Status</span>
                        <span class="detail-value" data-field="status">-</span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Staff ID</span>
                        <span class="detail-value" data-field="staff-id">-</span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Created</span>
                        <span class="detail-value" data-field="created">-</span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Category</span>
                        <span class="detail-value" data-field="category">-</span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label" data-label-field="category-detail-label">Specific Problem</span>
                        <span class="detail-value" data-field="category-detail">-</span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label" data-label-field="category-sub-detail-label">Specific Software</span>
                        <span class="detail-value" data-field="category-sub-detail">-</span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label" data-label-field="category-detail-note-label">Other Name</span>
                        <span class="detail-value" data-field="category-detail-note">-</span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Location</span>
                        <span class="detail-value" data-field="problem-location">-</span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Name</span>
                        <span class="detail-value" data-field="full-name">-</span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Department</span>
                        <span class="detail-value" data-field="department">-</span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Attachment</span>
                        <span class="detail-value">
                            <a class="button ghost small hidden-link" href="#" target="_blank" rel="noopener" data-field="file-link">Open File</a>
                            <span data-field="file-empty">None</span>
                        </span>
                    </div>
                </div>

                <div class="complaint-detail-box">
                    <p class="detail-label">Complaint Details</p>
                    <p class="detail-text" data-field="details">-</p>
                </div>
            </div>

            <div class="modal-footer complaint-modal-footer">
                <div class="modal-actions">
                    <form method="POST" action="/it/update_status.php" onsubmit="return confirm('Mark this complaint as done?');">
                        <input type="hidden" name="id" value="" data-action-field="id" />
                        <input type="hidden" name="action" value="done" />
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />
                        <button class="button small" type="submit">Mark Done</button>
                    </form>
                </div>
                <p class="hint complaint-modal-note">Use this after the complaint has been fully resolved.</p>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const modal = document.getElementById('status-modal');
            if (!modal) return;

            const body = document.body;
            const openButtons = document.querySelectorAll('[data-modal-open="status-modal"]');
            const closeButtons = modal.querySelectorAll('[data-modal-close]');
            const title = modal.querySelector('.modal-title');
            const fileLink = modal.querySelector('[data-field="file-link"]');
            const fileEmpty = modal.querySelector('[data-field="file-empty"]');
            const detailLabel = modal.querySelector('[data-label-field="category-detail-label"]');
            const subDetailLabel = modal.querySelector('[data-label-field="category-sub-detail-label"]');
            const detailNoteLabel = modal.querySelector('[data-label-field="category-detail-note-label"]');
            const fieldMap = {
                id: modal.querySelector('[data-field="id"]'),
                'staff-id': modal.querySelector('[data-field="staff-id"]'),
                'full-name': modal.querySelector('[data-field="full-name"]'),
                department: modal.querySelector('[data-field="department"]'),
                category: modal.querySelector('[data-field="category"]'),
                'category-detail': modal.querySelector('[data-field="category-detail"]'),
                'category-sub-detail': modal.querySelector('[data-field="category-sub-detail"]'),
                'category-detail-note': modal.querySelector('[data-field="category-detail-note"]'),
                'problem-location': modal.querySelector('[data-field="problem-location"]'),
                status: modal.querySelector('[data-field="status"]'),
                created: modal.querySelector('[data-field="created"]'),
                details: modal.querySelector('[data-field="details"]')
            };

            function detailLabelForCategory(category) {
                if (category === 'Hardware') return 'Hardware';
                if (category === 'Software Support') return 'Request Type';
                if (category === 'Connectivity & Network') return 'Connection Type';
                return 'Specific Problem';
            }

            function subDetailLabelForCategory(category) {
                if (category === 'Connectivity & Network') return 'Issue Type';
                return 'Specific Software';
            }

            function detailNoteLabelForCategory(category) {
                if (category === 'Software Support') return 'Other Software';
                if (category === 'Hardware') return 'Other Device';
                return 'Other Name';
            }

            function closeModal() {
                modal.classList.remove('show');
                modal.setAttribute('aria-hidden', 'true');
                body.style.overflow = '';
            }

            function openModal(button) {
                const data = button.dataset;

                title.textContent = data.code || data.id || '-';
                fieldMap.id.textContent = data.code || data.id || '-';
                fieldMap['staff-id'].textContent = data.staffId || '-';
                fieldMap['full-name'].textContent = data.fullName || '-';
                fieldMap.department.textContent = data.department || '-';
                fieldMap.category.textContent = data.category || 'Not set';
                if (detailLabel) {
                    detailLabel.textContent = detailLabelForCategory(data.category || '');
                }
                if (subDetailLabel) {
                    subDetailLabel.textContent = subDetailLabelForCategory(data.category || '');
                }
                if (detailNoteLabel) {
                    detailNoteLabel.textContent = detailNoteLabelForCategory(data.category || '');
                }
                fieldMap['category-detail'].textContent = data.categoryDetail || '-';
                fieldMap['category-sub-detail'].textContent = data.categorySubDetail || '-';
                fieldMap['category-detail-note'].textContent = data.categoryDetailNote || '-';
                fieldMap['problem-location'].textContent = data.problemLocation || '-';
                fieldMap.status.textContent = data.status || '-';
                fieldMap.created.textContent = data.created || '-';
                fieldMap.details.textContent = data.details || '-';

                modal.querySelectorAll('[data-action-field="id"]').forEach((input) => {
                    input.value = data.id || '';
                });

                if (data.filePath) {
                    fileLink.href = data.filePath;
                    fileLink.textContent = data.fileName || 'Open File';
                    fileLink.hidden = false;
                    fileEmpty.hidden = true;
                } else {
                    fileLink.href = '#';
                    fileLink.hidden = true;
                    fileEmpty.hidden = false;
                }

                modal.classList.add('show');
                modal.setAttribute('aria-hidden', 'false');
                body.style.overflow = 'hidden';
            }

            openButtons.forEach((button) => {
                button.addEventListener('click', function () {
                    openModal(button);
                });
            });

            closeButtons.forEach((button) => {
                button.addEventListener('click', closeModal);
            });

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    closeModal();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && modal.classList.contains('show')) {
                    closeModal();
                }
            });
        })();
    </script>
</body>
</html>
