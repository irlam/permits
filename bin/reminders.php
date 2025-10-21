<?php
require __DIR__ . '/../vendor/autoload.php';
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

$publicKey  = $_ENV['VAPID_PUBLIC_KEY'] ?? '';
$privateKey = $_ENV['VAPID_PRIVATE_KEY'] ?? '';
$subject    = $_ENV['VAPID_SUBJECT'] ?? 'mailto:ops@example.com';
$webPush = new WebPush(['VAPID' => ['subject'=>$subject,'publicKey'=>$publicKey,'privateKey'=>$privateKey]]);

// Permits expiring in the next hour
$q = $db->pdo->query("SELECT id, ref, valid_to FROM forms WHERE status IN ('issued','active') AND valid_to IS NOT NULL AND datetime(valid_to) <= datetime('now', '+1 hour')");
$due = $q->fetchAll();

foreach ($due as $row) {
  $payload = json_encode([
    'title' => 'Permit expiring soon',
    'body'  => "Ref {$row['ref']} expires at {$row['valid_to']}",
    'url'   => ($_ENV['APP_URL'] ?? '') . '/?form=' . $row['id']
  ]);
  $subs = $db->pdo->query("SELECT endpoint, p256dh, auth FROM push_subscriptions")->fetchAll();
  foreach ($subs as $s) {
    $webPush->queueNotification(new Subscription($s['endpoint'], $s['p256dh'], $s['auth']), $payload);
  }
}
foreach ($webPush->flush() as $r) { /* log if needed */ }
echo "Reminders sent.\n";
