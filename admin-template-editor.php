<?php
/**
 * Permit Template Editor (Admin)
 */

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

function formatDateForDisplay(?string $value): string
{
    if (empty($value)) {
        return '—';
    }

    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d H:i');
    } catch (Throwable $e) {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function prettyPrintJson(?string $json): string
{
    if ($json === null || $json === '') {
        return '';
    }

    $decoded = json_decode($json, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    return $json;
}

$messages = [];
$errors = [];
$supportsFormStructure = false;

try {
    $db->pdo->query('SELECT form_structure FROM form_templates LIMIT 1');
    $supportsFormStructure = true;
} catch (Throwable $e) {
    $supportsFormStructure = false;
}

$activeTemplateId = null;
$formName = '';
$formVersion = '';
$schemaText = '';
$structureText = '';
$schemaArray = null;
$schemaDecodeError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $activeTemplateId = trim($_POST['template_id'] ?? '');
    $formName = trim($_POST['name'] ?? '');
    $formVersion = trim($_POST['version'] ?? '');
    $schemaInput = trim($_POST['json_schema'] ?? '');
    $structureInput = trim($_POST['form_structure'] ?? '');

    if ($activeTemplateId === '') {
        $errors[] = 'Template identifier missing.';
    }

    if ($formName === '') {
        $errors[] = 'Template name is required.';
    }

    if ($formVersion === '' || !ctype_digit($formVersion) || (int)$formVersion < 1) {
        $errors[] = 'Version must be a positive whole number.';
    }

    if ($schemaInput === '') {
        $errors[] = 'Template schema cannot be empty.';
    }

    $decodedSchema = null;
    if (empty($errors)) {
        $decodedSchema = json_decode($schemaInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'Schema JSON is invalid: ' . json_last_error_msg();
        }
    }

    $decodedStructure = null;
    if ($supportsFormStructure && $structureInput !== '') {
        $decodedStructure = json_decode($structureInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'Form structure JSON is invalid: ' . json_last_error_msg();
        }
    }

    $templateRow = null;
    if (empty($errors)) {
        $lookup = $db->pdo->prepare('SELECT id FROM form_templates WHERE id = ? LIMIT 1');
        $lookup->execute([$activeTemplateId]);
        $templateRow = $lookup->fetch(PDO::FETCH_ASSOC);

        if (!$templateRow) {
            $errors[] = 'Template not found.';
        }
    }

    if (empty($errors)) {
        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $timestampExpr = $driver === 'mysql' ? 'NOW()' : "datetime('now')";

        $nameValue = $formName;
        $versionValue = (int)$formVersion;
        $schemaValue = json_encode($decodedSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $structureValue = null;

        if ($supportsFormStructure) {
            $structureValue = $structureInput === ''
                ? null
                : json_encode($decodedStructure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($supportsFormStructure) {
            $sql = "UPDATE form_templates SET name = ?, version = ?, json_schema = ?, form_structure = ?, updated_at = $timestampExpr WHERE id = ?";
            $params = [$nameValue, $versionValue, $schemaValue, $structureValue, $activeTemplateId];
        } else {
            $sql = "UPDATE form_templates SET name = ?, version = ?, json_schema = ?, updated_at = $timestampExpr WHERE id = ?";
            $params = [$nameValue, $versionValue, $schemaValue, $activeTemplateId];
        }

        $update = $db->pdo->prepare($sql);
        $update->execute($params);

        if (function_exists('logActivity')) {
            logActivity(
                'template_updated',
                'admin',
                'form_template',
                $activeTemplateId,
                sprintf('Template %s updated via admin UI by %s (%s).', $activeTemplateId, $currentUser['name'] ?? 'admin', $currentUser['email'] ?? '')
            );
        }

        header('Location: /admin-template-editor.php?template=' . urlencode($activeTemplateId) . '&updated=1');
        exit;
    } else {
        $schemaText = $schemaInput;
        $structureText = $structureInput;
    }
} else {
    $activeTemplateId = isset($_GET['template']) ? trim($_GET['template']) : null;
    if (isset($_GET['updated'])) {
        $messages[] = 'Template updated successfully.';
    }
}

$listStmt = $db->pdo->query('SELECT id, name, version, updated_at, published_at FROM form_templates ORDER BY name ASC');
$templates = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$editingTemplate = null;
if ($activeTemplateId !== null && $activeTemplateId !== '') {
    $editStmt = $db->pdo->prepare('SELECT * FROM form_templates WHERE id = ? LIMIT 1');
    $editStmt->execute([$activeTemplateId]);
    $editingTemplate = $editStmt->fetch(PDO::FETCH_ASSOC);

    if ($editingTemplate) {
        $schemaArray = json_decode($editingTemplate['json_schema'] ?? '', true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $schemaDecodeError = json_last_error_msg();
            $schemaArray = null;
        }

        if ($formName === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $formName = $editingTemplate['name'] ?? '';
        }
        if ($formVersion === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $formVersion = (string)($editingTemplate['version'] ?? '');
        }
        if ($schemaText === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $schemaText = prettyPrintJson($editingTemplate['json_schema'] ?? '');
        }
        if ($supportsFormStructure) {
            if ($structureText === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
                $structureText = prettyPrintJson($editingTemplate['form_structure'] ?? '');
            }
        } else {
            $structureText = '';
        }

        if ($schemaArray === null && $schemaDecodeError !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $errors[] = 'We could not load this template into the visual editor. Please review the raw JSON below.';
        }
    } else {
        if (!in_array('Template not found.', $errors, true)) {
            $errors[] = 'Selected template could not be loaded.';
        }
        $activeTemplateId = null;
    }
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Edit Permit Templates</title>
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: system-ui, -apple-system, sans-serif; min-height: 100vh; margin: 0; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 32px 16px 96px; }
        a.back { color: #60a5fa; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 24px; }
        a.back:hover { text-decoration: underline; }
        h1 { font-size: 28px; margin-bottom: 12px; }
        p.lead { color: #94a3b8; margin-bottom: 24px; }
        .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: rgba(34, 197, 94, 0.12); border: 1px solid rgba(34, 197, 94, 0.45); color: #bbf7d0; }
        .alert-error { background: rgba(248, 113, 113, 0.16); border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; }
        .layout { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 24px; align-items: start; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 24px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.35); }
        .card-list { position: sticky; top: 32px; }
        .template-list { display: flex; flex-direction: column; gap: 12px; margin: 0; padding: 0; list-style: none; }
        .template-button { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; padding: 14px 16px; border-radius: 14px; border: 1px solid transparent; background: rgba(15, 23, 42, 0.6); color: inherit; text-decoration: none; transition: all 0.15s ease-in-out; }
        .template-button:hover { border-color: rgba(59, 130, 246, 0.6); background: rgba(59, 130, 246, 0.12); }
        .template-button.active { border-color: #3b82f6; background: rgba(59, 130, 246, 0.18); box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.45); }
        .template-title { font-weight: 600; font-size: 15px; }
        .template-meta { display: flex; gap: 10px; align-items: center; font-size: 12px; color: #94a3b8; }
        .tag { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 999px; background: rgba(148, 163, 184, 0.18); color: #e2e8f0; font-size: 12px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 10px 16px; border-radius: 10px; border: none; cursor: pointer; font-weight: 600; background: #3b82f6; color: #fff; text-decoration: none; font-size: 14px; }
        .btn:hover { background: #2563eb; }
        .btn-secondary { background: rgba(148, 163, 184, 0.2); color: #e2e8f0; }
        .btn-secondary:hover { background: rgba(148, 163, 184, 0.35); }
        form label { display: block; font-weight: 600; margin-bottom: 8px; color: #cbd5f5; }
        form input[type="text"], form input[type="number"], form textarea { width: 100%; background: #0f172a; border: 1px solid #334155; border-radius: 10px; color: #e2e8f0; padding: 10px 12px; font-family: 'JetBrains Mono', SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 13px; }
        form input[type="text"]:focus, form input[type="number"]:focus, form textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25); }
    form textarea { min-height: 160px; resize: vertical; }
    form textarea.textarea-large { min-height: 320px; }
        form select { width: 100%; background: #0f172a; border: 1px solid #334155; border-radius: 10px; color: #e2e8f0; padding: 10px 12px; font-size: 13px; }
        form select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25); }
        .field-group { margin-bottom: 20px; }
        .field-inline { display: flex; gap: 16px; }
        .field-inline .field-group { flex: 1; }
        .helper { color: #94a3b8; font-size: 13px; margin-top: 6px; }
        .empty { color: #94a3b8; font-size: 14px; text-align: center; padding: 16px 0; }
        .editor-stack { display: flex; flex-direction: column; gap: 24px; }
        .editor-panel { background: rgba(15, 23, 42, 0.6); border: 1px solid #2d3a54; border-radius: 14px; padding: 20px; display: flex; flex-direction: column; gap: 20px; }
        .panel-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
        .panel-header h3 { margin: 0; font-size: 18px; color: #e2e8f0; }
        .panel-header p { margin: 4px 0 0; color: #94a3b8; font-size: 13px; }
        .panel-header .actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .grid-two { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .grid-three { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; }
    .field-card-list, .section-list, .signature-list { display: flex; flex-direction: column; gap: 14px; }
    .field-card, .section-card { background: rgba(15, 23, 42, 0.7); border: 1px solid #334155; border-radius: 12px; padding: 16px; display: flex; flex-direction: column; gap: 16px; }
        .field-card-header, .section-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
        .field-card-title, .section-title { font-weight: 600; font-size: 15px; color: #e2e8f0; }
        .field-card-actions, .section-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .btn-icon { background: rgba(148, 163, 184, 0.18); border: 1px solid rgba(148, 163, 184, 0.35); color: #e2e8f0; border-radius: 8px; padding: 6px 10px; font-size: 12px; cursor: pointer; }
        .btn-icon:hover { background: rgba(148, 163, 184, 0.3); }
    .chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; background: rgba(148, 163, 184, 0.18); border: 1px solid rgba(148, 163, 184, 0.35); font-size: 12px; color: #cbd5f5; }
        .field-options, .field-advanced { background: rgba(15, 23, 42, 0.55); border: 1px dashed rgba(148, 163, 184, 0.4); border-radius: 10px; padding: 12px; display: flex; flex-direction: column; gap: 12px; }
        .field-options h4, .field-advanced h4 { margin: 0; font-size: 13px; color: #cbd5f5; }
        .option-list { display: flex; flex-direction: column; gap: 10px; }
        .option-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; gap: 12px; align-items: center; }
        .option-row input { padding: 8px 10px; }
    .item-list { display: flex; flex-direction: column; gap: 10px; }
        .item-row, .signature-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: center; }
        .empty-note { border: 1px dashed rgba(148, 163, 184, 0.35); border-radius: 12px; padding: 14px 16px; text-align: center; font-size: 13px; color: #94a3b8; }
        .switch { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: #cbd5f5; }
        .switch input { width: auto; }
        .form-footer { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-top: 20px; }
        details.advanced-toggle { background: rgba(15, 23, 42, 0.6); border: 1px dashed rgba(148, 163, 184, 0.35); border-radius: 12px; padding: 16px; color: #cbd5f5; }
        details.advanced-toggle summary { cursor: pointer; font-weight: 600; margin-bottom: 10px; list-style: none; }
        details.advanced-toggle summary::marker { display: none; }
        .inline-note { font-size: 13px; color: #94a3b8; }
        .alert-inline { display: none; }
        @media (max-width: 960px) {
            .layout { grid-template-columns: 1fr; }
            .card-list { position: static; }
            .panel-header { flex-direction: column; align-items: flex-start; }
            .field-card-actions, .section-actions { width: 100%; justify-content: flex-start; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <a class="back" href="/admin.php">⬅ Back to Admin</a>
        <h1>Edit Permit Templates</h1>
        <p class="lead">Adjust template names, versions, and JSON payloads so each scenario matches the questions you need.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endforeach; ?>

        <div class="layout">
            <div class="card card-list">
                <h2 style="margin-bottom:16px; font-size:20px;">Available Templates</h2>
                <?php if (!$templates): ?>
                    <p class="empty">No templates found. Run the importer first.</p>
                <?php else: ?>
                    <ul class="template-list">
                        <?php foreach ($templates as $template): ?>
                            <?php
                                $isActive = $template['id'] === $activeTemplateId;
                                $class = $isActive ? 'template-button active' : 'template-button';
                            ?>
                            <li>
                                <a class="<?php echo $class; ?>" href="/admin-template-editor.php?template=<?php echo urlencode($template['id']); ?>">
                                    <span class="template-title"><?php echo htmlspecialchars($template['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                    <div class="template-meta">
                                        <span class="tag">v<?php echo htmlspecialchars((string)$template['version'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                        <span><?php echo htmlspecialchars($template['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                        <span>Updated <?php echo formatDateForDisplay($template['updated_at'] ?? null); ?></span>
                                    </div>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="card">
                <?php if ($editingTemplate): ?>
                    <?php $schemaTitleValue = is_array($schemaArray) ? ($schemaArray['title'] ?? '') : ''; ?>
                    <h2 style="margin-bottom:16px; font-size:20px;">Editing: <code><?php echo htmlspecialchars($editingTemplate['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code></h2>

                    <?php if ($schemaArray === null && $schemaDecodeError !== null): ?>
                        <p class="inline-note" style="margin-bottom:16px;">We could not convert this template into the visual editor yet, so the raw JSON editor is shown below.</p>
                        <form method="post" novalidate>
                            <input type="hidden" name="template_id" value="<?php echo htmlspecialchars($editingTemplate['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <div class="field-inline">
                                <div class="field-group">
                                    <label for="name">Template Name</label>
                                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($formName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                                    <p class="helper">Displayed to users when selecting the template.</p>
                                </div>
                                <div class="field-group" style="max-width:160px;">
                                    <label for="version">Version</label>
                                    <input type="number" id="version" name="version" min="1" value="<?php echo htmlspecialchars($formVersion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                                    <p class="helper">Increase when publishing changes.</p>
                                </div>
                            </div>

                            <div class="field-group">
                                <label for="json_schema">Template JSON Schema</label>
                                <textarea id="json_schema" name="json_schema" spellcheck="false" required><?php echo htmlspecialchars($schemaText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                                <p class="helper">Full schema used for permit creation. Must be valid JSON.</p>
                            </div>

                            <?php if ($supportsFormStructure): ?>
                            <div class="field-group">
                                <label for="form_structure">Public Form Structure (optional)</label>
                                <textarea id="form_structure" name="form_structure" spellcheck="false"><?php echo htmlspecialchars($structureText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                                <p class="helper">Leave blank to keep the fallback structure generated from the schema.</p>
                            </div>
                            <?php endif; ?>

                            <div class="form-footer">
                                <button type="submit" class="btn">Save Changes</button>
                                <a class="btn btn-secondary" href="/admin-template-editor.php">Cancel</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <form id="template-editor-form" method="post" novalidate>
                            <input type="hidden" name="template_id" value="<?php echo htmlspecialchars($editingTemplate['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <div id="ui-errors" class="alert alert-error alert-inline"></div>

                            <div class="editor-stack" id="template-editor-root">
                                <section class="editor-panel">
                                    <div class="panel-header">
                                        <div>
                                            <h3>Template Basics</h3>
                                            <p>Set how this permit appears to your team and track versions safely.</p>
                                        </div>
                                        <div class="actions">
                                            <span class="chip">ID: <?php echo htmlspecialchars($editingTemplate['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                            <span class="chip">Last updated <?php echo htmlspecialchars(formatDateForDisplay($editingTemplate['updated_at'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                        </div>
                                    </div>
                                    <div class="grid-two">
                                        <div class="field-group">
                                            <label for="name">Template Name</label>
                                            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($formName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                                            <p class="helper">Shown in the admin area when selecting a template.</p>
                                        </div>
                                        <div class="field-group">
                                            <label for="schema_title">Permit Title</label>
                                            <input type="text" id="schema_title" name="schema_title" value="<?php echo htmlspecialchars($schemaTitleValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                                            <p class="helper">Displayed to permit issuers across the system.</p>
                                        </div>
                                    </div>
                                    <div class="grid-three">
                                        <div class="field-group">
                                            <label for="template_id_display">Template ID</label>
                                            <input type="text" id="template_id_display" value="<?php echo htmlspecialchars($editingTemplate['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" readonly>
                                            <p class="helper">Used internally &mdash; change in database only if absolutely necessary.</p>
                                        </div>
                                        <div class="field-group">
                                            <label for="version">Version</label>
                                            <input type="number" id="version" name="version" min="1" value="<?php echo htmlspecialchars($formVersion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                                            <p class="helper">Save with a new version when you publish updates.</p>
                                        </div>
                                        <div class="field-group">
                                            <label for="schema_summary">Summary (optional)</label>
                                            <input type="text" id="schema_summary" placeholder="Short description" value="<?php echo htmlspecialchars(is_array($schemaArray) ? ($schemaArray['summary'] ?? '') : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                            <p class="helper">Appears in overviews where supported.</p>
                                        </div>
                                    </div>
                                </section>

                                <section class="editor-panel" id="meta-panel">
                                    <div class="panel-header">
                                        <div>
                                            <h3>General Questions</h3>
                                            <p>These fields collect the core details before any sections begin.</p>
                                        </div>
                                        <div class="actions">
                                            <button type="button" class="btn btn-secondary" id="add-meta-field">Add Question</button>
                                        </div>
                                    </div>
                                    <div id="meta-fields-list" class="field-card-list"></div>
                                </section>

                                <section class="editor-panel" id="sections-panel">
                                    <div class="panel-header">
                                        <div>
                                            <h3>Permit Sections</h3>
                                            <p>Group checks and additional fields by stage. Each section becomes a card for issuers.</p>
                                        </div>
                                        <div class="actions">
                                            <button type="button" class="btn btn-secondary" id="add-section">Add Section</button>
                                        </div>
                                    </div>
                                    <div id="sections-list" class="section-list"></div>
                                </section>

                                <section class="editor-panel" id="signatures-panel">
                                    <div class="panel-header">
                                        <div>
                                            <h3>Sign-off Order</h3>
                                            <p>Update the steps required to approve and close the permit.</p>
                                        </div>
                                        <div class="actions">
                                            <button type="button" class="btn btn-secondary" id="add-signature">Add Sign-off</button>
                                        </div>
                                    </div>
                                    <div id="signature-list" class="signature-list"></div>
                                    <p class="inline-note">These labels should match the roles or people expected to sign the permit.</p>
                                </section>

                                <details class="advanced-toggle">
                                    <summary>Advanced: View raw JSON</summary>
                                    <p class="inline-note">You normally will not need this, but it is available for auditing or quick copy/paste.</p>
                                    <div class="field-group">
                                        <label for="json_schema_raw">Template JSON</label>
                                        <textarea id="json_schema_raw" class="textarea-large" spellcheck="false" readonly><?php echo htmlspecialchars($schemaText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                                    </div>
                                    <?php if ($supportsFormStructure): ?>
                                    <div class="field-group">
                                        <label for="form_structure_raw">Form Structure JSON</label>
                                        <textarea id="form_structure_raw" class="textarea-large" spellcheck="false" readonly><?php echo htmlspecialchars($structureText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                                    </div>
                                    <?php endif; ?>
                                </details>
                            </div>

                            <textarea id="json_schema" name="json_schema" hidden><?php echo htmlspecialchars($schemaText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                            <?php if ($supportsFormStructure): ?>
                            <textarea id="form_structure" name="form_structure" hidden><?php echo htmlspecialchars($structureText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                            <?php endif; ?>

                            <div class="form-footer">
                                <button type="submit" class="btn">Save Changes</button>
                                <a class="btn btn-secondary" href="/admin-template-editor.php">Cancel</a>
                                <span class="inline-note">Saved templates update instantly for new permits.</span>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <h2 style="margin-bottom:16px; font-size:20px;">Select a template to edit</h2>
                    <p style="color:#94a3b8; line-height:1.6;">Choose a permit template on the left to update its name, version, or JSON payload. Saving will immediately make the new copy available to issuers.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
<?php if ($editingTemplate && $schemaArray !== null && $schemaDecodeError === null): ?>
<script>
(function () {
    const config = <?php echo json_encode([
        'schema' => $schemaArray,
        'templateId' => $editingTemplate['id'],
        'supportsFormStructure' => $supportsFormStructure,
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;

    const OPTION_TYPES = ['select', 'multiselect', 'radio'];
    const NUMERIC_TYPES = ['number'];

    function deepClone(value) {
        return JSON.parse(JSON.stringify(value ?? {}));
    }

    function normaliseMetaField(field) {
        const clone = typeof field === 'object' && field !== null ? deepClone(field) : {};
        clone.label = typeof clone.label === 'string' ? clone.label : '';
        clone.key = typeof clone.key === 'string' ? clone.key : '';
        clone.type = typeof clone.type === 'string' ? clone.type : 'text';
        if (OPTION_TYPES.includes(clone.type)) {
            clone.options = Array.isArray(clone.options) ? clone.options : [];
        }
        return clone;
    }

    function normaliseSectionField(field) {
        const clone = typeof field === 'object' && field !== null ? deepClone(field) : {};
        clone.label = typeof clone.label === 'string' ? clone.label : '';
        clone.type = typeof clone.type === 'string' ? clone.type : 'text';
        if (OPTION_TYPES.includes(clone.type)) {
            clone.options = Array.isArray(clone.options) ? clone.options : [];
        }
        return clone;
    }

    function normaliseSection(section) {
        const clone = typeof section === 'object' && section !== null ? deepClone(section) : {};
        clone.title = typeof clone.title === 'string' ? clone.title : '';
        clone.items = Array.isArray(clone.items) ? clone.items.map((item) => (typeof item === 'string' ? item : '')).filter(Boolean) : [];
        clone.fields = Array.isArray(clone.fields) ? clone.fields.map((field) => normaliseSectionField(field)) : [];
        return clone;
    }

    function normaliseSchema(schema) {
        const base = typeof schema === 'object' && schema !== null ? deepClone(schema) : {};
        if (!base.meta || typeof base.meta !== 'object') {
            base.meta = {};
        }
        base.meta.fields = Array.isArray(base.meta.fields) ? base.meta.fields.map((field) => normaliseMetaField(field)) : [];
        base.sections = Array.isArray(base.sections) ? base.sections.map((section) => normaliseSection(section)) : [];
        base.signatures = Array.isArray(base.signatures) ? base.signatures.map((sig) => (typeof sig === 'string' ? sig : '')).filter(Boolean) : [];
        return base;
    }

    function createIconButton(label, text) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-icon';
        btn.textContent = text;
        btn.title = label;
        return btn;
    }

    function createFieldGroup(labelText, controlEl, helperText) {
        const wrapper = document.createElement('div');
        wrapper.className = 'field-group';
        if (labelText) {
            const label = document.createElement('label');
            label.textContent = labelText;
            wrapper.appendChild(label);
        }
        if (controlEl) {
            wrapper.appendChild(controlEl);
        }
        if (helperText) {
            const helper = document.createElement('p');
            helper.className = 'helper';
            helper.textContent = helperText;
            wrapper.appendChild(helper);
        }
        return wrapper;
    }

    function createEmptyNote(message) {
        const note = document.createElement('div');
        note.className = 'empty-note';
        note.textContent = message;
        return note;
    }

    function slugify(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 60);
    }

    const form = document.getElementById('template-editor-form');
    if (!form) {
        return;
    }

    const state = normaliseSchema(config.schema);
    state.id = config.templateId;
    if (typeof state.version !== 'number' || !Number.isFinite(state.version) || state.version < 1) {
        state.version = 1;
    }

    const autoKeyMap = new WeakMap();
    state.meta.fields.forEach((field) => autoKeyMap.set(field, false));

    const nameInput = document.getElementById('name');
    const versionInput = document.getElementById('version');
    const titleInput = document.getElementById('schema_title');
    const summaryInput = document.getElementById('schema_summary');
    const metaListEl = document.getElementById('meta-fields-list');
    const sectionsListEl = document.getElementById('sections-list');
    const signatureListEl = document.getElementById('signature-list');
    const addMetaBtn = document.getElementById('add-meta-field');
    const addSectionBtn = document.getElementById('add-section');
    const addSignatureBtn = document.getElementById('add-signature');
    const hiddenJson = document.getElementById('json_schema');
    const rawJsonTextarea = document.getElementById('json_schema_raw');
    const errorsBox = document.getElementById('ui-errors');

    if (titleInput) {
        if (!titleInput.value && typeof state.title === 'string') {
            titleInput.value = state.title;
        }
        state.title = titleInput.value;
    }

    if (summaryInput) {
        if (!summaryInput.value && typeof state.summary === 'string') {
            summaryInput.value = state.summary;
        } else if (summaryInput.value) {
            state.summary = summaryInput.value;
        }
    }

    if (versionInput) {
        const provided = parseInt(versionInput.value, 10);
        if (Number.isFinite(provided) && provided > 0) {
            state.version = provided;
        } else {
            versionInput.value = String(state.version);
        }
    }

    if (titleInput) {
        titleInput.addEventListener('input', (event) => {
            state.title = event.target.value;
        });
    }

    if (summaryInput) {
        summaryInput.addEventListener('input', (event) => {
            const val = event.target.value;
            if (val && val.trim()) {
                state.summary = val;
            } else {
                delete state.summary;
            }
        });
    }

    if (versionInput) {
        versionInput.addEventListener('input', (event) => {
            const raw = parseInt(event.target.value, 10);
            if (Number.isFinite(raw) && raw > 0) {
                state.version = raw;
            }
        });
    }

    function renderOptionsList(field, listEl) {
        listEl.innerHTML = '';
        if (!Array.isArray(field.options) || field.options.length === 0) {
            listEl.appendChild(createEmptyNote('No options yet.'));
            return;
        }

        field.options.forEach((option, optionIndex) => {
            if (typeof option !== 'object' || option === null) {
                option = {};
                field.options[optionIndex] = option;
            }

            const row = document.createElement('div');
            row.className = 'option-row';

            const valueInput = document.createElement('input');
            valueInput.type = 'text';
            valueInput.placeholder = 'Option value';
            valueInput.value = typeof option.value === 'string' ? option.value : '';
            valueInput.addEventListener('input', (event) => {
                option.value = event.target.value;
            });
            row.appendChild(valueInput);

            const labelInput = document.createElement('input');
            labelInput.type = 'text';
            labelInput.placeholder = 'Display label';
            labelInput.value = typeof option.label === 'string' ? option.label : '';
            labelInput.addEventListener('input', (event) => {
                option.label = event.target.value;
            });
            row.appendChild(labelInput);

            const removeBtn = createIconButton('Remove option', 'Remove');
            removeBtn.addEventListener('click', () => {
                field.options.splice(optionIndex, 1);
                renderOptionsList(field, listEl);
            });
            row.appendChild(removeBtn);

            listEl.appendChild(row);
        });
    }

    function createFieldCard(field, index, options) {
        const card = document.createElement('div');
        card.className = 'field-card';
        const fallbackTitle = options.labelFallback || `Field ${index + 1}`;

        const header = document.createElement('div');
        header.className = 'field-card-header';

        const titleEl = document.createElement('span');
        titleEl.className = 'field-card-title';
        titleEl.textContent = field.label && field.label.trim() ? field.label.trim() : fallbackTitle;
        header.appendChild(titleEl);

        const actions = document.createElement('div');
        actions.className = 'field-card-actions';

        const moveUpBtn = createIconButton('Move up', 'Up');
        moveUpBtn.disabled = index === 0;
        moveUpBtn.addEventListener('click', () => {
            if (index === 0) {
                return;
            }
            const arr = options.arrayRef;
            const tmp = arr[index - 1];
            arr[index - 1] = arr[index];
            arr[index] = tmp;
            options.onReRender();
        });
        actions.appendChild(moveUpBtn);

        const moveDownBtn = createIconButton('Move down', 'Down');
        moveDownBtn.disabled = index === options.arrayRef.length - 1;
        moveDownBtn.addEventListener('click', () => {
            if (index === options.arrayRef.length - 1) {
                return;
            }
            const arr = options.arrayRef;
            const tmp = arr[index + 1];
            arr[index + 1] = arr[index];
            arr[index] = tmp;
            options.onReRender();
        });
        actions.appendChild(moveDownBtn);

        const removeBtn = createIconButton('Remove field', 'Remove');
        removeBtn.addEventListener('click', () => {
            options.arrayRef.splice(index, 1);
            options.onReRender();
        });
        actions.appendChild(removeBtn);

        header.appendChild(actions);
        card.appendChild(header);

        const topRow = document.createElement('div');
        topRow.className = options.showKey ? 'grid-three' : 'grid-two';

        const labelInput = document.createElement('input');
        labelInput.type = 'text';
        labelInput.value = field.label || '';
        labelInput.addEventListener('input', (event) => {
            field.label = event.target.value;
            titleEl.textContent = event.target.value.trim() || fallbackTitle;
            if (options.showKey && keyInput) {
                const autoFlag = options.autoKeyMap ? options.autoKeyMap.get(field) : false;
                if (autoFlag !== false) {
                    const suggestion = slugify(event.target.value);
                    if (suggestion) {
                        field.key = suggestion;
                        keyInput.value = suggestion;
                        if (options.autoKeyMap) {
                            options.autoKeyMap.set(field, true);
                        }
                    }
                }
            }
        });
        topRow.appendChild(createFieldGroup('Question label', labelInput, 'Shown to permit issuers.'));

        let keyInput = null;
        if (options.showKey) {
            keyInput = document.createElement('input');
            keyInput.type = 'text';
            keyInput.value = field.key || '';
            keyInput.addEventListener('input', (event) => {
                field.key = event.target.value;
                if (options.autoKeyMap) {
                    options.autoKeyMap.set(field, false);
                }
            });
            topRow.appendChild(createFieldGroup('Reference key', keyInput, 'Used for exports and automations.'));
        }

        const typeSelect = document.createElement('select');
        const supportedTypes = ['text', 'textarea', 'number', 'date', 'datetime', 'time', 'select', 'multiselect', 'checkbox', 'radio'];
        const chosenType = typeof field.type === 'string' ? field.type : 'text';
        supportedTypes.forEach((type) => {
            const opt = document.createElement('option');
            opt.value = type;
            opt.textContent = type.charAt(0).toUpperCase() + type.slice(1);
            if (type === chosenType) {
                opt.selected = true;
            }
            typeSelect.appendChild(opt);
        });
        field.type = chosenType;
        typeSelect.addEventListener('change', (event) => {
            field.type = event.target.value;
            if (OPTION_TYPES.includes(field.type)) {
                field.options = Array.isArray(field.options) ? field.options : [];
                optionsBox.style.display = 'flex';
                renderOptionsList(field, optionList);
            } else {
                optionsBox.style.display = 'none';
                delete field.options;
            }
            numericWrap.style.display = NUMERIC_TYPES.includes(field.type) ? 'grid' : 'none';
        });
        topRow.appendChild(createFieldGroup('Field type', typeSelect, 'Controls how the answer is captured.'));

        card.appendChild(topRow);

        const middleRow = document.createElement('div');
        middleRow.className = 'grid-two';

        const placeholderInput = document.createElement('input');
        placeholderInput.type = 'text';
        placeholderInput.value = typeof field.placeholder === 'string' ? field.placeholder : '';
        placeholderInput.addEventListener('input', (event) => {
            const val = event.target.value;
            if (val) {
                field.placeholder = val;
            } else {
                delete field.placeholder;
            }
        });
        middleRow.appendChild(createFieldGroup('Placeholder (optional)', placeholderInput));

        const defaultInput = document.createElement('input');
        defaultInput.type = 'text';
        if (field.default !== undefined && field.default !== null) {
            defaultInput.value = String(field.default);
        } else {
            defaultInput.value = '';
        }
        defaultInput.addEventListener('input', (event) => {
            const val = event.target.value;
            if (val.trim() === '') {
                delete field.default;
                return;
            }
            if (field.type === 'number') {
                const num = Number(val);
                field.default = Number.isFinite(num) ? num : val;
            } else {
                field.default = val;
            }
        });
        middleRow.appendChild(createFieldGroup('Default value (optional)', defaultInput));

        card.appendChild(middleRow);

        const numericWrap = document.createElement('div');
        numericWrap.className = 'grid-three';
        numericWrap.style.display = NUMERIC_TYPES.includes(field.type) ? 'grid' : 'none';

        const minInput = document.createElement('input');
        minInput.type = 'number';
        minInput.value = field.min !== undefined ? field.min : '';
        minInput.addEventListener('input', (event) => {
            const val = event.target.value;
            if (val === '') {
                delete field.min;
            } else {
                const num = Number(val);
                field.min = Number.isFinite(num) ? num : val;
            }
        });
        numericWrap.appendChild(createFieldGroup('Min', minInput));

        const maxInput = document.createElement('input');
        maxInput.type = 'number';
        maxInput.value = field.max !== undefined ? field.max : '';
        maxInput.addEventListener('input', (event) => {
            const val = event.target.value;
            if (val === '') {
                delete field.max;
            } else {
                const num = Number(val);
                field.max = Number.isFinite(num) ? num : val;
            }
        });
        numericWrap.appendChild(createFieldGroup('Max', maxInput));

        const stepInput = document.createElement('input');
        stepInput.type = 'number';
        stepInput.value = field.step !== undefined ? field.step : '';
        stepInput.addEventListener('input', (event) => {
            const val = event.target.value;
            if (val === '') {
                delete field.step;
            } else {
                const num = Number(val);
                field.step = Number.isFinite(num) ? num : val;
            }
        });
        numericWrap.appendChild(createFieldGroup('Step', stepInput));

        card.appendChild(numericWrap);

        const requiredWrap = document.createElement('label');
        requiredWrap.className = 'switch';
        const requiredCheckbox = document.createElement('input');
        requiredCheckbox.type = 'checkbox';
        requiredCheckbox.checked = !!field.required;
        requiredCheckbox.addEventListener('change', (event) => {
            if (event.target.checked) {
                field.required = true;
            } else {
                delete field.required;
            }
        });
        requiredWrap.appendChild(requiredCheckbox);
        requiredWrap.appendChild(document.createTextNode('Required response'));
        card.appendChild(requiredWrap);

        const optionsBox = document.createElement('div');
        optionsBox.className = 'field-options';
        optionsBox.style.display = OPTION_TYPES.includes(field.type) ? 'flex' : 'none';

        const optionsTitle = document.createElement('h4');
        optionsTitle.textContent = 'Selectable choices';
        optionsBox.appendChild(optionsTitle);

        const optionList = document.createElement('div');
        optionList.className = 'option-list';
        optionsBox.appendChild(optionList);

    const addOptionBtn = document.createElement('button');
    addOptionBtn.type = 'button';
    addOptionBtn.className = 'btn btn-secondary';
    addOptionBtn.textContent = 'Add Option';
        addOptionBtn.addEventListener('click', () => {
            if (!Array.isArray(field.options)) {
                field.options = [];
            }
            field.options.push({ value: '', label: '' });
            renderOptionsList(field, optionList);
        });
        optionsBox.appendChild(addOptionBtn);

        card.appendChild(optionsBox);

        if (OPTION_TYPES.includes(field.type)) {
            field.options = Array.isArray(field.options) ? field.options : [];
        }
        renderOptionsList(field, optionList);

        return card;
    }

    function renderChecklist(section, container, sectionIndex) {
        container.innerHTML = '';
        if (!Array.isArray(section.items) || section.items.length === 0) {
            container.appendChild(createEmptyNote('No checklist items yet.'));
            return;
        }

        section.items.forEach((text, itemIndex) => {
            const row = document.createElement('div');
            row.className = 'item-row';

            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.minHeight = '60px';
            textarea.addEventListener('input', (event) => {
                section.items[itemIndex] = event.target.value;
            });
            row.appendChild(textarea);

            const removeBtn = createIconButton('Remove item', 'Remove');
            removeBtn.addEventListener('click', () => {
                section.items.splice(itemIndex, 1);
                renderChecklist(section, container, sectionIndex);
            });
            row.appendChild(removeBtn);

            container.appendChild(row);
        });
    }

    function createSectionCard(section, sectionIndex) {
        const card = document.createElement('div');
        card.className = 'section-card';

        const header = document.createElement('div');
        header.className = 'section-header';

        const titleWrap = document.createElement('div');
        const titleText = document.createElement('span');
        titleText.className = 'section-title';
        titleText.textContent = section.title && section.title.trim() ? section.title.trim() : `Section ${sectionIndex + 1}`;
        titleWrap.appendChild(titleText);
        header.appendChild(titleWrap);

        const actions = document.createElement('div');
        actions.className = 'section-actions';

        const upBtn = createIconButton('Move section up', 'Up');
        upBtn.disabled = sectionIndex === 0;
        upBtn.addEventListener('click', () => {
            if (sectionIndex === 0) {
                return;
            }
            const arr = state.sections;
            const tmp = arr[sectionIndex - 1];
            arr[sectionIndex - 1] = arr[sectionIndex];
            arr[sectionIndex] = tmp;
            renderSections();
        });
        actions.appendChild(upBtn);

        const downBtn = createIconButton('Move section down', 'Down');
        downBtn.disabled = sectionIndex === state.sections.length - 1;
        downBtn.addEventListener('click', () => {
            if (sectionIndex === state.sections.length - 1) {
                return;
            }
            const arr = state.sections;
            const tmp = arr[sectionIndex + 1];
            arr[sectionIndex + 1] = arr[sectionIndex];
            arr[sectionIndex] = tmp;
            renderSections();
        });
        actions.appendChild(downBtn);

        const removeBtn = createIconButton('Remove section', 'Remove');
        removeBtn.addEventListener('click', () => {
            if (window.confirm('Remove this entire section? All of its content will be deleted.')) {
                state.sections.splice(sectionIndex, 1);
                renderSections();
            }
        });
        actions.appendChild(removeBtn);

        header.appendChild(actions);
        card.appendChild(header);

        const titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.value = section.title || '';
        titleInput.addEventListener('input', (event) => {
            section.title = event.target.value;
            titleText.textContent = event.target.value.trim() || `Section ${sectionIndex + 1}`;
        });
        card.appendChild(createFieldGroup('Section title', titleInput, 'Shown as the heading for this stage.'));

        const checklistHeading = document.createElement('h4');
        checklistHeading.textContent = 'Checklist items';
        checklistHeading.style.margin = '0';
        card.appendChild(checklistHeading);

        const checklistHelp = document.createElement('p');
        checklistHelp.className = 'helper';
        checklistHelp.textContent = 'Each line becomes a checkbox when issuing permits.';
        card.appendChild(checklistHelp);

        const itemsContainer = document.createElement('div');
        itemsContainer.className = 'item-list';
        card.appendChild(itemsContainer);
        renderChecklist(section, itemsContainer, sectionIndex);

        const addItemBtn = document.createElement('button');
        addItemBtn.type = 'button';
        addItemBtn.className = 'btn btn-secondary';
        addItemBtn.textContent = 'Add Checklist Item';
        addItemBtn.addEventListener('click', () => {
            if (!Array.isArray(section.items)) {
                section.items = [];
            }
            section.items.push('');
            renderChecklist(section, itemsContainer, sectionIndex);
        });
        card.appendChild(addItemBtn);

        const fieldsHeading = document.createElement('h4');
        fieldsHeading.textContent = 'Additional fields';
        fieldsHeading.style.margin = '24px 0 0';
        card.appendChild(fieldsHeading);

        const fieldsHelp = document.createElement('p');
        fieldsHelp.className = 'helper';
        fieldsHelp.textContent = 'Use these for text inputs or dropdowns within this section.';
        card.appendChild(fieldsHelp);

        const fieldList = document.createElement('div');
        fieldList.className = 'field-card-list';
        card.appendChild(fieldList);

        if (!Array.isArray(section.fields) || section.fields.length === 0) {
            fieldList.appendChild(createEmptyNote('No additional fields yet.'));
        } else {
            section.fields.forEach((field, fieldIndex) => {
                const fieldCard = createFieldCard(field, fieldIndex, {
                    arrayRef: section.fields,
                    onReRender: renderSections,
                    showKey: false,
                    requireKey: false,
                    labelFallback: `Field ${fieldIndex + 1}`,
                });
                fieldList.appendChild(fieldCard);
            });
        }

        const addFieldBtn = document.createElement('button');
        addFieldBtn.type = 'button';
        addFieldBtn.className = 'btn btn-secondary';
        addFieldBtn.textContent = 'Add Field';
        addFieldBtn.addEventListener('click', () => {
            section.fields.push(normaliseSectionField({}));
            renderSections();
        });
        card.appendChild(addFieldBtn);

        return card;
    }

    function renderMetaFields() {
        if (!metaListEl) {
            return;
        }
        metaListEl.innerHTML = '';
        if (!state.meta.fields.length) {
            metaListEl.appendChild(createEmptyNote('No general questions yet. Add one to capture the basics.'));
            return;
        }
        state.meta.fields.forEach((field, index) => {
            const card = createFieldCard(field, index, {
                arrayRef: state.meta.fields,
                onReRender: renderMetaFields,
                showKey: true,
                requireKey: true,
                labelFallback: `Question ${index + 1}`,
                autoKeyMap,
            });
            metaListEl.appendChild(card);
        });
    }

    function renderSections() {
        if (!sectionsListEl) {
            return;
        }
        sectionsListEl.innerHTML = '';
        if (!state.sections.length) {
            sectionsListEl.appendChild(createEmptyNote('No sections yet. Add one to structure the permit.'));
            return;
        }
        state.sections.forEach((section, index) => {
            sectionsListEl.appendChild(createSectionCard(section, index));
        });
    }

    function renderSignatures() {
        if (!signatureListEl) {
            return;
        }
        signatureListEl.innerHTML = '';
        if (!state.signatures.length) {
            signatureListEl.appendChild(createEmptyNote('No sign-offs yet. Add each approval required.'));
            return;
        }
        state.signatures.forEach((signature, index) => {
            const row = document.createElement('div');
            row.className = 'signature-row';

            const input = document.createElement('input');
            input.type = 'text';
            input.value = signature;
            input.placeholder = 'e.g. issuer_issue';
            input.addEventListener('input', (event) => {
                state.signatures[index] = event.target.value;
            });
            row.appendChild(input);

            const actions = document.createElement('div');
            actions.className = 'field-card-actions';

            const upBtn = createIconButton('Move up', 'Up');
            upBtn.disabled = index === 0;
            upBtn.addEventListener('click', () => {
                if (index === 0) {
                    return;
                }
                const arr = state.signatures;
                const tmp = arr[index - 1];
                arr[index - 1] = arr[index];
                arr[index] = tmp;
                renderSignatures();
            });
            actions.appendChild(upBtn);

            const downBtn = createIconButton('Move down', 'Down');
            downBtn.disabled = index === state.signatures.length - 1;
            downBtn.addEventListener('click', () => {
                if (index === state.signatures.length - 1) {
                    return;
                }
                const arr = state.signatures;
                const tmp = arr[index + 1];
                arr[index + 1] = arr[index];
                arr[index] = tmp;
                renderSignatures();
            });
            actions.appendChild(downBtn);

            const removeBtn = createIconButton('Remove', 'Remove');
            removeBtn.addEventListener('click', () => {
                state.signatures.splice(index, 1);
                renderSignatures();
            });
            actions.appendChild(removeBtn);

            row.appendChild(actions);
            signatureListEl.appendChild(row);
        });
    }

    if (addMetaBtn) {
        addMetaBtn.addEventListener('click', () => {
            const field = normaliseMetaField({ type: 'text' });
            autoKeyMap.set(field, true);
            state.meta.fields.push(field);
            renderMetaFields();
        });
    }

    if (addSectionBtn) {
        addSectionBtn.addEventListener('click', () => {
            state.sections.push(normaliseSection({}));
            renderSections();
        });
    }

    if (addSignatureBtn) {
        addSignatureBtn.addEventListener('click', () => {
            state.signatures.push('');
            renderSignatures();
        });
    }

    renderMetaFields();
    renderSections();
    renderSignatures();

    function validateState() {
        const errors = [];

        if (!state.title || !state.title.trim()) {
            errors.push('Permit title is required.');
        }

        if (!state.meta.fields.length) {
            errors.push('Add at least one general question.');
        }

        const seenKeys = new Set();
        state.meta.fields.forEach((field, index) => {
            const label = String(field.label || '').trim();
            const key = String(field.key || '').trim();
            if (!label) {
                errors.push(`General question ${index + 1} needs a label.`);
            }
            if (!key) {
                errors.push(`General question ${index + 1} needs a reference key.`);
            }
            if (key) {
                if (seenKeys.has(key)) {
                    errors.push(`Reference key "${key}" is duplicated.`);
                } else {
                    seenKeys.add(key);
                }
            }
            if (OPTION_TYPES.includes(field.type)) {
                const options = Array.isArray(field.options) ? field.options : [];
                const hasValidOption = options.some((option) => option && (option.value || option.label));
                if (!hasValidOption) {
                    errors.push(`General question ${index + 1} needs at least one choice.`);
                }
            }
        });

        state.sections.forEach((section, sectionIndex) => {
            const title = String(section.title || '').trim();
            if (!title) {
                errors.push(`Section ${sectionIndex + 1} needs a title.`);
            }
            (section.fields || []).forEach((field, fieldIndex) => {
                const label = String(field.label || '').trim();
                if (!label) {
                    errors.push(`Section ${sectionIndex + 1}, field ${fieldIndex + 1} needs a label.`);
                }
                if (OPTION_TYPES.includes(field.type)) {
                    const options = Array.isArray(field.options) ? field.options : [];
                    const hasValidOption = options.some((option) => option && (option.value || option.label));
                    if (!hasValidOption) {
                        errors.push(`Section ${sectionIndex + 1}, field ${fieldIndex + 1} needs at least one choice.`);
                    }
                }
            });
        });

        if (!state.signatures.length) {
            errors.push('Add at least one sign-off step so permits can be authorised.');
        }

        return errors;
    }

    function cleanField(field, requireKey) {
        const clone = deepClone(field);
        clone.label = (clone.label || '').trim();
        if (!clone.label) {
            return null;
        }

        if (requireKey) {
            clone.key = (clone.key || '').trim();
            if (!clone.key) {
                return null;
            }
        } else if (typeof clone.key === 'string') {
            clone.key = clone.key.trim();
            if (!clone.key) {
                delete clone.key;
            }
        }

        if (typeof clone.placeholder === 'string') {
            const trimmed = clone.placeholder.trim();
            if (trimmed) {
                clone.placeholder = trimmed;
            } else {
                delete clone.placeholder;
            }
        }

        if (clone.default === '' || clone.default === null) {
            delete clone.default;
        }

        if (typeof clone.help === 'string') {
            const trimmed = clone.help.trim();
            if (trimmed) {
                clone.help = trimmed;
            } else {
                delete clone.help;
            }
        }

        if (!OPTION_TYPES.includes(clone.type)) {
            delete clone.options;
        } else {
            clone.options = Array.isArray(clone.options) ? clone.options : [];
            clone.options = clone.options
                .map((option) => {
                    if (typeof option !== 'object' || option === null) {
                        return null;
                    }
                    const value = typeof option.value === 'string' ? option.value.trim() : '';
                    const label = typeof option.label === 'string' ? option.label.trim() : '';
                    if (!value && !label) {
                        return null;
                    }
                    return {
                        value: value || label,
                        label: label || value,
                    };
                })
                .filter(Boolean);
            if (!clone.options.length) {
                return null;
            }
        }

        ['min', 'max', 'step'].forEach((prop) => {
            if (!(prop in clone)) {
                return;
            }
            const val = clone[prop];
            if (val === '' || val === null) {
                delete clone[prop];
                return;
            }
            if (typeof val === 'string') {
                const trimmed = val.trim();
                if (!trimmed) {
                    delete clone[prop];
                    return;
                }
                const num = Number(trimmed);
                clone[prop] = Number.isFinite(num) ? num : trimmed;
            }
        });

        if (clone.required !== true) {
            delete clone.required;
        }

        return clone;
    }

    function prepareSchemaForSubmit() {
        const output = deepClone(state);
        output.id = config.templateId;
        output.title = state.title || '';
        if (summaryInput && summaryInput.value && summaryInput.value.trim()) {
            output.summary = summaryInput.value.trim();
        } else {
            delete output.summary;
        }

        output.version = state.version && state.version > 0 ? state.version : 1;

        output.meta = output.meta || {};
        output.meta.fields = (state.meta.fields || [])
            .map((field) => cleanField(field, true))
            .filter(Boolean);

        output.sections = (state.sections || []).map((section) => {
            const clone = deepClone(section);
            clone.title = (clone.title || '').trim();
            clone.items = Array.isArray(clone.items)
                ? clone.items.map((item) => String(item || '').trim()).filter(Boolean)
                : [];
            clone.fields = Array.isArray(clone.fields)
                ? clone.fields.map((field) => cleanField(field, false)).filter(Boolean)
                : [];
            return clone;
        }).filter((section) => section.title || section.items.length || section.fields.length);

        output.signatures = (state.signatures || [])
            .map((sig) => String(sig || '').trim())
            .filter(Boolean);

        return output;
    }

    function clearErrors() {
        if (!errorsBox) {
            return;
        }
        errorsBox.innerHTML = '';
        errorsBox.style.display = 'none';
    }

    function showErrors(messages) {
        if (!errorsBox) {
            return;
        }
        errorsBox.innerHTML = '';
        const list = document.createElement('ul');
        list.style.margin = '0';
        list.style.paddingLeft = '18px';
        messages.forEach((msg) => {
            const li = document.createElement('li');
            li.textContent = msg;
            list.appendChild(li);
        });
        errorsBox.appendChild(list);
        errorsBox.style.display = 'block';
        errorsBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    form.addEventListener('submit', (event) => {
        clearErrors();
        const errors = validateState();
        if (errors.length) {
            event.preventDefault();
            showErrors(errors);
            return;
        }

        const prepared = prepareSchemaForSubmit();
        if (hiddenJson) {
            hiddenJson.value = JSON.stringify(prepared, null, 2);
        }
        if (rawJsonTextarea) {
            rawJsonTextarea.value = hiddenJson ? hiddenJson.value : JSON.stringify(prepared, null, 2);
        }

        if (!nameInput || !nameInput.value.trim()) {
            event.preventDefault();
            showErrors(['Template name is required.']);
        }
    });
})();
</script>
<?php endif; ?>
</html>
