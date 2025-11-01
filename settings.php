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
use Permits\SystemSettings;

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

    if ($action === 'save_branding') {
      $companyName = trim((string)($_POST['company_name'] ?? ''));
      $brandingUpdates = ['company_name' => $companyName];

      $upload = $_FILES['company_logo'] ?? null;
      if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($upload['error'] !== UPLOAD_ERR_OK) {
          throw new RuntimeException('Logo upload failed with error code ' . (int)$upload['error']);
        }

        $maxSize = 2 * 1024 * 1024; // 2 MB
        if (($upload['size'] ?? 0) > $maxSize) {
          throw new RuntimeException('Logo is too large. Maximum size is 2 MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($upload['tmp_name']);
        $allowed = [
          'image/png'  => 'png',
          'image/jpeg' => 'jpg',
          'image/webp' => 'webp',
          'image/svg+xml' => 'svg',
        ];

        if (!isset($allowed[$mime])) {
          throw new RuntimeException('Unsupported logo format. Please upload PNG, JPG, WEBP, or SVG files.');
        }

        $brandingDir = $root . '/uploads/branding';
        if (!is_dir($brandingDir)) {
          if (!mkdir($brandingDir, 0755, true) && !is_dir($brandingDir)) {
            throw new RuntimeException('Unable to create uploads/branding directory.');
          }
        }

        $filename = 'company-logo-' . date('Ymd_His') . '.' . $allowed[$mime];
        $destination = $brandingDir . '/' . $filename;

        if (!move_uploaded_file($upload['tmp_name'], $destination)) {
          throw new RuntimeException('Unable to save uploaded logo.');
        }
        @chmod($destination, 0644);

        $newRelativePath = 'uploads/branding/' . $filename;

        $existingPath = trim((string)($settings['company_logo_path'] ?? ''));
        if ($existingPath !== '') {
          $existingFile = $root . '/' . ltrim($existingPath, '/');
          if (is_file($existingFile)) {
            @unlink($existingFile);
          }
        }

        $brandingUpdates['company_logo_path'] = $newRelativePath;
        $settings['company_logo_path'] = $newRelativePath;
      }

      SystemSettings::save($db, $brandingUpdates);
      $settings = array_merge($settings, $brandingUpdates);
      $success = 'Branding updated successfully';
    }

    if ($action === 'remove_logo') {
      $existingPath = trim((string)($settings['company_logo_path'] ?? ''));
      if ($existingPath !== '') {
        $existingFile = $root . '/' . ltrim($existingPath, '/');
        if (is_file($existingFile)) {
          @unlink($existingFile);
        }
      }

      SystemSettings::save($db, ['company_logo_path' => '']);
      unset($settings['company_logo_path']);
      $success = 'Company logo removed.';
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
$companyName = trim((string)($settings['company_name'] ?? '')) ?: 'Permits System';
$companyLogoPath = trim((string)($settings['company_logo_path'] ?? ''));
$companyLogoUrl = $companyLogoPath !== '' ? asset('/' . ltrim($companyLogoPath, '/')) : null;
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
  <div class="brand-mark">
    <?php if ($companyLogoUrl): ?>
      <img src="<?= $companyLogoUrl ?>" alt="<?= htmlspecialchars($companyName) ?> logo" class="brand-mark__logo">
    <?php endif; ?>
    <div>
      <div class="brand-mark__name"><?= htmlspecialchars($companyName) ?></div>
      <div class="brand-mark__sub">System Settings</div>
    </div>
  </div>
  <div class="top-actions">
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

  <!-- Company Branding -->
  <div class="card">
    <h2>Company Branding</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save_branding">

      <div class="field">
        <label>Company Name</label>
        <input type="text" name="company_name" value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>" placeholder="Your company name">
      </div>

      <?php if ($companyLogoUrl): ?>
        <div class="field">
          <label>Current Logo</label>
          <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
            <img src="<?= $companyLogoUrl ?>" alt="<?= htmlspecialchars($companyName) ?> logo" style="max-width:160px;max-height:120px;border-radius:12px;background:#0a101a;padding:12px;border:1px solid #1f2937;">
            <span style="color:#94a3b8;font-size:13px;">Displayed on dashboards, headers, and printed outputs.</span>
          </div>
        </div>
      <?php endif; ?>

      <div class="field">
        <label>Upload Logo</label>
        <input type="file" name="company_logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
        <p style="color:#94a3b8;font-size:13px;margin-top:8px;">Use a square PNG, JPG, WEBP, or SVG. Recommended 400×400px, max 2&nbsp;MB.</p>
      </div>

      <button type="submit" class="btn btn-accent">Save Branding</button>
    </form>

    <?php if ($companyLogoUrl): ?>
      <form method="post" style="margin-top:12px;">
        <input type="hidden" name="action" value="remove_logo">
        <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Remove the current company logo?')">Remove Logo</button>
      </form>
    <?php endif; ?>
  </div>

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
