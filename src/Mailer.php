<?php
/**
 * Email Mailer Class
 * 
 * Description: Handles sending emails for permit notifications
 * Name: Mailer.php
 * 
 * Features:
 * - Send permit expiry notifications
 * - Send permit created notifications
 * - Send status change notifications
 * - HTML email templates
 * - SMTP or PHP mail() support
 */

class Mailer {
    private $from;
    private $fromName;
    private $useSmtp;
    private $smtpHost;
    private $smtpPort;
    private $smtpUser;
    private $smtpPass;
    private $smtpSecure;
    
    /**
     * Initialize mailer with configuration
     */
    public function __construct() {
        // Load from environment
        $this->from = $_ENV['MAIL_FROM'] ?? 'noreply@' . parse_url($_ENV['APP_URL'], PHP_URL_HOST);
        $this->fromName = $_ENV['MAIL_FROM_NAME'] ?? 'Permits System';
        $this->useSmtp = ($_ENV['MAIL_USE_SMTP'] ?? 'false') === 'true';
        
        // SMTP settings
        $this->smtpHost = $_ENV['SMTP_HOST'] ?? '';
        $this->smtpPort = intval($_ENV['SMTP_PORT'] ?? 587);
        $this->smtpUser = $_ENV['SMTP_USER'] ?? '';
        $this->smtpPass = $_ENV['SMTP_PASS'] ?? '';
        $this->smtpSecure = $_ENV['SMTP_SECURE'] ?? 'tls'; // tls or ssl
    }
    
    /**
     * Send email using PHP mail() function
     */
    private function sendWithPhpMail($to, $subject, $htmlBody, $textBody = null) {
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'From: ' . $this->fromName . ' <' . $this->from . '>';
        $headers[] = 'Reply-To: ' . $this->from;
        $headers[] = 'X-Mailer: PHP/' . phpversion();
        
        return mail($to, $subject, $htmlBody, implode("\r\n", $headers));
    }
    
    /**
     * Send email using SMTP (requires PHPMailer or similar)
     * For now, falls back to PHP mail()
     */
    private function sendWithSmtp($to, $subject, $htmlBody, $textBody = null) {
        // TODO: Implement SMTP sending with PHPMailer if installed
        // For now, fallback to PHP mail()
        return $this->sendWithPhpMail($to, $subject, $htmlBody, $textBody);
    }
    
    /**
     * Main send function
     */
    public function send($to, $subject, $htmlBody, $textBody = null) {
        if ($this->useSmtp && !empty($this->smtpHost)) {
            return $this->sendWithSmtp($to, $subject, $htmlBody, $textBody);
        } else {
            return $this->sendWithPhpMail($to, $subject, $htmlBody, $textBody);
        }
    }
    
    /**
     * Send permit expiring notification
     */
    public function sendPermitExpiring($permitData, $recipientEmail, $daysUntilExpiry) {
        $subject = "⚠️ Permit Expiring Soon: {$permitData['ref']}";
        
        $expiryDate = date('d/m/Y H:i', strtotime($permitData['valid_to']));
        $permitUrl = $_ENV['APP_URL'] . '/form/' . $permitData['id'];
        
        $htmlBody = $this->getEmailTemplate('expiring', [
            'permit_ref' => $permitData['ref'],
            'template_type' => $permitData['template_id'],
            'site_block' => $permitData['site_block'] ?? 'N/A',
            'expiry_date' => $expiryDate,
            'days_until_expiry' => $daysUntilExpiry,
            'permit_url' => $permitUrl,
            'status' => $permitData['status']
        ]);
        
        return $this->send($recipientEmail, $subject, $htmlBody);
    }
    
    /**
     * Send permit created notification
     */
    public function sendPermitCreated($permitData, $recipientEmail) {
        $subject = "✅ New Permit Created: {$permitData['ref']}";
        
        $permitUrl = $_ENV['APP_URL'] . '/form/' . $permitData['id'];
        $validFrom = date('d/m/Y H:i', strtotime($permitData['valid_from']));
        $validTo = date('d/m/Y H:i', strtotime($permitData['valid_to']));
        
        $htmlBody = $this->getEmailTemplate('created', [
            'permit_ref' => $permitData['ref'],
            'template_type' => $permitData['template_id'],
            'site_block' => $permitData['site_block'] ?? 'N/A',
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'permit_url' => $permitUrl,
            'status' => $permitData['status']
        ]);
        
        return $this->send($recipientEmail, $subject, $htmlBody);
    }
    
