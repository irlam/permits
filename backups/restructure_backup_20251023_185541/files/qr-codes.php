<?php
/**
 * QR Codes Display Page
 * 
 * File Path: /qr-codes.php
 * Description: Display all permit template QR codes for easy scanning and printing
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * Features:
 * - Display all template QR codes
 * - Print-friendly layout
 * - Download individual codes
 * - Download all as sheet
 * - Activity logging
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/Auth.php';
require __DIR__ . '/src/ActivityLogger.php';

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
$auth = new Auth($db);
$logger = new \Permits\ActivityLogger($db);

$isLoggedIn = $auth->isLoggedIn();
if ($isLoggedIn) {
    $currentUser = $auth->getCurrentUser();
    $logger->setUser($currentUser['id'], $currentUser['email']);
    $logger->log('qr_codes_viewed', 'system', 'qr_codes', null, 'QR codes page accessed');
}

// Get all templates (check if active column exists)
try {
    // Try with active column first
    $templates = $db->pdo->query("
        SELECT * FROM form_templates 
        WHERE active = 1 
        ORDER BY name ASC
    ")->fetchAll();
} catch (PDOException $e) {
    // Fallback: active column doesn't exist yet, get all templates
    if (strpos($e->getMessage(), 'active') !== false) {
        $templates = $db->pdo->query("
            SELECT * FROM form_templates 
            ORDER BY name ASC
        ")->fetchAll();
    } else {
        throw $e;
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Codes - Permits & Registers</title>
    <link rel="stylesheet" href="/assets/app.css">
    <style>
        body{background:#0a101a}
        .container{max-width:1400px;margin:0 auto;padding:20px}
        .hero{background:linear-gradient(135deg,#1e3a8a 0%,#0ea5e9 100%);padding:40px 20px;text-align:center;margin-bottom:40px;border-radius:12px}
        .hero h1{font-size:36px;color:#fff;margin:0 0 12px 0}
        .hero p{font-size:16px;color:#e0f2fe;margin:0}
        .qr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(350px,1fr));gap:24px;margin-bottom:40px}
        .qr-card{background:#111827;border:2px solid #1f2937;border-radius:12px;padding:32px;text-align:center;transition:all 0.2s}
        .qr-card:hover{border-color:#0ea5e9;transform:translateY(-4px)}
        .qr-code{background:#fff;padding:20px;border-radius:12px;display:inline-block;margin-bottom:20px;box-shadow:0 4px 6px rgba(0,0,0,0.3)}
        .qr-code img{display:block;width:250px;height:250px}
        .qr-card h2{color:#e5e7eb;font-size:22px;margin:0 0 8px 0}
        .qr-card p{color:#94a3b8;font-size:14px;margin:0 0 20px 0;min-height:40px}
        .qr-card .actions{display:flex;gap:12px;justify-content:center}
        .qr-card .btn{padding:10px 20px;font-size:14px}
        .print-section{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:32px;margin-bottom:40px}
        .print-section h2{color:#e5e7eb;margin:0 0 16px 0;font-size:24px}
        .print-section p{color:#94a3b8;margin:0 0 20px 0;line-height:1.6}
        .print-actions{display:flex;gap:12px;flex-wrap:wrap}
        .instructions{background:#0a101a;border:1px solid#1f2937;border-radius:8px;padding:20px;margin-bottom:32px}
        .instructions h3{color:#e5e7eb;margin:0 0 12px 0;font-size:18px}
        .instructions ol{color:#94a3b8;margin:0;padding-left:20px;line-height:1.8}
        .instructions li{margin-bottom:8px}
        
        @media print{
            body{background:#fff}
            header,.print-section,.instructions,.btn{display:none!important}
            .container{max-width:100%;padding:0}
            .qr-grid{grid-template-columns:repeat(2,1fr);gap:40px;page-break-inside:avoid}
            .qr-card{border:2px solid #000;padding:20px;background:#fff;page-break-inside:avoid}
            .qr-card h2,.qr-card p{color:#000}
            .qr-card .actions{display:none}
            .hero{display:none}
        }
    </style>
</head>
<body>
    <header class="top">
        <h1>📱 QR Codes</h1>
        <div style="display:flex;gap:8px">
            <a class="btn" href="/dashboard.php">← Dashboard</a>
            <?php if($isLoggedIn && $auth->hasRole('admin')): ?>
                <a class="btn" href="/admin.php">⚙️ Admin</a>
            <?php endif; ?>
        </div>
    </header>
    
    <div class="container">
        <div class="hero">
            <h1>📱 Scan to Create Permits</h1>
            <p>Quick access to permit creation via QR codes</p>
        </div>
        
        <div class="instructions">
            <h3>📖 How to Use QR Codes</h3>
            <ol>
                <li><strong>Scan the QR code</strong> using your phone's camera or QR code app</li>
                <li><strong>You'll be taken</strong> directly to the permit creation form</li>
                <li><strong>Fill out the form</strong> with required information</li>
                <li><strong>Submit the permit</strong> for approval</li>
                <li><strong>Track your permit</strong> status in the dashboard</li>
            </ol>
        </div>
        
        <div class="print-section">
            <h2>🖨️ Print QR Codes</h2>
            <p>Print all QR codes on one page to display on-site. Workers can scan the code for the permit they need.</p>
            <div class="print-actions">
                <button onclick="window.print()" class="btn">
                    🖨️ Print All QR Codes
                </button>
                <a href="/qr-codes-sheet.php" class="btn" style="background:#6b7280">
                    📄 Download PDF Sheet
                </a>
            </div>
        </div>
        
        <?php if(empty($templates)): ?>
            <div style="background:#111827;border:1px solid #1f2937;border-radius:12px;padding:60px;text-align:center">
                <div style="font-size:64px;margin-bottom:16px;opacity:0.3">📋</div>
                <h2 style="color:#e5e7eb;margin:0 0 8px 0">No Templates Available</h2>
                <p style="color:#94a3b8">Please contact your administrator to add permit templates.</p>
            </div>
        <?php else: ?>
            <div class="qr-grid">
                <?php foreach($templates as $template): ?>
                <div class="qr-card">
                    <div class="qr-code">
                        <img src="/qr-code.php?template=<?=$template['id']?>&size=250" alt="QR Code for <?=htmlspecialchars($template['name'])?>">
                    </div>
                    <h2><?=htmlspecialchars($template['name'])?></h2>
                    <p><?=htmlspecialchars($template['description'] ?? 'Scan to create this permit')?></p>
                    <div class="actions">
                        <a href="/create-permit.php?template=<?=$template['id']?>" class="btn">
                            ➕ Create Permit
                        </a>
                        <a href="/qr-code.php?template=<?=$template['id']?>&size=1000&download=1" class="btn" style="background:#6b7280" download>
                            📥 Download QR
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div style="background:#111827;border:1px solid #1f2937;border-radius:12px;padding:32px;margin-top:40px">
            <h2 style="color:#e5e7eb;margin:0 0 16px 0;font-size:24px">💡 Tips for On-Site Use</h2>
            <div style="color:#94a3b8;line-height:1.8">
                <ul style="margin:0;padding-left:20px">
                    <li><strong>Print and Laminate:</strong> Print QR codes and laminate them for durability</li>
                    <li><strong>Strategic Placement:</strong> Place QR codes at entry points, tool rooms, and work areas</li>
                    <li><strong>Clear Signage:</strong> Add clear labels above each QR code explaining what it's for</li>
                    <li><strong>Size Matters:</strong> Print QR codes large enough to scan from 1-2 meters away</li>
                    <li><strong>Regular Updates:</strong> Check and replace damaged or faded QR codes monthly</li>
                    <li><strong>Multiple Locations:</strong> Place the same QR code in multiple locations for convenience</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>