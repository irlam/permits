<?php
/**
 * Permits System - Email Notification Manager
 * 
 * Description: Handles sending email notifications for permit events
 * Name: Email.php
 * Last Updated: 21/10/2025 21:03:42 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Send email notifications when permits change status
 * - Send expiry reminder emails
 * - Send approval/rejection notifications
 * - Template-based email formatting
 * 
 * Features:
 * - HTML email templates
 * - Configurable SMTP settings
 * - Queue support for bulk emails
 * - Email logging and tracking
 */

namespace Permits;

use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Email notification manager for the Permits system
 */
class Email {
    /**
     * @var PDO Database connection
     */
    private PDO $pdo;
    
    /**
     * @var string Application root directory
     */
    private string $root;
    
    /**
     * Constructor
     * 
     * @param Db $db Database connection wrapper
     * @param string $root Application root directory path
     */
    public function __construct(Db $db, string $root) {
        $this->pdo = $db->pdo;
        $this->root = $root;
    }
    
    /**
     * Queue an email for sending
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject line
     * @param string $body HTML email body
     * @return string Email queue ID
     */
    public function queue(string $to, string $subject, string $body): string {
        $id = Uuid::uuid4()->toString();
        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "datetime('now')"
            : 'NOW()';
        
        $stmt = $this->pdo->prepare("
            INSERT INTO email_queue (id, to_email, subject, body, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', {$now})
        ");
        
        $stmt->execute([$id, $to, $subject, $body]);
        
        return $id;
    }
    
    /**
     * Send a permit approval notification
     * 
     * @param array $form Form/permit data
     * @param string $recipientEmail Email address to send to
     * @return string Email queue ID
     */
    public function sendApprovalNotification(array $form, string $recipientEmail): string {
        $reference = $form['ref_number'] ?? $form['ref'] ?? $form['id'] ?? 'Unknown';
        $subject = "Permit Approved: " . $reference;
        $body = $this->renderTemplate('permit-approved', [
            'form' => $form,
            'permitNo' => $reference,
            'siteBlock' => $form['site_block'] ?? 'Unknown',
            'validFrom' => $form['valid_from'] ?? 'N/A',
            'validTo' => $form['valid_to'] ?? 'N/A',
        ]);
        
        return $this->queue($recipientEmail, $subject, $body);
    }
    
    /**
     * Send a permit rejection notification
     * 
     * @param array $form Form/permit data
     * @param string $recipientEmail Email address to send to
     * @param string $reason Rejection reason
     * @return string Email queue ID
     */
    public function sendRejectionNotification(array $form, string $recipientEmail, string $reason = ''): string {
        $reference = $form['ref_number'] ?? $form['ref'] ?? $form['id'] ?? 'Unknown';
        $subject = "Permit Rejected: " . $reference;
        $body = $this->renderTemplate('permit-rejected', [
            'form' => $form,
            'permitNo' => $reference,
            'reason' => $reason,
        ]);
        
        return $this->queue($recipientEmail, $subject, $body);
    }
    
    /**
     * Send a permit expiry reminder
     * 
     * @param array $form Form/permit data
     * @param string $recipientEmail Email address to send to
     * @param int $daysUntilExpiry Number of days until expiry
     * @return string Email queue ID
     */
    public function sendExpiryReminder(array $form, string $recipientEmail, int $daysUntilExpiry): string {
        $reference = $form['ref_number'] ?? $form['ref'] ?? $form['id'] ?? 'Unknown';
        $subject = "Permit Expiring Soon: " . $reference;
        $body = $this->renderTemplate('permit-expiring', [
            'form' => $form,
            'permitNo' => $reference,
            'daysUntilExpiry' => $daysUntilExpiry,
            'expiryDate' => $form['valid_to'] ?? 'Unknown',
        ]);
        
        return $this->queue($recipientEmail, $subject, $body);
    }
    
    /**
     * Send a permit created notification
     * 
     * @param array $form Form/permit data
     * @param string $recipientEmail Email address to send to
     * @return string Email queue ID
     */
    public function sendCreatedNotification(array $form, string $recipientEmail): string {
        $reference = $form['ref_number'] ?? $form['ref'] ?? $form['id'] ?? 'Unknown';
        $subject = "New Permit Created: " . $reference;
        $body = $this->renderTemplate('permit-created', [
            'form' => $form,
            'permitNo' => $reference,
            'siteBlock' => $form['site_block'] ?? 'Unknown',
            'status' => $form['status'] ?? 'draft',
        ]);
        
        return $this->queue($recipientEmail, $subject, $body);
    }

    /**
     * Send a notification to approvers when a permit awaits approval.
     *
     * @param array $form Form/permit data (expects ref/ref_number, template_name, holder info)
     * @param string $recipientEmail Approval recipient email address
     * @param array $context Additional context such as URLs and recipient meta
     */
    public function sendPendingApprovalNotification(array $form, string $recipientEmail, array $context = []): string {
        $ref = $form['ref_number'] ?? $form['ref'] ?? $form['id'] ?? 'Permit';
        $subject = 'Permit Awaiting Approval: ' . $ref;

        $body = $this->renderTemplate('permit-awaiting-approval', [
            'form' => $form,
            'recipient' => $context['recipient'] ?? null,
            'approvalUrl' => $context['approvalUrl'] ?? null,
            'viewUrl' => $context['viewUrl'] ?? null,
            'subject' => $subject,
        ]);

        return $this->queue($recipientEmail, $subject, $body);
    }
    
    /**
     * Render an email template with data
     * 
     * @param string $templateName Template name (without .php extension)
     * @param array $data Data to pass to the template
     * @return string Rendered HTML
     */
    private function renderTemplate(string $templateName, array $data): string {
        $templatePath = $this->root . '/templates/emails/' . $templateName . '.php';
        
        // Check if template exists
        if (!file_exists($templatePath)) {
            // Fallback to simple HTML if template doesn't exist
            return $this->createSimpleEmail($templateName, $data);
        }
        
        // Extract data for use in template
        extract($data);
        
        // Capture template output
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }
    
    /**
     * Create a simple HTML email when template doesn't exist
     * 
     * @param string $type Email type
     * @param array $data Email data
     * @return string Simple HTML email
     */
    private function createSimpleEmail(string $type, array $data): string {
        $baseUrl = $_ENV['APP_URL'] ?? 'http://localhost:8080';
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #3b82f6; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9fafb; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 12px; }
        .btn { display: inline-block; padding: 12px 24px; background: #3b82f6; color: white; text-decoration: none; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Permits System</h1>
        </div>
        <div class="content">
            <h2>' . htmlspecialchars(ucwords(str_replace('-', ' ', $type))) . '</h2>';
        
        foreach ($data as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $html .= '<p><strong>' . htmlspecialchars(ucwords(str_replace('_', ' ', $key))) . ':</strong> ' . htmlspecialchars($value) . '</p>';
            }
        }
        
        $html .= '
            <p style="margin-top: 30px;">
                <a href="' . htmlspecialchars($baseUrl) . '" class="btn">View Permits System</a>
            </p>
        </div>
        <div class="footer">
            <p>This is an automated message from the Permits System.</p>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Get pending emails from the queue
     * 
     * @param int $limit Maximum number of emails to retrieve
     * @return array Array of pending email records
     */
    public function getPendingEmails(int $limit = 50): array {
        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "datetime('now')"
            : 'NOW()';
        $stmt = $this->pdo->prepare("
            SELECT * FROM email_queue 
            WHERE status = 'pending'
              AND (available_at IS NULL OR available_at <= {$now})
            ORDER BY created_at ASC 
            LIMIT ?
        ");
        
        $stmt->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Atomically claim queued messages for one worker.
     *
     * MySQL uses a single ordered UPDATE so it remains safe on MySQL 5.7 and
     * MariaDB without SKIP LOCKED. SQLite serialises the short claim section
     * with BEGIN IMMEDIATE. Callers should claim one message at a time so work
     * is never left idle long enough to be mistaken for a crashed worker.
     *
     * @return array<int,array<string,mixed>>
     */
    public function claimPendingEmails(
        int $limit,
        string $claimToken,
        int $maxAttempts = 5,
        int $staleMinutes = 15
    ): array {
        $limit = max(1, min(500, $limit));
        $maxAttempts = max(1, min(20, $maxAttempts));
        $staleMinutes = max(5, min(240, $staleMinutes));
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $claimToken) !== 1) {
            throw new \InvalidArgumentException('Invalid email queue claim token.');
        }

        $this->recoverStaleClaims($maxAttempts, $staleMinutes);
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $sql = "
                UPDATE email_queue
                SET status = 'processing', claim_token = ?, claimed_at = NOW(),
                    attempt_count = attempt_count + 1
                WHERE status = 'pending'
                  AND attempt_count < ?
                  AND (available_at IS NULL OR available_at <= NOW())
                ORDER BY created_at ASC
                LIMIT {$limit}
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$claimToken, $maxAttempts]);
        } elseif ($driver === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $select = $this->pdo->prepare("
                    SELECT id FROM email_queue
                    WHERE status = 'pending'
                      AND attempt_count < ?
                      AND (available_at IS NULL OR available_at <= datetime('now'))
                    ORDER BY created_at ASC
                    LIMIT ?
                ");
                $select->bindValue(1, $maxAttempts, PDO::PARAM_INT);
                $select->bindValue(2, $limit, PDO::PARAM_INT);
                $select->execute();
                $ids = $select->fetchAll(PDO::FETCH_COLUMN);

                $claim = $this->pdo->prepare("
                    UPDATE email_queue
                    SET status = 'processing', claim_token = ?, claimed_at = datetime('now'),
                        attempt_count = attempt_count + 1
                    WHERE id = ? AND status = 'pending'
                ");
                foreach ($ids as $id) {
                    $claim->execute([$claimToken, $id]);
                }
                $this->pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (\Throwable $rollbackError) {
                    // Preserve the original claim error.
                }
                throw $e;
            }
        } else {
            throw new \RuntimeException('Unsupported email queue database driver.');
        }

        $stmt = $this->pdo->prepare("
            SELECT * FROM email_queue
            WHERE status = 'processing' AND claim_token = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$claimToken]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function releaseAfterFailure(
        string $id,
        string $claimToken,
        string $error,
        int $maxAttempts = 5,
        int $delaySeconds = 60
    ): bool {
        $maxAttempts = max(1, min(20, $maxAttempts));
        $delaySeconds = max(30, min(86400, $delaySeconds));
        $error = preg_replace('/[\x00-\x1F\x7F]+/', ' ', trim($error)) ?? 'Delivery failed.';
        $error = mb_substr($error !== '' ? $error : 'Delivery failed.', 0, 1000, 'UTF-8');
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $availableAt = $driver === 'sqlite'
            ? "datetime('now', '+{$delaySeconds} seconds')"
            : "DATE_ADD(NOW(), INTERVAL {$delaySeconds} SECOND)";

        $stmt = $this->pdo->prepare("
            UPDATE email_queue
            SET status = CASE WHEN attempt_count >= ? THEN 'failed' ELSE 'pending' END,
                available_at = CASE WHEN attempt_count >= ? THEN NULL ELSE {$availableAt} END,
                claimed_at = NULL,
                claim_token = NULL,
                last_error = ?
            WHERE id = ? AND status = 'processing' AND claim_token = ?
        ");
        $stmt->execute([$maxAttempts, $maxAttempts, $error, $id, $claimToken]);
        return $stmt->rowCount() === 1;
    }

    private function recoverStaleClaims(int $maxAttempts, int $staleMinutes): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $staleBefore = $driver === 'sqlite'
            ? "datetime('now', '-{$staleMinutes} minutes')"
            : "DATE_SUB(NOW(), INTERVAL {$staleMinutes} MINUTE)";
        $now = $driver === 'sqlite' ? "datetime('now')" : 'NOW()';

        $stmt = $this->pdo->prepare("
            UPDATE email_queue
            SET status = CASE WHEN attempt_count >= ? THEN 'failed' ELSE 'pending' END,
                available_at = CASE WHEN attempt_count >= ? THEN NULL ELSE {$now} END,
                claimed_at = NULL,
                claim_token = NULL,
                last_error = CASE
                    WHEN last_error IS NULL OR last_error = '' THEN 'Delivery worker did not complete the claim.'
                    ELSE last_error
                END
            WHERE status = 'processing'
              AND (claimed_at IS NULL OR claimed_at < {$staleBefore})
        ");
        $stmt->execute([$maxAttempts, $maxAttempts]);

        $exhausted = $this->pdo->prepare("
            UPDATE email_queue
            SET status = 'failed', available_at = NULL
            WHERE status = 'pending' AND attempt_count >= ?
        ");
        $exhausted->execute([$maxAttempts]);
    }
    
    /**
     * Mark an email as sent
     * 
     * @param string $id Email queue ID
     * @return bool Success status
     */
    public function markAsSent(string $id, ?string $claimToken = null): bool {
        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "datetime('now')"
            : 'NOW()';
        $stmt = $this->pdo->prepare("
            UPDATE email_queue 
            SET status = 'sent', sent_at = {$now}, available_at = NULL,
                claimed_at = NULL, claim_token = NULL, last_error = NULL
            WHERE id = ?" . ($claimToken !== null ? " AND status = 'processing' AND claim_token = ?" : '') . "
        ");
        
        $parameters = $claimToken !== null ? [$id, $claimToken] : [$id];
        $stmt->execute($parameters);
        return $stmt->rowCount() === 1;
    }
    
    /**
     * Mark an email as failed
     * 
     * @param string $id Email queue ID
     * @return bool Success status
     */
    public function markAsFailed(string $id): bool {
        $stmt = $this->pdo->prepare("
            UPDATE email_queue 
            SET status = 'failed', available_at = NULL, claimed_at = NULL, claim_token = NULL
            WHERE id = ?
        ");
        
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }
}
