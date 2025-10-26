# 🚀 HYBRID PUBLIC PERMIT SYSTEM - COMPLETE PLAN

**Date:** 23/10/2025  
**Status:** IN PROGRESS  
**Phase:** 4 - Hybrid System with Notifications

---

## 📋 SYSTEM OVERVIEW

### **What This Does:**
- ✅ Public users can create permits (no login)
- ✅ Check permit status by email on homepage
- ✅ Optional push notifications when approved
- ✅ Managers approve/reject permits
- ✅ Email notifications on approval
- ✅ PWA support (offline, notifications, install)

---

## 📦 FILES TO CREATE

### **✅ COMPLETED:**
1. update-hybrid-system.php - Database update script
2. create-permit-public.php - Public permit creation form

### **🔄 IN PROGRESS:**
3. Updated index.php - With status checker
4. check-status-api.php - Email status lookup
5. view-permit-public.php - Public permit view
6. manager-approvals.php - Approval dashboard section
7. api/approve-permit.php - Approval endpoint
8. api/reject-permit.php - Rejection endpoint
9. manifest.json - PWA manifest
10. service-worker.js - Push notifications
11. api/subscribe-push.php - Save push subscription
12. api/send-notification.php - Send push notification

---

## 🎯 USER FLOWS

### **PUBLIC USER CREATES PERMIT:**
```
1. Visit homepage
2. Click permit template (e.g., Hot Works)
3. Redirects to: create-permit-public.php?template=xxx
4. Fills in:
   - Name, email, phone
   - Permit details
   - [✓] Enable notifications (optional)
5. Clicks "Submit for Approval"
6. Permit created as "pending_approval"
7. Success message with reference number
8. Can check status on homepage by email
```

### **CHECK STATUS ON HOMEPAGE:**
```
1. Visit homepage
2. Enter email in status checker
3. See list of permits:
   - ⏳ Hot Works #123 - Awaiting Approval
   - ✅ Dig Permit #122 - Approved
4. Click permit to view details
```

### **MANAGER APPROVES:**
```
1. Manager logs in
2. Dashboard shows "⏳ Pending Approvals (3)"
3. Clicks to review
4. Sees permit details
5. Clicks "✅ Approve"
6. Status changes to "active"
7. System sends:
   - Email to permit holder
   - Push notification (if enabled)
```

### **PERMIT HOLDER GETS NOTIFIED:**
```
If notifications enabled:
📱 DING! "Your Hot Works Permit #123 is approved!"
[Click] → Opens permit view

If notifications disabled:
📧 Email: "Your permit is approved - view here"
OR check status on homepage
```

---

## 🗄️ DATABASE CHANGES

### **forms table - NEW COLUMNS:**
```sql
holder_email VARCHAR(255) NULL - Email of permit requester
holder_name VARCHAR(255) NULL - Name of permit requester
holder_phone VARCHAR(50) NULL - Phone of permit requester
notification_token TEXT NULL - Push notification subscription
unique_link VARCHAR(100) NULL - Unique view link for public
```

### **NEW TABLE: push_subscriptions**
```sql
CREATE TABLE push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint TEXT NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    user_email VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

## 🏠 UPDATED HOMEPAGE FEATURES

### **Status Checker Section:**
```html
<div class="status-checker">
    <h2>🔍 Check Your Permit Status</h2>
    <input type="email" placeholder="Enter your email">
    <button>Check Status</button>
    
    <!-- Results -->
    <div class="permit-list">
        <div class="permit pending">
            ⏳ Hot Works Permit #123
            Status: Awaiting Manager Approval
            Submitted: 23/10/2025
        </div>
        <div class="permit approved">
            ✅ Dig Permit #122
            Status: Approved & Active
            Valid: 23/10 - 25/10/2025
            [View] [Print] [QR Code]
        </div>
    </div>
</div>
```

---

## 📊 MANAGER DASHBOARD ADDITIONS

### **Pending Approvals Section:**
```html
<div class="pending-approvals">
    <h2>⏳ Pending Approvals (3)</h2>
    
    <div class="approval-card">
        <h3>Hot Works Permit #123</h3>
        <p>Submitted by: John Smith (john@example.com)</p>
        <p>Date: 23/10/2025 14:30</p>
        <button class="approve">✅ Approve</button>
        <button class="reject">❌ Reject</button>
        <button class="view">👁️ View Details</button>
    </div>
