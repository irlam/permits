<?php
/**
 * Test Push Notification Script
 * 
 * Description: Sends a test push notification to all subscribed users
 * Name: test-push-notification.php
 * 
 * What it does:
 * - Retrieves all push subscriptions from the database
 * - Sends a test notification to each subscriber
 * - Reports success/failure for each subscription
 * - Cleans up invalid/expired subscriptions
 * 
 * Usage: php bin/test-push-notification.php
 * 
 * Custom message: php bin/test-push-notification.php "Your custom message"
 */

declare(strict_types=1);

date_default_timezone_set('Europe/London');

// --- Bootstrap (PDO + ENV) ---
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
[$app, $db] = require $root . '/src/bootstrap.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║               Test Push Notification Sender                        ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// --- Check VAPID configuration ---
$vapidPublic  = $_ENV['VAPID_PUBLIC_KEY']  ?? '';
$vapidPrivate = $_ENV['VAPID_PRIVATE_KEY'] ?? '';
$vapidSubject = $_ENV['VAPID_SUBJECT']     ?? '';

if ($vapidPublic === '' || $vapidPrivate === '') {
    echo "✗ ERROR: VAPID keys not configured in .env file\n";
    echo "\n";
    echo "Please run the following steps:\n";
    echo "  1. Generate VAPID keys: php generate_vapid.php\n";
    echo "  2. Copy the keys to your .env file\n";
    echo "  3. Set VAPID_SUBJECT in .env (e.g., 'mailto:your@email.com')\n";
    echo "\n";
    exit(1);
}

if ($vapidSubject === '' || $vapidSubject === 'mailto:ops@defecttracker.uk') {
    echo "⚠ WARNING: VAPID_SUBJECT not properly configured in .env\n";
    echo "  Current value: {$vapidSubject}\n";
    echo "  Please update it to your contact email\n";
    echo "\n";
}

echo "✓ VAPID configuration found\n";
echo "  Public Key: " . substr($vapidPublic, 0, 20) . "...\n";
echo "  Subject: {$vapidSubject}\n";
echo "\n";

// --- Initialize WebPush ---
$webPush = new WebPush([
    'VAPID' => [
        'subject'    => $vapidSubject,
        'publicKey'  => $vapidPublic,
        'privateKey' => $vapidPrivate,
    ],
]);

// --- Load subscriptions ---
$pdo = $db->pdo;
echo "Loading push subscriptions from database...\n";

$stmt = $pdo->query("SELECT id, endpoint, p256dh, auth, endpoint_hash, user_id FROM push_subscriptions");
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$subs) {
    echo "✗ No push subscriptions found in the database\n";
    echo "\n";
    echo "To subscribe to push notifications:\n";
    echo "  1. Open your application in a browser\n";
    echo "  2. Open the browser console (F12)\n";
    echo "  3. Run: window.subscribeToPush()\n";
    echo "  4. Grant notification permission when prompted\n";
    echo "\n";
    exit(0);
}

echo "✓ Found " . count($subs) . " subscription(s)\n";
echo "\n";

// De-duplicate by endpoint_hash
$dedup = [];
foreach ($subs as $s) {
    $h = $s['endpoint_hash'] ?: hash('sha256', $s['endpoint']);
    $dedup[$h] = [
        'id'       => $s['id'],
        'endpoint' => $s['endpoint'],
        'p256dh'   => $s['p256dh'],
        'auth'     => $s['auth'],
        'hash'     => $h,
        'user_id'  => $s['user_id'] ?? 'anonymous',
    ];
}
$subs = array_values($dedup);

// --- Prepare notification payload ---
$customMessage = $argv[1] ?? null;

$appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
if ($appUrl === '') {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $appUrl = $scheme . '://' . $host;
}

$title = '🔔 Test Push Notification';
$body = $customMessage ?: 'This is a test notification from the Permits System. If you can see this, push notifications are working correctly!';
$url = $appUrl . '/';

$payload = json_encode([
    'title' => $title,
    'body'  => $body,
    'url'   => $url,
    'icon'  => $appUrl . '/assets/pwa/icon-192.png',
    'badge' => $appUrl . '/assets/pwa/icon-32.png',
    'tag'   => 'test-notification',
], JSON_UNESCAPED_SLASHES);

echo "Notification Details:\n";
echo "  Title: {$title}\n";
echo "  Body: {$body}\n";
echo "  URL: {$url}\n";
echo "\n";

// --- Queue notifications ---
echo "Sending notifications...\n";
echo "─────────────────────────────────────────────────────────────────────\n";

foreach ($subs as $s) {
    $subscription = new Subscription(
        $s['endpoint'],
        $s['p256dh'],
        $s['auth']
    );
    $webPush->queueNotification($subscription, $payload);
}

// --- Send & collect reports ---
$sent = 0;
$errors = 0;
$pruned = 0;

foreach ($webPush->flush() as $report) {
    $endpoint = $report->getRequest()->getUri()->__toString();
    $ok = $report->isSuccess();

    // Find the subscription info for this endpoint
    $subInfo = null;
    foreach ($subs as $s) {
        if ($s['endpoint'] === $endpoint) {
            $subInfo = $s;
            break;
        }
    }
    
    $userId = $subInfo ? $subInfo['user_id'] : 'unknown';
    $displayEndpoint = substr($endpoint, 0, 60) . '...';

    if ($ok) {
        $sent++;
        echo "  ✓ Sent to user: {$userId}\n";
        echo "    Endpoint: {$displayEndpoint}\n";
        continue;
    }

    $errors++;
    $statusCode = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;
    $reason = $report->getReason() ?? 'unknown error';

    echo "  ✗ Failed for user: {$userId}\n";
    echo "    Status: {$statusCode} - {$reason}\n";
    echo "    Endpoint: {$displayEndpoint}\n";

    // If it's a "gone" endpoint, remove it
    if (in_array($statusCode, [404, 410], true)) {
        $hash = hash('sha256', $endpoint);
        $del = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = :h");
        $del->execute([':h' => $hash]);
        $pruned++;
        echo "    → Removed invalid subscription\n";
    }
}

echo "─────────────────────────────────────────────────────────────────────\n";
echo "\n";

// --- Summary ---
echo "Summary:\n";
echo "  Total subscriptions: " . count($subs) . "\n";
echo "  Successfully sent: {$sent}\n";
echo "  Errors: {$errors}\n";
echo "  Cleaned up: {$pruned}\n";
echo "\n";

if ($sent > 0) {
    echo "✓ Test notification(s) sent successfully!\n";
    echo "  Check your browser/device for the notification.\n";
} elseif ($errors > 0) {
    echo "⚠ All notifications failed to send.\n";
    echo "  Please check your VAPID configuration and subscription endpoints.\n";
} else {
    echo "⚠ No notifications were sent.\n";
}

echo "\n";

exit(($errors > 0 && $sent === 0) ? 1 : 0);
