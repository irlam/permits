<?php
/**
 * QR Code Manager - Individual Permits
 * 
 * File Path: /admin/qr-codes-individual.php
 * Description: Browse and generate QR codes for individual permits with detailed information
 * Created: 01/11/2025
 * Last Modified: 01/11/2025
 * 
 * Features:
 * - Browse all permits with search/filter
 * - Generate QR code for selected permit
 * - Print individual QR codes
 * - Download as PDF
 * - Company logo integration
 * - Detailed permit information display
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

// Get selected permit ID from query
$selectedPermitId = $_GET['permit_id'] ?? null;

// Get all permits with details
try {
    $stmt = $db->pdo->prepare("
        SELECT f.*, ft.name as template_name, u.name as holder_name, u.email as holder_email
        FROM forms f
        JOIN form_templates ft ON f.template_id = ft.id
        LEFT JOIN users u ON f.holder_id = u.id
        ORDER BY f.created_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $permits = $stmt->fetchAll();
} catch (Exception $e) {
    $permits = [];
}

// Generate QR for selected permit
$selectedPermit = null;
$qrData = null;
$baseUrl = rtrim($_ENV['APP_URL'] ?? ($app->config('APP_URL') ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))), '/');

if ($selectedPermitId && !empty($permits)) {
    $selectedPermit = null;
    foreach ($permits as $permit) {
        if ($permit['id'] === $selectedPermitId) {
            $selectedPermit = $permit;
            break;
        }
    }

    if ($selectedPermit && !empty($selectedPermit['unique_link'])) {
        $qrOptions = new QROptions([
            'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel'     => QRCode::ECC_H,
            'scale'        => 8,
            'quietzoneSize'=> 4,
            'imageBase64'  => true,
        ]);
        $qrCode = new QRCode($qrOptions);
        
        $qrUrl = $baseUrl . '/view-permit-public.php?link=' . urlencode((string)$selectedPermit['unique_link']);
        try {
            $qrData = $qrCode->render($qrUrl);
        } catch (Exception $e) {
            $qrData = null;
        }
    }
}

// Get search/filter query
$searchQuery = $_GET['q'] ?? '';
$filteredPermits = $permits;
if (!empty($searchQuery)) {
    $searchLower = strtolower($searchQuery);
    $filteredPermits = array_filter($permits, function($p) use ($searchLower) {
        return strpos(strtolower($p['ref_number'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($p['template_name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($p['holder_name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($p['holder_email'] ?? ''), $searchLower) !== false;
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code Manager - Individual Permits</title>
    <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
    <style>
        body {
            background: #0f172a;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }

        .layout {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 24px;
            margin-bottom: 32px;
        }

        .sidebar {
            background: linear-gradient(135deg, #0a101a 0%, #0f172a 100%);
            border: 2px solid #1e293b;
            border-radius: 16px;
            padding: 24px;
            height: fit-content;
            position: sticky;
            top: 100px;
        }

        .sidebar-title {
            color: #f1f5f9;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-box {
            margin-bottom: 16px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 12px;
            background: linear-gradient(135deg, #0f172a 0%, #1a202c 100%);
            border: 2px solid #334155;
            border-radius: 8px;
            color: #e2e8f0;
            font-size: 13px;
            transition: all 0.3s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: #06b6d4;
            box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.1);
        }

        .permits-list {
            max-height: 600px;
            overflow-y: auto;
            border-top: 1px solid #1e293b;
            padding-top: 12px;
        }

        .permit-item {
            padding: 12px;
            margin-bottom: 8px;
            background: rgba(6, 182, 212, 0.05);
            border: 2px solid transparent;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .permit-item:hover {
            background: rgba(6, 182, 212, 0.1);
            border-color: #06b6d4;
            transform: translateX(4px);
        }

        .permit-item.active {
            background: rgba(6, 182, 212, 0.2);
            border-color: #06b6d4;
        }

        .permit-item-ref {
            color: #06b6d4;
            font-weight: 700;
            font-size: 13px;
        }

        .permit-item-type {
            color: #94a3b8;
            font-size: 12px;
            margin-top: 2px;
        }

        .permit-item-status {
            font-size: 10px;
            color: #64748b;
            margin-top: 4px;
        }

        .qr-viewer {
            background: linear-gradient(135deg, #0a101a 0%, #0f172a 100%);
            border: 2px solid #1e293b;
            border-radius: 16px;
            padding: 32px;
            text-align: center;
        }

        .qr-viewer-header {
            margin-bottom: 24px;
            padding-bottom: 24px;
            border-bottom: 2px solid #1e293b;
            text-align: left;
        }

        .qr-viewer-header h2 {
            color: #f1f5f9;
            font-size: 20px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .permit-detail {
            margin-top: 16px;
            color: #94a3b8;
            font-size: 13px;
            line-height: 1.6;
        }

        .detail-row {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 8px;
            padding: 8px 0;
            border-bottom: 1px solid #1e293b;
        }

        .detail-label {
            color: #64748b;
            font-weight: 600;
        }

        .detail-value {
            color: #cbd5e1;
            word-break: break-word;
        }

        .qr-display {
            margin: 32px 0;
            padding: 24px;
            background: white;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .qr-display img {
            max-width: 100%;
            max-height: 400px;
        }

        .empty-state {
            color: #94a3b8;
            padding: 48px 24px;
            text-align: center;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }

        .qr-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 24px;
        }

        .btn-action {
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
            font-size: 14px;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(6, 182, 212, 0.3);
        }

        .btn-action-secondary {
            background: linear-gradient(135deg, #475569 0%, #64748b 100%);
        }

        @media print {
            .sidebar,
            .site-header,
            .qr-actions,
            .search-box,
            .permits-list {
                display: none;
            }

            .qr-viewer {
                border: none;
                background: white;
                padding: 0;
            }

            .qr-viewer-header {
                border: none;
            }

            .qr-viewer-header h2,
            .qr-viewer-header p,
            .permit-detail {
                color: #000;
            }

            .detail-row {
                border-color: #e5e7eb;
            }

            .detail-label,
            .detail-value {
                color: #000;
            }

            .qr-display {
                background: white;
                box-shadow: none;
                border: 1px solid #e5e7eb;
            }
        }

        .company-info-banner {
            background: linear-gradient(135deg, rgba(6, 182, 212, 0.1) 0%, rgba(14, 165, 233, 0.05) 100%);
            border: 1px solid rgba(6, 182, 212, 0.3);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .company-logo-small {
            width: 48px;
            height: 48px;
            background: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
        }

        .company-logo-small img {
            max-width: 100%;
            max-height: 100%;
        }

        .company-name-display {
            color: #e2e8f0;
            font-weight: 600;
            font-size: 14px;
        }
    </style>
</head>
<body class="theme-dark">
    <header class="site-header">
        <div class="brand-mark">
            <div>
                <div class="brand-mark__name">QR Code Manager</div>
                <div class="brand-mark__sub">📋 Individual Permits</div>
            </div>
        </div>
        <div class="site-header__actions">
            <a href="<?php echo htmlspecialchars($app->url('admin.php')); ?>" class="btn btn-secondary">⬅️ Admin Panel</a>
            <a href="<?php echo htmlspecialchars($app->url('logout.php')); ?>" class="btn btn-secondary">🚪 Logout</a>
        </div>
    </header>

    <main class="container">
        <div class="layout">
            <!-- Sidebar with Permit List -->
            <div class="sidebar">
                <div class="sidebar-title">🔲 Select Permit</div>

                <div class="search-box">
                    <input 
                        type="text" 
                        id="searchInput"
                        placeholder="Search by ref, type, holder..."
                        value="<?= htmlspecialchars($searchQuery); ?>"
                    >
                </div>

                <div class="permits-list" id="permitsList">
                    <?php if (empty($filteredPermits)): ?>
                        <div style="color: #94a3b8; padding: 20px 12px; text-align: center; font-size: 13px;">
                            No permits found
                        </div>
                    <?php else: ?>
                        <?php foreach ($filteredPermits as $permit): ?>
                            <a 
                                href="?permit_id=<?= htmlspecialchars($permit['id']); ?><?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : ''; ?>"
                                class="permit-item <?= ($selectedPermitId === $permit['id']) ? 'active' : ''; ?>"
                            >
                                <div class="permit-item-ref"><?= htmlspecialchars($permit['ref_number'] ?? 'N/A'); ?></div>
                                <div class="permit-item-type"><?= htmlspecialchars($permit['template_name']); ?></div>
                                <div class="permit-item-status">
                                    <?= ucfirst(str_replace('_', ' ', $permit['status'])); ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Main QR Viewer -->
            <div class="qr-viewer">
                <?php if ($selectedPermit && $qrData): ?>
                    <div class="company-info-banner">
                        <?php if ($companyLogoUrl): ?>
                            <div class="company-logo-small">
                                <img src="<?= $companyLogoUrl ?>" alt="<?= htmlspecialchars($companyName) ?>">
                            </div>
                        <?php endif; ?>
                        <div class="company-name-display"><?= htmlspecialchars($companyName); ?></div>
                    </div>

                    <div class="qr-viewer-header">
                        <h2>📋 <?= htmlspecialchars($selectedPermit['ref_number'] ?? 'Permit'); ?></h2>
                        <p style="color: #94a3b8; margin: 8px 0 0 0;"><?= htmlspecialchars($selectedPermit['template_name']); ?></p>
                    </div>

                    <div class="permit-detail">
                        <div class="detail-row">
                            <div class="detail-label">Status:</div>
                            <div class="detail-value">
                                <span style="background: <?= $selectedPermit['status'] === 'active' ? '#10b98120' : '#f59e0b20'; ?>; color: <?= $selectedPermit['status'] === 'active' ? '#10b981' : '#f59e0b'; ?>; padding: 4px 8px; border-radius: 4px;">
                                    <?= ucfirst(str_replace('_', ' ', $selectedPermit['status'])); ?>
                                </span>
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Holder:</div>
                            <div class="detail-value"><?= htmlspecialchars($selectedPermit['holder_name'] ?? $selectedPermit['holder_email'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Created:</div>
                            <div class="detail-value"><?= date('d/m/Y H:i', strtotime($selectedPermit['created_at'])); ?></div>
                        </div>
                        <?php if (!empty($selectedPermit['valid_from']) && !empty($selectedPermit['valid_to'])): ?>
                            <div class="detail-row">
                                <div class="detail-label">Valid:</div>
                                <div class="detail-value">
                                    <?= date('d/m/Y', strtotime($selectedPermit['valid_from'])); ?> to 
                                    <?= date('d/m/Y', strtotime($selectedPermit['valid_to'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="qr-display">
                        <img src="<?= $qrData; ?>" alt="QR Code">
                    </div>

                    <div style="background: rgba(6, 182, 212, 0.05); border: 1px solid rgba(6, 182, 212, 0.3); border-radius: 8px; padding: 12px; margin: 16px 0; color: #cbd5e1; font-size: 12px; line-height: 1.6;">
                        💡 This QR code links to the public permit view page. Scan it to see permit details. You can print this page to create a physical notice board post.
                    </div>

                    <div class="qr-actions">
                        <button onclick="window.print()" class="btn-action">🖨️ Print / Save as PDF</button>
                        <button onclick="downloadQR()" class="btn-action btn-action-secondary">⬇️ Download QR Image</button>
                    </div>

                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <p>Select a permit from the list to generate its QR code</p>
                        <p style="font-size: 12px; color: #64748b; margin-top: 12px;">
                            <?php if (empty($permits)): ?>
                                No permits available. Create some permits first.
                            <?php else: ?>
                                <?php if ($selectedPermit && empty($selectedPermit['unique_link'])): ?>
                                    This permit doesn't have a unique link yet.
                                <?php else: ?>
                                    Choose a permit from the sidebar
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // Real-time search
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const query = this.value;
            const params = new URLSearchParams();
            if (query) params.append('q', query);
            window.location.href = '?' + params.toString();
        });

        function downloadQR() {
            const img = document.querySelector('.qr-display img');
            if (img) {
                const link = document.createElement('a');
                link.href = img.src;
                link.download = 'permit-qr-code.png';
                link.click();
            }
        }
    </script>
</body>
</html>
