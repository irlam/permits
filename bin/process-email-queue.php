<?php
declare(strict_types=1);

date_default_timezone_set('Europe/London');

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use Permits\Email;
use Permits\EmailQueueProcessor;
use Permits\Mailer;

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

$options = getopt('', ['limit::']);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 50;

echo '[' . date('Y-m-d H:i:s') . "] Processing email queue (limit={$limit})...\n";

$email   = new Email($db, $root);
$mailer  = Mailer::fromDatabase($db);
$worker  = new EmailQueueProcessor($email, $mailer);
try {
    $result = $worker->process($limit);
} catch (Throwable $e) {
    error_log('[Permits email queue] Worker failed: ' . $e->getMessage());
    fwrite(STDERR, "Email queue could not be processed. Check the private server log and run the database migration.\n");
    exit(1);
}

if ($result['disabled']) {
    echo "Outbound email is disabled; queued notifications were left unchanged.\n";
    exit(0);
}

$errors = $result['errors'];
$processed = $result['processed'];
$sent = $result['sent'];
$failed = $result['failed'];
$retrying = $result['retrying'];

echo "Processed: {$processed}\n";
echo "Sent     : {$sent}\n";
echo "Failed   : {$failed}\n";
echo "Retrying : {$retrying}\n";

if (!empty($errors)) {
    echo "Errors:\n";
    foreach ($errors as $message) {
        echo "  - {$message}\n";
    }
}

echo '[' . date('Y-m-d H:i:s') . "] Email queue complete.\n";

exit($failed > 0 ? 1 : 0);
