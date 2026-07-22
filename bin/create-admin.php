<?php
declare(strict_types=1);

use Permits\AdminProvisioner;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$usage = static function ($stream): void {
    fwrite($stream, <<<TEXT
Create the first Permits administrator with a generated one-time password.

Usage:
  php bin/create-admin.php --email=owner@example.com [--name="Site Administrator"]

Options:
  --email   Required administrator email address.
  --name    Display name (defaults to "Administrator").
  --help    Show this help.

This command never replaces or resets an existing account.

TEXT);
};

$options = getopt('', ['email:', 'name:', 'help']);
if (isset($options['help'])) {
    $usage(STDOUT);
    exit(0);
}

$email = is_string($options['email'] ?? null) ? $options['email'] : '';
$name = is_string($options['name'] ?? null) ? $options['name'] : 'Administrator';
if ($email === '') {
    fwrite(STDERR, "Error: --email is required.\n\n");
    $usage(STDERR);
    exit(2);
}

try {
    [, $db] = require dirname(__DIR__) . '/src/bootstrap.php';
    $administrator = AdminProvisioner::createFirstAdmin($db->pdo, $email, $name);

    fwrite(STDOUT, <<<TEXT
Administrator created successfully.

Email: {$administrator['email']}
One-time password: {$administrator['password']}

Copy this password now; it will not be shown again. Sign in over HTTPS and change it immediately from My Account.

TEXT);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
