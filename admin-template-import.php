<?php
/**
 * Permit Template Importer (Admin)
 *
 * Provides a web-driven way to run the preset seeder and schema check.
 * Useful for shared hosting environments without CLI access.
 */

require __DIR__ . '/vendor/autoload.php';

use Permits\DatabaseMaintenance;
use Permits\FormTemplateSeeder;

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

$columnResult = null;
$importResult = null;
$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Step 1: ensure DB schema is aligned
    try {
        $columnResult = DatabaseMaintenance::ensureFormTemplateColumns($db);
        if (!empty($columnResult['added'])) {
            $messages[] = 'Added columns: ' . implode(', ', $columnResult['added']);
        }
        if (!empty($columnResult['alreadyPresent'])) {
            $messages[] = 'Columns already present: ' . implode(', ', $columnResult['alreadyPresent']);
        }
        if (!empty($columnResult['errors'])) {
            foreach ($columnResult['errors'] as $error) {
                $errors[] = $error;
            }
        }
    } catch (\Throwable $e) {
        $errors[] = 'Failed to verify columns: ' . $e->getMessage();
    }

    if (empty($errors)) {
        // Step 2: run the seeder
        try {
            $directory = $root . '/templates/form-presets';
            $importResult = FormTemplateSeeder::importFromDirectory($db, $directory);

            $messages[] = sprintf(
                'Processed %d template(s).',
                $importResult['processed']
            );

            if (!empty($importResult['imported'])) {
                $messages[] = 'Imported or updated: ' . implode(', ', $importResult['imported']);
            }

            if (!empty($importResult['errors'])) {
                foreach ($importResult['errors'] as $issue) {
                    $errors[] = $issue;
                }
            }

            if (function_exists('logActivity')) {
                logActivity(
                    'templates_seeded',
                    'admin',
                    'form_template',
                    null,
                    sprintf(
                        'Templates seeded via admin UI by %s (%s). Processed %d, errors: %d.',
                        $currentUser['name'] ?? 'admin',
                        $currentUser['email'] ?? '',
                        $importResult['processed'] ?? 0,
                        isset($importResult['errors']) ? count($importResult['errors']) : 0
                    )
                );
            }
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        } catch (\Throwable $e) {
            $errors[] = 'Unexpected error while importing: ' . $e->getMessage();
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Permit Template Importer</title>
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: system-ui, -apple-system, sans-serif; min-height: 100vh; margin: 0; }
        .wrap { max-width: 900px; margin: 0 auto; padding: 32px 16px 80px; }
        a.back { color: #60a5fa; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 24px; }
        a.back:hover { text-decoration: underline; }
        h1 { font-size: 28px; margin-bottom: 12px; }
        p.lead { color: #94a3b8; margin-bottom: 24px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 24px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.35); margin-bottom: 24px; }
        .card h2 { font-size: 22px; margin-bottom: 8px; }
        .card p { color: #94a3b8; margin-bottom: 16px; }
        .btn { background: #3b82f6; border: none; color: white; padding: 14px 28px; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 15px; }
        .btn:hover { background: #2563eb; }
        .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: rgba(34, 197, 94, 0.12); border: 1px solid rgba(34, 197, 94, 0.45); color: #bbf7d0; }
        .alert-error { background: rgba(248, 113, 113, 0.12); border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; }
        ul { margin: 0; padding-left: 20px; }
        li { margin-bottom: 6px; }
        .muted { font-size: 13px; color: #64748b; margin-top: 12px; }
        form { margin-top: 20px; }
    </style>
</head>
<body>
    <div class="wrap">
        <a class="back" href="/admin.php">⬅ Back to Admin</a>
        <h1>Permit Template Importer</h1>
        <p class="lead">Run the seeder without command-line access. This will scan <code>templates/form-presets/</code> and upsert every JSON file into <code>form_templates</code>.</p>

        <?php if (!empty($messages)): ?>
            <div class="alert alert-success">
                <ul>
                    <?php foreach ($messages as $message): ?>
                        <li><?= htmlspecialchars($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Import Presets</h2>
            <p>This process can be safely re-run at any time. Existing templates will be updated in place, and new ones will be created automatically.</p>
            <form method="post">
                <input type="hidden" name="action" value="run-import">
                <button type="submit" class="btn">Run Import</button>
            </form>
            <p class="muted">
                Need to add more presets first? Upload JSON schemas into <code>templates/form-presets/</code> via FTP or the file manager, then return here and re-run the import.
            </p>
        </div>
    </div>
</body>
</html>
