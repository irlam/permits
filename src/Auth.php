<?php
/**
 * Authentication helpers and thin OO wrapper.
 *
 * Exposes the legacy procedural helpers (startSession, login, etc.) while providing
 * a lightweight Auth class for newer templates/components that expect an object.
 */
declare(strict_types=1);

use Permits\Db;

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
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        
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
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        
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

/**
 * Object-oriented wrapper consumed by templates and controllers.
 */
class Auth
{
    private Db $db;

    /** Cached user for repeated lookups during a request. */
    private ?array $userCache = null;

    public function __construct(Db $db)
    {
        $this->db = $db;
        startSession();
    }

    public function startSession(): void
    {
        startSession();
    }

    public function isLoggedIn(): bool
    {
        return isLoggedIn();
    }

    public function getCurrentUser(): ?array
    {
        if ($this->userCache !== null) {
            return $this->userCache;
        }

        if (!$this->isLoggedIn()) {
            return null;
        }

        try {
            $stmt = $this->db->pdo->prepare('SELECT id, email, name, role, status, last_login FROM users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            $this->userCache = $user;
            return $user;
        } catch (\Throwable $e) {
            error_log('Auth getCurrentUser error: ' . $e->getMessage());
            return null;
        }
    }

    public function login(string $email, string $password): array
    {
        $result = login($email, $password);
        if (!empty($result['success']) && $result['success'] === true) {
            $this->userCache = $result['user'] ?? null;
        }
        return $result;
    }

    public function logout(): void
    {
        logout();
        $this->userCache = null;
    }

    public function requireLogin(?string $redirectTo = null): void
    {
        if ($this->isLoggedIn()) {
            if ($this->isSessionExpired()) {
                $this->logout();
            } else {
                return;
            }
        }

        $target = $redirectTo ?? ($_SERVER['REQUEST_URI'] ?? '/dashboard.php');
        $loginUrl = '/login.php?redirect=' . urlencode($target);
        header('Location: ' . $loginUrl);
        exit;
    }

    public function hasRole(string $role): bool
    {
        $user = $this->getCurrentUser();
        return $user !== null && strtolower($user['role'] ?? '') === strtolower($role);
    }

    public function hasAnyRole(array $roles): bool
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return false;
        }
        $currentRole = strtolower($user['role'] ?? '');
        foreach ($roles as $role) {
            if ($currentRole === strtolower((string)$role)) {
                return true;
            }
        }
        return false;
    }

    public function isSessionExpired(): bool
    {
        return isSessionExpired();
    }

    public function refreshSession(): void
    {
        refreshSession();
    }

    public function getUserDisplayName(?array $user = null): string
    {
        return getUserDisplayName($user ?? $this->getCurrentUser());
    }

    public function getUserInitials(?array $user = null): string
    {
        return getUserInitials($user ?? $this->getCurrentUser());
    }
}