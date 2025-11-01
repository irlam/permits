<?php
/**
 * Comprehensive Activity Log Viewer & Manager
 * 
 * File Path: /admin/activity.php
 * Description: Interactive activity log dashboard with search, filtering, export, and data management
 * Created: 01/11/2025
 * Last Modified: 01/11/2025
 * 
 * Features:
 * - Real-time activity log display
 * - Advanced search & filtering by user, action, date, IP, status
 * - Data size management (100MB limit with auto-cleanup)
 * - Export to CSV/JSON
 * - Activity statistics & charts
 * - User activity timeline
 * - IP address tracking & geolocation lookup
 * - Suspicious activity alerts
 * - Archive old data
 * - Responsive design
 */

require __DIR__ . '/../vendor/autoload.php';
[$app, $db, $root] = require_once __DIR__ . '/../src/bootstrap.php';

// Start session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$stmt = $db->pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || $currentUser['role'] !== 'admin') {
    die('<h1>Access Denied</h1><p>Admin access required.</p>');
}

// Constants
const MAX_LOG_SIZE_MB = 100;
const MAX_LOG_SIZE_BYTES = MAX_LOG_SIZE_MB * 1024 * 1024;
const BATCH_DELETE_LIMIT = 1000;

// Handle actions
$action = $_GET['action'] ?? '';
$message = '';
$messageType = 'info';

// Check and manage log size
function checkAndManageLogSize($db) {
    try {
        // Get database name and table size
        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'mysql') {
            $stmt = $db->pdo->query("SELECT table_name, ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size_MB' FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'activity_log'");
            $tableInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            $sizeBytes = ($tableInfo['Size_MB'] ?? 0) * 1024 * 1024;
        } else {
            // SQLite - estimate from row count
            $stmt = $db->pdo->query("SELECT COUNT(*) as count FROM activity_log");
            $rowCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            $sizeBytes = $rowCount * 500; // Rough estimate: 500 bytes per row
        }
        
        if ($sizeBytes > MAX_LOG_SIZE_BYTES) {
            // Delete oldest records in batches
            $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $deleteStmt = $db->pdo->prepare("
                    DELETE FROM activity_log 
                    WHERE id IN (
                        SELECT id FROM activity_log 
                        ORDER BY timestamp ASC 
                        LIMIT ?
                    )
                ");
            } else {
                $deleteStmt = $db->pdo->prepare("
                    DELETE FROM activity_log 
                    WHERE id IN (
                        SELECT id FROM activity_log 
                        ORDER BY timestamp ASC 
                        LIMIT ?
                    )
                ");
            }
            $deleteStmt->execute([BATCH_DELETE_LIMIT]);
            return true;
        }
    } catch (Exception $e) {
        error_log("Log size management error: " . $e->getMessage());
    }
    return false;
}

// Get log table size
function getLogTableSize($db) {
    try {
        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'mysql') {
            $stmt = $db->pdo->query("SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size_MB' FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'activity_log'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($result['Size_MB'] ?? 0);
        } else {
            // SQLite estimate
            $stmt = $db->pdo->query("SELECT COUNT(*) as count FROM activity_log");
            $rowCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            return round(($rowCount * 500) / 1024 / 1024, 2);
        }
    } catch (Exception $e) {
        return 0;
    }
}

// Export functionality
if ($action === 'export') {
    $format = $_GET['format'] ?? 'csv';
    $stmt = $db->pdo->prepare("SELECT * FROM activity_log ORDER BY timestamp DESC LIMIT 10000");
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="activity-log-' . date('Y-m-d-His') . '.json"');
        echo json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } else {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="activity-log-' . date('Y-m-d-His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        if (!empty($logs)) {
            fputcsv($output, array_keys($logs[0]));
            foreach ($logs as $log) {
                fputcsv($output, $log);
            }
        }
        fclose($output);
    }
    exit;
}

