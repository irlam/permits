<?php
declare(strict_types=1);

use Permits\Db;
use Permits\Email;
use PHPUnit\Framework\TestCase;

final class EmailLifecycleNotificationTest extends TestCase
{
    public function testApprovalAndRejectionNotificationsQueueWithSecureLifecycleLinks(): void
    {
        $reflection = new ReflectionClass(Db::class);
        /** @var Db $db */
        $db = $reflection->newInstanceWithoutConstructor();
        $db->pdo = new PDO('sqlite::memory:');
        $db->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->pdo->exec(
            'CREATE TABLE email_queue ('
            . 'id TEXT PRIMARY KEY, to_email TEXT, subject TEXT, body TEXT, '
            . 'status TEXT, attempt_count INTEGER NOT NULL DEFAULT 0, available_at TEXT NULL, '
            . 'claimed_at TEXT NULL, claim_token TEXT NULL, last_error TEXT NULL, '
            . 'created_at TEXT, sent_at TEXT NULL)'
        );

        $oldAppUrl = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'https://permits.example.test';

        try {
            $email = new Email($db, dirname(__DIR__));
            $form = [
                'id' => 'permit-id',
                'ref' => 'legacy-ref-must-not-win',
                'ref_number' => 'PTW-2026-000123',
                'unique_link' => str_repeat('a', 64),
                'status' => 'awaiting_acceptance',
                'duration_label' => '4 hours',
                'valid_from' => null,
                'valid_to' => null,
            ];

            $approvalId = $email->sendApprovalNotification($form, 'holder@example.test');
            $rejectionId = $email->sendRejectionNotification($form, 'holder@example.test', 'Add a barrier.');

            $rows = $db->pdo->query('SELECT id, subject, body, status, created_at FROM email_queue ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(2, $rows);
            self::assertSame('Permit Approved: PTW-2026-000123', $rows[0]['subject']);
            self::assertSame('Permit Rejected: PTW-2026-000123', $rows[1]['subject']);
            self::assertStringContainsString(
                'permit-workflow.php?link=' . str_repeat('a', 64) . '#accept',
                $rows[0]['body']
            );
            self::assertStringContainsString('Do not start work yet', $rows[0]['body']);
            self::assertStringContainsString('Awaiting holder acceptance', $rows[0]['body']);
            self::assertStringContainsString('4 hours', $rows[0]['body']);
            self::assertStringContainsString('Add a barrier.', $rows[1]['body']);
            self::assertSame('pending', $rows[0]['status']);
            self::assertNotSame('', (string) $rows[0]['created_at']);

            self::assertTrue($email->markAsSent($approvalId));
            $sent = $db->pdo->query("SELECT status, sent_at FROM email_queue WHERE id = " . $db->pdo->quote($approvalId))->fetch(PDO::FETCH_ASSOC);
            self::assertSame('sent', $sent['status']);
            self::assertNotEmpty($sent['sent_at']);
            self::assertNotSame($approvalId, $rejectionId);
        } finally {
            if ($oldAppUrl === null) unset($_ENV['APP_URL']);
            else $_ENV['APP_URL'] = $oldAppUrl;
        }
    }
}
