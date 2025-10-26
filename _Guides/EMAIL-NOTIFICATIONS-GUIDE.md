# 📧 Email Notifications - Complete Guide

**Feature:** Automated Email Notifications  
**Version:** 4.1  
**Date:** 21st October 2025

---

## ✅ **WHAT'S NEW**

Automatic email notifications for:
- ⚠️ **Permits expiring within 24 hours** (urgent)
- 📅 **Permits expiring within 7 days** (warning)
- ✅ **New permits created** (optional)
- 🔄 **Status changes** (optional)
- Beautiful HTML email templates
- Multiple recipient support
- SMTP or PHP mail() support

---

## 🎯 **KEY FEATURES**

### **Automatic Notifications**
- Runs via cron job (hourly or twice daily)
- Checks database for expiring permits
- Sends professional HTML emails
- Logs all sent notifications
- Prevents duplicate emails

### **Email Types**
1. **24-Hour Expiry** - Red alert, urgent action needed
2. **7-Day Expiry** - Orange warning, plan ahead
3. **Permit Created** - Informational
4. **Status Changed** - Tracking updates

### **Smart Features**
- Won't send duplicate emails (tracks in database)
- Multiple recipients supported
- Customizable sender email/name
- Professional dark-themed templates
- Direct links to permits

---

## 🚀 **QUICK SETUP (5 Minutes)**

### **Step 1: Configure Email Settings**

Visit: `https://yourdomain.com/email-settings.php?key=YOUR_ADMIN_KEY`

**Enter:**
1. **Notification Emails:** Who receives alerts (comma-separated)
   ```
   manager@company.com, safety@company.com
   ```

2. **From Email:** What address emails come from
   ```
   noreply@yourdomain.com
   ```

3. **From Name:** Display name
   ```
   Permits System
   ```

4. **Save Settings**

### **Step 2: Set Up Cron Job**

**Via Plesk:**
1. Go to Scheduled Tasks
2. **Command:** 
   ```
   /usr/bin/php /path/to/permits/bin/send-notifications.php
   ```
3. **Schedule:** `0 * * * *` (every hour)
4. Or: `0 9,17 * * *` (9 AM and 5 PM)
5. Save

**Via cPanel:**
1. Cron Jobs
2. Add: `/usr/bin/php /home/username/public_html/bin/send-notifications.php`
3. Schedule: Hourly or twice daily
4. Save

### **Step 3: Test**

Run manually:
```bash
php bin/send-notifications.php
```

Check your email inbox!

---

## 📨 **EMAIL SETTINGS OPTIONS**

### **Basic Settings (Required)**

**NOTIFICATION_EMAILS**
- Who receives notifications
- Comma-separated list
- Example: `john@company.com, sarah@company.com`

**MAIL_FROM**
- Sender email address
- Example: `noreply@yourdomain.com`
- Must be valid email format

**MAIL_FROM_NAME**
- Display name in inbox
- Example: `Permits System` or `Safety Alerts`

### **SMTP Settings (Optional)**

**Why use SMTP?**
- More reliable delivery
- Better spam score
- Professional appearance
- Tracking capabilities

**MAIL_USE_SMTP**
- Set to `true` to enable SMTP
- Set to `false` to use PHP mail()

**SMTP_HOST**
- Your SMTP server
- Examples:
  - Gmail: `smtp.gmail.com`
  - Office 365: `smtp.office365.com`
  - SendGrid: `smtp.sendgrid.net`
  - Custom: `mail.yourdomain.com`

**SMTP_PORT**
- Usually `587` (TLS recommended)
- Or `465` (SSL)
- Or `25` (insecure, not recommended)

**SMTP_USER**
- SMTP authentication username
- Usually your email address
- Example: `notifications@yourdomain.com`

**SMTP_PASS**
- SMTP authentication password
- **For Gmail:** Use App Password (not regular password)
- **Security:** Stored in .env (never committed to git)

**SMTP_SECURE**
- `tls` (recommended, port 587)
- `ssl` (port 465)

---

## 🔐 **SMTP SETUP GUIDES**

### **Gmail (Recommended for Testing)**

1. **Enable 2-Step Verification:**
   - Go to Google Account settings
   - Security → 2-Step Verification → Turn On

2. **Create App Password:**
   - Security → App Passwords
   - Select app: Mail
   - Select device: Other (Permits System)
   - Copy the 16-character password

3. **Settings:**
   ```
   MAIL_USE_SMTP=true
   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=587
   SMTP_USER=your-email@gmail.com
   SMTP_PASS=your-16-char-app-password
   SMTP_SECURE=tls
   ```

### **Office 365**

```
MAIL_USE_SMTP=true
SMTP_HOST=smtp.office365.com
SMTP_PORT=587
SMTP_USER=your-email@company.com
SMTP_PASS=your-password
SMTP_SECURE=tls
```

