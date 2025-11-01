<?php
/**
 * QR Code Manager - All Permits
 * 
 * File Path: /admin/qr-codes-all.php
 * Description: Display and generate QR codes for all active permits with print-to-PDF capability
 * Created: 01/11/2025
 * Last Modified: 01/11/2025
 * 
 * Features:
 * - Display all permit QR codes
 * - Company logo integration
 * - Print-friendly layout
 * - Download as PDF
 * - Generate for future permits
 * - Responsive grid layout
 */

require __DIR__ . '/../vendor/autoload.php';
[$app, $db, $root] = require_once __DIR__ . '/../src/bootstrap.php';

use Permits\SystemSettings;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

// Start session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$stmt = $db->pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || $currentUser['role'] !== 'admin') {
    die('<h1>Access Denied</h1><p>Admin access required.</p>');
}

// Get company info
$companyName = SystemSettings::companyName($db) ?? 'Permits System';
$companyLogoPath = SystemSettings::companyLogoPath($db);
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;

// Get all active permits
try {
    $stmt = $db->pdo->prepare("
        SELECT f.*, ft.name as template_name
        FROM forms f
        JOIN form_templates ft ON f.template_id = ft.id
        WHERE f.status IN ('active', 'pending_approval')
        ORDER BY f.created_at DESC
    ");
    $stmt->execute();
    $permits = $stmt->fetchAll();
} catch (Exception $e) {
    $permits = [];
}

// QR code settings
$qrOptions = new QROptions([
    'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
    'eccLevel'     => QRCode::ECC_H,
    'scale'        => 5,
    'quietzoneSize'=> 2,
    'imageBase64'  => true,
]);
$qrCode = new QRCode($qrOptions);

// Get base URL
$baseUrl = rtrim($_ENV['APP_URL'] ?? ($app->config('APP_URL') ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))), '/');

