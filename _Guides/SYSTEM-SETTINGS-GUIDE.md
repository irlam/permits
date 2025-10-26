# ⚙️ System Settings - Complete Guide

**File Path:** `/admin/settings.php`  
**Created:** 21/10/2025  
**Access:** Admin only

---

## ✅ WHAT'S NEW

Complete system settings page to configure your entire permits system:

- 🏢 **Company Information** - Name, address, contact details
- 🌐 **Site Settings** - App name, timezone, date format
- 📋 **Permit Settings** - Reference prefix, expiry warnings
- 🔒 **Security Settings** - Session timeout, password rules

---

## 🎯 SETTINGS CATEGORIES

### 🏢 **Company Information**

**Company Name**
- Your organization name
- Appears on permits and reports
- Example: "Acme Corporation Ltd"

**Company Address**
- Full postal address
- Multi-line format
- Appears on official documents

**Phone & Email**
- Main contact information
- Shown on permits
- For contractor inquiries

---

### 🌐 **Site Settings**

**Application Name**
- Displayed in browser tabs
- Page headers
- Email notifications
- Default: "Permits System"

**Timezone**
- System timezone for all dates/times
- Options:
  - UTC
  - Europe/London (UK)
  - America/New_York (EST)
  - Asia/Dubai
  - Australia/Sydney
  - Many more...

**Date Format**
- How dates display throughout system
- Options:
  - `DD/MM/YYYY HH:MM` (UK)
  - `MM/DD/YYYY HH:MM AM/PM` (US)
  - `YYYY-MM-DD HH:MM` (ISO)
  - `DD.MM.YYYY HH:MM` (EU)

---

### 📋 **Permit Settings**

**Reference Prefix**
- Optional prefix for all permit refs
- Example: "SITE-" creates "SITE-HW-2025-001"
- Max 10 characters
- Leave blank for no prefix

**Expiry Notifications**

**24-Hour Warning** ⚠️
- Urgent email alert
- Red priority
- Sent when permit < 24h from expiry
- Checkbox: Enable/disable

**7-Day Warning** 📅
- Advance warning
- Orange priority
- Sent when permit < 7 days from expiry
- Checkbox: Enable/disable

**Auto-Expire Permits** 🔄
- Automatically change status
- When valid_to date passes
- Status changes to "expired"
- Runs via cron job

---

### 🔒 **Security Settings**

**Session Timeout**
- Minutes before auto-logout
- Default: 1440 (24 hours)
- Range: 5 to 10,080 (1 week)
- Balance security vs convenience

**Minimum Password Length**
- Characters required
- Default: 8
- Range: 6 to 32
- Enforced on user creation

**Require Special Characters**
- Force `!@#$%^&*` in passwords
- Checkbox: Enable/disable
- Increases password strength
- May frustrate users

---

## 🚀 QUICK START

### **Step 1: Access Settings**
1. Login as admin
2. Admin Panel
3. Click "System Settings"

### **Step 2: Configure Company**
1. Enter company name
2. Add address (multi-line)
3. Add phone and email
4. Save

### **Step 3: Set Timezone**
1. Select your timezone
2. Choose date format
3. Save

### **Step 4: Configure Permits**
1. Set reference prefix (optional)
2. Enable expiry warnings
3. Enable auto-expire
4. Save

### **Step 5: Security**
1. Set session timeout
2. Set password length
3. Enable special characters (optional)
4. Save

---

## 💾 HOW IT WORKS

### **Storage**
All settings saved to `.env` file:
```env
COMPANY_NAME=Acme Corporation Ltd
COMPANY_ADDRESS=123 Business Street
COMPANY_PHONE=+44 20 1234 5678
APP_TIMEZONE=Europe/London
DATE_FORMAT=d/m/Y H:i
PERMIT_REF_PREFIX=SITE-
SESSION_TIMEOUT=1440
```

### **Instant Effect**
- Most settings apply immediately
- Some require page refresh
- No server restart needed
- Changes persist forever

### **Security**
- Admin-only access
- Settings stored in .env (not database)
- Not visible to regular users
- Backed up with .env file

---

## 📋 DEFAULT VALUES

If not set, these defaults apply:

