<?php
namespace Permits;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

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
 * - SMTP, PHP mail() or file log support
 */

class Mailer
{
    private string $from;
    private string $fromName;
    private string $driver;
    private string $smtpHost;
    private int $smtpPort;
    private string $smtpUser;
    private string $smtpPass;
    private string $smtpSecure;
    private int $smtpTimeout;
    private string $logDirectory;

    /**
     * @param array<string,mixed> $options Optional overrides for testing or custom transports
     */
    public function __construct(array $options = [])
    {
        $appHost = parse_url((string)($_ENV['APP_URL'] ?? 'http://localhost'), PHP_URL_HOST) ?: 'localhost';

        $this->from = (string)($options['from']
            ?? $_ENV['MAIL_FROM']
            ?? $_ENV['MAIL_FROM_ADDRESS']
            ?? ('noreply@' . $appHost));

        $this->fromName = (string)($options['from_name']
            ?? $_ENV['MAIL_FROM_NAME']
            ?? $_ENV['MAIL_FROM']
            ?? 'Permits System');

        $this->driver = strtolower((string)($options['driver']
            ?? $_ENV['MAIL_DRIVER']
            ?? (($_ENV['MAIL_USE_SMTP'] ?? 'false') === 'true' ? 'smtp' : 'mail')));

        $this->smtpHost = (string)($options['smtp_host']
            ?? $_ENV['SMTP_HOST']
            ?? $_ENV['MAIL_HOST']
            ?? '');

        $this->smtpPort = (int)($options['smtp_port']
            ?? $_ENV['SMTP_PORT']
            ?? $_ENV['MAIL_PORT']
            ?? 587);

        $this->smtpUser = (string)($options['smtp_user']
            ?? $_ENV['SMTP_USER']
            ?? $_ENV['MAIL_USERNAME']
            ?? '');

        $this->smtpPass = (string)($options['smtp_pass']
            ?? $_ENV['SMTP_PASS']
            ?? $_ENV['MAIL_PASSWORD']
            ?? '');

        $this->smtpSecure = strtolower((string)($options['smtp_secure']
            ?? $_ENV['SMTP_SECURE']
            ?? $_ENV['MAIL_ENCRYPTION']
            ?? 'tls'));

        $this->smtpTimeout = (int)($options['smtp_timeout'] ?? 30);

        $defaultLogDir = $options['default_log_dir'] ?? ($this->discoverProjectRoot() . '/storage/mail');
        $this->logDirectory = (string)($options['log_directory'] ?? $_ENV['MAIL_LOG_PATH'] ?? $defaultLogDir);
    }

    /**
     * Create a mailer instance seeded with settings stored in the database. Any
     * options supplied explicitly will override the persisted values.
     *
     * @param array<string,mixed> $options
     */
    public static function fromDatabase(Db $db, array $options = []): self
    {
        $stored = SystemSettings::mailerOptions($db);
        return new self(array_merge($stored, $options));
    }

    private function discoverProjectRoot(): string
    {
        $root = realpath(__DIR__ . '/..');
        return $root !== false ? $root : sys_get_temp_dir();
    }

    /**
     * Main send function
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $driver = $this->driver;

        if ($driver === 'smtp') {
            return $this->sendWithSmtp($to, $subject, $htmlBody, $textBody);
        }

        if ($driver === 'log') {
            return $this->sendToLog($to, $subject, $htmlBody, $textBody);
        }

        return $this->sendWithPhpMail($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Send email using PHP mail() function
     */
    private function sendWithPhpMail(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'From: ' . $this->fromName . ' <' . $this->from . '>';
        $headers[] = 'Reply-To: ' . $this->from;
        $headers[] = 'X-Mailer: PHP/' . PHP_VERSION;

        return mail($to, $subject, $htmlBody, implode("\r\n", $headers));
    }

