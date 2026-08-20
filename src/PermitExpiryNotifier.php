<?php
declare(strict_types=1);

namespace Permits;

use InvalidArgumentException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use PDO;
use Throwable;

/**
 * Sends STOP WORK notifications for permits that have recently expired.
 *
 * Delivery receipts are written to form_events via NotificationDeliveryLedger,
 * so a failed recipient is retried on the next worker run without duplicating
 * successful deliveries. The expiry status itself remains authoritative even
 * when a notification channel is unavailable.
 */
final class PermitExpiryNotifier
{
    public function __construct(private Db $db)
    {
    }

    /**
     * @return array{permits:int,email_sent:int,push_sent:int,errors:int,pruned:int,lock_skipped:bool}
     */
    public function notifyRecentlyExpired(int $lookbackMinutes = 1440): array
    {
        $lookbackMinutes = max(1, min(10080, $lookbackMinutes));
        $summary = [
            'permits' => 0,
            'email_sent' => 0,
            'push_sent' => 0,
            'errors' => 0,
            'pruned' => 0,
            'lock_skipped' => false,
        ];

        $pdo = $this->db->pdo;
        $lock = WorkerLock::acquire($pdo, 'expired-permit-alerts', 300);
        if ($lock === null) {
            $summary['lock_skipped'] = true;
            return $summary;
        }

        try {
            $permits = $this->recentlyExpiredPermits($lookbackMinutes);
            $summary['permits'] = count($permits);
            if ($permits === []) {
                return $summary;
            }

            $ledger = new NotificationDeliveryLedger($pdo);

            $mailer = null;
            try {
                $candidate = Mailer::fromDatabase($this->db);
                if ($candidate->isEnabled()) {
                    $mailer = $candidate;
                }
            } catch (Throwable $exception) {
                $summary['errors']++;
                error_log('[expiry-alerts] Email transport could not be initialised: ' . $exception::class);
            }

            $webPush = $this->createWebPush();
            $subscriptions = [];
            if ($webPush !== null) {
                $subscriptions = $this->validPushSubscriptions($summary);
            }

            foreach ($permits as $permit) {
                if (!$lock->refresh()) {
                    $summary['errors']++;
                    error_log('[expiry-alerts] Worker lock expired before all notifications were processed.');
                    break;
                }

                $notificationKey = 'expired:' . (string)($permit['valid_to'] ?? '');

                if ($mailer !== null) {
                    $this->sendEmails($mailer, $ledger, $permit, $notificationKey, $summary);
                }

                if ($webPush !== null && $subscriptions !== []) {
                    $this->sendPushes($webPush, $ledger, $subscriptions, $permit, $notificationKey, $summary);
                }
            }
        } catch (Throwable $exception) {
            $summary['errors']++;
            error_log('[expiry-alerts] Notification pass failed: ' . $exception->getMessage());
        } finally {
            try {
                $lock->release();
            } catch (Throwable $exception) {
                error_log('[expiry-alerts] Worker lock could not be released.');
            }
        }

        return $summary;
    }

