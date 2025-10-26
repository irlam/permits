# 💾 Backup & Restore System - Complete Guide

**Created:** 23/10/2025  
**Version:** 1.0.0  
**Status:** ✅ COMPLETE

---

## ✅ WHAT YOU GET

Complete database backup and restore system with:

- 💾 **One-click Backup** - Create instant database snapshots
- 📥 **Download Backups** - Save to your computer
- 📤 **Upload Backups** - Restore from external files
- 🔄 **Restore Database** - Roll back to any backup point
- 🗑️ **Delete Old Backups** - Manage disk space
- 📊 **Backup History** - View all backups with dates/sizes
- 🔐 **Activity Logging** - All backup actions tracked
- 🎨 **Drag & Drop** - Easy file uploads

---

## 📁 NEW FILE

### **`/admin/backup.php` (NEW - 600+ lines)**

**Location:** `/admin/backup.php`

**Features:**
- ✅ Database information display
- ✅ One-click backup creation
- ✅ Download backup files
- ✅ Upload external backups
- ✅ Restore from any backup
- ✅ Delete old backups
- ✅ Drag & drop file upload
- ✅ Full activity logging
- ✅ Backup validation
- ✅ File size display
- ✅ Warning confirmations
- ✅ Beautiful UI

---

## 🚀 QUICK START

### **Step 1: Upload File**
```
Upload: /admin/backup.php
```

### **Step 2: Create Backup Directory**
```
Create folder: /backups/
Permissions: 755
```

### **Step 3: Access**
```
Admin Panel → Backup & Restore
OR
https://permits.defecttracker.uk/admin/backup.php
```

### **Step 4: Create First Backup**
```
Click: "📦 Create Backup"
Wait: 2-5 seconds
Done: Backup created!
```

---

## 🎯 FEATURES

### **1. Database Information**
See your database details:
- Database name
- Host address
- Port number
- Total backups count
- Backup directory location

### **2. Quick Actions**
Fast access buttons:
- 📦 **Create Backup** - One-click backup
- 📤 **Upload Backup** - Upload from computer
- 📊 **View Logs** - See backup activity

### **3. Backup List**
View all backups with:
- Filename (with timestamp)
- File size (in KB)
- Creation date/time
- Action buttons (Download/Restore/Delete)

### **4. Upload Area**
Drag & drop or click to upload:
- Accepts .sql files only
- Max 100MB
- Visual feedback
- Instant upload

---

## 📦 CREATING BACKUPS

### **Method 1: One-Click Backup**

1. Go to Backup & Restore page
2. Click "📦 Create Backup"
3. Wait a few seconds
4. Backup appears in list!

**Filename format:**
```
backup_dbname_YYYY-MM-DD_HH-MM-SS.sql
```

**Example:**
```
backup_k87747_permits_2025-10-23_14-30-15.sql
```

### **What's Backed Up:**
- ✅ All database tables
- ✅ All table structures
- ✅ All data (permits, users, etc.)
- ✅ All relationships
- ✅ All indexes
- ✅ Everything in your database!

### **Activity Log Entry:**
```
Action: backup_created
Category: system
Description: Database backup created: backup_...sql (1,234.56 KB)
User: admin@permits.local
Status: success
```

---

## 📥 DOWNLOADING BACKUPS

### **To Download:**

1. Find backup in list
2. Click "📥 Download" button
3. File downloads to your computer
4. Store safely!

### **Activity Log Entry:**
```
Action: backup_downloaded
Category: system
Description: Backup downloaded: backup_...sql
User: admin@permits.local
Status: success
```

### **Best Practices:**
- ✅ Download backups weekly
- ✅ Store on external drive
- ✅ Keep 3-5 recent backups locally
- ✅ Use cloud storage for important backups
- ✅ Label backups clearly

---

## 📤 UPLOADING BACKUPS

### **Method 1: Drag & Drop**

1. Open Backup & Restore page
2. Drag .sql file from computer
3. Drop onto upload area
4. Wait for upload
5. Backup appears in list!

### **Method 2: Click to Upload**

1. Click upload area (or "📤 Upload Backup" button)
2. Select .sql file
3. Wait for upload
4. Backup appears in list!

### **Activity Log Entry:**
```
Action: backup_uploaded
Category: system
Description: Backup uploaded: backup_...sql (1,234.56 KB)
User: admin@permits.local
Status: success
```

### **Requirements:**
- File type: .sql only
- Max size: 100MB
- Valid SQL file

---

## 🔄 RESTORING BACKUPS

### **⚠️ CRITICAL WARNING**

**Restoring replaces ALL current data!**

### **Before Restoring:**
1. ✅ Create backup of current database first!
2. ✅ Make sure you have the right backup file
3. ✅ Confirm the backup date is correct
4. ✅ Understand this cannot be undone
5. ✅ Consider testing in development first

