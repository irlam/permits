<?php
/**
 * OpenAI API Key Settings (Admin)
 *
 * Allows admins to securely set and update the OpenAI API key for AI-powered features.
 */

require __DIR__ . '/../vendor/autoload.php';
[$app, $db, $root] = require_once __DIR__ . '/../src/bootstrap.php';
if (isset($_GET['debug'])) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    echo '<pre style="background:#222;color:#fff;padding:12px;">';
    echo 'Session ID: ' . session_id() . "\n";
    echo 'Session Data: ' . print_r($_SESSION, true) . "\n";
    echo '</pre>';
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

$keyFile = $root . '/config/openai.key';
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey = trim($_POST['api_key'] ?? '');
    if ($apiKey && preg_match('/^sk-[a-zA-Z0-9]{20,}/', $apiKey)) {
        file_put_contents($keyFile, $apiKey);
        $messages[] = 'OpenAI API key saved successfully.';
    } else {
        $errors[] = 'Please enter a valid OpenAI API key (starts with sk-).';
    }
}
$currentKey = is_file($keyFile) ? trim(file_get_contents($keyFile)) : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OpenAI API Key Settings</title>
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: system-ui, -apple-system, sans-serif; min-height: 100vh; margin: 0; }
        .wrap { max-width: 600px; margin: 0 auto; padding: 32px 16px 80px; }
        a.back { color: #60a5fa; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 24px; }
        a.back:hover { text-decoration: underline; }
        h1 { font-size: 28px; margin-bottom: 12px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 24px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.35); margin-bottom: 24px; }
        .btn { background: #3b82f6; border: none; color: white; padding: 14px 28px; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 15px; }
        .btn:hover { background: #2563eb; }
        .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: rgba(34, 197, 94, 0.12); border: 1px solid rgba(34, 197, 94, 0.45); color: #bbf7d0; }
        .alert-error { background: rgba(248, 113, 113, 0.12); border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; }
        label { display:block; margin-bottom:8px; }
        input[type="text"] { width:100%; padding:10px; border-radius:8px; border:1px solid #334155; background:#1e293b; color:#e2e8f0; font-size:16px; }
    </style>
</head>
<body>
    <div class="wrap">
        <a class="back" href="/admin.php">⬅ Back to Admin</a>
        <h1>OpenAI API Key Settings</h1>
        <div class="card">
            <form method="post">
                <label for="api_key"><strong>OpenAI API Key:</strong></label>
                <input type="text" id="api_key" name="api_key" value="<?= htmlspecialchars($currentKey) ?>" placeholder="sk-..." autocomplete="off" required>
                <br>
                <button type="submit" class="btn">Save API Key</button>
            </form>
            <p style="color:#94a3b8;font-size:14px;margin-top:18px;">Your API key is stored securely on the server and never shown to other users. Required for AI-powered field extraction and advanced features.<br>Get your key from <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener" style="color:#60a5fa;">OpenAI API Keys</a>.</p>
        </div>
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
    </div>
</body>
</html>
