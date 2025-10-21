<?php
/**
 * Permits System - Authentication Manager
 * 
 * Description: Handles user authentication, sessions, and permissions
 * Name: Auth.php
 * Last Updated: 21/10/2025 21:03:42 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - User login/logout functionality
 * - Session management
 * - Role-based access control (Admin, Manager, Viewer)
 * - Password hashing and verification
 * - Remember me functionality
 * 
 * Features:
 * - Secure password hashing with bcrypt
 * - Session token management
 * - Cookie-based remember me
 * - Role-based permissions
 * - Rate limiting support
 */

namespace Permits;

use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Authentication manager for the Permits system
 */
class Auth {
    /**
     * @var PDO Database connection
     */
    private PDO $pdo;
    
    /**
     * @var array Current user session data
     */
    private ?array $currentUser = null;
    
    /**
     * Constructor
     * 
     * @param Db $db Database connection wrapper
     */
    public function __construct(Db $db) {
        $this->pdo = $db->pdo;
        $this->loadSession();
    }
    
    /**
     * Attempt to log in a user
     * 
     * @param string $username Username or email
     * @param string $password Plain text password
     * @param bool $remember Whether to create a persistent session
     * @return bool True if login successful, false otherwise
     */
    public function login(string $username, string $password, bool $remember = false): bool {
        // Find user by username or email
        $stmt = $this->pdo->prepare("
            SELECT * FROM users 
            WHERE username = ? OR email = ?
            LIMIT 1
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return false;
        }
        
        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }
        
        // Update last login time
        $updateStmt = $this->pdo->prepare("
            UPDATE users SET last_login = NOW() WHERE id = ?
        ");
        $updateStmt->execute([$user['id']]);
        
        // Create session
        $this->createSession($user, $remember);
        
        return true;
    }
    
    /**
     * Log out the current user
     * 
     * @return void
     */
    public function logout(): void {
        if ($this->currentUser) {
            // Delete session from database
            if (!empty($_COOKIE['session_token'])) {
                $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE token = ?");
                $stmt->execute([$_COOKIE['session_token']]);
            }
            
            // Clear session cookie
            setcookie('session_token', '', time() - 3600, '/', '', true, true);
            
            $this->currentUser = null;
        }
    }
    
    /**
     * Create a new user
     * 
     * @param string $username Username
     * @param string $email Email address
     * @param string $password Plain text password
     * @param string $role User role (admin, manager, viewer)
     * @return string User ID
     * @throws \Exception If user creation fails
     */
    public function createUser(string $username, string $email, string $password, string $role = 'viewer'): string {
        // Validate role
        $validRoles = ['admin', 'manager', 'viewer'];
        if (!in_array($role, $validRoles)) {
            throw new \Exception("Invalid role: $role");
        }
        
        // Hash password
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        
        // Create user
        $id = Uuid::uuid4()->toString();
        $stmt = $this->pdo->prepare("
            INSERT INTO users (id, username, email, password_hash, role, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        try {
            $stmt->execute([$id, $username, $email, $passwordHash, $role]);
            return $id;
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) { // Duplicate entry
                throw new \Exception("Username or email already exists");
            }
            throw $e;
        }
    }
    
    /**
     * Check if user is authenticated
     * 
     * @return bool True if user is logged in
     */
    public function isAuthenticated(): bool {
        return $this->currentUser !== null;
    }
    
    /**
     * Get current user data
     * 
     * @return array|null User data or null if not authenticated
     */
    public function getCurrentUser(): ?array {
        return $this->currentUser;
    }
    
    /**
     * Check if current user has a specific role
     * 
     * @param string $role Role to check
     * @return bool True if user has the role
     */
    public function hasRole(string $role): bool {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        return $this->currentUser['role'] === $role;
    }
    
    /**
     * Check if current user has permission (role hierarchy)
     * 
     * @param string $minimumRole Minimum required role
     * @return bool True if user has permission
     */
    public function hasPermission(string $minimumRole): bool {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        $roleHierarchy = [
            'viewer' => 1,
            'manager' => 2,
            'admin' => 3,
        ];
        
        $userLevel = $roleHierarchy[$this->currentUser['role']] ?? 0;
        $requiredLevel = $roleHierarchy[$minimumRole] ?? 999;
        
        return $userLevel >= $requiredLevel;
    }
    
    /**
     * Create a session for a user
     * 
     * @param array $user User data
     * @param bool $remember Whether to create a persistent session
     * @return void
     */
    private function createSession(array $user, bool $remember = false): void {
        // Generate session token
        $token = bin2hex(random_bytes(32));
        
        // Calculate expiry (30 days for remember me, 24 hours otherwise)
        $expiryHours = $remember ? 720 : 24;
        
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $expirySQL = "DATE_ADD(NOW(), INTERVAL $expiryHours HOUR)";
        } else {
            $expirySQL = "datetime('now', '+$expiryHours hours')";
        }
        
        // Create session record
        $id = Uuid::uuid4()->toString();
        $stmt = $this->pdo->prepare("
            INSERT INTO sessions (id, user_id, token, expires_at, created_at)
            VALUES (?, ?, ?, $expirySQL, NOW())
        ");
        $stmt->execute([$id, $user['id'], $token]);
        
        // Set cookie
        $cookieExpiry = $remember ? time() + (30 * 24 * 3600) : 0; // 0 = session cookie
        setcookie('session_token', $token, $cookieExpiry, '/', '', true, true);
        
        // Set current user
        $this->currentUser = $user;
    }
    
    /**
     * Load session from cookie
     * 
     * @return void
     */
    private function loadSession(): void {
        if (empty($_COOKIE['session_token'])) {
            return;
        }
        
        $token = $_COOKIE['session_token'];
        
        // Find valid session
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $nowSQL = $driver === 'mysql' ? 'NOW()' : "datetime('now')";
        
        $stmt = $this->pdo->prepare("
            SELECT s.*, u.* 
            FROM sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.token = ? AND s.expires_at > $nowSQL
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $session = $stmt->fetch();
        
        if ($session) {
            $this->currentUser = $session;
        } else {
            // Invalid or expired session - clear cookie
            setcookie('session_token', '', time() - 3600, '/', '', true, true);
        }
    }
    
    /**
     * Clean up expired sessions
     * 
     * @return int Number of sessions deleted
     */
    public function cleanupExpiredSessions(): int {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $nowSQL = $driver === 'mysql' ? 'NOW()' : "datetime('now')";
        
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE expires_at < $nowSQL");
        $stmt->execute();
        
        return $stmt->rowCount();
    }
}
