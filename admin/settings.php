<?php
/**
 * Admin Settings - Company Branding & Configuration
 * 
 * File Path: /admin/settings.php
 * Description: Manage company branding (logo, name) and system configuration
 * Created: 01/11/2025
 * Last Modified: 01/11/2025
 * 
 * Features:
 * - Company name management
 * - Logo upload and storage
 * - Logo preview and removal
 * - System configuration
 */

// Load application bootstrap
require __DIR__ . '/../vendor/autoload.php';
[$app, $db, $root] = require_once __DIR__ . '/../src/bootstrap.php';

use Permits\SystemSettings;

// Start session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

// Get current user and verify admin role
$stmt = $db->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || $currentUser['role'] !== 'admin') {
    http_response_code(403);
    echo '<h1>Access denied</h1><p>Administrator role required.</p>';
    exit;
}

$error = '';
$success = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
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
  <title>Admin Settings - Permits</title>
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
      <div class="brand-mark__sub">Admin Settings</div>
    </div>
  </div>
  <div class="top-actions">
  <a class="btn" href="<?php echo htmlspecialchars($app->url('admin.php')); ?>">Admin Panel</a>
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
        <p style="color:#94a3b8;font-size:13px;margin-top:4px;">Displayed in site headers and branding areas throughout the system.</p>
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

  <!-- Documentation -->
  <div class="card">
    <h2>About This Page</h2>
    <p>Manage your company's branding and system configuration from this admin settings page.</p>
    <ul style="margin-top:12px;color:#94a3b8;font-size:14px;">
      <li><strong>Company Name:</strong> Used throughout the site in headers and branding.</li>
      <li><strong>Logo:</strong> Appears in site headers, dashboards, and printed permit documents.</li>
      <li><strong>File Support:</strong> PNG, JPG, WEBP, or SVG (max 2 MB)</li>
      <li><strong>Recommended Size:</strong> 400×400px (square aspect ratio recommended)</li>
    </ul>
  </div>
</section>

<script src="/assets/app.js"></script>
</body>
</html>
