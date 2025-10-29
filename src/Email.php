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
        
        $stmt = $this->pdo->prepare("
            INSERT INTO email_queue (id, to_email, subject, body, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
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
        $subject = "Permit Approved: " . ($form['ref'] ?? 'Unknown');
        $body = $this->renderTemplate('permit-approved', [
            'form' => $form,
            'permitNo' => $form['ref'] ?? 'Unknown',
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
        $subject = "Permit Rejected: " . ($form['ref'] ?? 'Unknown');
        $body = $this->renderTemplate('permit-rejected', [
            'form' => $form,
            'permitNo' => $form['ref'] ?? 'Unknown',
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
        $subject = "Permit Expiring Soon: " . ($form['ref'] ?? 'Unknown');
        $body = $this->renderTemplate('permit-expiring', [
            'form' => $form,
            'permitNo' => $form['ref'] ?? 'Unknown',
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
        $subject = "New Permit Created: " . ($form['ref'] ?? 'Unknown');
        $body = $this->renderTemplate('permit-created', [
            'form' => $form,
            'permitNo' => $form['ref'] ?? 'Unknown',
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
            'decisionUrl' => $context['decisionUrl'] ?? ($context['approvalUrl'] ?? null),
            'quickApproveUrl' => $context['quickApproveUrl'] ?? null,
            'quickRejectUrl' => $context['quickRejectUrl'] ?? null,
            'viewUrl' => $context['viewUrl'] ?? null,
            'managerUrl' => $context['managerUrl'] ?? null,
            'expiresAt' => $context['expiresAt'] ?? null,
            'approvalUrl' => $context['decisionUrl'] ?? ($context['approvalUrl'] ?? null),
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
        $stmt = $this->pdo->prepare("
            SELECT * FROM email_queue 
            WHERE status = 'pending' 
            ORDER BY created_at ASC 
            LIMIT ?
        ");
        
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    /**
     * Mark an email as sent
     * 
     * @param string $id Email queue ID
     * @return bool Success status
     */
    public function markAsSent(string $id): bool {
        $stmt = $this->pdo->prepare("
            UPDATE email_queue 
            SET status = 'sent', sent_at = NOW() 
            WHERE id = ?
        ");
        
        return $stmt->execute([$id]);
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
            SET status = 'failed' 
            WHERE id = ?
        ");
        
        return $stmt->execute([$id]);
    }
}
