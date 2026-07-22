<?php
/**
 * Send one scoped push reminder shortly before each active permit expires.
 *
 * Usage: php bin/reminders.php [lookahead-minutes]
 */
declare(strict_types=1);

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Permits\NotificationDeliveryLedger;
use Permits\PushSubscriptionValidator;
use Permits\WorkerLock;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
[, $db] = require $root . '/src/bootstrap.php';

$lookaheadMinutes = isset($argv[1]) && is_numeric($argv[1])
    ? max(1, min(1440, (int)$argv[1]))
    : 60;

$appUrl = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
$appUrlParts = parse_url($appUrl);
if (
    $appUrl === ''
    || !is_array($appUrlParts)
    || !in_array(strtolower((string)($appUrlParts['scheme'] ?? '')), ['https', 'http'], true)
    || empty($appUrlParts['host'])
) {
    fwrite(STDERR, "[reminders] APP_URL must be a complete application URL.\n");
    exit(2);
}

$vapidPublic = trim((string)($_ENV['VAPID_PUBLIC_KEY'] ?? ''));
$vapidPrivate = trim((string)($_ENV['VAPID_PRIVATE_KEY'] ?? ''));
$vapidSubject = trim((string)($_ENV['VAPID_SUBJECT'] ?? ''));
if ($vapidPublic === '' || $vapidPrivate === '' || $vapidSubject === '') {
    fwrite(STDERR, "[reminders] Configure VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY and VAPID_SUBJECT first.\n");
    exit(2);
}

$webPush = new WebPush(
    [
        'VAPID' => [
            'subject' => $vapidSubject,
            'publicKey' => $vapidPublic,
            'privateKey' => $vapidPrivate,
        ],
    ],
    [],
    30,
    [
        'allow_redirects' => false,
        'connect_timeout' => 5,
    ]
);

$pdo = $db->pdo;
try {
    $workerLock = WorkerLock::acquire($pdo, 'push-expiry-reminders', 1800);
} catch (Throwable $exception) {
    fwrite(STDERR, "[reminders] Unable to acquire the worker lock. Run php bin/migrate.php.\n");
    exit(2);
}
if ($workerLock === null) {
    echo "[reminders] Another reminder worker is already running; this run was skipped.\n";
    exit(0);
}
register_shutdown_function(static function () use ($workerLock): void {
    try {
        $workerLock->release();
    } catch (Throwable $exception) {
        error_log('[reminders] Failed to release the worker lock.');
    }
});

$deliveryLedger = new NotificationDeliveryLedger($pdo);
$now = date('Y-m-d H:i:s');
$deadline = date('Y-m-d H:i:s', time() + ($lookaheadMinutes * 60));

$dueStmt = $pdo->prepare(<<<'SQL'
    SELECT f.id,
           COALESCE(NULLIF(f.ref_number, ''), NULLIF(f.ref, ''), f.id) AS permit_ref,
           f.unique_link,
           f.valid_to,
           f.holder_id,
           f.issuer_id,
           f.holder_email
      FROM forms f
     WHERE f.status IN ('active', 'issued', 'approved', 'open')
       AND f.valid_to IS NOT NULL
       AND f.valid_to > ?
       AND f.valid_to <= ?
     ORDER BY f.valid_to ASC
SQL);
$dueStmt->execute([$now, $deadline]);
$duePermits = $dueStmt->fetchAll(PDO::FETCH_ASSOC);

if ($duePermits === []) {
    echo "[reminders] No active permits expire in the next {$lookaheadMinutes} minutes.\n";
    exit(0);
}

$subscriptions = $pdo->query(<<<'SQL'
    SELECT ps.endpoint, ps.p256dh, ps.auth, ps.endpoint_hash, ps.user_id,
           u.email, u.role
      FROM push_subscriptions ps
      JOIN users u ON u.id = ps.user_id
     WHERE u.status = 'active'
SQL)->fetchAll(PDO::FETCH_ASSOC);

$sent = 0;
$errors = 0;
$pruned = 0;
$permitsNotified = 0;

// Validate legacy rows before the Web Push library sees them. This both
// prevents unsafe outbound targets and ensures one malformed key cannot stop
// reminders for every other user.
$validSubscriptions = [];
foreach ($subscriptions as $subscriptionRow) {
    try {
        $validated = PushSubscriptionValidator::validate(
            (string)$subscriptionRow['endpoint'],
            (string)$subscriptionRow['p256dh'],
            (string)$subscriptionRow['auth']
        );
        $validSubscriptions[] = array_merge($subscriptionRow, $validated);
    } catch (InvalidArgumentException $exception) {
        $delete = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ?');
        $delete->execute([(string)$subscriptionRow['endpoint_hash']]);
        $pruned += $delete->rowCount();
        $errors++;
    }
}
$subscriptions = $validSubscriptions;

if ($subscriptions === []) {
    printf(
        "[reminders] No valid active user subscriptions are available. Errors: %d | Pruned: %d\n",
        $errors,
        $pruned
    );
    exit($errors > 0 ? 1 : 0);
}

