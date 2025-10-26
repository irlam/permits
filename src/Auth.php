<?php
/**
 * Authentication Helper Functions
 * 
 * File Path: /src/auth.php
 * Description: Handle user authentication, sessions, and login/logout
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * Functions:
 * - startSession() - Initialize secure session
 * - isLoggedIn() - Check if user is authenticated
 * - getCurrentUser() - Get current logged-in user
 * - login() - Authenticate user and create session
 * - logout() - Destroy session and log out
 * - requireLogin() - Redirect to login if not authenticated
 */

/**
 * Start secure session
 * Initializes session with secure settings
 */
function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        // Secure session settings
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_secure', 1); // HTTPS only
        ini_set('session.cookie_samesite', 'Lax');
        
        session_start();
    }
}

/**
 * Check if user is logged in
 * 
 * @return bool True if user is authenticated
 */
function isLoggedIn() {
    startSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current logged-in user
 * 
 * @global object $db Database connection
 * @return array|null User data or null if not logged in
 */
function getCurrentUser() {
    global $db;
    
    if (!isLoggedIn()) {
        return null;
    }
    
    try {
        $stmt = $db->pdo->prepare("SELECT id, email, name, role, status, last_login FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $user ?: null;
    } catch (Exception $e) {
        error_log("Error getting current user: " . $e->getMessage());
        return null;
    }
}

/**
 * Authenticate user and create session
 * 
 * @global object $db Database connection
 * @param string $email User email
 * @param string $password User password
 * @return array Result with 'success' boolean and 'message' string
 */
function login($email, $password) {
    global $db;
    
    startSession();
    
    try {
        // Get user from database
        $stmt = $db->pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if user exists
        if (!$user) {
            return [
                'success' => false,
                'message' => 'Invalid email or password'
            ];
        }
        
        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            return [
                'success' => false,
                'message' => 'Invalid email or password'
            ];
        }
        
        // Create session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['login_time'] = time();
        
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        // Update last login time
        $updateStmt = $db->pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$user['id']]);
        
        // Log login activity
        if (function_exists('logActivity')) {
            logActivity('user_login', 'auth', 'user', $user['id'], "User logged in: {$user['email']}");
        }
        
        return [
            'success' => true,
            'message' => 'Login successful',
            'user' => $user
        ];
        
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'An error occurred during login'
        ];
    }
}

/**
 * Log out current user
 * Destroys session and clears all session data
 * 
 * @global object $db Database connection
 */
function logout() {
    global $db;
    
    startSession();
    
    // Log logout activity
    if (isLoggedIn() && function_exists('logActivity')) {
        $user = getCurrentUser();
        if ($user) {
            logActivity('user_logout', 'auth', 'user', $user['id'], "User logged out: {$user['email']}");
        }
    }
    
    // Destroy session
    $_SESSION = array();
    
    // Delete session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    session_destroy();
}

/**
 * Require user to be logged in
 * Redirects to login page if not authenticated
 * 
 * @param string $redirect_to URL to redirect to after login (optional)
 */
function requireLogin($redirect_to = null) {
    if (!isLoggedIn()) {
        $login_url = '/login.php';
        
        // Add return URL if specified
        if ($redirect_to) {
            $login_url .= '?redirect=' . urlencode($redirect_to);
        } else {
            // Use current page as return URL
            $current_url = $_SERVER['REQUEST_URI'] ?? '/dashboard.php';
            $login_url .= '?redirect=' . urlencode($current_url);
        }
        
        header('Location: ' . $login_url);
        exit;
    }
}

/**
 * Get user's display name
 * Returns name or email if name not set
 * 
 * @param array $user User data
 * @return string Display name
 */
function getUserDisplayName($user) {
    if (!$user) {
        return 'Guest';
    }
    
    return !empty($user['name']) ? $user['name'] : $user['email'];
}

/**
 * Get user's initials for avatar
 * 
 * @param array $user User data
 * @return string Two-letter initials
 */
function getUserInitials($user) {
    if (!$user || empty($user['name'])) {
        return '??';
    }
    
    $parts = explode(' ', $user['name']);
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    } else {
        return strtoupper(substr($user['name'], 0, 2));
    }
}

/**
 * Check if session is expired (optional security feature)
 * Sessions expire after 8 hours of inactivity
 * 
 * @return bool True if session is expired
 */
function isSessionExpired() {
    startSession();
    
    if (!isset($_SESSION['login_time'])) {
        return true;
    }
    
    // Session expires after 8 hours (28800 seconds)
    $timeout = 28800;
    
    if (time() - $_SESSION['login_time'] > $timeout) {
        return true;
    }
    
    // Update last activity time
    $_SESSION['login_time'] = time();
    
    return false;
}

/**
 * Refresh session (prevent expiration)
 * Call this on user activity to keep session alive
 */
function refreshSession() {
    startSession();
    $_SESSION['login_time'] = time();
}