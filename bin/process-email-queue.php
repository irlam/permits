<?php
declare(strict_types=1);

date_default_timezone_set('Europe/London');

require __DIR__ . '/../vendor/autoload.php';

use Permits\Email;
use Permits\EmailQueueProcessor;
use Permits\Mailer;

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

$options = getopt('', ['limit::']);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 50;

echo '[' . date('Y-m-d H:i:s') . "] Processing email queue (limit={$limit})...\n";

$email   = new Email($db, $root);
$mailer  = new Mailer();
$worker  = new EmailQueueProcessor($email, $mailer);
$result  = $worker->process($limit);

$errors = $result['errors'];
$processed = $result['processed'];
$sent = $result['sent'];
$failed = $result['failed'];

echo "Processed: {$processed}\n";
echo "Sent     : {$sent}\n";
echo "Failed   : {$failed}\n";

if (!empty($errors)) {
    echo "Errors:\n";
    foreach ($errors as $message) {
        echo "  - {$message}\n";
    }
}

echo '[' . date('Y-m-d H:i:s') . "] Email queue complete.\n";

exit($failed > 0 ? 1 : 0);
