<?php
/**
 * Admin Panel - Simple Version
 * 
 * File Path: /admin.php
 * Description: Main admin dashboard without Auth class dependency
 * Created: 24/10/2025
 * Last Modified: 24/10/2025
 * 
 * Features:
 * - Admin dashboard
 * - Statistics display
 * - Links to all admin functions
 * - Simple session-based auth
 */

// Load bootstrap
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

require_once __DIR__ . '/src/check-expiry.php';

if (function_exists('check_and_expire_permits')) {
    check_and_expire_permits($db);
}

// Start session
session_start();

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

// Get statistics
$stats = [];
try {
    $stats['total_users'] = $db->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['total_permits'] = $db->pdo->query("SELECT COUNT(*) FROM forms")->fetchColumn();
    $stats['active_permits'] = $db->pdo->query("SELECT COUNT(*) FROM forms WHERE status='active'")->fetchColumn();
    $stats['total_templates'] = $db->pdo->query("SELECT COUNT(*) FROM form_templates")->fetchColumn();
} catch (Exception $e) {
    $stats = [
        'total_users' => 0,
        'total_permits' => 0,
        'active_permits' => 0,
        'total_templates' => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Permits System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #0f172a;
            color: #e5e7eb;
            min-height: 100vh;
        }
        
        .header {
            background: #1e293b;
            border-bottom: 1px solid #334155;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .header h1 {
            font-size: 24px;
            color: #e5e7eb;
        }
        
        .header-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .user-info {
            color: #94a3b8;
            font-size: 14px;
            margin-right: 12px;
        }
        
        .btn {
            padding: 8px 16px;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
            display: inline-block;
        }
        
        .btn:hover {
            background: #2563eb;
        }
        
        .btn-secondary {
            background: #475569;
        }
        
        .btn-secondary:hover {
            background: #64748b;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }
        
        .welcome {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }
        
        .welcome h2 {
            color: #e5e7eb;
            margin-bottom: 8px;
        }
        
        .welcome p {
            color: #94a3b8;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-card .icon {
            font-size: 32px;
            margin-bottom: 12px;
        }
        
        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
            color: #e5e7eb;
            margin-bottom: 8px;
        }
        
        .stat-card .label {
            color: #94a3b8;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .admin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .admin-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 24px;
            transition: all 0.2s;
        }
        
        .admin-card:hover {
            transform: translateY(-2px);
            border-color: #3b82f6;
        }
        
        .admin-card .icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.8;
        }
        
        .admin-card h3 {
            color: #e5e7eb;
            margin-bottom: 12px;
            font-size: 20px;
        }
        
        .admin-card p {
            color: #94a3b8;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .admin-card .btn {
            width: 100%;
            text-align: center;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #bbf7d0;
        }

        .alert-error {
            background: rgba(220, 38, 38, 0.12);
            border: 1px solid rgba(220, 38, 38, 0.35);
            color: #fecaca;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .header-actions {
                width: 100%;
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>⚙️ Admin Panel</h1>
        <div class="header-actions">
            <span class="user-info">👤 <?php echo htmlspecialchars($currentUser['name']); ?></span>
            <a class="btn btn-secondary" href="/dashboard.php">📊 Dashboard</a>
            <a class="btn btn-secondary" href="/">🏠 Home</a>
            <a class="btn btn-secondary" href="/logout.php">🚪 Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="welcome">
            <h2>Welcome, <?php echo htmlspecialchars($currentUser['name']); ?>!</h2>
            <p>Manage users, configure settings, and control your permits system from here.</p>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon">👥</div>
                <div class="value"><?php echo number_format($stats['total_users']); ?></div>
                <div class="label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="icon">📋</div>
                <div class="value"><?php echo number_format($stats['total_permits']); ?></div>
                <div class="label">Total Permits</div>
            </div>
            <div class="stat-card">
                <div class="icon">✅</div>
                <div class="value"><?php echo number_format($stats['active_permits']); ?></div>
                <div class="label">Active Permits</div>
            </div>
            <div class="stat-card">
                <div class="icon">📄</div>
                <div class="value"><?php echo number_format($stats['total_templates']); ?></div>
                <div class="label">Templates</div>
            </div>
        </div>
        
        <!-- Admin Functions -->
        <div class="admin-grid">
            <div class="admin-card">
                <div class="icon">⏱️</div>
                <h3>Permit Duration Presets</h3>
                <p>Define the quick-select expiry options used when issuing permits. Manage the preset list from a dedicated admin page.</p>
                <a href="/admin-permit-durations.php" class="btn">Manage Durations</a>
            </div>

            <!-- User Management -->
            <div class="admin-card">
                <div class="icon">👥</div>
                <h3>User Management</h3>
                <p>Create, edit, and manage user accounts. Invite new users, assign roles, and control access permissions.</p>
                <a href="/admin/users.php" class="btn">Manage Users</a>
            </div>

            <!-- Custom Permit Creator -->
            <div class="admin-card">
                <div class="icon">🧩</div>
                <h3>Custom Permit Creator</h3>
                <p>Clone an existing template or start from a blank canvas, then jump straight into issuing your custom permit.</p>
                <a href="/admin-custom-permit.php" class="btn">Create Custom Permit</a>
            </div>

            <!-- Template Importer -->
            <div class="admin-card">
                <div class="icon">📦</div>
                <h3>Permit Template Importer</h3>
                <p>Run the built-in seeder to sync all JSON presets in seconds. Ideal for shared hosting without CLI access.</p>
                <a href="/admin-template-import.php" class="btn">Import Templates</a>
            </div>

            <div class="admin-card">
                <div class="icon">🛠️</div>
                <h3>Edit Permit Templates</h3>
                <p>Review, tweak, and republish existing permit templates so each scenario has the right questions before issuing.</p>
                <a href="/admin-template-editor.php" class="btn">Edit Templates</a>
            </div>
            
            <!-- Email Settings -->
            <div class="admin-card">
                <div class="icon">📧</div>
                <h3>Email Settings</h3>
                <p>Configure email notifications, SMTP settings, and notification recipients for permit alerts.</p>
                <a href="/admin/email-settings.php" class="btn">Email Settings</a>
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
            
            <!-- Manager Approvals -->
            <div class="admin-card">
                <div class="icon">✅</div>
                <h3>Pending Approvals</h3>
                <p>Review and approve pending permit requests. Manage the approval workflow.</p>
                <a href="/manager-approvals.php" class="btn">View Approvals</a>
            </div>
        </div>
    </div>

</body>
</html>