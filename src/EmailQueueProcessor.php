<?php
namespace Permits;

use Throwable;

/**
 * EmailQueueProcessor
 * --------------------
 * Drains the email_queue table and hands off messages to the Mailer transport.
 */
final class EmailQueueProcessor
{
    private Email $email;
    private Mailer $mailer;
    private int $maxAttempts;
    private int $retryBaseSeconds;
    private int $staleClaimMinutes;

    public function __construct(
        Email $email,
        Mailer $mailer,
        int $maxAttempts = 5,
        int $retryBaseSeconds = 60,
        int $staleClaimMinutes = 15
    )
    {
        $this->email  = $email;
        $this->mailer = $mailer;
        $this->maxAttempts = max(1, min(20, $maxAttempts));
        $this->retryBaseSeconds = max(30, min(3600, $retryBaseSeconds));
        $this->staleClaimMinutes = max(5, min(240, $staleClaimMinutes));
    }

    /**
     * Process pending emails.
     *
     * @return array{processed:int,sent:int,failed:int,retrying:int,disabled:bool,errors:array<int,string>}
     */
    public function process(int $limit = 50): array
    {
        $report = [
            'processed' => 0,
            'sent'      => 0,
            'failed'    => 0,
            'retrying'  => 0,
            'disabled'  => false,
            'errors'    => [],
        ];

        // Checking before claiming is deliberate: switching email off pauses
        // the queue without changing status, retry counters or bearer-link
        // content, and never falls through to the log transport.
        if (!$this->mailer->isEnabled()) {
            $report['disabled'] = true;
            return $report;
        }

        $limit = max(1, min(500, $limit));
        for ($position = 0; $position < $limit; $position++) {
            $claimToken = bin2hex(random_bytes(16));
            $claimed = $this->email->claimPendingEmails(
                1,
                $claimToken,
                $this->maxAttempts,
                $this->staleClaimMinutes
            );
            if ($claimed === []) {
                break;
            }

            $row = $claimed[0];
            $report['processed']++;
            $emailId = (string)$row['id'];
            $to      = (string)$row['to_email'];
            $subject = (string)$row['subject'];
            $body    = (string)$row['body'];
            $attempt = max(1, (int)($row['attempt_count'] ?? 1));

            try {
                $sent = $this->mailer->send($to, $subject, $body);
                if ($sent && $this->email->markAsSent($emailId, $claimToken)) {
                    $report['sent']++;
                    continue;
                }

                throw new \RuntimeException($sent
                    ? 'The delivery claim expired before completion.'
                    : 'The mail transport returned an unsuccessful result.');
            } catch (Throwable $e) {
                $delay = min(3600, $this->retryBaseSeconds * (2 ** min(10, $attempt - 1)));
                $this->email->releaseAfterFailure(
                    $emailId,
                    $claimToken,
                    $e->getMessage(),
                    $this->maxAttempts,
                    $delay
                );
                $report['failed']++;
                if ($attempt < $this->maxAttempts) {
                    $report['retrying']++;
                }
                $report['errors'][] = $attempt < $this->maxAttempts
                    ? "Delivery attempt failed for email {$emailId}; it will retry automatically."
                    : "Email {$emailId} reached the retry limit and needs administrator attention.";
                error_log(sprintf(
                    '[Permits email queue %s attempt %d/%d] %s: %s',
                    $emailId,
                    $attempt,
                    $this->maxAttempts,
                    $e::class,
                    $e->getMessage()
                ));
            }
        }

        return $report;
    }
}
