<?php
declare(strict_types=1);

use Permits\DatabaseMaintenance;

[, $db] = require dirname(__DIR__) . '/src/bootstrap.php';

$results = [
    'forms' => DatabaseMaintenance::ensureFormsColumns($db),
    'templates' => DatabaseMaintenance::ensureFormTemplateColumns($db),
];

$errors = array_merge($results['forms']['errors'], $results['templates']['errors']);
foreach ($results as $table => $result) {
    fwrite(STDOUT, sprintf("%s: added [%s], already present [%s]\n", $table, implode(', ', $result['added']), implode(', ', $result['alreadyPresent'])));
}
if ($errors) {
    foreach ($errors as $error) { fwrite(STDERR, $error . PHP_EOL); }
    exit(1);
}
