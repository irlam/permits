<?php
/**
 * QR Code Manager - Permits for New Creation
 * 
 * File Path: /admin/qr-codes-active-permits.php
 * Description: Auto-fetch permits available for creating new permits and generate persistent QR codes
 * Created: 01/11/2025
 * Last Modified: 01/11/2025
 * 
 * Features:
 * - Auto-fetch all permits available for new permit creation (draft, pending_approval, active)
 * - One unique persistent QR code per permit (does not change)
 * - Real-time status updates
 * - Auto-refresh every 30 seconds for new permits
 * - Print/Save as PDF
 * - Company logo integration
 * - Statistics and filtering
 * - Responsive design
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

$baseUrl = rtrim($_ENV['APP_URL'] ?? ($app->config('APP_URL') ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))), '/');

// Get all permits available for creating new permits
// These are permits in draft, pending_approval, or active status that can be used as templates
try {
    $stmt = $db->pdo->prepare("
        SELECT 
            f.id,
            f.ref,
            f.unique_link,
            f.status,
            f.created_at,
            f.valid_from,
            f.valid_to,
            f.work_started_at,
            f.holder_id,
            ft.name as template_name,
            u.name as holder_name,
            u.email as holder_email
        FROM forms f
        JOIN form_templates ft ON f.template_id = ft.id
        LEFT JOIN users u ON f.holder_id = u.id
        WHERE f.status IN ('draft', 'pending_approval', 'active')
        ORDER BY f.created_at DESC
    ");
    $stmt->execute();
    $permits = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $permits = [];
}

// Generate QR codes for all permits
$permitQrCodes = [];
$qrOptions = new QROptions([
    'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
    'eccLevel'     => QRCode::ECC_H,
    'scale'        => 8,
    'quietzoneSize'=> 4,
    'imageBase64'  => true,
]);

foreach ($permits as $permit) {
    if (!empty($permit['unique_link'])) {
        $qrCode = new QRCode($qrOptions);
        $qrUrl = $baseUrl . '/view-permit-public.php?link=' . urlencode((string)$permit['unique_link']);
        try {
            $permitQrCodes[$permit['id']] = $qrCode->render($qrUrl);
        } catch (Exception $e) {
            $permitQrCodes[$permit['id']] = null;
        }
    }
}

// Calculate statistics by status
$stats = [
    'total' => count($permits),
    'draft' => count(array_filter($permits, fn($p) => $p['status'] === 'draft')),
    'pending_approval' => count(array_filter($permits, fn($p) => $p['status'] === 'pending_approval')),
    'active' => count(array_filter($permits, fn($p) => $p['status'] === 'active')),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active Permits QR Codes - <?php echo htmlspecialchars($companyName); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #111a2e 100%);
            color: #e2e8f0;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(6, 182, 212, 0.1);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header-logo {
            width: 50px;
            height: 50px;
            object-fit: contain;
            opacity: 0.9;
        }

        .header-title h1 {
            font-size: 28px;
            font-weight: 700;
            color: #06b6d4;
            margin-bottom: 4px;
        }

        .header-title p {
            font-size: 14px;
            color: #94a3b8;
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-print {
            background: linear-gradient(135deg, #06b6d4 0%, #0ea5e9 100%);
            color: #0f172a;
        }

        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(6, 182, 212, 0.3);
        }

        .btn-back {
            background: rgba(148, 163, 184, 0.1);
            color: #94a3b8;
            border: 1px solid rgba(148, 163, 184, 0.2);
        }

        .btn-back:hover {
            background: rgba(148, 163, 184, 0.2);
            border-color: rgba(148, 163, 184, 0.3);
        }

        /* Statistics Bar */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(6, 182, 212, 0.1);
            border-radius: 12px;
            padding: 16px;
        }

        .stat-label {
            font-size: 13px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #06b6d4;
        }

        /* Main Content */
        .content {
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(6, 182, 212, 0.1);
            border-radius: 12px;
            padding: 24px;
        }

        /* QR Grid */
        .qr-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 24px;
        }

        .qr-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.8) 0%, rgba(15, 23, 42, 0.9) 100%);
            border: 1px solid rgba(6, 182, 212, 0.1);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .qr-card:hover {
            border-color: rgba(6, 182, 212, 0.3);
            box-shadow: 0 8px 24px rgba(6, 182, 212, 0.15);
            transform: translateY(-4px);
        }

        .qr-code-area {
            background: white;
            padding: 16px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 280px;
        }

        .qr-code-area img {
            max-width: 100%;
            height: auto;
        }

        .qr-info {
            padding: 16px;
        }

        .qr-ref {
            font-size: 16px;
            font-weight: 700;
            color: #06b6d4;
            margin-bottom: 8px;
            word-break: break-word;
        }

        .qr-template {
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 12px;
        }

        .qr-status-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            font-size: 12px;
        }

        .qr-status-label {
            color: #64748b;
        }

        .qr-status-badge {
            background: rgba(6, 182, 212, 0.1);
            color: #06b6d4;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
        }

        .qr-status-badge.working {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }

        .qr-status-badge.pending {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .qr-holder {
            font-size: 12px;
            color: #94a3b8;
            margin-bottom: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(6, 182, 212, 0.1);
        }

        .qr-link {
            font-size: 11px;
            color: #64748b;
            word-break: break-all;
            font-family: 'Courier New', monospace;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .empty-state h2 {
            font-size: 24px;
            color: #e2e8f0;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: #94a3b8;
            margin-bottom: 24px;
        }

        /* Print Styles */
        @media print {
            body {
                background: white;
                padding: 0;
            }

            .header,
            .header-actions,
            .stats-bar {
                display: none;
            }

            .content {
                background: white;
                border: none;
                padding: 0;
            }

            .qr-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .qr-card {
                border: 1px solid #ddd;
                background: white;
                break-inside: avoid;
                page-break-inside: avoid;
            }

            .qr-code-area {
                background: white;
                min-height: 250px;
            }

            .qr-info {
                font-size: 11px;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-title h1 {
                font-size: 22px;
            }

            .qr-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            }

            .stats-bar {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .qr-grid {
                grid-template-columns: 1fr;
            }

            .stats-bar {
                grid-template-columns: 1fr;
            }

            .header-left {
                width: 100%;
            }
        }

        /* Auto-refresh indicator */
        .refresh-indicator {
            font-size: 12px;
            color: #64748b;
            margin-top: 16px;
            text-align: center;
        }

        .refresh-dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #06b6d4;
            animation: pulse 2s infinite;
            margin-right: 6px;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <?php if ($companyLogoUrl): ?>
                    <img src="<?php echo htmlspecialchars($companyLogoUrl); ?>" alt="<?php echo htmlspecialchars($companyName); ?>" class="header-logo">
                <?php endif; ?>
                <div class="header-title">
                    <h1>� Permit Templates - QR Codes</h1>
                    <p><?php echo htmlspecialchars($companyName); ?> - Static QR Codes for New Permit Creation</p>
                </div>
            </div>
            <div class="header-actions">
                <button class="btn btn-print" onclick="window.print();">🖨️ Print / Save as PDF</button>
                <a href="/admin.php" class="btn btn-back">← Back to Admin</a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-label">Total Templates</div>
                <div class="stat-value"><?php echo $stats['total']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Draft</div>
                <div class="stat-value" style="color: #64748b;"><?php echo $stats['draft']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending Approval</div>
                <div class="stat-value" style="color: #f59e0b;"><?php echo $stats['pending_approval']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active</div>
                <div class="stat-value" style="color: #22c55e;"><?php echo $stats['active']; ?></div>
            </div>
        </div>

        <!-- Content -->
        <div class="content">
            <?php if (!empty($permits)): ?>
                <h2 style="margin-bottom: 8px;">Permit Templates - Static QR Codes for New Creation</h2>
                <p style="color: #94a3b8; font-size: 14px; margin-bottom: 16px;">
                    Each permit below has a unique, static QR code. Use these templates to create new permits. QR codes remain the same for each template.
                </p>

                <!-- QR Grid -->
                <div class="qr-grid">
                    <?php foreach ($permits as $permit): ?>
                        <div class="qr-card">
                            <div class="qr-code-area">
                                <?php if (isset($permitQrCodes[$permit['id']])): ?>
                                    <img src="<?php echo htmlspecialchars($permitQrCodes[$permit['id']]); ?>" alt="QR Code for <?php echo htmlspecialchars($permit['ref']); ?>">
                                <?php else: ?>
                                    <span style="color: #64748b;">QR Code unavailable</span>
                                <?php endif; ?>
                            </div>
                            <div class="qr-info">
                                <div class="qr-ref"><?php echo htmlspecialchars($permit['ref']); ?></div>
                                <div class="qr-template"><?php echo htmlspecialchars($permit['template_name']); ?></div>

                                <div class="qr-status-row">
                                    <span class="qr-status-label">Status:</span>
                                    <span class="qr-status-badge <?php 
                                        if ($permit['status'] === 'active') echo 'working';
                                        elseif ($permit['status'] === 'pending_approval') echo 'pending';
                                    ?>">
                                        <?php 
                                            if ($permit['status'] === 'active') echo '✓ Active';
                                            elseif ($permit['status'] === 'pending_approval') echo '⏳ Pending';
                                            else echo '📝 Draft';
                                        ?>
                                    </span>
                                </div>

                                <?php if ($permit['holder_name']): ?>
                                    <div class="qr-holder">
                                        <strong>Template for:</strong> <?php echo htmlspecialchars($permit['holder_name']); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="qr-link">
                                    Template ID: <?php echo htmlspecialchars($permit['id']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="refresh-indicator">
                    <span class="refresh-dot"></span>
                    Auto-refreshing every 30 seconds for new templates
                </div>

            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <h2>No Permit Templates</h2>
                    <p>There are currently no permits available as templates for new permit creation.</p>
                    <p style="font-size: 13px; color: #64748b;">Create a permit template first to make it available for new permit creation.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-refresh page every 30 seconds
        setInterval(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
