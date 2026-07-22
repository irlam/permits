<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$failed = false;
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if ($file->getExtension() !== 'php' || str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) { continue; }
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path);
    exec($command, $output, $code);
    if ($code !== 0) { $failed = true; fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL); }
    $output = [];
}
exit($failed ? 1 : 0);
