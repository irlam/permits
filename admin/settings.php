<?php
/**
 * System Settings - Admin Panel
 * 
 * File Path: /admin/settings.php
 * Description: General system settings including company branding and configuration
 * Created: 24/10/2025
 * Last Modified: 01/11/2025
 * 
 * Features:
 * - Company information and branding
 * - Logo upload and management
 * - System preferences and configuration
 * - Timezone and date format settings
 * - Permit reference prefix settings
 */

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

// Get current user
$stmt = $db->pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if user is admin
if (!$currentUser || $currentUser['role'] !== 'admin') {
    die('<h1>Access Denied</h1><p>Admin access required. <a href="/dashboard.php">Back to Dashboard</a></p>');
}

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'save_settings') {
            $companyName = trim($_POST['company_name'] ?? '');
            $siteTitle = trim($_POST['site_title'] ?? '');
            $timezone = $_POST['timezone'] ?? 'Europe/London';
            $dateFormat = $_POST['date_format'] ?? 'd/m/Y';
            $permitPrefix = trim($_POST['permit_prefix'] ?? 'PTW');
            
            // Read current .env
            $envFile = __DIR__ . '/../.env';
            if (!file_exists($envFile)) {
                $envContent = '';
            } else {
                $envContent = file_get_contents($envFile);
            }
            
            // Update or add settings
            $settings = [
                'COMPANY_NAME' => $companyName,
                'SITE_TITLE' => $siteTitle,
                'TIMEZONE' => $timezone,
                'DATE_FORMAT' => $dateFormat,
                'PERMIT_PREFIX' => $permitPrefix,
            ];
            
            foreach ($settings as $key => $value) {
                // Escape the value and add quotes if it contains spaces or special characters
                if (preg_match('/[\s\'"#]/', $value)) {
                    $escapedValue = '"' . str_replace('"', '\\"', $value) . '"';
                } else {
                    $escapedValue = $value;
                }
                
                if (preg_match("/^{$key}=/m", $envContent)) {
                    // Update existing
                    $envContent = preg_replace("/^{$key}=.*$/m", "{$key}={$escapedValue}", $envContent);
                } else {
                    // Add new
                    $envContent .= "\n{$key}={$escapedValue}";
                }
            }
            
            // Write back to .env
            file_put_contents($envFile, $envContent);
            
            $message = 'System settings saved successfully!';
            $messageType = 'success';
            
            // Reload environment
            $_ENV = array_merge($_ENV, $settings);
        }
        
        if ($action === 'save_branding') {
            $brandingUpdates = [];
            $companyNameFromForm = trim((string)($_POST['company_name_branding'] ?? ''));
            if ($companyNameFromForm !== '') {
                $brandingUpdates['company_name'] = $companyNameFromForm;
            }

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

                // Load existing settings to cleanup old logo
                $dbSettings = [];
                $stmt = $db->pdo->query("SELECT `key`, value FROM settings");
                while ($row = $stmt->fetch()) {
                    $dbSettings[$row['key']] = $row['value'];
                }

                $existingPath = trim((string)($dbSettings['company_logo_path'] ?? ''));
                if ($existingPath !== '') {
                    $existingFile = $root . '/' . ltrim($existingPath, '/');
                    if (is_file($existingFile)) {
                        @unlink($existingFile);
                    }
                }

                $brandingUpdates['company_logo_path'] = $newRelativePath;
            }

            if (!empty($brandingUpdates)) {
                SystemSettings::save($db, $brandingUpdates);
                $message = 'Branding updated successfully';
                $messageType = 'success';
            }
        }

        if ($action === 'remove_logo') {
            // Load existing settings to cleanup logo
            $dbSettings = [];
            $stmt = $db->pdo->query("SELECT `key`, value FROM settings");
            while ($row = $stmt->fetch()) {
                $dbSettings[$row['key']] = $row['value'];
            }

            $existingPath = trim((string)($dbSettings['company_logo_path'] ?? ''));
            if ($existingPath !== '') {
                $existingFile = $root . '/' . ltrim($existingPath, '/');
                if (is_file($existingFile)) {
                    @unlink($existingFile);
                }
            }

            SystemSettings::save($db, ['company_logo_path' => '']);
            $message = 'Company logo removed.';
            $messageType = 'success';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Load current settings
$companyName = $_ENV['COMPANY_NAME'] ?? '';
$siteTitle = $_ENV['SITE_TITLE'] ?? 'Permits System';
$timezone = $_ENV['TIMEZONE'] ?? 'Europe/London';
$dateFormat = $_ENV['DATE_FORMAT'] ?? 'd/m/Y';
$permitPrefix = $_ENV['PERMIT_PREFIX'] ?? 'PTW';

// Remove quotes if they exist
$companyName = trim($companyName, '"');
$siteTitle = trim($siteTitle, '"');

// Load branding settings from database
$dbSettings = [];
try {
    $stmt = $db->pdo->query("SELECT `key`, value FROM settings");
    while ($row = $stmt->fetch()) {
        $dbSettings[$row['key']] = $row['value'];
    }
} catch (\Exception $e) {
    // Settings table might not exist yet
}

$dbCompanyName = trim((string)($dbSettings['company_name'] ?? '')) ?: $companyName;
$companyLogoPath = trim((string)($dbSettings['company_logo_path'] ?? ''));
$companyLogoUrl = $companyLogoPath !== '' ? asset('/' . ltrim($companyLogoPath, '/')) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Admin</title>
    <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
    <style>
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 24px;
        }
        
        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
        }
        
        .alert-success {
            background: #064e3b;
            border: 1px solid #10b981;
            color: #d1fae5;
        }
        
        .alert-error {
            background: #7f1d1d;
            border: 1px solid #ef4444;
            color: #fecaca;
        }
        
        .card-description {
            color: #94a3b8;
            margin-bottom: 24px;
            font-size: 14px;
        }
        
        .label-description {
            font-size: 12px;
            color: #94a3b8;
            font-weight: 400;
            margin-top: 4px;
        }
        
        .info-box {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 16px;
            margin-top: 16px;
        }
        
        .info-box h4 {
            color: #e5e7eb;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .info-box p {
            color: #94a3b8;
            font-size: 13px;
            line-height: 1.6;
        }

        .logo-preview {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .logo-preview img {
            max-width: 160px;
            max-height: 120px;
            border-radius: 12px;
            background: #0a101a;
            padding: 12px;
            border: 1px solid #1f2937;
        }

        .remove-logo-btn {
            margin-top: 12px;
        }
    </style>
</head>
<body>
    <header class="top">
        <div class="brand-mark">
            <?php if ($companyLogoUrl): ?>
                <img src="<?= $companyLogoUrl ?>" alt="<?= htmlspecialchars($dbCompanyName) ?> logo" class="brand-mark__logo">
            <?php endif; ?>
            <div>
                <div class="brand-mark__name"><?= htmlspecialchars($dbCompanyName) ?></div>
                <div class="brand-mark__sub">Admin Settings</div>
            </div>
        </div>
        <div class="top-actions">
            <a class="btn" href="<?php echo htmlspecialchars($app->url('admin.php')); ?>">Admin Panel</a>
            <a class="btn" href="<?php echo htmlspecialchars($app->url('dashboard.php')); ?>">Dashboard</a>
            <a class="btn" href="<?php echo htmlspecialchars($app->url('logout.php')); ?>">Logout</a>
        </div>
    </header>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Company Branding Section -->
        <div class="card">
            <h2>🎨 Company Branding</h2>
            <p class="card-description">Manage your company logo and branding</p>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_branding">

                <div class="field">
                    <label>
                        Company Name (Branding)
                        <div class="label-description">Used in site headers and branding areas</div>
                    </label>
                    <input type="text" name="company_name_branding" value="<?= htmlspecialchars($dbCompanyName) ?>" placeholder="Your company name">
                </div>

                <?php if ($companyLogoUrl): ?>
                    <div class="field">
                        <label>Current Logo</label>
                        <div class="logo-preview">
                            <img src="<?= $companyLogoUrl ?>" alt="<?= htmlspecialchars($dbCompanyName) ?> logo">
                            <span style="color:#94a3b8;font-size:13px;">Displayed on dashboards, headers, and printed outputs.</span>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label>
                        Upload Logo
                        <div class="label-description">PNG, JPG, WEBP, or SVG (max 2 MB, recommended 400×400px)</div>
                    </label>
                    <input type="file" name="company_logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
                </div>

                <button type="submit" class="btn btn-accent">💾 Save Branding</button>
            </form>

            <?php if ($companyLogoUrl): ?>
                <form method="post" class="remove-logo-btn">
                    <input type="hidden" name="action" value="remove_logo">
                    <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Remove the current company logo?')">🗑️ Remove Logo</button>
                </form>
            <?php endif; ?>
        </div>
        
        <!-- System Settings Form -->
        <form method="POST">
            <input type="hidden" name="action" value="save_settings">
            
            <!-- Company Information -->
            <div class="card">
                <h2>🏢 Company Information</h2>
                <p class="card-description">Basic information about your organization</p>
                
                <div class="form-group">
                    <label>
                        Company Name (Environment)
                        <div class="label-description">Your company or organization name (stored in .env)</div>
                    </label>
                    <input type="text" name="company_name" value="<?php echo htmlspecialchars($companyName); ?>" placeholder="Your Company Ltd">
                </div>
                
                <div class="form-group">
                    <label>
                        Site Title
                        <div class="label-description">Title displayed in browser and emails</div>
                    </label>
                    <input type="text" name="site_title" value="<?php echo htmlspecialchars($siteTitle); ?>" placeholder="Permits System">
                </div>
            </div>
            
            <!-- Regional Settings -->
            <div class="card">
                <h2>🌍 Regional Settings</h2>
                <p class="card-description">Configure timezone and date formats</p>
                
                <div class="form-group">
                    <label>
                        Timezone
                        <div class="label-description">Default timezone for the system</div>
                    </label>
                    <select name="timezone">
                        <option value="Europe/London" <?php echo $timezone === 'Europe/London' ? 'selected' : ''; ?>>Europe/London (GMT)</option>
                        <option value="Europe/Paris" <?php echo $timezone === 'Europe/Paris' ? 'selected' : ''; ?>>Europe/Paris (CET)</option>
                        <option value="America/New_York" <?php echo $timezone === 'America/New_York' ? 'selected' : ''; ?>>America/New_York (EST)</option>
                        <option value="America/Los_Angeles" <?php echo $timezone === 'America/Los_Angeles' ? 'selected' : ''; ?>>America/Los_Angeles (PST)</option>
                        <option value="Asia/Dubai" <?php echo $timezone === 'Asia/Dubai' ? 'selected' : ''; ?>>Asia/Dubai (GST)</option>
                        <option value="Asia/Tokyo" <?php echo $timezone === 'Asia/Tokyo' ? 'selected' : ''; ?>>Asia/Tokyo (JST)</option>
                        <option value="Australia/Sydney" <?php echo $timezone === 'Australia/Sydney' ? 'selected' : ''; ?>>Australia/Sydney (AEDT)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>
                        Date Format
                        <div class="label-description">How dates are displayed throughout the system</div>
                    </label>
                    <select name="date_format">
                        <option value="d/m/Y" <?php echo $dateFormat === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY (24/10/2025)</option>
                        <option value="m/d/Y" <?php echo $dateFormat === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY (10/24/2025)</option>
                        <option value="Y-m-d" <?php echo $dateFormat === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD (2025-10-24)</option>
                        <option value="d M Y" <?php echo $dateFormat === 'd M Y' ? 'selected' : ''; ?>>DD Mon YYYY (24 Oct 2025)</option>
                    </select>
                </div>
            </div>
            
            <!-- Permit Settings -->
            <div class="card">
                <h2>📋 Permit Settings</h2>
                <p class="card-description">Configure how permits are generated</p>
                
                <div class="form-group">
                    <label>
                        Permit Reference Prefix
                        <div class="label-description">Prefix for permit reference numbers (e.g. PTW-2025-0001)</div>
                    </label>
                    <input type="text" name="permit_prefix" value="<?php echo htmlspecialchars($permitPrefix); ?>" placeholder="PTW" maxlength="10">
                </div>
                
                <div class="info-box">
                    <h4>📌 Reference Number Format:</h4>
                    <p>Permits will be numbered as: <strong><?php echo htmlspecialchars($permitPrefix); ?>-<?php echo date('Y'); ?>-####</strong><br>
                    Example: <?php echo htmlspecialchars($permitPrefix); ?>-<?php echo date('Y'); ?>-0001</p>
                </div>
            </div>
            
            <button type="submit" class="btn btn-accent">💾 Save System Settings</button>
        </form>
        
        <div class="info-box" style="margin-top: 24px;">
            <h4>ℹ️ Important Notes:</h4>
            <p>• Branding settings (company name, logo) are stored in the database<br>
            • System settings are stored in the .env file in the root directory<br>
            • Values with spaces are automatically quoted<br>
            • Some changes may require a page refresh to take effect<br>
            • Make sure to test your configuration after making changes</p>
        </div>
    </div>
</body>
</html>