```
APP_NAME=Permits System
APP_TIMEZONE=UTC
DATE_FORMAT=d/m/Y H:i
PERMIT_EXPIRY_WARNING_24H=true
PERMIT_EXPIRY_WARNING_7D=true
PERMIT_AUTO_EXPIRE=true
PERMIT_REF_PREFIX=(blank)
SESSION_TIMEOUT=1440
PASSWORD_MIN_LENGTH=8
REQUIRE_PASSWORD_SPECIAL=false
```

---

## 🎨 USER INTERFACE

### **Layout**
```
┌─────────────────────────────────────┐
│ ⚙️ System Settings                  │
├─────────────────────────────────────┤
│                                     │
│ 🏢 Company Information              │
│ [Company Name field]                │
│ [Address textarea]                  │
│ [Phone] [Email]                     │
│                                     │
│ 🌐 Site Settings                    │
│ [App Name]                          │
│ [Timezone] [Date Format]            │
│                                     │
│ 📋 Permit Settings                  │
│ [Reference Prefix]                  │
│ ☑ 24-Hour Warning                   │
│ ☑ 7-Day Warning                     │
│ ☑ Auto-Expire                       │
│                                     │
│ 🔒 Security Settings                │
│ [Session Timeout] [Password Length] │
│ ☐ Require Special Characters        │
│                                     │
├─────────────────────────────────────┤
│ 💾 Save All Settings                │
└─────────────────────────────────────┘
```

### **Features**
- ✅ Sticky save button at bottom
- ✅ Success/error messages
- ✅ Helpful descriptions under each field
- ✅ Organized into sections
- ✅ Icons for visual clarity
- ✅ Responsive grid layout

---

## 🔄 INTEGRATION WITH SYSTEM

### **Company Info Usage**
- Displayed on permit PDFs
- Shown in email footers
- Printed reports
- QR code vCards

### **Timezone Impact**
- All database timestamps
- Email send times
- Permit expiry checks
- Activity logs
- Cron job scheduling

### **Date Format Impact**
- All date displays
- User interface
- Reports and exports
- Email notifications

### **Permit Settings Impact**
- Reference generation
- Email notification triggers
- Cron job behavior
- Status automation

### **Security Settings Impact**
- Login sessions
- User creation validation
- Password reset requirements
- Authentication rules

---

## 🐛 TROUBLESHOOTING

### **Settings Not Saving**
- Check .env file is writable
- Check file permissions
- Check admin authentication
- Check for PHP errors

### **Changes Not Taking Effect**
- Hard refresh browser (`Ctrl + Shift + R`)
- Clear browser cache
- Check .env file updated
- Some changes need re-login

### **Timezone Not Working**
- PHP timezone must be set
- Check php.ini settings
- May need server restart
- Verify timezone string valid

### **Special Characters Not Enforced**
- Feature not yet implemented in Auth class
- Currently just saved to config
- Will be enforced in future update
- Use as documentation for now

---

## ✅ TESTING CHECKLIST

After configuring settings:

- [ ] Company name appears correctly
- [ ] Timezone shows correct times
- [ ] Date format displays as expected
- [ ] Reference prefix works on new permits
- [ ] Email notifications use company info
- [ ] Session timeout works as configured
- [ ] Password length enforced

---

## 🔮 FUTURE ENHANCEMENTS

Planned additions:
- Logo upload
- Custom email templates
- Multiple date format examples
- Backup schedule configuration
- Maintenance mode toggle
- Custom status options
- Permit number format customization
- Email signature editor
- Two-factor authentication settings

---

## 📞 COMMON QUESTIONS

**Q: Do I need to fill in all fields?**
A: No, only fill what's relevant. Blanks use defaults.

**Q: Will changing timezone affect existing permits?**
A: No, only affects how dates are displayed.

**Q: Can I use a custom date format?**
A: Not yet - choose from provided options.

**Q: How often should I backup .env?**
A: After any settings change, or weekly.

**Q: Can regular users see these settings?**
A: No, admin-only access.

---

## 🎉 COMPLETE!

You now have a centralized system settings page to configure your entire permits system!

**Access:** `/admin/settings.php`

---

**Last Updated:** 21/10/2025, 23:15 GMT