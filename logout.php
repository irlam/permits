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
require_once __DIR__ . '/src/Auth.php';

$auth = new Auth($db);
$auth->logout();

// Redirect to login page
header('Location: ' . $app->url('login.php'));
exit;