    /** @return list<array<string,mixed>> */
    private function recentlyExpiredPermits(int $lookbackMinutes): array
    {
        $now = date('Y-m-d H:i:s');
        $cutoff = date('Y-m-d H:i:s', time() - ($lookbackMinutes * 60));
        $statement = $this->db->pdo->prepare(<<<'SQL'
            SELECT f.id,
                   COALESCE(NULLIF(f.ref_number, ''), NULLIF(f.ref, ''), f.id) AS ref,
                   f.unique_link,
                   f.template_id,
                   f.site_block,
                   f.valid_to,
                   f.holder_id,
                   f.issuer_id,
                   f.holder_email,
                   COALESCE(ft.name, 'Permit') AS template_name
              FROM forms f
              LEFT JOIN form_templates ft ON ft.id = f.template_id
             WHERE LOWER(f.status) = 'expired'
               AND f.requires_approval = 1
               AND f.valid_to IS NOT NULL
               AND f.valid_to >= ?
               AND f.valid_to <= ?
             ORDER BY f.valid_to ASC, f.id ASC
        SQL);
        $statement->execute([$cutoff, $now]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $permit
     * @param array{permits:int,email_sent:int,push_sent:int,errors:int,pruned:int,lock_skipped:bool} $summary
     */
    private function sendEmails(
        Mailer $mailer,
        NotificationDeliveryLedger $ledger,
        array $permit,
        string $notificationKey,
        array &$summary
    ): void {
        $formId = (string)$permit['id'];
        try {
            $alreadyDelivered = $ledger->deliveredRecipientKeys($formId, 'expiry_email_sent', $notificationKey);
        } catch (Throwable $exception) {
            $summary['errors']++;
            return;
        }

        foreach ($this->responsibleEmailRecipients($permit) as $email) {
            $recipientKey = NotificationDeliveryLedger::recipientKey($email);
            if (isset($alreadyDelivered[$recipientKey])) {
                continue;
            }

            try {
                $this->sendExpiredEmail($mailer, $permit, $email);
                $ledger->recordDelivery(
                    $formId,
                    'expiry_email_sent',
                    $notificationKey,
                    $recipientKey,
                    ['channel' => 'email', 'notice' => 'expired_stop_work']
                );
                $alreadyDelivered[$recipientKey] = true;
                $summary['email_sent']++;
            } catch (Throwable $exception) {
                $summary['errors']++;
                error_log('[expiry-alerts] Expired permit email delivery failed: ' . $exception::class);
            }
        }
    }

    /** @param array<string,mixed> $permit @return list<string> */
    private function responsibleEmailRecipients(array $permit): array
    {
        $recipients = [];
        $add = static function (mixed $candidate) use (&$recipients): void {
            if (!is_scalar($candidate)) {
                return;
            }
            $email = trim((string)$candidate);
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
                return;
            }
            $recipients[strtolower($email)] = $email;
        };

        $add($permit['holder_email'] ?? null);

        $responsibleIds = array_values(array_unique(array_filter([
            trim((string)($permit['holder_id'] ?? '')),
            trim((string)($permit['issuer_id'] ?? '')),
        ], static fn(string $value): bool => $value !== '')));

        $sql = "SELECT id, email, role FROM users WHERE status = 'active' AND (LOWER(role) IN ('admin','manager')";
        $params = [];
        if ($responsibleIds !== []) {
            $sql .= ' OR id IN (' . implode(',', array_fill(0, count($responsibleIds), '?')) . ')';
            $params = $responsibleIds;
        }
        $sql .= ')';

        try {
            $statement = $this->db->pdo->prepare($sql);
            $statement->execute($params);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $user) {
                $add($user['email'] ?? null);
            }
        } catch (Throwable $exception) {
            // Preserve direct holder notification even if the user directory is unavailable.
            error_log('[expiry-alerts] Responsible user emails could not be loaded.');
        }

        return array_values($recipients);
    }

