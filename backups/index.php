<?php
/**
 * Backups Directory Browser
 * 
 * File Path: /backups/index.php
 * Description: Lists and manages database backups with session-based authentication
 * Created: 04/11/2025
 * 
 * Features:
 * - Session-based authentication (no secondary login)
 * - List all SQL backup files
 * - Download backup files
 * - Consistent theming with other Monitoring & Maintenance pages
 */

require __DIR__ . '/../vendor/autoload.php';
[$app, $db, $root] = require_once __DIR__ . '/../src/bootstrap.php';

use Permits\SystemSettings;

// Start session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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
    http_response_code(403);
    echo '<h1>Access Denied</h1><p>Admin access required. <a href="/dashboard.php">Back to Dashboard</a></p>';
    exit;
}

// Handle download requests
$download = $_GET['download'] ?? null;
if ($download) {
    $safeName = basename($download);
    $backupDir = __DIR__;
    $path = realpath($backupDir . '/' . $safeName);
    
    // Security check: ensure path is within backups directory
    if ($path === false || !str_starts_with($path, realpath($backupDir) . DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        exit('Backup file not found');
    }
    
    if (!is_file($path)) {
        http_response_code(404);
        exit('Backup file not found');
    }
    
    // Determine content type based on extension
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $contentType = match ($ext) {
        'sql' => 'application/sql',
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
        default => 'application/octet-stream',
    };
    
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// Function to list backup files
function list_backup_files(string $backupDir): array
{
    if (!is_dir($backupDir)) {
        return [];
    }
    
    // Get all SQL and ZIP files
    $sqlFiles = glob($backupDir . '/*.sql') ?: [];
    $zipFiles = glob($backupDir . '/*.zip') ?: [];
    $allFiles = array_merge($sqlFiles, $zipFiles);
    
    // Sort by modification time (newest first)
    usort($allFiles, function ($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    return array_map(static function (string $path): array {
        return [
            'name' => basename($path),
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'created' => filemtime($path) ?: time(),
            'type' => strtoupper(pathinfo($path, PATHINFO_EXTENSION)),
        ];
    }, $allFiles);
}

// Function to format bytes
function format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = min((int)floor(log($bytes, 1024)), count($units) - 1);
    return number_format($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
}

$backupFiles = list_backup_files(__DIR__);
$companyName = SystemSettings::companyName($db) ?? 'Permits System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Backups - <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="<?= asset('/assets/app.css') ?>">
    <style>
        body { 
            background: linear-gradient(135deg, #0f172a 0%, #111a2e 100%); 
            color: #e2e8f0; 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; 
            margin: 0; 
            min-height: 100vh;
            padding: 20px;
        }
        
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
        }
        
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
            margin: 0;
        }
        
        .header-actions {
            display: flex;
            gap: 12px;
        }
        
        a.back { 
            color: #60a5fa; 
            text-decoration: none; 
            display: inline-flex; 
            align-items: center; 
            gap: 6px; 
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        a.back:hover { 
            text-decoration: underline; 
        }
        
        .lead { 
            color: #94a3b8; 
            margin-bottom: 24px; 
            font-size: 14px;
        }
        
        .card { 
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.8) 0%, rgba(15, 23, 42, 0.9) 100%); 
            border: 1px solid rgba(6, 182, 212, 0.1); 
            border-radius: 16px; 
            padding: 24px; 
            box-shadow: 0 30px 60px rgba(15, 23, 42, 0.35); 
            margin-bottom: 24px;
        }
        
        .card h2 {
            font-size: 20px;
            margin-top: 0;
            margin-bottom: 16px;
            color: #06b6d4;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
            font-size: 13px; 
        }
        
        th, td { 
            padding: 12px; 
            text-align: left; 
            border-bottom: 1px solid rgba(6, 182, 212, 0.1); 
        }
        
        th { 
            color: #94a3b8; 
            font-weight: 600; 
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.05em;
        }
        
        tbody tr:hover { 
            background: rgba(6, 182, 212, 0.05); 
        }
        
        .btn { 
            padding: 8px 16px; 
            border: none; 
            border-radius: 8px; 
            font-size: 13px; 
            font-weight: 600; 
            cursor: pointer; 
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        
        .btn-primary { 
            background: linear-gradient(135deg, #06b6d4 0%, #0ea5e9 100%); 
            color: #0f172a; 
        }
        
        .btn-primary:hover { 
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(6, 182, 212, 0.4);
        }
        
        .btn-secondary { 
            background: rgba(59, 130, 246, 0.12); 
            color: #e2e8f0; 
            border: 1px solid rgba(6, 182, 212, 0.2); 
        }
        
        .btn-secondary:hover { 
            background: rgba(59, 130, 246, 0.2); 
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-sql {
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #bbf7d0;
        }
        
        .badge-zip {
            background: rgba(59, 130, 246, 0.12);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #bfdbfe;
        }
        
        .info-box {
            background: rgba(6, 182, 212, 0.1);
            border: 1px solid rgba(6, 182, 212, 0.2);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
        }
        
        .info-box h3 {
            margin-top: 0;
            margin-bottom: 8px;
            font-size: 16px;
            color: #06b6d4;
        }
        
        .info-box ul {
            margin: 0;
            padding-left: 20px;
            color: #cbd5e1;
            font-size: 13px;
            line-height: 1.6;
        }
        
        @media (max-width: 768px) {
            table, tbody, tr, td, th { 
                display: block; 
            }
            
            th { 
                border-bottom: none; 
                margin-top: 16px; 
            }
            
            td { 
                border-bottom: none; 
                padding: 6px 12px; 
            }
            
            td:before {
                content: attr(data-label);
                font-weight: 600;
                color: #94a3b8;
                display: inline-block;
                width: 120px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a class="back" href="/admin.php">⬅ Back to Admin Panel</a>
        
        <div class="header">
            <h1>💾 Database Backups</h1>
            <div class="header-actions">
                <a href="/admin/backup.php" class="btn btn-primary">🚀 Create New Backup</a>
            </div>
        </div>
        
        <p class="lead">Browse and download database backup files. All backups are stored securely and accessible only to administrators.</p>
        
        <div class="info-box">
            <h3>ℹ️ About Backups</h3>
            <ul>
                <li><strong>SQL files</strong> contain database dumps that can be imported using MySQL/MariaDB client tools</li>
                <li><strong>ZIP files</strong> contain full application backups including files and database</li>
                <li>Backups are sorted by creation date (newest first)</li>
                <li>Always verify backups after download to ensure data integrity</li>
            </ul>
        </div>
        
        <div class="card">
            <h2>Available Backups</h2>
            <?php if (empty($backupFiles)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📦</div>
                    <p>No backup files found in this directory.</p>
                    <p><a href="/admin/backup.php" class="btn btn-primary" style="margin-top: 16px;">Create Your First Backup</a></p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>File Name</th>
                                <th>Type</th>
                                <th>Created</th>
                                <th>Size</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backupFiles as $backup): ?>
                                <tr>
                                    <td data-label="File Name"><?= htmlspecialchars($backup['name']) ?></td>
                                    <td data-label="Type">
                                        <span class="badge badge-<?= strtolower($backup['type']) ?>">
                                            <?= htmlspecialchars($backup['type']) ?>
                                        </span>
                                    </td>
                                    <td data-label="Created"><?= date('Y-m-d H:i:s', $backup['created']) ?></td>
                                    <td data-label="Size"><?= format_bytes((int)$backup['size']) ?></td>
                                    <td data-label="Actions">
                                        <a class="btn btn-secondary" href="?download=<?= urlencode($backup['name']) ?>">
                                            ⬇️ Download
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>📚 Backup Management Tips</h2>
            <ul style="color: #cbd5e1; font-size: 14px; line-height: 1.6; margin: 0;">
                <li>Regular backups are essential for disaster recovery and data protection</li>
                <li>Store backups in multiple locations (local and off-site) for redundancy</li>
                <li>Test your backups periodically to ensure they can be restored</li>
                <li>Keep backup retention policies aligned with your business requirements</li>
                <li>Document your backup and restore procedures for your team</li>
            </ul>
        </div>
    </div>
</body>
</html>
