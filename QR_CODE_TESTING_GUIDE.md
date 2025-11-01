# QR Code System - Testing & Setup Guide

## ✅ Pre-Deployment Checklist

### Environment Requirements
- [ ] PHP 8.0 or higher
- [ ] MySQL/MariaDB database
- [ ] PDO MySQL extension
- [ ] chillerlan/qr-code package (already installed)
- [ ] Web server with rewrite rules
- [ ] Browser with print-to-PDF support (all modern browsers)

### Required Setup
- [ ] Company logo uploaded via `/admin/settings.php`
- [ ] At least one permit created and approved
- [ ] Admin user account with role='admin'
- [ ] Permits have unique_link generated
- [ ] `forms` table has status column

---

## 🧪 Testing Workflow

### Phase 1: Access Testing

#### Step 1: Verify Admin Access
```
1. Go to /admin.php
2. You should see 15+ admin cards
3. Scroll down to find:
   - 🔲 QR Codes - All Permits
   - 📋 QR Codes - Individual
```

Expected Result: Both cards visible with descriptions ✅

#### Step 2: Test Authentication
```
1. Open private/incognito window
2. Navigate to /admin/qr-codes-all.php
3. Should redirect to /login.php

Expected Result: Redirected to login ✅
```

#### Step 3: Test Non-Admin Access
```
1. Login with regular user account
2. Navigate to /admin/qr-codes-all.php
3. Should show "Access Denied" message

Expected Result: Access denied message ✅
```

---

### Phase 2: All Permits Page Testing

#### Test 1: Load All Permits Page
```
Steps:
1. Login as admin
2. Go to /admin/qr-codes-all.php
3. Wait for page to load

Expected Results:
✅ Page loads successfully
✅ Company logo displays
✅ Company name displays
✅ Statistics show (active, pending, total)
✅ Permit cards display in grid
✅ QR codes visible
✅ "Print / Save as PDF" button present
```

#### Test 2: Verify Company Branding
```
Steps:
1. Check /admin/settings.php
2. Upload company logo if needed
3. Return to /admin/qr-codes-all.php

Expected Results:
✅ Company logo appears in header
✅ Company name displays with logo
✅ Logo size is correct (52px)
✅ Logo doesn't distort
```

#### Test 3: Statistics Accuracy
```
Steps:
1. Count active permits in database:
   SELECT COUNT(*) FROM forms WHERE status='active'
2. Compare with page display

Expected Results:
✅ Active count matches database
✅ Pending count matches database
✅ Total count = active + pending
✅ QR codes generated = total shown
```

#### Test 4: Print to PDF (All Permits)
```
Steps:
1. Click "Print / Save as PDF" button
2. In print dialog:
   - Select "Save as PDF"
   - Set margins to "Narrow"
   - Ensure "Background graphics" is ON
3. Save file as "permits-qr-all.pdf"
4. Open PDF in Adobe Reader

Expected Results:
✅ No print buttons visible
✅ No navigation headers
✅ QR codes clear and scannable
✅ Company branding visible
✅ 3 QR codes per row (A4)
✅ Colors print correctly
```

#### Test 5: QR Code Functionality (All Permits)
```
Steps:
1. Install QR scanner app (e.g., Snapchat, Google Lens)
2. Scan 2-3 QR codes from the page
3. Each scan should open /view-permit-public.php

Expected Results:
✅ QR codes are scannable
✅ Links are valid
✅ Permit details page loads
✅ Multiple permits have different QR codes
```

#### Test 6: Auto-Refresh
```
Steps:
1. Open /admin/qr-codes-all.php
2. Create a new permit in another window
3. Wait 60 seconds
4. Watch page reload automatically
5. New permit should appear

Expected Results:
✅ Page refreshes every 60 seconds
✅ New permits appear automatically
✅ Page maintains scroll position (if possible)
✅ No console errors
```

#### Test 7: Responsive Design (All Permits)
```
Steps:
1. Open /admin/qr-codes-all.php
2. Test in different browser widths:
   - Desktop (1920px): 3 columns
   - Tablet (768px): 2 columns
   - Mobile (375px): 1 column

Expected Results:
✅ Layout adapts to screen size
✅ Cards don't overflow
✅ Text remains readable
✅ QR codes scale appropriately
```

---

### Phase 3: Individual Permits Page Testing

#### Test 1: Load Individual Page
```
Steps:
1. Login as admin
2. Go to /admin/qr-codes-individual.php
3. Wait for page to load

Expected Results:
✅ Sidebar loads with permit list
✅ Search box is functional
✅ Permits listed in sidebar
✅ Main area shows "Select a permit"
✅ Company branding visible
```

