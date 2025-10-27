<?php
declare(strict_types=1);

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * QR Codes Display Page - Simple Version
 * 
 * File Path: /qr-codes.php
 * Description: Display all permit template QR codes
 * Created: 24/10/2025
 * Last Modified: 24/10/2025
 * 
 * Features:
 * - Display all template QR codes
 * - Print-friendly layout
 * - Easy scanning
 */

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

// Start session
session_start();

// Check if user is logged in (optional for QR codes page)
$isLoggedIn = isset($_SESSION['user_id']);
$currentUser = null;

if ($isLoggedIn) {
    $stmt = $db->pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all templates
try {
    $templates = $db->pdo->query("
        SELECT * FROM form_templates 
        WHERE active = 1 
        ORDER BY name ASC
    ")->fetchAll();
} catch (PDOException $e) {
    // Fallback if active column doesn't exist
    try {
        $templates = $db->pdo->query("
            SELECT * FROM form_templates 
            ORDER BY name ASC
        ")->fetchAll();
    } catch (Exception $e2) {
        $templates = [];
    }
}

// QR generator reused for each template
$qrOptions = new QROptions([
    'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
    'eccLevel'     => QRCode::ECC_Q,
    'scale'        => 8,
    'quietzoneSize'=> 4,
    'imageBase64'  => false,
]);
$qrCode = new QRCode($qrOptions);

// Get base URL
$baseUrl = rtrim($_ENV['APP_URL'] ?? ($app->config('APP_URL') ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Codes - Permit Templates</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #f3f4f6;
            padding: 20px;
        }
        
        .header {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .header h1 {
            font-size: 28px;
            color: #111827;
            margin-bottom: 8px;
        }
        
        .header p {
            color: #6b7280;
        }
        
        .actions {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-block;
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2563eb;
        }
        
        .btn-secondary {
            background: white;
            color: #111827;
            border: 2px solid #e5e7eb;
        }
        
        .btn-secondary:hover {
            background: #f9fafb;
        }
        
        .qr-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 24px;
        }
        
        .qr-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .qr-card h3 {
            font-size: 18px;
            color: #111827;
            margin-bottom: 16px;
        }
        
        .qr-code-container {
            background: #f9fafb;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 16px;
        }
        
        .qr-code-container img {
            width: 100%;
            max-width: 200px;
            height: auto;
        }
        
        .qr-url {
            font-size: 12px;
            color: #6b7280;
            word-break: break-all;
            margin-bottom: 16px;
            padding: 8px;
            background: #f9fafb;
            border-radius: 4px;
        }
        
        .qr-actions {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        
        .empty-state {
            background: white;
            border-radius: 12px;
            padding: 48px 24px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }
        
        .empty-state h2 {
            font-size: 24px;
            color: #111827;
            margin-bottom: 8px;
        }
        
        .empty-state p {
            color: #6b7280;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .header, .actions, .no-print {
                display: none !important;
            }
            
            .qr-grid {
                display: block;
            }
            
            .qr-card {
                page-break-inside: avoid;
                margin-bottom: 20px;
                border: 2px solid #000;
            }
        }
    </style>
</head>
<body>
    <div class="header no-print">
        <h1>📱 QR Code Templates</h1>
        <p>Scan these QR codes to quickly create permits</p>
        
        <div class="actions">
            <button onclick="window.print()" class="btn btn-primary">🖨️ Print All</button>
            <?php if ($isLoggedIn): ?>
                <a href="<?php echo htmlspecialchars($app->url('dashboard.php')); ?>" class="btn btn-secondary">📊 Dashboard</a>
            <?php else: ?>
                <a href="<?php echo htmlspecialchars($app->url('/')); ?>" class="btn btn-secondary">🏠 Home</a>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (empty($templates)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">📭</div>
            <h2>No Templates Found</h2>
            <p>No permit templates are available yet.</p>
        </div>
    <?php else: ?>
        <div class="qr-grid">
            <?php foreach ($templates as $template): ?>
                <?php
                $createUrl = $baseUrl . '/create-permit-public.php?template=' . urlencode($template['id']);
                $qrError = null;
                try {
                    $qrBinary = $qrCode->render($createUrl);
                    $qrDataUri = 'data:image/png;base64,' . base64_encode($qrBinary);
                } catch (Throwable $e) {
                    $qrDataUri = null;
                    $qrError = $e->getMessage();
                }
                ?>
                <div class="qr-card">
                    <h3><?php echo htmlspecialchars($template['name']); ?></h3>
                    
                    <div class="qr-code-container">
                        <?php if ($qrDataUri): ?>
                            <img src="<?php echo $qrDataUri; ?>"
                                 alt="QR Code for <?php echo htmlspecialchars($template['name']); ?>">
                        <?php else: ?>
                            <div style="color:#ef4444;font-size:14px;">QR unavailable<?php echo $qrError ? ': ' . htmlspecialchars($qrError) : ''; ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="qr-url">
                        <?php echo htmlspecialchars($createUrl); ?>
                    </div>
                    
                    <div class="qr-actions no-print">
                        <a href="<?php echo htmlspecialchars($createUrl); ?>" 
                           class="btn btn-primary" 
                           target="_blank">
                            📝 Create Permit
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</body>
</html>