<?php
/**
 * Permits System - Email Notification Sender (Cron Job)
 * 
 * Description: Processes the email queue and sends pending notifications
 * Name: send-notifications.php
 * Last Updated: 21/10/2025 21:03:42 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Process email queue and send pending emails
 * - Send expiry reminder notifications
 * - Track email delivery status
 * - Handle SMTP configuration
 * 
 * Usage:
 * Run from command line: php bin/send-notifications.php
 * Or via cron: */5 * * * * php /path/to/permits/bin/send-notifications.php
 * 
 * Cron Schedule Recommendation:
 * - Every 5 minutes: */5 * * * *
 * - Every 15 minutes: */15 * * * *
 * - Every hour: 0 * * * *
 */

// Load application bootstrap
[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

use Permits\Email;

echo "========================================\n";
echo "Email Notification Sender\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// Initialize email handler
$emailHandler = new Email($db, $root);

// Check if email is enabled in settings
$emailEnabled = false;
try {
    $stmt = $db->pdo->prepare("SELECT value FROM settings WHERE `key` = 'email_enabled'");
    $stmt->execute();
    $setting = $stmt->fetch();
    $emailEnabled = $setting && $setting['value'] === 'true';
} catch (\Exception $e) {
    echo "⚠ Settings table not found. Email sending disabled.\n";
    echo "  Run: php bin/migrate-features.php\n\n";
    exit(0);
}

if (!$emailEnabled) {
    echo "ℹ Email notifications are disabled in settings.\n";
    echo "  To enable: UPDATE settings SET value='true' WHERE `key`='email_enabled'\n\n";
    exit(0);
}

// Get SMTP settings
$smtpHost = '';
$smtpPort = 587;
$smtpUser = '';
$smtpPass = '';
$smtpFrom = 'noreply@permits.local';

try {
    $settings = $db->pdo->query("SELECT `key`, value FROM settings WHERE `key` LIKE 'smtp_%'")->fetchAll();
    foreach ($settings as $setting) {
        switch ($setting['key']) {
            case 'smtp_host': $smtpHost = $setting['value']; break;
            case 'smtp_port': $smtpPort = (int)$setting['value']; break;
            case 'smtp_user': $smtpUser = $setting['value']; break;
            case 'smtp_pass': $smtpPass = $setting['value']; break;
            case 'smtp_from': $smtpFrom = $setting['value']; break;
        }
    }
} catch (\Exception $e) {
    echo "❌ Error loading SMTP settings: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Validate SMTP configuration
if (empty($smtpHost)) {
    echo "⚠ SMTP host not configured. Cannot send emails.\n";
    echo "  Configure SMTP settings in the settings table.\n\n";
    exit(0);
}

echo "SMTP Configuration:\n";
echo "  Host: $smtpHost\n";
echo "  Port: $smtpPort\n";
echo "  User: " . ($smtpUser ?: '(none)') . "\n";
echo "  From: $smtpFrom\n\n";

// Process pending emails
echo "Processing email queue...\n";
$pendingEmails = $emailHandler->getPendingEmails(50);
echo "Found " . count($pendingEmails) . " pending email(s)\n\n";

$sent = 0;
$failed = 0;

foreach ($pendingEmails as $email) {
    echo "Processing email #{$email['id']}...\n";
    echo "  To: {$email['to_email']}\n";
    echo "  Subject: {$email['subject']}\n";
    
    try {
        // In a production environment, you would use a proper SMTP library here
        // For now, we'll use PHP's mail() function or mark as sent for demonstration
        
        // Build email headers
        $headers = [
            'From: ' . $smtpFrom,
            'Content-Type: text/html; charset=UTF-8',
            'MIME-Version: 1.0',
        ];
        
        // Attempt to send email
        // Note: In production, use a library like PHPMailer or Symfony Mailer for SMTP
        $success = mail(
            $email['to_email'],
            $email['subject'],
            $email['body'],
            implode("\r\n", $headers)
        );
        
        if ($success) {
            $emailHandler->markAsSent($email['id']);
            echo "  ✓ Email sent successfully\n";
            $sent++;
        } else {
            $emailHandler->markAsFailed($email['id']);
            echo "  ✗ Failed to send email\n";
            $failed++;
        }
    } catch (\Exception $e) {
        $emailHandler->markAsFailed($email['id']);
        echo "  ✗ Error: " . $e->getMessage() . "\n";
        $failed++;
    }
    
    echo "\n";
}

// Send expiry reminders
echo "Checking for expiring permits...\n";

try {
    // Find permits expiring in 7 days
    $stmt = $db->pdo->prepare("
        SELECT f.*, DATEDIFF(f.valid_to, NOW()) as days_until_expiry
        FROM forms f
        WHERE f.status IN ('active', 'issued')
        AND f.valid_to IS NOT NULL
        AND DATEDIFF(f.valid_to, NOW()) BETWEEN 1 AND 7
        ORDER BY f.valid_to ASC
        LIMIT 20
    ");
    $stmt->execute();
    $expiringPermits = $stmt->fetchAll();
    
    echo "Found " . count($expiringPermits) . " permit(s) expiring soon\n\n";
    
    foreach ($expiringPermits as $permit) {
        // Check if we've already sent a reminder recently
        $checkStmt = $db->pdo->prepare("
            SELECT COUNT(*) FROM email_queue 
            WHERE to_email LIKE ? 
            AND subject LIKE ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $checkStmt->execute(['%' . $permit['ref'] . '%', '%Expiring%']);
        
        if ($checkStmt->fetchColumn() > 0) {
            echo "  Skipping {$permit['ref']} - reminder already sent today\n";
            continue;
        }
        
        // Queue expiry reminder
        // Note: In production, you'd get the actual recipient email from user/contact data
        $recipientEmail = $smtpFrom; // Placeholder - replace with actual recipient
        
        $emailHandler->sendExpiryReminder(
            $permit,
            $recipientEmail,
            (int)$permit['days_until_expiry']
        );
        
        echo "  ✓ Queued expiry reminder for {$permit['ref']} ({$permit['days_until_expiry']} days)\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Error checking expiring permits: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";
echo "Summary:\n";
echo "  Emails sent: $sent\n";
echo "  Failed: $failed\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";
