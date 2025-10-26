<?php
/**
 * Admin Panel
 * 
 * Description: Main admin dashboard with navigation to all admin functions
 * Name: admin.php
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/Auth.php';

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
$auth = new Auth($db);

// Require admin access
$auth->requireAdmin();

$currentUser = $auth->getCurrentUser();

// Get statistics
$stats = [];
$stats['total_users'] = $db->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$stats['total_permits'] = $db->pdo->query("SELECT COUNT(*) FROM forms")->fetchColumn();
$stats['active_permits'] = $db->pdo->query("SELECT COUNT(*) FROM forms WHERE status='active'")->fetchColumn();
$stats['total_templates'] = $db->pdo->query("SELECT COUNT(*) FROM form_templates")->fetchColumn();

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Permits System</title>
    <link rel="stylesheet" href="/assets/app.css">
    <style>
        .admin-wrap{max-width:1200px;margin:0 auto;padding:20px}
        .welcome{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:24px;margin-bottom:24px}
        .welcome h2{color:#e5e7eb;margin:0 0 8px 0}
        .welcome p{color:#94a3b8;margin:0}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
        .stat-card{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:20px;text-align:center}
        .stat-card .icon{font-size:32px;margin-bottom:12px}
        .stat-card .value{font-size:32px;font-weight:700;color:#e5e7eb;margin-bottom:8px}
        .stat-card .label{color:#94a3b8;font-size:14px;text-transform:uppercase;letter-spacing:0.5px}
        .admin-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px}
        .admin-card{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:24px;transition:transform 0.2s,border-color 0.2s}
        .admin-card:hover{transform:translateY(-2px);border-color:#0ea5e9}
        .admin-card h3{color:#e5e7eb;margin:0 0 12px 0;font-size:20px}
        .admin-card p{color:#94a3b8;margin:0 0 20px 0;font-size:14px;line-height:1.6}
        .admin-card .icon{font-size:48px;margin-bottom:16px;opacity:0.8}
        .admin-card .btn{width:100%}
    </style>
</head>
<body>
    <header class="top">
        <h1>⚙️ Admin Panel</h1>
        <div style="display:flex;gap:8px;align-items:center">
            <span style="color:#94a3b8;font-size:14px">👤 <?=htmlspecialchars($currentUser['name'])?></span>
            <a class="btn" href="/dashboard">📊 Dashboard</a>
            <a class="btn" href="/">🏠 Home</a>
            <a class="btn" href="/logout.php">🚪 Logout</a>
        </div>
    </header>
    
    <div class="admin-wrap">
        <div class="welcome">
            <h2>Welcome to the Admin Panel, <?=htmlspecialchars($currentUser['name'])?>!</h2>
            <p>Manage users, configure settings, and control your permits system from here.</p>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon">👥</div>
                <div class="value"><?=number_format($stats['total_users'])?></div>
                <div class="label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="icon">📋</div>
                <div class="value"><?=number_format($stats['total_permits'])?></div>
                <div class="label">Total Permits</div>
            </div>
            <div class="stat-card">
                <div class="icon">✅</div>
                <div class="value"><?=number_format($stats['active_permits'])?></div>
                <div class="label">Active Permits</div>
            </div>
            <div class="stat-card">
                <div class="icon">📄</div>
                <div class="value"><?=number_format($stats['total_templates'])?></div>
                <div class="label">Templates</div>
            </div>
        </div>
        
        <!-- Admin Functions -->
        <div class="admin-grid">
            <!-- User Management -->
            <div class="admin-card">
                <div class="icon">👥</div>
                <h3>User Management</h3>
                <p>Create, edit, and manage user accounts. Invite new users, assign roles, and control access permissions.</p>
                <a href="/admin/users.php" class="btn">Manage Users</a>
            </div>
            
            <!-- Email Settings -->
            <div class="admin-card">
                <div class="icon">📧</div>
                <h3>Email Settings</h3>
                <p>Configure email notifications, SMTP settings, and notification recipients for permit alerts.</p>
                <a href="/admin/email-settings.php" class="btn">Email Settings</a>
            </div>
            
            <!-- Template Management -->
            <div class="admin-card">
                <div class="icon">📄</div>
                <h3>Template Management</h3>
                <p>Upload, edit, and manage permit templates. Add new form types and customize existing ones.</p>
                <a href="/admin/templates.php" class="btn">Manage Templates</a>
            </div>
            
            <!-- System Settings -->
            <div class="admin-card">
                <div class="icon">⚙️</div>
                <h3>System Settings</h3>
                <p>Configure general system settings, site information, and application preferences.</p>
                <a href="/admin/settings.php" class="btn">System Settings</a>
            </div>
            
            <!-- Activity Log -->
            <div class="admin-card">
                <div class="icon">📊</div>
                <h3>Activity Log</h3>
                <p>View system activity, user actions, and audit trail. Monitor all changes and events.</p>
                <a href="/admin/activity.php" class="btn">View Activity</a>
            </div>
            
            <!-- Database Backup -->
            <div class="admin-card">
                <div class="icon">💾</div>
                <h3>Backup & Restore</h3>
                <p>Create database backups, export data, and restore from previous backups.</p>
                <a href="/admin/backup.php" class="btn">Backup Tools</a>
            </div>
        </div>
    </div>
</body>
</html>
