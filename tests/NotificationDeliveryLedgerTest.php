<?php
declare(strict_types=1);

use Permits\NotificationDeliveryLedger;
use PHPUnit\Framework\TestCase;

final class NotificationDeliveryLedgerTest extends TestCase
{
    private PDO $pdo;
    private NotificationDeliveryLedger $ledger;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE form_events (
                id TEXT NOT NULL PRIMARY KEY,
                form_id TEXT NOT NULL,
                type TEXT NOT NULL,
                at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                by_user TEXT NULL,
                payload TEXT NULL
            )
        SQL);
        $this->ledger = new NotificationDeliveryLedger($this->pdo);
    }

    public function testReceiptsAreScopedToRecipientAndNotificationOccurrence(): void
    {
        $firstRecipient = NotificationDeliveryLedger::recipientKey('ONE@example.com');
        $secondRecipient = NotificationDeliveryLedger::recipientKey('two@example.com');

        $this->ledger->recordDelivery('permit-1', 'email_expiry_reminder', '24h:2030-01-02 12:00:00', $firstRecipient);

        self::assertSame(
            [$firstRecipient => true],
            $this->ledger->deliveredRecipientKeys('permit-1', 'email_expiry_reminder', '24h:2030-01-02 12:00:00')
        );
        self::assertArrayNotHasKey(
            $secondRecipient,
            $this->ledger->deliveredRecipientKeys('permit-1', 'email_expiry_reminder', '24h:2030-01-02 12:00:00')
        );
        self::assertSame([], $this->ledger->deliveredRecipientKeys(
            'permit-1',
            'email_expiry_reminder',
            '24h:2030-01-03 12:00:00'
        ));
    }

    public function testMalformedAndLegacyEventsDoNotSuppressDelivery(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO form_events (id, form_id, type, payload) VALUES (?, ?, ?, ?)'
        );
        $insert->execute(['event-1', 'permit-1', 'push_expiry_reminder', '{broken']);
        $insert->execute(['event-2', 'permit-1', 'push_expiry_reminder', json_encode(['sent' => 2])]);

        self::assertSame([], $this->ledger->deliveredRecipientKeys(
            'permit-1',
            'push_expiry_reminder',
            'expiry:2030-01-02 12:00:00'
        ));
    }

    public function testRecipientKeysAreNormalisedWithoutStoringTheAddress(): void
    {
        self::assertSame(
            NotificationDeliveryLedger::recipientKey('person@example.com'),
            NotificationDeliveryLedger::recipientKey(' Person@Example.COM ')
        );
    }
}
