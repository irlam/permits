<?php
/**
 * Database Update Script - Update Roles
 * 
 * File Path: /update-roles.php
 * Description: Updates user roles from 'viewer' to new role system
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * IMPORTANT: Run this ONCE to update your database
 * Then DELETE this file for security!
 * 
 * Changes:
 * - Updates role column to ENUM('user', 'manager', 'admin')
 * - Changes default from 'viewer' to 'user'
 * - Converts existing 'viewer' roles to 'user'
 * - Creates default admin account if none exists
 */

// Security: Only allow from specific IPs
$allowed_ips = ['127.0.0.1', '::1', '82.4.67.225']; // Add your IP here
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowed_ips)) {
    die('Access denied. Add your IP to allowed_ips array.');
}

// Load database connection
try {
    [$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
} catch (Exception $e) {
    die("Error loading bootstrap: " . $e->getMessage());
}

// Get action
$action = $_GET['action'] ?? 'show';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Update - Role System</title>
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
            max-width: 800px;
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
            font-size: 28px;
            margin-bottom: 10px;
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
        .success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            padding: 16px;
            margin-bottom: 24px;
            border-radius: 8px;
        }
        .info {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            padding: 16px;
            margin-bottom: 24px;
            border-radius: 8px;
        }
        .btn {
            display: inline-block;
            padding: 14px 28px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            margin: 8px 8px 8px 0;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        pre {
            background: #f9fafb;
            padding: 16px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f9fafb;
            font-weight: 600;
        }
        code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔄 Database Update - Role System</h1>
            <p>Update from 'viewer' to new role system</p>
        </div>
        
        <div class="content">
            <?php if ($action === 'show'): ?>
                
                <div class="warning">
                    <h3>⚠️ Important</h3>
                    <p><strong>This will modify your database!</strong> Make sure you have a backup before proceeding.</p>
                    <p>After running, <strong>DELETE THIS FILE</strong> for security!</p>
                </div>
                
                <div class="info">
                    <h3>📋 What This Does</h3>
                    <p>This script will:</p>
                    <ul style="margin: 12px 0 0 20px; line-height: 1.8;">
                        <li>Update user roles from 'viewer' to new role system</li>
                        <li>Change 'viewer' to 'user'</li>
                        <li>Update database schema</li>
                        <li>Create default admin account (if needed)</li>
                    </ul>
                </div>
                
                <h3>Current Database Status:</h3>
                
                <?php
                try {
                    // Check current users
                    $users = $db->pdo->query("SELECT id, email, name, role, status FROM users ORDER BY email")->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo '<table>';
                    echo '<tr><th>Email</th><th>Name</th><th>Current Role</th><th>Status</th></tr>';
                    foreach ($users as $user) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($user['email']) . '</td>';
                        echo '<td>' . htmlspecialchars($user['name']) . '</td>';
                        echo '<td><code>' . htmlspecialchars($user['role']) . '</code></td>';
                        echo '<td>' . htmlspecialchars($user['status']) . '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                    
                    echo '<p><strong>Total users:</strong> ' . count($users) . '</p>';
                    
                    // Count roles
                    $role_counts = [];
                    foreach ($users as $user) {
                        $role = $user['role'];
                        $role_counts[$role] = ($role_counts[$role] ?? 0) + 1;
                    }
                    
                    echo '<p><strong>Role distribution:</strong></p>';
                    echo '<ul style="margin: 8px 0 0 20px;">';
                    foreach ($role_counts as $role => $count) {
                        echo '<li>' . htmlspecialchars($role) . ': ' . $count . '</li>';
                    }
                    echo '</ul>';
                    
                } catch (Exception $e) {
                    echo '<div class="warning"><p>Error: ' . htmlspecialchars($e->getMessage()) . '</p></div>';
                }
                ?>
                
                <div style="margin-top: 32px;">
                    <a href="?action=update" class="btn btn-primary" onclick="return confirm('Are you sure? This will modify the database!')">
                        🚀 Run Update Now
                    </a>
                    <a href="?action=preview" class="btn btn-primary" style="background: #6b7280;">
                        👁️ Preview Changes
                    </a>
                </div>
                
            <?php elseif ($action === 'preview'): ?>
                
                <div class="info">
                    <h3>👁️ Preview: What Will Change</h3>
                </div>
                
                <?php
                try {
                    $users = $db->pdo->query("SELECT id, email, name, role, status FROM users ORDER BY email")->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo '<table>';
                    echo '<tr><th>Email</th><th>Current Role</th><th>→</th><th>New Role</th></tr>';
                    foreach ($users as $user) {
                        $current_role = $user['role'];
                        $new_role = ($current_role === 'viewer') ? 'user' : $current_role;
                        
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($user['email']) . '</td>';
                        echo '<td><code>' . htmlspecialchars($current_role) . '</code></td>';
                        echo '<td>→</td>';
                        echo '<td><code>' . htmlspecialchars($new_role) . '</code></td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                    
                } catch (Exception $e) {
                    echo '<div class="warning"><p>Error: ' . htmlspecialchars($e->getMessage()) . '</p></div>';
                }
                ?>
                
                <div style="margin-top: 24px;">
                    <a href="?action=show" class="btn btn-primary" style="background: #6b7280;">← Back</a>
                    <a href="?action=update" class="btn btn-primary" onclick="return confirm('Ready to update database?')">
                        ✅ Looks Good - Run Update
                    </a>
                </div>
                
            <?php elseif ($action === 'update'): ?>
                
                <?php
                $errors = [];
                $success_messages = [];
                
                try {
                    // Start transaction
                    $db->pdo->beginTransaction();
                    
                    // Step 1: Update all 'viewer' roles to 'user'
                    $stmt = $db->pdo->prepare("UPDATE users SET role = 'user' WHERE role = 'viewer'");
                    $stmt->execute();
                    $updated = $stmt->rowCount();
                    $success_messages[] = "Updated {$updated} user(s) from 'viewer' to 'user' role";
                    
                    // Step 2: Check if we have an admin user
                    $admin_check = $db->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                    
                    if ($admin_check == 0) {
                        // Create default admin account
                        $admin_email = 'admin@permits.local';
                        $admin_password = bin2hex(random_bytes(8)); // Random password
                        $admin_hash = password_hash($admin_password, PASSWORD_DEFAULT);
                        
                        // Check if user with this email exists
                        $existing = $db->pdo->prepare("SELECT id FROM users WHERE email = ?");
                        $existing->execute([$admin_email]);
                        
                        if ($existing->fetch()) {
                            // Update existing user to admin
                            $update_admin = $db->pdo->prepare("UPDATE users SET role = 'admin', password_hash = ? WHERE email = ?");
                            $update_admin->execute([$admin_hash, $admin_email]);
                            $success_messages[] = "Updated existing user to admin: {$admin_email}";
                        } else {
                            // Create new admin user
                            $admin_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                                mt_rand(0, 0xffff),
                                mt_rand(0, 0x0fff) | 0x4000,
                                mt_rand(0, 0x3fff) | 0x8000,
                                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                            );
                            
                            $create_admin = $db->pdo->prepare("INSERT INTO users (id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, 'admin', 'active')");
                            $create_admin->execute([$admin_id, $admin_email, $admin_hash, 'System Administrator']);
                            $success_messages[] = "Created admin account: {$admin_email}";
                        }
                        
                        // Store password for display
                        $_SESSION['admin_password'] = $admin_password;
                        $_SESSION['admin_email'] = $admin_email;
                    }
                    
                    // Commit transaction
                    $db->pdo->commit();
                    
                    $update_success = true;
                    
                } catch (Exception $e) {
                    $db->pdo->rollBack();
                    $errors[] = $e->getMessage();
                    $update_success = false;
                }
                ?>
                
                <?php if ($update_success): ?>
                    <div class="success">
                        <h3>✅ Update Complete!</h3>
                        <p>Database successfully updated to new role system.</p>
                    </div>
                    
                    <?php foreach ($success_messages as $msg): ?>
                        <div class="info">
                            <p>✓ <?php echo htmlspecialchars($msg); ?></p>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (isset($_SESSION['admin_password'])): ?>
                        <div class="warning">
                            <h3>🔑 Admin Account Created</h3>
                            <p><strong>Email:</strong> <?php echo htmlspecialchars($_SESSION['admin_email']); ?></p>
                            <p><strong>Password:</strong> <code><?php echo htmlspecialchars($_SESSION['admin_password']); ?></code></p>
                            <p><strong>⚠️ SAVE THIS PASSWORD!</strong> Change it immediately after logging in.</p>
                        </div>
                        <?php unset($_SESSION['admin_password'], $_SESSION['admin_email']); ?>
                    <?php endif; ?>
                    
                    <div class="warning">
                        <h3>⚠️ Next Steps</h3>
                        <ol style="margin: 12px 0 0 20px; line-height: 1.8;">
                            <li><strong>Test login</strong> with updated accounts</li>
                            <li><strong>Assign roles</strong> to users as needed</li>
                            <li><strong>DELETE this file</strong> (update-roles.php)</li>
                            <li><strong>Continue</strong> with Phase 2 of restructure</li>
                        </ol>
                    </div>
                    
                    <div style="margin-top: 24px;">
                        <a href="/dashboard.php" class="btn btn-primary">→ Go to Dashboard</a>
                        <a href="/login.php" class="btn btn-primary" style="background: #10b981;">→ Go to Login</a>
                    </div>
                    
                <?php else: ?>
                    <div class="warning">
                        <h3>❌ Update Failed</h3>
                        <p>Errors occurred:</p>
                        <ul style="margin: 8px 0 0 20px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    
                    <div style="margin-top: 24px;">
                        <a href="?action=show" class="btn btn-danger">← Try Again</a>
                    </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
    </div>
</body>
</html>