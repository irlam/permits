<?php
declare(strict_types=1);

use Permits\ProductionHealthCheck;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

echo "=== Permits Production Health Check ===\n\n";

try {
    [$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';
} catch (Throwable $e) {
    error_log('Production health check bootstrap failure [' . get_class($e) . '].');
    fwrite(STDERR, "[FAIL] Application bootstrap or database connection failed. Check the private server error log.\n");
    exit(1);
}

$sessionCookieParameters = session_get_cookie_params();
$checker = new ProductionHealthCheck(
    $db->pdo,
    $root,
    [
        'app_env' => $app->config('APP_ENV', 'production'),
        'app_url' => $app->config('APP_URL', ''),
        'app_debug' => $app->config('APP_DEBUG', false),
        'session_cookie_secure' => $sessionCookieParameters['secure'] ?? false,
        'session_cookie_httponly' => $sessionCookieParameters['httponly'] ?? false,
        'db_driver' => $app->config('DB_DRIVER', ''),
        'backup_path' => (string)($_ENV['BACKUP_PATH'] ?? ''),
    ]
);
$report = $checker->run();

foreach ($report['checks'] as $check) {
    echo ($check['ok'] ? '[PASS] ' : '[FAIL] ') . $check['message'] . "\n";
}

$failureCount = count($report['failures']);
echo "\n=== Summary ===\n";
if ($failureCount === 0) {
    echo "All production health checks passed.\n";
} else {
    echo "{$failureCount} production health check(s) failed. Correct the failures above before release.\n";
}

exit(ProductionHealthCheck::exitCode($report));
