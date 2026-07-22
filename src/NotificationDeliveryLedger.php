<?php
declare(strict_types=1);

namespace Permits;

use PDO;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Recipient-scoped delivery receipts stored in the existing permit event log.
 * Failed recipients have no receipt and are therefore retried on the next run.
 */
final class NotificationDeliveryLedger
{
    public function __construct(private PDO $pdo)
    {
    }

    public static function recipientKey(string $recipient): string
    {
        $recipient = strtolower(trim($recipient));
        if ($recipient === '') {
            throw new RuntimeException('Notification recipient cannot be empty.');
        }

        return hash('sha256', $recipient);
    }

    /**
     * @return array<string,true> Successfully delivered recipient keys.
     */
    public function deliveredRecipientKeys(string $formId, string $eventType, string $notificationKey): array
    {
        self::assertIdentifier($formId, 'form ID', 191);
        self::assertIdentifier($eventType, 'event type', 64);
        self::assertIdentifier($notificationKey, 'notification key', 255);

        $statement = $this->pdo->prepare(
            'SELECT payload FROM form_events WHERE form_id = ? AND type = ? ORDER BY at ASC'
        );
        $statement->execute([$formId, $eventType]);

        $delivered = [];
        while (($payload = $statement->fetchColumn()) !== false) {
            if (!is_string($payload) || $payload === '') {
                continue;
            }

            $decoded = json_decode($payload, true);
            if (!is_array($decoded) || ($decoded['notification_key'] ?? null) !== $notificationKey) {
                continue;
            }

            $recipientKey = strtolower((string) ($decoded['recipient_key'] ?? ''));
            if (preg_match('/^[a-f0-9]{64}$/', $recipientKey) === 1) {
                $delivered[$recipientKey] = true;
            }
        }

        return $delivered;
    }

    /** @param array<string,mixed> $metadata */
    public function recordDelivery(
        string $formId,
        string $eventType,
        string $notificationKey,
        string $recipientKey,
        array $metadata = []
    ): void {
        self::assertIdentifier($formId, 'form ID', 191);
        self::assertIdentifier($eventType, 'event type', 64);
        self::assertIdentifier($notificationKey, 'notification key', 255);
        $recipientKey = strtolower(trim($recipientKey));
        if (preg_match('/^[a-f0-9]{64}$/', $recipientKey) !== 1) {
            throw new RuntimeException('Notification recipient key is invalid.');
        }

        // Fixed fields are applied last so optional metadata cannot corrupt the
        // idempotency receipt.
        $payload = json_encode(array_merge($metadata, [
            'notification_key' => $notificationKey,
            'recipient_key' => $recipientKey,
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $statement = $this->pdo->prepare(
            'INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            Uuid::uuid4()->toString(),
            $formId,
            $eventType,
            'system',
            $payload,
        ]);
    }

    private static function assertIdentifier(string $value, string $label, int $maxLength): void
    {
        if ($value === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException('Notification ' . $label . ' is invalid.');
        }
    }
}
