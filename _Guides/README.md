# 🛡️ Permits & Safety Management System

A comprehensive, modern web-based permit management system for construction sites, industrial facilities, and safety-critical operations. Built with PHP, featuring a Progressive Web App (PWA) interface with offline capabilities.

![Version](https://img.shields.io/badge/version-4.0-blue)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4)
![License](https://img.shields.io/badge/license-MIT-green)

---

## 🎯 **Overview**

This system provides a complete solution for managing safety permits including Hot Works, Permit to Dig, Work at Height, and custom permit types. It features a modern dark-themed UI, comprehensive search capabilities, file attachments, digital signatures, QR code generation, and detailed audit trails.

---

## ✨ **Key Features**

### **Core Functionality**
- ✅ **Multiple Permit Templates** - Hot Works, Permit to Dig, Work at Height (extensible)
- ✅ **Complete CRUD Operations** - Create, View, Edit, Delete permits
- ✅ **Digital Signatures** - 4 signature canvases per permit
- ✅ **File Attachments** - Upload photos, PDFs, documents to any permit
- ✅ **Status Management** - Draft → Pending → Issued → Active → Expired → Closed
- ✅ **Search & Filter** - By keyword, status, template, date range
- ✅ **QR Code Generation** - Mobile access via QR scanning
- ✅ **Duplicate Permits** - Copy existing permits to save time

### **Dashboard & Analytics**
- 📊 **Statistics Dashboard** - Real-time overview of all permits
- 📈 **Interactive Charts** - Status breakdown, trends, templates, locations
- ⚠️ **Expiring Soon Alerts** - Proactive permit management
- 📌 **Recent Activity Feed** - Track all changes

### **Automation & Workflow**
- ⏰ **Auto-Status Updates** - Automatic permit expiry via cron
- 📱 **Push Notifications** - Browser notifications for expiring permits
- 🔔 **Event Logging** - Complete audit trail of all actions
- 📋 **Event History** - Track who did what and when

### **Advanced Features**
- 🔍 **Advanced Search** - Multiple filter combinations
- 📱 **PWA Support** - Install as mobile app, works offline
- 🎨 **Modern Dark UI** - Professional, easy on the eyes
- 📄 **Print to PDF** - Export permits for offline use
- 💾 **Smart Caching** - Automatic updates without manual cache clearing

---

## 🚀 **Quick Start**

### **Requirements**
- PHP 8.0 or higher
- MySQL 5.7+ or SQLite 3
- Apache/Nginx with mod_rewrite
- Composer for dependencies

### **Installation**

1. **Clone the repository**
```bash
git clone https://github.com/yourusername/permits-system.git
cd permits-system
```

2. **Install dependencies**
```bash
composer install
```

3. **Configure environment**
```bash
cp .env.example .env
# Edit .env with your database credentials
```

4. **Set up database**
```bash
php bin/setup-db.php
```

5. **Configure web server**
Point your web root to the project directory and ensure `.htaccess` is enabled.

6. **Access the system**
Navigate to `https://yourdomain.com` in your browser.

---

## 📁 **Project Structure**

```
permits-system/
├── assets/              # CSS, JavaScript, images
│   ├── app.css         # Main stylesheet
│   └── app.js          # Service worker registration
├── bin/                # Command-line scripts
│   ├── setup-db.php   # Database initialization
│   ├── reminders.php  # Push notification sender
│   └── auto-status-update.php  # Auto-expire permits
├── src/                # PHP source code
│   ├── bootstrap.php  # Application bootstrap
│   ├── routes.php     # Route definitions
│   └── Db.php         # Database connection
├── templates/          # PHP view templates
│   ├── layout.php     # Homepage with search
│   ├── dashboard.php  # Statistics dashboard
│   └── forms/         # Form-related templates
│       ├── renderer.php  # New form creation
│       ├── view.php     # Form detail view
│       └── edit.php     # Form editing
├── uploads/            # User-uploaded attachments
├── vendor/             # Composer dependencies
├── .env               # Environment configuration
├── .htaccess          # Apache rewrite rules
├── composer.json      # PHP dependencies
├── index.php          # Application entry point
├── manifest.webmanifest  # PWA manifest
└── sw.js              # Service worker
```

---

## 📖 **Documentation**

Comprehensive documentation is available in the `/docs` directory.

---

## 🔧 **Configuration**

### **Environment Variables**

```env
# Database
DB_DSN=mysql:host=localhost;dbname=permits;charset=utf8mb4
DB_USER=your_username
DB_PASS=your_password

# Application
APP_URL=https://yourdomain.com
ADMIN_KEY=your-secret-admin-key

# Push Notifications (Optional)
VAPID_PUBLIC_KEY=your-public-key
VAPID_PRIVATE_KEY=your-private-key
VAPID_SUBJECT=mailto:your@email.com
```

### **Cron Jobs**

Set up these scheduled tasks:

**Auto-Expire Permits (Daily at midnight):**
```bash
0 0 * * * /usr/bin/php /path/to/permits/bin/auto-status-update.php
```

**Push Notifications (Every 5 minutes):**
```bash
*/5 * * * * /usr/bin/php /path/to/permits/bin/reminders.php
```

---

## 🔌 **API Endpoints**

### **Forms**
```
GET  /                          # List all forms
GET  /dashboard                 # Statistics dashboard
GET  /form/{id}                 # View form
GET  /form/{id}/edit            # Edit form
GET  /form/{id}/duplicate       # Duplicate form
GET  /new/{templateId}          # Create new form
POST /api/forms                 # Save form
PUT  /api/forms/{id}            # Update form
DELETE /api/forms/{id}          # Delete form
```

### **Attachments**
```
POST /api/forms/{id}/attachments    # Upload file
DELETE /api/attachments/{id}        # Delete file
```

---

## 🤝 **Contributing**

Contributions are welcome! Please follow these steps:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## 📝 **Changelog**

### **Version 4.0** (21 Oct 2025)
- ✨ Added Statistics Dashboard with charts
- ✨ Added QR code generation for permits
- ✨ Added duplicate permit functionality
- ✨ Added auto-status update script
- 🐛 Fixed caching issues with service worker
- 📚 Comprehensive documentation

---

## 📄 **License**

This project is licensed under the MIT License.

---

**Built with ❤️ for Safety Professionals**

*Last Updated: 21st October 2025*
