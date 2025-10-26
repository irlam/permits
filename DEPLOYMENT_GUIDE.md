# Deployment & Handover Guide

This guide walks a non-technical audience through packaging, deploying, and handing over the permit system to a different hosting provider or customer. It also covers optional automation ideas and highlights common pitfalls.

---

## 1. What You Need

- **Hosting**: Shared, VPS, or managed PHP hosting that supports
  - PHP 8.0+ with PDO, OpenSSL, Mbstring, JSON, cURL, Zip
  - MySQL 8.0+ (or compatible MariaDB)
  - HTTPS (recommended)
- **Access tools**
  - A file manager or SFTP client (FileZilla, Cyberduck, etc.)
  - phpMyAdmin or MySQL Workbench for database import
  - Optional: Git / Composer if host allows SSH

> **Tip:** For a non-technical buyer, pick a host with a “1‑click” LAMP stack and phpMyAdmin pre-installed (e.g. SiteGround, Hostinger, DigitalOcean App Platform).

---

## 2. Packaging the Project for Handoff

1. **Export the database content**
   - Open phpMyAdmin on the current server.
   - Select the `k87747_permits` database (or whatever name you used).
   - Click **Export → Custom → Format: SQL**, tick "Add DROP TABLE", and download the `.sql` file.
   - Save it as `database/permits_export_YYYYMMDD.sql` inside the repository before zipping.
   - Keep the provided patch file `database/k87747_permits_patch.sql.txt` alongside it.

2. **Copy application files**
   - Make sure `vendor/` is included (Composer dependencies already installed).
   - Remove any environment-specific secrets (e.g. `.env` with production credentials). Instead, ship a `.env.example` with placeholder values.

3. **Create the delivery bundle**
   - Zip the entire project directory **after** adding the fresh database export: `permits-full-package-YYYYMMDD.zip`.
   - Deliver the zip + a copy of this guide to the buyer.

---

## 3. Deploying on a New Host (Non-Coder Edition)

1. **Create an empty database** on the new host (e.g. `permits_live`). Note the hostname, username, password.
2. **Upload files**
   - Unzip the package locally.
   - Use the host’s file manager or SFTP to upload all files into the web root (`public_html/` or similar).
   - Ensure `uploads/` remains writable (set permissions to 755 or 775 depending on host guidance).
3. **Import the database**
   - Open phpMyAdmin on the new host.
   - Select the empty database and choose **Import**.
   - Upload `permits_export_YYYYMMDD.sql`.
   - Once complete, run the patch file (if needed) by importing `database/k87747_permits_patch.sql.txt`.
4. **Configure environment**
   - Copy `.env.example` to `.env`.
   - Edit the following keys with the new values:
     ```
     APP_URL="https://your-domain.com"
     DB_HOST=localhost
     DB_PORT=3306
     DB_DATABASE=permits_live
     DB_USERNAME=your_db_user
     DB_PASSWORD=your_db_password
     MAIL_* (if using SMTP)
     PUSH_VAPID_* (if using push notifications)
     ```
   - If the host disables `APP_ENV=development`, set it to `production`.
5. **Cache & permissions**
   - If SSH is available, run `php bin/check_env_and_db.php` to verify environment.
   - Ensure `backups/` and `uploads/` folders are writable.
6. **Smoke test**
   - Visit `/login.php` on the new domain.
   - Log in using an admin account (reset password in the `users` table if needed).
   - Run through permit creation to confirm email and push settings.

---

## 4. Optional: One-Click Installer (`installer.php`)

For non-coders, you can include a simple installer script. Recommended approach:

1. Duplicate `installer-sample.php` (create this from the template below) in the project root.
2. The script should:
   - Prompt for database credentials and site URL.
   - Test the connection.
   - Run the SQL import (or guide the user to do it manually if file uploads are restricted).
   - Write the `.env` file.
   - Remove itself once finished for security.

**Sample outline (pseudo-code):**

```php
<?php
// installer-sample.php (DO NOT leave in production)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Validate submitted DB credentials
    // 2. Attempt PDO connection
    // 3. Run SQL import using exec() or mysqli multi_query
    // 4. Copy .env.example to .env with submitted values
    // 5. Provide success message and delete this file
}
// Render HTML form with inputs for DB host, name, user, password, app URL
```

> **Security note:** Do **not** leave the installer accessible after use. Rename or delete it immediately.

---

## 5. Automations & CI/CD (Optional)

If the customer wants turnkey deployments:

- **GitHub Actions**: create a workflow that, on tag push, builds the project and deploys via SFTP or rsync using stored secrets.
- **Envoyer/Forge/RunCloud**: connect the repo, configure `.env` per environment, let the platform handle Composer and migrations.
- **Custom deploy form**: build a small `deploy.php` that accepts credentials, but store secrets outside web root. For non-technical teams this can introduce security risk; prefer platform tools above.

---

## 6. Common Issues & Fixes

| Issue | Cause | Fix |
| --- | --- | --- |
| Blank page on load | Display errors disabled, PHP fatal | Enable `APP_DEBUG=true` temporarily in `.env`, check `storage/logs/` or host error logs. |
| Cannot connect to DB | Wrong credentials / host | Confirm DB hostname (often `localhost` or `127.0.0.1`), user grants, and port 3306. |
| Emails not sending | SMTP credentials missing | Fill `MAIL_HOST`, `MAIL_USERNAME`, etc. Use a transactional service (SendGrid, Mailgun). |
| Push notifications failing | VAPID keys not generated | Run `/generate_vapid.php` on the new host and update `.env`. |
| Permission denied for uploads/backups | Inherited restrictive permissions | Set folders to 755/775, or use hosting “Fix Permissions” tool. |

---

## 7. License & Source Sale Checklist

- Document what the buyer is receiving (source code + database export + instructions).
- Clarify support terms (hours, response time, ongoing maintenance).
- Include a changelog (`CHANGES.md`) so the buyer knows what’s new.
- Provide default admin credentials securely (separate email or password reset instructions).

---

## 8. Next Steps / Suggestions

- Draft a short **handover PDF** summarising system purpose, key URLs, and support contacts.
- Host a quick screen-recorded video walking through login and key features (5–10 minutes).
- If you adopt the installer, keep it in version control but remember to delete after each deployment.
- Consider Dockerising (optional): build a `docker-compose.yml` with `nginx`, `php-fpm`, `mysql`. Buyers comfortable with Docker can spin up the whole stack locally.

Feel free to adapt or expand this guide for specific customer SLAs or hosting platforms.
