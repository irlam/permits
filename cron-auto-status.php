<?php
/**
 * Web-Accessible Cron Endpoint
 * 
 * Description: Simple web endpoint for Plesk scheduled tasks
 * Name: cron-auto-status.php
 * 
 * Usage: https://yourdomain.com/cron-auto-status.php?key=YOUR_SECRET_KEY
 */

// Security check
$expectedKey = $_ENV['ADMIN_KEY'] ?? 'change-this-secret-key';
$providedKey = $_GET['key'] ?? '';

if ($providedKey !== $expectedKey) {
    http_response_code(403);
    exit('Forbidden');
}

// Run the auto-status script
require __DIR__ . '/bin/auto-status-update.php';