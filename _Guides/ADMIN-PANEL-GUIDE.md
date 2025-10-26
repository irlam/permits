# 🔐 Admin Panel & Login System - Complete Guide

**Feature:** Admin Panel with User Authentication  
**Version:** 5.0  
**Date:** 21st October 2025

---

## ✅ **WHAT'S NEW**

Complete admin panel with user management:
- 🔐 **User Login System** - Secure authentication
- 👥 **User Management** - Create, edit, delete users
- 🎭 **Role-Based Access** - Admin, Manager, Viewer
- ⚙️ **Admin Panel** - Centralized control center
- 📧 **Protected Email Settings** - Admin-only access
- 🔒 **Session Management** - Secure sessions
- 🔑 **Password Reset** - Token-based recovery
- 📊 **Activity Tracking** - Last login tracking

---

## 🎯 **USER ROLES**

### **Admin** 👑
- Full system access
- Manage users (create, edit, delete)
- Access admin panel
- Configure email settings
- Manage templates
- System settings
- View all permits
- Create/edit/delete permits

### **Manager** 📋
- Create new permits
- Edit all permits
- View all permits
- Delete own permits
- Cannot access admin panel
- Cannot manage users

### **Viewer** 👁️
- View permits only
- Cannot create permits
- Cannot edit permits
- Cannot delete permits
- Read-only access

---

## 🚀 **QUICK SETUP (10 Minutes)**

### **Step 1: Upload Files**
Upload these new files to your server:
- `/src/Auth.php`
- `/login.php`
- `/logout.php`
- `/admin.php`
- `/admin/users.php`
- `/admin/email-settings.php`
- `/bin/setup-users.php`

### **Step 2: Run Database Setup**
```bash
php bin/setup-users.php
```

**Output:**
```
Setting up users table...
✓ Users table created
✓ Default admin account created

===========================================
DEFAULT ADMIN CREDENTIALS:
Email: admin@permits.local
Password: admin123

⚠️  IMPORTANT: Change this password immediately!
===========================================
```

### **Step 3: First Login**
1. Visit: `https://yourdomain.com/login.php`
2. Email: `admin@permits.local`
3. Password: `admin123`
4. Click "Sign In"

### **Step 4: Change Default Password**
1. Go to Admin Panel
2. User Management
3. Edit your admin account
4. Set new secure password
5. Save changes

### **Step 5: Create Additional Users**
1. Admin Panel → User Management
2. Fill in user details
3. Select appropriate role
4. Click "Create User"
5. Share credentials with user

---

## 📁 **FILE STRUCTURE**

```
permits-system/
├── login.php              # Login page
├── logout.php             # Logout handler
├── admin.php              # Admin panel dashboard
├── admin/
│   ├── users.php         # User management
│   ├── email-settings.php # Email configuration
│   ├── templates.php     # Template management (future)
│   ├── settings.php      # System settings (future)
│   ├── activity.php      # Activity log (future)
│   └── backup.php        # Backup tools (future)
├── src/
│   └── Auth.php          # Authentication class
└── bin/
    └── setup-users.php   # Database setup script
```

---

## 🔐 **AUTHENTICATION FEATURES**

### **Secure Login**
- Email + password authentication
- Password hashing (bcrypt)
- Session-based authentication
- Remember me option (30 days)
- Redirect to intended page after login

### **Session Management**
- Secure session cookies
- HttpOnly cookies
- Session timeout
- Last login tracking

### **Password Security**
- Bcrypt hashing
- Minimum 8 characters
- Reset via email token (future)
- Change password in admin panel

### **Access Control**
- Role-based permissions
- Route-level protection
- Automatic redirects
- Insufficient permission handling

---

## 👥 **USER MANAGEMENT**

### **Create New User**
**Admin Panel → User Management → Create**

**Fields:**
- **Name:** Full name
- **Email:** Must be unique
- **Password:** Minimum 8 characters
- **Role:** Admin, Manager, or Viewer

**Example:**
```
Name: John Smith
Email: john@company.com
Password: SecurePass123!
Role: Manager
```

