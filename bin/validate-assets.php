<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
$files = glob($root . '/templates/form-presets/*.json') ?: [];
$files[] = $root . '/manifest.webmanifest';
$errors = [];

foreach ($files as $path) {
    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        $errors[] = 'Unable to read ' . basename($path);
        continue;
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            $errors[] = basename($path) . ' must contain a JSON object or array.';
        }
    } catch (JsonException $exception) {
        $errors[] = basename($path) . ': ' . $exception->getMessage();
    }
}

if ($files === [$root . '/manifest.webmanifest']) {
    $errors[] = 'No permit preset JSON files were found.';
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}

printf("Validated %d JSON assets.\n", count($files));
