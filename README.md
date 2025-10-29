# Permits System (PHP + Composer + PWA) — Advanced Features

Modern permits management system with dashboard, notifications, export, authentication, and mobile support.

## Quick Start

1) `composer install`
2) `cp .env.example .env` (set APP_URL; switch DB_DSN to MySQL if needed)
3) `php bin/migrate.php` (if it exists, or skip if not)
4) `php bin/migrate-features.php` (create new feature tables)
5) Local dev: `composer run serve` then open http://localhost:8080

## Features

### 📊 Dashboard with Statistics
- Real-time permit metrics and statistics
- Active permits count
- Permits expiring in 7/30 days
- Status breakdown (draft, pending, issued, active, expired, closed)
- Recent activity timeline
- Quick action buttons

**Access:** `/dashboard`

### 📧 Email Notification System
- Automated email notifications for permit events
- Permit approval/rejection notifications
- Expiry reminder emails
- New permit creation alerts
- Template-based HTML emails
- Configurable SMTP / logging drivers
- Queue processor with retry-safe status tracking

**⚠️ IMPORTANT - Setup Required:**
Before emails will be sent, you MUST configure approval notification recipients:
```bash
# Add at least one recipient who will receive approval notifications
php bin/setup-approval-recipients.php add "Manager Name" manager@company.com

# Or use the web interface at /admin-approval-notifications.php
```

See [EMAIL_SETUP.md](EMAIL_SETUP.md) for complete setup instructions and troubleshooting.

**Features:**
- Email queue for reliable delivery
- Queue worker: `*/2 * * * * php /path/permits/bin/process-email-queue.php`
- Customizable email templates in `templates/emails/`
- Cron job for expiry reminders: `*/5 * * * * php /path/permits/bin/send-notifications.php`

**Templates:**
- `permit-awaiting-approval.php` - Pending approval notifications
- `permit-approved.php` - Approval notifications
- `permit-rejected.php` - Rejection notifications
- `permit-expiring.php` - Expiry reminders
- `permit-created.php` - Creation notifications

### 📥 Advanced Search & Export
- CSV export with filter support
- Export current search results
- Custom column selection
- Date range filtering
- Status and template filtering

**Access:** Click "Export CSV" button on homepage or visit `/api/export/csv`

### 🔐 User Management & Authentication
- Secure login/logout functionality
- Password hashing with bcrypt
- Session management with cookies
- Role-based access control (Admin, Manager, Viewer)
- Remember me functionality (30 days)

**Roles:**
- **Admin:** Full system access
- **Manager:** Manage permits and users
- **Viewer:** Read-only access

**Routes:**
- `/login` - Login page
- `/logout` - Logout
- `/settings` - User settings and configuration

### 📱 Enhanced Mobile & PWA Experience
- Touch-friendly buttons (minimum 44x44px)
- Swipe gesture support
- Pull-to-refresh functionality
- Mobile-optimized forms and layouts
- Responsive grid system
- Touch feedback animations
- Offline-first caching for the core shell
- Installable PWA with generated maskable icons

**Mobile Features:**
- Bottom navigation on small screens
- Hamburger menu support
- Gesture detection (swipe left/right)
- Viewport height fix for mobile browsers
- Prevent double-tap zoom on buttons

### 🎨 Theme Customization
- Dark theme (default)
- Light theme option
- Theme toggle button
- Customizable color schemes
- CSS custom properties for easy theming

**Access:** `/settings` for theme configuration

**Themes Available:**
- Dark (default) - Modern dark blue-black theme
- Light - Clean white theme with subtle grays

## Database Setup

### New Tables

Run the migration to create new feature tables:

```bash
php bin/migrate-features.php
```

This creates:
- `email_queue` - Email notification queue
- `users` - User accounts and authentication
- `sessions` - User session management
- `settings` - Application configuration

### Default Settings

After migration, configure settings in the `settings` table:
- `theme` - UI theme (dark/light)
- `email_enabled` - Enable/disable email notifications
- `smtp_host` - SMTP server hostname
- `smtp_port` - SMTP port (default: 587)
- `smtp_user` - SMTP username
- `smtp_from` - From email address

## Cron Jobs

### Email Queue Processor
Flush queued emails via the SMTP/log transport:
```bash
*/2 * * * * php /path/permits/bin/process-email-queue.php
```

### Email Notifications
Send proactive expiry reminder emails:
```bash
*/5 * * * * php /path/permits/bin/send-notifications.php
```

### Existing Reminders
Push notifications for expiring permits:
```bash
*/5 * * * * php /path/permits/bin/reminders.php
```

### Auto Status Updates
Automatically update permit statuses:
```bash
0 2 * * * php /path/permits/bin/auto-status-update.php
```

## Configuration

### Environment Variables (.env)

```
APP_ENV=dev
APP_URL=https://your-domain.com
DB_DSN=mysql:host=localhost;dbname=permits;charset=utf8mb4
DB_USER=your_db_user
DB_PASS=your_db_password
ADMIN_KEY=your-random-admin-key
```

### Email Configuration

Configure SMTP settings in the database `settings` table or via `/settings`:

