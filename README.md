# Permits Starter (PHP + Composer + PWA) — Root Webroot
No public/ folder. Point your domain to this directory.

## Quick start
1) `composer install`
2) `cp .env.example .env` (set APP_URL; switch DB_DSN to MySQL if needed)
3) `php bin/migrate.php`
4) Local dev: `composer run serve` then open http://localhost:8080

## Production
- Apache: this folder as DocumentRoot, keep provided `.htaccess` for routing.
- Nginx: route all requests to `index.php` if no file exists.
- Set cron for reminders: `*/5 * * * * php /path/permits/bin/reminders.php`
