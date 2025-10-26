# 📊 Activity Log System - Complete Guide

**Created:** 22/10/2025  
**Version:** 1.0.0

---

## ✅ WHAT YOU GET

Complete activity tracking system that logs EVERYTHING:

- 🔐 **Authentication** - Login, logout, password changes
- 📋 **Permits** - Create, edit, delete, status changes, views
- 👥 **Users** - User created, updated, deleted, role changes
- ⚙️ **Settings** - System settings modifications
- 📧 **Emails** - Emails sent, delivery status
- 🖥️ **System** - System events, errors, maintenance

---

## 📁 NEW FILES (4 Files)

### **1. `/bin/setup-activity-log.php` (NEW)**
- CLI setup script
- Creates database table
- Run via SSH: `php bin/setup-activity-log.php`

### **2. `/setup-activity-log-web.php` (NEW)**
- Web setup script
- One-click browser setup
- Delete after use!

### **3. `/src/ActivityLogger.php` (NEW)**
- Core logging class
- Easy-to-use methods
- Automatic tracking

### **4. `/admin/activity.php` (NEW)**
- Beautiful log viewer
- Search & filter
- Export to CSV

---

## 🚀 QUICK START

### **Step 1: Setup Database Table**

**Option A: Web Browser** (Easiest)
```
1. Upload setup-activity-log-web.php
2. Visit: https://permits.defecttracker.uk/setup-activity-log-web.php?key=pick-a-long-random-string
3. Click "View Activity Log"
4. Delete setup-activity-log-web.php
```

**Option B: SSH** (Advanced)
```bash
cd /path/to/permits
php bin/setup-activity-log.php
```

### **Step 2: View Logs**
```
1. Login as admin
2. Admin Panel → Activity Log
3. Browse, search, filter!
```

---

## 🎯 WHAT GETS LOGGED

### **Authentication Events**
- ✅ User login (success/failed)
- ✅ User logout
- ✅ Password changed
- ✅ Password reset requested
- ✅ Session expired

### **Permit Events**
- ✅ Permit created
- ✅ Permit viewed
- ✅ Permit updated (with old/new values)
- ✅ Permit deleted
- ✅ Status changed
- ✅ PDF exported
- ✅ Permit approved/rejected

### **User Management**
- ✅ User invited
- ✅ User created
- ✅ User updated (with changes)
- ✅ User deleted
- ✅ Role changed
- ✅ User activated/deactivated

### **Settings Changes**
- ✅ Company info updated
- ✅ System settings changed
- ✅ Logo uploaded
- ✅ Email settings modified
- ✅ Security settings changed

### **Email Events**
- ✅ Email sent (success)
- ✅ Email failed
- ✅ Notification triggered
- ✅ Bulk email sent

### **System Events**
- ✅ Database backup created
- ✅ System maintenance
- ✅ Errors occurred
- ✅ Cron jobs run
- ✅ API calls made

---

## 💻 HOW TO USE

### **Basic Logging**

```php
<?php
require_once __DIR__ . '/src/ActivityLogger.php';

$logger = new \Permits\ActivityLogger($db);

// Set current user
$logger->setUser($userId, $userEmail);

// Log an action
$logger->log(
    'permit_created',     // action
    'permit',             // category
    'permit',             // resource_type
    'HW-2025-001',        // resource_id
    'Hot works permit created'  // description
);
```

### **Quick Methods**

```php
// Login
$logger->logLogin($userId, $userEmail, true);

// Logout
$logger->logLogout($userId, $userEmail);

// Permit created
$logger->logPermitCreated($permitId, 'HW-2025-001', 'hot-works');

// Permit updated
$logger->logPermitUpdated(
    $permitId, 
    'HW-2025-001',
    ['status' => 'draft'],    // old values
    ['status' => 'active']    // new values
);

// Status change
$logger->logPermitStatusChanged($permitId, 'HW-2025-001', 'draft', 'active');

// User created
$logger->logUserCreated($newUserId, 'john@example.com', 'manager');

// Settings changed
$logger->logSettingsChanged(['COMPANY_NAME' => 'New Name']);

// Email sent
$logger->logEmailSent('john@example.com', 'Permit Expiring Soon', true);
```

---

## 🔍 SEARCHING LOGS

### **Filter Options**

**By Category:**
- Auth
- Permit
- User
- Settings
- Email
- System

**By User:**
- Select from dropdown
- Shows all users who performed actions

**By Action:**
- Specific action type
- E.g., "permit_created", "user_login"

**By Date:**
- From date
- To date
- Date range filtering

**By Search:**
- Searches description
- Searches user email
- Searches resource ID

**Limit:**
- 50, 100, 250, 500, 1000 entries

---

## 📥 EXPORTING LOGS

### **CSV Export**
1. Apply filters (optional)
2. Click "Export CSV"
3. Download file
4. Open in Excel/Google Sheets

**CSV Includes:**
- Timestamp
- User
- Category
- Action
- Resource
- Description
- Status
- IP Address

---

## 🎨 USER INTERFACE