</div>
```

---

## 🔔 PUSH NOTIFICATION SYSTEM

### **How It Works:**

1. **User Enables Notifications:**
   - Checks box on permit form
   - Browser asks permission
   - Subscription saved to database

2. **Manager Approves:**
   - Status changes to "active"
   - System checks if user has notifications
   - Sends push via service worker

3. **User Receives:**
   - Notification appears
   - Even if browser closed
   - Click opens permit

### **Service Worker Features:**
- Cache permits for offline viewing
- Handle push notifications
- Background sync
- Auto-update

---

## 📧 EMAIL NOTIFICATIONS

### **Email Templates Needed:**

1. **Permit Approved Email:**
```
Subject: ✅ Your Permit #123 is Approved!

Hi John,

Good news! Your Hot Works Permit #123 has been approved 
by the safety manager.

Permit Details:
- Reference: #123
- Type: Hot Works Permit
- Valid: 23/10/2025 - 25/10/2025

View your permit: [Link]

Questions? Contact us at safety@company.com
```

2. **Manager New Permit Email:**
```
Subject: ⏳ New Permit Awaiting Approval

Hi Manager,

A new permit has been submitted and requires your approval:

- Permit: Hot Works #123
- Submitted by: John Smith
- Date: 23/10/2025 14:30

Review now: [Link to Dashboard]
```

---

## 🎨 PWA FEATURES

### **manifest.json:**
```json
{
  "name": "Permit System",
  "short_name": "Permits",
  "description": "Work Permit Management System",
  "start_url": "/",
  "display": "standalone",
  "background_color": "#667eea",
  "theme_color": "#667eea",
  "icons": [...]
}
```

### **Install Prompt:**
- Shows "Install App" button
- Works as standalone app
- Push notifications work
- Offline viewing

---

## 🔧 API ENDPOINTS

### **1. /api/check-status.php**
```
POST /api/check-status.php
Body: { email: "john@example.com" }
Returns: [ list of permits ]
```

### **2. /api/approve-permit.php**
```
POST /api/approve-permit.php
Body: { permit_id: "xxx", approved_by: "manager_id" }
Returns: { success: true }
Triggers: Email + Push notification
```

### **3. /api/subscribe-push.php**
```
POST /api/subscribe-push.php
Body: { subscription: {...}, email: "john@example.com" }
Returns: { success: true }
```

### **4. /api/send-notification.php**
```
POST /api/send-notification.php  
Body: { email: "john@example.com", message: "Approved!" }
Returns: { success: true }
```

---

## 📱 NOTIFICATION PAYLOAD

```javascript
{
  title: "✅ Permit Approved!",
  body: "Your Hot Works Permit #123 is now active",
  icon: "/icon-192.png",
  badge: "/badge-72.png",
  data: {
    url: "/view-permit-public.php?link=xxx",
    permit_id: "123"
  },
  actions: [
    { action: "view", title: "View Permit" },
    { action: "close", title: "Close" }
  ]
}
```

---

## ✅ TESTING CHECKLIST

### **Public User Flow:**
- [ ] Create permit without login
- [ ] Enable notifications
- [ ] See success message
- [ ] Check status on homepage
- [ ] Receive approval notification
- [ ] View approved permit
- [ ] Print QR code

### **Manager Flow:**
- [ ] Login as manager
- [ ] See pending approvals
- [ ] View permit details
- [ ] Approve permit
- [ ] Confirm email sent
- [ ] Confirm push sent

### **PWA Features:**
- [ ] Install prompt shows
- [ ] Install app works
- [ ] Notifications work
- [ ] Offline viewing works
- [ ] Updates automatically

---

## 🚀 DEPLOYMENT ORDER

1. **Run database update:**
   - Upload update-hybrid-system.php
   - Visit in browser
   - Run update
   - Delete file

2. **Upload permit creation:**
   - create-permit-public.php

3. **Update homepage:**
   - new-index.php (with status checker)

4. **Upload APIs:**
   - check-status-api.php
   - approve-permit.php
   - subscribe-push.php
   - send-notification.php

5. **Upload PWA files:**
   - manifest.json
   - service-worker.js

6. **Update dashboard:**
   - Add pending approvals section

7. **Test everything!**

---

## 💡 FUTURE ENHANCEMENTS

- SMS notifications (Twilio)
- Reject with reason
- Permit amendments
- Bulk approvals
- Advanced filtering
- Export reports
- Analytics dashboard

---

**Created:** 23/10/2025  
**Status:** Building Phase 4  
**Next:** Create remaining critical files