### **To Restore:**

1. Find backup in list
2. Click "🔄 Restore" button
3. Read warning message carefully
4. Confirm you want to proceed
5. Wait 5-10 seconds
6. Database restored!

### **Warning Message:**
```
⚠️ WARNING: This will replace your current database!

Are you sure you want to restore from:
backup_k87747_permits_2025-10-23_14-30-15.sql

This action cannot be undone!
```

### **Activity Log Entry:**
```
Action: backup_restored
Category: system
Description: Database restored from backup: backup_...sql
User: admin@permits.local
Status: warning (because it's a destructive action)
```

### **What Happens:**
- ❌ Current database is replaced
- ✅ Backup data is loaded
- ✅ All tables restored
- ✅ All data restored
- ❌ Changes after backup date are lost

---

## 🗑️ DELETING BACKUPS

### **To Delete:**

1. Find backup in list
2. Click "🗑️ Delete" button
3. Confirm deletion
4. Backup deleted!

### **Activity Log Entry:**
```
Action: backup_deleted
Category: system
Description: Backup deleted: backup_...sql
User: admin@permits.local
Status: warning
```

### **When to Delete:**
- Old backups (>30 days)
- Redundant backups
- Test backups
- Large backups eating disk space

### **Keep:**
- Most recent 3-5 backups
- Pre-major-change backups
- Monthly archival backups
- Known-good backups

---

## 📊 ACTIVITY LOGGING

### **All Actions Logged:**

✅ **Backup Created**
- When: Every create
- Status: success
- Includes: Filename, size

✅ **Backup Downloaded**
- When: Every download
- Status: success
- Includes: Filename

✅ **Backup Uploaded**
- When: Every upload
- Status: success
- Includes: Filename, size

✅ **Backup Restored**
- When: Every restore
- Status: warning (destructive)
- Includes: Filename

✅ **Backup Deleted**
- When: Every delete
- Status: warning
- Includes: Filename

✅ **Backup Failed**
- When: Error occurs
- Status: failed
- Includes: Error message

✅ **Page Viewed**
- When: Page accessed
- Status: success

### **View Logs:**
```
Method 1: Admin Panel → Activity Log → Filter: system
Method 2: Click "📊 View Backup Logs" button
Method 3: https://permits.defecttracker.uk/admin/activity.php?category=system
```

---

## 🎨 USER INTERFACE

### **Sections:**

**1. Database Information**
```
┌────────────────────────────┐
│ 🗄️ Database Information   │
├────────────────────────────┤
│ Database Name: k87747_permits
│ Host: 10.35.233.124
│ Port: 3306
│ Total Backups: 5
│ Backup Directory: /backups/
└────────────────────────────┘
```

**2. Quick Actions**
```
[📦 Create Backup] [📤 Upload Backup] [📊 View Backup Logs]
```

**3. Upload Area**
```
┌────────────────────────────┐
│         📁                 │
│ Click to upload or         │
│ drag & drop                │
│ SQL files only • Max 100MB │
└────────────────────────────┘
```

**4. Backup List**
```
┌─────────────────────────────────────────────────────┐
│ 📋 Available Backups (5)                            │
├──────────────────┬──────┬─────────────┬────────────┤
│ Backup File      │ Size │ Created     │ Actions    │
├──────────────────┼──────┼─────────────┼────────────┤
│ backup_...sql    │ 125KB│ 23/10/2025  │ [⬇️][🔄][🗑️]│
└──────────────────┴──────┴─────────────┴────────────┘
```

---

## ⚠️ IMPORTANT NOTES

### **Before Restoring:**
1. **ALWAYS create a backup first!**
2. Restoring will **DELETE ALL CURRENT DATA**
3. This action **CANNOT BE UNDONE**
4. Make sure you have the **CORRECT BACKUP FILE**
5. Test in development environment if possible

### **Best Practices:**
- ✅ Create backups before major changes
- ✅ Create backups before updates
- ✅ Download important backups locally
- ✅ Delete old backups regularly
- ✅ Keep 3-5 recent backups
- ✅ Store off-server for disaster recovery
- ✅ Test restores periodically

### **Backup Schedule:**
- **Daily:** Automatic (if setup)
- **Weekly:** Manual download
- **Monthly:** Archive to cloud
- **Before Updates:** Always!
- **Before Major Changes:** Always!

---

## 🔐 SECURITY

### **Access Control:**
- ✅ Admin-only access
- ✅ All actions logged
- ✅ IP addresses tracked
- ✅ User attribution

### **File Security:**
- ✅ Stored in /backups/ directory
- ✅ Not web-accessible (if configured)
- ✅ .htaccess protection recommended
- ✅ Regular cleanup