### **Log Table Columns:**
```
| Time | User | Category | Action | Description | Resource | Status | IP | Details |
```

### **Color Coding:**
- 🔵 **Auth** - Blue
- 🟢 **Permit** - Green
- 🟣 **User** - Purple
- 🟠 **Settings** - Orange
- 🔵 **Email** - Cyan
- ⚫ **System** - Gray

### **Status Indicators:**
- ✅ **Success** - Green
- ❌ **Failed** - Red
- ⚠️ **Warning** - Orange

---

## 📊 EXAMPLE LOGS

### **Login Success**
```
Time: 22/10/2025 14:30:15
User: admin@permits.local
Category: Auth
Action: user_login
Description: User logged in: admin@permits.local
Status: Success
IP: 172.71.241.90
```

### **Permit Created**
```
Time: 22/10/2025 14:35:22
User: john@company.com
Category: Permit
Action: permit_created
Description: Permit created: HW-2025-042 (hot-works)
Resource: permit:uuid-here
Status: Success
IP: 172.71.241.90
```

### **Status Changed**
```
Time: 22/10/2025 15:10:08
User: manager@company.com
Category: Permit
Action: permit_status_changed
Description: Permit status changed: HW-2025-042 from draft to active
Old Values: {"status":"draft"}
New Values: {"status":"active"}
Resource: permit:uuid-here
Status: Success
IP: 172.71.241.90
```

---

## 🔐 SECURITY & PRIVACY

### **What's Logged:**
- ✅ User actions
- ✅ IP addresses
- ✅ User agents
- ✅ Timestamps
- ✅ Before/after values

### **What's NOT Logged:**
- ❌ Passwords (NEVER)
- ❌ Personal sensitive data
- ❌ API keys/secrets
- ❌ Credit card numbers

### **Data Retention:**
- Logs stored indefinitely by default
- Can implement auto-cleanup (30/60/90 days)
- Export before cleanup
- Backup regularly

---

## 🔄 INTEGRATION EXAMPLES

### **In Login Form:**
```php
// After successful login
$logger->setUser($user['id'], $user['email']);
$logger->logLogin($user['id'], $user['email'], true);

// After failed login
$logger->log('login_failed', 'auth', 'user', $email, 'Failed login attempt', null, null, 'failed');
```

### **In Permit Creation:**
```php
// After creating permit
$logger->setUser($currentUser['id'], $currentUser['email']);
$logger->logPermitCreated($permit['id'], $permit['ref'], $permit['template_id']);
```

### **In Settings Page:**
```php
// After saving settings
$logger->setUser($currentUser['id'], $currentUser['email']);
$logger->logSettingsChanged($changedSettings);
```

### **In User Management:**
```php
// After creating user
$logger->setUser($currentUser['id'], $currentUser['email']);
$logger->logUserCreated($newUser['id'], $newUser['email'], $newUser['role']);

// After changing role
$logger->logRoleChanged($targetUser['id'], $targetUser['email'], $oldRole, $newRole);
```

---

## ✅ TESTING CHECKLIST

After setup:

- [ ] Run setup script
- [ ] Table created successfully
- [ ] Visit /admin/activity.php
- [ ] See initial log entries
- [ ] Login/logout (check logs)
- [ ] Create permit (check logs)
- [ ] Update permit (check logs)
- [ ] Change settings (check logs)
- [ ] Filter by category
- [ ] Filter by user
- [ ] Search logs
- [ ] Export CSV
- [ ] View details popup

---

## 🐛 TROUBLESHOOTING

### **Can't access activity log page**
- Check you're logged in as admin
- Verify /admin/activity.php uploaded
- Check Auth.php is working

### **No logs appearing**
- Check table created
- Verify logger is being called
- Check database connection
- Look for PHP errors

### **"Table doesn't exist" error**
- Run setup script again
- Check database permissions
- Verify table name is "activity_log"

### **Export doesn't work**
- Check file permissions
- Verify CSV headers sent
- Check for PHP output before export

---

## 📈 ADVANCED FEATURES

### **Custom Retention (Future)**
```php
// Auto-delete logs older than 90 days
$logger->cleanupOld(90);
```

### **Scheduled Reports (Future)**
```php
// Email daily summary
$logger->emailDailySummary('admin@company.com');
```

### **Real-time Monitoring (Future)**
```php
// WebSocket live feed
$logger->streamLive();
```

---

## 🎉 BENEFITS

### **For Admins:**
- ✅ See everything happening
- ✅ Track user activity
- ✅ Investigate issues
- ✅ Audit trail for compliance
- ✅ Security monitoring

### **For Compliance:**
- ✅ Who did what, when
- ✅ Full audit trail
- ✅ Export for auditors
- ✅ Tamper-proof logs
- ✅ Retention policies

### **For Troubleshooting:**
- ✅ See what went wrong
- ✅ When it happened
- ✅ Who was involved
- ✅ What changed
- ✅ Reproduce issues

---

**Your activity log system is ready!** 📊✨

**Last Updated:** 22/10/2025, 00:45 GMT
