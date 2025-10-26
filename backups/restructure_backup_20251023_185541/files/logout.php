<?php
/**
 * Logout Handler
 * 
 * File Path: /logout.php
 * Description: Handles user logout with activity logging
 * Created: 22/10/2025
 * Last Modified: 22/10/2025
 * 
 * Features:
 * - Logs user logout
 * - Clears session
 * - Redirects to login page
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/Auth.php';
require __DIR__ . '/src/ActivityLogger.php';

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
$auth = new Auth($db);
$logger = new \Permits\ActivityLogger($db);

// Get current user before logging out
$currentUser = $auth->getCurrentUser();

if ($currentUser) {
    // Log the logout
    $logger->setUser($currentUser['id'], $currentUser['email']);
    $logger->logLogout($currentUser['id'], $currentUser['email']);
}

// Perform logout
$auth->logout();

// Redirect to login page
header('Location: /login.php');
exit;