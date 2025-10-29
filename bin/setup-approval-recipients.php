#!/usr/bin/env php
<?php
/**
 * Setup Approval Notification Recipients
 * 
 * This script helps administrators quickly set up email recipients who will
 * be notified when permits are submitted for approval.
 * 
 * Usage:
 *   php bin/setup-approval-recipients.php add "John Doe" john@example.com
 *   php bin/setup-approval-recipients.php list
 *   php bin/setup-approval-recipients.php delete john@example.com
 */

// Load bootstrap
[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/approval-notifications.php';

$action = $argv[1] ?? 'help';

switch ($action) {
    case 'add':
        $name = $argv[2] ?? '';
        $email = $argv[3] ?? '';
        
        if (empty($name) || empty($email)) {
            echo "Error: Name and email are required.\n";
            echo "Usage: php bin/setup-approval-recipients.php add \"Name\" email@example.com\n";
            exit(1);
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo "Error: Invalid email address: $email\n";
            exit(1);
        }
        
        try {
            addApprovalNotificationRecipient($db, $name, $email);
            echo "✓ Added approval recipient: $name <$email>\n";
            echo "\nCurrent recipients:\n";
            listRecipients($db);
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
        break;
        
    case 'list':
        listRecipients($db);
        break;
        
    case 'delete':
        $email = $argv[2] ?? '';
        
        if (empty($email)) {
            echo "Error: Email is required.\n";
            echo "Usage: php bin/setup-approval-recipients.php delete email@example.com\n";
            exit(1);
        }
        
        try {
            $recipients = getApprovalNotificationRecipients($db);
            $found = false;
            
            foreach ($recipients as $recipient) {
                if (strtolower($recipient['email']) === strtolower($email)) {
                    deleteApprovalNotificationRecipient($db, $recipient['id']);
                    echo "✓ Removed approval recipient: {$recipient['name']} <{$recipient['email']}>\n";
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                echo "Error: Recipient not found: $email\n";
                exit(1);
            }
            
            echo "\nCurrent recipients:\n";
            listRecipients($db);
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
        break;
        
    case 'help':
    default:
        echo "Approval Notification Recipients Setup\n";
        echo "======================================\n\n";
        echo "This script manages who receives email notifications when permits are submitted for approval.\n\n";
        echo "Commands:\n";
        echo "  add \"Name\" email@example.com  - Add a new recipient\n";
        echo "  list                         - Show all current recipients\n";
        echo "  delete email@example.com     - Remove a recipient\n";
        echo "  help                         - Show this help message\n\n";
        echo "Examples:\n";
        echo "  php bin/setup-approval-recipients.php add \"Manager Name\" manager@company.com\n";
        echo "  php bin/setup-approval-recipients.php list\n";
        echo "  php bin/setup-approval-recipients.php delete manager@company.com\n\n";
        echo "Note: You can also manage recipients via the web interface at:\n";
        echo "  https://your-domain.com/admin-approval-notifications.php\n";
        break;
}

function listRecipients($db) {
    try {
        $recipients = getApprovalNotificationRecipients($db);
        
        if (empty($recipients)) {
            echo "No approval recipients configured.\n";
            echo "\nTo receive email notifications when permits are submitted for approval,\n";
            echo "add at least one recipient using:\n";
            echo "  php bin/setup-approval-recipients.php add \"Name\" email@example.com\n";
            return;
        }
        
        echo "Current Approval Recipients:\n";
        echo "============================\n";
        foreach ($recipients as $recipient) {
            echo "  • {$recipient['name']} <{$recipient['email']}>\n";
        }
        echo "\nTotal: " . count($recipients) . " recipient(s)\n";
    } catch (Exception $e) {
        echo "Error listing recipients: " . $e->getMessage() . "\n";
    }
}
