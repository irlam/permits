<?php
declare(strict_types=1);

namespace Permits;

/**
 * CSRF (Cross-Site Request Forgery) Protection
 * 
 * Provides token generation and validation to prevent CSRF attacks
 * on form submissions and state-changing operations.
 * 
 * @package Permits
 */
class Csrf
{
    /** Session key for storing CSRF tokens */
    private const SESSION_KEY = 'csrf_tokens';
    
    /** Maximum number of tokens to store per session */
    private const MAX_TOKENS = 10;
    
    /** Token validity in seconds (default: 1 hour) */
    private const TOKEN_LIFETIME = 3600;

    /**
     * Initialize session if not already started
     */
    private static function ensureSession(): void
    {
        if (\session_status() === \PHP_SESSION_NONE) {
            if (\function_exists('startSession')) {
                \startSession();
            } else {
                \session_start();
            }
        }
    }

    /**
     * Generate a new CSRF token
     * 
     * @param string $action Optional action identifier for token scoping
     * @return string The generated token
     */
    public static function generateToken(string $action = 'default'): string
    {
        self::ensureSession();
        
        // Generate a random token
        $token = \bin2hex(\random_bytes(32));
        
        // Initialize tokens array if not exists
        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        
        // Store token with timestamp and action
        $_SESSION[self::SESSION_KEY][$token] = [
            'action' => $action,
            'time' => \time(),
        ];
        
        // Clean up old tokens to prevent session bloat
        self::cleanupTokens();
        
        return $token;
    }

    /**
     * Validate a CSRF token
     * 
     * @param string $token The token to validate
     * @param string $action Optional action identifier for token scoping
     * @param bool $consumeToken Whether to remove token after validation (default: true)
     * @return bool True if token is valid, false otherwise
     */
    public static function validateToken(string $token, string $action = 'default', bool $consumeToken = true): bool
    {
        self::ensureSession();
        
        // Check if token exists in session
        if (!isset($_SESSION[self::SESSION_KEY][$token])) {
            return false;
        }
        
        $tokenData = $_SESSION[self::SESSION_KEY][$token];
        
        // Validate action matches
        if ($tokenData['action'] !== $action) {
            return false;
        }
        
        // Validate token hasn't expired
        if ((\time() - $tokenData['time']) > self::TOKEN_LIFETIME) {
            // Remove expired token
            unset($_SESSION[self::SESSION_KEY][$token]);
            return false;
        }
        
        // Remove token after use (one-time token)
        if ($consumeToken) {
            unset($_SESSION[self::SESSION_KEY][$token]);
        }
        
        return true;
    }

    /**
     * Clean up expired tokens from session
     */
    private static function cleanupTokens(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !\is_array($_SESSION[self::SESSION_KEY])) {
            return;
        }
        
        $now = \time();
        $tokens = $_SESSION[self::SESSION_KEY];
        
        // Remove expired tokens
        foreach ($tokens as $token => $data) {
            if (($now - $data['time']) > self::TOKEN_LIFETIME) {
                unset($_SESSION[self::SESSION_KEY][$token]);
            }
        }
        
        // Limit number of tokens to prevent session bloat
        if (\count($_SESSION[self::SESSION_KEY]) > self::MAX_TOKENS) {
            // Keep only the most recent tokens
            $_SESSION[self::SESSION_KEY] = \array_slice(
                $_SESSION[self::SESSION_KEY],
                -self::MAX_TOKENS,
                null,
                true
            );
        }
    }

    /**
     * Get token from request (POST or header)
     * 
     * @return string|null The token if found, null otherwise
     */
    public static function getTokenFromRequest(): ?string
    {
        // Check POST data
        if (isset($_POST['csrf_token']) && \is_string($_POST['csrf_token'])) {
            return $_POST['csrf_token'];
        }
        
        // Check custom header
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && \is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        
        return null;
    }

    /**
     * Validate CSRF token from request
     * 
     * @param string $action Optional action identifier
     * @return bool True if valid, false otherwise
     */
    public static function validateRequest(string $action = 'default'): bool
    {
        $token = self::getTokenFromRequest();
        
        if ($token === null) {
            return false;
        }
        
        return self::validateToken($token, $action);
    }

    /**
     * Generate hidden input field for forms
     * 
     * @param string $action Optional action identifier
     * @return string HTML input field
     */
    public static function getFormField(string $action = 'default'): string
    {
        $token = self::generateToken($action);
        return '<input type="hidden" name="csrf_token" value="' . \htmlspecialchars($token, \ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Clear all tokens from session
     */
    public static function clearTokens(): void
    {
        self::ensureSession();
        unset($_SESSION[self::SESSION_KEY]);
    }
}
