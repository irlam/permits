<?php
/**
 * VAPID Key Generator for Web Push Notifications
 * 
 * Description: Generates VAPID (Voluntary Application Server Identification) keys
 *              required for sending web push notifications.
 * 
 * Usage: php generate_vapid.php
 * 
 * What it does:
 * - Generates a new pair of VAPID public and private keys
 * - Keys are cryptographically secure and unique
 * - Use these keys to configure push notifications in your .env file
 * 
 * Security Notes:
 * - Keep the private key SECRET (never commit to version control)
 * - The public key can be shared with clients
 * - Generate keys ONCE and store them in your .env file
 * - If keys are lost, existing subscriptions will become invalid
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
use Minishlink\WebPush\VAPID;

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║            VAPID Key Generator for Web Push Notifications          ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

try {
    $keys = VAPID::createVapidKeys();
    
    echo "✓ VAPID keys generated successfully!\n\n";
    echo "Copy these values to your .env file:\n";
    echo "─────────────────────────────────────────────────────────────────────\n\n";
    
    echo "VAPID_PUBLIC_KEY=\"{$keys['publicKey']}\"\n";
    echo "VAPID_PRIVATE_KEY=\"{$keys['privateKey']}\"\n";
    
    echo "\n─────────────────────────────────────────────────────────────────────\n";
    echo "\nNext steps:\n";
    echo "  1. Copy the above keys to your .env file\n";
    echo "  2. Set VAPID_SUBJECT in .env (e.g., 'mailto:your@email.com')\n";
    echo "  3. Restart your application server\n";
    echo "  4. Test push notifications with: php bin/test-push-notification.php\n";
    echo "\n";
    echo "⚠️  IMPORTANT: Keep the private key SECRET!\n";
    echo "   - Do not commit it to version control\n";
    echo "   - Do not share it publicly\n";
    echo "   - Store it securely in your .env file\n";
    echo "\n";
    
} catch (Exception $e) {
    echo "✗ Error generating VAPID keys: {$e->getMessage()}\n";
    exit(1);
}
