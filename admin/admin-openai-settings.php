<?php
/**
 * AI Provider Settings (Admin)
 *
 * Manage API credentials for supported AI providers that power field extraction.
 */

require __DIR__ . '/../vendor/autoload.php';
[$app, $db, $root] = require_once __DIR__ . '/../src/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (isset($_GET['debug'])) {
    echo '<pre style="background:#222;color:#fff;padding:12px;">';
    echo 'Session Name: ' . session_name() . "\n";
    echo 'Session ID: ' . session_id() . "\n";
    echo 'Session Data: ' . print_r($_SESSION, true) . "\n";
    echo 'Cookies: ' . print_r($_COOKIE, true) . "\n";
    echo '</pre>';
    exit;
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

$settingsFile = $root . '/config/ai-settings.json';
$messages = [];
$errors = [];

function provider_label(string $provider): string
{
    return ucwords(str_replace('_', ' ', $provider));
}

function default_ai_settings(): array
{
    return [
        'provider' => 'openai',
        'providers' => [
            'openai' => [
                'api_key' => '',
                'endpoint' => 'https://api.openai.com/v1/chat/completions',
                'model' => 'gpt-4o-mini',
            ],
            'azure_openai' => [
                'api_key' => '',
                'endpoint' => 'https://YOUR-RESOURCE.openai.azure.com',
                'deployment' => '',
                'api_version' => '2024-02-15-preview',
            ],
            'anthropic' => [
                'api_key' => '',
                'endpoint' => 'https://api.anthropic.com/v1/messages',
                'model' => 'claude-3-sonnet-20240229',
                'version' => '2023-06-01',
                'max_tokens' => 900,
            ],
        ],
    ];
}

function load_ai_settings(string $root, string $settingsFile): array
{
    $settings = default_ai_settings();

    if (is_file($settingsFile)) {
        $raw = file_get_contents($settingsFile);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $settings = array_replace_recursive($settings, $decoded);
            }
        }
    }

    // Legacy OpenAI-only fallbacks
    $legacyKeyPath = $root . '/config/openai.key';
    if (empty($settings['providers']['openai']['api_key']) && is_file($legacyKeyPath)) {
        $settings['providers']['openai']['api_key'] = trim((string)file_get_contents($legacyKeyPath));
    }
    $legacyConfigPath = $root . '/config/openai.php';
    if (is_file($legacyConfigPath)) {
        $legacy = require $legacyConfigPath;
        if (is_array($legacy)) {
            $settings['providers']['openai']['endpoint'] = $legacy['endpoint'] ?? $settings['providers']['openai']['endpoint'];
            $settings['providers']['openai']['model'] = $legacy['model'] ?? $settings['providers']['openai']['model'];
            if (empty($settings['providers']['openai']['api_key']) && !empty($legacy['api_key'])) {
                $settings['providers']['openai']['api_key'] = $legacy['api_key'];
            }
        }
    }
    if (empty($settings['providers']['openai']['api_key'])) {
        $envKey = getenv('OPENAI_API_KEY');
        if ($envKey) {
            $settings['providers']['openai']['api_key'] = $envKey;
        }
    }

    if (!isset($settings['provider']) || !isset($settings['providers'][$settings['provider']])) {
        $settings['provider'] = 'openai';
    }

    return $settings;
}

function save_ai_settings(string $settingsFile, array $settings, array &$errors): bool
{
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $errors[] = 'Failed to encode AI settings as JSON.';
        return false;
    }
    if (file_put_contents($settingsFile, $json . "\n", LOCK_EX) === false) {
        $errors[] = 'Unable to write AI settings file. Check permissions for config/.';
        return false;
    }
    return true;
}

function validate_ai_key(string $provider, string $key): ?string
{
    if ($key === '') {
        return 'API key cannot be empty.';
    }

    if ($provider === 'openai') {
        if (!str_starts_with($key, 'sk-')) {
            return 'OpenAI-compatible keys should start with sk-.';
        }
    } elseif ($provider === 'anthropic') {
        if (!str_starts_with($key, 'sk-ant-') && !str_starts_with($key, 'sk-proj-')) {
            return 'Anthropic keys typically start with sk-ant- or sk-proj-.';
        }
    } elseif (strlen($key) < 20) {
        return 'The API key looks too short.';
    }

    return null;
}

