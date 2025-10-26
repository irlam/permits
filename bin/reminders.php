<?php
/**
 * bin/reminders.php
 *
 * Sends push notifications for permits expiring soon.
 *
 * Usage (CLI):
 *   php bin/reminders.php           # default 60-minute lookahead
 *   php bin/reminders.php 45        # custom minutes
 *
 * CRON example (every 15 minutes):
 *   */15 * * * * /usr/bin/php /path/to/httpdocs/bin/reminders.php 60 >/dev/null 2>&1
 */

declare(strict_types=1);

date_default_timezone_set('Europe/London');

// --- Bootstrap (PDO + ENV) ---
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
[$app, $db] = require $root . '/src/bootstrap.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// --- Config / arguments ---
$lookaheadMinutes = 60;
if (PHP_SAPI === 'cli' && isset($argv[1]) && is_numeric($argv[1])) {
    $lookaheadMinutes = max(1, (int)$argv[1]);
}

$appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
if ($appUrl === '') {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $appUrl = $scheme . '://' . $host;
}

$vapidPublic  = $_ENV['VAPID_PUBLIC_KEY']  ?? '';
$vapidPrivate = $_ENV['VAPID_PRIVATE_KEY'] ?? '';
$vapidSubject = $_ENV['VAPID_SUBJECT']     ?? '';

if ($vapidPublic === '' || $vapidPrivate === '') {
    fwrite(STDERR, "[reminders] Missing VAPID keys in .env (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY). Aborting.\n");
    exit(2);
}

$webPush = new WebPush([
    'VAPID' => [
        'subject'    => $vapidSubject ?: 'mailto:ops@example.com',
        'publicKey'  => $vapidPublic,
        'privateKey' => $vapidPrivate,
    ],
]);

$pdo    = $db->pdo;
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

// --- Find due permits within lookahead window ---
if ($driver === 'mysql') {
    // MySQL
    $stmt = $pdo->prepare("
        SELECT id, ref_number, valid_to
        FROM forms
        WHERE valid_to IS NOT NULL
          AND valid_to <= DATE_ADD(NOW(), INTERVAL :mins MINUTE)
        ORDER BY valid_to ASC
    ");
    $stmt->bindValue(':mins', $lookaheadMinutes, PDO::PARAM_INT);
} else {
    // SQLite (dev convenience)
    $stmt = $pdo->prepare("
        SELECT id, ref_number, valid_to
        FROM forms
        WHERE valid_to IS NOT NULL
          AND datetime(valid_to) <= datetime('now', '+' || :mins || ' minutes')
        ORDER BY valid_to ASC
    ");
    $stmt->bindValue(':mins', $lookaheadMinutes, PDO::PARAM_INT);
}

$stmt->execute();
$due = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$due) {
    echo "[reminders] No permits expiring in next {$lookaheadMinutes} minutes. Done.\n";
    exit(0);
}

// --- Load subscriptions ---
$subs = $pdo->query("SELECT endpoint, p256dh, auth, endpoint_hash FROM push_subscriptions")
            ->fetchAll(PDO::FETCH_ASSOC);

if (!$subs) {
    echo "[reminders] No push subscriptions found. Nothing to notify.\n";
    exit(0);
}

// De-dup by endpoint_hash (safety)
$dedup = [];
foreach ($subs as $s) {
    $h = $s['endpoint_hash'] ?: hash('sha256', $s['endpoint']);
    $dedup[$h] = [
        'endpoint' => $s['endpoint'],
        'p256dh'   => $s['p256dh'],
        'auth'     => $s['auth'],
        'hash'     => $h,
    ];
}
$subs = array_values($dedup);

// --- Queue notifications ---
$queued = 0;
foreach ($due as $row) {
    $permitId   = (string)$row['id'];
    $ref        = (string)$row['ref_number'];
    $validToRaw = (string)$row['valid_to'];

    // Format time for message (local)
    $validTo = '';
    try {
        $dt = new DateTimeImmutable($validToRaw, new DateTimeZone('Europe/London'));
        $validTo = $dt->format('D, d M Y H:i');
    } catch (Throwable $e) {
        $validTo = $validToRaw;
    }

    $url = $appUrl . '/?form=' . rawurlencode($permitId);

    $payload = json_encode([
        'title' => 'Permit expiring soon',
        'body'  => "Ref {$ref} expires at {$validTo}",
        'url'   => $url,
        // You can add any extra fields your SW expects, e.g. icon/badge/tag
    ], JSON_UNESCAPED_SLASHES);

    foreach ($subs as $s) {
        $subscription = new Subscription(
            $s['endpoint'],
            $s['p256dh'],
            $s['auth']
        );
        $webPush->queueNotification($subscription, $payload);
        $queued++;
    }
}

// --- Send & collect reports; prune dead endpoints ---
$sent = 0;
$errors = 0;
$pruned = 0;

foreach ($webPush->flush() as $report) {
    $endpoint = $report->getRequest()->getUri()->__toString();
    $ok = $report->isSuccess();

    if ($ok) {
        $sent++;
        continue;
    }

    $errors++;
    $reason = $report->getReason() ?? 'unknown error';

    // If it's a "gone" endpoint, remove it
    $statusCode = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;
    if (in_array($statusCode, [404, 410], true)) {
        // Compute hash & delete by endpoint_hash
        $hash = hash('sha256', $endpoint);
        $del = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = :h");
        $del->execute([':h' => $hash]);
        $pruned++;
    }

    // Optional: log details somewhere persistent
    // error_log("[reminders] Push failed ($statusCode): $reason — $endpoint");
}

// --- Summary ---
printf(
    "[reminders] Permits due: %d | Subscribers: %d | Queued: %d | Sent: %d | Errors: %d | Pruned: %d\n",
    count($due),
    count($subs),
    $queued,
    $sent,
    $errors,
    $pruned
);

exit(($errors > 0) ? 1 : 0);