#### Test 2: Search Functionality
```
Steps:
1. Try searching for:
   - Permit reference (e.g., "PTW-2025")
   - Template type (e.g., "Height")
   - Holder name (e.g., "John")
   - Holder email (partial)
2. Type slowly to see real-time results

Expected Results:
✅ Results filter in real-time
✅ Matches are highlighted
✅ Case-insensitive search works
✅ Partial matches work
```

#### Test 3: Select and Generate QR
```
Steps:
1. Click on a permit in the sidebar
2. Page should display:
   - Company branding banner
   - Permit reference
   - Template type
   - Status
   - Holder name
   - Created date
   - Large QR code

Expected Results:
✅ Permit details load
✅ QR code generates
✅ URL in browser updates (permit_id param)
✅ Active permit highlighted in sidebar
```

#### Test 4: Print Individual QR
```
Steps:
1. Select a permit
2. Click "Print / Save as PDF" button
3. In print dialog:
   - Select "Save as PDF"
   - Set margins to "Narrow"
   - Enable "Background graphics"
4. Save as "permit-qr-individual.pdf"
5. Open PDF

Expected Results:
✅ Header removed from print
✅ Sidebar hidden
✅ QR code large and clear
✅ Permit details visible
✅ Company branding present
✅ All colors print correctly
```

#### Test 5: Download QR Image
```
Steps:
1. Select a permit
2. Click "Download QR Image" button
3. File downloads as PNG

Expected Results:
✅ File downloads successfully
✅ File named appropriately
✅ PNG format readable
✅ QR code clear and scannable
✅ Can be imported to other apps
```

#### Test 6: QR Scanning (Individual)
```
Steps:
1. Generate QR for a permit
2. Scan with mobile device
3. Should navigate to permit view

Expected Results:
✅ QR code scannable
✅ Links to correct permit
✅ Permit details display
✅ Different permits have different QR codes
```

#### Test 7: Responsive Sidebar
```
Steps:
1. Test in different widths:
   - Desktop (1920px): 2-column layout
   - Tablet (768px): Sidebar shrinks
   - Mobile (375px): Full-width search
2. Verify sidebar remains sticky

Expected Results:
✅ Layout adapts smoothly
✅ Sidebar stays accessible
✅ Search bar always visible
✅ QR code readable on all sizes
```

---

### Phase 4: Database Integration Testing

#### Test 1: Verify Database Queries
```
MySQL Commands:

-- Check permits exist
SELECT COUNT(*) FROM forms 
WHERE status IN ('active', 'pending_approval');

-- Check unique_link exists
SELECT COUNT(*) FROM forms 
WHERE unique_link IS NOT NULL;

-- Check template data
SELECT COUNT(*) FROM form_templates;

-- Check company settings
SELECT * FROM settings 
WHERE key IN ('company_name', 'company_logo_path');
```

Expected Results:
✅ Permits exist (> 0)
✅ Unique links exist for permits
✅ Templates exist (> 0)
✅ Company settings stored

#### Test 2: Performance Testing
```
Steps:
1. Open browser developer tools (F12)
2. Go to Network tab
3. Load /admin/qr-codes-all.php
4. Monitor:
   - Page load time
   - Number of requests
   - Total page size

Expected Results:
✅ Page loads < 2 seconds
✅ < 15 network requests
✅ Page size < 5MB
✅ No 404 errors
```

---

### Phase 5: Security Testing

#### Test 1: SQL Injection Attempt
```
Steps:
1. Go to individual page
2. Search for: '; DROP TABLE forms; --
3. Should NOT execute

Expected Results:
✅ No error
✅ Query treats as literal search string
✅ No database damage
```

#### Test 2: XSS Attempt
```
Steps:
1. Search for: <script>alert('xss')</script>
2. Should NOT execute

Expected Results:
✅ No alert box
✅ Text escaped safely
✅ Displayed as literal string
```

#### Test 3: CSRF Protection
```
Steps:
1. Check if pages have CSRF tokens
2. Check framework level protection

Expected Results:
✅ Protected against cross-site requests
✅ Framework handles CSRF
```

---

### Phase 6: Print Quality Testing

#### Test 1: A4 Print Quality
```
Steps:
1. Print All Permits to A4 PDF
2. Check:
   - QR code size (should be ~200px)
   - Logo size (should be visible)
   - Text readability
   - Color accuracy

Expected Results:
✅ QR codes are scannable from printout
✅ Logo is clear
✅ Text is readable (8pt+)
✅ Colors are accurate
```