    /**
     * Send email using a minimal SMTP client (AUTH LOGIN)
     */
    private function sendWithSmtp(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        if ($this->smtpHost === '') {
            throw new RuntimeException('SMTP host not configured. Set SMTP_HOST or MAIL_HOST.');
        }

        $recipients = array_filter(array_map('trim', preg_split('/[,;]+/', $to)));
        if (empty($recipients)) {
            throw new RuntimeException('No recipient email address provided for SMTP send.');
        }

        $secure = $this->smtpSecure;
        $host = $this->smtpHost;
        $port = $this->smtpPort > 0 ? $this->smtpPort : ($secure === 'ssl' ? 465 : 587);

        $scheme = $secure === 'ssl' ? 'ssl://' : '';
        $socket = @stream_socket_client(
            $scheme . $host . ':' . $port,
            $errno,
            $errstr,
            $this->smtpTimeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create([])
        );

        if (!$socket) {
            throw new RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $this->smtpTimeout);

        try {
            $this->expectReply($socket, 220, 'greeting');

            $domain = $this->localDomain();
            $this->sendCommand($socket, "EHLO {$domain}", 250);

            if ($secure === 'tls') {
                $this->sendCommand($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Failed to enable STARTTLS encryption.');
                }
                $this->sendCommand($socket, "EHLO {$domain}", 250);
            }

            if ($this->smtpUser !== '' && $this->smtpPass !== '') {
                $this->sendCommand($socket, 'AUTH LOGIN', 334);
                $this->sendCommand($socket, base64_encode($this->smtpUser), 334);
                $this->sendCommand($socket, base64_encode($this->smtpPass), 235);
            }

            $this->sendCommand($socket, 'MAIL FROM:<' . $this->from . '>', 250);
            foreach ($recipients as $recipient) {
                $this->sendCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            }

            $this->sendCommand($socket, 'DATA', 354);

            $message = $this->buildMimeMessage($recipients, $subject, $htmlBody, $textBody);
            $this->writeData($socket, $message);

            $this->sendCommand($socket, '.', 250);
            $this->sendCommand($socket, 'QUIT', 221);
        } catch (Throwable $e) {
            fclose($socket);
            throw $e;
        }

        fclose($socket);
        return true;
    }

    private function sendToLog(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $this->ensureDirectory($this->logDirectory);

        $ts = (new DateTimeImmutable('now'))->format('Y-m-d_H-i-s');
        $file = rtrim($this->logDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "mail-{$ts}-" . bin2hex(random_bytes(4)) . '.log';

        $payload = json_encode([
            'to'       => $to,
            'subject'  => $subject,
            'html'     => $htmlBody,
            'text'     => $textBody,
            'from'     => $this->from,
            'fromName' => $this->fromName,
            'driver'   => $this->driver,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            $payload = "Unable to encode mail payload";
        }

        return (bool)file_put_contents($file, $payload, LOCK_EX);
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create mail log directory: ' . $dir);
        }
    }

    /**
     * @param resource $socket
     * @param int|array<int> $expected
     */
    private function sendCommand($socket, string $command, $expected): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expectReply($socket, $expected, $command);
    }

    /**
     * @param resource $socket
     * @param int|array<int> $expectedCodes
     */
    private function expectReply($socket, $expectedCodes, string $context): void
    {
        $expected = (array)$expectedCodes;
        $response = '';

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        if ($response === '') {
            throw new RuntimeException("SMTP {$context} failed: empty response");
        }

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException("SMTP {$context} failed: received {$code} ({$response})");
        }
    }

    private function writeData($socket, string $data): void
    {
        $normalised = preg_replace("/(\r\n|\r|\n)/", "\r\n", $data);
        if ($normalised === null) {
            $normalised = $data;
        }

        $escaped = preg_replace('/^\./m', '..', $normalised);
        if ($escaped === null) {
            $escaped = $normalised;
        }

        fwrite($socket, $escaped . "\r\n");
    }

    private function buildMimeMessage(array $recipients, string $subject, string $htmlBody, ?string $textBody = null): string
    {
        $boundary = '=====' . bin2hex(random_bytes(16)) . '=====';
        $date = (new DateTimeImmutable('now'))->format('D, d M Y H:i:s O');
        $messageId = sprintf('<%s@%s>', bin2hex(random_bytes(8)), $this->localDomain());

        $headers = [
            'From: ' . $this->fromName . ' <' . $this->from . '>',
            'To: ' . implode(', ', $recipients),
            'Subject: ' . $subject,
            'Date: ' . $date,
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $parts = [];

        $text = $textBody ?? strip_tags($htmlBody);
        $parts[] = "--{$boundary}\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n\r\n" .
            $text . "\r\n";

        $parts[] = "--{$boundary}\r\n" .
            "Content-Type: text/html; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n\r\n" .
            $htmlBody . "\r\n";

        $parts[] = "--{$boundary}--\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n" . implode('', $parts);
    }

    private function localDomain(): string
    {
        $host = parse_url((string)($_ENV['APP_URL'] ?? ''), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }
        return 'localhost';
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
