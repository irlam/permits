<?php
declare(strict_types=1);

use Permits\Csrf;
use Permits\SystemSettings;
/**
 * Team login page.
 * 
 * File Path: /login.php
 * Description: Secure team authentication
 * Created: 24/10/2025
 * Last Modified: 24/10/2025
 * 
 */

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/Auth.php';

/** Accept only a local application path for post-login redirects. */
function safeLoginRedirect(string $candidate): string
{
    $candidate = trim($candidate);
    if (
        $candidate === ''
        || $candidate[0] !== '/'
        || str_starts_with($candidate, '//')
        || str_contains($candidate, '\\')
        || preg_match('/[\x00-\x1f\x7f]/', $candidate) === 1
    ) {
        return '/dashboard.php';
    }

    $parts = parse_url($candidate);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return '/dashboard.php';
    }

    $path = strtolower((string)($parts['path'] ?? ''));
    if (in_array($path, ['/login.php', '/logout.php'], true)) {
        return '/dashboard.php';
    }

    return $candidate;
}

$auth = new Auth($db);
$redirectPath = safeLoginRedirect((string)($_POST['redirect'] ?? $_GET['redirect'] ?? '/dashboard.php'));

if ($auth->isLoggedIn()) {
    header('Location: ' . $app->url($redirectPath));
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateRequest('login')) {
        http_response_code(419);
        $error = 'This login form has expired. Please refresh the page and try again.';
    }

    $emailInput = $_POST['email'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    
    if ($error !== null) {
        // Keep the clear page-expired message set above.
    } elseif (
        $email === ''
        || $password === ''
        || strlen($email) > 255
        || strlen($password) > 4096
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    ) {
        $error = 'Please enter both email and password.';
    } else {
        $result = $auth->login($email, $password);
        if (!empty($result['success'])) {
            header('Location: ' . $app->url($redirectPath));
            exit;
        }

        $error = isset($result['retry_after'])
            ? 'Too many login attempts. Please wait 15 minutes and try again.'
            : 'Invalid email or password.';
    }
}

$branding = SystemSettings::branding($db);
$companyName = $branding['company_name'];
$companyLogoPath = $branding['company_logo_path'];
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;
$brandingCss = SystemSettings::brandingCssVariables($branding);
?>
<!DOCTYPE html>
<html lang="en" style="<?= htmlspecialchars($brandingCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Sign In - <?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
    <meta name="theme-color" content="<?= htmlspecialchars($branding['primary_colour'], ENT_QUOTES, 'UTF-8') ?>">
    <style>
      /* Minimal input styling to blend with dark theme */
      .form-group label{display:block;font-weight:600;margin:0 0 6px;color:#cbd5e1}
      .form-group input{width:100%;padding:10px 12px;background:#0b1220;border:1px solid #334155;border-radius:8px;color:#e5e7eb}
      .form-group input:focus{outline:none;border-color:var(--brand-primary);box-shadow:0 0 0 3px rgba(var(--brand-primary-rgb),.18)}
      .login-card{max-width:420px;margin:0 auto}
    </style>
    </head>
<body class="theme-dark">
  <header class="site-header">
    <div class="brand-mark">
      <?php if ($companyLogoUrl): ?>
        <img src="<?= htmlspecialchars($companyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> logo" class="brand-mark__logo">
      <?php endif; ?>
      <div>
        <div class="brand-mark__name"><?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <div class="brand-mark__sub">Team sign in</div>
      </div>
    </div>
    <div class="site-header__actions">
      <a class="btn btn-secondary" href="<?= htmlspecialchars($app->url('/'), ENT_QUOTES, 'UTF-8') ?>">Home</a>
    </div>
  </header>
  <main class="site-container">
    <section class="surface-card login-card">
      <div class="card-header">
        <h3>Team sign in</h3>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error" role="alert">
          <?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="<?= htmlspecialchars($app->url('login.php'), ENT_QUOTES, 'UTF-8') ?>">
        <?= Csrf::getFormField('login') ?>
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectPath, ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-group" style="margin-bottom:14px;">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" required autofocus autocomplete="username" value="<?= htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
        <div class="form-group" style="margin-bottom:18px;">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">Sign in</button>
      </form>
    </section>

    <div style="text-align:center;color:#94a3b8;margin-top:16px;">
      <a class="btn btn-ghost" href="<?= htmlspecialchars($app->url('/'), ENT_QUOTES, 'UTF-8') ?>">&larr; Back to Homepage</a>
    </div>
  </main>
</body>
</html>