### **Edit User**
**Click "Edit" button on user row**

**Can change:**
- ✅ Name
- ✅ Email
- ✅ Role
- ✅ Status (Active/Inactive)
- ✅ Password (optional)

**Cannot change:**
- ❌ User ID
- ❌ Creation date
- ❌ Created by

### **Delete User**
**Click "Delete" button on user row**

**Notes:**
- Cannot delete yourself
- Confirmation required
- Permanent deletion
- Use "Inactive" status instead for temporary disable

### **User Status**
- **Active:** Can login
- **Inactive:** Cannot login, preserved in database

---

## ⚙️ **ADMIN PANEL**

### **Dashboard**
**URL:** `/admin.php`  
**Access:** Admin only

**Features:**
- System statistics
- Quick links to all admin functions
- User count
- Permit count
- Template count

### **Admin Functions**

**1. User Management** `/admin/users.php`
- Create/edit/delete users
- Assign roles
- View user activity

**2. Email Settings** `/admin/email-settings.php`
- Configure SMTP
- Set notification recipients
- Test email delivery

**3. Template Management** `/admin/templates.php` (future)
- Upload new templates
- Edit existing templates
- Preview templates

**4. System Settings** `/admin/settings.php` (future)
- Site name
- Company info
- General preferences

**5. Activity Log** `/admin/activity.php` (future)
- View user actions
- Audit trail
- Export logs

**6. Backup & Restore** `/admin/backup.php` (future)
- Database backup
- Data export
- Restore from backup

---

## 🔒 **PROTECTING PAGES**

### **Require Login**
Add to any page:
```php
<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/Auth.php';

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
$auth = new Auth($db);

// Require login
$auth->requireLogin();

// Your page content here
?>
```

### **Require Specific Role**
```php
// Require admin
$auth->requireAdmin();

// Require manager or admin
$auth->requireRole('manager');

// Check role
if ($auth->hasRole('admin')) {
    // Admin-only code
}
```

### **Get Current User**
```php
$user = $auth->getCurrentUser();
echo "Welcome, " . $user['name'];
echo "Your role: " . $user['role'];
```

---

## 🎨 **USER INTERFACE**

### **Login Page**
- Clean, modern design
- Email/password fields
- Remember me checkbox
- Forgot password link
- Dark theme matching system

### **Admin Panel**
- Card-based layout
- Statistics cards
- Quick access buttons
- Responsive design
- Breadcrumb navigation

### **User Management**
- Create user form
- Users table
- Inline editing
- Delete confirmation
- Role badges
- Status badges

---

## 🔄 **WORKFLOW EXAMPLES**

### **Scenario 1: New Employee**
1. Admin creates user account
2. Assigns "Manager" role
3. Shares login credentials
4. Employee logs in
5. Changes password
6. Can now create permits

### **Scenario 2: Contractor Access**
1. Admin creates user with "Viewer" role
2. Contractor can view permits only
3. Cannot create or modify
4. Access revoked when done (status=inactive)

### **Scenario 3: Multiple Admins**
1. Create user with "Admin" role
2. Both admins can manage system
3. Each has own account
4. Activity tracked separately

---

## 🐛 **TROUBLESHOOTING**

### **Cannot Login**

**Issue:** "Invalid email or password"  
**Solutions:**
- Check caps lock
- Verify email is correct
- Check account status is "active"
- Try password reset (future feature)
- Check database users table

**Issue:** Redirect loop  
**Solutions:**
- Clear browser cookies
- Check session configuration
- Verify Auth.php is loaded

### **"Access Denied"**

**Issue:** Insufficient permissions  
**Solutions:**
- Check your user role
- Admin panel requires "admin" role
- Contact administrator

### **Session Expires Quickly**

**Solutions:**
- Check server session timeout
- Use "Remember me" option
- Check PHP session.gc_maxlifetime

### **Cannot Create Users**

**Issue:** "Email already exists"  
**Solution:** Use different email address

**Issue:** Database error  
**Solution:**
- Check users table exists
- Run setup-users.php
- Check database permissions

