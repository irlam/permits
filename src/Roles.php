<?php
/**
 * Role Management Functions
 * 
 * File Path: /src/roles.php
 * Description: Handle role-based permissions and access control
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * Role Hierarchy:
 * - user: Basic permissions (create/view own permits)
 * - manager: Operations control (view/edit all permits, authorize)
 * - admin: Full control (everything)
 * 
 * Functions:
 * - hasRole() - Check if user has specific role
 * - hasAnyRole() - Check if user has any of specified roles
 * - isAdmin() - Check if user is admin
 * - isManager() - Check if user is manager or admin
 * - isUser() - Check if user is user (or higher)
 * - requireRole() - Require specific role or redirect
 * - can() - Check if user can perform action
 */

/**
 * Check if current user has specific role
 * 
 * @param string $role Role to check (user, manager, admin)
 * @return bool True if user has exact role
 */
function hasRole($role) {
    $user = getCurrentUser();
    
    if (!$user) {
        return false;
    }
    
    return $user['role'] === $role;
}

/**
 * Check if current user has any of the specified roles
 * 
 * @param array $roles Array of roles to check
 * @return bool True if user has any of the roles
 */
function hasAnyRole($roles) {
    $user = getCurrentUser();
    
    if (!$user) {
        return false;
    }
    
    return in_array($user['role'], $roles);
}

/**
 * Check if current user is admin
 * 
 * @return bool True if user is admin
 */
function isAdmin() {
    return hasRole('admin');
}

/**
 * Check if current user is manager or admin
 * 
 * @return bool True if user is manager or admin
 */
function isManager() {
    return hasAnyRole(['manager', 'admin']);
}

/**
 * Check if current user is user, manager, or admin
 * 
 * @return bool True if user has any role
 */
function isUser() {
    return hasAnyRole(['user', 'manager', 'admin']);
}

/**
 * Require specific role or redirect to access denied
 * 
 * @param string|array $required_role Role(s) required
 * @param string $redirect_to URL to redirect to if access denied (default: dashboard)
 */
function requireRole($required_role, $redirect_to = '/dashboard.php') {
    // First check if logged in
    requireLogin();
    
    $user = getCurrentUser();
    
    if (!$user) {
        header('Location: /login.php');
        exit;
    }
    
    // Check if user has required role
    $has_access = false;
    
    if (is_array($required_role)) {
        $has_access = hasAnyRole($required_role);
    } else {
        $has_access = hasRole($required_role);
    }
    
    if (!$has_access) {
        // Log unauthorized access attempt
        if (function_exists('logActivity')) {
            logActivity(
                'unauthorized_access',
                'security',
                'access',
                $user['id'],
                "User {$user['email']} attempted to access restricted area requiring role: " . (is_array($required_role) ? implode(', ', $required_role) : $required_role)
            );
        }
        
        // Redirect to dashboard with error
        $_SESSION['error_message'] = 'You do not have permission to access that page.';
        header('Location: ' . $redirect_to);
        exit;
    }
}

/**
 * Check if user can perform specific action
 * 
 * @param string $action Action to check permission for
 * @param mixed $resource Optional resource to check permission on
 * @return bool True if user can perform action
 */
function can($action, $resource = null) {
    $user = getCurrentUser();
    
    if (!$user) {
        return false;
    }
    
    $role = $user['role'];
    
    // Define permissions matrix
    $permissions = [
        'user' => [
            'create_permit',
            'view_own_permits',
            'edit_own_draft_permits',
            'view_templates'
        ],
        'manager' => [
            'create_permit',
            'view_own_permits',
            'edit_own_draft_permits',
            'view_templates',
            'view_all_permits',
            'edit_all_permits',
            'authorize_permits',
            'view_qr_codes',
            'print_qr_codes',
            'view_activity_log'
        ],
        'admin' => [
            'create_permit',
            'view_own_permits',
            'edit_own_draft_permits',
            'view_templates',
            'view_all_permits',
            'edit_all_permits',
            'authorize_permits',
            'view_qr_codes',
            'print_qr_codes',
            'view_activity_log',
            'create_templates',
            'edit_templates',
            'delete_templates',
            'manage_users',
            'view_users',
            'create_users',
            'edit_users',
            'delete_users',
            'change_user_roles',
            'system_settings',
            'email_settings',
            'backup_restore',
            'view_system_info'
        ]
    ];
    
    // Check if role has permission
    if (!isset($permissions[$role])) {
        return false;
    }
    
    // Special case: check if user owns the resource
    if ($action === 'edit_permit' && $resource) {
        // Admins and managers can edit any permit
        if ($role === 'admin' || $role === 'manager') {
            return true;
        }
        
        // Users can only edit their own draft permits
        if ($role === 'user' && isset($resource['status']) && $resource['status'] === 'draft') {
            return isset($resource['holder_id']) && $resource['holder_id'] === $user['id'];
        }
        
        return false;
    }
    
    return in_array($action, $permissions[$role]);
}

/**
 * Get role display name
 * 
 * @param string $role Role identifier
 * @return string Human-readable role name
 */
function getRoleDisplayName($role) {
    $names = [
        'user' => 'User',
        'manager' => 'Manager',
        'admin' => 'Administrator'
    ];
    
    return $names[$role] ?? ucfirst($role);
}

/**
 * Get role badge HTML
 * Returns styled badge for role
 * 
 * @param string $role Role identifier
 * @return string HTML badge
 */
function getRoleBadge($role) {
    $badges = [
        'user' => '<span class="badge badge-blue">User</span>',
        'manager' => '<span class="badge badge-purple">Manager</span>',
        'admin' => '<span class="badge badge-red">Admin</span>'
    ];
    
    return $badges[$role] ?? '<span class="badge badge-gray">' . htmlspecialchars($role) . '</span>';
}

/**
 * Get all available roles
 * 
 * @return array Array of role identifiers
 */
function getAllRoles() {
    return ['user', 'manager', 'admin'];
}

/**
 * Get role description
 * 
 * @param string $role Role identifier
 * @return string Role description
 */
function getRoleDescription($role) {
    $descriptions = [
        'user' => 'Can create and view own permits. Basic access.',
        'manager' => 'Can view, edit and authorize all permits. Access to QR codes.',
        'admin' => 'Full system access. Can manage users, templates and settings.'
    ];
    
    return $descriptions[$role] ?? '';
}

/**
 * Check if user can access admin panel
 * 
 * @return bool True if user can access admin panel
 */
function canAccessAdmin() {
    return hasAnyRole(['manager', 'admin']);
}

/**
 * Get permissions for role
 * Returns array of permissions for given role
 * 
 * @param string $role Role identifier
 * @return array Array of permission strings
 */
function getRolePermissions($role) {
    $permissions = [
        'user' => [
            'Create permits',
            'View own permits',
            'Edit own draft permits'
        ],
        'manager' => [
            'Create permits',
            'View all permits',
            'Edit all permits',
            'Authorize permits',
            'View QR codes',
            'Print QR codes',
            'View activity logs'
        ],
        'admin' => [
            'All manager permissions',
            'Create/edit templates',
            'Manage users',
            'Change user roles',
            'System settings',
            'Email settings',
            'Backup & restore'
        ]
    ];
    
    return $permissions[$role] ?? [];
}