<?php
use Permits\SystemSettings;
/**
 * Main Dashboard with Metrics
 * 
 * File Path: /dashboard.php
 * Description: Main dashboard with statistics and metrics
 * Created: 24/10/2025
 * Last Modified: 24/10/2025
 * 
 * Features:
 * - Metrics cards (total, pending, active, expired)
 * - Recent permits display with filtering
 */

// Load bootstrap
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

require_once __DIR__ . '/src/check-expiry.php';

// Opportunistically sweep for expired permits while throttling the work
if (function_exists('maybe_check_and_expire_permits')) {
    maybe_check_and_expire_permits($db, 900);
} elseif (function_exists('check_and_expire_permits')) {
    check_and_expire_permits($db);
}

// Start session if not already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$currentUser = null;

if ($isLoggedIn) {
    $stmt = $db->pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get metrics
$metrics = [
    'total' => 0,
    'pending' => 0,
    'active' => 0,
    'expired' => 0
];

try {
    // Total permits
    $stmt = $db->pdo->query("SELECT COUNT(*) FROM forms");
    $metrics['total'] = $stmt->fetchColumn();
    
    // Pending permits
    $stmt = $db->pdo->query("SELECT COUNT(*) FROM forms WHERE status = 'pending_approval'");
    $metrics['pending'] = $stmt->fetchColumn();
    
    // Active permits
    $stmt = $db->pdo->query("SELECT COUNT(*) FROM forms WHERE status = 'active'");
    $metrics['active'] = $stmt->fetchColumn();
    
    // Expired permits
    $stmt = $db->pdo->query("SELECT COUNT(*) FROM forms WHERE status = 'expired'");
    $metrics['expired'] = $stmt->fetchColumn();
} catch (Exception $e) {
    // Ignore errors, metrics stay at 0
}

$companyName = SystemSettings::companyName($db) ?? 'Permits System';
$companyLogoPath = SystemSettings::companyLogoPath($db);
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;

// Resolve status filter coming from metric cards (?status=<key>)
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
$allowedFilters = ['total', 'pending', 'active', 'expired', 'rejected', 'draft', 'closed'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = '';
}

$statusSqlMap = [
    'pending'  => 'pending_approval',
    'active'   => 'active',
    'expired'  => 'expired',
    'rejected' => 'rejected',
    'draft'    => 'draft',
    'closed'   => 'closed',
];

$statusLabelMap = [
    ''        => 'Recent Permits',
    'total'   => 'All Permits',
    'pending' => 'Pending Approval',
    'active'  => 'Active Permits',
    'expired' => 'Expired Permits',
    'rejected'=> 'Rejected Permits',
    'draft'   => 'Draft Permits',
    'closed'  => 'Closed Permits',
];

$permitsLimit = $statusFilter === '' ? 10 : 50;
$permitsListTitle = $statusLabelMap[$statusFilter] ?? 'Permits';
$filterActive = $statusFilter !== '';

// Fetch permits for the current filter selection
$permitsList = [];
try {
    if ($isLoggedIn && $currentUser) {
        $conditions = [];
        $params = [];

        if (!in_array($currentUser['role'], ['admin', 'manager'], true)) {
            $conditions[] = 'f.holder_id = :holder';
            $params['holder'] = $currentUser['id'];
        }

        if ($filterActive && $statusFilter !== 'total') {
            $dbStatus = $statusSqlMap[$statusFilter] ?? null;
            if ($dbStatus !== null) {
                $conditions[] = 'f.status = :status';
                $params['status'] = $dbStatus;
            }
        }

        $whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $limitClause = 'LIMIT ' . (int) $permitsLimit;

        $sql = "
            SELECT f.*, ft.name as template_name,
                   u.name as holder_name, u.email as holder_email
            FROM forms f
            JOIN form_templates ft ON f.template_id = ft.id
            LEFT JOIN users u ON f.holder_id = u.id
            $whereClause
            ORDER BY f.created_at DESC
            $limitClause
        ";

        $stmt = $db->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $permitsList = $stmt->fetchAll();
    }
} catch (Exception $e) {
    $permitsList = [];
}

// Helper function for status badges
function getStatusBadge($status) {
    $badges = [
        'active' => '<span class="badge badge-success">✅ Active</span>',
        'pending_approval' => '<span class="badge badge-warning">⏳ Pending</span>',
        'expired' => '<span class="badge badge-danger">❌ Expired</span>',
        'rejected' => '<span class="badge badge-danger">❌ Rejected</span>',
        'draft' => '<span class="badge badge-gray">📝 Draft</span>'
    ];
    return $badges[$status] ?? '<span class="badge badge-gray">' . htmlspecialchars($status) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Dashboard - Permits System</title>
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
                <div class="brand-mark__sub">🛡️ Permit System</div>
            </div>
        </div>
        <div class="site-header__actions">
            <?php if ($isLoggedIn && $currentUser): ?>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'U', 0, 2)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($currentUser['name'] ?? 'User'); ?></div>
                        <div style="font-size: 12px; color: #6b7280;"><?php echo htmlspecialchars($currentUser['role'] ?? 'user'); ?></div>
                    </div>
                </div>
                <?php if ($currentUser['role'] === 'admin'): ?>
                    <a href="<?php echo htmlspecialchars($app->url('admin.php')); ?>" class="btn btn-secondary">⚙️ Admin</a>
                <?php endif; ?>
                <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'manager'): ?>
                    <a href="<?php echo htmlspecialchars($app->url('manager-approvals.php')); ?>" class="btn btn-secondary">✅ Approvals</a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($app->url('logout.php')); ?>" class="btn btn-secondary">🚪 Logout</a>
            <?php else: ?>
                <a href="<?php echo htmlspecialchars($app->url('login.php')); ?>" class="btn btn-primary">🔐 Login</a>
            <?php endif; ?>
        </div>
    </header>

    <main class="site-container">
        <section class="hero-card">
            <h2>🛡️ Permit System</h2>
            <p>Create permits easily, check status anytime</p>
        </section>

        <section class="stats-grid" aria-label="Permit metrics">
            <a class="stat-card metric-total<?php echo $statusFilter === 'total' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($app->url('dashboard.php?status=total')); ?>">
                <div class="icon">📊</div>
                <div class="label">Total Permits</div>
                <div class="value"><?php echo number_format($metrics['total']); ?></div>
            </a>

            <a class="stat-card metric-pending<?php echo $statusFilter === 'pending' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($app->url('dashboard.php?status=pending')); ?>">
                <div class="icon">⏳</div>
                <div class="label">Pending</div>
                <div class="value"><?php echo number_format($metrics['pending']); ?></div>
            </a>

            <a class="stat-card metric-active<?php echo $statusFilter === 'active' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($app->url('dashboard.php?status=active')); ?>">
                <div class="icon">✅</div>
                <div class="label">Active</div>
                <div class="value"><?php echo number_format($metrics['active']); ?></div>
            </a>

            <a class="stat-card metric-expired<?php echo $statusFilter === 'expired' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($app->url('dashboard.php?status=expired')); ?>">
                <div class="icon">❌</div>
                <div class="label">Expired</div>
                <div class="value"><?php echo number_format($metrics['expired']); ?></div>
            </a>
        </section>
        
        <?php if ($isLoggedIn): ?>
            <section class="surface-card">
                <div class="card-header">
                    <span style="display:flex; align-items:center; gap:8px;">
                        📜
                        <span class="filter-pill"><?php echo htmlspecialchars($permitsListTitle); ?></span>
                        <span class="filter-count">(<?php echo count($permitsList); ?>)</span>
                    </span>
                    <?php if ($filterActive): ?>
                        <a href="<?php echo htmlspecialchars($app->url('dashboard.php')); ?>" class="btn btn-secondary" style="margin-left:auto; padding: 8px 14px; font-size: 12px;">
                            ✖ Clear Filter
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($permitsList)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📜</div>
                        <p><?php echo $filterActive ? 'No permits match this status yet.' : 'No recent permits to display just yet.'; ?></p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Type</th>
                                <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'manager'): ?>
                                    <th>Holder</th>
                                <?php endif; ?>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($permitsList as $permit): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($permit['ref_number'] ?? 'N/A'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($permit['template_name']); ?></td>
                                    <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'manager'): ?>
                                        <td><?php echo htmlspecialchars($permit['holder_name'] ?? $permit['holder_email'] ?? 'Unknown'); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo getStatusBadge($permit['status']); ?></td>
                                    <td><?php echo isset($permit['created_at']) ? date('d/m/Y H:i', strtotime($permit['created_at'])) : 'N/A'; ?></td>
                                    <td>
                                        <?php if (!empty($permit['unique_link'])): ?>
                                            <a href="<?php echo htmlspecialchars($app->url('view-permit-public.php?link=' . urlencode((string)$permit['unique_link']))); ?>"
                                               class="btn btn-secondary"
                                               style="padding: 6px 12px; font-size: 12px;">
                                                👁️ View
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="surface-card">
                <div class="card-header">
                    <h3>🔒 Sign in to view permits</h3>
                </div>
                <p>Login to filter, browse, and manage permits from the dashboard.</p>
                <a href="<?php echo htmlspecialchars($app->url('login.php')); ?>" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
                    🔐 Go to Login
                </a>
            </section>
        <?php endif; ?>

        <div style="text-align: center; color: #94a3b8; margin-top: 32px; opacity: 0.8;">
            <p>© 2025 Permit System • Secure & Efficient Permit Management</p>
        </div>
    </main>
</body>
</html>