### **cPanel (Most Web Hosts)**

```
MAIL_USE_SMTP=true
SMTP_HOST=mail.yourdomain.com
SMTP_PORT=587
SMTP_USER=notifications@yourdomain.com
SMTP_PASS=your-cpanel-email-password
SMTP_SECURE=tls
```

### **SendGrid (Professional)**

1. Sign up at sendgrid.com
2. Create API key
3. Settings:
```
MAIL_USE_SMTP=true
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USER=apikey
SMTP_PASS=your-sendgrid-api-key
SMTP_SECURE=tls
```

---

## 📧 **EMAIL TEMPLATES**

### **24-Hour Expiry (Urgent)**
```
Subject: ⚠️ Permit Expiring Soon: HW-2025-001

URGENT - This permit expires in 1 day(s)

Permit Reference: HW-2025-001
Type: hot-works-v1
Location: Block A
Expires: 22/10/2025 17:00
Status: ACTIVE

[View Permit Button]

Please review this permit and take appropriate action before it expires.
```

### **7-Day Expiry (Warning)**
```
Subject: ⚠️ Permit Expiring Soon: DIG-2025-005

WARNING - This permit expires in 5 day(s)

Permit Reference: DIG-2025-005
Type: permit-to-dig-v1
Location: Block B
Expires: 27/10/2025 12:00
Status: ISSUED

[View Permit Button]

Please review this permit and take appropriate action before it expires.
```

### **Permit Created**
```
Subject: ✅ New Permit Created: WAH-2025-010

New Permit Created

Permit Reference: WAH-2025-010
Type: work-at-height-v1
Location: Block C
Valid From: 21/10/2025 08:00
Valid To: 21/10/2025 18:00
Status: ACTIVE

[View Permit Button]
```

### **Status Changed**
```
Subject: 🔄 Permit Status Changed: HW-2025-001

Permit Status Changed

active → CLOSED

Permit Reference: HW-2025-001
Type: hot-works-v1
Location: Block A

[View Permit Button]
```

---

## ⚙️ **HOW IT WORKS**

### **Notification Script Flow**

1. **Script runs** (via cron)
2. **Checks database** for expiring permits
3. **Filters out** permits that already received emails
4. **For each expiring permit:**
   - Get permit details
   - For each recipient email:
     - Generate HTML email
     - Send email
     - Log event in database
5. **Reports summary**
6. **Exits**

### **Preventing Duplicates**

The system logs each sent email in the `form_events` table:
```json
{
  "type": "email_sent",
  "by_user": "system",
  "payload": {
    "type": "24h_expiry",
    "recipient": "manager@company.com",
    "permit_ref": "HW-2025-001"
  }
}
```

Won't send again within:
- **24h expiry:** 24 hours
- **7d expiry:** 7 days

### **Email Delivery**

**PHP mail():**
- Uses server's built-in mail function
- Simple setup
- May have deliverability issues
- Good for testing

**SMTP:**
- Uses external mail server
- Better delivery rates
- More reliable
- Professional
- Recommended for production

---

## 📊 **CRON JOB SCHEDULES**

### **Every Hour** (Recommended)
```bash
0 * * * * /usr/bin/php /path/to/permits/bin/send-notifications.php
```
- Runs: Top of every hour
- Best for: High-activity sites
- Response time: ~1 hour

### **Twice Daily** (Morning & Evening)
```bash
0 9,17 * * * /usr/bin/php /path/to/permits/bin/send-notifications.php
```
- Runs: 9 AM and 5 PM
- Best for: Standard offices
- Response time: Up to 12 hours

### **Once Daily** (Morning)
```bash
0 8 * * * /usr/bin/php /path/to/permits/bin/send-notifications.php
```
- Runs: 8 AM daily
- Best for: Low-activity sites
- Response time: Up to 24 hours

### **Every 3 Hours**
```bash
0 */3 * * * /usr/bin/php /path/to/permits/bin/send-notifications.php
```
- Runs: 00:00, 03:00, 06:00, etc.
- Best for: Medium activity
- Response time: ~3 hours

---

## 🧪 **TESTING**

### **Test 1: Manual Run**
```bash
cd /path/to/permits
php bin/send-notifications.php
```

**Expected output:**
```
[2025-10-21 20:00:00] Email Notifications Script Starting...
Recipients: manager@company.com

Checking permits expiring within 24 hours...
Found 2 permit(s) expiring within 24 hours
  ✓ Sent 24h expiry email for HW-2025-001 to manager@company.com
  ✓ Sent 24h expiry email for DIG-2025-005 to manager@company.com

Checking permits expiring within 7 days...
Found 1 permit(s) expiring within 7 days
  ✓ Sent 7d expiry email for WAH-2025-010 to manager@company.com

Summary:
  Total emails sent: 3
  Errors: 0
[2025-10-21 20:00:01] Email Notifications Script Complete.
```

