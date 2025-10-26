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
            public array $failed = [];

            public function __construct() {}

            public function getPendingEmails(int $limit = 50): array
            {
                return array_slice($this->pending, 0, $limit);
            }

            public function markAsSent(string $id): bool
            {
                $this->sent[] = $id;
                return true;
            }

            public function markAsFailed(string $id): bool
            {
                $this->failed[] = $id;
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
        $this->assertCount(1, $report['errors']);

        $this->assertSame(['1'], $email->sent);
        $this->assertSame(['2'], $email->failed);
        $this->assertCount(2, $mailer->calls);
    }
}
