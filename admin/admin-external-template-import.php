<?php
/**
 * External Template Importer (Admin)
 *
 * Allows admins to fetch and convert public construction templates from external sources (e.g., SafetyCulture, OSHA, HSE) into the local JSON format.
 * No API keys required; uses public web scraping and conversion for demonstration.
 */

require __DIR__ . '/../vendor/autoload.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

$stmt = $db->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || $currentUser['role'] !== 'admin') {
    http_response_code(403);
    echo '<h1>Access denied</h1><p>Administrator role required.</p>';
    exit;
}

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $source = $_POST['source'] ?? '';
    $templateUrl = $_POST['template_url'] ?? '';
    if ($source && $templateUrl) {
        // Basic fetch and conversion logic (demo: fetches SafetyCulture public template page and creates a stub JSON)
        $raw = @file_get_contents($templateUrl);
        if ($raw && strpos($raw, '<html') !== false) {
            // Extract a title for demonstration
            if (preg_match('/<title>(.*?)<\\/title>/', $raw, $m)) {
                $title = trim(strip_tags($m[1]));
            } else {
                $title = 'Imported Template';
            }
            $id = strtolower(preg_replace('/[^a-z0-9]+/', '-', $title)) . '-ext';
            $json = [
                'id' => $id,
                'title' => $title,
                'name' => $title,
                'description' => 'Imported from ' . htmlspecialchars($templateUrl),
                'fields' => [
                    [ 'label' => 'Imported Field Example', 'type' => 'text', 'required' => false ]
                ]
            ];
            $jsonPath = $root . '/templates/forms/' . $id . '.json';
            file_put_contents($jsonPath, json_encode($json, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            $messages[] = 'Imported template: ' . $title . ' → ' . basename($jsonPath);
        } else {
            $errors[] = 'Failed to fetch or parse the template URL.';
        }
    } else {
        $errors[] = 'Source and template URL are required.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>External Template Importer</title>
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
                <h1>🌐 External Template Importer</h1>
                <p class="lead"><strong>Import construction permit templates from trusted sources in seconds!</strong><br>
                Paste a public template URL from SafetyCulture, OSHA, HSE, or other supported sites. This tool will fetch the page and create a ready-to-edit permit template in your system.<br><br>
                <span style="color:#38bdf8">Tip:</span> For best results, use direct links to template or checklist pages. You can further edit the imported template in the admin editor.<br><br>
                <strong>Supported sources:</strong>
                <ul style="margin:8px 0 0 18px;padding:0;font-size:15px;">
                    <li><a href="https://safetyculture.com/library" target="_blank" rel="noopener">SafetyCulture Library</a></li>
                    <li><a href="https://www.osha.gov/sample-safety-health-programs" target="_blank" rel="noopener">OSHA Sample Programs</a></li>
                    <li><a href="https://www.hse.gov.uk/construction/" target="_blank" rel="noopener">HSE Construction (UK)</a></li>
                    <li><a href="https://marketplace.safetyculture.com/templates" target="_blank" rel="noopener">iAuditor Marketplace</a></li>
                    <li><a href="https://www.safeworkaustralia.gov.au/doc/templates-and-forms" target="_blank" rel="noopener">Safe Work Australia</a></li>
                </ul>
                <br>
                <span style="color:#fbbf24">Coming soon:</span> Direct field mapping, batch import, and more sources!<br>
                </p>

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
            <h2>Import External Template</h2>
            <form method="post" style="margin-bottom:18px;">
                <label><strong>Source:</strong><br>
                    <select name="source" required style="margin-top:4px;">
                        <option value="">Select Source</option>
                        <option value="safetyculture">SafetyCulture</option>
                        <option value="osha">OSHA</option>
                        <option value="hse">HSE (UK)</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <br><br>
                <label><strong>Template URL:</strong><br>
                    <input type="url" name="template_url" style="width:400px;margin-top:4px;" required placeholder="https://...">
                </label>
                <br><br>
                <button type="submit" class="btn">Import Template</button>
            </form>
            <div style="font-size:14px;color:#94a3b8;line-height:1.6;">
                <strong>Instructions:</strong>
                <ol style="margin:8px 0 8px 20px;padding:0;">
                  <li>Choose a source and paste a public template/checklist URL.</li>
                  <li>Click <b>Import Template</b>. The system will fetch the page and create a new template file.</li>
                  <li>Edit the imported template in <b>Edit Permit Templates</b> to match your needs.</li>
                  <li>Re-run the <b>Permit Template Importer</b> to sync it into your system.</li>
                </ol>
                <span style="color:#38bdf8">Note:</span> This tool currently creates a basic template from the page title. Full field mapping and batch import are coming soon.<br>
                <span style="color:#fbbf24">Feedback and suggestions welcome!</span>
            </div>
        </div>
    </div>
</body>
</html>
