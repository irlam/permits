<?php
declare(strict_types=1);

use Permits\Csrf;
use Permits\SystemSettings;
use Permits\UserAccountPolicy;

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/Auth.php';

$auth = new Auth($db);
$auth->requireLogin();
$user = $auth->getCurrentUser();
if ($user === null) {
    http_response_code(401);
    exit('Authentication required.');
}

$message = '';
$messageType = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::validateRequest('account-password')) {
        http_response_code(419);
        $message = 'This form expired. Refresh the page and try again.';
        $messageType = 'error';
    } else {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        try {
            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('The new passwords do not match.');
            }
            $passwordError = UserAccountPolicy::passwordError($newPassword, true);
            if ($passwordError !== null) {
                throw new RuntimeException($passwordError);
            }

            $stmt = $db->pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND status = ? LIMIT 1');
            $stmt->execute([$user['id'], 'active']);
            $currentHash = (string)($stmt->fetchColumn() ?: '');
            if ($currentHash === '' || !password_verify($currentPassword, $currentHash)) {
                throw new RuntimeException('Your current password is incorrect.');
            }
            if (password_verify($newPassword, $currentHash)) {
                throw new RuntimeException('Choose a password you are not already using.');
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            if ($newHash === false) {
                throw new RuntimeException('Unable to secure the new password.');
            }

            $update = $db->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $update->execute([$newHash, $user['id']]);
            session_regenerate_id(true);

            if (function_exists('logActivity')) {
                logActivity('password_changed', 'auth', 'user', (string)$user['id'], 'User changed their own password');
            }

            $message = 'Password changed successfully.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to change the password.';
            $messageType = 'error';
            if (!$e instanceof RuntimeException) {
                error_log('Self-service password change failed: ' . $e->getMessage());
            }
        }
    }
}

$branding = SystemSettings::branding($db);
$companyName = $branding['company_name'];
$companyLogoPath = $branding['company_logo_path'];
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;
$brandingCss = SystemSettings::brandingCssVariables($branding);
?>
<!doctype html>
<html lang="en" style="<?= htmlspecialchars($brandingCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="<?= htmlspecialchars($branding['primary_colour'], ENT_QUOTES, 'UTF-8') ?>">
    <title>My account · <?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= asset('/assets/app.css') ?>">
    <style>
        .account-card { width: min(100%, 580px); margin: 30px auto; }
        .account-card label { display:block; margin: 16px 0 7px; color:#cbd5e1; font-weight:650; }
        .account-card input { width:100%; min-height:48px; padding:11px 13px; border:1px solid #334155; border-radius:10px; background:#0b1220; color:#f8fafc; font:inherit; }
        .account-card input:focus { outline:3px solid rgba(var(--brand-primary-rgb),.24); border-color:var(--brand-primary); }
        .account-card .hint { color:#94a3b8; font-size:.9rem; line-height:1.5; }
        .account-card .alert { margin:16px 0; padding:13px 15px; border-radius:10px; }
        .account-card .alert-success { background:#064e3b; color:#d1fae5; }
        .account-card .alert-error { background:#7f1d1d; color:#fee2e2; }
    </style>
</head>
<body class="theme-dark">
<header class="site-header">
    <div class="brand-mark">
        <?php if ($companyLogoUrl): ?><img src="<?= htmlspecialchars($companyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?> logo" class="brand-mark__logo"><?php endif; ?>
        <div><div class="brand-mark__name"><?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><div class="brand-mark__sub">My account</div></div>
    </div>
    <div class="site-header__actions">
        <a class="btn btn-secondary" href="<?= htmlspecialchars($app->url('dashboard.php'), ENT_QUOTES, 'UTF-8') ?>">Dashboard</a>
        <a class="btn btn-secondary" href="<?= htmlspecialchars($app->url('logout.php'), ENT_QUOTES, 'UTF-8') ?>">Logout</a>
    </div>
</header>
<main class="site-container">
    <section class="surface-card account-card">
        <div class="card-header"><h1 style="font-size:1.45rem;margin:0">Change my password</h1></div>
        <p class="hint">Signed in as <?= htmlspecialchars((string)$user['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>. Use a password that you do not use on another website.</p>
        <?php if ($message !== ''): ?><div class="alert alert-<?= $messageType ?>" role="alert"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
            <?= Csrf::getFormField('account-password') ?>
            <label for="current_password">Current password</label>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            <label for="new_password">New password</label>
            <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="12" required>
            <p class="hint">At least 12 characters, including upper-case, lower-case and a number.</p>
            <label for="confirm_password">Confirm new password</label>
            <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" required>
            <button class="btn btn-primary" type="submit" style="width:100%;margin-top:20px">Change password</button>
        </form>
    </section>
</main>
</body>
</html>
