<?php
/**
 * System Analysis Report
 * 
 * File Path: /system-tools/system_analysis_report.php
 * Description: Comprehensive system health and performance analysis dashboard
 * Created: 2025-11-04
 * 
 * Features:
 * - System health metrics
 * - Database statistics and performance
 * - Server resource usage
 * - PHP configuration analysis
 * - Application statistics
 * - Modern dark theme UI
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

// Collect system information
function getSystemInfo($db) {
    $info = [];
    
    // PHP Version
    $info['php_version'] = PHP_VERSION;
    $info['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    
    // Memory
    $info['memory_limit'] = ini_get('memory_limit');
    $info['memory_usage'] = round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB';
    $info['memory_peak'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB';
    
    // Disk space
    $info['disk_free'] = round(disk_free_space('/') / 1024 / 1024 / 1024, 2) . ' GB';
    $info['disk_total'] = round(disk_total_space('/') / 1024 / 1024 / 1024, 2) . ' GB';
    
    // PHP Settings
    $info['max_execution_time'] = ini_get('max_execution_time') . 's';
    $info['upload_max_filesize'] = ini_get('upload_max_filesize');
    $info['post_max_size'] = ini_get('post_max_size');
    
    return $info;
}

function getDatabaseStats($db) {
    $stats = [];
    
    try {
        // Get database size
        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'mysql') {
            $stmt = $db->pdo->query("
                SELECT 
                    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['database_size'] = ($result['size_mb'] ?? 0) . ' MB';
            
            // Get table count
            $stmt = $db->pdo->query("
                SELECT COUNT(*) as table_count 
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['table_count'] = $result['table_count'] ?? 0;
        } else {
            $stats['database_size'] = 'N/A (SQLite)';
            $stmt = $db->pdo->query("SELECT COUNT(*) as table_count FROM sqlite_master WHERE type='table'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['table_count'] = $result['table_count'] ?? 0;
        }
        
        // Get record counts for key tables
        $tables = ['users', 'permits', 'activity_log'];
        foreach ($tables as $table) {
            try {
                $stmt = $db->pdo->query("SELECT COUNT(*) as count FROM $table");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $stats[$table . '_count'] = $result['count'] ?? 0;
            } catch (Exception $e) {
                $stats[$table . '_count'] = 'N/A';
            }
        }
    } catch (Exception $e) {
        $stats['error'] = $e->getMessage();
    }
    
    return $stats;
}

function getApplicationStats($db) {
    $stats = [];
    
    try {
        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        // Recent activity (last 24 hours)
        if ($driver === 'mysql') {
            $stmt = $db->pdo->query("
                SELECT COUNT(*) as count 
                FROM activity_log 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 DAY)
            ");
        } else {
            $stmt = $db->pdo->query("
                SELECT COUNT(*) as count 
                FROM activity_log 
                WHERE timestamp > datetime('now', '-1 day')
            ");
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['activity_24h'] = $result['count'] ?? 0;
        
        // Active users (last 7 days)
        if ($driver === 'mysql') {
            $stmt = $db->pdo->query("
                SELECT COUNT(DISTINCT user_id) as count 
                FROM activity_log 
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
        } else {
            $stmt = $db->pdo->query("
                SELECT COUNT(DISTINCT user_id) as count 
                FROM activity_log 
                WHERE timestamp > datetime('now', '-7 days')
            ");
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['active_users_7d'] = $result['count'] ?? 0;
        
        // Permit statistics
        $stmt = $db->pdo->query("
            SELECT 
                status,
                COUNT(*) as count 
            FROM permits 
            GROUP BY status
        ");
        $permitStats = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $permitStats[$row['status']] = $row['count'];
        }
        $stats['permit_stats'] = $permitStats;
        
    } catch (Exception $e) {
        $stats['error'] = $e->getMessage();
    }
    
    return $stats;
}

// Gather all data
$systemInfo = getSystemInfo($db);
$dbStats = getDatabaseStats($db);
$appStats = getApplicationStats($db);

// Calculate health score
$healthScore = 100;
$healthIssues = [];

// Check memory usage
$memoryUsed = (float)str_replace(' MB', '', $systemInfo['memory_usage']);
$memoryLimit = (int)str_replace('M', '', $systemInfo['memory_limit']);
if ($memoryUsed > $memoryLimit * 0.8) {
    $healthScore -= 20;
    $healthIssues[] = 'High memory usage detected';
}

// Check disk space
$diskFree = (float)str_replace(' GB', '', $systemInfo['disk_free']);
if ($diskFree < 5) {
    $healthScore -= 30;
    $healthIssues[] = 'Low disk space available';
}

$healthStatus = $healthScore >= 80 ? 'good' : ($healthScore >= 60 ? 'warning' : 'critical');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Analysis Report - System Tools</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Ubuntu, Arial, sans-serif;
            background: #0a0f1a;
            color: #f9fafb;
            min-height: 100vh;
            padding: 24px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            padding-bottom: 20px;
            border-bottom: 1px solid #1f2937;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 600;
            color: #f9fafb;
        }

        .header-subtitle {
            font-size: 13px;
            color: #9ca3af;
            margin-top: 4px;
        }

        .btn {
            padding: 10px 16px;
            border: 1px solid #1f2937;
            border-radius: 8px;
            background: #111827;
            color: #f9fafb;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        }

        .btn:hover {
            background: #1f2937;
            border-color: #374151;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
            transform: translateY(-1px);
        }

        .btn-accent {
            background: #3b82f6;
            border-color: #3b82f6;
            color: #ffffff;
        }

        .btn-accent:hover {
            background: #2563eb;
            border-color: #2563eb;
        }

        /* Health Score Card */
        .health-card {
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
        }

        .health-score {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 16px;
        }

        .health-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            border: 4px solid;
        }

        .health-circle.good {
            background: rgba(16, 185, 129, 0.1);
            border-color: #10b981;
            color: #10b981;
        }

        .health-circle.warning {
            background: rgba(245, 158, 11, 0.1);
            border-color: #f59e0b;
            color: #f59e0b;
        }

        .health-circle.critical {
            background: rgba(239, 68, 68, 0.1);
            border-color: #ef4444;
            color: #ef4444;
        }

        .health-info h2 {
            font-size: 20px;
            margin-bottom: 8px;
            color: #f9fafb;
        }

        .health-status {
            font-size: 14px;
            color: #9ca3af;
        }

        .health-issues {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #1f2937;
        }

        .issue-item {
            padding: 8px 12px;
            background: rgba(239, 68, 68, 0.1);
            border-left: 3px solid #ef4444;
            border-radius: 4px;
            margin-bottom: 8px;
            font-size: 14px;
        }

        /* Grid Layout */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        /* Card */
        .card {
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
            transition: box-shadow 0.2s ease;
        }

        .card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
        }

        .card h2 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #f9fafb;
            padding-bottom: 12px;
            border-bottom: 1px solid #1f2937;
        }

        /* Stat Item */
        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #1f2937;
        }

        .stat-item:last-child {
            border-bottom: none;
        }

        .stat-label {
            font-size: 13px;
            color: #9ca3af;
            font-weight: 500;
        }

        .stat-value {
            font-size: 15px;
            color: #f9fafb;
            font-weight: 600;
        }

        .stat-value.good {
            color: #10b981;
        }

        .stat-value.warning {
            color: #f59e0b;
        }

        .stat-value.critical {
            color: #ef4444;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.active {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }

        .status-badge.pending {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
        }

        .status-badge.issued {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
            
            body {
                padding: 16px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
            
            .health-score {
                flex-direction: column;
                text-align: center;
            }
        }

        /* Print Styles */
        @media print {
            body {
                background: #fff;
                color: #111;
            }
            
            .btn {
                display: none;
            }
            
            .card, .health-card {
                background: #fff;
                border-color: #999;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div>
                <h1>System Analysis Report</h1>
                <div class="header-subtitle">Comprehensive system health and performance metrics</div>
            </div>
            <div>
                <a href="/admin.php" class="btn">← Back to Admin</a>
                <button class="btn btn-accent" onclick="window.print()">Print Report</button>
            </div>
        </div>

        <!-- Health Score -->
        <div class="health-card">
            <div class="health-score">
                <div class="health-circle <?php echo $healthStatus; ?>">
                    <?php echo $healthScore; ?>%
                </div>
                <div class="health-info">
                    <h2>System Health Score</h2>
                    <div class="health-status">
                        Status: <strong><?php echo ucfirst($healthStatus); ?></strong>
                        | Last checked: <?php echo date('Y-m-d H:i:s'); ?>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($healthIssues)): ?>
            <div class="health-issues">
                <strong style="color: #ef4444; font-size: 14px; margin-bottom: 8px; display: block;">Issues Detected:</strong>
                <?php foreach ($healthIssues as $issue): ?>
                <div class="issue-item"><?php echo htmlspecialchars($issue); ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- System Information Grid -->
        <div class="grid">
            <!-- PHP & Server Info -->
            <div class="card">
                <h2>Server Information</h2>
                <div class="stat-item">
                    <span class="stat-label">PHP Version</span>
                    <span class="stat-value"><?php echo htmlspecialchars($systemInfo['php_version']); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Server Software</span>
                    <span class="stat-value"><?php echo htmlspecialchars($systemInfo['server_software']); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Memory Limit</span>
                    <span class="stat-value"><?php echo htmlspecialchars($systemInfo['memory_limit']); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Max Execution Time</span>
                    <span class="stat-value"><?php echo htmlspecialchars($systemInfo['max_execution_time']); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Upload Max Size</span>
                    <span class="stat-value"><?php echo htmlspecialchars($systemInfo['upload_max_filesize']); ?></span>
                </div>
            </div>

            <!-- Memory Usage -->
            <div class="card">
                <h2>Memory Usage</h2>
                <div class="stat-item">
                    <span class="stat-label">Current Usage</span>
                    <span class="stat-value"><?php echo htmlspecialchars($systemInfo['memory_usage']); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Peak Usage</span>
                    <span class="stat-value"><?php echo htmlspecialchars($systemInfo['memory_peak']); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Memory Limit</span>
                    <span class="stat-value"><?php echo htmlspecialchars($systemInfo['memory_limit']); ?></span>
                </div>
            </div>

            <!-- Disk Space -->
            <div class="card">
                <h2>Disk Space</h2>
                <div class="stat-item">
                    <span class="stat-label">Free Space</span>
                    <span class="stat-value <?php echo $diskFree < 5 ? 'critical' : 'good'; ?>">
                        <?php echo htmlspecialchars($systemInfo['disk_free']); ?>
                    </span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Total Space</span>
                    <span class="stat-value"><?php echo htmlspecialchars($systemInfo['disk_total']); ?></span>
                </div>
            </div>
        </div>

        <!-- Database Statistics -->
        <div class="grid">
            <div class="card">
                <h2>Database Statistics</h2>
                <div class="stat-item">
                    <span class="stat-label">Database Size</span>
                    <span class="stat-value"><?php echo htmlspecialchars($dbStats['database_size']); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Total Tables</span>
                    <span class="stat-value"><?php echo htmlspecialchars($dbStats['table_count']); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Users</span>
                    <span class="stat-value"><?php echo htmlspecialchars($dbStats['users_count']); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Permits</span>
                    <span class="stat-value"><?php echo htmlspecialchars($dbStats['permits_count']); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Activity Logs</span>
                    <span class="stat-value"><?php echo htmlspecialchars($dbStats['activity_log_count']); ?></span>
                </div>
            </div>

            <!-- Application Statistics -->
            <div class="card">
                <h2>Application Statistics</h2>
                <div class="stat-item">
                    <span class="stat-label">Activity (24h)</span>
                    <span class="stat-value"><?php echo htmlspecialchars($appStats['activity_24h']); ?> events</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Active Users (7d)</span>
                    <span class="stat-value"><?php echo htmlspecialchars($appStats['active_users_7d']); ?> users</span>
                </div>
            </div>

            <!-- Permit Status Breakdown -->
            <div class="card">
                <h2>Permit Status Breakdown</h2>
                <?php if (!empty($appStats['permit_stats'])): ?>
                    <?php foreach ($appStats['permit_stats'] as $status => $count): ?>
                    <div class="stat-item">
                        <span class="stat-label">
                            <span class="status-badge <?php echo strtolower($status); ?>">
                                <?php echo htmlspecialchars(strtoupper($status)); ?>
                            </span>
                        </span>
                        <span class="stat-value"><?php echo htmlspecialchars($count); ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="stat-item">
                        <span class="stat-label">No permit data available</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
