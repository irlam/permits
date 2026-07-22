<?php
declare(strict_types=1);

use Permits\Email;
use Permits\EmailQueueProcessor;
use Permits\Mailer;
use PHPUnit\Framework\TestCase;

final class EmailQueueProcessorTest extends TestCase
{
    public function testProcessMarksSentAndFailed(): void
    {
        $email = new class extends Email {
            public array $pending = [];
            public array $sent = [];
            public array $released = [];

            public function __construct() {}

            public function claimPendingEmails(int $limit, string $claimToken, int $maxAttempts = 5, int $staleMinutes = 15): array
            {
                if ($this->pending === []) {
                    return [];
                }

                $row = array_shift($this->pending);
                $row['attempt_count'] = ((int)($row['attempt_count'] ?? 0)) + 1;
                return [$row];
            }

            public function markAsSent(string $id, ?string $claimToken = null): bool
            {
                $this->sent[] = $id;
                return true;
            }

            public function releaseAfterFailure(string $id, string $claimToken, string $error, int $maxAttempts = 5, int $delaySeconds = 60): bool
            {
                $this->released[] = $id;
                return true;
            }
        };

        $mailer = new class(['ok' => true, 'fail' => false]) extends Mailer {
            private array $responses;
            public array $calls = [];

            public function __construct(array $responses)
            {
                $this->responses = $responses;
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
            {
                $this->calls[] = [$to, $subject];
                return $this->responses[$subject] ?? true;
            }
        };

        $email->pending = [
            ['id' => '1', 'to_email' => 'a@example.com', 'subject' => 'ok', 'body' => '<p>ok</p>'],
            ['id' => '2', 'to_email' => 'b@example.com', 'subject' => 'fail', 'body' => '<p>fail</p>'],
        ];

        $processor = new EmailQueueProcessor($email, $mailer);
        $report = $processor->process();

        $this->assertSame(2, $report['processed']);
        $this->assertSame(1, $report['sent']);
        $this->assertSame(1, $report['failed']);
        $this->assertSame(1, $report['retrying']);
        $this->assertFalse($report['disabled']);
        $this->assertCount(1, $report['errors']);

        $this->assertSame(['1'], $email->sent);
        $this->assertSame(['2'], $email->released);
        $this->assertCount(2, $mailer->calls);
    }

    public function testDisabledDeliveryDoesNotClaimAnything(): void
    {
        $email = new class extends Email {
            public int $claims = 0;
            public function __construct() {}
            public function claimPendingEmails(int $limit, string $claimToken, int $maxAttempts = 5, int $staleMinutes = 15): array
            {
                $this->claims++;
                return [];
            }
        };
        $mailer = new Mailer([
            'enabled' => false,
            'driver' => 'log',
            'from' => 'qa@example.com',
        ]);

        $report = (new EmailQueueProcessor($email, $mailer))->process();

        self::assertTrue($report['disabled']);
        self::assertSame(0, $report['processed']);
        self::assertSame(0, $email->claims);
    }
}
