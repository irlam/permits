<?php
/**
 * Email Notifications Script
 * 
 * Description: Sends email notifications for expiring permits and other events
 * Name: send-notifications.php
 * 
 * What it does:
 * - Checks for permits expiring within 24 hours
 * - Checks for permits expiring within 7 days
 * - Sends email notifications to configured recipients
 * - Logs all sent notifications
 * 
 * Setup as Cron Job:
 * Run every hour: 0 * * * * /usr/bin/php /path/to/bin/send-notifications.php
 * Run twice daily: 0 9,17 * * * /usr/bin/php /path/to/bin/send-notifications.php
 * 
 * Manual run: php bin/send-notifications.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Mailer.php';
use Ramsey\Uuid\Uuid;

// Load environment variables
[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

// Initialize mailer
$mailer = new Mailer();

// Get notification recipients from environment (comma-separated emails)
$notificationEmails = $_ENV['NOTIFICATION_EMAILS'] ?? '';
if (empty($notificationEmails)) {
    echo "[" . date('Y-m-d H:i:s') . "] No notification emails configured. Set NOTIFICATION_EMAILS in .env\n";
    exit(0);
}

$recipients = array_map('trim', explode(',', $notificationEmails));

echo "[" . date('Y-m-d H:i:s') . "] Email Notifications Script Starting...\n";
echo "Recipients: " . implode(', ', $recipients) . "\n\n";

$now = date('Y-m-d H:i:s');
$totalSent = 0;
$totalErrors = 0;

// ============================================
// CHECK 1: Permits expiring within 24 hours
// ============================================
echo "Checking permits expiring within 24 hours...\n";

$twentyFourHours = date('Y-m-d H:i:s', strtotime('+24 hours'));
$expiring24h = $db->pdo->prepare("
    SELECT id, ref, template_id, site_block, valid_to, status
    FROM forms
    WHERE status IN ('issued', 'active')
    AND valid_to BETWEEN ? AND ?
    AND id NOT IN (
        SELECT form_id FROM form_events 
        WHERE type = 'email_sent' 
        AND payload LIKE '%24h_expiry%'
        AND at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    )
");
$expiring24h->execute([$now, $twentyFourHours]);
$permits24h = $expiring24h->fetchAll();

echo "Found " . count($permits24h) . " permit(s) expiring within 24 hours\n";

foreach ($permits24h as $permit) {
    foreach ($recipients as $email) {
        try {
            $success = $mailer->sendPermitExpiring($permit, $email, 1);
            
            if ($success) {
                // Log email sent event
                $evt = $db->pdo->prepare("INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?,?,?,?,?)");
                $evt->execute([
                    Uuid::uuid4()->toString(),
                    $permit['id'],
                    'email_sent',
                    'system',
                    json_encode([
                        'type' => '24h_expiry',
                        'recipient' => $email,
                        'permit_ref' => $permit['ref']
                    ])
                ]);
                
                echo "  ✓ Sent 24h expiry email for {$permit['ref']} to {$email}\n";
                $totalSent++;
            } else {
                echo "  ✗ Failed to send email for {$permit['ref']} to {$email}\n";
                $totalErrors++;
            }
        } catch (Exception $e) {
            echo "  ✗ Error sending email for {$permit['ref']}: " . $e->getMessage() . "\n";
            $totalErrors++;
        }
    }
}

// ============================================
// CHECK 2: Permits expiring within 7 days
// ============================================
echo "\nChecking permits expiring within 7 days...\n";

$sevenDays = date('Y-m-d H:i:s', strtotime('+7 days'));
$expiring7d = $db->pdo->prepare("
    SELECT id, ref, template_id, site_block, valid_to, status
    FROM forms
    WHERE status IN ('issued', 'active')
    AND valid_to BETWEEN ? AND ?
    AND id NOT IN (
        SELECT form_id FROM form_events 
        WHERE type = 'email_sent' 
        AND payload LIKE '%7d_expiry%'
        AND at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    )
");
$expiring7d->execute([$twentyFourHours, $sevenDays]);
$permits7d = $expiring7d->fetchAll();

echo "Found " . count($permits7d) . " permit(s) expiring within 7 days\n";

foreach ($permits7d as $permit) {
    foreach ($recipients as $email) {
        try {
            $hoursUntilExpiry = (strtotime($permit['valid_to']) - time()) / 3600;
            $daysUntilExpiry = ceil($hoursUntilExpiry / 24);
            
            $success = $mailer->sendPermitExpiring($permit, $email, $daysUntilExpiry);
            
            if ($success) {
                // Log email sent event
                $evt = $db->pdo->prepare("INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?,?,?,?,?)");
                $evt->execute([
                    Uuid::uuid4()->toString(),
                    $permit['id'],
                    'email_sent',
                    'system',
                    json_encode([
                        'type' => '7d_expiry',
                        'recipient' => $email,
                        'permit_ref' => $permit['ref'],
                        'days_until_expiry' => $daysUntilExpiry
                    ])
                ]);
                
                echo "  ✓ Sent 7d expiry email for {$permit['ref']} to {$email}\n";
                $totalSent++;
            } else {
                echo "  ✗ Failed to send email for {$permit['ref']} to {$email}\n";
                $totalErrors++;
            }
        } catch (Exception $e) {
            echo "  ✗ Error sending email for {$permit['ref']}: " . $e->getMessage() . "\n";
            $totalErrors++;
        }
    }
}

// Summary
echo "\n";
echo "Summary:\n";
echo "  Total emails sent: {$totalSent}\n";
echo "  Errors: {$totalErrors}\n";
echo "[" . date('Y-m-d H:i:s') . "] Email Notifications Script Complete.\n";

exit($totalErrors > 0 ? 1 : 0);
