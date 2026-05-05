<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../mail_helper.php';
require_login();

if (($_SESSION['user']['role'] ?? '') !== 'user') {
    header('Location: /index.php');
    exit;
}

$pdo = get_pdo();
$message = '';
$error = '';
$selectedCategory = trim((string) ($_POST['category'] ?? ''));
$selectedCategoryDetail = trim((string) ($_POST['category_detail'] ?? ''));
$selectedCategorySubDetail = trim((string) ($_POST['category_sub_detail'] ?? ''));
$categoryDetailNoteValue = trim((string) ($_POST['category_detail_note'] ?? ''));
$detailsValue = trim((string) ($_POST['details'] ?? ''));
$problemLocationValue = trim((string) ($_POST['problem_location'] ?? ''));
$categories = complaint_categories();
$selectedDetailOptions = complaint_category_detail_options($selectedCategory);
$selectedSubDetailOptions = complaint_category_sub_detail_options($selectedCategory, $selectedCategoryDetail);
$showCategoryDetail = $selectedDetailOptions !== [];
$showCategorySubDetail = $selectedSubDetailOptions !== [];
$showCategoryDetailNote = complaint_category_detail_note_required($selectedCategory, $selectedCategoryDetail, $selectedCategorySubDetail);
$showProblemLocation = complaint_category_requires_location($selectedCategory);
$detailLabelText = 'Specific Problem';
$detailPlaceholderText = 'Choose a specific problem';
$allowedUploadExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
$allowedUploadMimeTypes = [
    'image/jpeg',
    'image/png',
    'application/pdf',
];
$maxUploadBytes = 5 * 1024 * 1024;
if ($selectedCategory === 'Hardware') {
    $detailLabelText = 'Hardware';
    $detailPlaceholderText = 'Choose the hardware';
} elseif ($selectedCategory === 'Software Support') {
    $detailLabelText = 'Request Type';
    $detailPlaceholderText = 'Choose the request type';
} elseif ($selectedCategory === 'Connectivity & Network') {
    $detailLabelText = 'Connection Type';
    $detailPlaceholderText = 'Choose the connection type';
}
$subDetailLabelText = $selectedCategory === 'Connectivity & Network' ? 'Issue Type' : 'Specific Software';
$subDetailPlaceholderText = $selectedCategory === 'Connectivity & Network' ? 'Choose the issue type' : 'Choose the software';
$detailNoteLabelText = $selectedCategory === 'Software Support' ? 'Software Name' : 'Device Name';
$detailNotePlaceholderText = $selectedCategory === 'Software Support' ? 'Tell us which software' : 'Tell us which device';
$categoryDetailOptionsByCategory = [];
$categorySubDetailOptionsByCategory = [];
foreach ($categories as $categoryOption) {
    $categoryDetailOptionsByCategory[$categoryOption] = complaint_category_detail_options($categoryOption);
    $categorySubDetailOptionsByCategory[$categoryOption] = [];
    foreach ($categoryDetailOptionsByCategory[$categoryOption] as $detailOption) {
        $categorySubDetailOptionsByCategory[$categoryOption][$detailOption] = complaint_category_sub_detail_options($categoryOption, $detailOption);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $details = $detailsValue;
    $category = $selectedCategory;
    $categoryDetail = $selectedCategoryDetail;
    $categorySubDetail = $selectedCategorySubDetail;
    $categoryDetailNote = $categoryDetailNoteValue;
    $problemLocation = $problemLocationValue;
    $csrf = $_POST['csrf'] ?? '';

    if (!csrf_check($csrf)) {
        $error = 'Invalid request.';
    } elseif ($category === '' || !complaint_category_valid($category)) {
        $error = 'Please choose a problem category.';
    } elseif (complaint_category_detail_options($category) !== [] && !complaint_category_detail_valid($category, $categoryDetail)) {
        if ($category === 'Hardware') {
            $error = 'Please choose the hardware.';
        } elseif ($category === 'Software Support') {
            $error = 'Please choose the request type.';
        } elseif ($category === 'Connectivity & Network') {
            $error = 'Please choose the connection type.';
        } else {
            $error = 'Please choose the specific problem.';
        }
    } elseif (complaint_category_detail_options($category) === [] && $categoryDetail !== '') {
        $error = 'Invalid problem selection.';
    } elseif (complaint_category_sub_detail_options($category, $categoryDetail) !== [] && !complaint_category_sub_detail_valid($category, $categoryDetail, $categorySubDetail)) {
        $error = $category === 'Connectivity & Network' ? 'Please choose the network issue.' : 'Please choose the specific software.';
    } elseif (complaint_category_sub_detail_options($category, $categoryDetail) === [] && $categorySubDetail !== '') {
        $error = 'Invalid selection.';
    } elseif (complaint_category_detail_note_required($category, $categoryDetail, $categorySubDetail) && $categoryDetailNote === '') {
        $error = $category === 'Software Support' ? 'Please enter the software name.' : 'Please enter the device name.';
    } elseif (!complaint_category_detail_note_required($category, $categoryDetail, $categorySubDetail) && $categoryDetailNote !== '') {
        $error = 'Invalid name.';
    } elseif ($details === '') {
        $error = 'Complaint details are required.';
    } elseif (complaint_category_requires_location($category) && $problemLocation === '') {
        $error = $category === 'Connectivity & Network' ? 'Please enter the network location.' : 'Please enter the hardware location.';
    } elseif (!complaint_category_requires_location($category) && $problemLocation !== '') {
        $error = 'Invalid location.';
    } else {
        $filePath = null;
        $fileName = null;

        if (!empty($_FILES['attachment']['name'])) {
            $uploadDir = __DIR__ . '/../uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $originalName = basename($_FILES['attachment']['name']);
            $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
            $uniqueName = uniqid('complaint_', true) . ($extension ? '.' . $extension : '');
            $targetPath = $uploadDir . '/' . $uniqueName;

            if (($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'The attachment could not be uploaded.';
            } elseif (($_FILES['attachment']['size'] ?? 0) > $maxUploadBytes) {
                $error = 'The attachment must be 5 MB or smaller.';
            } elseif (!in_array($extension, $allowedUploadExtensions, true)) {
                $error = 'Only JPG, PNG, and PDF files are allowed.';
            } elseif (!is_uploaded_file($_FILES['attachment']['tmp_name'])) {
                $error = 'Invalid file upload.';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = $finfo ? finfo_file($finfo, $_FILES['attachment']['tmp_name']) : false;
                if ($finfo) {
                    finfo_close($finfo);
                }

                if ($mimeType === false || !in_array($mimeType, $allowedUploadMimeTypes, true)) {
                    $error = 'The uploaded file type is not allowed.';
                }
            }

            if ($error === '') {
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetPath)) {
                    $filePath = '/uploads/' . $uniqueName;
                    $fileName = $safeName;
                } else {
                    $error = 'Failed to upload the file.';
                }
            }
        }

        if ($error === '') {
            $stmt = $pdo->prepare('INSERT INTO complaints (user_id, category, category_detail, category_sub_detail, category_detail_note, problem_location, details, file_path, file_name, status) VALUES (:user_id, :category, :category_detail, :category_sub_detail, :category_detail_note, :problem_location, :details, :file_path, :file_name, :status)');
            $stmt->execute([
                'user_id' => $_SESSION['user']['id'],
                'category' => $category,
                'category_detail' => $categoryDetail !== '' ? $categoryDetail : null,
                'category_sub_detail' => $categorySubDetail !== '' ? $categorySubDetail : null,
                'category_detail_note' => $categoryDetailNote !== '' ? $categoryDetailNote : null,
                'problem_location' => $problemLocation !== '' ? $problemLocation : null,
                'details' => $details,
                'file_path' => $filePath,
                'file_name' => $fileName,
                'status' => 'pending',
            ]);
            $complaintId = (int) $pdo->lastInsertId();
            $complaintCode = build_complaint_code($complaintId);
            $codeUpdate = $pdo->prepare('UPDATE complaints SET complaint_code = :complaint_code WHERE id = :id');
            $codeUpdate->execute([
                'complaint_code' => $complaintCode,
                'id' => $complaintId,
            ]);
            $submittedBy = trim((string) ($_SESSION['user']['full_name'] ?? $_SESSION['user']['staff_id'] ?? 'A user'));
            $submittedDepartment = trim((string) ($_SESSION['user']['department'] ?? ''));
            $itEmailStmt = $pdo->prepare(
                "SELECT full_name, email
                 FROM users
                 WHERE role = 'it'
                   AND email IS NOT NULL
                   AND email <> ''
                   AND email_verified_at IS NOT NULL"
            );
            $itEmailStmt->execute();
            $itRecipients = $itEmailStmt->fetchAll();

            foreach ($itRecipients as $recipient) {
                try {
                    send_it_complaint_notification(
                        (string) $recipient['email'],
                        (string) ($recipient['full_name'] ?: 'IT Team'),
                        $complaintCode,
                        $submittedBy,
                        $submittedDepartment !== '' ? $submittedDepartment : 'Unassigned',
                        $category,
                        $details
                    );
                } catch (Throwable $exception) {
                    // Keep complaint submission successful even if one email fails.
                }
            }
            $userEmail = trim((string) ($_SESSION['user']['email'] ?? ''));
            $userFullName = trim((string) ($_SESSION['user']['full_name'] ?? $_SESSION['user']['staff_id'] ?? 'User'));

            if ($userEmail !== '') {
                try {
                    send_user_complaint_confirmation(
                        $userEmail,
                        $userFullName,
                        $complaintCode,
                        $category,
                        $details
                    );
                } catch (Throwable $exception) {
                    // Keep complaint submission successful even if the confirmation email fails.
                }
            }
            $message = 'Complaint submitted successfully.';
            $selectedCategory = '';
            $selectedCategoryDetail = '';
            $selectedCategorySubDetail = '';
            $categoryDetailNoteValue = '';
            $detailsValue = '';
            $problemLocationValue = '';
            $selectedDetailOptions = [];
            $selectedSubDetailOptions = [];
            $showCategoryDetail = false;
            $showCategorySubDetail = false;
            $showCategoryDetailNote = false;
            $showProblemLocation = false;
            $detailLabelText = 'Specific Problem';
            $detailPlaceholderText = 'Choose a specific problem';
            $subDetailLabelText = 'Specific Software';
            $subDetailPlaceholderText = 'Choose the software';
            $detailNoteLabelText = 'Device Name';
            $detailNotePlaceholderText = 'Tell us which device';
        }
    }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nexus IT | Submit Complaint</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide">
            <div class="header-row">
                <div>
                    <h1 class="brand">Nexus IT</h1>
                    <p class="subtitle">Submit Complaint</p>
                </div>
                <div class="header-actions">
                    <a class="button ghost" href="/user/index.php">Back to Dashboard</a>
                    <a class="button ghost" href="/logout.php">Log Out</a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert success" role="status"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" class="form" id="complaint-form">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />

                <label class="label" for="category">Problem Category</label>
                <select class="input" id="category" name="category" required>
                    <option value="" disabled <?php echo $selectedCategory === '' ? 'selected' : ''; ?>>Choose a category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $selectedCategory === $category ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div id="category-detail-wrap" style="<?php echo $showCategoryDetail ? '' : 'display: none;'; ?>">
                    <label class="label" for="category_detail"><span id="category-detail-label-text"><?php echo htmlspecialchars($detailLabelText); ?></span></label>
                    <select class="input" id="category_detail" name="category_detail" <?php echo $showCategoryDetail ? 'required' : ''; ?>>
                        <option value="" disabled <?php echo $selectedCategoryDetail === '' ? 'selected' : ''; ?>><?php echo htmlspecialchars($detailPlaceholderText); ?></option>
                        <?php foreach ($selectedDetailOptions as $detailOption): ?>
                            <option value="<?php echo htmlspecialchars($detailOption); ?>" <?php echo $selectedCategoryDetail === $detailOption ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($detailOption); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="category-sub-detail-wrap" style="<?php echo $showCategorySubDetail ? '' : 'display: none;'; ?>">
                    <label class="label" for="category_sub_detail"><span id="category-sub-detail-label-text"><?php echo htmlspecialchars($subDetailLabelText); ?></span></label>
                    <select class="input" id="category_sub_detail" name="category_sub_detail" <?php echo $showCategorySubDetail ? 'required' : ''; ?>>
                        <option value="" disabled <?php echo $selectedCategorySubDetail === '' ? 'selected' : ''; ?>><?php echo htmlspecialchars($subDetailPlaceholderText); ?></option>
                        <?php foreach ($selectedSubDetailOptions as $subDetailOption): ?>
                            <option value="<?php echo htmlspecialchars($subDetailOption); ?>" <?php echo $selectedCategorySubDetail === $subDetailOption ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subDetailOption); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="category-detail-note-wrap" style="<?php echo $showCategoryDetailNote ? '' : 'display: none;'; ?>">
                    <label class="label" for="category_detail_note"><span id="category-detail-note-label-text"><?php echo htmlspecialchars($detailNoteLabelText); ?></span></label>
                    <input
                        class="input"
                        type="text"
                        id="category_detail_note"
                        name="category_detail_note"
                        value="<?php echo htmlspecialchars($categoryDetailNoteValue); ?>"
                        placeholder="<?php echo htmlspecialchars($detailNotePlaceholderText); ?>"
                        <?php echo $showCategoryDetailNote ? 'required' : ''; ?>
                    />
                </div>

                <label class="label" for="details">Complaint Details</label>
                <textarea class="input" id="details" name="details" rows="5" required><?php echo htmlspecialchars($detailsValue); ?></textarea>

                <div id="problem-location-wrap" style="<?php echo $showProblemLocation ? '' : 'display: none;'; ?>">
                    <label class="label" for="problem_location">Location</label>
                    <input
                        class="input"
                        type="text"
                        id="problem_location"
                        name="problem_location"
                        value="<?php echo htmlspecialchars($problemLocationValue); ?>"
                        <?php echo $showProblemLocation ? 'required' : ''; ?>
                    />
                </div>

                <label class="label" for="attachment">File (optional)</label>
                <input class="input" type="file" id="attachment" name="attachment" accept=".jpg,.jpeg,.png,.pdf,application/pdf,image/jpeg,image/png" />
                <p class="hint">Allowed file types: JPG, PNG, PDF. Maximum size: 5 MB.</p>

                <button class="button" type="submit">Submit Complaint</button>
            </form>
        </section>
    </main>
    <script>
        (function () {
            const categorySelect = document.getElementById('category');
            const detailWrap = document.getElementById('category-detail-wrap');
            const detailSelect = document.getElementById('category_detail');
            const detailLabelText = document.getElementById('category-detail-label-text');
            const subDetailWrap = document.getElementById('category-sub-detail-wrap');
            const subDetailSelect = document.getElementById('category_sub_detail');
            const subDetailLabelText = document.getElementById('category-sub-detail-label-text');
            const detailNoteWrap = document.getElementById('category-detail-note-wrap');
            const detailNoteLabelText = document.getElementById('category-detail-note-label-text');
            const detailNoteInput = document.getElementById('category_detail_note');
            const problemLocationWrap = document.getElementById('problem-location-wrap');
            const problemLocationInput = document.getElementById('problem_location');
            const detailOptionsByCategory = <?php echo json_encode($categoryDetailOptionsByCategory, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
            const subDetailOptionsByCategory = <?php echo json_encode($categorySubDetailOptionsByCategory, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

            function detailLabelForCategory(category) {
                if (category === 'Hardware') return 'Hardware';
                if (category === 'Software Support') return 'Request Type';
                if (category === 'Connectivity & Network') return 'Connection Type';
                return 'Specific Problem';
            }

            function detailPlaceholderForCategory(category) {
                if (category === 'Hardware') return 'Choose the hardware';
                if (category === 'Software Support') return 'Choose the request type';
                if (category === 'Connectivity & Network') return 'Choose the connection type';
                return 'Choose a specific problem';
            }

            function subDetailLabelForCategory(category) {
                return category === 'Connectivity & Network' ? 'Issue Type' : 'Specific Software';
            }

            function subDetailPlaceholderForCategory(category) {
                return category === 'Connectivity & Network' ? 'Choose the issue type' : 'Choose the software';
            }

            function detailNoteLabelForCategory(category) {
                return category === 'Software Support' ? 'Software Name' : 'Device Name';
            }

            function detailNotePlaceholderForCategory(category) {
                return category === 'Software Support' ? 'Tell us which software' : 'Tell us which device';
            }

            function syncConditionalFields() {
                const category = categorySelect.value;
                const selectedDetail = detailSelect.value;
                const selectedSubDetail = subDetailSelect.value;
                const needsDetailNote = (category === 'Hardware' && selectedDetail === 'Other') || (category === 'Software Support' && selectedSubDetail === 'Other');
                const needsLocation = category === 'Hardware' || category === 'Connectivity & Network';

                detailNoteWrap.style.display = needsDetailNote ? '' : 'none';
                detailNoteInput.required = needsDetailNote;
                detailNoteInput.disabled = !needsDetailNote;
                if (detailNoteLabelText) {
                    detailNoteLabelText.textContent = detailNoteLabelForCategory(category);
                }
                detailNoteInput.placeholder = detailNotePlaceholderForCategory(category);
                if (!needsDetailNote) {
                    detailNoteInput.value = '';
                }

                problemLocationWrap.style.display = needsLocation ? '' : 'none';
                problemLocationInput.required = needsLocation;
                problemLocationInput.disabled = !needsLocation;
                if (!needsLocation) {
                    problemLocationInput.value = '';
                }
            }

            function syncCategorySubDetail() {
                const category = categorySelect.value;
                const selectedDetail = detailSelect.value;
                const subDetailOptions = ((subDetailOptionsByCategory[category] || {})[selectedDetail]) || [];
                const currentSubDetail = subDetailOptions.includes(subDetailSelect.value) ? subDetailSelect.value : '';
                const needsSubDetail = subDetailOptions.length > 0;

                subDetailSelect.innerHTML = '';
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.disabled = true;
                placeholder.textContent = subDetailPlaceholderForCategory(category);
                subDetailSelect.appendChild(placeholder);

                subDetailOptions.forEach(function (subDetailOption) {
                    const option = document.createElement('option');
                    option.value = subDetailOption;
                    option.textContent = subDetailOption;
                    subDetailSelect.appendChild(option);
                });

                subDetailWrap.style.display = needsSubDetail ? '' : 'none';
                subDetailSelect.required = needsSubDetail;
                subDetailSelect.disabled = !needsSubDetail;
                if (subDetailLabelText) {
                    subDetailLabelText.textContent = subDetailLabelForCategory(category);
                }

                if (!needsSubDetail) {
                    subDetailSelect.value = '';
                } else if (currentSubDetail !== '') {
                    subDetailSelect.value = currentSubDetail;
                } else {
                    subDetailSelect.value = '';
                }

                syncConditionalFields();
            }

            function syncCategoryDetail() {
                const category = categorySelect.value;
                const detailOptions = detailOptionsByCategory[category] || [];
                const currentDetail = detailOptions.includes(detailSelect.value) ? detailSelect.value : '';
                const needsDetail = detailOptions.length > 0;

                detailSelect.innerHTML = '';
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.disabled = true;
                placeholder.textContent = detailPlaceholderForCategory(category);
                detailSelect.appendChild(placeholder);

                detailOptions.forEach(function (detailOption) {
                    const option = document.createElement('option');
                    option.value = detailOption;
                    option.textContent = detailOption;
                    detailSelect.appendChild(option);
                });

                detailWrap.style.display = needsDetail ? '' : 'none';
                detailSelect.required = needsDetail;
                detailSelect.disabled = !needsDetail;
                if (detailLabelText) {
                    detailLabelText.textContent = detailLabelForCategory(category);
                }

                if (!needsDetail) {
                    detailSelect.value = '';
                } else if (currentDetail !== '') {
                    detailSelect.value = currentDetail;
                } else {
                    detailSelect.value = '';
                }

                syncCategorySubDetail();
            }

            syncCategoryDetail();
            categorySelect.addEventListener('change', syncCategoryDetail);
            detailSelect.addEventListener('change', syncCategorySubDetail);
            subDetailSelect.addEventListener('change', syncConditionalFields);
        })();
    </script>
</body>
</html>
