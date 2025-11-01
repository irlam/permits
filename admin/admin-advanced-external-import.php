<?php
/**
 * Advanced External Template Importer (Admin)
 *
 * Batch import, advanced parsing, and AI-assisted field extraction for construction templates.
 *
 * Roadmap:
 * 1. Batch import from multiple URLs or file uploads
 * 2. Source-specific HTML parsing (SafetyCulture, OSHA, HSE)
 * 3. AI/LLM field extraction (future)
 * 4. Visual mapping UI (future)
 * 5. Scheduled sync (future)
 */

// DEBUG: Output session and cookie info for troubleshooting, before any redirect
require __DIR__ . '/../vendor/autoload.php';
[$app, $db, $root] = require_once __DIR__ . '/../src/bootstrap.php';
if (isset($_GET['debug'])) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    echo '<pre style="background:#222;color:#fff;padding:12px;">';
    echo 'Session ID: ' . session_id() . "\n";
    echo 'Session Data: ' . print_r($_SESSION, true) . "\n";
    echo 'Cookies: ' . print_r($_COOKIE, true) . "\n";
    echo '</pre>';
    exit;
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
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
$messages = [];
$errors = [];

function extract_fields_from_html($html, $source) {
    // Simple demo: extract <li> or <label> as fields
    $fields = [];
    if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html, $m)) {
        foreach ($m[1] as $item) {
            $label = strip_tags($item);
            if (strlen($label) > 2) {
                $fields[] = [ 'label' => $label, 'type' => 'text', 'required' => false ];
            }
        }
    }
    if (empty($fields) && preg_match_all('/<label[^>]*>(.*?)<\/label>/is', $html, $m)) {
        foreach ($m[1] as $item) {
            $label = strip_tags($item);
            if (strlen($label) > 2) {
                $fields[] = [ 'label' => $label, 'type' => 'text', 'required' => false ];
            }
        }
    }
    return $fields;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $urls = array_filter(array_map('trim', explode("\n", $_POST['template_urls'] ?? '')));
    $source = $_POST['source'] ?? '';
    foreach ($urls as $templateUrl) {
        $raw = @file_get_contents($templateUrl);
        if ($raw && strpos($raw, '<html') !== false) {
            // Extract title
            if (preg_match('/<title>(.*?)<\\/title>/', $raw, $m)) {
                $title = trim(strip_tags($m[1]));
            } else {
                $title = 'Imported Template';
            }
            $id = strtolower(preg_replace('/[^a-z0-9]+/', '-', $title)) . '-adv';
            $fields = extract_fields_from_html($raw, $source);
            if (empty($fields)) {
                $fields[] = [ 'label' => 'Imported Field Example', 'type' => 'text', 'required' => false ];
            }
            $json = [
                'id' => $id,
                'title' => $title,
                'name' => $title,
                'description' => 'Imported from ' . htmlspecialchars($templateUrl),
                'fields' => $fields
            ];
            $jsonPath = $root . '/templates/forms/' . $id . '.json';
            file_put_contents($jsonPath, json_encode($json, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            $messages[] = 'Imported: ' . $title . ' → ' . basename($jsonPath) . ' (' . count($fields) . ' fields)';
        } else {
            $errors[] = 'Failed to fetch or parse: ' . htmlspecialchars($templateUrl);
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Advanced External Template Importer</title>
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
        textarea { width: 100%; min-height: 80px; font-size: 15px; border-radius: 8px; border: 1px solid #334155; background: #1e293b; color: #e2e8f0; padding: 8px; }
    </style>
</head>
<body>
    <div class="wrap">
        <a class="back" href="/admin.php">⬅ Back to Admin</a>
        <h1>🚀 Advanced External Template Importer</h1>
        <p class="lead"><strong>Batch import and advanced parsing for construction templates.</strong><br>
        Paste one or more public template URLs (one per line) from SafetyCulture, OSHA, HSE, or other supported sites. This tool will fetch, parse, and create ready-to-edit permit templates.<br><br>
        <span style="color:#38bdf8">Tip:</span> For best results, use direct links to checklist/template pages. More parsing logic and AI field extraction coming soon!
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
            <h2>Batch Import & Parse</h2>
            <form method="post">
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
                <label><strong>Template URLs (one per line):</strong><br>
                    <textarea name="template_urls" required placeholder="https://...\nhttps://...\n"></textarea>
                </label>
                <br><br>
                <button type="submit" class="btn">Batch Import</button>
            </form>
            <div style="font-size:14px;color:#94a3b8;line-height:1.6;">
                <strong>Instructions:</strong>
                <ol style="margin:8px 0 8px 20px;padding:0;">
                  <li>Choose a source and paste one or more public template/checklist URLs (one per line).</li>
                  <li>Click <b>Batch Import</b>. The system will fetch and parse each page, creating new template files.</li>
                  <li>Edit the imported templates in <b>Edit Permit Templates</b> to match your needs.</li>
                  <li>Re-run the <b>Permit Template Importer</b> to sync them into your system.</li>
                </ol>
                <span style="color:#38bdf8">Note:</span> This tool currently extracts list items and labels as fields. AI field extraction and visual mapping are coming soon.<br>
                <span style="color:#fbbf24">Feedback and suggestions welcome!</span>
            </div>
        </div>
    </div>
</body>
</html>
