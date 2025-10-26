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
        .layout { display: grid; grid-template-columns: minmax(280px, 1fr) minmax(0, 2fr); gap: 24px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 24px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.35); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #2d3a54; }
        th { font-weight: 600; color: #cbd5f5; }
        tr:hover td { background: rgba(59, 130, 246, 0.08); }
        .tag { display: inline-block; padding: 2px 8px; border-radius: 999px; background: rgba(148, 163, 184, 0.18); color: #e2e8f0; font-size: 12px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 10px 16px; border-radius: 10px; border: none; cursor: pointer; font-weight: 600; background: #3b82f6; color: #fff; text-decoration: none; font-size: 14px; }
        .btn:hover { background: #2563eb; }
        .btn-secondary { background: rgba(148, 163, 184, 0.2); color: #e2e8f0; }
        .btn-secondary:hover { background: rgba(148, 163, 184, 0.35); }
        form label { display: block; font-weight: 600; margin-bottom: 8px; color: #cbd5f5; }
        form input[type="text"], form input[type="number"], form textarea { width: 100%; background: #0f172a; border: 1px solid #334155; border-radius: 10px; color: #e2e8f0; padding: 10px 12px; font-family: 'JetBrains Mono', SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 13px; }
        form input[type="text"]:focus, form input[type="number"]:focus, form textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25); }
        form textarea { min-height: 320px; resize: vertical; }
        .field-group { margin-bottom: 20px; }
        .field-inline { display: flex; gap: 16px; }
        .field-inline .field-group { flex: 1; }
        .helper { color: #94a3b8; font-size: 13px; margin-top: 6px; }
        .empty { color: #94a3b8; font-size: 14px; text-align: center; padding: 16px 0; }
        @media (max-width: 960px) {
            .layout { grid-template-columns: 1fr; }
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
            <div class="card">
                <h2 style="margin-bottom:16px; font-size:20px;">Available Templates</h2>
                <?php if (!$templates): ?>
                    <p class="empty">No templates found. Run the importer first.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Version</th>
                                <th>Updated</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $template): ?>
                                <tr<?php echo ($template['id'] === $activeTemplateId) ? ' style="background: rgba(59,130,246,0.12);"' : ''; ?>>
                                    <td><code><?php echo htmlspecialchars($template['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code></td>
                                    <td><?php echo htmlspecialchars($template['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                    <td><span class="tag">v<?php echo htmlspecialchars((string)$template['version'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span></td>
                                    <td><?php echo formatDateForDisplay($template['updated_at'] ?? null); ?></td>
                                    <td style="text-align:right;"><a class="btn btn-secondary" href="/admin-template-editor.php?template=<?php echo urlencode($template['id']); ?>">Edit</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card">
                <?php if ($editingTemplate): ?>
                    <h2 style="margin-bottom:16px; font-size:20px;">Editing: <code><?php echo htmlspecialchars($editingTemplate['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code></h2>
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

                        <div class="field-group" style="display:flex; gap:12px;">
                            <button type="submit" class="btn">Save Changes</button>
                            <a class="btn btn-secondary" href="/admin-template-editor.php">Clear Selection</a>
                        </div>
                    </form>
                <?php else: ?>
                    <h2 style="margin-bottom:16px; font-size:20px;">Select a template to edit</h2>
                    <p style="color:#94a3b8; line-height:1.6;">Choose a permit template on the left to update its name, version, or JSON payload. Saving will immediately make the new copy available to issuers.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