    /**
     * Send status change notification
     */
    public function sendStatusChanged($permitData, $recipientEmail, $oldStatus, $newStatus) {
        $subject = "🔄 Permit Status Changed: {$permitData['ref']}";
        
        $permitUrl = $_ENV['APP_URL'] . '/form/' . $permitData['id'];
        
        $htmlBody = $this->getEmailTemplate('status_changed', [
            'permit_ref' => $permitData['ref'],
            'template_type' => $permitData['template_id'],
            'site_block' => $permitData['site_block'] ?? 'N/A',
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'permit_url' => $permitUrl
        ]);
        
        return $this->send($recipientEmail, $subject, $htmlBody);
    }
    
    /**
     * Get email template HTML
     */
    private function getEmailTemplate($type, $data) {
        $baseTemplate = $this->getBaseTemplate();
        
        $content = '';
        
        switch($type) {
            case 'expiring':
                $urgency = $data['days_until_expiry'] <= 1 ? 'URGENT' : 'WARNING';
                $urgencyColor = $data['days_until_expiry'] <= 1 ? '#ef4444' : '#f59e0b';
                
                $content = "
                    <div style='background:{$urgencyColor};color:#fff;padding:12px;border-radius:8px;margin-bottom:20px;text-align:center'>
                        <strong>{$urgency}</strong> - This permit expires in {$data['days_until_expiry']} day(s)
                    </div>
                    <h2 style='color:#e5e7eb;margin-bottom:20px'>Permit Expiring Soon</h2>
                    <table style='width:100%;border-collapse:collapse'>
                        <tr><td style='padding:8px;border-bottom:1px solid #1f2937;color:#94a3b8'>Permit Reference:</td><td style='padding:8px;border-bottom:1px solid #1f2937;color:#e5e7eb'><strong>{$data['permit_ref']}</strong></td></tr>
                        <tr><td style='padding:8px;border-bottom:1px solid #1f2937;color:#94a3b8'>Type:</td><td style='padding:8px;border-bottom:1px solid #1f2937;color:#e5e7eb'>{$data['template_type']}</td></tr>
                        <tr><td style='padding:8px;border-bottom:1px solid #1f2937;color:#94a3b8'>Location:</td><td style='padding:8px;border-bottom:1px solid #1f2937;color:#e5e7eb'>{$data['site_block']}</td></tr>
                        <tr><td style='padding:8px;border-bottom:1px solid #1f2937;color:#94a3b8'>Expires:</td><td style='padding:8px;border-bottom:1px solid #1f2937;color:#e5e7eb'><strong>{$data['expiry_date']}</strong></td></tr>
                        <tr><td style='padding:8px;border-bottom:1px solid #1f2937;color:#94a3b8'>Status:</td><td style='padding:8px;border-bottom:1px solid #1f2937;color:#e5e7eb'><span style='text-transform:uppercase'>{$data['status']}</span></td></tr>
                    </table>
                    <div style='margin-top:30px;text-align:center'>
                        <a href='{$data['permit_url']}' style='background:#0ea5e9;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600'>View Permit</a>
                    </div>
                    <p style='color:#94a3b8;margin-top:20px;font-size:14px'>Please review this permit and take appropriate action before it expires.</p>
                ";
                break;
                
            case 'created':
                $content = "
                    <h2 style='color:#e5e7eb;margin-bottom:20px'>New Permit Created</h2>
                    <table style='width:100%;border-collapse:collapse'>
                        <tr><td style='padding:8px;border-bottom:1px solid #1f2937;color:#94a3b8'>Permit Reference:</td><td style='padding:8px;border-bottom:1px solid #1f2937;color:#e5e7eb'><strong>{$data['permit_ref']}</strong></td></tr>
                        <tr><td style='padding:8px;border-bottom:1px solid #1f2937;color:#94a3b8'>Type:</td><td style='padding:8px;border-bottom:1px solid #1f2937;color:#e5e7eb'>{$data['template_type']}</td></tr>
                        <tr><td style='padding:8px;border-bottom:1px solid #1f2937;color:#94a3b8'>Location:</td><td style='padding:8px;border-bottom:1px solid #1f2937;color:#e5e7eb'>{$data['site_block']}</td></tr>
                        <tr><td style='padding:8px;border-bottom:1px solid #1f2937;color:#94a3b8'>Valid From:</td><td style='padding:8px;border-bottom:1px solid #1f2937;color:#e5e7eb'>{$data['valid_from']}</td></tr>
                        <tr><td style='padding:8px;border-bottom:1px solid #1f2937;color:#94a3b8'>Valid To:</td><td style='padding:8px;border-bottom:1px solid #1f2937;color:#e5e7eb'>{$data['valid_to']}</td></tr>
                        <tr><td style='padding:8px;border-bottom:1px solid #1f2937;color:#94a3b8'>Status:</td><td style='padding:8px;border-bottom:1px solid #1f2937;color:#e5e7eb'><span style='text-transform:uppercase'>{$data['status']}</span></td></tr>
                    </table>
                    <div style='margin-top:30px;text-align:center'>
                        <a href='{$data['permit_url']}' style='background:#0ea5e9;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600'>View Permit</a>
                    </div>
                ";
                break;
                
            case 'status_changed':
                $content = "
                    <h2 style='color:#e5e7eb;margin-bottom:20px'>Permit Status Changed</h2>
                    <div style='background:#111827;border:1px solid #1f2937;padding:16px;border-radius:8px;margin-bottom:20px;text-align:center'>
                        <span style='color:#94a3b8'>{$data['old_status']}</span>
                        <span style='color:#0ea5e9;margin:0 12px'>→</span>
                        <strong style='color:#10b981;text-transform:uppercase'>{$data['new_status']}</strong>
                    </div>
                    <table style='width:100%;border-collapse:collapse'>
                        <tr><td style='padding:8px;border-bottom:1px solid #1f2937;color:#94a3b8'>Permit Reference:</td><td style='padding:8px;border-bottom:1px solid #1f2937;color:#e5e7eb'><strong>{$data['permit_ref']}</strong></td></tr>
                        <tr><td style='padding:8px;border-bottom:1px solid #1f2937;color:#94a3b8'>Type:</td><td style='padding:8px;border-bottom:1px solid #1f2937;color:#e5e7eb'>{$data['template_type']}</td></tr>
                        <tr><td style='padding:8px;border-bottom:1px solid #1f2937;color:#94a3b8'>Location:</td><td style='padding:8px;border-bottom:1px solid #1f2937;color:#e5e7eb'>{$data['site_block']}</td></tr>
                    </table>
                    <div style='margin-top:30px;text-align:center'>
                        <a href='{$data['permit_url']}' style='background:#0ea5e9;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600'>View Permit</a>
                    </div>
                ";
                break;
        }
        
        return str_replace('{{CONTENT}}', $content, $baseTemplate);
    }
    
    /**
     * Base email template
     */
    private function getBaseTemplate() {
        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
</head>
<body style='margin:0;padding:0;font-family:system-ui,-apple-system,sans-serif;background:#0a101a;color:#e5e7eb'>
    <table width='100%' cellpadding='0' cellspacing='0' style='background:#0a101a;padding:40px 20px'>
        <tr>
            <td align='center'>
                <table width='600' cellpadding='0' cellspacing='0' style='background:#111827;border:1px solid #1f2937;border-radius:12px;padding:30px'>
                    <tr>
                        <td>
                            <div style='text-align:center;margin-bottom:30px'>
                                <h1 style='color:#0ea5e9;margin:0;font-size:24px'>🛡️ Permits System</h1>
                            </div>
                            {{CONTENT}}
                            <div style='margin-top:40px;padding-top:20px;border-top:1px solid #1f2937;text-align:center;font-size:12px;color:#6b7280'>
                                <p>This is an automated notification from Permits System.</p>
                                <p>Please do not reply to this email.</p>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
        ";
    }
}
