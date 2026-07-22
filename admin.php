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

use Permits\SystemSettings;

require_once __DIR__ . '/src/Auth.php';
$auth = new Auth($db);
$currentUser = $auth->requireRoles(['admin']);

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

$branding = SystemSettings::branding($db);
$companyName = $branding['company_name'];
$companyLogoPath = $branding['company_logo_path'];
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;
$brandingCss = SystemSettings::brandingCssVariables($branding);
?>
<!DOCTYPE html>
<html lang="en" style="<?= htmlspecialchars($brandingCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Admin Panel - <?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
            <meta name="theme-color" content="<?= htmlspecialchars($branding['primary_colour'], ENT_QUOTES, 'UTF-8') ?>">
            <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
</head>
<body class="theme-dark">
    <header class="site-header">
        <div class="brand-mark">
            <?php if ($companyLogoUrl): ?>
                <img src="<?= $companyLogoUrl ?>" alt="<?= htmlspecialchars($companyName) ?> logo" class="brand-mark__logo">
            <?php endif; ?>
            <div>
                <div class="brand-mark__name"><?= htmlspecialchars($companyName) ?></div>
                <div class="brand-mark__sub">⚙️ Admin Panel</div>
            </div>
        </div>
        <div class="site-header__actions">
            <span class="user-info">👤 <?php echo htmlspecialchars($currentUser['name']); ?></span>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('dashboard.php')); ?>">📊 Dashboard</a>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('account.php')); ?>">🔑 My Account</a>
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

            <!-- External Template Importer -->
            <div class="admin-card">
                <div class="icon">🌐</div>
                <h3>External Template Starter</h3>
                <p>Create a basic, editable starting template from the title of a public SafetyCulture, OSHA, HSE or other HTTPS page. Always review and complete the safety fields before publishing.<br><br>
                <strong>Supported sources:</strong>
                <ul style="margin:8px 0 0 18px;padding:0;font-size:14px;">
                  <li><a href="https://safetyculture.com/library" target="_blank" rel="noopener">SafetyCulture Library</a></li>
                  <li><a href="https://www.osha.gov/sample-safety-health-programs" target="_blank" rel="noopener">OSHA Sample Programs</a></li>
                  <li><a href="https://www.hse.gov.uk/construction/" target="_blank" rel="noopener">HSE Construction (UK)</a></li>
                  <li><a href="https://marketplace.safetyculture.com/templates" target="_blank" rel="noopener">iAuditor Marketplace</a></li>
                  <li><a href="https://www.safeworkaustralia.gov.au/doc/templates-and-forms" target="_blank" rel="noopener">Safe Work Australia</a></li>
                </ul>
                </p>
                <a href="/admin/admin-external-template-import.php" class="btn">Create Template Starter</a>
            </div>

            <div class="admin-card">
                <div class="icon">🛠️</div>
                <h3>Edit Permit Templates</h3>
                <p>Review, tweak, and republish existing permit templates so each scenario has the right questions before issuing.</p>
                <a href="/admin-template-editor.php" class="btn">Edit Templates</a>
            </div>

            <div class="admin-card">
                <div class="icon">✉️</div>
                <h3>Approval Notifications</h3>
                <p>Maintain the list of people emailed as soon as a permit is submitted for approval.</p>
                <a href="/admin-approval-notifications.php" class="btn">Manage Recipients</a>
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

            <!-- Customer Help Centre -->
            <div class="admin-card">
                <div class="icon">?</div>
                <h3>Help Centre</h3>
                <p>Clear setup, daily-use and troubleshooting guidance for administrators and permit users.</p>
                <a href="/customer-guide/" class="btn">Open Help Centre</a>
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

            <!-- QR Codes - All Permits -->
            <div class="admin-card">
                <div class="icon">🔲</div>
                <h3>QR Codes - All Permits</h3>
                <p>Generate and display QR codes for all active permits. Perfect for printing notice boards. Includes company branding. Auto-updates with new permits.</p>
                <a href="/admin/qr-codes-all.php" class="btn">View All QR Codes</a>
            </div>

            <!-- QR Codes - Individual -->
            <div class="admin-card">
                <div class="icon">📋</div>
                <h3>QR Codes - Individual</h3>
                <p>Search permits and generate individual QR codes with full permit details. Perfect for custom notice board posts or sending to specific stakeholders.</p>
                <a href="/admin/qr-codes-individual.php" class="btn">Generate Individual QR Code</a>
            </div>
    </main>
</body>
</html>
