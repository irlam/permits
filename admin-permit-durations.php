<?php
/**
 * Permit Duration Presets Manager
 *
 * Dedicated admin UI for maintaining the preset duration options
 * exposed on the permit issuance screens.
 */

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/permit-durations.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$stmt = $db->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || $currentUser['role'] !== 'admin') {
    http_response_code(403);
    echo '<h1>Access denied</h1><p>Administrator role required.</p>';
    exit;
}

$successMessage = '';
$errorMessage = '';

$durationPresets = getPermitDurationPresets($db);
$durationFormRows = $durationPresets;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_durations') {
        $labels = isset($_POST['duration_label']) ? (array)$_POST['duration_label'] : [];
        $minutes = isset($_POST['duration_minutes']) ? (array)$_POST['duration_minutes'] : [];

        $submittedPresets = buildPermitDurationPresetsFromInput($labels, $minutes);
        $durationFormRows = $submittedPresets;

        $normalizedPresets = normalizePermitDurationPresets($submittedPresets);

        if (empty($normalizedPresets)) {
            $errorMessage = 'Please add at least one duration with a label and minutes greater than zero.';
        } else {
            try {
                savePermitDurationPresets($db, $normalizedPresets);
                $durationPresets = $normalizedPresets;
                $durationFormRows = $normalizedPresets;
                $successMessage = 'Permit duration presets updated.';

                if (function_exists('logActivity')) {
                    logActivity(
                        'settings_updated',
                        'admin',
                        'setting',
                        'permit_duration_presets',
                        'Permit duration presets updated via dedicated admin page.'
                    );
                }
            } catch (\Throwable $e) {
                $errorMessage = 'Unable to update duration presets: ' . $e->getMessage();
            }
        }
    }
}

$durationFormRows = $durationFormRows ?: [['label' => '', 'minutes' => 60]];

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Permit Duration Presets</title>
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: system-ui, -apple-system, sans-serif; min-height: 100vh; margin: 0; }
        .wrap { max-width: 900px; margin: 0 auto; padding: 32px 16px 80px; }
        h1 { font-size: 28px; margin-bottom: 8px; }
        p.lead { color: #94a3b8; margin-bottom: 24px; }
        a.back { color: #60a5fa; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 24px; }
        a.back:hover { text-decoration: underline; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 24px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.35); }
        .duration-grid { display: flex; flex-direction: column; gap: 16px; margin-top: 20px; }
        .duration-row { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; background: #0f172a; border: 1px solid #1f2937; border-radius: 12px; padding: 18px; }
        .duration-row .field-group { flex: 1 1 220px; display: flex; flex-direction: column; gap: 6px; }
        .duration-row label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .duration-row input { padding: 11px; border-radius: 10px; border: 1px solid #1f2937; background: #111827; color: #e2e8f0; }
        .duration-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 20px; }
        .btn { background: #3b82f6; border: none; color: white; padding: 12px 22px; border-radius: 10px; font-weight: 600; cursor: pointer; }
        .btn:hover { background: #2563eb; }
        .btn-secondary { background: #475569; }
        .btn-secondary:hover { background: #64748b; }
        .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: rgba(34, 197, 94, 0.12); border: 1px solid rgba(34, 197, 94, 0.45); color: #bbf7d0; }
        .alert-error { background: rgba(248, 113, 113, 0.12); border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; }
        form { margin-top: 16px; }
        @media (max-width: 640px) {
            .duration-row { flex-direction: column; align-items: stretch; }
            .duration-actions { flex-direction: column; align-items: stretch; }
            .btn, .btn-secondary { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <a class="back" href="/admin.php">⬅ Back to Admin</a>
        <h1>Permit Duration Presets</h1>
        <p class="lead">Configure the quick-select expiry options offered when issuing permits. Keep choices aligned with your organisation's standards.</p>

        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="post">
                <input type="hidden" name="action" value="update_durations">
                <div id="duration-rows" class="duration-grid">
                    <?php foreach ($durationFormRows as $preset): ?>
                        <div class="duration-row">
                            <div class="field-group">
                                <label>Label</label>
                                <input type="text" name="duration_label[]" value="<?= htmlspecialchars($preset['label'] ?? '') ?>" placeholder="e.g. 1 hour" required>
                            </div>
                            <div class="field-group">
                                <label>Minutes</label>
                                <input type="number" name="duration_minutes[]" value="<?= htmlspecialchars((string)($preset['minutes'] ?? '')) ?>" min="1" placeholder="60" required>
                            </div>
                            <button type="button" class="btn-secondary remove-duration">Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="duration-actions">
                    <button type="button" class="btn-secondary" id="add-duration">Add another duration</button>
                    <button type="submit" class="btn">Save Presets</button>
                </div>
            </form>
        </div>
    </div>

    <template id="duration-row-template">
        <div class="duration-row">
            <div class="field-group">
                <label>Label</label>
                <input type="text" name="duration_label[]" placeholder="e.g. 1 day" required>
            </div>
            <div class="field-group">
                <label>Minutes</label>
                <input type="number" name="duration_minutes[]" min="1" placeholder="1440" required>
            </div>
            <button type="button" class="btn-secondary remove-duration">Remove</button>
        </div>
    </template>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var addButton = document.getElementById('add-duration');
        var rowsContainer = document.getElementById('duration-rows');
        var template = document.getElementById('duration-row-template');

        if (!addButton || !rowsContainer || !template) {
            return;
        }

        addButton.addEventListener('click', function () {
            var clone = template.content.cloneNode(true);
            rowsContainer.appendChild(clone);
        });

        rowsContainer.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (target.classList.contains('remove-duration')) {
                var row = target.closest('.duration-row');
                if (!row) {
                    return;
                }

                if (rowsContainer.children.length > 1) {
                    row.remove();
                } else {
                    row.querySelectorAll('input').forEach(function (input) {
                        input.value = '';
                    });
                }
            }
        });
    });
    </script>
</body>
</html>
