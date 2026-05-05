<?php
require_once __DIR__ . '/../bootstrap.php';
require_admin();

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

$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_check($token)) {
        $error = 'Invalid session token. Please try again.';
    } else {
        $questions = $_POST['question'] ?? [];
        $answers = $_POST['answer'] ?? [];
        $items = [];

        foreach ($questions as $index => $question) {
            $q = trim((string) $question);
            $a = trim((string) ($answers[$index] ?? ''));
            if ($q === '' && $a === '') {
                continue;
            }
            if ($q === '' || $a === '') {
                $error = 'Every FAQ item needs both a question and an answer.';
                break;
            }
            $items[] = ['question' => $q, 'answer' => $a];
        }

        if (!$error && empty($items)) {
            $error = 'Please add at least one FAQ item.';
        } else {
            $dir = dirname($faqFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            if (!$error) {
                file_put_contents($faqFile, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $notice = 'FAQ updated successfully.';
            }
        }
    }
}

$currentFaq = $defaultFaq;
if (is_file($faqFile)) {
    $storedFaq = json_decode((string) file_get_contents($faqFile), true);
    if (is_array($storedFaq) && $storedFaq) {
        $currentFaq = $storedFaq;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nexus IT | Edit FAQ</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide dashboard">
            <div class="header-row">
                <div>
                    <p class="eyebrow">Nexus IT</p>
                    <h1 class="brand">Edit FAQ</h1>
                    <p class="subtitle">Update the FAQ content shown to users before they submit a complaint.</p>
                </div>
                <div class="header-actions">
                    <a class="button ghost" href="/admin/index.php">Back to Dashboard</a>
                </div>
            </div>

            <?php if ($notice): ?>
                <div class="alert success"><?php echo htmlspecialchars($notice); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form class="form" method="POST" id="faq-form">
                <label class="label">FAQ Items</label>
                <div id="faq-items">
                    <?php foreach ($currentFaq as $item): ?>
                        <div class="faq-item">
                            <input class="input" type="text" name="question[]" value="<?php echo htmlspecialchars($item['question'] ?? ''); ?>" placeholder="Question" required />
                            <textarea class="input" name="answer[]" rows="3" placeholder="Answer" required><?php echo htmlspecialchars($item['answer'] ?? ''); ?></textarea>
                            <button class="button ghost small remove-faq" type="button">Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="button ghost small" type="button" id="add-faq">Add FAQ Item</button>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>" />
                <div>
                    <button class="button" type="submit">Save FAQ</button>
                    <a class="button ghost" href="/admin/index.php">Cancel</a>
                </div>
            </form>
        </section>
    </main>

    <template id="faq-template">
        <div class="faq-item">
            <input class="input" type="text" name="question[]" placeholder="Question" required />
            <textarea class="input" name="answer[]" rows="3" placeholder="Answer" required></textarea>
            <button class="button ghost small remove-faq" type="button">Remove</button>
        </div>
    </template>

    <script>
        const addBtn = document.getElementById('add-faq');
        const list = document.getElementById('faq-items');
        const template = document.getElementById('faq-template');

        function bindRemove(button) {
            button.addEventListener('click', () => {
                const item = button.closest('.faq-item');
                if (item) item.remove();
            });
        }

        document.querySelectorAll('.remove-faq').forEach(bindRemove);

        addBtn.addEventListener('click', () => {
            const clone = template.content.cloneNode(true);
            const removeBtn = clone.querySelector('.remove-faq');
            bindRemove(removeBtn);
            list.appendChild(clone);
        });
    </script>
</body>
</html>
