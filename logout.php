<?php
/**
 * Logout Page - Simple Version
 * 
 * File Path: /logout.php
 * Description: Simple logout without Auth class
 * Created: 24/10/2025
 * Last Modified: 24/10/2025
 * 
 * Features:
 * - Destroys session
 * - Clears all session data
 * - Redirects to login
 */

// Load bootstrap
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

// Start session
session_start();

// Get user info before destroying session (for logging)
$userId = $_SESSION['user_id'] ?? null;
$userEmail = $_SESSION['user_email'] ?? null;

// Log logout if activity logger function exists
if ($userId && function_exists('logActivity')) {
    logActivity(
        'user_logout',
        'auth',
        'user',
        $userId,
        "User logged out: {$userEmail}"
    );
}

// Destroy the session
$_SESSION = array();

// Delete the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy session
session_destroy();

// Redirect to login page
header('Location: /login.php');
exit;