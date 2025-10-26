<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Provide sane defaults for environment-dependent code during tests.
$_ENV['APP_URL'] = $_ENV['APP_URL'] ?? 'http://localhost';
