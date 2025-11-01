# QR Code System - Quick Reference

## 🎯 Two Admin Tools Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                     ADMIN PANEL - QR TOOLS                      │
└─────────────────────────────────────────────────────────────────┘

┌──────────────────────────┐    ┌──────────────────────────┐
│   🔲 ALL PERMITS         │    │   📋 INDIVIDUAL          │
│                          │    │                          │
│  Display all active +    │    │  Search & select one     │
│  pending permits with    │    │  permit, generate        │
│  QR codes in grid        │    │  detailed QR code        │
│                          │    │                          │
│  • Print all at once     │    │  • Search by ref/holder  │
│  • Auto-refresh 60s      │    │  • Large QR display      │
│  • Company branding      │    │  • Print individual      │
│  • PDF export            │    │  • Download QR image     │
│  • Stats dashboard       │    │  • Mobile friendly       │
│                          │    │                          │
│  BEST FOR:               │    │  BEST FOR:               │
│  ✓ Notice boards         │    │  ✓ Specific stakeholders │
│  ✓ Batch printing        │    │  ✓ Email sharing         │
│  ✓ Quick overview        │    │  ✓ Custom prints         │
│  ✓ Large displays        │    │  ✓ Single permit focus   │
└──────────────────────────┘    └──────────────────────────┘
```

---

## 🌐 Feature Matrix

| Feature | All Permits | Individual |
|---------|:-----------:|:----------:|
| Display Multiple | ✅ | ❌ |
| Search | ✅ | ✅ |
| Company Logo | ✅ | ✅ |
| Print to PDF | ✅ | ✅ |
| Download QR | ❌ | ✅ |
| Auto-Refresh | ✅ | ❌ |
| Permit Details | Basic | Full |
| Mobile Friendly | ✅ | ✅ |
| Batch Operations | ✅ | ❌ |
| Grid Layout | ✅ | ❌ |

---

## 📱 Device Support

### Desktop (1920px+)
- All Permits: 3-column grid
- Individual: 2-column layout (sidebar + viewer)

### Tablet (768-1024px)
- All Permits: 2-column grid
- Individual: Responsive stacking

### Mobile (320-768px)
- All Permits: 1-column grid
- Individual: Stacked layout with full-width search

---

## 🖨️ Print Workflow

### Step 1: Navigate
```
Admin Panel → QR Codes (All or Individual)
```

### Step 2: Select
```
All Permits: Auto-displayed
Individual: Search and select from list
```

### Step 3: Print
```
Click "Print / Save as PDF" button
↓
Browser print dialog opens
↓
Select "Save as PDF"
↓
Configure margins (narrow recommended)
↓
Save file with descriptive name
```

### Step 4: Use
```
Print PDF on standard or large format
Post on notice board
Share via email
Archive for records
```

---

## 🔐 Access Control

```
┌─────────────────────────────────┐
│    User Requests QR Page        │
└────────────┬────────────────────┘
             │
             ▼
┌─────────────────────────────────┐
│  Session Active?                │
│  (Check $_SESSION['user_id'])   │
└────────────┬────────────────────┘
        No  │  Yes
            │  │
            │  ▼
            │ ┌─────────────────────────────────┐
            │ │ User Role = Admin?              │
            │ │ (Check $currentUser['role'])    │
            │ └────────────┬────────────────────┘
            │          No  │  Yes
            │              │  │
            ▼              ▼  ▼
      ┌─────────┐      ┌────────────┐
      │ DENIED  │      │ ALLOWED    │
      │ Redirect│      │ Load Page  │
      └─────────┘      └────────────┘
```

---

## 📊 Data Flow

### QR Generation Process

```
┌──────────────────────────────────┐
│ Database Query                   │
│ SELECT forms WHERE status IN     │
│ ('active', 'pending_approval')   │
└────────────┬─────────────────────┘
             │
             ▼
┌──────────────────────────────────┐
│ For Each Permit:                 │
│ - Get unique_link                │
│ - Build full URL                 │
│ - Generate QR code               │
│ - Convert to base64              │
└────────────┬─────────────────────┘
             │
             ▼