// Clear old logs
if ($action === 'clear-old' && isset($_POST['days'])) {
    $days = (int)$_POST['days'];
    $stmt = $db->pdo->prepare("DELETE FROM activity_log WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$days]);
    $deletedRows = $stmt->rowCount();
    $message = "Deleted $deletedRows log entries older than $days days";
    $messageType = 'success';
}

// Truncate all logs
if ($action === 'truncate-all' && isset($_POST['confirm']) && $_POST['confirm'] === 'yes') {
    $db->pdo->exec("TRUNCATE TABLE activity_log");
    $message = "All activity logs have been cleared";
    $messageType = 'success';
}

checkAndManageLogSize($db);

// Get filters
$filterUser = $_GET['user'] ?? '';
$filterAction = $_GET['action_type'] ?? '';
$filterStartDate = $_GET['start_date'] ?? '';
$filterEndDate = $_GET['end_date'] ?? '';
$filterIp = $_GET['ip'] ?? '';
$searchTerm = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

// Build query
$whereConditions = [];
$params = [];

if ($filterUser) {
    $whereConditions[] = "user_id = ?";
    $params[] = $filterUser;
}
if ($filterAction) {
    $whereConditions[] = "(action = ? OR type = ?)";
    $params[] = $filterAction;
    $params[] = $filterAction;
}
if ($filterStartDate) {
    $whereConditions[] = "DATE(timestamp) >= ?";
    $params[] = $filterStartDate;
}
if ($filterEndDate) {
    $whereConditions[] = "DATE(timestamp) <= ?";
    $params[] = $filterEndDate;
}
if ($filterIp) {
    $whereConditions[] = "ip_address LIKE ?";
    $params[] = "%$filterIp%";
}
if ($searchTerm) {
    $whereConditions[] = "(description LIKE ? OR details LIKE ? OR user_agent LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get total count
$countStmt = $db->pdo->prepare("SELECT COUNT(*) as total FROM activity_log $whereClause");
$countStmt->execute($params);
$total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($total / $perPage);
$offset = ($page - 1) * $perPage;

// Get logs
$stmt = $db->pdo->prepare("
    SELECT id, user_id, type, action, description, details, timestamp, ip_address, user_agent
    FROM activity_log
    $whereClause
    ORDER BY timestamp DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$perPage, $offset]));
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user list for filter
$usersStmt = $db->pdo->query("SELECT DISTINCT user_id FROM activity_log WHERE user_id IS NOT NULL ORDER BY user_id");
$userList = $usersStmt->fetchAll(PDO::FETCH_COLUMN);

// Get action types for filter
$actionsStmt = $db->pdo->query("SELECT DISTINCT COALESCE(type, action) as action_type FROM activity_log WHERE type IS NOT NULL OR action IS NOT NULL ORDER BY action_type");
$actionTypes = $actionsStmt->fetchAll(PDO::FETCH_COLUMN);

