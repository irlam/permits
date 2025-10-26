<?php
require __DIR__ . '/vendor/autoload.php';
use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();
echo "Public:  {$keys['publicKey']}\n";
echo "Private: {$keys['privateKey']}\n";
