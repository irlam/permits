<?php
declare(strict_types=1);

use Permits\DatabaseMaintenance;
use Permits\Db;
use Permits\Email;
use Permits\EmailQueueProcessor;
use Permits\Mailer;
use PHPUnit\Framework\TestCase;

final class EmailQueueReliabilityTest extends TestCase
{
    public function testDisabledDeliveryLeavesBearerLinkNotificationUntouchedAndUnlogged(): void
    {
        $db = $this->database();
        $email = new Email($db, dirname(__DIR__));
        $id = $email->queue(
            'holder@example.com',
            'Permit approved',
            '<a href="https://example.test/view-permit-public.php?link=bearer-secret">View</a>'
        );
        $logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'permits-disabled-mail-' . bin2hex(random_bytes(4));
        $mailer = new Mailer([
            'enabled' => false,
            'driver' => 'log',
            'log_directory' => $logDir,
            'from' => 'permits@example.com',
        ]);

        $result = (new EmailQueueProcessor($email, $mailer))->process();
        $row = $db->pdo->query("SELECT status, attempt_count, claim_token FROM email_queue WHERE id = " . $db->pdo->quote($id))->fetch(PDO::FETCH_ASSOC);

        self::assertTrue($result['disabled']);
        self::assertSame(['status' => 'pending', 'attempt_count' => 0, 'claim_token' => null], $row);
        self::assertDirectoryDoesNotExist($logDir);
    }

    public function testFailuresBackOffAndStopAtBoundedRetryLimit(): void
    {
        $db = $this->database();
        $email = new Email($db, dirname(__DIR__));
        $id = $email->queue('holder@example.com', 'Permit approved', '<p>Message</p>');
        $mailer = new class extends Mailer {
            public function __construct() {}
            public function isEnabled(): bool { return true; }
            public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
            {
                throw new RuntimeException('Temporary SMTP failure.');
            }
        };
        $processor = new EmailQueueProcessor($email, $mailer, 3, 30, 15);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1) {
                $db->pdo->exec("UPDATE email_queue SET available_at = datetime('now', '-1 second') WHERE id = " . $db->pdo->quote($id));
            }
            $result = $processor->process(1);
            self::assertSame(1, $result['processed']);
            self::assertSame(1, $result['failed']);
        }

        $row = $db->pdo->query("SELECT status, attempt_count, available_at, claim_token, last_error FROM email_queue WHERE id = " . $db->pdo->quote($id))->fetch(PDO::FETCH_ASSOC);
        self::assertSame('failed', $row['status']);
        self::assertSame(3, $row['attempt_count']);
        self::assertNull($row['available_at']);
        self::assertNull($row['claim_token']);
        self::assertSame('Temporary SMTP failure.', $row['last_error']);

        self::assertSame(0, $processor->process(1)['processed']);
    }

    public function testClaimsCannotOverlapAndStaleClaimsRecover(): void
    {
        $db = $this->database();
        $emailA = new Email($db, dirname(__DIR__));
        $emailB = new Email($db, dirname(__DIR__));
        $firstId = $emailA->queue('one@example.com', 'One', '<p>One</p>');
        $secondId = $emailA->queue('two@example.com', 'Two', '<p>Two</p>');

        $first = $emailA->claimPendingEmails(1, str_repeat('a', 32));
        $second = $emailB->claimPendingEmails(1, str_repeat('b', 32));

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertNotSame($first[0]['id'], $second[0]['id']);

        $emailA->markAsSent((string)$first[0]['id'], str_repeat('a', 32));
        $db->pdo->exec(
            "UPDATE email_queue SET claimed_at = datetime('now', '-30 minutes') " .
            "WHERE id = " . $db->pdo->quote((string)$second[0]['id'])
        );
        $recovered = $emailA->claimPendingEmails(1, str_repeat('c', 32), 5, 15);

        self::assertCount(1, $recovered);
        self::assertSame($second[0]['id'], $recovered[0]['id']);
        self::assertSame(2, $recovered[0]['attempt_count']);
        self::assertContains($firstId, array_column(array_merge($first, $second), 'id'));
        self::assertContains($secondId, array_column(array_merge($first, $second), 'id'));
    }

    private function database(): Db
    {
        $reflection = new ReflectionClass(Db::class);
        /** @var Db $db */
        $db = $reflection->newInstanceWithoutConstructor();
        $db->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $result = DatabaseMaintenance::ensureEmailQueueTable($db);
        self::assertSame([], $result['errors']);
        return $db;
    }
}
