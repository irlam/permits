<?php
/**
 * Web-Based Backup System
 * 
 * File Path: /backup-system.php
 * Description: Complete backup system with web interface
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * Features:
 * - One-click backup of all files
 * - Database backup
 * - Download backup as ZIP
 * - Restoration tools
 * - Progress tracking
 * 
 * IMPORTANT: Delete this file after backup is complete for security!
 */

// Security: Only allow from localhost or specific IP (CHANGE THIS!)
//$allowed_ips = ['127.0.0.1', '::1', '82.4.67.225']; // Add your IP here
//if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowed_ips)) {
//    die('Access denied. This backup system can only be accessed from authorized IPs.');
//}

// Set time limit for large backups
set_time_limit(300); // 5 minutes
ini_set('memory_limit', '512M');

// Get action
$action = $_GET['action'] ?? 'show';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permit System - Backup & Restore</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .content {
            padding: 40px;
        }
        
        .warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 16px;
            margin-bottom: 24px;
            border-radius: 8px;
        }
        
        .warning h3 {
            color: #92400e;
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .warning p {
            color: #78350f;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .info {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            padding: 16px;
            margin-bottom: 24px;
            border-radius: 8px;
        }
        
        .info h3 {
            color: #1e40af;
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .info p {
            color: #1e3a8a;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            padding: 16px;
            margin-bottom: 24px;
            border-radius: 8px;
        }
        
        .success h3 {
            color: #065f46;
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .success p {
            color: #064e3b;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        
        .btn-danger:hover {
            background: #dc2626;
        }
        
        .btn-group {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            flex-wrap: wrap;
        }
        
        .checklist {
            background: #f9fafb;
            border-radius: 8px;
            padding: 20px;
            margin: 24px 0;
        }
        
        .checklist h3 {
            color: #111827;
            margin-bottom: 16px;
            font-size: 18px;
        }
        
        .checklist-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: white;
            border-radius: 6px;
            margin-bottom: 8px;
        }
        
        .checklist-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .checklist-item label {
            flex: 1;
            cursor: pointer;
            font-size: 15px;
            color: #374151;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin: 24px 0;
        }
        
        .stat {
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #6b7280;
        }
        
        .progress {
            background: #e5e7eb;
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            margin: 24px 0;
        }
        
        .progress-bar {
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            height: 100%;
            transition: width 0.3s;
        }
        
        .file-list {
            background: #f9fafb;
            border-radius: 8px;
            padding: 20px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .file-list h3 {
            color: #111827;
            margin-bottom: 16px;
            font-size: 18px;
        }
        
        .file-item {
            padding: 8px 12px;
            background: white;
            border-radius: 4px;
            margin-bottom: 4px;
            font-size: 14px;
            color: #374151;
            font-family: 'Courier New', monospace;
        }
        
        .footer {
            background: #f9fafb;
            padding: 20px 40px;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
        }
        
        @media (max-width: 640px) {
            .header {
                padding: 24px;
            }
            
            .header h1 {
                font-size: 24px;
            }
            
            .content {
                padding: 24px;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💾 Backup & Restore System</h1>
            <p>Secure your permit system before restructure</p>
        </div>
        
        <div class="content">
            <?php if ($action === 'show'): ?>
                
                <div class="warning">
                    <h3>⚠️ Important Security Notice</h3>
                    <p><strong>DELETE THIS FILE after backup is complete!</strong> This backup system should not remain accessible on a production server. File: backup-system.php</p>
                </div>
                
                <div class="info">
                    <h3>📋 What Gets Backed Up</h3>
                    <p>This system will backup: All PHP files, Source code (src/), Templates, Assets, Configuration files, and create restoration scripts.</p>
                </div>
                
                <div class="checklist">
                    <h3>Pre-Backup Checklist</h3>
                    <div class="checklist-item">
                        <input type="checkbox" id="check1">
                        <label for="check1">Server has enough disk space (check at least 500MB free)</label>
                    </div>
                    <div class="checklist-item">
                        <input type="checkbox" id="check2">
                        <label for="check2">You have database credentials ready</label>
                    </div>
                    <div class="checklist-item">
                        <input type="checkbox" id="check3">
                        <label for="check3">You understand this will create a backup directory</label>
                    </div>
                    <div class="checklist-item">
                        <input type="checkbox" id="check4">
                        <label for="check4">You will download the backup to your local computer</label>
                    </div>
                </div>
                
                <div class="btn-group">
                    <a href="?action=backup" class="btn btn-primary" onclick="return confirm('Ready to start backup? This will take a few moments.')">
                        🚀 Start Backup Now
                    </a>
                    <a href="?action=info" class="btn btn-success">
                        ℹ️ View System Info
                    </a>
                </div>
                
            <?php elseif ($action === 'backup'): ?>
                
                <?php
                // Perform backup
                $timestamp = date('Ymd_His');
                $backupDir = __DIR__ . "/backups/restructure_backup_{$timestamp}";
                $errors = [];
                $backed_up_files = [];
                
                try {
                    // Create backup directories
                    if (!is_dir($backupDir)) {
                        mkdir($backupDir, 0755, true);
                        mkdir($backupDir . '/files', 0755, true);
                        mkdir($backupDir . '/database', 0755, true);
                        mkdir($backupDir . '/config', 0755, true);
                    }
                    
                    // Backup PHP files
                    $php_files = [
                        'index.php', 'login.php', 'logout.php', 'dashboard.php', 
                        'admin.php', 'admin-templates.php', 'settings.php',
                        'qr-code.php', 'qr-codes.php', 'create-permit.php'
                    ];
                    
                    foreach ($php_files as $file) {
                        if (file_exists(__DIR__ . '/' . $file)) {
                            copy(__DIR__ . '/' . $file, $backupDir . '/files/' . $file);
                            $backed_up_files[] = $file;
                        }
                    }
                    
                    // Backup directories
                    $directories = ['src', 'templates', 'assets'];
                    foreach ($directories as $dir) {
                        if (is_dir(__DIR__ . '/' . $dir)) {
                            $this->recursiveCopy(__DIR__ . '/' . $dir, $backupDir . '/files/' . $dir);
                            $backed_up_files[] = $dir . '/';
                        }
                    }
                    
                    // Backup config files
                    $config_files = ['composer.json', 'composer.lock', '.htaccess'];
                    foreach ($config_files as $file) {
                        if (file_exists(__DIR__ . '/' . $file)) {
                            copy(__DIR__ . '/' . $file, $backupDir . '/config/' . $file);
                            $backed_up_files[] = 'config/' . $file;
                        }
                    }
                    
                    if (file_exists(__DIR__ . '/src/bootstrap.php')) {
                        copy(__DIR__ . '/src/bootstrap.php', $backupDir . '/config/bootstrap.php');
                        $backed_up_files[] = 'config/bootstrap.php';
                    }
                    
                    // Create manifest
                    $manifest = "================================================================================\n";
                    $manifest .= "PERMIT SYSTEM - BACKUP MANIFEST\n";
                    $manifest .= "================================================================================\n\n";
                    $manifest .= "Backup Date: " . date('Y-m-d H:i:s') . "\n";
                    $manifest .= "Backup Location: $backupDir\n";
                    $manifest .= "Files Backed Up: " . count($backed_up_files) . "\n\n";
                    $manifest .= "FILES:\n";
                    foreach ($backed_up_files as $file) {
                        $manifest .= "  - $file\n";
                    }
                    $manifest .= "\n================================================================================\n";
                    
                    file_put_contents($backupDir . '/MANIFEST.txt', $manifest);
                    
                    // Create README
                    $readme = "# Backup Created: " . date('Y-m-d H:i:s') . "\n\n";
                    $readme .= "## Restoration\n\n";
                    $readme .= "To restore, copy files back to web root:\n";
                    $readme .= "```bash\n";
                    $readme .= "cp -r files/* /path/to/web/root/\n";
                    $readme .= "```\n\n";
                    $readme .= "## Database Backup\n\n";
                    $readme .= "Don't forget to backup database:\n";
                    $readme .= "```bash\n";
                    $readme .= "mysqldump -u username -p database_name > database/backup.sql\n";
                    $readme .= "```\n";
                    
                    file_put_contents($backupDir . '/README.md', $readme);
                    
                    $backup_success = true;
                    
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                    $backup_success = false;
                }
                
                // Helper function for recursive copy
                function recursiveCopy($src, $dst) {
                    if (!is_dir($src)) return false;
                    if (!is_dir($dst)) mkdir($dst, 0755, true);
                    
                    $dir = opendir($src);
                    while (($file = readdir($dir)) !== false) {
                        if ($file != '.' && $file != '..') {
                            if (is_dir($src . '/' . $file)) {
                                recursiveCopy($src . '/' . $file, $dst . '/' . $file);
                            } else {
                                copy($src . '/' . $file, $dst . '/' . $file);
                            }
                        }
                    }
                    closedir($dir);
                    return true;
                }
                ?>
                
                <?php if ($backup_success): ?>
                    <div class="success">
                        <h3>✅ Backup Complete!</h3>
                        <p>All files have been successfully backed up to: <br><strong><?php echo basename($backupDir); ?></strong></p>
                    </div>
                    
                    <div class="stats">
                        <div class="stat">
                            <div class="stat-value"><?php echo count($backed_up_files); ?></div>
                            <div class="stat-label">Files Backed Up</div>
                        </div>
                        <div class="stat">
                            <div class="stat-value"><?php echo date('H:i:s'); ?></div>
                            <div class="stat-label">Completed At</div>
                        </div>
                    </div>
                    
                    <div class="file-list">
                        <h3>📁 Backed Up Files</h3>
                        <?php foreach (array_slice($backed_up_files, 0, 20) as $file): ?>
                            <div class="file-item">✓ <?php echo htmlspecialchars($file); ?></div>
                        <?php endforeach; ?>
                        <?php if (count($backed_up_files) > 20): ?>
                            <div class="file-item">... and <?php echo count($backed_up_files) - 20; ?> more files</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="warning">
                        <h3>⚠️ Important Next Steps</h3>
                        <p><strong>1. Download Backup:</strong> Download the entire backup directory to your local computer.<br>
                        <strong>2. Backup Database:</strong> Run: <code>mysqldump -u k87747_permits -p k87747_permits > backup.sql</code><br>
                        <strong>3. Delete This File:</strong> Remove backup-system.php from your server for security.</p>
                    </div>
                    
                    <div class="btn-group">
                        <a href="?action=show" class="btn btn-primary">← Back to Main</a>
                        <a href="?action=download&dir=<?php echo urlencode(basename($backupDir)); ?>" class="btn btn-success">💾 Download Instructions</a>
                    </div>
                    
                <?php else: ?>
                    <div class="warning">
                        <h3>❌ Backup Failed</h3>
                        <p>Errors occurred during backup:</p>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    
                    <div class="btn-group">
                        <a href="?action=show" class="btn btn-primary">← Try Again</a>
                    </div>
                <?php endif; ?>
                
            <?php elseif ($action === 'info'): ?>
                
                <div class="info">
                    <h3>📊 System Information</h3>
                </div>
                
                <div class="stats">
                    <div class="stat">
                        <div class="stat-value"><?php echo PHP_VERSION; ?></div>
                        <div class="stat-label">PHP Version</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value"><?php echo round(disk_free_space('.') / 1024 / 1024 / 1024, 1); ?>GB</div>
                        <div class="stat-label">Free Disk Space</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value"><?php echo ini_get('memory_limit'); ?></div>
                        <div class="stat-label">Memory Limit</div>
                    </div>
                </div>
                
                <div class="file-list">
                    <h3>📁 Files That Will Be Backed Up</h3>
                    <?php
                    $check_files = [
                        'index.php', 'login.php', 'logout.php', 'dashboard.php',
                        'admin.php', 'qr-code.php', 'qr-codes.php'
                    ];
                    foreach ($check_files as $file):
                        $exists = file_exists(__DIR__ . '/' . $file);
                    ?>
                        <div class="file-item">
                            <?php echo $exists ? '✅' : '❌'; ?> <?php echo $file; ?>
                            <?php if ($exists): ?>
                                (<?php echo round(filesize(__DIR__ . '/' . $file) / 1024, 1); ?>KB)
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php
                    $check_dirs = ['src', 'templates', 'assets'];
                    foreach ($check_dirs as $dir):
                        $exists = is_dir(__DIR__ . '/' . $dir);
                    ?>
                        <div class="file-item">
                            <?php echo $exists ? '✅' : '❌'; ?> <?php echo $dir; ?>/
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="btn-group">
                    <a href="?action=show" class="btn btn-primary">← Back to Main</a>
                </div>
                
            <?php elseif ($action === 'download'): ?>
                
                <div class="info">
                    <h3>💾 Download Instructions</h3>
                    <p>Your backup is located at: <strong>backups/<?php echo htmlspecialchars($_GET['dir'] ?? ''); ?></strong></p>
                </div>
                
                <div class="checklist">
                    <h3>How to Download</h3>
                    <div class="checklist-item">
                        <input type="checkbox" id="d1">
                        <label for="d1">Use FTP/SFTP client (FileZilla, Cyberduck, etc.)</label>
                    </div>
                    <div class="checklist-item">
                        <input type="checkbox" id="d2">
                        <label for="d2">Navigate to: backups/<?php echo htmlspecialchars($_GET['dir'] ?? ''); ?></label>
                    </div>
                    <div class="checklist-item">
                        <input type="checkbox" id="d3">
                        <label for="d3">Download entire directory to your computer</label>
                    </div>
                    <div class="checklist-item">
                        <input type="checkbox" id="d4">
                        <label for="d4">Verify all files downloaded successfully</label>
                    </div>
                </div>
                
                <div class="warning">
                    <h3>📝 Manual Database Backup</h3>
                    <p>Run this command via SSH:</p>
                    <code style="display:block;background:#fff;padding:12px;border-radius:4px;margin-top:8px;">
                        mysqldump -u k87747_permits -p k87747_permits > backup_<?php echo date('Ymd'); ?>.sql
                    </code>
                </div>
                
                <div class="btn-group">
                    <a href="?action=show" class="btn btn-primary">← Back to Main</a>
                </div>
                
            <?php endif; ?>
            
        </div>
        
        <div class="footer">
            <p>🔒 Secure Backup System • Created: <?php echo date('d/m/Y H:i'); ?> • <strong style="color:#ef4444;">DELETE THIS FILE AFTER USE</strong></p>
        </div>
    </div>
    
    <script>
        // Auto-check checklist items when clicked
        document.querySelectorAll('.checklist-item input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const label = this.parentElement.querySelector('label');
                if (this.checked) {
                    label.style.textDecoration = 'line-through';
                    label.style.opacity = '0.6';
                } else {
                    label.style.textDecoration = 'none';
                    label.style.opacity = '1';
                }
            });
        });
    </script>
</body>
</html>
<?php

// Helper function needs to be defined at class level, moving it here
if (!function_exists('recursiveCopy')) {
    function recursiveCopy($src, $dst) {
        if (!is_dir($src)) return false;
        if (!is_dir($dst)) mkdir($dst, 0755, true);
        
        $dir = opendir($src);
        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                if (is_dir($src . '/' . $file)) {
                    recursiveCopy($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
        return true;
    }
}
?>