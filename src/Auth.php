<?php
/**
 * Authentication helpers and thin OO wrapper.
 *
 * Exposes the legacy procedural helpers (startSession, login, etc.) while providing
 * a lightweight Auth class for newer templates/components that expect an object.
 */
declare(strict_types=1);

use Permits\Db;
use Permits\LoginRateLimiter;

/**
 * Start secure session
 * Initializes session with secure settings
 */
function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        if (!session_start()) {
            throw new \RuntimeException('Unable to start the authentication session.');
        }
    }
}

/**
 * Remove all authenticated identity from the current session and rotate its ID.
 * The session itself stays usable so the login page can safely display a fresh
 * CSRF token after an account is disabled or removed.
 */
function clearAuthenticationSession(): void
{
    startSession();

    foreach ([
        'user_id',
        'user_email',
        'user_name',
        'user_role',
        'login_time',
        'authenticated_at',
        'last_activity_at',
        'csrf_action_tokens',
    ] as $key) {
        unset($_SESSION[$key]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * Check if user is logged in
 * 
 * @return bool True if user is authenticated
 */
function isLoggedIn() {
    startSession();
    $hasIdentity = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    if ($hasIdentity && isSessionExpired()) {
        clearAuthenticationSession();
        return false;
    }

    return $hasIdentity;
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

        $role = strtolower(trim((string)($user['role'] ?? '')));
        if (
            !$user
            || strtolower((string)($user['status'] ?? '')) !== 'active'
            || !in_array($role, Auth::VALID_ROLES, true)
        ) {
            clearAuthenticationSession();
            return null;
        }

        $user['role'] = $role;

        return $user;
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

    $nowTimestamp = time();
    $windowStart = $nowTimestamp - LoginRateLimiter::WINDOW_SECONDS;
    $normalisedEmail = strtolower(trim((string) $email));
    $clientIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $rateLimiter = null;
    try {
        $rateLimiter = new LoginRateLimiter($db->pdo);
        $rateStatus = $rateLimiter->status($normalisedEmail, $clientIp, $nowTimestamp);
        if ($rateStatus['limited']) {
            return [
                'success' => false,
                'message' => 'Too many attempts. Wait 15 minutes and try again.',
                'retry_after' => $rateStatus['retry_after'],
            ];
        }
    } catch (Throwable $rateError) {
        // An unapplied migration must not make valid logins impossible. The
        // session limiter below remains as a temporary fallback until migrate runs.
        error_log('Persistent login limiter unavailable: ' . $rateError->getMessage());
        $rateLimiter = null;
    }

    $failedAttempts = array_values(array_filter(
        is_array($_SESSION['login_failures'] ?? null) ? $_SESSION['login_failures'] : [],
        static fn($timestamp): bool => is_int($timestamp) && $timestamp >= $windowStart
    ));
    $_SESSION['login_failures'] = $failedAttempts;

    if (count($failedAttempts) >= LoginRateLimiter::MAX_FAILURES) {
        return [
            'success' => false,
            'message' => 'Too many attempts. Wait 15 minutes and try again.',
            'retry_after' => max(1, LoginRateLimiter::WINDOW_SECONDS - ($nowTimestamp - min($failedAttempts))),
        ];
    }

    $recordFailure = static function () use ($rateLimiter, $normalisedEmail, $clientIp, $nowTimestamp): void {
        $_SESSION['login_failures'][] = $nowTimestamp;
        if ($rateLimiter instanceof LoginRateLimiter) {
            try {
                $rateLimiter->recordFailure($normalisedEmail, $clientIp, $nowTimestamp);
            } catch (Throwable $rateError) {
                error_log('Unable to record persistent login failure: ' . $rateError->getMessage());
            }
        }
    };
    
    try {
        // Get user from database
        $stmt = $db->pdo->prepare("SELECT * FROM users WHERE LOWER(TRIM(email)) = ? AND status = 'active'");
        $stmt->execute([$normalisedEmail]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // Check if user exists
        if (!$user) {
            $recordFailure();
            usleep(250000);
            return [
                'success' => false,
                'message' => 'Invalid email or password'
            ];
        }

        $role = strtolower(trim((string)($user['role'] ?? '')));
        if (!in_array($role, Auth::VALID_ROLES, true)) {
            $recordFailure();
            usleep(250000);
            return [
                'success' => false,
                'message' => 'Invalid email or password'
            ];
        }
        $user['role'] = $role;
        
        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            $recordFailure();
            usleep(250000);
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
        $_SESSION['authenticated_at'] = time();
        $_SESSION['last_activity_at'] = time();
        unset($_SESSION['login_failures']);
        if ($rateLimiter instanceof LoginRateLimiter) {
            try {
                $rateLimiter->clear($normalisedEmail, $clientIp);
            } catch (Throwable $rateError) {
                error_log('Unable to clear persistent login failures: ' . $rateError->getMessage());
            }
        }
        
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        // Update last login time
        $now = $db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
        $updateStmt = $db->pdo->prepare("UPDATE users SET last_login = $now WHERE id = ?");
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
        
    } catch (Throwable $e) {
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
        $cookie = session_get_cookie_params();
        $options = [
            'expires' => time() - 3600,
            'path' => (string) ($cookie['path'] ?? '/'),
            'secure' => (bool) ($cookie['secure'] ?? false),
            'httponly' => (bool) ($cookie['httponly'] ?? true),
            'samesite' => (string) ($cookie['samesite'] ?? 'Lax'),
        ];
        if (!empty($cookie['domain'])) {
            $options['domain'] = (string) $cookie['domain'];
        }
        setcookie(session_name(), '', $options);
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
    $user = getCurrentUser();
    if ($user === null || isSessionExpired()) {
        clearAuthenticationSession();
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

    // Keep role/name/email in step with database edits and record activity only
    // after the expiry and active-account checks have passed.
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    refreshSession();
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
 * Sessions expire after a configurable period of inactivity (two hours by
 * default). SESSION_IDLE_TIMEOUT is bounded to 5 minutes..24 hours.
 * 
 * @return bool True if session is expired
 */
function isSessionExpired() {
    startSession();
    
    $lastActivity = $_SESSION['last_activity_at'] ?? $_SESSION['login_time'] ?? null;
    if (!is_int($lastActivity)) {
        return true;
    }

    $timeout = filter_var($_ENV['SESSION_IDLE_TIMEOUT'] ?? 7200, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 300, 'max_range' => 86400],
    ]);
    if ($timeout === false) {
        $timeout = 7200;
    }

    if (time() - $lastActivity > $timeout) {
        return true;
    }

    return false;
}

/**
 * Refresh session (prevent expiration)
 * Call this on user activity to keep session alive
 */
function refreshSession() {
    startSession();
    $_SESSION['last_activity_at'] = time();
    // Retain the legacy key while older pages are being phased out.
    $_SESSION['login_time'] = $_SESSION['last_activity_at'];
}

/**
 * Object-oriented wrapper consumed by templates and controllers.
 */
class Auth
{
    /** @var list<string> */
    public const VALID_ROLES = ['user', 'manager', 'admin'];

    private Db $db;

    /** Cached user for repeated lookups during a request. */
    private ?array $userCache = null;

    /** Distinguishes a cached missing/inactive account from a cache miss. */
    private bool $userLoaded = false;

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
        return $this->getCurrentUser() !== null;
    }

    public function getCurrentUser(): ?array
    {
        // Authentication is resolved in one place so direct calls to this method,
        // role checks, and access gates can never bypass the idle timeout.
        if (!isLoggedIn()) {
            $this->userCache = null;
            $this->userLoaded = true;
            return null;
        }

        if ($this->userLoaded) {
            return $this->userCache;
        }

        try {
            $stmt = $this->db->pdo->prepare('SELECT id, email, name, role, status, last_login FROM users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            $role = strtolower(trim((string)($user['role'] ?? '')));
            if (
                $user === null
                || strtolower((string)($user['status'] ?? '')) !== 'active'
                || !in_array($role, self::VALID_ROLES, true)
            ) {
                clearAuthenticationSession();
                $user = null;
            } else {
                $user['role'] = $role;
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $role;
            }
            $this->userCache = $user;
            $this->userLoaded = true;
            return $user;
        } catch (\Throwable $e) {
            error_log('Auth getCurrentUser error: ' . $e->getMessage());
            clearAuthenticationSession();
            $this->userLoaded = true;
            return null;
        }
    }

    public function login(string $email, string $password): array
    {
        $result = login($email, $password);
        if (!empty($result['success']) && $result['success'] === true) {
            $this->userCache = $result['user'] ?? null;
            $this->userLoaded = true;
        }
        return $result;
    }

    public function logout(): void
    {
        logout();
        $this->userCache = null;
        $this->userLoaded = true;
    }

    public function requireLogin(?string $redirectTo = null): void
    {
        $user = $this->getCurrentUser();
        if ($user !== null) {
            $this->refreshSession();
            return;
        }

        clearAuthenticationSession();
        $this->userCache = null;
        $this->userLoaded = true;

        $target = $redirectTo ?? ($_SERVER['REQUEST_URI'] ?? '/dashboard.php');
        $loginUrl = '/login.php?redirect=' . urlencode($target);
        header('Location: ' . $loginUrl);
        exit;
    }

    /**
     * Require an active user with one of the supplied roles for an HTML page.
     * Unauthenticated users go to login; authenticated users without the role
     * return to the dashboard with a generic message.
     *
     * @param list<string> $roles
     * @return array<string,mixed>
     */
    public function requireRoles(array $roles, string $deniedRedirect = '/dashboard.php'): array
    {
        $roles = $this->normaliseRoles($roles);
        $this->requireLogin();
        $user = $this->getCurrentUser();

        if ($user !== null && in_array($user['role'], $roles, true)) {
            return $user;
        }

        $_SESSION['error_message'] = 'You do not have permission to access that page.';
        header('Location: ' . $deniedRedirect);
        exit;
    }

    /**
     * Require an active authenticated user for a JSON API. The response never
     * reveals whether an account was removed, disabled, or timed out.
     *
     * @param list<string> $roles Empty means any recognised authenticated role.
     * @return array<string,mixed>
     */
    public function requireJson(array $roles = []): array
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            $this->jsonError(401, 'Authentication required');
        }

        $roles = $roles === [] ? self::VALID_ROLES : $this->normaliseRoles($roles);
        if (!in_array($user['role'], $roles, true)) {
            $this->jsonError(403, 'Insufficient permissions');
        }

        $this->refreshSession();
        return $user;
    }

    /**
     * Resolve a user for a non-HTML/non-JSON protected resource, such as a QR
     * image. A null result lets the caller preserve its normal not-found reply.
     *
     * @param list<string> $roles
     * @return array<string,mixed>|null
     */
    public function userForRoles(array $roles): ?array
    {
        $user = $this->getCurrentUser();
        $roles = $this->normaliseRoles($roles);
        if ($user === null || !in_array($user['role'], $roles, true)) {
            return null;
        }

        $this->refreshSession();
        return $user;
    }

    public function hasRole(string $role): bool
    {
        $user = $this->getCurrentUser();
        return $user !== null && $user['role'] === strtolower(trim($role));
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

    /** @param list<string> $roles @return list<string> */
    private function normaliseRoles(array $roles): array
    {
        $normalised = array_values(array_unique(array_map(
            static fn (mixed $role): string => strtolower(trim((string)$role)),
            $roles
        )));
        if ($normalised === [] || array_diff($normalised, self::VALID_ROLES) !== []) {
            throw new \InvalidArgumentException('An access gate was configured with an invalid role.');
        }

        return $normalised;
    }

    private function jsonError(int $status, string $message): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'success' => false,
            'ok' => false,
            'message' => $message,
            'error' => $message,
        ], JSON_UNESCAPED_SLASHES);
        exit;
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
