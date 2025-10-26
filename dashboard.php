<?php
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
 * - Quick permit creation
 * - Recent permits display
 * - Template list
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

// Get templates
try {
    $templates = $db->pdo->query("
        SELECT * FROM form_templates 
        WHERE active = 1 
        ORDER BY name ASC
    ")->fetchAll();
} catch (PDOException $e) {
    try {
        $templates = $db->pdo->query("
            SELECT * FROM form_templates 
            ORDER BY name ASC
        ")->fetchAll();
    } catch (Exception $e2) {
        $templates = [];
    }
}

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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .header h1 {
            font-size: 28px;
            color: #111827;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }
        
        .btn-secondary:hover {
            background: #f9fafb;
        }
        
        /* Metrics Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .metric-card {
            display: block;
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s, box-shadow 0.2s, outline 0.2s;
            color: inherit;
            text-decoration: none;
        }
        
        .metric-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }

        .metric-card.is-active {
            outline: 3px solid rgba(102, 126, 234, 0.35);
            box-shadow: 0 12px 28px rgba(102, 126, 234, 0.25);
        }

        .metric-card.is-active .value {
            color: #4338ca;
        }
        
        .metric-card .icon {
            font-size: 32px;
            margin-bottom: 12px;
        }
        
        .metric-card .label {
            font-size: 14px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .metric-card .value {
            font-size: 36px;
            font-weight: bold;
            color: #111827;
        }
        
        .metric-total { border-left: 4px solid #3b82f6; }
        .metric-pending { border-left: 4px solid #f59e0b; }
        .metric-active { border-left: 4px solid #10b981; }
        .metric-expired { border-left: 4px solid #ef4444; }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: space-between;
        }
        
        /* Templates Grid */
        .templates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        
        .template-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            padding: 24px;
            color: white;
            text-decoration: none;
            display: block;
            transition: all 0.2s;
            text-align: center;
        }
        
        .template-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(102, 126, 234, 0.4);
        }
        
        .template-card .icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        .template-card .name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .template-card .version {
            font-size: 12px;
            opacity: 0.9;
        }
        
        /* Permits Table */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #f9fafb;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #6b7280;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 16px 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-gray { background: #f3f4f6; color: #374151; }
        
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: #6b7280;
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }

        .filter-count {
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
            margin-left: 8px;
        }

        .filter-pill {
            background: rgba(99, 102, 241, 0.12);
            color: #4338ca;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .metrics-grid {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div>
                <h1>🛡️ Permit System</h1>
                <p style="color: #6b7280; margin-top: 4px;">Create permits easily, check status anytime</p>
            </div>
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
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
                        <a href="/admin.php" class="btn btn-secondary">⚙️ Admin</a>
                    <?php endif; ?>
                    <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'manager'): ?>
                        <a href="/manager-approvals.php" class="btn btn-secondary">✅ Approvals</a>
                    <?php endif; ?>
                    <a href="/logout.php" class="btn btn-secondary">🚪 Logout</a>
                <?php else: ?>
                    <a href="/login.php" class="btn btn-primary">🔐 Login</a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Metrics -->
        <div class="metrics-grid">
            <a class="metric-card metric-total<?php echo $statusFilter === 'total' ? ' is-active' : ''; ?>" href="/dashboard.php?status=total">
                <div class="icon">📊</div>
                <div class="label">Total Permits</div>
                <div class="value"><?php echo number_format($metrics['total']); ?></div>
            </a>
            
            <a class="metric-card metric-pending<?php echo $statusFilter === 'pending' ? ' is-active' : ''; ?>" href="/dashboard.php?status=pending">
                <div class="icon">⏳</div>
                <div class="label">Pending</div>
                <div class="value"><?php echo number_format($metrics['pending']); ?></div>
            </a>
            
            <a class="metric-card metric-active<?php echo $statusFilter === 'active' ? ' is-active' : ''; ?>" href="/dashboard.php?status=active">
                <div class="icon">✅</div>
                <div class="label">Active</div>
                <div class="value"><?php echo number_format($metrics['active']); ?></div>
            </a>
            
            <a class="metric-card metric-expired<?php echo $statusFilter === 'expired' ? ' is-active' : ''; ?>" href="/dashboard.php?status=expired">
                <div class="icon">❌</div>
                <div class="label">Expired</div>
                <div class="value"><?php echo number_format($metrics['expired']); ?></div>
            </a>
        </div>
        
        <!-- Create New Permit -->
        <div class="card">
            <div class="card-title">📋 Create New Permit</div>
            <p style="color: #6b7280; margin-bottom: 20px;">Select a permit type to get started. No login required!</p>
            
            <?php if (empty($templates)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <p>No permit templates available yet</p>
                </div>
            <?php else: ?>
                <div class="templates-grid">
                    <?php 
                    $icons = [
                        'Hot Works Permit' => '🔥',
                        'Permit to Dig' => '⛏️',
                        'Work at Height Permit' => '🪜',
                        'Confined Space Permit' => '🚪',
                        'Electrical Work Permit' => '⚡'
                    ];
                    
                    foreach ($templates as $template): 
                        $icon = $icons[$template['name']] ?? '📋';
                    ?>
                        <a href="/create-permit-public.php?template=<?php echo $template['id']; ?>" class="template-card">
                            <div class="icon"><?php echo $icon; ?></div>
                            <div class="name"><?php echo htmlspecialchars($template['name']); ?></div>
                            <div class="version">Version <?php echo $template['version'] ?? 1; ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Permits Listing -->
        <?php if ($isLoggedIn): ?>
            <div class="card">
                <div class="card-title">
                    <span style="display:flex; align-items:center; gap:8px;">
                        📜
                        <span class="filter-pill"><?php echo htmlspecialchars($permitsListTitle); ?></span>
                        <span class="filter-count">(<?php echo count($permitsList); ?>)</span>
                    </span>
                    <?php if ($filterActive): ?>
                        <a href="/dashboard.php" class="btn btn-secondary" style="margin-left:auto; padding: 8px 14px; font-size: 12px;">
                            ✖ Clear Filter
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($permitsList)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">�️</div>
                        <p><?php echo $filterActive ? 'No permits match this status yet.' : 'No recent permits to display just yet.'; ?></p>
                    </div>
                <?php else: ?>
                    <table>
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
                                            <a href="/view-permit-public.php?link=<?php echo urlencode($permit['unique_link']); ?>"
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
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-title">🔒 Sign in to view permits</div>
                <p style="color:#6b7280; margin-bottom:16px;">Login to filter, browse, and manage permits from the dashboard.</p>
                <a href="/login.php" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
                    🔐 Go to Login
                </a>
            </div>
        <?php endif; ?>
        
        <div style="text-align: center; color: white; margin-top: 32px; opacity: 0.8;">
            <p>© 2025 Permit System • Secure & Efficient Permit Management</p>
        </div>
    </div>
</body>
</html>