<?php
/**
 * Email Settings Admin Page
 */

use Permits\Mailer;
use Permits\SystemSettings;

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$stmt = $db->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch(\PDO::FETCH_ASSOC);

if (!$currentUser || $currentUser['role'] !== 'admin') {
    http_response_code(403);
    echo '<h1>Access denied</h1><p>Administrator role required.</p>';
    exit;
}

$feedback = [
    'success' => '',
    'error'   => '',
];

$testFeedback = [
    'success' => '',
    'error'   => '',
    'details' => [],
];

$defaults = [
    'email_enabled'     => 'false',
    'mail_driver'       => 'smtp',
    'smtp_host'         => '',
    'smtp_port'         => '587',
    'smtp_user'         => '',
    'smtp_pass'         => '',
    'smtp_secure'       => 'tls',
    'smtp_timeout'      => '30',
    'mail_from_address' => '',
    'mail_from_name'    => 'Permits System',
];

try {
    $settings = SystemSettings::load($db, [], $defaults);
} catch (\Throwable $e) {
    $settings = $defaults;
    $feedback['error'] = 'Unable to load settings: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        try {
            $emailEnabled = isset($_POST['email_enabled']);
            $driver = strtolower(trim($_POST['mail_driver'] ?? 'smtp'));
            $allowedDrivers = ['smtp', 'mail', 'log'];
            if (!in_array($driver, $allowedDrivers, true)) {
                $driver = 'smtp';
            }

            $host = trim((string)($_POST['smtp_host'] ?? ''));
            $port = (int)($_POST['smtp_port'] ?? 587);
            if ($port <= 0) {
                $port = 587;
            }

            $smtpUser = trim((string)($_POST['smtp_user'] ?? ''));
            $smtpPassInput = (string)($_POST['smtp_pass'] ?? '');
            $smtpPass = $smtpPassInput !== '' ? $smtpPassInput : ($settings['smtp_pass'] ?? '');
            $smtpSecure = strtolower(trim((string)($_POST['smtp_secure'] ?? 'tls')));
            if (!in_array($smtpSecure, ['tls', 'ssl', 'none'], true)) {
                $smtpSecure = 'tls';
            }
            if ($smtpSecure === 'none') {
                $smtpSecure = '';
            }

            $timeout = (int)($_POST['smtp_timeout'] ?? 30);
            if ($timeout <= 0) {
                $timeout = 30;
            }

            $fromAddress = trim((string)($_POST['mail_from_address'] ?? ''));
            $fromName = trim((string)($_POST['mail_from_name'] ?? 'Permits System'));

            $payload = [
                'email_enabled'     => $emailEnabled ? 'true' : 'false',
                'mail_driver'       => $driver,
                'smtp_host'         => $host,
                'smtp_port'         => (string)$port,
                'smtp_user'         => $smtpUser,
                'smtp_pass'         => $smtpPass,
                'smtp_secure'       => $smtpSecure,
                'smtp_timeout'      => (string)$timeout,
                'mail_from_address' => $fromAddress,
                'mail_from_name'    => $fromName,
            ];

            SystemSettings::save($db, $payload);

            $settings = array_replace($settings, $payload);
            $feedback['success'] = 'Email settings saved successfully.';

            if (function_exists('logActivity')) {
                logActivity('settings_updated', 'mail', 'setting', 'email', 'SMTP settings updated by ' . ($currentUser['username'] ?? 'admin'));
            }
        } catch (\Throwable $e) {
            $feedback['error'] = 'Failed to save settings: ' . $e->getMessage();
        }
    } elseif ($action === 'test') {
        try {
            $recipient = trim((string)($_POST['test_email'] ?? ''));
            if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Enter a valid email address to send the test to.');
            }

            $mailer = Mailer::fromDatabase($db);
            $subject = 'Permits Email Test - ' . date('Y-m-d H:i:s');
            $body = '<p>This is a test email sent from the Permits admin panel.</p>' .
                '<p>If you are reading this, the SMTP settings saved in the admin area are working.</p>' .
                '<p>Timestamp: ' . htmlspecialchars(date('c'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';

            $result = $mailer->send($recipient, $subject, $body);

            $mailerOptions = SystemSettings::mailerOptions($db);
            $effectiveDriver = strtolower((string)($mailerOptions['driver'] ?? ($settings['mail_driver'] ?? 'smtp')));
            $enabled = ($settings['email_enabled'] ?? 'false') === 'true';

            if ($result) {
                if (!$enabled || $effectiveDriver === 'log') {
                    $testFeedback['success'] = 'Test email written to the mail log (email delivery is currently disabled). Check storage/mail for the entry.';
                } else {
                    $testFeedback['success'] = 'Test email dispatched successfully. Check the destination inbox in a moment.';
                }
            } else {
                $testFeedback['error'] = 'Mailer returned false when attempting to send the test email.';
            }

            $testFeedback['details'] = [
                'Recipient'      => $recipient,
                'Driver'         => $effectiveDriver,
                'Email enabled'  => $enabled ? 'Yes' : 'No',
                'SMTP host'      => $settings['smtp_host'] ?: '(empty)',
                'SMTP port'      => $settings['smtp_port'] ?: '(default)',
                'Encryption'     => $settings['smtp_secure'] !== '' ? strtoupper($settings['smtp_secure']) : 'None',
                'From address'   => $settings['mail_from_address'] ?: '(default)',
                'Sent at'        => date('Y-m-d H:i:s'),
            ];

            if (function_exists('logActivity')) {
                logActivity('settings_tested', 'mail', 'setting', 'email', 'SMTP test email triggered by ' . ($currentUser['username'] ?? 'admin'));
            }
        } catch (\Throwable $e) {
            $testFeedback['error'] = 'Test email failed: ' . $e->getMessage();
            $testFeedback['details'] = [
                'Recipient' => trim((string)($_POST['test_email'] ?? '')),
                'Driver'    => strtolower((string)($settings['mail_driver'] ?? 'smtp')),
            ];
        }
    }
}

