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

    public function __construct(Email $email, Mailer $mailer)
    {
        $this->email  = $email;
        $this->mailer = $mailer;
    }

    /**
     * Process pending emails.
     *
     * @return array{processed:int,sent:int,failed:int,errors:array<int,string>}
     */
    public function process(int $limit = 50): array
    {
        $report = [
            'processed' => 0,
            'sent'      => 0,
            'failed'    => 0,
            'errors'    => [],
        ];

        $pending = $this->email->getPendingEmails($limit);
        if (empty($pending)) {
            return $report;
        }

        foreach ($pending as $row) {
            $report['processed']++;
            $emailId = (string)$row['id'];
            $to      = (string)$row['to_email'];
            $subject = (string)$row['subject'];
            $body    = (string)$row['body'];

            try {
                $sent = $this->mailer->send($to, $subject, $body);
                if ($sent) {
                    $this->email->markAsSent($emailId);
                    $report['sent']++;
                } else {
                    $this->email->markAsFailed($emailId);
                    $report['failed']++;
                    $report['errors'][] = "Mailer returned false for email {$emailId}";
                }
            } catch (Throwable $e) {
                $this->email->markAsFailed($emailId);
                $report['failed']++;
                $report['errors'][] = '[' . $emailId . '] ' . $e->getMessage();
            }
        }

        return $report;
    }
}
