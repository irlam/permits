<?php
declare(strict_types=1);

use Minishlink\WebPush\VAPID;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
    $keys = VAPID::createVapidKeys();
} catch (Throwable $e) {
    fwrite(STDERR, "Unable to generate VAPID keys: {$e->getMessage()}\n");
    exit(1);
}

echo "VAPID_PUBLIC_KEY=\"{$keys['publicKey']}\"\n";
echo "VAPID_PRIVATE_KEY=\"{$keys['privateKey']}\"\n";
echo "\nCopy both values into .env and keep the private key secret.\n";
echo "Set VAPID_SUBJECT to a contact such as mailto:safety@example.com.\n";