┌──────────────────────────────────┐
│ Render HTML with:                │
│ - Company logo (from settings)   │
│ - Company name (from settings)   │
│ - Permit details                 │
│ - QR code image                  │
└──────────────────────────────────┘
```

---

## 🎨 Color Scheme

### Status Colors
```
Active Permit          Pending Approval       Error
─────────────          ────────────────       ─────
Background: #10b98120  Background: #f59e0b20  Background: #ef444420
Text: #10b981          Text: #f59e0b          Text: #ef4444
```

### UI Colors
```
Primary Accent         Secondary Text         Border
──────────────         ──────────────         ──────
#06b6d4 (Cyan)         #94a3b8 (Slate)        #1e293b (Dark)
#0ea5e9 (Sky)          #64748b (Dark Slate)   #334155 (Darker)
```

---

## ⚙️ Configuration Options

### Edit Auto-Refresh Interval (All Permits)
```javascript
// Change from 60000ms to desired milliseconds
setTimeout(() => {
    location.reload();
}, 60000);  // 60 seconds

// Examples:
// 30000 = 30 seconds
// 120000 = 2 minutes
// 300000 = 5 minutes
```

### Edit Grid Columns (All Permits)
```css
.qr-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);  /* Change 3 to 2, 4, etc */
    gap: 24px;
}
```

### Edit QR Code Scale
```php
$qrOptions = new QROptions([
    'scale' => 5,  // Change 3-10 (larger = bigger QR)
    'quietzoneSize' => 2,
]);
```

---

## 🔍 Search Capabilities (Individual Page)

### Search by:
- ✅ Permit Reference Number (e.g., "PTW-2025-001")
- ✅ Template Type (e.g., "Work at Height")
- ✅ Holder Name (e.g., "John Smith")
- ✅ Holder Email (e.g., "john@example.com")
- ✅ Partial matches (e.g., "2025" matches all 2025 permits)

### Search Examples:
```
Query: "PTW"           → All permits starting with PTW
Query: "Height"        → All Work at Height permits
Query: "john"          → Permits for users with "john" in name/email
Query: "active"        → Filters by status
```

---

## 📋 Permit Information Displayed

### All Permits Page (Per Card):
- Permit Reference Number
- Template Type
- Status Badge (Active/Pending)
- QR Code
- Scan Instructions

### Individual Page (Full Details):
- Permit Reference Number
- Template Type
- Current Status (with color badge)
- Permit Holder
- Created Date & Time
- Valid From & To Dates
- Large QR Code
- Scan Instructions

---

## 🚀 Integration Points

### Database Tables Used:
```
forms
├── id
├── ref_number
├── template_id
├── status
├── holder_id
├── unique_link
└── created_at

form_templates
├── id
└── name

users
├── id
├── name
└── email

settings
├── key
└── value
```

### External Functions Used:
```
SystemSettings::companyName($db)
SystemSettings::companyLogoPath($db)
asset(path)
app->url(path)
htmlspecialchars()
```

---

## ✅ Validation Checklist

Before deploying, verify:
- [ ] PHP version 8.0+ installed
- [ ] chillerlan/qr-code package available
- [ ] Company logo uploaded via System Settings
- [ ] Permits exist with active/pending status
- [ ] Permits have unique_link generated
- [ ] Admin accounts have 'admin' role
- [ ] Browser supports print-to-PDF
- [ ] All files have no syntax errors

---

## 🆘 Common Issues & Solutions

| Issue | Cause | Solution |
|-------|-------|----------|
| No QR codes showing | Missing unique_link | Run permit creation with link generation |
| Logo not visible | Path misconfigured | Check System Settings → upload logo |
| Can't access pages | Not admin | Login with admin account |
| PDF too large | High QR scale | Reduce 'scale' value in code |
| QR won't scan | Damaged generation | Check chillerlan package installed |
| Print missing content | Print margins too tight | Adjust margins in print dialog |

---

## 📞 Support

For issues or questions:
1. Check QR_CODE_SYSTEM.md for detailed docs
2. Verify admin access and roles
3. Check browser console for JavaScript errors
4. Verify database connectivity
5. Check file permissions for logos

---

**Quick Links:**
- Admin Panel: `/admin.php`
- All Permits QR: `/admin/qr-codes-all.php`
- Individual QR: `/admin/qr-codes-individual.php`
- System Settings: `/admin/settings.php`
- Full Docs: `QR_CODE_SYSTEM.md`

**Last Updated**: 01/11/2025  
**Version**: 1.0  
**Status**: Production Ready ✅
