<?php
declare(strict_types=1);

use Permits\DatabaseMaintenance;
use Permits\Phase4DatabaseMaintenance;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

[, $db] = require dirname(__DIR__) . '/src/bootstrap.php';

$results = [
    'email_queue' => DatabaseMaintenance::ensureEmailQueueTable($db),
    'login_attempts' => DatabaseMaintenance::ensureLoginAttemptsTable($db),
    'public_rate_limits' => DatabaseMaintenance::ensurePublicRateLimitsTable($db),
    'worker_locks' => DatabaseMaintenance::ensureWorkerLocksTable($db),
    'forms' => DatabaseMaintenance::ensureFormsColumns($db),
    'form_identifiers' => DatabaseMaintenance::ensureFormsUniqueIndexes($db),
    'templates' => DatabaseMaintenance::ensureFormTemplateColumns($db),
    'activity_log' => DatabaseMaintenance::ensureActivityLogColumns($db),
    'form_events' => Phase4DatabaseMaintenance::ensureFormEventsTable($db),
    'permit_links' => Phase4DatabaseMaintenance::ensurePermitLinksTable($db),
];

$errors = [];
foreach ($results as $table => $result) {
    $errors = array_merge($errors, $result['errors']);
    fwrite(STDOUT, sprintf("%s: added [%s], already present [%s]\n", $table, implode(', ', $result['added']), implode(', ', $result['alreadyPresent'])));
}
if ($errors) {
    foreach ($errors as $error) { fwrite(STDERR, $error . PHP_EOL); }
    exit(1);
}
