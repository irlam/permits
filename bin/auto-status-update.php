<?php
declare(strict_types=1);

/**
 * Scheduled permit expiry worker.
 *
 * Recommended cron frequency:
 *   * * * * * /usr/bin/php /path/to/permits/bin/auto-status-update.php
 *
 * Expiry is deliberately owned by this CLI worker. Web requests remain
 * read-only so an expiry sweep cannot race approval or closure requests.
 * After the status sweep, retry-safe STOP WORK alerts are delivered for
 * permits that expired within the last 24 hours.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/check-expiry.php';

$startedAt = date('Y-m-d H:i:s');
fwrite(STDOUT, "[{$startedAt}] Permit expiry worker starting...\n");

try {
    $updated = check_and_expire_permits($db, true);
    fwrite(STDOUT, "Expired {$updated} permit(s).\n");

    $notifier = new \Permits\PermitExpiryNotifier($db);
    $alerts = $notifier->notifyRecentlyExpired(1440);
    printf(
        "Expiry alerts — permits: %d | emails: %d | push: %d | errors: %d | pruned: %d%s\n",
        $alerts['permits'],
        $alerts['email_sent'],
        $alerts['push_sent'],
        $alerts['errors'],
        $alerts['pruned'],
        $alerts['lock_skipped'] ? ' | another alert worker already running' : ''
    );

    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] Permit expiry worker complete.\n");
    exit($alerts['errors'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    error_log('Permit expiry worker failed: ' . $error->getMessage());
    fwrite(STDERR, "Permit expiry worker failed. Check the application error log.\n");
    exit(1);
}
