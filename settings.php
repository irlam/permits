<?php
/**
 * Permits System - Settings Page
 * 
 * Description: Application settings and configuration interface
 * Name: settings.php
 * Last Updated: 21/10/2025 21:03:42 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Theme settings and customization
 * - Email notification configuration
 * - System preferences
 * - User profile settings
 * 
 * Features:
 * - Theme toggle (light/dark)
 * - Email SMTP configuration
 * - User profile updates
 * - System settings
 */

// Load application bootstrap
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

use Permits\Auth;

// Initialize auth
$auth = new Auth($db);

$error = '';
$success = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_theme') {
            $theme = $_POST['theme'] ?? 'dark';
            $stmt = $db->pdo->prepare("
                INSERT INTO settings (`key`, value, updated_at) 
                VALUES ('theme', ?, NOW())
                ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()
            ");
            $stmt->execute([$theme, $theme]);
            $success = 'Theme updated successfully';
        }
        
        if ($action === 'update_email') {
            $emailEnabled = isset($_POST['email_enabled']) ? 'true' : 'false';
            $smtpHost = $_POST['smtp_host'] ?? '';
            $smtpPort = $_POST['smtp_port'] ?? '587';
            $smtpUser = $_POST['smtp_user'] ?? '';
            $smtpFrom = $_POST['smtp_from'] ?? '';
            
            $settings = [
                'email_enabled' => $emailEnabled,
                'smtp_host' => $smtpHost,
                'smtp_port' => $smtpPort,
                'smtp_user' => $smtpUser,
                'smtp_from' => $smtpFrom,
            ];
            
            foreach ($settings as $key => $value) {
                $stmt = $db->pdo->prepare("
                    INSERT INTO settings (`key`, value, updated_at) 
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()
                ");
                $stmt->execute([$key, $value, $value]);
            }
            
            $success = 'Email settings updated successfully';
        }
    } catch (\Exception $e) {
        $error = 'Error updating settings: ' . $e->getMessage();
    }
}

// Load current settings
$settings = [];
try {
    $stmt = $db->pdo->query("SELECT `key`, value FROM settings");
    while ($row = $stmt->fetch()) {
        $settings[$row['key']] = $row['value'];
    }
} catch (\Exception $e) {
    $error = 'Settings table not found. Run: php bin/migrate-features.php';
}

$tpls = [];
try {
    $tpls = $db->pdo->query("SELECT id, name, version FROM form_templates ORDER BY name, version DESC")->fetchAll();
} catch (\Exception $e) {
    // Ignore
}

$base = $_ENV['APP_URL'] ?? '/';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#0ea5e9">
  <title>Settings - Permits</title>
  <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
</head>
<body>
<header class="top">
  <h1>⚙️ Settings</h1>
  <div style="display: flex; gap: 12px;">
  <a class="btn" href="<?php echo htmlspecialchars($app->url('dashboard.php')); ?>">Dashboard</a>
  <a class="btn" href="<?php echo htmlspecialchars($app->url('/')); ?>">Home</a>
  </div>
</header>

<section class="grid">
  <?php if ($error): ?>
    <div class="card" style="grid-column: 1/-1; background: #fef2f2; border-color: #fecaca; color: #991b1b;">
      <strong>⚠ Error:</strong> <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="card" style="grid-column: 1/-1; background: #f0fdf4; border-color: #bbf7d0; color: #166534;">
      <strong>✓ Success:</strong> <?= htmlspecialchars($success) ?>
    </div>
  <?php endif; ?>

  <!-- Theme Settings -->
  <div class="card">
    <h2>Theme Settings</h2>
    <form method="post">
      <input type="hidden" name="action" value="update_theme">
      <div class="field">
        <label>Theme</label>
        <select name="theme">
          <option value="dark" <?= ($settings['theme'] ?? 'dark') === 'dark' ? 'selected' : '' ?>>Dark (Default)</option>
          <option value="light" <?= ($settings['theme'] ?? 'dark') === 'light' ? 'selected' : '' ?>>Light</option>
        </select>
      </div>
      <button type="submit" class="btn btn-accent">Save Theme</button>
    </form>
  </div>

  <!-- Email Settings -->
  <div class="card">
    <h2>Email Notifications</h2>
    <form method="post">
      <input type="hidden" name="action" value="update_email">
      
      <div class="field">
        <label>
          <input type="checkbox" name="email_enabled" value="1" <?= ($settings['email_enabled'] ?? 'false') === 'true' ? 'checked' : '' ?>>
          Enable Email Notifications
        </label>
      </div>
      
      <div class="field">
        <label>SMTP Host</label>
        <input type="text" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>" placeholder="smtp.example.com">
      </div>
      
      <div class="field">
        <label>SMTP Port</label>
        <input type="number" name="smtp_port" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>">
      </div>
      
      <div class="field">
        <label>SMTP Username</label>
        <input type="text" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>">
      </div>
      
      <div class="field">
        <label>From Email Address</label>
        <input type="email" name="smtp_from" value="<?= htmlspecialchars($settings['smtp_from'] ?? 'noreply@permits.local') ?>">
      </div>
      
      <button type="submit" class="btn btn-accent">Save Email Settings</button>
    </form>
  </div>

  <!-- User Information (if authenticated) -->
  <?php if ($auth->isAuthenticated()): ?>
    <?php $user = $auth->getCurrentUser(); ?>
    <div class="card">
      <h2>Current User</h2>
      <div class="field">
        <label>Username</label>
        <value><?= htmlspecialchars($user['username']) ?></value>
      </div>
      <div class="field">
        <label>Email</label>
        <value><?= htmlspecialchars($user['email']) ?></value>
      </div>
      <div class="field">
        <label>Role</label>
        <value><?= htmlspecialchars(ucfirst($user['role'])) ?></value>
      </div>
      <div class="field">
        <label>Last Login</label>
        <value><?= $user['last_login'] ? date('M d, Y g:i A', strtotime($user['last_login'])) : 'Never' ?></value>
      </div>
      <a href="/logout" class="btn">Logout</a>
    </div>
  <?php endif; ?>

  <!-- System Information -->
  <div class="card">
    <h2>System Information</h2>
    <div class="field">
      <label>PHP Version</label>
      <value><?= PHP_VERSION ?></value>
    </div>
    <div class="field">
      <label>Database Driver</label>
      <value><?= $db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) ?></value>
    </div>
    <div class="field">
      <label>App URL</label>
      <value><?= htmlspecialchars($_ENV['APP_URL'] ?? 'Not set') ?></value>
    </div>
  </div>
</section>

<script src="/assets/app.js"></script>
</body>
</html>
