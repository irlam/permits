# Push Notifications Setup Guide

This guide will help you set up and test push notifications in the Permits System.

## Overview

The Permits System includes a complete push notification system that can send real-time alerts to users' browsers and devices. This is useful for:
- Permit approval/rejection notifications
- Permit expiring soon alerts
- Status change notifications
- Custom system alerts

## Prerequisites

- Modern browser with Push API support (Chrome, Firefox, Edge, Safari 16+)
- HTTPS connection (required for service workers and push notifications)
- PHP 8.0+ with the `minishlink/web-push` library installed

## Step 1: Generate VAPID Keys

VAPID (Voluntary Application Server Identification) keys are required for web push notifications.

```bash
php generate_vapid.php
```

This will output:
```
╔════════════════════════════════════════════════════════════════════╗
║            VAPID Key Generator for Web Push Notifications          ║
╚════════════════════════════════════════════════════════════════════╝

✓ VAPID keys generated successfully!

Copy these values to your .env file:
─────────────────────────────────────────────────────────────────────

VAPID_PUBLIC_KEY="BDVsQdQidBa3sVZPwUdDWZlNm8kopTMl3thClnoZ0weD..."
VAPID_PRIVATE_KEY="MlCecixb8fyQYgFm1Zpd7eq9-ISNlgo_3_DHHrKVugQ"

─────────────────────────────────────────────────────────────────────
```

⚠️ **IMPORTANT**: Generate these keys **ONCE** and store them securely. If you lose them, all existing subscriptions will become invalid.

## Step 2: Configure Your Environment

Open your `.env` file and add the generated VAPID keys:

```env
# =========================
# Web Push (VAPID)
# =========================
VAPID_PUBLIC_KEY="your_generated_public_key_here"
VAPID_PRIVATE_KEY="your_generated_private_key_here"
VAPID_SUBJECT="mailto:your-contact-email@example.com"
```

**Configuration Notes:**
- `VAPID_PUBLIC_KEY`: The public key (can be shared with clients)
- `VAPID_PRIVATE_KEY`: The private key (keep SECRET, never commit to version control)
- `VAPID_SUBJECT`: Your contact email or website URL (e.g., `mailto:admin@example.com` or `https://example.com`)

## Step 3: Subscribe to Push Notifications

### Method 1: Automatic Subscription (Recommended)

Users will be automatically prompted for notification permission when they visit your application. The subscription happens automatically after permission is granted.

### Method 2: Manual Subscription

Users can manually subscribe using the browser console:

1. Open your application in a browser
2. Open the browser developer console (F12)
3. Run the following command:
   ```javascript
   window.subscribeToPush()
   ```
4. Grant permission when prompted

### Method 3: Programmatic Subscription

You can add a "Enable Notifications" button to your UI:

```html
<button onclick="window.subscribeToPush()">
  🔔 Enable Notifications
</button>
```

## Step 4: Test Push Notifications

Send a test notification to all subscribed users:

```bash
php bin/test-push-notification.php
```

Or send a custom test message:

```bash
php bin/test-push-notification.php "Hello from the Permits System!"
```

The script will:
- ✓ Verify VAPID configuration
- ✓ Load all subscriptions from the database
- ✓ Send test notification to each subscriber
- ✓ Report success/failure for each subscription
- ✓ Clean up invalid/expired subscriptions automatically

Example output:
```
╔════════════════════════════════════════════════════════════════════╗
║               Test Push Notification Sender                        ║
╚════════════════════════════════════════════════════════════════════╝

✓ VAPID configuration found
  Public Key: BDVsQdQidBa3sVZPwUdD...
  Subject: mailto:admin@example.com

✓ Found 3 subscription(s)

Sending notifications...
─────────────────────────────────────────────────────────────────────
  ✓ Sent to user: john.doe@example.com
    Endpoint: https://fcm.googleapis.com/fcm/send/...
  ✓ Sent to user: jane.smith@example.com
    Endpoint: https://fcm.googleapis.com/fcm/send/...
  ✓ Sent to user: anonymous
    Endpoint: https://fcm.googleapis.com/fcm/send/...
─────────────────────────────────────────────────────────────────────

Summary:
  Total subscriptions: 3
  Successfully sent: 3
  Errors: 0
  Cleaned up: 0

✓ Test notification(s) sent successfully!
  Check your browser/device for the notification.
```

## How It Works

### Client-Side Flow

1. **Service Worker Registration**: The service worker (`sw.js`) is registered when the page loads
2. **Permission Request**: User grants notification permission
3. **Subscription**: Browser subscribes to push notifications using VAPID public key
4. **Storage**: Subscription details are sent to `/api/push/subscribe.php` and stored in the database

### Server-Side Flow

1. **Trigger Event**: An event occurs (e.g., permit expiring soon)
2. **Load Subscriptions**: Server loads relevant subscriptions from the database
3. **Send Notification**: Server sends push notification via Web Push API
4. **Delivery**: Browser receives notification and displays it to the user
5. **Cleanup**: Invalid subscriptions (404/410 errors) are automatically removed

### Architecture

```
┌─────────────────┐
│  User Browser   │
│   (Client)      │
└────────┬────────┘
         │ 1. Subscribe
         ▼
┌─────────────────────────┐
│ /api/push/subscribe.php │
│  Stores subscription    │
└────────┬────────────────┘
         │ 2. Save to DB
         ▼
┌─────────────────────┐
│  push_subscriptions │
│      (Database)     │
└────────┬────────────┘
         │ 3. Load subscriptions
         ▼
┌──────────────────────┐
│  bin/reminders.php   │
│ Sends notifications  │
└────────┬─────────────┘
         │ 4. Push via Web Push API
         ▼
┌─────────────────┐
│  User Browser   │
│  Shows alert    │
└─────────────────┘
```