// Generate QR data URIs for all permits
$qrCodes = [];
foreach ($permits as $permit) {
    if (!empty($permit['unique_link'])) {
        $qrUrl = $baseUrl . '/view-permit-public.php?link=' . urlencode((string)$permit['unique_link']);
        try {
            $qrData = $qrCode->render($qrUrl);
            $qrCodes[$permit['id']] = $qrData;
        } catch (Exception $e) {
            $qrCodes[$permit['id']] = null;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Codes - All Permits</title>
    <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
    <style>
        body {
            background: #0f172a;
        }

        .qr-header {
            background: linear-gradient(135deg, #0f172a 0%, #111a2e 100%);
            border-bottom: 2px solid #1e293b;
            padding: 24px;
            margin-bottom: 32px;
            border-radius: 16px;
        }

        .qr-title-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .qr-title-section h1 {
            color: #f1f5f9;
            font-size: 28px;
            margin: 0;
        }

        .qr-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-print {
            background: linear-gradient(135deg, #06b6d4 0%, #0ea5e9 100%);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(6, 182, 212, 0.3);
        }

        .qr-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .qr-card {
            background: linear-gradient(135deg, #0a101a 0%, #0f172a 100%);
            border: 2px solid #1e293b;
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .qr-card:hover {
            border-color: #0ea5e9;
            box-shadow: 0 8px 24px rgba(6, 182, 212, 0.15);
            transform: translateY(-4px);
        }

        .qr-card-header {
            margin-bottom: 16px;
            text-align: left;
        }

        .qr-ref {
            color: #06b6d4;
            font-weight: 700;
            font-size: 16px;
        }

        .qr-type {
            color: #94a3b8;
            font-size: 13px;
            margin-top: 4px;
        }

        .qr-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 8px;
        }

        .qr-status.active {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }

        .qr-status.pending {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
        }

        .qr-image {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 200px;
            height: 200px;
            background: white;
            border-radius: 12px;
            margin: 16px auto;
            padding: 12px;
        }

        .qr-image img {
            max-width: 100%;
            max-height: 100%;
        }

        .qr-footer {
            color: #94a3b8;
            font-size: 12px;
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px solid #1e293b;
        }

        .qr-empty {
            grid-column: 1 / -1;
            padding: 48px 24px;
            text-align: center;
            color: #94a3b8;
        }

        @media print {
            body {
                background: white;
            }

            .qr-header,
            .qr-actions,
            .site-header,
            .site-container {
                display: none;
            }

            .qr-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 32px;
                margin: 0;
            }

            .qr-card {
                background: white;
                border: 1px solid #e5e7eb;
                page-break-inside: avoid;
            }

            .qr-image {
                background: white;
                border: 1px solid #e5e7eb;
                width: 200px;
                height: 200px;
            }

            .qr-card-header,
            .qr-footer {
                display: none;
            }
        }

        .company-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 2px solid #1e293b;
        }

        .company-logo {
            width: 52px;
            height: 52px;
            background: rgba(6, 182, 212, 0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .company-logo img {
            max-width: 100%;
            max-height: 100%;
        }

        .company-info h2 {
            color: #f1f5f9;
            margin: 0;
            font-size: 20px;
        }

        .company-info p {
            color: #94a3b8;
            margin: 4px 0 0 0;
            font-size: 13px;
        }

        .info-box {
            background: rgba(6, 182, 212, 0.05);
            border: 1px solid rgba(6, 182, 212, 0.3);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            color: #cbd5e1;
            font-size: 14px;
            line-height: 1.6;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .stat-badge {
            background: linear-gradient(135deg, rgba(6, 182, 212, 0.1) 0%, rgba(14, 165, 233, 0.05) 100%);
            border: 1px solid rgba(6, 182, 212, 0.3);
            border-radius: 8px;
            padding: 12px;
            text-align: center;
        }

        .stat-label {
            color: #94a3b8;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            color: #06b6d4;
            font-size: 24px;
            font-weight: 700;
            margin-top: 4px;
        }
    </style>
</head>
<body class="theme-dark">
    <header class="site-header">
        <div class="brand-mark">
            <div>
                <div class="brand-mark__name">QR Code Manager</div>
                <div class="brand-mark__sub">📋 All Permits</div>
            </div>
        </div>
        <div class="site-header__actions">
            <a href="<?php echo htmlspecialchars($app->url('admin.php')); ?>" class="btn btn-secondary">⬅️ Admin Panel</a>
            <a href="<?php echo htmlspecialchars($app->url('logout.php')); ?>" class="btn btn-secondary">🚪 Logout</a>
        </div>
    </header>

    <main class="site-container">
        <div class="qr-header">
            <div class="company-header">
                <?php if ($companyLogoUrl): ?>
                    <div class="company-logo">
                        <img src="<?= $companyLogoUrl ?>" alt="<?= htmlspecialchars($companyName) ?>">
                    </div>
                <?php endif; ?>
                <div class="company-info">
                    <h2><?= htmlspecialchars($companyName) ?></h2>
                    <p>📋 QR Code Display for All Active Permits</p>
                </div>
            </div>

            <div class="qr-title-section">
                <div>
                    <h1>🔲 Permit QR Codes</h1>
                    <p style="color: #94a3b8; margin-top: 4px;">All <?= count($permits); ?> active and pending permits</p>
                </div>
                <div class="qr-actions">
                    <button onclick="window.print()" class="btn-print">🖨️ Print / Save as PDF</button>
                </div>
            </div>

            <div class="info-box">
                💡 Each permit has a unique QR code that links to its public view page. Print this page and post it on your notice board. Future permits will appear here automatically.
            </div>

            <div class="stats-row">
                <div class="stat-badge">
                    <div class="stat-label">Total Active</div>
                    <div class="stat-value"><?= count(array_filter($permits, fn($p) => $p['status'] === 'active')); ?></div>
                </div>
                <div class="stat-badge">
                    <div class="stat-label">Pending Approval</div>
                    <div class="stat-value"><?= count(array_filter($permits, fn($p) => $p['status'] === 'pending_approval')); ?></div>
                </div>
                <div class="stat-badge">
                    <div class="stat-label">Total Permits</div>
                    <div class="stat-value"><?= count($permits); ?></div>
                </div>
                <div class="stat-badge">
                    <div class="stat-label">QR Codes Ready</div>
                    <div class="stat-value"><?= count(array_filter($qrCodes)); ?></div>
                </div>
            </div>
        </div>

        <?php if (empty($permits)): ?>
            <div class="qr-grid">
                <div class="qr-empty">
                    <div style="font-size: 48px; margin-bottom: 12px;">📭</div>
                    <p>No active or pending permits found.</p>
                    <p style="font-size: 12px; margin-top: 8px;">Create some permits first, then return here to generate QR codes.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="qr-grid">
                <?php foreach ($permits as $permit): ?>
                    <div class="qr-card">
                        <div class="qr-card-header">
                            <div class="qr-ref"><?= htmlspecialchars($permit['ref_number'] ?? 'N/A'); ?></div>
                            <div class="qr-type"><?= htmlspecialchars($permit['template_name']); ?></div>
                            <span class="qr-status <?= $permit['status'] === 'active' ? 'active' : 'pending'; ?>">
                                <?= ucfirst(str_replace('_', ' ', $permit['status'])); ?>
                            </span>
                        </div>

                        <?php if (!empty($qrCodes[$permit['id']])): ?>
                            <div class="qr-image">
                                <img src="<?= $qrCodes[$permit['id']]; ?>" alt="QR Code for <?= htmlspecialchars($permit['ref_number']); ?>">
                            </div>
                        <?php else: ?>
                            <div class="qr-image" style="background: rgba(239, 68, 68, 0.1); border: 2px dashed #ef4444;">
                                <span style="color: #ef4444;">❌ Error</span>
                            </div>
                        <?php endif; ?>

                        <div class="qr-footer">
                            Scan to view permit details
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <script>
        // Auto-refresh every 60 seconds to show new permits
        setTimeout(() => {
            location.reload();
        }, 60000);
    </script>
</body>
</html>
