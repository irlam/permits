<?php
/**
 * Custom Permit Creator (Admin)
 *
 * Allows administrators to spin up a custom permit template by either
 * cloning an existing template or starting from a minimal blank schema.
 * After saving, the admin is redirected straight to the standard form
 * renderer so they can begin issuing the permit immediately.
 */

require __DIR__ . '/vendor/autoload.php';
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

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

$errors = [];
$success = '';

// Load templates for cloning options
try {
    $templates = $db->pdo->query('SELECT id, name, version FROM form_templates ORDER BY name ASC, version DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $templates = [];
    $errors[] = 'Unable to load existing templates: ' . $e->getMessage();
}

function slugify_template_id(string $value): string
{
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
    $value = trim($value, '-');
    if ($value !== '') {
        return $value;
    }

    try {
        return 'custom-' . substr(bin2hex(random_bytes(3)), 0, 6);
    } catch (Throwable $e) {
        return 'custom-' . substr(preg_replace('/[^a-f0-9]/', '', uniqid('', true)), 0, 6);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $templateName = trim($_POST['template_name'] ?? '');
    $templateIdInput = trim($_POST['template_id'] ?? '');
    $baseTemplateId = $_POST['base_template'] ?? '__blank';
    $version = max(1, (int)($_POST['version'] ?? 1));
    $copyMeta = isset($_POST['copy_meta']);
    $copySections = isset($_POST['copy_sections']);

    if ($templateName === '') {
        $errors[] = 'Template name is required.';
    }

    if (empty($errors)) {
        $templateId = $templateIdInput !== '' ? slugify_template_id($templateIdInput) : slugify_template_id($templateName);

        if (!preg_match('/^[a-z0-9\-]{3,}$/', $templateId)) {
            $errors[] = 'Template ID must contain letters, numbers or hyphens (min 3 characters).';
        }

        $schema = null;

        if (empty($errors)) {
            if ($baseTemplateId !== '__blank') {
                $baseStmt = $db->pdo->prepare('SELECT json_schema FROM form_templates WHERE id = ? LIMIT 1');
                $baseStmt->execute([$baseTemplateId]);
                $base = $baseStmt->fetch(PDO::FETCH_ASSOC);

                if (!$base) {
                    $errors[] = 'Selected base template could not be found.';
                } else {
                    $decoded = json_decode($base['json_schema'] ?? '[]', true);
                    if (!is_array($decoded)) {
                        $errors[] = 'Base template JSON could not be parsed.';
                    } else {
                        // Create a clean clone while optionally trimming meta/sections
                        $schema = $decoded;
                        $schema['id'] = $templateId;
                        $schema['title'] = $templateName;
                        $schema['version'] = $version;

                        if (!$copyMeta) {
                            $schema['meta'] = ['fields' => []];
                        }

                        if (!$copySections) {
                            $schema['sections'] = [];
                        }
                    }
                }
            } else {
                $schema = [
                    'id' => $templateId,
                    'title' => $templateName,
                    'version' => $version,
                    'meta' => [
                        'fields' => [
                            ['key' => 'permitNo', 'label' => 'Permit Number', 'type' => 'text'],
                            ['key' => 'validFrom', 'label' => 'Valid From', 'type' => 'datetime'],
                            ['key' => 'validTo', 'label' => 'Valid To', 'type' => 'datetime'],
                        ],
                    ],
                    'sections' => [],
                    'signatures' => ['issuer_issue', 'holder_issue', 'holder_return', 'issuer_return'],
                ];
            }
        }

        if (empty($errors) && !$schema) {
            $errors[] = 'Unable to build schema for new template.';
        }

        if (empty($errors) && $schema) {
            $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            try {
                $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'mysql') {
                    $sql = 'INSERT INTO form_templates (id, name, version, json_schema, created_by, published_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                            ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), json_schema = VALUES(json_schema), updated_at = NOW()';
                } else {
                    $sql = 'INSERT INTO form_templates (id, name, version, json_schema, created_by, published_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, datetime("now"), datetime("now"))
                            ON CONFLICT(id) DO UPDATE SET name = excluded.name, version = excluded.version, json_schema = excluded.json_schema, updated_at = datetime("now")';
                }

                $createdBy = $currentUser['email'] ?? ($currentUser['name'] ?? 'admin');
                $stmt = $db->pdo->prepare($sql);
                $stmt->execute([$templateId, $templateName, $version, $schemaJson, $createdBy]);

                if (function_exists('logActivity')) {
                    logActivity(
                        'template_created',
                        'admin',
                        'form_template',
                        $templateId,
                        sprintf('Custom template %s (%s) created via admin UI.', $templateName, $templateId)
                    );
                }

                header('Location: /new/' . urlencode($templateId) . '?source=custom');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Unable to save template: ' . $e->getMessage();
            }
        }
    }
}

$copyMetaChecked = $_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['copy_meta']);
$copySectionsChecked = $_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['copy_sections']);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Custom Permit Creator</title>
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: system-ui, -apple-system, sans-serif; min-height: 100vh; margin: 0; }
        .wrap { max-width: 880px; margin: 0 auto; padding: 32px 16px 64px; }
        h1 { font-size: 28px; margin-bottom: 12px; }
        p.lead { color: #94a3b8; margin-bottom: 24px; }
        a.back { color: #60a5fa; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 24px; }
        a.back:hover { text-decoration: underline; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 24px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.35); }
        .grid { display: grid; gap: 20px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); margin-top: 24px; }
        label { display: block; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; color: #94a3b8; }
        input[type="text"], input[type="number"], select, textarea { width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid #334155; background: #111827; color: #e2e8f0; font-size: 15px; }
        input[type="text"]:focus, input[type="number"]:focus, select:focus, textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25); }
        textarea { resize: vertical; min-height: 120px; }
        .form-row { margin-bottom: 18px; }
        .options { background: #0f172a; border: 1px solid #1f2937; border-radius: 12px; padding: 16px; }
        .options legend { font-size: 14px; font-weight: 600; margin-bottom: 12px; color: #f8fafc; }
        .options label { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; text-transform: none; letter-spacing: normal; }
        .options input[type="checkbox"] { width: 18px; height: 18px; }
        .actions { margin-top: 24px; display: flex; justify-content: flex-end; gap: 12px; }
        .btn { background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%); border: none; color: white; padding: 12px 24px; border-radius: 10px; font-weight: 600; cursor: pointer; }
        .btn:hover { opacity: 0.92; }
        .muted { color: #64748b; font-size: 13px; margin-top: 6px; }
        .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; }
        .alert.error { background: rgba(248, 113, 113, 0.12); border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; }
        .alert.success { background: rgba(34, 197, 94, 0.12); border: 1px solid rgba(34, 197, 94, 0.4); color: #bbf7d0; }
    </style>
</head>
<body>
    <div class="wrap">
        <a href="/admin.php" class="back">← Back to Admin</a>
        <h1>Custom Permit Creator</h1>
        <p class="lead">Clone an existing template or start from a blank layout. After saving, you'll land on the permit form so you can begin issuing immediately.</p>

        <?php if (!empty($errors)): ?>
            <div class="alert error">
                <strong>We hit a problem:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <form method="post">
                <div class="form-row">
                    <label for="template_name">Template Name *</label>
                    <input type="text" id="template_name" name="template_name" placeholder="e.g. Hot Works - Night Shift" required value="<?= htmlspecialchars($_POST['template_name'] ?? '') ?>">
                    <p class="muted">This label appears throughout the app.</p>
                </div>

                <div class="grid">
                    <div class="form-row">
                        <label for="template_id">Template ID</label>
                        <input type="text" id="template_id" name="template_id" placeholder="auto-generated if left blank" value="<?= htmlspecialchars($_POST['template_id'] ?? '') ?>">
                        <p class="muted">Lowercase letters, numbers and hyphens only. We'll slugify it for you.</p>
                    </div>
                    <div class="form-row">
                        <label for="version">Version</label>
                        <input type="number" id="version" name="version" min="1" value="<?= htmlspecialchars($_POST['version'] ?? '1') ?>">
                        <p class="muted">Increment when you update this template later.</p>
                    </div>
                </div>

                <div class="form-row">
                    <label for="base_template">Start From</label>
                    <select id="base_template" name="base_template">
                        <option value="__blank">Blank template</option>
                        <?php foreach ($templates as $tpl): ?>
                            <option value="<?= htmlspecialchars($tpl['id']) ?>" <?= (($_POST['base_template'] ?? '') === $tpl['id']) ? 'selected' : '' ?>><?= htmlspecialchars($tpl['name']) ?> (v<?= (int)$tpl['version'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="muted">Clone a template to reuse its structure or start fresh.</p>
                </div>

                <fieldset class="options">
                    <legend>Clone Options</legend>
                    <label>
                        <input type="checkbox" name="copy_meta" value="1" <?= $copyMetaChecked ? 'checked' : '' ?>>
                        Copy metadata fields (holder info, dates, etc.)
                    </label>
                    <label>
                        <input type="checkbox" name="copy_sections" value="1" <?= $copySectionsChecked ? 'checked' : '' ?>>
                        Copy checklist sections and items
                    </label>
                    <p class="muted">Uncheck options to start with empty sections when cloning.</p>
                </fieldset>

                <div class="actions">
                    <button type="submit" class="btn">Create Template &amp; Continue →</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
