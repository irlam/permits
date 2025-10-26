<?php
/**
 * Ensure form_templates has the required metadata columns.
 *
 * Usage: php bin/ensure-form-template-columns.php
 */

use Permits\DatabaseMaintenance;

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

fwrite(STDOUT, "Checking form_templates schema...\n");

try {
    $result = DatabaseMaintenance::ensureFormTemplateColumns($db);
} catch (\Throwable $e) {
    fwrite(STDERR, "✗ Failed to inspect schema: {$e->getMessage()}\n");
    exit(1);
}

foreach ($result['added'] as $column) {
    fwrite(STDOUT, "✔ Added column {$column}\n");
}

foreach ($result['alreadyPresent'] as $column) {
    fwrite(STDOUT, "• Column already present: {$column}\n");
}

foreach ($result['errors'] as $error) {
    fwrite(STDERR, "✗ {$error}\n");
}

if (!empty($result['errors'])) {
    exit(1);
}

fwrite(STDOUT, "Schema check complete.\n");
exit(0);