### **Test 2: Create Expiring Permit**
1. Create new permit
2. Set valid_to = tomorrow
3. Set status = "active"
4. Run script
5. Check email inbox

### **Test 3: Check Event Log**
1. View permit in system
2. Check "History" section
3. Should see "email_sent" events

---

## 🐛 **TROUBLESHOOTING**

### **No Emails Sent**

**Check 1: NOTIFICATION_EMAILS set?**
```bash
grep NOTIFICATION_EMAILS .env
```
Should show your email addresses.

**Check 2: Are there expiring permits?**
Run query:
```sql
SELECT * FROM forms 
WHERE status IN ('issued','active') 
AND valid_to < DATE_ADD(NOW(), INTERVAL 7 DAY);
```

**Check 3: Already sent recently?**
```sql
SELECT * FROM form_events 
WHERE type='email_sent' 
ORDER BY at DESC 
LIMIT 10;
```

### **Emails Going to Spam**

**Solutions:**
1. Use SMTP instead of PHP mail()
2. Set up SPF record for your domain
3. Set up DKIM signing
4. Use reputable SMTP provider
5. Check sender email matches domain

### **SMTP Authentication Failed**

**Gmail:**
- Enable 2-Step Verification
- Use App Password (not regular password)
- Allow less secure apps (if needed)

**Office 365:**
- Enable SMTP AUTH in admin
- Use correct username/password
- Check port (587 for TLS)

**cPanel:**
- Email account must exist
- Use correct password
- Port usually 587 or 465

### **Script Not Running**

**Check cron is set up:**
```bash
crontab -l
```
Should show your cron job.

**Check permissions:**
```bash
chmod +x bin/send-notifications.php
```

**Check PHP path:**
```bash
which php
```
Use this path in cron.

---

## 📈 **MONITORING**

### **Via Event History**
- View any permit
- Check "History" section
- Look for "email_sent" events
- Shows when emails were sent

### **Via Cron Logs**
**Plesk:**
- Scheduled Tasks → View log

**cPanel:**
- Check email for cron output

**SSH:**
```bash
tail -f /var/log/cron
```

### **Via Script Output**
Add to cron for logging:
```bash
0 * * * * /usr/bin/php /path/to/bin/send-notifications.php >> /path/to/email-notifications.log 2>&1
```

Then check:
```bash
tail -f /path/to/email-notifications.log
```

---

## 🎯 **BEST PRACTICES**

### **Email Recipients**
✅ Add multiple recipients for redundancy  
✅ Include safety manager  
✅ Include site supervisor  
✅ Include operations manager  
❌ Don't add too many (creates noise)

### **Cron Schedule**
✅ Hourly for high-activity sites  
✅ Twice daily for standard offices  
✅ Morning only for low-activity  
❌ Don't run more than hourly (unnecessary load)

### **SMTP vs PHP mail()**
✅ Use SMTP for production  
✅ Use reputable SMTP provider  
✅ Gmail works well for small sites  
❌ Don't use PHP mail() for production

### **Testing**
✅ Test before going live  
✅ Check spam folder  
✅ Verify links work  
✅ Test with real expiring permits

---

## 🔮 **FUTURE ENHANCEMENTS**

Possible additions:
- **Daily digest email** - Summary of all expiring permits
- **Customizable thresholds** - Set your own expiry warnings (3 days, 14 days)
- **Email on approval needed** - Notify when permits need approval
- **Email on form submission** - Notify when new permits created
- **Per-template recipients** - Different emails for different permit types
- **SMS notifications** - Text message alerts
- **Slack integration** - Post to Slack channels

---

## ✅ **DEPLOYMENT CHECKLIST**

After adding email notifications:

- [ ] Uploaded new files (Mailer.php, send-notifications.php, email-settings.php)
- [ ] Configured email settings via settings page
- [ ] Added NOTIFICATION_EMAILS to .env
- [ ] Set up cron job (hourly or twice daily)
- [ ] Ran test manually: `php bin/send-notifications.php`
- [ ] Checked email inbox for test
- [ ] Verified links in emails work
- [ ] Checked event history shows "email_sent"
- [ ] Configured SMTP (if using)
- [ ] Tested with real expiring permit

---

## 📞 **SUPPORT**

**Common Issues:**
- Emails not sending? Check NOTIFICATION_EMAILS in .env
- Going to spam? Set up SMTP
- Cron not running? Check cron setup and PHP path
- Duplicates? System prevents this automatically

---

**Email Notifications Complete!** 📧

You now have automatic email alerts keeping everyone informed about expiring permits!

Last Updated: 21st October 2025, 21:00 GMT