---

## 🔐 **SECURITY BEST PRACTICES**

### **Passwords**
✅ Change default admin password immediately  
✅ Use strong passwords (12+ characters)  
✅ Mix uppercase, lowercase, numbers, symbols  
✅ Don't reuse passwords  
✅ Don't share passwords  

### **User Accounts**
✅ Create individual accounts for each person  
✅ Use appropriate roles (least privilege)  
✅ Disable accounts when not needed  
✅ Review user list regularly  
✅ Remove old accounts  

### **Admin Access**
✅ Limit admin role to trusted staff  
✅ Use "Manager" for most users  
✅ Monitor admin activity  
✅ Don't share admin credentials  

### **Session Security**
✅ Logout when done  
✅ Don't use "Remember me" on shared computers  
✅ Close browser when finished  
✅ Use HTTPS (SSL certificate)  

---

## 📊 **DATABASE SCHEMA**

### **Users Table**
```sql
CREATE TABLE users (
  id VARCHAR(36) PRIMARY KEY,
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL,
  role VARCHAR(50) DEFAULT 'viewer',
  status VARCHAR(50) DEFAULT 'active',
  invited_by VARCHAR(36),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME,
  reset_token VARCHAR(255),
  reset_token_expires DATETIME
);
```

**Fields:**
- `id`: UUID primary key
- `email`: Unique email address
- `password_hash`: Bcrypt hashed password
- `name`: User's full name
- `role`: admin, manager, or viewer
- `status`: active or inactive
- `invited_by`: ID of user who created this account
- `created_at`: Account creation timestamp
- `last_login`: Last successful login
- `reset_token`: Password reset token
- `reset_token_expires`: Token expiration

---

## 🔮 **FUTURE ENHANCEMENTS**

Planned features:
- **Password Reset Email** - Forgot password functionality
- **Two-Factor Authentication** - Extra security layer
- **Login History** - Track all login attempts
- **Email Verification** - Verify email on registration
- **User Profiles** - Extended user information
- **API Keys** - For external integrations
- **Audit Log** - Complete activity tracking
- **Role Customization** - Create custom roles
- **Department/Team** - Organize users
- **User Import** - Bulk user creation from CSV

---

## ✅ **DEPLOYMENT CHECKLIST**

### **Before Deployment:**
- [ ] Upload all new files
- [ ] Run setup-users.php
- [ ] Test login page loads
- [ ] Can login with default credentials
- [ ] Admin panel accessible

### **After Deployment:**
- [ ] Login as admin
- [ ] Change default password
- [ ] Create your actual admin account
- [ ] Delete/disable default admin
- [ ] Create user accounts for team
- [ ] Test each role's permissions
- [ ] Verify email settings work

### **Security Check:**
- [ ] Default password changed
- [ ] HTTPS enabled (SSL certificate)
- [ ] Session cookies secure
- [ ] Admin access restricted
- [ ] All files uploaded correctly

---

## 🎯 **NEXT STEPS**

After login system:

**Option 1: Integrate with Permits** 🔗
- Track who created each permit
- Filter "My Permits"
- User-specific notifications

**Option 2: Approval Workflow** ✍️
- Submit permits for approval
- Approve/reject functionality
- Approval history

**Option 3: Activity Logging** 📊
- Complete audit trail
- User action tracking
- Export activity logs

**Option 4: Password Reset** 🔑
- Email-based reset
- Token validation
- Secure password change

---

## 📞 **SUPPORT**

**Common Questions:**
- Q: Can I have multiple admins?
- A: Yes! Create users with "admin" role

- Q: How do I reset a user's password?
- A: Edit user → Enter new password → Save

- Q: Can users change their own password?
- A: Currently via admin only. Self-service coming soon.

- Q: What happens if I forget admin password?
- A: Access database directly or create new admin via SQL

---

**Admin Panel & Login System Complete!** 🔐

You now have a professional multi-user system with role-based access control!

Last Updated: 21st October 2025, 22:00 GMT