    /** @param array<string,mixed> $permit */
    private function sendExpiredEmail(Mailer $mailer, array $permit, string $recipientEmail): void
    {
        $ref = trim((string)($permit['ref'] ?? 'Permit')) ?: 'Permit';
        $type = trim((string)($permit['template_name'] ?? $permit['template_id'] ?? 'Permit')) ?: 'Permit';
        $area = trim((string)($permit['site_block'] ?? '')) ?: 'Not recorded';
        $expiry = $this->formatUkDate((string)($permit['valid_to'] ?? ''));
        $url = $this->permitUrl($permit);

        $eRef = htmlspecialchars($ref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $eType = htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $eArea = htmlspecialchars($area, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $eExpiry = htmlspecialchars($expiry, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $eUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $subject = '⛔ PERMIT EXPIRED — STOP WORK: ' . $ref;
        $html = <<<HTML
<!doctype html><html><body style="margin:0;background:#0f172a;color:#e5e7eb;font-family:system-ui,-apple-system,Segoe UI,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:30px 14px;background:#0f172a"><tr><td align="center">
<table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;width:100%;background:#111827;border:1px solid #7f1d1d;border-radius:14px;padding:26px">
<tr><td><div style="background:#991b1b;color:#fff;padding:16px;border-radius:10px;text-align:center;font-size:20px;font-weight:800">⛔ PERMIT EXPIRED — STOP WORK</div>
<h2 style="color:#fff">Work is no longer authorised under this permit.</h2>
<table width="100%" cellpadding="7" cellspacing="0" style="border-collapse:collapse">
<tr><td style="color:#94a3b8">Reference</td><td style="color:#fff;font-weight:700">{$eRef}</td></tr>
<tr><td style="color:#94a3b8">Permit type</td><td style="color:#fff">{$eType}</td></tr>
<tr><td style="color:#94a3b8">Area</td><td style="color:#fff">{$eArea}</td></tr>
<tr><td style="color:#94a3b8">Expired</td><td style="color:#fecaca;font-weight:800">{$eExpiry}</td></tr>
</table>
<p style="color:#fecaca;line-height:1.55"><strong>Do not continue or restart work under this permit.</strong> If work is still required, follow the site permit procedure and obtain fresh valid authorisation before work resumes.</p>
<div style="text-align:center;margin-top:22px"><a href="{$eUrl}" style="display:inline-block;background:#fff;color:#991b1b;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:800">View expired permit</a></div>
</td></tr></table></td></tr></table></body></html>
HTML;
        $text = "PERMIT EXPIRED — STOP WORK\nReference: {$ref}\nPermit type: {$type}\nArea: {$area}\nExpired: {$expiry}\nWork is no longer authorised under this permit. Do not continue or restart work until fresh valid authorisation is in place.\n{$url}";

        if (!$mailer->send($recipientEmail, $subject, $html, $text)) {
            throw new \RuntimeException('Expired permit email was not accepted by the transport.');
        }
    }

    private function createWebPush(): ?WebPush
    {
        $public = trim((string)($_ENV['VAPID_PUBLIC_KEY'] ?? ''));
        $private = trim((string)($_ENV['VAPID_PRIVATE_KEY'] ?? ''));
        $subject = trim((string)($_ENV['VAPID_SUBJECT'] ?? ''));
        if ($public === '' || $private === '' || $subject === '') {
            return null;
        }

        try {
            return new WebPush([
                'VAPID' => [
                    'subject' => $subject,
                    'publicKey' => $public,
                    'privateKey' => $private,
                ],
            ], [], 30, [
                'allow_redirects' => false,
                'connect_timeout' => 5,
            ]);
        } catch (Throwable $exception) {
            error_log('[expiry-alerts] Push transport could not be initialised: ' . $exception::class);
            return null;
        }
    }

    /**
     * @param array{permits:int,email_sent:int,push_sent:int,errors:int,pruned:int,lock_skipped:bool} $summary
     * @return list<array<string,mixed>>
     */
    private function validPushSubscriptions(array &$summary): array
    {
        $rows = $this->db->pdo->query(<<<'SQL'
            SELECT ps.endpoint, ps.p256dh, ps.auth, ps.endpoint_hash,
                   ps.user_id, u.email, u.role
              FROM push_subscriptions ps
              JOIN users u ON u.id = ps.user_id
             WHERE u.status = 'active'
        SQL)->fetchAll(PDO::FETCH_ASSOC);

        $valid = [];
        foreach ($rows as $row) {
            try {
                $validated = PushSubscriptionValidator::validate(
                    (string)$row['endpoint'],
                    (string)$row['p256dh'],
                    (string)$row['auth']
                );
                $valid[] = array_merge($row, $validated);
            } catch (InvalidArgumentException $exception) {
                $delete = $this->db->pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ?');
                $delete->execute([(string)$row['endpoint_hash']]);
                $summary['pruned'] += $delete->rowCount();
            }
        }

        return $valid;
    }

    /**
     * @param list<array<string,mixed>> $subscriptions
     * @param array<string,mixed> $permit
     * @param array{permits:int,email_sent:int,push_sent:int,errors:int,pruned:int,lock_skipped:bool} $summary
     */
    private function sendPushes(
        WebPush $webPush,
        NotificationDeliveryLedger $ledger,
        array $subscriptions,
        array $permit,
        string $notificationKey,
        array &$summary
    ): void {
        $formId = (string)$permit['id'];
        try {
            $alreadyDelivered = $ledger->deliveredRecipientKeys($formId, 'expiry_push_sent', $notificationKey);
        } catch (Throwable $exception) {
            $summary['errors']++;
            return;
        }

        $holderId = trim((string)($permit['holder_id'] ?? ''));
        $issuerId = trim((string)($permit['issuer_id'] ?? ''));
        $holderEmail = strtolower(trim((string)($permit['holder_email'] ?? '')));
        $ref = trim((string)($permit['ref'] ?? 'Permit')) ?: 'Permit';
        $expiry = $this->formatUkDate((string)($permit['valid_to'] ?? ''));
        $url = $this->permitUrl($permit);

        $payload = json_encode([
            'title' => '⛔ Permit expired — STOP WORK',
            'body' => "Ref {$ref} expired at {$expiry}. Work is no longer authorised.",
            'url' => $url,
            'tag' => 'permit-expired-' . $formId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            $summary['errors']++;
            return;
        }

        foreach ($subscriptions as $subscriptionRow) {
            $role = strtolower((string)($subscriptionRow['role'] ?? ''));
            $userId = trim((string)($subscriptionRow['user_id'] ?? ''));
            $userEmail = strtolower(trim((string)($subscriptionRow['email'] ?? '')));
            $responsible = in_array($role, ['admin','manager'], true)
                || ($holderId !== '' && hash_equals($holderId, $userId))
                || ($issuerId !== '' && hash_equals($issuerId, $userId))
                || ($holderEmail !== '' && $userEmail !== '' && hash_equals($holderEmail, $userEmail));
            if (!$responsible) {
                continue;
            }

            $endpointHash = hash('sha256', (string)$subscriptionRow['endpoint']);
            if (isset($alreadyDelivered[$endpointHash])) {
                continue;
            }

            try {
                $report = $webPush->sendOneNotification(new Subscription(
                    (string)$subscriptionRow['endpoint'],
                    (string)$subscriptionRow['p256dh'],
                    (string)$subscriptionRow['auth']
                ), $payload);

                if ($report->isSuccess()) {
                    $ledger->recordDelivery(
                        $formId,
                        'expiry_push_sent',
                        $notificationKey,
                        $endpointHash,
                        ['channel' => 'push', 'notice' => 'expired_stop_work']
                    );
                    $alreadyDelivered[$endpointHash] = true;
                    $summary['push_sent']++;
                    continue;
                }

                $summary['errors']++;
                $statusCode = $report->getResponse()?->getStatusCode() ?? 0;
                if (in_array($statusCode, [404,410], true)) {
                    $delete = $this->db->pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ?');
                    $delete->execute([(string)$subscriptionRow['endpoint_hash']]);
                    $summary['pruned'] += $delete->rowCount();
                }
            } catch (Throwable $exception) {
                $summary['errors']++;
                error_log('[expiry-alerts] Expired permit push delivery failed: ' . $exception::class);
            }
        }
    }

    /** @param array<string,mixed> $permit */
    private function permitUrl(array $permit): string
    {
        $baseUrl = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        $uniqueLink = trim((string)($permit['unique_link'] ?? ''));
        if ($baseUrl !== '' && $uniqueLink !== '') {
            return $baseUrl . '/view-permit-public.php?link=' . rawurlencode($uniqueLink);
        }
        if ($baseUrl !== '') {
            return $baseUrl . '/permit-board.php';
        }
        return '/permit-board.php';
    }

    private function formatUkDate(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date('d/m/Y H:i', $timestamp);
    }
}
