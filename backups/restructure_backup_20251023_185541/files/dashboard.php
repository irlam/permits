<?php
/**
 * Main Dashboard - Permits & Registers
 * 
 * File Path: /dashboard.php
 * Description: Main landing page with easy permit access, templates, and QR codes
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * Features:
 * - Quick permit creation buttons
 * - Recent permits display
 * - QR code access
 * - User-friendly interface
 * - Activity logging
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/Auth.php';
require __DIR__ . '/src/ActivityLogger.php';

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
$auth = new Auth($db);
$logger = new \Permits\ActivityLogger($db);

// Check if user is logged in
$isLoggedIn = $auth->isLoggedIn();
$currentUser = null;

if ($isLoggedIn) {
    $currentUser = $auth->getCurrentUser();
    $logger->setUser($currentUser['id'], $currentUser['email']);
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

// Get recent forms for logged-in users
$recentForms = [];
if ($isLoggedIn) {
    $stmt = $db->pdo->prepare("
        SELECT f.*, t.name as template_name
        FROM forms f
        LEFT JOIN form_templates t ON f.template_id = t.id
        WHERE f.created_by = ?
        ORDER BY f.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$currentUser['id']]);
    $recentForms = $stmt->fetchAll();
}

// Get statistics
$stats = [];
if ($isLoggedIn) {
    $stmt = $db->pdo->prepare("SELECT COUNT(*) FROM forms WHERE created_by = ?");
    $stmt->execute([$currentUser['id']]);
    $stats['my_permits'] = $stmt->fetchColumn();
    
    $stmt = $db->pdo->prepare("SELECT COUNT(*) FROM forms WHERE created_by = ? AND status = 'active'");
    $stmt->execute([$currentUser['id']]);
    $stats['active_permits'] = $stmt->fetchColumn();
    
    $stmt = $db->pdo->prepare("SELECT COUNT(*) FROM forms WHERE created_by = ? AND approval_status = 'pending'");
    $stmt->execute([$currentUser['id']]);
    $stats['pending_permits'] = $stmt->fetchColumn();
}

// Log page access
if ($isLoggedIn) {
    $logger->log('dashboard_viewed', 'system', 'dashboard', null, 'Dashboard accessed');
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Permits & Registers</title>
    <link rel="stylesheet" href="/assets/app.css">
    <style>
        body{background:#0a101a}
        .hero{background:linear-gradient(135deg,#1e3a8a 0%,#0ea5e9 100%);padding:60px 20px;text-align:center;margin-bottom:40px}
        .hero h1{font-size:48px;color:#fff;margin:0 0 16px 0;font-weight:700}
        .hero p{font-size:20px;color:#e0f2fe;margin:0;opacity:0.9}
        .container{max-width:1400px;margin:0 auto;padding:0 20px 40px}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:40px}
        .stat-card{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:24px;text-align:center;transition:transform 0.2s}
        .stat-card:hover{transform:translateY(-4px);border-color:#0ea5e9}
        .stat-card .icon{font-size:40px;margin-bottom:12px}
        .stat-card .value{font-size:36px;font-weight:700;color:#e5e7eb;margin-bottom:8px}
        .stat-card .label{color:#94a3b8;font-size:14px;text-transform:uppercase;letter-spacing:0.5px}
        .section{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:32px;margin-bottom:32px}
        .section-title{font-size:24px;font-weight:600;color:#e5e7eb;margin:0 0 24px 0;display:flex;align-items:center;gap:12px}
        .section-title .icon{font-size:28px}
        .templates-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px}
        .template-card{background:#0a101a;border:2px solid #1f2937;border-radius:12px;padding:24px;transition:all 0.2s;cursor:pointer}
        .template-card:hover{border-color:#0ea5e9;transform:translateY(-2px);box-shadow:0 8px 16px rgba(14,165,233,0.2)}
        .template-card h3{color:#e5e7eb;font-size:20px;margin:0 0 12px 0}
        .template-card p{color:#94a3b8;font-size:14px;margin:0 0 20px 0;line-height:1.6}
        .template-card .actions{display:flex;gap:12px}
        .template-card .btn{flex:1;padding:12px;font-size:14px;font-weight:600}
        .qr-preview{width:80px;height:80px;background:#fff;border-radius:8px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;border:2px solid #1f2937}
        .qr-preview img{width:100%;height:100%;object-fit:contain}
        .forms-table{width:100%;border-collapse:collapse}
        .forms-table th{background:#0a101a;color:#94a3b8;padding:12px;text-align:left;font-size:12px;text-transform:uppercase;border-bottom:1px solid #1f2937;position:sticky;top:0}
        .forms-table td{padding:12px;color:#e5e7eb;border-bottom:1px solid #1f2937}
        .forms-table tr:hover{background:#0a101a}
        .status-badge{display:inline-block;padding:4px 12px;border-radius:6px;font-size:11px;font-weight:600;text-transform:uppercase}
        .status-active{background:#10b981;color:#fff}
        .status-draft{background:#6b7280;color:#fff}
        .status-expired{background:#ef4444;color:#fff}
        .status-pending{background:#f59e0b;color:#000}
        .status-approved{background:#10b981;color:#fff}
        .status-rejected{background:#ef4444;color:#fff}
        .empty-state{text-align:center;padding:60px 20px;color:#6b7280}
        .empty-state .icon{font-size:64px;margin-bottom:16px;opacity:0.3}
        .empty-state h3{color:#e5e7eb;margin:0 0 8px 0}
        .cta-section{background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%);border-radius:12px;padding:40px;text-align:center;margin-bottom:32px}
        .cta-section h2{color:#fff;font-size:28px;margin:0 0 12px 0}
        .cta-section p{color:#e0e7ff;font-size:16px;margin:0 0 24px 0}
        .cta-buttons{display:flex;gap:16px;justify-content:center;flex-wrap:wrap}
        .quick-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-bottom:32px}
        .quick-action{background:#111827;border:1px solid #1f2937;border-radius:8px;padding:20px;display:flex;align-items:center;gap:16px;cursor:pointer;transition:all 0.2s;text-decoration:none}
        .quick-action:hover{border-color:#0ea5e9;background:#0f1419;transform:translateY(-2px)}
        .quick-action .icon{font-size:32px;flex-shrink:0}
        .quick-action .text{flex:1}
        .quick-action .title{color:#e5e7eb;font-weight:600;margin-bottom:4px;font-size:16px}
        .quick-action .desc{color:#94a3b8;font-size:13px}
    </style>
</head>
<body>
    <header class="top">
        <h1>📋 Permits & Registers</h1>
        <div style="display:flex;gap:8px">
            <?php if($isLoggedIn): ?>
                <?php if($auth->hasRole('admin')): ?>
                    <a class="btn" href="/admin.php">⚙️ Admin</a>
                <?php endif; ?>
                <a class="btn" href="/logout.php">🔓 Logout</a>
            <?php else: ?>
                <a class="btn" href="/login.php">🔐 Login</a>
            <?php endif; ?>
        </div>
    </header>
    
    <?php if(!$isLoggedIn): ?>
        <!-- Hero Section for Non-Logged In Users -->
        <div class="hero">
            <h1>🛡️ Welcome to Permits & Registers</h1>
            <p>Digital permit management made simple and secure</p>
        </div>
        
        <div class="container">
            <div class="cta-section">
                <h2>Get Started</h2>
                <p>Create permits quickly using QR codes or login to manage your permits</p>
                <div class="cta-buttons">
                    <a href="/qr-codes.php" class="btn" style="background:#fff;color:#000;padding:12px 32px;font-size:16px">
                        📱 View QR Codes
                    </a>
                    <a href="/login.php" class="btn" style="padding:12px 32px;font-size:16px">
                        🔐 Login to Dashboard
                    </a>
                </div>
            </div>
            
            <div class="section">
                <div class="section-title">
                    <span class="icon">📄</span>
                    <span>Available Permit Templates</span>
                </div>
                
                <?php if(empty($templates)): ?>
                    <div class="empty-state">
                        <div class="icon">📋</div>
                        <h3>No templates available</h3>
                        <p>Please contact your administrator</p>
                    </div>
                <?php else: ?>
                    <div class="templates-grid">
                        <?php foreach($templates as $template): ?>
                        <div class="template-card">
                            <div class="qr-preview">
                                <img src="/qr-code.php?template=<?=$template['id']?>&size=80" alt="QR Code">
                            </div>
                            <h3><?=htmlspecialchars($template['name'])?></h3>
                            <p><?=htmlspecialchars($template['description'] ?? 'No description')?></p>
                            <div class="actions">
                                <a href="/create-permit.php?template=<?=$template['id']?>" class="btn">
                                    ➕ Create Permit
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <!-- Dashboard for Logged In Users -->
        <div class="container">
            <h2 style="color:#e5e7eb;margin:0 0 24px 0;font-size:32px">Welcome back, <?=htmlspecialchars($currentUser['name'] ?? $currentUser['email'])?> 👋</h2>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="icon">📋</div>
                    <div class="value"><?=$stats['my_permits'] ?? 0?></div>
                    <div class="label">My Permits</div>
                </div>
                <div class="stat-card">
                    <div class="icon">✅</div>
                    <div class="value"><?=$stats['active_permits'] ?? 0?></div>
                    <div class="label">Active</div>
                </div>
                <div class="stat-card">
                    <div class="icon">⏳</div>
                    <div class="value"><?=$stats['pending_permits'] ?? 0?></div>
                    <div class="label">Pending Approval</div>
                </div>
                <div class="stat-card">
                    <div class="icon">📄</div>
                    <div class="value"><?=count($templates)?></div>
                    <div class="label">Templates Available</div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="/create-permit.php" class="quick-action">
                    <div class="icon">➕</div>
                    <div class="text">
                        <div class="title">Create New Permit</div>
                        <div class="desc">Start a new permit application</div>
                    </div>
                </a>
                <a href="/qr-codes.php" class="quick-action">
                    <div class="icon">📱</div>
                    <div class="text">
                        <div class="title">View QR Codes</div>
                        <div class="desc">Scan to create permits quickly</div>
                    </div>
                </a>
                <a href="/my-permits.php" class="quick-action">
                    <div class="icon">📊</div>
                    <div class="text">
                        <div class="title">My Permits</div>
                        <div class="desc">View all your permits</div>
                    </div>
                </a>
                <?php if($stats['pending_permits'] > 0): ?>
                <a href="/my-permits.php?status=pending" class="quick-action" style="border-color:#f59e0b">
                    <div class="icon">⏳</div>
                    <div class="text">
                        <div class="title">Pending Approval (<?=$stats['pending_permits']?>)</div>
                        <div class="desc">Permits waiting for approval</div>
                    </div>
                </a>
                <?php endif; ?>
            </div>
            
            <!-- Create Permit Templates -->
            <div class="section">
                <div class="section-title">
                    <span class="icon">📄</span>
                    <span>Create New Permit</span>
                </div>
                
                <?php if(empty($templates)): ?>
                    <div class="empty-state">
                        <div class="icon">📋</div>
                        <h3>No templates available</h3>
                        <p>Please contact your administrator</p>
                    </div>
                <?php else: ?>
                    <div class="templates-grid">
                        <?php foreach($templates as $template): ?>
                        <div class="template-card" onclick="window.location.href='/create-permit.php?template=<?=$template['id']?>'">
                            <div class="qr-preview">
                                <img src="/qr-code.php?template=<?=$template['id']?>&size=80" alt="QR Code">
                            </div>
                            <h3><?=htmlspecialchars($template['name'])?></h3>
                            <p><?=htmlspecialchars($template['description'] ?? 'No description')?></p>
                            <div class="actions">
                                <a href="/create-permit.php?template=<?=$template['id']?>" class="btn" onclick="event.stopPropagation()">
                                    ➕ Create Permit
                                </a>
                                <a href="/qr-code.php?template=<?=$template['id']?>&download=1" class="btn" style="background:#6b7280" onclick="event.stopPropagation()">
                                    📥 QR Code
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Recent Permits -->
            <?php if(!empty($recentForms)): ?>
            <div class="section">
                <div class="section-title">
                    <span class="icon">📋</span>
                    <span>Recent Permits</span>
                    <a href="/my-permits.php" class="btn" style="margin-left:auto;padding:8px 16px;font-size:14px">View All</a>
                </div>
                
                <div style="overflow-x:auto">
                    <table class="forms-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Template</th>
                                <th>Status</th>
                                <th>Approval Status</th>
                                <th>Created</th>
                                <th>Valid Until</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recentForms as $form): ?>
                            <tr>
                                <td><strong><?=htmlspecialchars($form['reference'])?></strong></td>
                                <td><?=htmlspecialchars($form['template_name'])?></td>
                                <td>
                                    <span class="status-badge status-<?=$form['status']?>">
                                        <?=ucfirst($form['status'])?>
                                    </span>
                                </td>
                                <td>
                                    <?php if($form['approval_status']): ?>
                                        <span class="status-badge status-<?=$form['approval_status']?>">
                                            <?=ucfirst($form['approval_status'])?>
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge" style="background:#374151">Not Required</span>
                                    <?php endif; ?>
                                </td>
                                <td><?=date('d/m/Y H:i', strtotime($form['created_at']))?></td>
                                <td>
                                    <?php if($form['valid_to']): ?>
                                        <?=date('d/m/Y H:i', strtotime($form['valid_to']))?>
                                    <?php else: ?>
                                        <span style="color:#6b7280">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="/view-permit.php?id=<?=$form['id']?>" class="btn" style="padding:6px 12px;font-size:12px">
                                        View
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>