## Automated Notifications

### Permit Expiry Reminders

The system can automatically send push notifications for expiring permits via a cron job:

```bash
# Run every 15 minutes
*/15 * * * * php /path/to/permits/bin/reminders.php 60
```

The `60` parameter is the lookahead window in minutes. Adjust as needed:
- `15` = Notify for permits expiring in next 15 minutes
- `60` = Notify for permits expiring in next hour
- `1440` = Notify for permits expiring in next 24 hours

### Custom Notifications

You can send custom push notifications from your PHP code:

```php
<?php
require __DIR__ . '/vendor/autoload.php';
[$app, $db] = require __DIR__ . '/src/bootstrap.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// Initialize WebPush
$webPush = new WebPush([
    'VAPID' => [
        'subject'    => $_ENV['VAPID_SUBJECT'],
        'publicKey'  => $_ENV['VAPID_PUBLIC_KEY'],
        'privateKey' => $_ENV['VAPID_PRIVATE_KEY'],
    ],
]);

// Load subscriptions
$subs = $db->pdo->query("SELECT endpoint, p256dh, auth FROM push_subscriptions")->fetchAll();

// Prepare notification
$payload = json_encode([
    'title' => 'Important Alert',
    'body'  => 'Your permit has been approved!',
    'url'   => '/view-permit?id=123',
    'icon'  => '/assets/pwa/icon-192.png',
]);

// Queue and send
foreach ($subs as $s) {
    $subscription = new Subscription($s['endpoint'], $s['p256dh'], $s['auth']);
    $webPush->queueNotification($subscription, $payload);
}

// Send all queued notifications
foreach ($webPush->flush() as $report) {
    if ($report->isSuccess()) {
        echo "Notification sent successfully\n";
    } else {
        echo "Failed: " . $report->getReason() . "\n";
    }
}
```

## API Endpoints

### Subscribe to Push Notifications
```http
POST /api/push/subscribe.php
Content-Type: application/json

{
  "endpoint": "https://fcm.googleapis.com/fcm/send/...",
  "keys": {
    "p256dh": "BKxJ...",
    "auth": "aW3..."
  }
}
```

**Response:**
```json
{
  "ok": true,
  "id": "uuid-here",
  "action": "created"
}
```

### Unsubscribe from Push Notifications
```http
POST /api/push/unsubscribe.php
Content-Type: application/json

{
  "endpoint": "https://fcm.googleapis.com/fcm/send/..."
}
```

**Response:**
```json
{
  "ok": true,
  "deleted": 1
}
```

## Troubleshooting

### No subscriptions found
- Ensure the user has granted notification permission
- Check browser console for errors
- Verify VAPID public key is correctly exposed via `window.VAPID_PUBLIC_KEY`
- Test manually: `window.subscribeToPush()`

### Notifications not received
- Verify VAPID keys are correct in `.env`
- Check that VAPID_SUBJECT is a valid mailto: or https: URL
- Ensure HTTPS is enabled (required for service workers)
- Test with: `php bin/test-push-notification.php`
- Check browser notification settings (not blocked)

### Subscriptions failing with 401/403
- VAPID keys are incorrect or not matching
- VAPID_SUBJECT is invalid
- Regenerate VAPID keys if needed (will invalidate existing subscriptions)

### Subscriptions failing with 404/410
- The push subscription has expired or been revoked
- These are automatically cleaned up by the test script and reminders script
- User needs to re-subscribe

### Service Worker not registering
- Ensure you're accessing the site via HTTPS (or localhost)
- Check browser console for errors
- Verify `sw.js` is accessible at `/sw.js`
- Clear browser cache and try again

## Browser Support

| Browser | Support | Notes |
|---------|---------|-------|
| Chrome 50+ | ✅ Full | Best support |
| Firefox 44+ | ✅ Full | Full support |
| Edge 17+ | ✅ Full | Full support |
| Safari 16+ | ✅ Full | macOS 13+ and iOS 16+ |
| Opera 39+ | ✅ Full | Full support |

## Security Considerations

1. **HTTPS Required**: Push notifications only work over HTTPS (or localhost for development)
2. **Keep Private Key Secret**: Never expose `VAPID_PRIVATE_KEY` in client-side code
3. **User Permission**: Always respect user's notification preferences
4. **Rate Limiting**: Consider implementing rate limits to prevent notification spam
5. **Subscription Validation**: The system automatically validates and cleans up invalid subscriptions

## Best Practices

1. **Request Permission Thoughtfully**: Don't request permission immediately on page load. Wait for user interaction.
2. **Provide Value**: Only send notifications that provide real value to the user
3. **Allow Opt-Out**: Make it easy for users to unsubscribe
4. **Clean Up**: Regularly clean up expired/invalid subscriptions
5. **Test Thoroughly**: Use the test script before deploying new notification features
6. **Monitor**: Log notification failures and success rates

## Additional Resources

- [Web Push API Documentation](https://developer.mozilla.org/en-US/docs/Web/API/Push_API)
- [Service Worker Guide](https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API)
- [VAPID Specification](https://tools.ietf.org/html/rfc8292)
- [minishlink/web-push Library](https://github.com/web-push-libs/web-push-php)