function test_ai_provider(string $provider, array $providerSettings, array &$messages, array &$errors): void
{
    $apiKey = trim((string)($providerSettings['api_key'] ?? ''));
    if ($apiKey === '') {
        $errors[] = 'Add an API key before testing.';
        return;
    }

    $label = provider_label($provider);
    $endpoint = '';
    $headers = [];
    $method = 'GET';
    $body = null;

    switch ($provider) {
        case 'azure_openai':
            $base = rtrim((string)($providerSettings['endpoint'] ?? ''), '/');
            if ($base === '') {
                $errors[] = 'Azure OpenAI requires a resource endpoint (e.g. https://your-resource.openai.azure.com).';
                return;
            }
            $apiVersion = $providerSettings['api_version'] ?? '2024-02-15-preview';
            $endpoint = $base . '/openai/deployments?api-version=' . rawurlencode($apiVersion);
            $headers = [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
            ];
            break;

        case 'anthropic':
            $base = rtrim((string)($providerSettings['endpoint'] ?? 'https://api.anthropic.com/v1/messages'), '/');
            $modelsEndpoint = preg_replace('#/messages$#', '/models', $base);
            if ($modelsEndpoint === $base) {
                $modelsEndpoint .= '/models';
            }
            $endpoint = $modelsEndpoint;
            $headers = [
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . ($providerSettings['version'] ?? '2023-06-01'),
            ];
            break;

        default: // openai & compatible APIs
            $base = trim((string)($providerSettings['endpoint'] ?? 'https://api.openai.com/v1/chat/completions'));
            $base = preg_replace('#/chat/.*$#', '', $base);
            $base = rtrim($base, '/');
            if ($base === '') {
                $base = 'https://api.openai.com/v1';
            }
            if (!preg_match('#/v\d+$#', $base)) {
                $base .= '/v1';
            }
            $endpoint = $base . '/models?limit=1';
            $headers = [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ];
            break;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $errors[] = $label . ' request failed: ' . curl_error($ch);
        curl_close($ch);
        return;
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
        $messages[] = $label . ' connectivity check succeeded (HTTP ' . $status . ').';
    } elseif ($status === 401) {
        $errors[] = $label . ' returned HTTP 401 (unauthorized). Double-check the key and permissions.';
    } else {
        $snippet = trim(substr($response, 0, 200));
        $errors[] = $label . ' responded with HTTP ' . $status . '. Body snippet: ' . $snippet;
    }
}

$settings = load_ai_settings($root, $settingsFile);
$currentProvider = $settings['provider'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedProvider = $_POST['provider'] ?? $currentProvider;
    if (!isset($settings['providers'][$selectedProvider])) {
        $selectedProvider = 'openai';
    }
    $settings['provider'] = $selectedProvider;

    $providerInput = $_POST['providers'] ?? [];
    foreach ($settings['providers'] as $providerKey => $providerDefaults) {
        $input = $providerInput[$providerKey] ?? [];
        foreach ($providerDefaults as $field => $defaultValue) {
            if ($field === 'max_tokens') {
                $value = isset($input[$field]) ? (int)$input[$field] : (int)$defaultValue;
                $settings['providers'][$providerKey][$field] = max(1, $value);
            } else {
                $value = isset($input[$field]) ? trim((string)$input[$field]) : (string)$defaultValue;
                $settings['providers'][$providerKey][$field] = $value;
            }
        }
    }

    $activeSettings = $settings['providers'][$selectedProvider];
    $validationError = validate_ai_key($selectedProvider, trim((string)($activeSettings['api_key'] ?? '')));

    $wantsSave = isset($_POST['save_api_key']);
    $wantsTest = isset($_POST['test_connection']);

    if ($validationError) {
        $errors[] = $validationError;
    } else {
        if ($wantsSave) {
            if (save_ai_settings($settingsFile, $settings, $errors)) {
                $messages[] = provider_label($selectedProvider) . ' settings saved.';
            }
        }

        if ($wantsTest) {
            test_ai_provider($selectedProvider, $activeSettings, $messages, $errors);
        }
    }

    $currentProvider = $selectedProvider;
}