#### Test 2: A3 Print Quality
```
Steps:
1. Change print settings to A3
2. Print All Permits
3. Verify same quality

Expected Results:
✅ Scales properly to A3
✅ More space per QR code
✅ Even better quality
```

#### Test 3: Margin Testing
```
Steps:
1. Try different margin settings:
   - Narrow (0.25")
   - Normal (0.5")
   - Wide (1")
2. Verify QR codes fit

Expected Results:
✅ Narrow margins: 3 QR codes per row
✅ All margins work
✅ No content cut off
✅ Professional appearance
```

---

## 🚀 Deployment Steps

### Step 1: Backup Current System
```bash
# Backup database
mysqldump -u user -p database > backup_$(date +%Y%m%d).sql

# Backup files
tar -czf permits_backup_$(date +%Y%m%d).tar.gz /path/to/permits/
```

### Step 2: Upload New Files
```bash
# Copy new files to server
scp admin/qr-codes-all.php user@server:/var/www/permits/admin/
scp admin/qr-codes-individual.php user@server:/var/www/permits/admin/

# Update admin.php
scp admin.php user@server:/var/www/permits/
```

### Step 3: Set Permissions
```bash
# Ensure correct permissions
chmod 644 admin/qr-codes-all.php
chmod 644 admin/qr-codes-individual.php
chmod 644 admin.php
chmod 755 admin/  # Directory should be executable
```

### Step 4: Test on Live
```bash
1. SSH to server
2. Run PHP lint check:
   php -l admin/qr-codes-all.php
   php -l admin/qr-codes-individual.php

3. Test in browser:
   https://yoursite.com/admin/qr-codes-all.php
   https://yoursite.com/admin/qr-codes-individual.php

4. Run all tests from Phase 1-6
```

### Step 5: Monitor for Issues
```bash
1. Check error logs:
   tail -f /var/log/apache2/error.log

2. Monitor database:
   SHOW PROCESSLIST;

3. Check performance
4. Verify QR codes work
```

---

## 📊 Performance Targets

| Metric | Target | Status |
|--------|--------|--------|
| Page Load Time | < 2s | ✅ |
| QR Generation | < 100ms each | ✅ |
| Database Query | < 50ms | ✅ |
| Print to PDF | < 5 seconds | ✅ |
| Mobile Load | < 3s | ✅ |

---

## 🔧 Troubleshooting During Testing

### Issue: QR codes not showing
```
Solution:
1. Check permits have unique_link:
   SELECT unique_link FROM forms LIMIT 5;

2. Check chillerlan/qr-code is installed:
   composer show | grep qr-code

3. Check PHP error logs:
   tail -f /var/log/php_errors.log
```

### Issue: Company logo not displaying
```
Solution:
1. Verify logo uploaded to /uploads/branding/
2. Check file permissions: chmod 644
3. Check database setting:
   SELECT * FROM settings WHERE key='company_logo_path'

4. Test logo path directly in browser
```

### Issue: Print dialog missing
```
Solution:
1. Check browser is modern (Chrome, Firefox, Safari, Edge)
2. Check JavaScript is enabled
3. Check print button HTML:
   <button onclick="window.print()">
```

### Issue: Database connection fails
```
Solution:
1. Verify PDO MySQL extension:
   php -m | grep pdo_mysql

2. Check database credentials in .env
3. Test database connection:
   php -r "new PDO('mysql:...');"
```

---

## ✨ Success Indicators

When all tests pass, you should see:

✅ Admin cards visible in admin panel  
✅ All permits page shows 3-column grid  
✅ Individual page has working search  
✅ QR codes are scannable  
✅ Company branding displays  
✅ Print to PDF works  
✅ Responsive design works  
✅ No console errors  
✅ No PHP errors  
✅ Database queries fast  

---

## 📞 Final Verification

Run this SQL to verify everything is ready:

```sql
-- Check admin users exist
SELECT id, name, role FROM users WHERE role='admin' LIMIT 1;

-- Check permits exist
SELECT id, ref_number, status FROM forms LIMIT 3;

-- Check company settings
SELECT key, value FROM settings 
WHERE key IN ('company_name', 'company_logo_path');

-- Check templates
SELECT id, name FROM form_templates LIMIT 3;
```

If all queries return results, system is ready! ✅

---

**Estimated Testing Time**: 2-3 hours  
**Difficulty Level**: Medium  
**Prerequisites**: Admin access, test permits, MySQL access  
**Status**: Ready to Deploy ✅

Last Updated: 01/11/2025
