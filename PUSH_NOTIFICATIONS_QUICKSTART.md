# Push Notifications - Quick Start Guide

Get push notifications working in 3 simple steps!

## Prerequisites
- Permits system installed and running
- HTTPS enabled (or localhost for testing)
- Modern browser (Chrome, Firefox, Edge, or Safari 16+)

## Quick Setup (3 Steps)

### Step 1: Generate VAPID Keys
```bash
php generate_vapid.php
```

Copy the output keys to your `.env` file:
```env
VAPID_PUBLIC_KEY="BDVsQdQidBa3sVZPwUdD..."
VAPID_PRIVATE_KEY="MlCecixb8fyQYgFm1Zpd..."
VAPID_SUBJECT="mailto:admin@example.com"
```

### Step 2: Subscribe to Notifications

Open your application in a browser, then open the browser console (F12) and run:
```javascript
window.subscribeToPush()
```

Grant permission when prompted. Done! You're subscribed.

### Step 3: Test It

Send a test notification:
```bash
php bin/test-push-notification.php
```

You should see a notification pop up in your browser! 🎉

## Alternative: Interactive Setup

For a guided setup experience:
```bash
bash bin/setup-push-notifications.sh
```

This script will:
- Generate VAPID keys
- Update your .env file
- Guide you through configuration
- Optionally test notifications

## Testing Commands

```bash
# Send test notification to all subscribers
php bin/test-push-notification.php

# Send custom test message
php bin/test-push-notification.php "Your custom message"

# Subscribe in browser console
window.subscribeToPush()

# Unsubscribe in browser console
window.unsubscribeFromPush()
```

## Automated Notifications

Set up a cron job to send permit expiry reminders:

```bash
# Every 15 minutes, check for permits expiring in next 60 minutes
*/15 * * * * php /path/to/permits/bin/reminders.php 60
```

## Troubleshooting

### "No subscriptions found"
1. Make sure you ran `window.subscribeToPush()` in the browser
2. Check that you granted notification permission
3. Verify the browser supports push notifications

### "VAPID keys not configured"
1. Run `php generate_vapid.php`
2. Copy the keys to your `.env` file
3. Make sure there are no quotes issues in .env

### "Notifications not received"
1. Check VAPID keys are correct in `.env`
2. Ensure HTTPS is enabled (or using localhost)
3. Verify browser notification settings (not blocked)
4. Check browser console for errors

## Next Steps

- Read the full documentation: [PUSH_NOTIFICATIONS.md](PUSH_NOTIFICATIONS.md)
- Set up automated reminders (see cron job above)
- Customize notification content in `bin/reminders.php`
- Add "Enable Notifications" button to your UI (optional)

## Support

For detailed documentation, architecture, API reference, and advanced configuration, see:
- [PUSH_NOTIFICATIONS.md](PUSH_NOTIFICATIONS.md) - Complete documentation
- [README.md](README.md) - Project overview with push notification features

---

**Need help?** Check the troubleshooting section in PUSH_NOTIFICATIONS.md or the browser console for error messages.