### **Sensitive Data:**
- ⚠️ Backups contain ALL data
- ⚠️ Includes user passwords (hashed)
- ⚠️ Includes all permits
- ⚠️ Includes all settings
- ⚠️ Store backups securely!

---

## 🐛 TROUBLESHOOTING

### **"Backup failed" Error**

**Causes:**
- MySQL not accessible
- Insufficient permissions
- Disk space full
- Wrong credentials

**Solutions:**
1. Check database credentials in .env
2. Verify MySQL is running
3. Check disk space: `df -h`
4. Check /backups/ folder permissions: `chmod 755`
5. Test MySQL connection manually

### **"Restore failed" Error**

**Causes:**
- Invalid SQL file
- MySQL syntax error
- Insufficient permissions
- Corrupted backup

**Solutions:**
1. Verify backup file is valid .sql
2. Try restoring different backup
3. Check MySQL error logs
4. Test backup file manually
5. Re-create backup and try again

### **"Cannot upload file"**

**Causes:**
- File too large (>100MB)
- Wrong file type (not .sql)
- Upload limit in PHP
- Insufficient permissions

**Solutions:**
1. Check file is .sql format
2. Check file size < 100MB
3. Increase PHP upload_max_filesize
4. Check /backups/ folder permissions: `chmod 755`

### **"Backup directory not found"**

**Cause:**
- /backups/ folder doesn't exist

**Solution:**
```bash
mkdir /path/to/permits/backups
chmod 755 /path/to/permits/backups
```

---

## 📋 CHECKLIST

After setup:

- [ ] File uploaded to /admin/backup.php
- [ ] /backups/ directory created
- [ ] Directory has 755 permissions
- [ ] Can access backup page
- [ ] Can create backup
- [ ] Backup appears in list
- [ ] Can download backup
- [ ] Can upload backup
- [ ] Can view file size
- [ ] Can see creation date
- [ ] Restore warning works
- [ ] Delete confirmation works
- [ ] Activity logging works
- [ ] All actions appear in logs

---

## 🎉 SUCCESS EXAMPLES

### **Creating Backup:**
```
✅ Backup created successfully!
File: backup_k87747_permits_2025-10-23_14-30-15.sql (1,234.56 KB)
```

### **Downloading Backup:**
```
✅ Backup download started
File: backup_k87747_permits_2025-10-23_14-30-15.sql
Size: 1,234.56 KB
```

### **Uploading Backup:**
```
✅ Backup uploaded successfully: backup_old_database.sql
```

### **Restoring Backup:**
```
✅ Database restored successfully from: backup_k87747_permits_2025-10-23_14-30-15.sql
```

### **Deleting Backup:**
```
✅ Backup deleted: backup_old_2025-10-01.sql
```

---

## 🚀 DEPLOYMENT

### **Upload This File:**
```
/admin/backup.php → /admin/backup.php on server
```

### **Create Directory:**
```
mkdir /backups/
chmod 755 /backups/
```

### **Access:**
```
https://permits.defecttracker.uk/admin/backup.php
```

### **Test:**
1. Create backup ✅
2. Download it ✅
3. Upload it ✅
4. Check logs ✅
5. Delete test backup ✅

---

## 💡 TIPS & TRICKS

### **Quick Backup Before Changes:**
```
1. Go to Backup & Restore
2. Click "Create Backup"
3. Wait 5 seconds
4. Make your changes
5. If something breaks, restore!
```

### **Download Weekly Backups:**
```
Every Friday:
1. Go to Backup & Restore
2. Create new backup
3. Download it
4. Store on external drive
5. Delete backups older than 30 days
```

### **Disaster Recovery:**
```
1. Always keep 3 backups:
   - Latest (today)
   - Week old
   - Month old
2. Store on different locations:
   - Server
   - Local computer
   - Cloud storage (Google Drive, Dropbox)
3. Test restores quarterly
```

---

## 🎯 NEXT STEPS

**Now that you have backup:**

1. **Create Your First Backup**
   - Go to backup page
   - Click create
   - Download it!

2. **Setup Schedule**
   - Weekly manual backups
   - Download to local
   - Cloud storage

3. **Monitor Space**
   - Delete old backups
   - Keep 3-5 recent
   - Archive important ones

4. **Test Restore** (Optional)
   - In development
   - Verify it works
   - Good practice!

---

## 🎉 YOU'RE DONE!

**Complete backup system ready!**

You can now:
- ✅ Create backups instantly
- ✅ Download to your computer
- ✅ Upload from external files
- ✅ Restore any time
- ✅ Track all actions
- ✅ Manage disk space
- ✅ Protect your data!

**Your data is now safe!** 💾✨

---

**Last Updated:** 23/10/2025, 02:30 GMT