$activeSettings = $settings['providers'][$currentProvider];
$providerLabel = provider_label($currentProvider);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Provider Settings</title>
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: system-ui, -apple-system, sans-serif; min-height: 100vh; margin: 0; }
        .wrap { max-width: 720px; margin: 0 auto; padding: 32px 16px 80px; }
        a.back { color: #60a5fa; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 24px; }
        a.back:hover { text-decoration: underline; }
        h1 { font-size: 28px; margin-bottom: 12px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 24px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.35); margin-bottom: 24px; }
        label { display:block; margin-bottom:8px; font-weight:600; }
        select, input[type="text"], input[type="number"] { width:100%; padding:10px; border-radius:8px; border:1px solid #334155; background:#0f172a; color:#e2e8f0; font-size:15px; }
        input[type="number"] { max-width: 180px; }
        .btn { background: #3b82f6; border: none; color: white; padding: 14px 28px; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 15px; }
        .btn:hover { background: #2563eb; }
        .btn-secondary { background: rgba(59, 130, 246, 0.12); border: 1px solid #475569; color: #e2e8f0; padding: 12px 24px; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 15px; }
        .btn-secondary:hover { background: rgba(59, 130, 246, 0.2); }
        .btn-row { display:flex; gap:12px; flex-wrap:wrap; margin-top:16px; }
        .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: rgba(34, 197, 94, 0.12); border: 1px solid rgba(34, 197, 94, 0.45); color: #bbf7d0; }
        .alert-error { background: rgba(248, 113, 113, 0.12); border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; }
        .provider-panel { display:none; border:1px solid #334155; border-radius:12px; padding:18px; margin-top:18px; background: rgba(15, 23, 42, 0.65); }
        .provider-panel.active { display:block; }
        .provider-panel h3 { margin-top:0; margin-bottom:12px; font-size:18px; }
        .muted { color:#94a3b8; font-size:13px; margin-top:8px; }
        .field-grid { display:grid; gap:12px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
    </style>
</head>
<body>
    <div class="wrap">
        <a class="back" href="/admin.php">⬅ Back to Admin</a>
        <h1>AI Provider Settings</h1>
        <p class="muted" style="margin:0 0 18px 0;">Select a provider, enter its credentials, and optionally test connectivity. These settings power AI-assisted field extraction throughout the admin tools.</p>

        <div class="card">
            <form method="post">
                <label for="provider">Active Provider</label>
                <select id="provider" name="provider">
                    <?php foreach ($settings['providers'] as $providerKey => $providerSettings): ?>
                        <option value="<?= htmlspecialchars($providerKey) ?>" <?= $providerKey === $currentProvider ? 'selected' : '' ?>><?= htmlspecialchars(provider_label($providerKey)) ?></option>
                    <?php endforeach; ?>
                </select>

                <div class="provider-panel" data-provider="openai">
                    <h3>OpenAI &amp; Compatible APIs</h3>
                    <div class="field-grid">
                        <div>
                            <label for="openai-key">API Key</label>
                            <input type="text" id="openai-key" name="providers[openai][api_key]" value="<?= htmlspecialchars($settings['providers']['openai']['api_key']) ?>" placeholder="sk-..." autocomplete="off">
                        </div>
                        <div>
                            <label for="openai-model">Model ID</label>
                            <input type="text" id="openai-model" name="providers[openai][model]" value="<?= htmlspecialchars($settings['providers']['openai']['model']) ?>" placeholder="gpt-4o-mini">
                        </div>
                        <div>
                            <label for="openai-endpoint">Endpoint</label>
                            <input type="text" id="openai-endpoint" name="providers[openai][endpoint]" value="<?= htmlspecialchars($settings['providers']['openai']['endpoint']) ?>" placeholder="https://api.openai.com/v1/chat/completions">
                        </div>
                    </div>
                    <p class="muted">Works with OpenAI, OpenRouter, Groq, Mistral, and any OpenAI-compatible Chat Completions API.</p>
                </div>

                <div class="provider-panel" data-provider="azure_openai">
                    <h3>Azure OpenAI</h3>
                    <div class="field-grid">
                        <div>
                            <label for="azure-key">API Key</label>
                            <input type="text" id="azure-key" name="providers[azure_openai][api_key]" value="<?= htmlspecialchars($settings['providers']['azure_openai']['api_key']) ?>" placeholder="Azure resource key" autocomplete="off">
                        </div>
                        <div>
                            <label for="azure-endpoint">Resource Endpoint</label>
                            <input type="text" id="azure-endpoint" name="providers[azure_openai][endpoint]" value="<?= htmlspecialchars($settings['providers']['azure_openai']['endpoint']) ?>" placeholder="https://your-resource.openai.azure.com">
                        </div>
                        <div>
                            <label for="azure-deployment">Deployment Name</label>
                            <input type="text" id="azure-deployment" name="providers[azure_openai][deployment]" value="<?= htmlspecialchars($settings['providers']['azure_openai']['deployment']) ?>" placeholder="gpt-4o-mini">
                        </div>
                        <div>
                            <label for="azure-version">API Version</label>
                            <input type="text" id="azure-version" name="providers[azure_openai][api_version]" value="<?= htmlspecialchars($settings['providers']['azure_openai']['api_version']) ?>" placeholder="2024-02-15-preview">
                        </div>
                    </div>
                    <p class="muted">Endpoint should match your Azure resource URL. Deployment corresponds to the model deployment name inside Azure OpenAI Studio.</p>
                </div>

                <div class="provider-panel" data-provider="anthropic">
                    <h3>Anthropic Claude</h3>
                    <div class="field-grid">
                        <div>
                            <label for="anthropic-key">API Key</label>
                            <input type="text" id="anthropic-key" name="providers[anthropic][api_key]" value="<?= htmlspecialchars($settings['providers']['anthropic']['api_key']) ?>" placeholder="sk-ant-..." autocomplete="off">
                        </div>
                        <div>
                            <label for="anthropic-model">Model ID</label>
                            <input type="text" id="anthropic-model" name="providers[anthropic][model]" value="<?= htmlspecialchars($settings['providers']['anthropic']['model']) ?>" placeholder="claude-3-sonnet-20240229">
                        </div>
                        <div>
                            <label for="anthropic-endpoint">Endpoint</label>
                            <input type="text" id="anthropic-endpoint" name="providers[anthropic][endpoint]" value="<?= htmlspecialchars($settings['providers']['anthropic']['endpoint']) ?>" placeholder="https://api.anthropic.com/v1/messages">
                        </div>
                        <div>
                            <label for="anthropic-version">API Version</label>
                            <input type="text" id="anthropic-version" name="providers[anthropic][version]" value="<?= htmlspecialchars($settings['providers']['anthropic']['version']) ?>" placeholder="2023-06-01">
                        </div>
                        <div>
                            <label for="anthropic-max">Max Tokens</label>
                            <input type="number" id="anthropic-max" name="providers[anthropic][max_tokens]" value="<?= (int)$settings['providers']['anthropic']['max_tokens'] ?>" min="1">
                        </div>
                    </div>
                    <p class="muted">Max tokens limits the number of tokens requested when enhancing fields. Lower values reduce cost.</p>
                </div>

                <div class="btn-row">
                    <button type="submit" name="save_api_key" value="1" class="btn">Save Settings</button>
                    <button type="submit" name="test_connection" value="1" class="btn-secondary">Test Connection</button>
                </div>
            </form>
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

        <p class="muted">Current provider: <strong><?= htmlspecialchars($providerLabel) ?></strong>. Settings are stored in <code>config/ai-settings.json</code>. Back up the file before deploying to production.</p>
    </div>

    <script>
        (function () {
            const select = document.getElementById('provider');
            const panels = Array.from(document.querySelectorAll('.provider-panel'));
            function syncPanels() {
                panels.forEach(panel => {
                    panel.classList.toggle('active', panel.dataset.provider === select.value);
                });
            }
            select.addEventListener('change', syncPanels);
            syncPanels();
        })();
    </script>
</body>
</html>