// Avoid echoing the stored password into the form field.
$displayPassword = '';

$mailerOptions = SystemSettings::mailerOptions($db);
$emailEnabled = ($settings['email_enabled'] ?? 'false') === 'true';
$effectiveDriver = strtolower((string)($mailerOptions['driver'] ?? ($settings['mail_driver'] ?? 'smtp')));
$logPath = $_ENV['MAIL_LOG_PATH'] ?? ($root . '/storage/mail');

$diagnostics = [
    'Email enabled'   => $emailEnabled ? 'Yes' : 'No',
    'Active driver'   => $emailEnabled ? $effectiveDriver : $effectiveDriver . ' (email disabled)',
    'SMTP host'       => $settings['smtp_host'] ?: ($_ENV['SMTP_HOST'] ?? $_ENV['MAIL_HOST'] ?? '(empty)'),
    'SMTP port'       => $settings['smtp_port'] ?: ($_ENV['SMTP_PORT'] ?? $_ENV['MAIL_PORT'] ?? '(default)'),
    'Encryption'      => $settings['smtp_secure'] !== '' ? strtoupper($settings['smtp_secure']) : 'None',
    'Username'        => $settings['smtp_user'] ?: '(empty)',
    'From address'    => $settings['mail_from_address'] ?: ($_ENV['MAIL_FROM'] ?? $_ENV['MAIL_FROM_ADDRESS'] ?? '(default)'),
    'From name'       => $settings['mail_from_name'] ?: ($_ENV['MAIL_FROM_NAME'] ?? 'Permits System'),
    'Mail log path'   => $logPath,
];

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email Settings</title>
    <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; }
        .wrap { max-width: 960px; margin: 0 auto; padding: 32px 16px 80px; }
        a.back { color: #60a5fa; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 24px; }
        a.back:hover { text-decoration: underline; }
        h1 { font-size: 28px; margin-bottom: 8px; }
        p.lead { color: #94a3b8; margin-bottom: 24px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 24px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.35); }
        form { display: grid; gap: 20px; }
        .grid { display: grid; gap: 18px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        label { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; margin-bottom: 6px; color: #94a3b8; }
        input, select { width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #1f2937; background: #111827; color: #e2e8f0; }
        input[type="checkbox"] { width: auto; }
        .checkbox-label { display: flex; align-items: center; gap: 10px; font-size: 14px; text-transform: none; letter-spacing: normal; color: #e2e8f0; }
        .btn { display: inline-flex; justify-content: center; align-items: center; border: none; border-radius: 10px; padding: 12px 20px; font-weight: 600; cursor: pointer; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-secondary { background: #475569; color: white; }
        .actions { display: flex; justify-content: flex-end; gap: 12px; }
        .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: rgba(34, 197, 94, 0.12); border: 1px solid rgba(34, 197, 94, 0.45); color: #bbf7d0; }
        .alert-error { background: rgba(248, 113, 113, 0.12); border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; }
        .card h3 { margin-top: 28px; margin-bottom: 12px; font-size: 18px; }
        .diagnostic-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
        .diagnostic-list li { background: #111827; border: 1px solid #1f2937; border-radius: 10px; padding: 12px 16px; }
        .diagnostic-list span { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 4px; }
        .log-box { margin-top: 16px; background: #101c34; border: 1px solid #1d3355; border-radius: 12px; padding: 16px; font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace; font-size: 13px; white-space: pre-wrap; word-break: break-word; color: #e2e8f0; }
        @media (max-width: 720px) {
            .actions { flex-direction: column; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <a class="back" href="/admin.php">⬅ Back to Admin</a>
        <h1>Email Settings</h1>
        <p class="lead">Configure how the permits system sends email notifications. Values saved here override the .env defaults.</p>

        <?php if ($feedback['success']): ?>
            <div class="alert alert-success"><?= htmlspecialchars($feedback['success'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($feedback['error']): ?>
            <div class="alert alert-error"><?= htmlspecialchars($feedback['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="post" novalidate>
                <input type="hidden" name="action" value="save">

            <label class="checkbox-label">
                <input type="checkbox" name="email_enabled" value="1" <?= ($settings['email_enabled'] ?? 'false') === 'true' ? 'checked' : '' ?>>
                Enable outbound email delivery
            </label>

            <div class="grid">
                <div>
                    <label for="mail_driver">Delivery Method</label>
                    <select id="mail_driver" name="mail_driver">
                        <?php $selectedDriver = strtolower($settings['mail_driver'] ?? 'smtp'); ?>
                        <option value="smtp" <?= $selectedDriver === 'smtp' ? 'selected' : '' ?>>SMTP (recommended)</option>
                        <option value="mail" <?= $selectedDriver === 'mail' ? 'selected' : '' ?>>PHP mail()</option>
                        <option value="log" <?= $selectedDriver === 'log' ? 'selected' : '' ?>>Log to file only</option>
                    </select>
                </div>
                <div>
                    <label for="smtp_host">SMTP Host</label>
                    <input id="smtp_host" type="text" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="mail.example.com">
                </div>
                <div>
                    <label for="smtp_port">SMTP Port</label>
                    <input id="smtp_port" type="number" name="smtp_port" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" min="1" max="65535">
                </div>
                <div>
                    <label for="smtp_secure">Encryption</label>
                    <?php $secure = $settings['smtp_secure'] ?? 'tls'; ?>
                    <select id="smtp_secure" name="smtp_secure">
                        <option value="tls" <?= $secure === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
                        <option value="ssl" <?= $secure === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="none" <?= $secure === '' ? 'selected' : '' ?>>None</option>
                    </select>
                </div>
            </div>

            <div class="grid">
                <div>
                    <label for="smtp_user">SMTP Username</label>
                    <input id="smtp_user" type="text" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" autocomplete="username">
                </div>
                <div>
                    <label for="smtp_pass">SMTP Password</label>
                    <input id="smtp_pass" type="password" name="smtp_pass" value="<?= $displayPassword; ?>" autocomplete="current-password" placeholder="Leave blank to keep existing">
                </div>
                <div>
                    <label for="smtp_timeout">SMTP Timeout (seconds)</label>
                    <input id="smtp_timeout" type="number" name="smtp_timeout" value="<?= htmlspecialchars($settings['smtp_timeout'] ?? '30', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" min="5" max="120">
                </div>
            </div>

            <div class="grid">
                <div>
                    <label for="mail_from_address">From Email Address</label>
                    <input id="mail_from_address" type="email" name="mail_from_address" value="<?= htmlspecialchars($settings['mail_from_address'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="permits@example.com">
                </div>
                <div>
                    <label for="mail_from_name">From Name</label>
                    <input id="mail_from_name" type="text" name="mail_from_name" value="<?= htmlspecialchars($settings['mail_from_name'] ?? 'Permits System', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="Permits System">
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary">Save Settings</button>
                <a class="btn btn-secondary" href="<?= htmlspecialchars($app->url('admin.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">Cancel</a>
            </div>
            </form>
        </div>

        <div class="card" style="margin-top: 32px; display: grid; gap: 20px;">
            <div>
                <h2 style="margin: 0 0 8px 0;">Send Test Email</h2>
                <p style="color: #94a3b8; margin: 0;">Send a quick test message to confirm your SMTP credentials are working. The message uses the currently saved settings.</p>
            </div>

            <?php if ($testFeedback['success']): ?>
                <div class="alert alert-success" style="margin: 0;">
                    <?= htmlspecialchars($testFeedback['success'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if ($testFeedback['error']): ?>
                <div class="alert alert-error" style="margin: 0;">
                    <?= htmlspecialchars($testFeedback['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form method="post" novalidate style="display: grid; gap: 18px;">
                <input type="hidden" name="action" value="test">
                <div class="grid">
                    <div>
                        <label for="test_email">Test Recipient</label>
                        <input id="test_email" type="email" name="test_email" value="<?= htmlspecialchars($_POST['test_email'] ?? ($settings['mail_from_address'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="you@example.com" required>
                    </div>
                </div>
                <div class="actions" style="justify-content: flex-start;">
                    <button type="submit" class="btn btn-primary">Send Test Email</button>
                </div>
            </form>

            <?php if (!empty($testFeedback['details'])): ?>
                <?php
                    $logLines = [];
                    foreach ($testFeedback['details'] as $label => $value) {
                        $stringValue = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        if ($stringValue === false || $stringValue === null) {
                            $stringValue = '[unserialisable value]';
                        }
                        $logLines[] = $label . ': ' . $stringValue;
                    }
                    $logOutput = implode(PHP_EOL, $logLines);
                ?>
                <div class="log-box"><?= htmlspecialchars($logOutput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <?php endif; ?>

            <h3>Current Mailer Diagnostics</h3>
            <ul class="diagnostic-list">
                <?php foreach ($diagnostics as $label => $value): ?>
                    <li>
                        <span><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                        <?= htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</body>
</html>
