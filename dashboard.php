<?php
use Permits\SystemSettings;
/**
 * Main Dashboard with Advanced Metrics
 * 
 * File Path: /dashboard.php
 * Description: Main dashboard with advanced statistics, analytics and real-time metrics
 * Created: 24/10/2025
 * Last Modified: 01/11/2025
 * 
 * Features:
 * - Advanced metrics cards (total, pending, active, expired)
 * - Real-time analytics and trends
 * - Activity timeline
 * - Permit creation trends
 * - Status distribution
 * - Performance indicators
 * - Recent activity log
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
    'expired' => 0,
    'rejected' => 0,
    'draft' => 0,
    'closed' => 0
];

$analytics = [
    'todayCreated' => 0,
    'thisWeekCreated' => 0,
    'thisMonthCreated' => 0,
    'avgCompletionTime' => 0,
    'approvalRate' => 0,
    'expiryRate' => 0
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
    
    // Rejected permits
    $stmt = $db->pdo->query("SELECT COUNT(*) FROM forms WHERE status = 'rejected'");
    $metrics['rejected'] = $stmt->fetchColumn();
    
    // Draft permits
    $stmt = $db->pdo->query("SELECT COUNT(*) FROM forms WHERE status = 'draft'");
    $metrics['draft'] = $stmt->fetchColumn();
    
    // Closed permits
    $stmt = $db->pdo->query("SELECT COUNT(*) FROM forms WHERE status = 'closed'");
    $metrics['closed'] = $stmt->fetchColumn();
    
    // Today's permits
    $stmt = $db->pdo->query("SELECT COUNT(*) FROM forms WHERE DATE(created_at) = DATE(NOW())");
    $analytics['todayCreated'] = $stmt->fetchColumn();
    
    // This week's permits
    $stmt = $db->pdo->query("SELECT COUNT(*) FROM forms WHERE WEEK(created_at) = WEEK(NOW()) AND YEAR(created_at) = YEAR(NOW())");
    $analytics['thisWeekCreated'] = $stmt->fetchColumn();
    
    // This month's permits
    $stmt = $db->pdo->query("SELECT COUNT(*) FROM forms WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())");
    $analytics['thisMonthCreated'] = $stmt->fetchColumn();
    
    // Approval rate (approved / requires approval)
    $stmt = $db->pdo->query("SELECT COUNT(*) FROM forms WHERE approval_status = 'approved' AND requires_approval = 1");
    $approved = $stmt->fetchColumn();
    $stmt = $db->pdo->query("SELECT COUNT(*) FROM forms WHERE requires_approval = 1");
    $requiresApproval = $stmt->fetchColumn();
    $analytics['approvalRate'] = $requiresApproval > 0 ? round(($approved / $requiresApproval) * 100, 1) : 0;
    
    // Expiry rate
    $stmt = $db->pdo->query("SELECT COUNT(*) FROM forms WHERE status = 'expired'");
    $expiredCount = $stmt->fetchColumn();
    $total = $metrics['total'];
    $analytics['expiryRate'] = $total > 0 ? round(($expiredCount / $total) * 100, 1) : 0;
    
} catch (Exception $e) {
    // Ignore errors, metrics stay at 0
}

$companyName = SystemSettings::companyName($db) ?? 'Permits System';
$companyLogoPath = SystemSettings::companyLogoPath($db);
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;

// Get 7-day trend data for chart
$permitTrends = [];
try {
    $stmt = $db->pdo->query("
        SELECT DATE(created_at) as date, COUNT(*) as count 
        FROM forms 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $trends = $stmt->fetchAll();
    
    // Build 7-day array
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $count = 0;
        foreach ($trends as $trend) {
            if ($trend['date'] === $date) {
                $count = $trend['count'];
                break;
            }
        }
        $permitTrends[$date] = $count;
    }
} catch (Exception $e) {
    // Ignore
}

// Get status distribution
$statusDistribution = [];
try {
    $statuses = ['active', 'pending_approval', 'expired', 'draft', 'rejected', 'closed'];
    foreach ($statuses as $status) {
        $stmt = $db->pdo->prepare("SELECT COUNT(*) FROM forms WHERE status = ?");
        $stmt->execute([$status]);
        $count = $stmt->fetchColumn();
        if ($count > 0) {
            $statusDistribution[$status] = $count;
        }
    }
} catch (Exception $e) {
    // Ignore
}

// Get recent activity log (last 8 items)
$recentActivity = [];
try {
    $stmt = $db->pdo->query("
        SELECT id, timestamp, action, description, user_email 
        FROM activity_log 
        ORDER BY timestamp DESC 
        LIMIT 8
    ");
    $recentActivity = $stmt->fetchAll();
} catch (Exception $e) {
    // Ignore
}

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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
    <style>
        .dashboard-header {
            background: linear-gradient(135deg, #0f172a 0%, #111a2e 100%);
            border-bottom: 2px solid #1e293b;
            padding: 24px;
            margin-bottom: 32px;
            border-radius: 16px;
        }

        .dashboard-greeting {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .greeting-text h1 {
            color: #f1f5f9;
            font-size: 28px;
            margin: 0;
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .greeting-sub {
            color: #94a3b8;
            font-size: 14px;
            margin-top: 4px;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .quick-stat {
            background: rgba(6, 182, 212, 0.1);
            border: 1px solid rgba(6, 182, 212, 0.3);
            border-radius: 12px;
            padding: 12px;
            text-align: center;
        }

        .quick-stat-label {
            color: #94a3b8;
            font-size: 12px;
            font-weight: 500;
        }

        .quick-stat-value {
            color: #06b6d4;
            font-size: 20px;
            font-weight: 700;
            margin-top: 4px;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .metric-card {
            background: linear-gradient(135deg, #0f172a 0%, #111a2e 100%);
            border: 2px solid #1e293b;
            border-radius: 16px;
            padding: 24px;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #06b6d4, #0ea5e9);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.3s ease;
        }

        .metric-card:hover {
            border-color: #0ea5e9;
            box-shadow: 0 8px 24px rgba(6, 182, 212, 0.15);
            transform: translateY(-4px);
        }

        .metric-card:hover::before {
            transform: scaleX(1);
        }

        .metric-card.pending { --accent: #f59e0b; }
        .metric-card.active { --accent: #10b981; }
        .metric-card.expired { --accent: #ef4444; }
        .metric-card.draft { --accent: #64748b; }

        .metric-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .metric-icon {
            font-size: 28px;
        }

        .metric-trend {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            padding: 4px 8px;
            background: rgba(16, 185, 129, 0.1);
            border-radius: 6px;
            color: #10b981;
        }

        .metric-label {
            color: #94a3b8;
            font-size: 13px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .metric-value {
            color: #f1f5f9;
            font-size: 32px;
            font-weight: 700;
            margin-top: 8px;
        }

        .metric-subtitle {
            color: #64748b;
            font-size: 12px;
            margin-top: 8px;
        }

        .analytics-section {
            background: linear-gradient(135deg, #0f172a 0%, #111a2e 100%);
            border: 2px solid #1e293b;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
        }

        .section-title {
            color: #f1f5f9;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .chart-container {
            background: linear-gradient(135deg, #0a101a 0%, #0f172a 100%);
            border: 1px solid #1e293b;
            border-radius: 12px;
            padding: 20px;
            position: relative;
            height: 300px;
        }

        .activity-timeline {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .activity-item {
            display: flex;
            gap: 12px;
            padding: 12px;
            background: rgba(6, 182, 212, 0.05);
            border-left: 3px solid #06b6d4;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .activity-item:hover {
            background: rgba(6, 182, 212, 0.1);
            transform: translateX(4px);
        }

        .activity-time {
            color: #94a3b8;
            font-size: 12px;
            white-space: nowrap;
            font-weight: 500;
        }

        .activity-content {
            flex: 1;
            min-width: 0;
        }

        .activity-action {
            color: #e2e8f0;
            font-size: 13px;
            font-weight: 500;
        }

        .activity-desc {
            color: #64748b;
            font-size: 12px;
            margin-top: 4px;
            word-break: break-word;
        }

        .kpi-badges {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
        }

        .kpi-badge {
            background: linear-gradient(135deg, rgba(6, 182, 212, 0.1) 0%, rgba(14, 165, 233, 0.05) 100%);
            border: 1px solid rgba(6, 182, 212, 0.3);
            border-radius: 12px;
            padding: 16px;
            text-align: center;
        }

        .kpi-label {
            color: #94a3b8;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .kpi-value {
            color: #06b6d4;
            font-size: 24px;
            font-weight: 700;
            margin-top: 8px;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 16px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body class="theme-dark">
    <header class="site-header">
        <div class="brand-mark">
            <?php if ($companyLogoUrl): ?>
                <img src="<?= $companyLogoUrl ?>" alt="<?= htmlspecialchars($companyName) ?> logo" class="brand-mark__logo">
            <?php endif; ?>
            <div>
                <div class="brand-mark__name"><?= htmlspecialchars($companyName) ?></div>
                <div class="brand-mark__sub">📊 Smart Dashboard</div>
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
        <?php if ($isLoggedIn): ?>
            <!-- Dashboard Header with Quick Stats -->
            <div class="dashboard-header">
                <div class="dashboard-greeting">
                    <div class="greeting-text">
                        <h1>👋 Welcome back, <?= htmlspecialchars(explode(' ', $currentUser['name'] ?? 'User')[0]); ?>!</h1>
                    </div>
                    <div style="text-align: right; color: #94a3b8; font-size: 13px;">
                        <?= date('l, F d, Y • H:i'); ?>
                    </div>
                </div>
                <div class="greeting-sub">Track permits, monitor approvals, and stay ahead of expiries</div>
                
                <div class="quick-stats">
                    <div class="quick-stat">
                        <div class="quick-stat-label">Created Today</div>
                        <div class="quick-stat-value"><?= $analytics['todayCreated']; ?></div>
                    </div>
                    <div class="quick-stat">
                        <div class="quick-stat-label">This Week</div>
                        <div class="quick-stat-value"><?= $analytics['thisWeekCreated']; ?></div>
                    </div>
                    <div class="quick-stat">
                        <div class="quick-stat-label">This Month</div>
                        <div class="quick-stat-value"><?= $analytics['thisMonthCreated']; ?></div>
                    </div>
                    <div class="quick-stat">
                        <div class="quick-stat-label">Approval Rate</div>
                        <div class="quick-stat-value"><?= $analytics['approvalRate']; ?>%</div>
                    </div>
                </div>
            </div>

            <!-- Main Metrics -->
            <div class="metrics-grid">
                <a href="<?php echo htmlspecialchars($app->url('dashboard.php?status=total')); ?>" class="metric-card" style="border-top: 3px solid #0ea5e9;">
                    <div class="metric-header">
                        <span class="metric-icon">📊</span>
                        <span class="metric-trend">↑ All time</span>
                    </div>
                    <div class="metric-label">Total Permits</div>
                    <div class="metric-value"><?= number_format($metrics['total']); ?></div>
                    <div class="metric-subtitle">System-wide permits</div>
                </a>

                <a href="<?php echo htmlspecialchars($app->url('dashboard.php?status=pending')); ?>" class="metric-card pending" style="border-top: 3px solid #f59e0b;">
                    <div class="metric-header">
                        <span class="metric-icon">⏳</span>
                        <span class="metric-trend">🔴 Pending</span>
                    </div>
                    <div class="metric-label">Awaiting Approval</div>
                    <div class="metric-value"><?= number_format($metrics['pending']); ?></div>
                    <div class="metric-subtitle">Require attention</div>
                </a>

                <a href="<?php echo htmlspecialchars($app->url('dashboard.php?status=active')); ?>" class="metric-card active" style="border-top: 3px solid #10b981;">
                    <div class="metric-header">
                        <span class="metric-icon">✅</span>
                        <span class="metric-trend">✓ Active</span>
                    </div>
                    <div class="metric-label">Active Permits</div>
                    <div class="metric-value"><?= number_format($metrics['active']); ?></div>
                    <div class="metric-subtitle">Currently valid</div>
                </a>

                <a href="<?php echo htmlspecialchars($app->url('dashboard.php?status=expired')); ?>" class="metric-card expired" style="border-top: 3px solid #ef4444;">
                    <div class="metric-header">
                        <span class="metric-icon">❌</span>
                        <span class="metric-trend">⚠️ Expired</span>
                    </div>
                    <div class="metric-label">Expired Permits</div>
                    <div class="metric-value"><?= number_format($metrics['expired']); ?></div>
                    <div class="metric-subtitle"><?= number_format($analytics['expiryRate'], 1); ?>% of total</div>
                </a>
            </div>

            <!-- Analytics Section -->
            <div class="analytics-section">
                <div class="section-title">📈 7-Day Permit Creation Trend</div>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <!-- KPI Section -->
            <div class="analytics-section">
                <div class="section-title">🎯 Key Performance Indicators</div>
                <div class="kpi-badges">
                    <div class="kpi-badge">
                        <div class="kpi-label">Approval Rate</div>
                        <div class="kpi-value"><?= $analytics['approvalRate']; ?>%</div>
                    </div>
                    <div class="kpi-badge">
                        <div class="kpi-label">Expiry Rate</div>
                        <div class="kpi-value"><?= $analytics['expiryRate']; ?>%</div>
                    </div>
                    <div class="kpi-badge">
                        <div class="kpi-label">Active Rate</div>
                        <div class="kpi-value"><?= $metrics['total'] > 0 ? round(($metrics['active'] / $metrics['total']) * 100, 1) : 0; ?>%</div>
                    </div>
                    <div class="kpi-badge">
                        <div class="kpi-label">Pending Rate</div>
                        <div class="kpi-value"><?= $metrics['total'] > 0 ? round(($metrics['pending'] / $metrics['total']) * 100, 1) : 0; ?>%</div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="charts-grid">
                <?php if (!empty($statusDistribution)): ?>
                    <div class="chart-container">
                        <div class="section-title" style="margin-bottom: 16px;">📊 Status Distribution</div>
                        <canvas id="statusChart"></canvas>
                    </div>
                <?php endif; ?>

                <?php if (!empty($recentActivity)): ?>
                    <div class="analytics-section" style="margin-bottom: 0;">
                        <div class="section-title">⚡ Recent Activity</div>
                        <div class="activity-timeline">
                            <?php foreach ($recentActivity as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-time"><?= date('H:i', strtotime($activity['timestamp'])); ?></div>
                                    <div class="activity-content">
                                        <div class="activity-action"><?= htmlspecialchars(ucfirst($activity['action'])); ?></div>
                                        <div class="activity-desc"><?= htmlspecialchars($activity['description'] ?? 'No description'); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Main Permits Table -->
            <section class="surface-card">
                <div class="card-header">
                    <span style="display:flex; align-items:center; gap:8px;">
                        📜
                        <span class="filter-pill"><?php echo htmlspecialchars($statusLabelMap[$statusFilter] ?? 'Permits'); ?></span>
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
                        <a href="<?php echo htmlspecialchars($app->url('create-permit.php')); ?>" class="btn btn-primary" style="margin-top: 12px;">➕ Create New Permit</a>
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

            <script>
                // Trend Chart
                const trendCtx = document.getElementById('trendChart')?.getContext('2d');
                if (trendCtx) {
                    new Chart(trendCtx, {
                        type: 'line',
                        data: {
                            labels: <?= json_encode(array_keys($permitTrends)); ?>,
                            datasets: [{
                                label: 'Permits Created',
                                data: <?= json_encode(array_values($permitTrends)); ?>,
                                borderColor: '#06b6d4',
                                backgroundColor: 'rgba(6, 182, 212, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointRadius: 5,
                                pointBackgroundColor: '#06b6d4',
                                pointBorderColor: '#0a101a',
                                pointBorderWidth: 2,
                                pointHoverRadius: 7,
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: { color: 'rgba(51, 65, 85, 0.3)' },
                                    ticks: { color: '#94a3b8' }
                                },
                                x: {
                                    grid: { color: 'rgba(51, 65, 85, 0.3)' },
                                    ticks: { color: '#94a3b8' }
                                }
                            }
                        }
                    });
                }

                // Status Distribution Chart
                const statusCtx = document.getElementById('statusChart')?.getContext('2d');
                if (statusCtx) {
                    new Chart(statusCtx, {
                        type: 'doughnut',
                        data: {
                            labels: <?= json_encode(array_keys($statusDistribution)); ?>,
                            datasets: [{
                                data: <?= json_encode(array_values($statusDistribution)); ?>,
                                backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#64748b', '#8b5cf6', '#3b82f6'],
                                borderColor: '#0a101a',
                                borderWidth: 2,
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: { color: '#e2e8f0' }
                                }
                            }
                        }
                    });
                }
            </script>
        <?php else: ?>
            <section class="surface-card">
                <div class="card-header">
                    <h3>🔒 Sign in to view your dashboard</h3>
                </div>
                <p>Login to view permits, analytics, and manage your account from the dashboard.</p>
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