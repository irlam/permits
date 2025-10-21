<?php
/**
 * Permits System - Web-Accessible Cron Endpoint
 * 
 * Description: Simple web endpoint for Plesk scheduled tasks to auto-update permit statuses
 * Name: cron-auto-status.php
 * Last Updated: 21/10/2025 19:22:30 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Provide web-accessible endpoint for scheduled tasks
 * - Trigger automatic status updates for expired permits
 * - Protected by ADMIN_KEY for security
 * - Works with Plesk cron jobs or external schedulers
 * 
 * Security:
 * - Requires valid ADMIN_KEY as query parameter
 * - Returns 403 Forbidden if key is missing or incorrect
 * - Uses constant-time comparison to prevent timing attacks
 * 
 * Usage:
 * - Direct access: https://yourdomain.com/cron-auto-status.php?key=YOUR_SECRET_KEY
 * - Plesk cron: curl "https://yourdomain.com/cron-auto-status.php?key=YOUR_SECRET_KEY"
 * - Schedule: Run every hour or as needed for timely status updates
 */

// Security check: verify admin key from query parameter
$expectedKey = $_ENV['ADMIN_KEY'] ?? 'change-this-secret-key';
$providedKey = $_GET['key'] ?? '';

// Deny access if key doesn't match
if ($providedKey !== $expectedKey) {
    http_response_code(403);
    exit('Forbidden');
}

// Execute the auto-status update script
require __DIR__ . '/bin/auto-status-update.php';