```sql
UPDATE settings SET value='true' WHERE `key`='email_enabled';
UPDATE settings SET value='smtp.example.com' WHERE `key`='smtp_host';
UPDATE settings SET value='587' WHERE `key`='smtp_port';
UPDATE settings SET value='user@example.com' WHERE `key`='smtp_user';
UPDATE settings SET value='noreply@example.com' WHERE `key`='smtp_from';
```

## Production Deployment

### Apache
- Set this folder as DocumentRoot
- Keep provided `.htaccess` for routing
- Ensure mod_rewrite is enabled

### Nginx
Route all requests to `index.php` if no file exists:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### Security
- Change `ADMIN_KEY` in `.env` to a long random string
- Use HTTPS in production
- Configure firewall rules
- Set proper file permissions (755 for directories, 644 for files)
- Keep database credentials secure

## File Structure

```
permits/
├── bin/
│   ├── auto-status-update.php    # Auto status updates (cron)
│   ├── reminders.php              # Push notification reminders (cron)
│   ├── send-notifications.php    # Email sender (cron)
│   ├── process-email-queue.php   # Queue worker (cron)
│   └── migrate-features.php      # Database migration [NEW]
├── src/
│   ├── bootstrap.php              # App initialization
│   ├── Db.php                     # Database connection
│   ├── routes.php                 # Route definitions
│   ├── Email.php                  # Email manager [NEW]
│   ├── Export.php                 # Data export [NEW]
│   └── Auth.php                   # Authentication [NEW]
├── templates/
│   ├── layout.php                 # Homepage template
│   ├── dashboard.php              # Dashboard template [NEW]
│   ├── emails/                    # Email templates [NEW]
│   │   ├── permit-approved.php
│   │   ├── permit-rejected.php
│   │   ├── permit-expiring.php
│   │   └── permit-created.php
│   └── forms/                     # Form templates
├── assets/
│   ├── app.css                    # Main stylesheet (enhanced mobile)
│   ├── app.js                     # JavaScript (enhanced mobile)
│   └── themes.css                 # Theme definitions
├── dashboard.php                  # Dashboard page
├── login.php                      # Login page
├── settings.php                   # Settings page
├── index.php                      # Application entry point
└── README.md                      # This file
```

## API Endpoints

### Core Routes
- `GET /` - Homepage with search
- `GET /dashboard` - Dashboard [NEW]
- `GET /new/{templateId}` - Create new form
- `GET /form/{formId}` - View form
- `GET /form/{formId}/edit` - Edit form
- `GET /form/{formId}/duplicate` - Duplicate form

### API Routes
- `POST /api/forms` - Create form
- `PUT /api/forms/{formId}` - Update form
- `DELETE /api/forms/{formId}` - Delete form
- `GET /api/templates` - List templates
- `POST /api/forms/{formId}/attachments` - Upload attachment
- `DELETE /api/attachments/{id}` - Delete attachment
- `POST /api/push/subscribe` - Push subscription
- `GET /api/export/csv` - Export to CSV [NEW]

### Authentication Routes [NEW]
- `GET /login` - Login page
- `POST /login` - Process login
- `GET /logout` - Logout
- `GET /settings` - Settings page
- `POST /settings` - Update settings

## Development

### Adding New Email Templates

1. Create template file in `templates/emails/`:
```php
<?php
// templates/emails/my-template.php
?>
<!DOCTYPE html>
<html>
<head>...</head>
<body>
  <!-- Your email HTML -->
</body>
</html>
```

2. Use in code:
```php
$email = new \Permits\Email($db, $root);
$email->queue($to, $subject, $email->renderTemplate('my-template', $data));
```

### Creating Users

```php
use Permits\Auth;

$auth = new Auth($db);
$userId = $auth->createUser('username', 'email@example.com', 'password', 'admin');
```

Or via database:
```sql
INSERT INTO users (id, username, email, password_hash, role)
VALUES (UUID(), 'admin', 'admin@example.com', '$2y$12$...', 'admin');
```

### Automated Tests

```bash
composer run test
```

Runs the PHPUnit suite located in `tests/` covering the mail transport and queue processor.

## Troubleshooting

## Troubleshooting

### Email Not Sending
**Problem:** Permits are submitted for approval but no emails are sent.

**Solution:**
1. Check if approval recipients are configured:
   ```bash
   php bin/setup-approval-recipients.php list
   ```
2. If no recipients, add at least one:
   ```bash
   php bin/setup-approval-recipients.php add "Manager" manager@example.com
   ```
3. Verify SMTP settings in `.env` file
4. Check email queue: `SELECT * FROM email_queue WHERE status='pending'`
5. Review logs for errors

See [EMAIL_SETUP.md](EMAIL_SETUP.md) for detailed troubleshooting.

### Authentication Issues
1. Ensure users table exists: `php bin/migrate-features.php`
2. Clear browser cookies
3. Check session timeout in Auth.php
4. Verify database connection

### Mobile Features Not Working
1. Clear browser cache
2. Check browser console for errors
3. Ensure JavaScript is enabled
4. Test on actual mobile device

## Browser Support

- Chrome/Edge 90+
- Firefox 88+
- Safari 14+
- Mobile browsers (iOS Safari, Chrome Mobile)
- Progressive Web App (PWA) capable

## License

Proprietary - All rights reserved

## Support

For issues or questions, contact the development team.
