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

if (function_exists('maybe_check_and_expire_permits')) {
    maybe_check_and_expire_permits($db, 900);
} elseif (function_exists('check_and_expire_permits')) {
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
    $backUrl = htmlspecialchars($app->url('dashboard.php'));
    die('<h1>Access Denied</h1><p>Admin access required. <a href="' . $backUrl . '">Back to Dashboard</a></p>');
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
        <?php 
            $cssPath = $root . '/assets/app.css';
            $cssVer  = @filemtime($cssPath) ?: time();
        ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($app->url('assets/app.css')); ?>?v=<?php echo urlencode((string)$cssVer); ?>">
</head>
<body class="theme-dark">
    <header class="site-header">
        <h1 class="site-header__title">⚙️ Admin Panel</h1>
        <div class="site-header__actions">
            <span class="user-info">👤 <?php echo htmlspecialchars($currentUser['name']); ?></span>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('dashboard.php')); ?>">📊 Dashboard</a>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('/')); ?>">🏠 Home</a>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('logout.php')); ?>">🚪 Logout</a>
        </div>
    </header>

    <main class="site-container">
        <section class="hero-card">
            <h2>Welcome, <?php echo htmlspecialchars($currentUser['name']); ?>!</h2>
            <p>Manage users, configure settings, and control your permits system from here.</p>
        </section>

        <section class="stats-grid" aria-label="System metrics">
            <article class="stat-card" aria-label="Total users">
                <div class="icon">👥</div>
                <div class="value"><?php echo number_format($stats['total_users']); ?></div>
                <div class="label">Total Users</div>
            </article>
            <article class="stat-card" aria-label="Total permits">
                <div class="icon">📋</div>
                <div class="value"><?php echo number_format($stats['total_permits']); ?></div>
                <div class="label">Total Permits</div>
            </article>
            <article class="stat-card" aria-label="Active permits">
                <div class="icon">✅</div>
                <div class="value"><?php echo number_format($stats['active_permits']); ?></div>
                <div class="label">Active Permits</div>
            </article>
            <article class="stat-card" aria-label="Templates">
                <div class="icon">📄</div>
                <div class="value"><?php echo number_format($stats['total_templates']); ?></div>
                <div class="label">Templates</div>
            </article>
        </section>

        <section class="admin-grid" aria-label="Admin tools">
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

            <div class="admin-card">
                <div class="icon">🎬</div>
                <h3>Presentation Metrics</h3>
                <p>Launch a cinematic dashboard with narration-ready stats for stakeholder demos and leadership briefings.</p>
                <a href="/presentation-dashboard.php" class="btn">Open Showcase</a>
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
        </section>
    </main>
</body>
</html>