foreach ($duePermits as $permit) {
    try {
        if (!$workerLock->refresh()) {
            fwrite(STDERR, "[reminders] The worker lock expired; stopping safely.\n");
            exit(2);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, "[reminders] The worker lock could not be refreshed; stopping safely.\n");
        exit(2);
    }

    $notificationKey = 'expiry:' . (string)$permit['valid_to'];
    try {
        $deliveredRecipients = $deliveryLedger->deliveredRecipientKeys(
            (string)$permit['id'],
            'push_expiry_reminder',
            $notificationKey
        );
    } catch (Throwable $exception) {
        $errors++;
        error_log('[reminders] Push delivery receipts could not be read for a permit.');
        continue;
    }
    $holderId = (string)($permit['holder_id'] ?? '');
    $issuerId = (string)($permit['issuer_id'] ?? '');
    $holderEmail = strtolower(trim((string)($permit['holder_email'] ?? '')));
    $eligible = [];

    foreach ($subscriptions as $subscriptionRow) {
        $role = strtolower((string)($subscriptionRow['role'] ?? ''));
        $userId = (string)($subscriptionRow['user_id'] ?? '');
        $userEmail = strtolower(trim((string)($subscriptionRow['email'] ?? '')));
        $isResponsibleUser = in_array($role, ['admin', 'manager'], true)
            || ($holderId !== '' && hash_equals($holderId, $userId))
            || ($issuerId !== '' && hash_equals($issuerId, $userId))
            || ($holderEmail !== '' && $userEmail !== '' && hash_equals($holderEmail, $userEmail));

        if (!$isResponsibleUser) {
            continue;
        }

        $endpointHash = hash('sha256', (string)$subscriptionRow['endpoint']);
        if (isset($deliveredRecipients[$endpointHash])) {
            continue;
        }
        $eligible[$endpointHash] = $subscriptionRow;
    }

    if ($eligible === []) {
        continue;
    }

    $validTo = (string)$permit['valid_to'];
    try {
        $validTo = (new DateTimeImmutable($validTo))->format('D, d M Y H:i');
    } catch (Throwable $e) {
        // Retain the stored value when legacy data cannot be parsed.
    }

    $uniqueLink = trim((string)($permit['unique_link'] ?? ''));
    $url = $uniqueLink !== ''
        ? $appUrl . '/view-permit-public.php?link=' . rawurlencode($uniqueLink)
        : $appUrl . '/manager-approvals.php';
    $payload = json_encode([
        'title' => 'Permit expiring soon',
        'body' => 'Ref ' . (string)$permit['permit_ref'] . ' expires at ' . $validTo,
        'url' => $url,
        'tag' => 'permit-expiry-' . (string)$permit['id'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!is_string($payload)) {
        $errors++;
        continue;
    }

    $permitSent = 0;
    foreach ($eligible as $endpointHash => $subscriptionRow) {
        try {
            if (!$workerLock->refresh()) {
                fwrite(STDERR, "[reminders] The worker lock expired; stopping safely.\n");
                exit(2);
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, "[reminders] The worker lock could not be refreshed; stopping safely.\n");
            exit(2);
        }

        try {
            // Send individually so encryption/preparation errors are isolated
            // to the affected browser subscription.
            $report = $webPush->sendOneNotification(new Subscription(
                (string)$subscriptionRow['endpoint'],
                (string)$subscriptionRow['p256dh'],
                (string)$subscriptionRow['auth']
            ), $payload);
        } catch (Throwable $exception) {
            $errors++;
            error_log('Push reminder delivery preparation failed: ' . $exception::class);
            continue;
        }

        if ($report->isSuccess()) {
            $permitSent++;
            $sent++;
            try {
                $deliveryLedger->recordDelivery(
                    (string)$permit['id'],
                    'push_expiry_reminder',
                    $notificationKey,
                    $endpointHash,
                    ['lookahead_minutes' => $lookaheadMinutes]
                );
            } catch (Throwable $exception) {
                // Delivery has already happened. Count the receipt failure so
                // operators know the next run may retry this recipient.
                $errors++;
                error_log('[reminders] A push delivery receipt could not be recorded.');
            }
            continue;
        }

        $errors++;
        $statusCode = $report->getResponse()?->getStatusCode() ?? 0;
        if (in_array($statusCode, [404, 410], true)) {
            $endpoint = $report->getRequest()->getUri()->__toString();
            $delete = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ?');
            $delete->execute([hash('sha256', $endpoint)]);
            $pruned += $delete->rowCount();
        }
    }

    if ($permitSent > 0) {
        $permitsNotified++;
    }
}

printf(
    "[reminders] Due: %d | Permits notified: %d | Sent: %d | Errors: %d | Pruned: %d\n",
    count($duePermits),
    $permitsNotified,
    $sent,
    $errors,
    $pruned
);

exit($errors > 0 ? 1 : 0);
