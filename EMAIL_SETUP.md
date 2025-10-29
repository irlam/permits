# Email Notification Setup Guide

## Overview

The Permits System sends email notifications when permits are submitted for approval. To enable this feature, you must configure approval notification recipients.

## Quick Setup

### Step 1: Configure SMTP Settings

Ensure your `.env` file has the correct email settings:

```env
MAIL_DRIVER="smtp"
MAIL_HOST="smtp.example.com"
MAIL_PORT="465"
MAIL_ENCRYPTION="ssl"
MAIL_USERNAME="permits@example.com"
MAIL_PASSWORD="your-password"
MAIL_FROM_ADDRESS="permits@example.com"
MAIL_FROM_NAME="Permits System"
```

### Step 2: Add Approval Recipients

You MUST add at least one approval recipient to receive email notifications. Choose one of these methods:

#### Method 1: Command Line (Recommended for Initial Setup)

```bash
# Add a recipient
php bin/setup-approval-recipients.php add "Manager Name" manager@company.com

# List all recipients
php bin/setup-approval-recipients.php list

# Remove a recipient
php bin/setup-approval-recipients.php delete manager@company.com
```

#### Method 2: Web Interface

1. Log in as an administrator
2. Go to `/admin-approval-notifications.php`
3. Add recipients using the form

### Step 3: Verify Setup

1. Create a test permit and submit it for approval (status: `pending_approval`)
2. Check that configured recipients receive an email notification
3. Review email queue: `SELECT * FROM email_queue ORDER BY created_at DESC LIMIT 5;`
4. Check logs for any errors

## How It Works

### Notification Flow

1. **Permit Submitted**: When a permit status is set to `pending_approval`
2. **Fetch Recipients**: System retrieves configured recipients from `settings` table
3. **Generate Links**: Creates unique approval links for each recipient
4. **Queue Email**: Email is queued in `email_queue` table
5. **Send Email**: Queue processor sends emails via SMTP

### Automatic Email Sending

Emails can be sent in two ways:

1. **Immediately** (when permit is submitted): The system attempts to send immediately
2. **Via Cron Job** (fallback): Run the queue processor every 2 minutes:
   ```bash
   */2 * * * * php /path/to/permits/bin/process-email-queue.php
   ```

## Troubleshooting

### No Emails Being Sent

**Check 1: Are recipients configured?**
```bash
php bin/setup-approval-recipients.php list
```

If no recipients are shown, add at least one:
```bash
php bin/setup-approval-recipients.php add "Admin" admin@example.com
```

**Check 2: Verify SMTP settings**
```bash
# Test database connection and settings
php bin/check_env_and_db.php
```

**Check 3: Review email queue**
```sql
SELECT * FROM email_queue WHERE status = 'pending' ORDER BY created_at DESC LIMIT 10;
```

**Check 4: Check for errors**
- Review PHP error logs
- Check application logs for email-related errors
- Test SMTP connection manually

### Email Queue Not Processing

If emails are queued but not sent:

1. **Manual processing**:
   ```bash
   php bin/process-email-queue.php
   ```

2. **Check cron job is running**:
   ```bash
   */2 * * * * php /path/to/permits/bin/process-email-queue.php
   ```

3. **Review SMTP configuration** in `.env` file

### Recipients Not Receiving Emails

1. **Check spam folder**: Approval emails may be filtered as spam
2. **Verify email addresses**: Ensure recipient emails are correct
3. **Check SMTP logs**: Review server logs for delivery failures
4. **Test with different recipient**: Try a different email address

## Email Templates

Email templates are located in `templates/emails/`:
- `permit-awaiting-approval.php` - Sent when permit needs approval
- `permit-approved.php` - Sent when permit is approved
- `permit-rejected.php` - Sent when permit is rejected
- `permit-expiring.php` - Sent for expiry reminders

## Advanced Configuration

### Multiple Recipients

Add multiple recipients to ensure redundancy:

```bash
php bin/setup-approval-recipients.php add "Manager 1" manager1@company.com
php bin/setup-approval-recipients.php add "Manager 2" manager2@company.com
php bin/setup-approval-recipients.php add "Supervisor" supervisor@company.com
```

### Email Queue Monitoring

Monitor the email queue table:

```sql
-- View pending emails
SELECT * FROM email_queue WHERE status = 'pending' ORDER BY created_at DESC;

-- View failed emails
SELECT * FROM email_queue WHERE status = 'failed' ORDER BY created_at DESC;

-- View sent emails (last 24 hours)
SELECT * FROM email_queue 
WHERE status = 'sent' 
  AND sent_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY sent_at DESC;
```

### Custom Email Driver

If you can't use SMTP, you can use alternative drivers:

- `mail` - PHP's built-in mail() function
- `log` - Log emails to file (testing only)

Set in `.env`:
```env
MAIL_DRIVER="mail"  # or "log"
```

## Support

For additional help:
1. Review the main README.md
2. Check `/admin-approval-notifications.php` for web-based management
3. Contact system administrator
