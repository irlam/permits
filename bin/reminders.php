<?php
/**
 * Permits System - Push Notification Reminders Script
 * 
 * Description: Sends push notifications for permits expiring soon
 * Name: reminders.php
 * Last Updated: 21/10/2025 19:22:30 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Send push notifications to subscribed users
 * - Alert about permits expiring within the next hour
 * - Use Web Push API with VAPID authentication
 * - Run as scheduled task (cron job)
 * 
 * Features:
 * - Queries forms with status 'issued' or 'active'
 * - Finds permits expiring in next 60 minutes
 * - Sends browser push notifications to all subscribers
 * - Includes permit reference and expiry time
 * - Deep link to specific permit in notification
 * 
 * Configuration:
 * - Requires VAPID_PUBLIC_KEY in .env
 * - Requires VAPID_PRIVATE_KEY in .env
 * - Requires VAPID_SUBJECT (mailto:) in .env
 * 
 * Usage:
 * - Command line: php bin/reminders.php
 * - Cron: (star)/30 * * * * php /path/to/bin/reminders.php (every 30 mins)
 * - Recommended: Run every 30 minutes for timely alerts
 */

require __DIR__ . '/../vendor/autoload.php';
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// Bootstrap application to access database and environment
[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

// Load VAPID credentials from environment for Web Push authentication
$publicKey  = $_ENV['VAPID_PUBLIC_KEY'] ?? '';
$privateKey = $_ENV['VAPID_PRIVATE_KEY'] ?? '';
$subject    = $_ENV['VAPID_SUBJECT'] ?? 'mailto:ops@example.com';

// Initialize Web Push client with VAPID configuration
$webPush = new WebPush(['VAPID' => ['subject'=>$subject,'publicKey'=>$publicKey,'privateKey'=>$privateKey]]);

// Query for permits expiring in the next hour
// Only checks issued/active permits with a valid_to date
$q = $db->pdo->query("SELECT id, ref, valid_to FROM forms WHERE status IN ('issued','active') AND valid_to IS NOT NULL AND datetime(valid_to) <= datetime('now', '+1 hour')");
$due = $q->fetchAll();

// Send notification for each expiring permit
foreach ($due as $row) {
  // Build notification payload with title, body, and deep link
  $payload = json_encode([
    'title' => 'Permit expiring soon',
    'body'  => "Ref {$row['ref']} expires at {$row['valid_to']}",
    'url'   => ($_ENV['APP_URL'] ?? '') . '/?form=' . $row['id']
  ]);
  
  // Get all push subscriptions from database
  $subs = $db->pdo->query("SELECT endpoint, p256dh, auth FROM push_subscriptions")->fetchAll();
  
  // Queue notification for each subscriber
  foreach ($subs as $s) {
    $webPush->queueNotification(new Subscription($s['endpoint'], $s['p256dh'], $s['auth']), $payload);
  }
}

// Send all queued notifications
foreach ($webPush->flush() as $r) { /* log results if needed */ }

// Output success message for cron logs
echo "Reminders sent.\n";
