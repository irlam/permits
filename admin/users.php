<?php
/**
 * User Management Dashboard
 * 
 * File Path: /admin/users.php
 * Description: Comprehensive user management interface with modern dark theme
 * Created: 03/11/2025
 * Last Modified: 03/11/2025
 * 
 * Features:
 * - User listing with search and filtering
 * - Create new users
 * - Edit existing users
 * - Delete users
 * - Role management (user, manager, admin)
 * - Status management (active, inactive)
 * - Responsive design matching activity.php theme
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

// Handle actions
$action = $_GET['action'] ?? '';
$message = '';
$messageType = 'info';

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Delete user (CSRF protected, POST only)
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $message = 'Invalid request';
        $messageType = 'error';
    } else {
        $userId = $_POST['user_id'] ?? '';
        
        // Prevent deleting yourself
        if ($userId === $_SESSION['user_id']) {
            $message = 'Cannot delete your own account';
            $messageType = 'error';
        } else {
            try {
                $stmt = $db->pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $message = 'User deleted successfully';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Error deleting user: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Create user
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $message = 'Invalid request';
        $messageType = 'error';
    } else {
        $email = trim($_POST['email'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $status = $_POST['status'] ?? 'active';
        
        if (empty($email) || empty($name) || empty($password)) {
            $message = 'Email, name, and password are required';
            $messageType = 'error';
        } else {
            try {
                // Check if email already exists
                $checkStmt = $db->pdo->prepare("SELECT id FROM users WHERE email = ?");
                $checkStmt->execute([$email]);
                if ($checkStmt->fetch()) {
                    $message = 'Email already exists';
                    $messageType = 'error';
                } else {
                    // Generate UUID (cryptographically secure)
                    $userId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        random_int(0, 0xffff), random_int(0, 0xffff),
                        random_int(0, 0xffff),
                        random_int(0, 0x0fff) | 0x4000,
                        random_int(0, 0x3fff) | 0x8000,
                        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
                    );
                    
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    
                    $stmt = $db->pdo->prepare("INSERT INTO users (id, email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$userId, $email, $passwordHash, $name, $role, $status]);
                    
                    $message = 'User created successfully';
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $message = 'Error creating user: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Update user
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $message = 'Invalid request';
        $messageType = 'error';
    } else {
        $userId = $_POST['user_id'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $status = $_POST['status'] ?? 'active';
        
        if (empty($userId) || empty($email) || empty($name)) {
            $message = 'User ID, email, and name are required';
            $messageType = 'error';
        } else {
            try {
                // Check if email already exists for other users
                $checkStmt = $db->pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $checkStmt->execute([$email, $userId]);
                if ($checkStmt->fetch()) {
                    $message = 'Email already exists for another user';
                    $messageType = 'error';
                } else {
                    if (!empty($password)) {
                        // Update with new password
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->pdo->prepare("UPDATE users SET email = ?, password_hash = ?, name = ?, role = ?, status = ? WHERE id = ?");
                        $stmt->execute([$email, $passwordHash, $name, $role, $status, $userId]);
                    } else {
                        // Update without changing password
                        $stmt = $db->pdo->prepare("UPDATE users SET email = ?, name = ?, role = ?, status = ? WHERE id = ?");
                        $stmt->execute([$email, $name, $role, $status, $userId]);
                    }
                    
                    $message = 'User updated successfully';
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $message = 'Error updating user: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Get filters
$filterRole = $_GET['role'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$searchTerm = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

// Build query
$whereConditions = [];
$params = [];

if ($filterRole) {
    $whereConditions[] = "role = ?";
    $params[] = $filterRole;
}
if ($filterStatus) {
    $whereConditions[] = "status = ?";
    $params[] = $filterStatus;
}
if ($searchTerm) {
    $whereConditions[] = "(name LIKE ? OR email LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get total count
$countStmt = $db->pdo->prepare("SELECT COUNT(*) as total FROM users $whereClause");
$countStmt->execute($params);
$total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($total / $perPage);
$offset = ($page - 1) * $perPage;

// Get users
$stmt = $db->pdo->prepare("
    SELECT id, email, name, role, status, created_at, last_login
    FROM users
    $whereClause
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$perPage, $offset]));
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$statsStmt = $db->pdo->query("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin_users,
        SUM(CASE WHEN role = 'manager' THEN 1 ELSE 0 END) as manager_users,
        SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) as regular_users
    FROM users
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin Dashboard</title>
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
            text-decoration: none;
            display: inline-block;
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

        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
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

        .badge {
            display: inline-block;
            background: rgba(6, 182, 212, 0.1);
            color: #06b6d4;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-admin {
            background: rgba(168, 85, 247, 0.1);
            color: #a855f7;
        }

        .badge-manager {
            background: rgba(251, 191, 36, 0.1);
            color: #fbbf24;
        }

        .badge-active {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }

        .badge-inactive {
            background: rgba(148, 163, 184, 0.1);
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
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(6, 182, 212, 0.2);
            border-radius: 12px;
            padding: 24px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h2 {
            font-size: 24px;
            color: #06b6d4;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            color: #94a3b8;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-modal:hover {
            color: #e2e8f0;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            color: #94a3b8;
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(6, 182, 212, 0.2);
            border-radius: 6px;
            padding: 10px 12px;
            color: #e2e8f0;
            font-size: 14px;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: rgba(6, 182, 212, 0.5);
            box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .timestamp {
            color: #64748b;
            white-space: nowrap;
            font-size: 12px;
        }

        .actions-cell {
            display: flex;
            gap: 8px;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filters {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>👥 User Management</h1>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="openCreateModal()">+ Create User</button>
                <a href="/admin.php" class="btn btn-secondary">← Back to Admin</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $messageType === 'error' ? 'error' : ''; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Users</div>
                <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Users</div>
                <div class="stat-value"><?php echo number_format($stats['active_users']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Administrators</div>
                <div class="stat-value"><?php echo number_format($stats['admin_users']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Managers</div>
                <div class="stat-value"><?php echo number_format($stats['manager_users']); ?></div>
            </div>
        </div>

        <div class="card">
            <h2>Filter Users</h2>
            <form method="GET" class="filters">
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Name or email...">
                </div>
                <div class="filter-group">
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        <option value="">All Roles</option>
                        <option value="admin" <?php echo $filterRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="manager" <?php echo $filterRole === 'manager' ? 'selected' : ''; ?>>Manager</option>
                        <option value="user" <?php echo $filterRole === 'user' ? 'selected' : ''; ?>>User</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All Statuses</option>
                        <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="filter-group" style="align-self: end;">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Users (<?php echo number_format($total); ?>)</h2>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <?php
                                    $roleClass = $user['role'] === 'admin' ? 'badge-admin' : ($user['role'] === 'manager' ? 'badge-manager' : '');
                                    ?>
                                    <span class="badge <?php echo $roleClass; ?>"><?php echo htmlspecialchars($user['role']); ?></span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $user['status'] === 'active' ? 'badge-active' : 'badge-inactive'; ?>">
                                        <?php echo htmlspecialchars($user['status']); ?>
                                    </span>
                                </td>
                                <td class="timestamp"><?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?></td>
                                <td class="timestamp"><?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : 'Never'; ?></td>
                                <td>
                                    <div class="actions-cell">
                                        <button class="btn btn-secondary btn-small" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($user), ENT_QUOTES, 'UTF-8'); ?>)'>Edit</button>
                                        <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                            <button class="btn btn-danger btn-small" onclick="confirmDelete('<?php echo htmlspecialchars($user['id']); ?>', '<?php echo htmlspecialchars($user['name']); ?>')">Delete</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($searchTerm); ?>&role=<?php echo urlencode($filterRole); ?>&status=<?php echo urlencode($filterStatus); ?>">← Previous</a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($searchTerm); ?>&role=<?php echo urlencode($filterRole); ?>&status=<?php echo urlencode($filterStatus); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($searchTerm); ?>&role=<?php echo urlencode($filterRole); ?>&status=<?php echo urlencode($filterStatus); ?>">Next →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Create User Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create New User</h2>
                <button class="close-modal" onclick="closeCreateModal()">×</button>
            </div>
            <form method="POST" action="?action=create">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="form-group">
                    <label for="create_name">Name *</label>
                    <input type="text" id="create_name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="create_email">Email *</label>
                    <input type="email" id="create_email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="create_password">Password *</label>
                    <input type="password" id="create_password" name="password" required>
                </div>
                <div class="form-group">
                    <label for="create_role">Role</label>
                    <select id="create_role" name="role">
                        <option value="user">User</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="create_status">Status</label>
                    <select id="create_status" name="status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Create User</button>
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit User</h2>
                <button class="close-modal" onclick="closeEditModal()">×</button>
            </div>
            <form method="POST" action="?action=update">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" id="edit_user_id" name="user_id">
                <div class="form-group">
                    <label for="edit_name">Name *</label>
                    <input type="text" id="edit_name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="edit_email">Email *</label>
                    <input type="email" id="edit_email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="edit_password">New Password (leave blank to keep current)</label>
                    <input type="password" id="edit_password" name="password">
                </div>
                <div class="form-group">
                    <label for="edit_role">Role</label>
                    <select id="edit_role" name="role">
                        <option value="user">User</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_status">Status</label>
                    <select id="edit_status" name="status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Update User</button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openCreateModal() {
            document.getElementById('createModal').classList.add('active');
        }

        function closeCreateModal() {
            document.getElementById('createModal').classList.remove('active');
        }

        function openEditModal(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_name').value = user.name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_role').value = user.role;
            document.getElementById('edit_status').value = user.status;
            document.getElementById('edit_password').value = '';
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        function confirmDelete(userId, userName) {
            if (confirm(`Are you sure you want to delete user "${userName}"? This action cannot be undone.`)) {
                // Create a form and submit it
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '?action=delete';
                
                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'user_id';
                userIdInput.value = userId;
                form.appendChild(userIdInput);
                
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = '<?php echo $_SESSION['csrf_token']; ?>';
                form.appendChild(csrfInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close modals when clicking outside
        document.getElementById('createModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeCreateModal();
            }
        });

        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeCreateModal();
                closeEditModal();
            }
        });
    </script>
</body>
</html>