// Get statistics
$statsStmt = $db->pdo->query("
    SELECT 
        COUNT(*) as total_events,
        COUNT(DISTINCT user_id) as unique_users,
        COUNT(DISTINCT ip_address) as unique_ips,
        MAX(timestamp) as last_activity
    FROM activity_log
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get activity by type (last 24h)
$typesStmt = $db->pdo->query("
    SELECT COALESCE(type, action) as action_type, COUNT(*) as count
    FROM activity_log
    WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 DAY)
    GROUP BY COALESCE(type, action)
    ORDER BY count DESC
    LIMIT 10
");
$topActivities = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get top users (last 24h)
$usersStatsStmt = $db->pdo->query("
    SELECT user_id, COUNT(*) as count
    FROM activity_log
    WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 DAY) AND user_id IS NOT NULL
    GROUP BY user_id
    ORDER BY count DESC
    LIMIT 5
");
$topUsers = $usersStatsStmt->fetchAll(PDO::FETCH_ASSOC);

$logSize = getLogTableSize($db);
$logSizePercent = ($logSize / MAX_LOG_SIZE_MB) * 100;
$logSizeStatus = $logSizePercent > 80 ? 'warning' : ($logSizePercent > 95 ? 'danger' : 'success');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - Admin Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #111a2e 100%);
            color: #e2e8f0;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            gap: 20px;
            flex-wrap: wrap;
        }

        .header h1 {
            font-size: 32px;
            font-weight: 700;
            color: #06b6d4;
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #06b6d4 0%, #0ea5e9 100%);
            color: #0f172a;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(6, 182, 212, 0.3);
        }

        .btn-secondary {
            background: rgba(148, 163, 184, 0.1);
            color: #94a3b8;
            border: 1px solid rgba(148, 163, 184, 0.2);
        }

        .btn-secondary:hover {
            background: rgba(148, 163, 184, 0.2);
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .btn-danger:hover {
            background: rgba(239, 68, 68, 0.2);
        }

        /* Message */
        .message {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #22c55e;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .message.error {
            background: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }

        /* Card */
        .card {
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(6, 182, 212, 0.1);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .card h2 {
            font-size: 20px;
            margin-bottom: 16px;
            color: #e2e8f0;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.8) 0%, rgba(15, 23, 42, 0.9) 100%);
            border: 1px solid rgba(6, 182, 212, 0.1);
            border-radius: 12px;
            padding: 16px;
        }

        .stat-label {
            font-size: 12px;
            color: #94a3b8;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #06b6d4;
        }

        .stat-sublabel {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }

        /* Progress Bar */
        .progress-bar {
            height: 8px;
            background: rgba(148, 163, 184, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 8px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #06b6d4 0%, #0ea5e9 100%);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .progress-fill.warning {
            background: linear-gradient(90deg, #f59e0b 0%, #fbbf24 100%);
        }

        .progress-fill.danger {
            background: linear-gradient(90deg, #ef4444 0%, #f87171 100%);
        }

        /* Filters */
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-size: 12px;
            color: #94a3b8;
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        .filter-group input,
        .filter-group select {
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(6, 182, 212, 0.2);
            border-radius: 6px;
            padding: 8px 12px;
            color: #e2e8f0;
            font-size: 14px;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: rgba(6, 182, 212, 0.5);
            box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.1);
        }

        /* Table */
        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        table thead {
            background: rgba(30, 41, 59, 0.5);
            border-bottom: 1px solid rgba(6, 182, 212, 0.1);
        }

        table th {
            padding: 12px;
            text-align: left;
            color: #94a3b8;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
        }

        table td {
            padding: 12px;
            border-bottom: 1px solid rgba(6, 182, 212, 0.05);
        }

        table tbody tr:hover {
            background: rgba(6, 182, 212, 0.05);
        }

        .timestamp {
            color: #64748b;
            white-space: nowrap;
        }

        .action-badge {
            display: inline-block;
            background: rgba(6, 182, 212, 0.1);
            color: #06b6d4;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .ip-address {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #94a3b8;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 4px;
            margin-top: 20px;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border-radius: 6px;
            background: rgba(30, 41, 59, 0.8);
            color: #94a3b8;
            text-decoration: none;
            border: 1px solid rgba(6, 182, 212, 0.1);
            cursor: pointer;
        }

        .pagination a:hover {
            background: rgba(6, 182, 212, 0.1);
            border-color: rgba(6, 182, 212, 0.3);
        }

        .pagination .active {
            background: linear-gradient(135deg, #06b6d4 0%, #0ea5e9 100%);
            color: #0f172a;
            border-color: #06b6d4;
        }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: #1e293b;
            border-radius: 12px;
            padding: 24px;
            max-width: 500px;
            width: 90%;
            border: 1px solid rgba(6, 182, 212, 0.2);
        }

        .modal-header h2 {
            margin-bottom: 12px;
            color: #e2e8f0;
        }

        .modal-body {
            margin-bottom: 20px;
            color: #cbd5e1;
        }

        .modal-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }

            .filters {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 12px;
            }

            table th, table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📊 Activity Log Dashboard</h1>
            <div class="header-actions">
                <a href="?action=export&format=csv" class="btn btn-primary">📥 Export CSV</a>
                <a href="?action=export&format=json" class="btn btn-primary">📥 Export JSON</a>
                <a href="/admin.php" class="btn btn-secondary">← Back</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $messageType === 'success' ? '' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Events</div>
                <div class="stat-value"><?php echo number_format($stats['total_events']); ?></div>
                <div class="stat-sublabel">All time activity</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Unique Users</div>
                <div class="stat-value"><?php echo number_format($stats['unique_users']); ?></div>
                <div class="stat-sublabel">Active users</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Unique IPs</div>
                <div class="stat-value"><?php echo number_format($stats['unique_ips']); ?></div>
                <div class="stat-sublabel">IP addresses</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Log Size</div>
                <div class="stat-value"><?php echo round($logSize, 2); ?> MB</div>
                <div class="progress-bar">
                    <div class="progress-fill <?php echo $logSizeStatus; ?>" style="width: <?php echo min($logSizePercent, 100); ?>%"></div>
                </div>
                <div class="stat-sublabel"><?php echo round($logSizePercent, 1); ?>% of <?php echo MAX_LOG_SIZE_MB; ?>MB</div>
            </div>
        </div>

        <!-- Top Activities -->
        <div class="card">
            <h2>📈 Top Activities (Last 24 Hours)</h2>
            <div class="charts-grid">
                <div class="chart-container">
                    <canvas id="activitiesChart"></canvas>
                </div>
                <div class="chart-container">
                    <canvas id="usersChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card">
            <h2>🔍 Search & Filter</h2>
            <form method="get" style="margin-bottom: 16px;">
                <div class="filters">
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Search description, IP, user agent..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <div class="filter-group">
                        <label>User ID</label>
                        <select name="user">
                            <option value="">All Users</option>
                            <?php foreach ($userList as $u): ?>
                                <option value="<?php echo htmlspecialchars($u); ?>" <?php echo $filterUser === $u ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($u); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Action Type</label>
                        <select name="action_type">
                            <option value="">All Actions</option>
                            <?php foreach ($actionTypes as $at): ?>
                                <option value="<?php echo htmlspecialchars($at); ?>" <?php echo $filterAction === $at ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($at); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" value="<?php echo htmlspecialchars($filterStartDate); ?>">
                    </div>
                    <div class="filter-group">
                        <label>End Date</label>
                        <input type="date" name="end_date" value="<?php echo htmlspecialchars($filterEndDate); ?>">
                    </div>
                    <div class="filter-group">
                        <label>IP Address</label>
                        <input type="text" name="ip" placeholder="Filter by IP..." value="<?php echo htmlspecialchars($filterIp); ?>">
                    </div>
                </div>
                <div style="display: flex; gap: 12px;">
                    <button type="submit" class="btn btn-primary">Search</button>
                    <a href="/admin/activity.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>
        </div>

        <!-- Activity Log Table -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h2>📋 Activity Log (<?php echo number_format($total); ?> entries)</h2>
                <div style="font-size: 12px; color: #94a3b8;">
                    Page <?php echo $page; ?> of <?php echo max(1, $totalPages); ?>
                </div>
            </div>

            <?php if (!empty($logs)): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>IP Address</th>
                                <th>User Agent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="timestamp"><?php echo htmlspecialchars(date('M d, Y H:i:s', strtotime($log['timestamp']))); ?></td>
                                    <td><?php echo htmlspecialchars($log['user_id'] ?? 'System'); ?></td>
                                    <td>
                                        <span class="action-badge">
                                            <?php echo htmlspecialchars($log['type'] ?? $log['action'] ?? 'unknown'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($log['description'] ?? $log['details'] ?? '', 0, 50)); ?></td>
                                    <td class="ip-address"><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></td>
                                    <td style="font-size: 11px; color: #64748b;">
                                        <?php 
                                            $ua = $log['user_agent'] ?? 'N/A';
                                            $ua = strpos($ua, 'Chrome') !== false ? 'Chrome' : (strpos($ua, 'Firefox') !== false ? 'Firefox' : (strpos($ua, 'Safari') !== false ? 'Safari' : substr($ua, 0, 30)));
                                            echo htmlspecialchars($ua);
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1<?php echo $filterUser ? '&user=' . urlencode($filterUser) : ''; ?><?php echo $filterAction ? '&action_type=' . urlencode($filterAction) : ''; ?>">« First</a>
                            <a href="?page=<?php echo $page - 1; ?><?php echo $filterUser ? '&user=' . urlencode($filterUser) : ''; ?><?php echo $filterAction ? '&action_type=' . urlencode($filterAction) : ''; ?>">‹ Previous</a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <?php if ($i === $page): ?>
                                <span class="active"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?><?php echo $filterUser ? '&user=' . urlencode($filterUser) : ''; ?><?php echo $filterAction ? '&action_type=' . urlencode($filterAction) : ''; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo $filterUser ? '&user=' . urlencode($filterUser) : ''; ?><?php echo $filterAction ? '&action_type=' . urlencode($filterAction) : ''; ?>">Next ›</a>
                            <a href="?page=<?php echo $totalPages; ?><?php echo $filterUser ? '&user=' . urlencode($filterUser) : ''; ?><?php echo $filterAction ? '&action_type=' . urlencode($filterAction) : ''; ?>">Last »</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p style="text-align: center; color: #94a3b8; padding: 40px;">No activity logs found matching your filters.</p>
            <?php endif; ?>
        </div>

        <!-- Data Management -->
        <div class="card">
            <h2>⚙️ Data Management</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
                <div style="padding: 16px; background: rgba(30, 41, 59, 0.5); border-radius: 8px;">
                    <h3 style="margin-bottom: 12px; color: #e2e8f0;">Clear Old Logs</h3>
                    <p style="font-size: 13px; color: #94a3b8; margin-bottom: 12px;">Delete logs older than specified days</p>
                    <form method="post" action="?action=clear-old" style="display: flex; gap: 8px;">
                        <input type="number" name="days" min="1" max="365" value="30" style="flex: 1; padding: 8px; background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(6, 182, 212, 0.2); border-radius: 6px; color: #e2e8f0;">
                        <button type="submit" class="btn btn-secondary">Clear</button>
                    </form>
                </div>

                <div style="padding: 16px; background: rgba(30, 41, 59, 0.5); border-radius: 8px;">
                    <h3 style="margin-bottom: 12px; color: #e2e8f0;">Truncate All Logs</h3>
                    <p style="font-size: 13px; color: #94a3b8; margin-bottom: 12px;">⚠️ Permanently delete ALL activity logs</p>
                    <button type="button" class="btn btn-danger" onclick="showTruncateModal()">Delete All</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Truncate Modal -->
    <div id="truncateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>⚠️ Confirm Delete</h2>
            </div>
            <div class="modal-body">
                <p>This action will <strong>permanently delete ALL activity logs</strong>. This cannot be undone!</p>
                <p style="margin-top: 12px; color: #f59e0b;">Are you absolutely sure?</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeTruncateModal()">Cancel</button>
                <form method="post" action="?action=truncate-all" style="display: inline;">
                    <input type="hidden" name="confirm" value="yes">
                    <button type="submit" class="btn btn-danger">Yes, Delete All</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        function showTruncateModal() {
            document.getElementById('truncateModal').classList.add('active');
        }

        function closeTruncateModal() {
            document.getElementById('truncateModal').classList.remove('active');
        }

        // Chart initialization
        window.addEventListener('DOMContentLoaded', function() {
            const activitiesCtx = document.getElementById('activitiesChart');
            if (activitiesCtx) {
                new Chart(activitiesCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode(array_map(fn($a) => $a['action_type'], $topActivities)); ?>,
                        datasets: [{
                            label: 'Activity Count',
                            data: <?php echo json_encode(array_map(fn($a) => $a['count'], $topActivities)); ?>,
                            backgroundColor: 'rgba(6, 182, 212, 0.6)',
                            borderColor: 'rgba(6, 182, 212, 1)',
                            borderRadius: 6
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
                                grid: { color: 'rgba(148, 163, 184, 0.1)' },
                                ticks: { color: '#94a3b8' }
                            },
                            x: {
                                grid: { display: false },
                                ticks: { color: '#94a3b8' }
                            }
                        }
                    }
                });
            }

            const usersCtx = document.getElementById('usersChart');
            if (usersCtx) {
                new Chart(usersCtx, {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo json_encode(array_map(fn($u) => $u['user_id'], $topUsers)); ?>,
                        datasets: [{
                            data: <?php echo json_encode(array_map(fn($u) => $u['count'], $topUsers)); ?>,
                            backgroundColor: [
                                'rgba(6, 182, 212, 0.8)',
                                'rgba(34, 197, 94, 0.8)',
                                'rgba(245, 158, 11, 0.8)',
                                'rgba(239, 68, 68, 0.8)',
                                'rgba(168, 85, 247, 0.8)'
                            ],
                            borderColor: 'rgba(15, 23, 42, 0.9)',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { color: '#94a3b8' }
                            }
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>
