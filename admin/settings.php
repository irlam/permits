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
use Permits\Csrf;
require_once __DIR__ . '/../src/Auth.php';

$auth = new Auth($db);
$currentUser = $auth->requireRoles(['admin']);

$message = '';
$messageType = '';

/** Delete only a previously validated file from the dedicated branding folder. */
function removeBrandingLogoFile(string $root, ?string $relativePath): void
{
    $safePath = SystemSettings::normaliseLogoPath($relativePath);
    if ($safePath === null) {
        return;
    }

    $file = $root . '/' . $safePath;
    if (is_file($file) && !unlink($file)) {
        error_log('Unable to remove old branding logo: ' . $file);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateRequest('admin-settings')) {
        http_response_code(419);
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Page expired</title><h1>Page expired</h1><p>Refresh the settings page and try again.</p>';
        exit;
    }

    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'save_settings') {
            $timezone = (string)($_POST['timezone'] ?? 'Europe/London');
            $permitPrefix = strtoupper(trim((string)($_POST['permit_prefix'] ?? 'PTW')));

            if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                throw new RuntimeException('Choose a valid timezone.');
            }
            if (preg_match('/^[A-Z0-9-]{2,10}$/', $permitPrefix) !== 1) {
                throw new RuntimeException('Permit prefix must be 2 to 10 letters, numbers or hyphens.');
            }

            SystemSettings::save($db, [
                'app_timezone' => $timezone,
                'permit_prefix' => $permitPrefix,
            ]);
            date_default_timezone_set($timezone);
            
            $message = 'System settings saved successfully!';
            $messageType = 'success';
            
        }
        
        if ($action === 'save_branding') {
            $oldLogoPath = SystemSettings::companyLogoPath($db);
            $companyNameFromForm = trim((string)($_POST['company_name_branding'] ?? ''));
            if ($companyNameFromForm === '') {
                throw new RuntimeException('Company name is required.');
            }
            if (mb_strlen($companyNameFromForm, 'UTF-8') > 120) {
                throw new RuntimeException('Company name must be 120 characters or fewer.');
            }
            $companyNameFromForm = SystemSettings::normaliseCompanyName($companyNameFromForm);

            $primaryColourInput = trim((string)($_POST['brand_primary_colour'] ?? ''));
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $primaryColourInput) !== 1) {
                throw new RuntimeException('Choose a valid six-digit brand colour.');
            }

            $brandingUpdates = [
                'company_name' => $companyNameFromForm,
                'brand_primary_colour' => SystemSettings::normalisePrimaryColour($primaryColourInput),
            ];

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
                ];

                if (!isset($allowed[$mime])) {
                    throw new RuntimeException('Unsupported logo format. Please upload a PNG, JPG, or WEBP file.');
                }

                $dimensions = @getimagesize($upload['tmp_name']);
                if ($dimensions === false || ($dimensions[0] ?? 0) < 1 || ($dimensions[1] ?? 0) < 1) {
                    throw new RuntimeException('The uploaded file is not a valid image.');
                }
                if ($dimensions[0] > 2400 || $dimensions[1] > 2400) {
                    throw new RuntimeException('Logo dimensions must not exceed 2400 by 2400 pixels.');
                }

                $brandingDir = $root . '/uploads/branding';
                if (!is_dir($brandingDir)) {
                    if (!mkdir($brandingDir, 0755, true) && !is_dir($brandingDir)) {
                        throw new RuntimeException('Unable to create uploads/branding directory.');
                    }
                }

                $filename = 'company-logo-' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
                $destination = $brandingDir . '/' . $filename;

                if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                    throw new RuntimeException('Unable to save uploaded logo.');
                }
                @chmod($destination, 0644);

                $newRelativePath = 'uploads/branding/' . $filename;

                $brandingUpdates['company_logo_path'] = $newRelativePath;
            }

            if (!empty($brandingUpdates)) {
                SystemSettings::save($db, $brandingUpdates);
                if (isset($brandingUpdates['company_logo_path']) && $oldLogoPath !== $brandingUpdates['company_logo_path']) {
                    removeBrandingLogoFile($root, $oldLogoPath);
                }
                $message = 'Company branding saved successfully.';
                $messageType = 'success';
            }
        }

        if ($action === 'remove_logo') {
            removeBrandingLogoFile($root, SystemSettings::companyLogoPath($db));

            SystemSettings::save($db, ['company_logo_path' => '']);
            $message = 'Company logo removed.';
            $messageType = 'success';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Load current settings. Environment values remain safe deployment defaults.
$companyName = trim((string)($_ENV['COMPANY_NAME'] ?? ''), '"');
$generalSettings = SystemSettings::load($db, ['app_timezone', 'permit_prefix'], [
    'app_timezone' => (string)($_ENV['APP_TIMEZONE'] ?? ($_ENV['TIMEZONE'] ?? 'Europe/London')),
    'permit_prefix' => (string)($_ENV['PERMIT_PREFIX'] ?? 'PTW'),
]);
$timezone = $generalSettings['app_timezone'];
$permitPrefix = $generalSettings['permit_prefix'];

$branding = SystemSettings::branding($db, $companyName !== '' ? $companyName : 'Permits System');
$dbCompanyName = $branding['company_name'];
$companyLogoPath = $branding['company_logo_path'];
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;
$brandingCss = SystemSettings::brandingCssVariables($branding);
?>
<!DOCTYPE html>
<html lang="en" style="<?= htmlspecialchars($brandingCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= htmlspecialchars($branding['primary_colour'], ENT_QUOTES, 'UTF-8') ?>">
    <title>System Settings - <?= htmlspecialchars($dbCompanyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
    <style>
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 24px;
        }
        
        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-success {
            background: linear-gradient(135deg, #064e3b 0%, #047857 100%);
            border: 1px solid #10b981;
            color: #d1fae5;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
        }
        
        .alert-error {
            background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 100%);
            border: 1px solid #ef4444;
            color: #fecaca;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.15);
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
        
        .card {
            background: linear-gradient(135deg, #0f172a 0%, #111a2e 100%);
            border: 1px solid #1e293b;
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
        }
        
        .card:hover {
            border-color: #334155;
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.08);
        }
        
        .card h2 {
            color: #f1f5f9;
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .form-group,
        .field {
            margin-bottom: 20px;
        }
        
        .form-group label,
        .field label {
            display: block;
            color: #e2e8f0;
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select,
        .field input,
        .field select {
            width: 100%;
            padding: 12px 16px;
            background: linear-gradient(135deg, #0f172a 0%, #1a202c 100%);
            border: 2px solid #334155;
            border-radius: 12px;
            color: #e2e8f0;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s ease;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .form-group input::placeholder,
        .field input::placeholder {
            color: #64748b;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .field input:focus,
        .field select:focus {
            outline: none;
            border-color: var(--brand-primary);
            background: linear-gradient(135deg, #082f49 0%, #0c4a6e 100%);
            box-shadow: 
                inset 0 2px 4px rgba(0, 0, 0, 0.2),
                0 0 0 3px rgba(6, 182, 212, 0.1),
                0 0 0 1px rgba(6, 182, 212, 0.3);
        }
        
        .form-group select,
        .field select {
            appearance: none;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="%2394a3b8" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>');
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 20px;
            padding-right: 40px;
        }
        
        .form-group select option,
        .field select option {
            background-color: #1e293b;
            color: #e2e8f0;
            padding: 8px;
        }
        
        .info-box {
            background: linear-gradient(135deg, #0f172a 0%, #111a2e 100%);
            border: 2px solid #334155;
            border-radius: 16px;
            padding: 20px;
            margin-top: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
        }
        
        .info-box:hover {
            border-color: #475569;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.08);
        }
        
        .info-box h4 {
            color: var(--brand-primary);
            margin-bottom: 12px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .info-box p {
            color: #cbd5e1;
            font-size: 13px;
            line-height: 1.7;
        }
        
        .info-box strong {
            color: #e2e8f0;
            font-weight: 600;
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
            border-radius: 16px;
            background: linear-gradient(135deg, #0f172a 0%, #1a202c 100%);
            padding: 16px;
            border: 2px solid #334155;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }
        
        .logo-preview img:hover {
            border-color: var(--brand-primary);
            box-shadow: 0 6px 16px rgba(6, 182, 212, 0.2);
        }

        .remove-logo-btn {
            margin-top: 12px;
        }
        
        input[type="file"]::file-selector-button {
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-primary-light) 100%);
            color: #ffffff;
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.3s ease;
            margin-right: 12px;
        }
        
        input[type="file"]::file-selector-button:hover {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3);
            transform: translateY(-1px);
        }
        
        input[type="file"] {
            padding: 12px 16px;
        }

        .colour-picker {
            display: grid;
            grid-template-columns: 72px minmax(0, 1fr);
            gap: 12px;
            align-items: center;
        }

        .colour-picker input[type="color"] {
            width: 72px;
            min-height: 48px;
            padding: 4px;
            cursor: pointer;
        }

        .colour-sample {
            min-height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            background: linear-gradient(135deg, var(--brand-primary-light), var(--brand-primary));
            color: var(--brand-on-primary);
            font-weight: 700;
        }

        @media (max-width: 640px) {
            .container { padding: 16px; }
            .card { padding: 20px; }
            .top-actions { width: 100%; }
            .top-actions .btn { flex: 1; }
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
            <h2>Company Branding</h2>
            <p class="card-description">Set the name, logo and colour shown to permit users and managers.</p>
            
            <form method="POST" enctype="multipart/form-data">
                <?= Csrf::getFormField('admin-settings') ?>
                <input type="hidden" name="action" value="save_branding">

                <div class="field">
                    <label>
                        Company Name
                        <div class="label-description">Shown in site headers, permits and QR printouts</div>
                    </label>
                    <input type="text" name="company_name_branding" value="<?= htmlspecialchars($dbCompanyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Your company name" maxlength="120" autocomplete="organization" required>
                </div>

                <div class="field">
                    <label for="brand_primary_colour">
                        Brand Colour
                        <div class="label-description">Used for main buttons, focus indicators and highlights</div>
                    </label>
                    <div class="colour-picker">
                        <input type="color" id="brand_primary_colour" name="brand_primary_colour" value="<?= htmlspecialchars($branding['primary_colour'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="colour-sample" id="brandColourSample">Button preview</div>
                    </div>
                </div>

                <?php if ($companyLogoUrl): ?>
                    <div class="field">
                        <label>Current Logo</label>
                        <div class="logo-preview">
                            <img src="<?= htmlspecialchars($companyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($dbCompanyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> logo">
                            <span style="color:#94a3b8;font-size:13px;">Displayed on dashboards, public pages and printed outputs.</span>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label>
                        Upload Logo
                        <div class="label-description">PNG, JPG or WEBP; max 2 MB and 2400×2400 pixels. A square transparent PNG works best.</div>
                    </label>
                    <input type="file" name="company_logo" accept="image/png,image/jpeg,image/webp">
                </div>

                <button type="submit" class="btn btn-accent">Save Branding</button>
            </form>

            <?php if ($companyLogoUrl): ?>
                <form method="post" class="remove-logo-btn">
                    <?= Csrf::getFormField('admin-settings') ?>
                    <input type="hidden" name="action" value="remove_logo">
                    <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Remove the current company logo?')">Remove Logo</button>
                </form>
            <?php endif; ?>
        </div>
        
        <!-- System Settings Form -->
        <form method="POST">
            <?= Csrf::getFormField('admin-settings') ?>
            <input type="hidden" name="action" value="save_settings">
            
            <!-- Regional Settings -->
            <div class="card">
                <h2>Regional Settings</h2>
                <p class="card-description">Choose the timezone used for permit and activity timestamps.</p>
                
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
            </div>
            
            <!-- Permit Settings -->
            <div class="card">
                <h2>Permit Settings</h2>
                <p class="card-description">Configure how permits are generated</p>
                
                <div class="form-group">
                    <label>
                        Permit Reference Prefix
                        <div class="label-description">Prefix for permit reference numbers (e.g. PTW-2025-0001)</div>
                    </label>
                    <input type="text" name="permit_prefix" value="<?php echo htmlspecialchars($permitPrefix); ?>" placeholder="PTW" maxlength="10">
                </div>
                
                <div class="info-box">
                    <h4>Reference Number Format</h4>
                    <p>Permits will be numbered as: <strong><?php echo htmlspecialchars($permitPrefix); ?>-<?php echo date('Y'); ?>-####</strong><br>
                    Example: <?php echo htmlspecialchars($permitPrefix); ?>-<?php echo date('Y'); ?>-0001</p>
                </div>
            </div>
            
            <button type="submit" class="btn btn-accent">Save System Settings</button>
        </form>
        
        <div class="info-box" style="margin-top: 24px;">
            <h4>About these settings</h4>
            <p>Branding changes take effect across public and manager pages after saving.<br>
            Regional and reference settings are stored safely in the application database.<br>
            Test one permit after changing regional or reference settings.</p>
        </div>
    </div>
    <script>
        (function () {
            var input = document.getElementById('brand_primary_colour');
            var sample = document.getElementById('brandColourSample');
            if (!input || !sample) return;
            input.addEventListener('input', function () {
                sample.style.background = input.value;
                var hex = input.value.replace('#', '');
                var red = parseInt(hex.slice(0, 2), 16);
                var green = parseInt(hex.slice(2, 4), 16);
                var blue = parseInt(hex.slice(4, 6), 16);
                var luminance = (red * 0.299) + (green * 0.587) + (blue * 0.114);
                sample.style.color = luminance > 155 ? '#0f172a' : '#ffffff';
            });
        }());
    </script>
</body>
</html>
