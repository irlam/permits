# Permits System

A mobile-first permit-to-work application for creating, approving, displaying and closing safety permits. Administrators manage users, permit templates, branding, email delivery and QR displays from a desktop-friendly control panel.

The plain-language customer documentation is available in [`customer-guide/index.html`](customer-guide/index.html). It covers first-time setup, everyday permit use, administration, installation and troubleshooting.

## Main features

- Public, mobile-friendly permit applications
- Draft, approval, rejection, active, expired and closed workflows
- Manager approval queue and audit history
- Public verification pages and per-permit QR codes
- Printable permits and QR notice-board views
- User and role management
- Company name, logo and colour branding
- Configurable SMTP email and approval recipients
- Permit template editor and import tools
- Scheduled expiry, reminders and queued email delivery
- Installable PWA shell that does not cache sensitive permit or admin pages

## Requirements

- PHP 8.1 or newer
- MySQL 5.7+ or MariaDB 10.2+
- Composer 2
- HTTPS in production
- PHP extensions listed in `composer.json`

## New installation

1. Upload the application to the website document root.
2. Run `composer install --no-dev --classmap-authoritative`.
3. Import `database/database.sql` into an empty database.
4. Copy `.env.example` to `.env`, enter the production URL, database credentials and fresh secrets, and prepare the external writable `BACKUP_PATH` folder if you set one.
5. Run `php bin/migrate.php` to verify and complete the schema.
6. Load the supplied permit types with `php bin/import-form-presets.php`.
7. Create the first administrator with `php bin/create-admin.php --email=owner@example.com --name="Site Administrator"`.
8. Copy the generated one-time password, sign in over HTTPS, change it immediately from **My Account**, then configure branding and email. Open **Admin → Backups** once to create and verify the private backup folder.
9. Complete a labelled test permit before opening the service to users.

Push notifications are optional. Generate fresh keys with `php bin/generate-vapid.php`, copy them into `.env`, and set `VAPID_SUBJECT` to an email address or HTTPS URL controlled by your organisation.

## Existing installation

1. Back up the database and uploaded files.
2. Deploy the new code and run `composer install --no-dev --classmap-authoritative`.
3. Import every SQL file in `database/imports` that has not previously been applied, in filename order.
4. Run `php bin/migrate.php`.
5. Clear the service-worker/browser cache and complete the lifecycle test in the customer guide.

The migrations are designed to be safely re-run on supported MySQL and MariaDB versions. They add required tables and columns, normalise legacy role/identifier data, and replace older non-unique identifier indexes where safe.

For this release, the upgrade imports are:

```text
database/imports/2026-07-activity-admin-compatibility.sql
database/imports/2026-07-email-queue.sql
database/imports/2026-07-production-hardening.sql
database/imports/2026-07-production-identifiers.sql
database/imports/2026-07-public-rate-limits.sql
database/imports/2026-07-security-login-rate-limits.sql
database/imports/2026-07-worker-locks.sql
```

If command-line access is available, `php bin/migrate.php` safely performs the same schema checks and reports anything it could not apply. The identifier import deliberately stops short of creating unique indexes when duplicate references or private links exist; resolve any reported duplicates before launch.

## Private backups

The admin backup screen stores archives outside the website directory. By default it creates a sibling folder named `permits-private-backups`. On managed hosting, set `BACKUP_PATH` in `.env` to an absolute writable path outside the document root. Download each backup into encrypted off-site storage and remove the server copy after verifying it.

## Scheduled tasks

Use PHP CLI tasks rather than public web endpoints. Adjust `/path/to/permits` for the server.

```cron
*/2 * * * * php /path/to/permits/bin/process-email-queue.php
*/5 * * * * php /path/to/permits/bin/send-notifications.php
*/15 * * * * php /path/to/permits/bin/reminders.php 60
* * * * * php /path/to/permits/bin/auto-status-update.php
```

Email delivery is disabled by default. Configure and enable it in **Admin → Email Settings** before scheduling live delivery. Disabling email leaves queued notices waiting. The `log` delivery method writes complete test messages to the private `data/mail` folder and must not be used as a live substitute for SMTP.

## Validation

Run before deployment:

```text
composer validate --strict
composer audit
composer check
php bin/health-check.php
```

CI runs syntax checks, PHPUnit tests and the Composer security audit on every push and pull request.

## Production safety

- Never commit `.env`, database exports, uploaded files, backups or generated API keys.
- Rotate credentials that have ever been copied into tickets, chat, email or source control.
- Keep `APP_DEBUG=false`, `SESSION_COOKIE_SECURE=true` and the correct HTTPS `APP_URL` in production.
- Rotate the first administrator password immediately and never retain a supplied or sample password on a live site.
- Restrict database and hosting accounts to the minimum permissions required.
- Store backups away from the web root and test restoration regularly.
- For Nginx or another non-Apache server, explicitly deny web access to `bin`, `config`, `data`, `database`, `src`, `storage`, `templates`, `tests` and `vendor`.
- Review inactive accounts and the activity log routinely.

## Licence

Proprietary. All rights reserved.
