<?php
/**
 * Email Settings Admin Page
 */

use Permits\SystemSettings;

use Permits\Csrf;

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/Auth.php';

$auth = new Auth($db);
$currentUser = $auth->requireRoles(['admin']);

$feedback = [
    'success' => '',
    'error'   => '',
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
    error_log('[Permits email settings] Unable to load settings: ' . $e->getMessage());
    $feedback['error'] = 'Email settings could not be loaded. Please try again or ask your hosting provider to check the server log.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateRequest('admin-email-settings')) {
        http_response_code(419);
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Page expired</title><h1>Page expired</h1><p>Refresh the email settings and try again.</p>';
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        try {
            $submitted = $_POST;
            $submitted['email_enabled'] = isset($_POST['email_enabled']) ? 'true' : 'false';
            $payload = SystemSettings::normaliseMailerSettings(
                $submitted,
                (string)($settings['smtp_pass'] ?? '')
            );

            SystemSettings::save($db, $payload);

            $settings = array_replace($settings, $payload);
            $feedback['success'] = 'Email settings saved successfully.';

            if (function_exists('logActivity')) {
                $actor = (string)($currentUser['email'] ?? $currentUser['name'] ?? 'administrator');
                logActivity('settings_updated', 'mail', 'setting', 'email', 'Email settings updated by ' . $actor);
            }
        } catch (\Throwable $e) {
            error_log('[Permits email settings] Unable to save settings: ' . $e->getMessage());
            $feedback['error'] = 'Email settings were not saved. Check the sender, SMTP host, port and timeout, then try again.';
        }
    }
}

// Avoid echoing the stored password into the form field.
$displayPassword = '';

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
        @media (max-width: 720px) {
            .actions { flex-direction: column; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <a class="back" href="/admin.php">&larr; Back to Admin</a>
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
                <?= Csrf::getFormField('admin-email-settings') ?>
                <input type="hidden" name="action" value="save">

            <label class="checkbox-label">
                <input type="checkbox" name="email_enabled" value="1" <?= ($settings['email_enabled'] ?? 'false') === 'true' ? 'checked' : '' ?>>
                Enable outbound email delivery
            </label>
            <p class="lead" style="margin: -8px 0 0;">When disabled, notifications stay safely queued until delivery is enabled. The log method writes full email content to the private <code>data/mail</code> folder and is intended for testing only.</p>

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
                    <input id="smtp_host" type="text" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="mail.example.com" maxlength="253" autocomplete="off">
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
                    <input id="smtp_user" type="text" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" autocomplete="username" maxlength="255">
                </div>
                <div>
                    <label for="smtp_pass">SMTP Password</label>
                    <input id="smtp_pass" type="password" name="smtp_pass" value="<?= $displayPassword; ?>" autocomplete="new-password" placeholder="Leave blank to keep existing" maxlength="4096">
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
                    <input id="mail_from_name" type="text" name="mail_from_name" value="<?= htmlspecialchars($settings['mail_from_name'] ?? 'Permits System', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="Permits System" maxlength="120">
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary">Save Settings</button>
                <a class="btn btn-secondary" href="<?= htmlspecialchars($app->url('admin.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">Cancel</a>
            </div>
            </form>
        </div>
    </div>
</body>
</html>
