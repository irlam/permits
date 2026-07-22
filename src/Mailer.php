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
    private bool $enabled;
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
        $appHost = parse_url((string)($_ENV['APP_URL'] ?? ''), PHP_URL_HOST);
        $fallbackFrom = is_string($appHost) && filter_var('noreply@' . $appHost, FILTER_VALIDATE_EMAIL) !== false
            ? 'noreply@' . $appHost
            : 'noreply@example.invalid';

        $this->enabled = self::booleanValue($options['enabled'] ?? $_ENV['EMAIL_ENABLED'] ?? false);

        $configuredFrom = trim((string)($options['from']
            ?? $_ENV['MAIL_FROM_ADDRESS']
            ?? $_ENV['MAIL_FROM']
            ?? ''));
        $this->from = $configuredFrom !== '' ? $configuredFrom : $fallbackFrom;
        self::assertEmailAddress($this->from, 'sender');

        $this->fromName = trim((string)($options['from_name']
            ?? $_ENV['MAIL_FROM_NAME']
            ?? 'Permits System'));
        if ($this->fromName === '') {
            $this->fromName = 'Permits System';
        }
        self::assertHeaderValue($this->fromName, 'sender name', 120);

        $this->driver = strtolower((string)($options['driver']
            ?? $_ENV['MAIL_DRIVER']
            ?? (($_ENV['MAIL_USE_SMTP'] ?? 'false') === 'true' ? 'smtp' : 'mail')));
        if (!in_array($this->driver, ['smtp', 'mail', 'log'], true)) {
            throw new RuntimeException('Unsupported email delivery method.');
        }

        $this->smtpHost = trim((string)($options['smtp_host']
            ?? $_ENV['SMTP_HOST']
            ?? $_ENV['MAIL_HOST']
            ?? ''));
        if ($this->smtpHost !== '' && !self::isValidSmtpHost($this->smtpHost)) {
            throw new RuntimeException('SMTP host is invalid.');
        }

        $this->smtpPort = (int)($options['smtp_port']
            ?? $_ENV['SMTP_PORT']
            ?? $_ENV['MAIL_PORT']
            ?? 587);
        if ($this->smtpPort < 1 || $this->smtpPort > 65535) {
            throw new RuntimeException('SMTP port must be between 1 and 65535.');
        }

        $this->smtpUser = (string)($options['smtp_user']
            ?? $_ENV['SMTP_USER']
            ?? $_ENV['MAIL_USERNAME']
            ?? '');
        self::assertHeaderValue($this->smtpUser, 'SMTP username', 255);

        $this->smtpPass = (string)($options['smtp_pass']
            ?? $_ENV['SMTP_PASS']
            ?? $_ENV['MAIL_PASSWORD']
            ?? '');
        if (strlen($this->smtpPass) > 4096) {
            throw new RuntimeException('SMTP password is too long.');
        }

        $this->smtpSecure = strtolower((string)($options['smtp_secure']
            ?? $_ENV['SMTP_SECURE']
            ?? $_ENV['MAIL_ENCRYPTION']
            ?? 'tls'));
        if ($this->smtpSecure === 'none') {
            $this->smtpSecure = '';
        }
        if (!in_array($this->smtpSecure, ['', 'tls', 'ssl'], true)) {
            throw new RuntimeException('SMTP encryption setting is invalid.');
        }

        $this->smtpTimeout = (int)($options['smtp_timeout']
            ?? $_ENV['SMTP_TIMEOUT']
            ?? $_ENV['MAIL_TIMEOUT']
            ?? 30);
        if ($this->smtpTimeout < 5 || $this->smtpTimeout > 120) {
            throw new RuntimeException('SMTP timeout must be between 5 and 120 seconds.');
        }

        $defaultLogDir = $options['default_log_dir'] ?? ($this->discoverProjectRoot() . '/data/mail');
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

    public function isEnabled(): bool
    {
        return $this->enabled;
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
        if (!$this->enabled) {
            throw new RuntimeException('Outbound email delivery is disabled.');
        }

        $recipients = self::normaliseRecipients($to);
        self::assertHeaderValue($subject, 'email subject', 500);
        $driver = $this->driver;

        if ($driver === 'smtp') {
            return $this->sendWithSmtp($recipients, $subject, $htmlBody, $textBody);
        }

        if ($driver === 'log') {
            return $this->sendToLog($recipients, $subject, $htmlBody, $textBody);
        }

        return $this->sendWithPhpMail($recipients, $subject, $htmlBody, $textBody);
    }

    /**
     * Send email using PHP mail() function
     */
    private function sendWithPhpMail(array $recipients, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'From: ' . $this->encodeHeader($this->fromName) . ' <' . $this->from . '>';
        $headers[] = 'Reply-To: ' . $this->from;
        $headers[] = 'X-Mailer: PHP/' . PHP_VERSION;

        return mail(implode(', ', $recipients), $this->encodeHeader($subject), $htmlBody, implode("\r\n", $headers));
    }

    /**
     * Send email using a minimal SMTP client (AUTH LOGIN)
     */
    private function sendWithSmtp(array $recipients, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        if ($this->smtpHost === '') {
            throw new RuntimeException('SMTP host not configured. Set SMTP_HOST or MAIL_HOST.');
        }

        $secure = $this->smtpSecure;
        $host = $this->smtpHost;
        $port = $this->smtpPort;

        $scheme = $secure === 'ssl' ? 'ssl://' : '';
        $peerName = trim($host, '[]');
        $socket = @stream_socket_client(
            $scheme . $host . ':' . $port,
            $errno,
            $errstr,
            $this->smtpTimeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'peer_name' => $peerName,
                    'allow_self_signed' => false,
                ],
            ])
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
                $this->sendCommand($socket, base64_encode($this->smtpUser), 334, 'AUTH username');
                $this->sendCommand($socket, base64_encode($this->smtpPass), 235, 'AUTH password');
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

    private function sendToLog(array $recipients, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $this->ensureDirectory($this->logDirectory);

        $ts = (new DateTimeImmutable('now'))->format('Y-m-d_H-i-s');
        $file = rtrim($this->logDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "mail-{$ts}-" . bin2hex(random_bytes(4)) . '.log';

        $payload = json_encode([
            'to'       => implode(', ', $recipients),
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

        $written = file_put_contents($file, $payload, LOCK_EX);
        if ($written !== false) {
            @chmod($file, 0640);
        }

        return $written !== false;
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create mail log directory: ' . $dir);
        }
    }

    private static function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalised = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($normalised === null) {
            throw new RuntimeException('Email enabled setting is invalid.');
        }

        return $normalised;
    }

    /** @return array<int,string> */
    private static function normaliseRecipients(string $to): array
    {
        $parts = preg_split('/[,;]+/', $to);
        $recipients = [];
        foreach (is_array($parts) ? $parts : [] as $recipient) {
            $recipient = trim($recipient);
            if ($recipient === '') {
                continue;
            }
            self::assertEmailAddress($recipient, 'recipient');
            $recipients[strtolower($recipient)] = $recipient;
        }

        if ($recipients === []) {
            throw new RuntimeException('No valid recipient email address was provided.');
        }
        if (count($recipients) > 50) {
            throw new RuntimeException('Too many recipient email addresses were provided.');
        }

        return array_values($recipients);
    }

    private static function assertEmailAddress(string $address, string $label): void
    {
        self::assertHeaderValue($address, $label . ' email address', 254);
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException(ucfirst($label) . ' email address is invalid.');
        }
    }

    private static function assertHeaderValue(string $value, string $label, int $maximumLength): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException(ucfirst($label) . ' must be a single line.');
        }
        if (mb_strlen($value, 'UTF-8') > $maximumLength) {
            throw new RuntimeException(ucfirst($label) . ' is too long.');
        }
    }

    private static function isValidSmtpHost(string $host): bool
    {
        if (strlen($host) > 253 || preg_match('#[\x00-\x20\x7F/\\\\]#', $host) === 1) {
            return false;
        }

        $ip = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i',
            $host
        ) === 1;
    }

    private function encodeHeader(string $value): string
    {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    /**
     * @param resource $socket
     * @param int|array<int> $expected
     */
    private function sendCommand($socket, string $command, $expected, ?string $safeContext = null): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expectReply($socket, $expected, $safeContext ?? $command);
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
            $safeResponse = preg_replace('/[\x00-\x1F\x7F]+/', ' ', trim($response)) ?? '';
            $safeResponse = mb_substr($safeResponse, 0, 300, 'UTF-8');
            throw new RuntimeException("SMTP {$context} failed: received {$code} ({$safeResponse})");
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
            'From: ' . $this->encodeHeader($this->fromName) . ' <' . $this->from . '>',
            'To: ' . implode(', ', $recipients),
            'Subject: ' . $this->encodeHeader($subject),
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
        if (is_string($host) && $host !== '' && self::isValidSmtpHost($host) && !str_contains($host, ':')) {
            return $host;
        }
        return 'localhost';
    }

    /** Build a public permit URL without exposing a database identifier. */
    private function publicPermitUrl(array $permitData): string
    {
        $baseUrl = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        $uniqueLink = trim((string)($permitData['unique_link'] ?? ''));

        if ($baseUrl === '' || $uniqueLink === '') {
            return $baseUrl !== '' ? $baseUrl : '/';
        }

        return $baseUrl . '/view-permit-public.php?link=' . rawurlencode($uniqueLink);
    }
    
    /**
     * Send permit expiring notification
     */
    public function sendPermitExpiring($permitData, $recipientEmail, $daysUntilExpiry) {
        $subject = "⚠️ Permit Expiring Soon: {$permitData['ref']}";
        
        $expiryDate = date('d/m/Y H:i', strtotime($permitData['valid_to']));
        $permitUrl = $this->publicPermitUrl((array)$permitData);
        
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
        
        $permitUrl = $this->publicPermitUrl((array)$permitData);
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
        
        $permitUrl = $this->publicPermitUrl((array)$permitData);
        
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
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $data[$key] = htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

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
