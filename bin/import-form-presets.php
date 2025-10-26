<?php
/**
 * Import stock form presets into the form_templates table.
 *
 * Usage: php bin/import-form-presets.php
 *
 * The script will ensure the target table has the required columns and then
 * hand off to the reusable FormTemplateSeeder to process JSON presets.
 */

use Permits\DatabaseMaintenance;
use Permits\FormTemplateSeeder;

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

$directory = $root . '/templates/form-presets';

fwrite(STDOUT, "Ensuring form_templates columns...\n");
try {
    $columnResult = DatabaseMaintenance::ensureFormTemplateColumns($db);
    foreach ($columnResult['added'] as $column) {
        fwrite(STDOUT, "✔ Added column {$column}\n");
    }
    foreach ($columnResult['alreadyPresent'] as $column) {
        fwrite(STDOUT, "• Column already present: {$column}\n");
    }
    foreach ($columnResult['errors'] as $error) {
        fwrite(STDERR, "✗ {$error}\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "✗ Failed to verify columns: {$e->getMessage()}\n");
    exit(1);
}

fwrite(STDOUT, "\nImporting presets from {$directory}...\n");

try {
    $result = FormTemplateSeeder::importFromDirectory($db, $directory);
} catch (\RuntimeException $e) {
    fwrite(STDERR, "✗ {$e->getMessage()}\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, "✗ Unexpected error: {$e->getMessage()}\n");
    exit(1);
}

foreach ($result['imported'] as $templateId) {
    fwrite(STDOUT, "✔ Imported template {$templateId}\n");
}

if ($result['processed'] === 0 && empty($result['errors'])) {
    fwrite(STDOUT, "No preset files found.\n");
} else {
    fwrite(STDOUT, "\nCompleted. {$result['processed']} templates processed.\n");
}

foreach ($result['errors'] as $error) {
    fwrite(STDERR, "✗ {$error}\n");
}

if (!empty($result['errors'])) {
    exit(1);
}

exit(0);
