<?php
/**
 * Send recipient-scoped email reminders for permits nearing expiry.
 *
 * Suggested cron: 0 * * * * /usr/bin/php /path/to/bin/send-notifications.php
 */
declare(strict_types=1);

use Permits\Mailer;
use Permits\NotificationDeliveryLedger;
use Permits\WorkerLock;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
[, $db] = require dirname(__DIR__) . '/src/bootstrap.php';

$mailer = Mailer::fromDatabase($db);
if (!$mailer->isEnabled()) {
    echo '[' . date('Y-m-d H:i:s') . "] Outbound email is disabled; no expiry notifications were processed.\n";
    exit(0);
}

$configuredRecipients = trim((string) ($_ENV['NOTIFICATION_EMAILS'] ?? ''));
if ($configuredRecipients === '') {
    echo '[' . date('Y-m-d H:i:s') . "] No notification emails configured. Set NOTIFICATION_EMAILS in .env.\n";
    exit(0);
}

$recipients = [];
$invalidRecipientCount = 0;
foreach (explode(',', $configuredRecipients) as $candidate) {
    $candidate = trim($candidate);
    if ($candidate === '') {
        continue;
    }
    if (filter_var($candidate, FILTER_VALIDATE_EMAIL) === false || strlen($candidate) > 254) {
        $invalidRecipientCount++;
        continue;
    }
    $recipients[strtolower($candidate)] = $candidate;
}
$recipients = array_values($recipients);
if ($recipients === [] || count($recipients) > 100) {
    fwrite(STDERR, "[send-notifications] NOTIFICATION_EMAILS must contain between 1 and 100 valid addresses.\n");
    exit(2);
}

$pdo = $db->pdo;
try {
    $workerLock = WorkerLock::acquire($pdo, 'email-expiry-reminders', 1800);
} catch (Throwable $exception) {
    fwrite(STDERR, "[send-notifications] Unable to acquire the worker lock. Run php bin/migrate.php.\n");
    exit(2);
}
if ($workerLock === null) {
    echo "[send-notifications] Another email reminder worker is already running; this run was skipped.\n";
    exit(0);
}
register_shutdown_function(static function () use ($workerLock): void {
    try {
        $workerLock->release();
    } catch (Throwable $exception) {
        error_log('[send-notifications] Failed to release the worker lock.');
    }
});

$deliveryLedger = new NotificationDeliveryLedger($pdo);
$now = new DateTimeImmutable('now');
$twentyFourHours = $now->modify('+24 hours');
$sevenDays = $now->modify('+7 days');
$totalSent = 0;
$totalErrors = $invalidRecipientCount;
$totalSkipped = 0;

if ($invalidRecipientCount > 0) {
    fwrite(STDERR, sprintf(
        "[send-notifications] Ignoring %d invalid configured recipient(s).\n",
        $invalidRecipientCount
    ));
}

/**
 * @return list<array<string,mixed>>
 */
$findPermits = static function (DateTimeImmutable $start, DateTimeImmutable $end) use ($pdo): array {
    $statement = $pdo->prepare(<<<'SQL'
        SELECT id,
               COALESCE(NULLIF(ref_number, ''), NULLIF(ref, ''), id) AS ref,
               unique_link, template_id, site_block, valid_to, status
          FROM forms
         WHERE status IN ('issued', 'active', 'approved', 'open')
           AND valid_to > ?
           AND valid_to <= ?
         ORDER BY valid_to ASC, id ASC
    SQL);
    $statement->execute([
        $start->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s'),
    ]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
};

/**
 * Deliver one reminder window, recording a receipt immediately after every
 * successful recipient. A failed recipient has no receipt and will be retried
 * on the next cron run without duplicating successful recipients.
 *
 * @param list<array<string,mixed>> $permits
 * @param list<string> $recipients
 */
$sendWindow = static function (
    array $permits,
    string $window,
    int $fallbackDays,
    array $recipients
) use (
    $mailer,
    $deliveryLedger,
    $workerLock,
    &$totalSent,
    &$totalErrors,
    &$totalSkipped
): bool {
    echo sprintf("Checking %s expiry window: %d permit(s).\n", $window, count($permits));

    foreach ($permits as $permit) {
        try {
            if (!$workerLock->refresh()) {
                fwrite(STDERR, "[send-notifications] The worker lock expired; stopping safely.\n");
                return false;
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, "[send-notifications] The worker lock could not be refreshed; stopping safely.\n");
            return false;
        }

        $formId = (string) $permit['id'];
        $validTo = (string) $permit['valid_to'];
        $notificationKey = $window . ':' . $validTo;
        try {
            $deliveredRecipients = $deliveryLedger->deliveredRecipientKeys(
                $formId,
                'email_sent',
                $notificationKey
            );
        } catch (Throwable $exception) {
            $totalErrors++;
            error_log('[send-notifications] Delivery receipts could not be read for a permit.');
            continue;
        }

        $secondsUntilExpiry = strtotime($validTo);
        $daysUntilExpiry = $secondsUntilExpiry === false
            ? $fallbackDays
            : max(1, (int) ceil(($secondsUntilExpiry - time()) / 86400));

        foreach ($recipients as $email) {
            $recipientKey = NotificationDeliveryLedger::recipientKey($email);
            if (isset($deliveredRecipients[$recipientKey])) {
                $totalSkipped++;
                continue;
            }

            try {
                if (!$workerLock->refresh()) {
                    fwrite(STDERR, "[send-notifications] The worker lock expired; stopping safely.\n");
                    return false;
                }
            } catch (Throwable $exception) {
                fwrite(STDERR, "[send-notifications] The worker lock could not be refreshed; stopping safely.\n");
                return false;
            }

            try {
                $success = $mailer->sendPermitExpiring($permit, $email, $daysUntilExpiry);
                if (!$success) {
                    throw new RuntimeException('The mail transport returned an unsuccessful result.');
                }

                $deliveryLedger->recordDelivery(
                    $formId,
                    'email_sent',
                    $notificationKey,
                    $recipientKey,
                    [
                        'type' => $window . '_expiry',
                        'days_until_expiry' => $daysUntilExpiry,
                    ]
                );
                $deliveredRecipients[$recipientKey] = true;
                $totalSent++;
                echo sprintf("  Sent one %s reminder.\n", $window);
            } catch (Throwable $exception) {
                $totalErrors++;
                error_log(sprintf(
                    '[send-notifications] %s delivery failed for permit key %s: %s',
                    $window,
                    substr(hash('sha256', $formId), 0, 12),
                    $exception::class
                ));
            }
        }
    }

    return true;
};

echo '[' . date('Y-m-d H:i:s') . "] Email expiry notification worker starting.\n";
$completed = $sendWindow(
    $findPermits($now, $twentyFourHours),
    '24h',
    1,
    $recipients
);
if ($completed) {
    $completed = $sendWindow(
        $findPermits($twentyFourHours, $sevenDays),
        '7d',
        7,
        $recipients
    );
}

printf(
    "Sent: %d | Already delivered: %d | Errors: %d\n",
    $totalSent,
    $totalSkipped,
    $totalErrors
);
echo '[' . date('Y-m-d H:i:s') . "] Email expiry notification worker complete.\n";

exit($completed && $totalErrors === 0 ? 0 : 1);
