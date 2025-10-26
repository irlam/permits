# 🚨 .env File Syntax Error - FIXED

**Error:** `Failed to parse dotenv file. Encountered unexpected whitespace at [Permits System]`  
**Cause:** Missing quotes around values with spaces  
**Solution:** Add quotes or update settings page

---

## ⚡ IMMEDIATE FIX

### **Your .env file currently has:**
```env
APP_NAME=Permits System
COMPANY_NAME=Acme Corporation Ltd
```

### **Should be:**
```env
APP_NAME="Permits System"
COMPANY_NAME="Acme Corporation Ltd"
```

---

## 🔧 **OPTION 1: Manual Fix (Do This Now!)**

### **Step 1: Open .env file**
Via FTP, cPanel File Manager, or SSH

### **Step 2: Find problematic lines**
Look for lines with spaces but no quotes:
```env
APP_NAME=Permits System          ❌ ERROR
COMPANY_NAME=Acme Corporation    ❌ ERROR
COMPANY_ADDRESS=123 Street       ❌ ERROR
```

### **Step 3: Add quotes**
```env
APP_NAME="Permits System"            ✅ FIXED
COMPANY_NAME="Acme Corporation"      ✅ FIXED
COMPANY_ADDRESS="123 Street"         ✅ FIXED
```

### **Step 4: Save and test**
Save file, refresh site - should work!

---

## 📋 **COMPLETE CORRECT .env FILE**

Copy this template and adjust your values:

```env
# Database Configuration
DB_DSN=mysql:host=10.35.233.124;port=3306;dbname=k87747_permits;charset=utf8mb4
DB_USER=k87747_permits
DB_PASS=Subaru5554346

# Application Settings
APP_URL=/
APP_NAME="Permits System"
APP_TIMEZONE="Europe/London"
DATE_FORMAT="d/m/Y H:i"

# Security
ADMIN_KEY=pick-a-long-random-string
SESSION_TIMEOUT=1440
PASSWORD_MIN_LENGTH=8
REQUIRE_PASSWORD_SPECIAL=false

# Company Information (add quotes!)
COMPANY_NAME="Your Company Name Here"
COMPANY_ADDRESS="Your Address Line 1
Address Line 2
City, Postcode"
COMPANY_PHONE="+44 20 1234 5678"
COMPANY_EMAIL="info@yourcompany.com"
COMPANY_LOGO=uploads/logo.png

# Permit Settings
PERMIT_REF_PREFIX="SITE-"
PERMIT_EXPIRY_WARNING_24H=true
PERMIT_EXPIRY_WARNING_7D=true
PERMIT_AUTO_EXPIRE=true
```

---

## 🔧 **OPTION 2: Updated Settings Page**

I've also fixed `/admin/settings.php` to automatically add quotes when saving!

**FILE: `/admin/settings.php` (UPDATED)**

**What Changed:**
- ✅ Always adds quotes around values
- ✅ Escapes existing quotes properly
- ✅ Handles multi-line text
- ✅ Prevents future syntax errors

**Upload this file** and future saves from the settings page will be properly quoted!

---

## 📖 **ENV FILE RULES**

### **When Quotes Are REQUIRED:**
```env
# Has spaces → MUST quote
APP_NAME="Permits System"
COMPANY_NAME="Acme Corp Ltd"

# Has special characters → MUST quote
PASSWORD="P@ssw0rd!"
EMAIL="admin@site.com"

# Multi-line → MUST quote
ADDRESS="Line 1
Line 2
Line 3"

# Has quotes inside → MUST quote and escape
TEXT="He said \"hello\""
```

### **When Quotes Are OPTIONAL:**
```env
# Simple numbers
PORT=3306
TIMEOUT=1440

# Boolean values
DEBUG=true
ENABLED=false

# Simple paths
LOGO=uploads/logo.png

# URLs without spaces
APP_URL=/
```

### **Best Practice:**
**Quote everything except numbers and booleans!**

```env
# Safe approach
APP_NAME="My App"
DB_HOST="localhost"
DB_PORT=3306
DEBUG=true
COMPANY="Acme Corp"
```

---

## 🐛 **COMMON ERRORS**

### **Error 1: Spaces Without Quotes**
```env
APP_NAME=Permits System  ❌
APP_NAME="Permits System" ✅
```

### **Error 2: Multi-line Without Quotes**
```env
ADDRESS=123 Street       ❌
London
UK

ADDRESS="123 Street      ✅
London
UK"
```

### **Error 3: Special Characters**
```env
PASSWORD=P@ssw0rd!       ❌
PASSWORD="P@ssw0rd!"     ✅
```

### **Error 4: Unescaped Quotes**
```env
TEXT="He said "hello""   ❌
TEXT="He said \"hello\"" ✅
```

---

## ✅ **VERIFICATION**

After fixing, test by visiting:
```
https://permits.defecttracker.uk/
```

Should load without errors!

---

## 🚀 **BOTH SOLUTIONS**

### **1. Manual Fix (Immediate)**
- Edit `.env` file now
- Add quotes around values with spaces
- Save and test

### **2. Upload Updated Settings Page**
- Upload `/admin/settings.php`
- Future saves automatically quoted
- No more manual fixing

---

## 📋 **CHECKLIST**

- [ ] Open `.env` file
- [ ] Add quotes around `APP_NAME`
- [ ] Add quotes around `COMPANY_NAME`
- [ ] Add quotes around `COMPANY_ADDRESS`
- [ ] Add quotes around any other values with spaces
- [ ] Save file
- [ ] Test site (should load)
- [ ] Upload updated `settings.php` (prevents future issues)

---

## 💡 **QUICK REFERENCE**

**Before:**
```env
APP_NAME=Permits System
COMPANY_NAME=Acme Corp
```

**After:**
```env
APP_NAME="Permits System"
COMPANY_NAME="Acme Corp"
```

**That's it!** Just add quotes! 🎯

---

**Last Updated:** 22/10/2025, 00:15 GMT
