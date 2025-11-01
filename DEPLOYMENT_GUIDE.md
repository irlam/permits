# Permit System Deployment Guide

This version of the guide is written for an end user who just received the project backup (from the new admin → Backup Utility). Follow the steps exactly to get the system running on a fresh server.

---

## 1. Before You Start

- **Hosting account** with:
  - PHP 8.1 or newer (with PDO, cURL, Zip, Mbstring ready out of the box)
  - MySQL 8 / MariaDB 10.4 or newer
  - HTTPS enabled for the domain
- **Tools you’ll use**
  - Host’s file manager or an SFTP client (FileZilla, Cyberduck)
  - phpMyAdmin (usually bundled with shared hosting)
  - A text editor to tweak the `.env` settings (VS Code, Sublime, or even Notepad)

> **Tip:** If the host offers “Softaculous → File Manager / phpMyAdmin” or similar, that’s enough. SSH access is nice to have but not required.

---

## 2. What You Received

After running the admin backup utility you should have a single zip file named like:

```
permits_backup_2025-11-01_143210.zip
```

Inside that archive you will find:

- All application files (PHP, templates, assets, vendor dependencies)
- `database/database.sql` — the SQL dump
- `MANIFEST.txt` — quick overview of what the backup contains
- `README.md` — restore checklist

Keep this zip safe; you’ll upload it in the next step.

---

## 3. Deploying to a New Server

1. **Create the database**
   - In your hosting control panel open MySQL Wizard / Database Manager.
   - Create a database (e.g. `permits_live`).
   - Create a database user and assign it **all privileges** to the database.
   - Note the hostname (often `localhost`), the database name, username, and password.

2. **Upload and extract the backup**
   - Log into your host’s file manager or connect via SFTP.
   - Go to the document root (often `public_html` or `www`).
   - Upload the zip file.
   - Extract it in place. This will recreate the full folder structure.
   - Ensure the following folders are writable by the web server: `backups/` and any `uploads/` directory you use. Set permissions to `755` or `775` depending on the host recommendation.

3. **Configure environment variables**
   - In the extracted files locate `.env` (if it does not exist, copy `.env.example` to `.env`).
   - Open `.env` in your editor and update these keys:

     ```env
     APP_URL="https://yourdomain.com"
     APP_ENV=production
     DB_HOST=localhost
     DB_PORT=3306
     DB_DATABASE=permits_live
     DB_USERNAME=the_user_you_created
     DB_PASSWORD=the_password_you_created

     MAIL_HOST=smtp.yourmailprovider.com
     MAIL_PORT=587
     MAIL_USERNAME=...
     MAIL_PASSWORD=...
     MAIL_ENCRYPTION=tls
     MAIL_FROM_ADDRESS=permits@yourdomain.com
     MAIL_FROM_NAME="Permit System"

     # Push notifications (optional)
     VAPID_PUBLIC_KEY=
     VAPID_PRIVATE_KEY=
     VAPID_SUBJECT=mailto:you@yourdomain.com
     ```

   - Save the file. If the host blocks direct editing, download → edit locally → upload back.

4. **Import the database**
   - Open phpMyAdmin from the hosting control panel.
   - Select the database you created.
   - Click **Import**.
   - Choose the file `database/database.sql` from the extracted backup on your local machine and upload it.
   - Wait for the success message (green banner). If the host times out, ask them to increase upload limits or import via SSH (`mysql -u user -p database < database.sql`).

5. **Run the environment check (optional but recommended)**
   - If SSH is available, run:
     ```bash
     php bin/check_env_and_db.php
     ```
   - It confirms the database connection and required folders.

6. **Final tidy-up**
   - Delete the uploaded zip file from the server once everything works.
   - Point your domain/subdomain to the new host (update DNS A record to the server IP). Allow up to an hour for propagation.

---

## 4. First Login & Post-Deployment Tasks

1. Visit `https://yourdomain.com/login.php`.
2. Use the existing admin email/password to sign in.
   - If you forgot it, open phpMyAdmin, look at the `users` table, and update the password column using the hash from another working account or trigger the password reset email if SMTP is already configured.
3. Navigate to **Admin → Settings** and verify:
   - Company details, email settings, and push notification keys.
   - AI provider settings, if you intend to use the template importer.
4. Navigate to **Admin → Backup Utility** and run a test backup on the new server so you know it works.
5. Browse the dashboard and create a test permit. Ensure emails and any web push notifications arrive.

---

## 5. Keeping the Installation Healthy

- **Regular backups**: Use the built-in admin Backup Utility weekly. Download and store copies outside the server.
- **Updates**: When you pull new code from GitHub, run the backup first, then deploy. After uploading, re-run the environment check.
- **Security**:
  - Never leave installer or backup scripts exposed outside the admin area.
  - Keep PHP version updated with your host.
  - Use strong passwords for both hosting and the admin panel.
- **Mail and push keys**: If you change hostnames, update the `.env` values and regenerate VAPID keys using `generate_vapid.php`.

---

## 6. Troubleshooting

| Problem | Likely Cause | Fix |
| --- | --- | --- |
| White page / error 500 | PHP error with display disabled | Temporarily set `APP_DEBUG=true` in `.env`, reload, then revert to `false` once resolved. Check the host error logs. |
| “Could not connect to database” | Wrong DB credentials or host | Re-open `.env`, confirm host (often `localhost`), database name, user, password. Save and try again. |
| Emails don’t send | SMTP values missing/incorrect | Verify `MAIL_*` values. Some hosts require you to use their SMTP relay or enable “less secure app” access. |
| Push notifications fail | Missing VAPID keys | Run `php generate_vapid.php`, copy the printed keys into `.env`, clear browser cache, and re-subscribe. |
| Permission denied for backups | Server disallows writing | Use file manager to set `backups/` and `uploads/` to `755` or `775`. |

---

## 7. Need to Re-Deploy Elsewhere?

If you move again:

1. Log into the admin area and use **Backup Utility → Generate Backup**.
2. Download the generated zip from `backups/`.
3. Follow this guide from the top on the new server.

That’s it—no manual database dump required thanks to the built-in exporter.

---

## 8. Support & Handover Notes

- Always store a copy of this guide alongside the backup zip.
- Share the primary admin login with trusted personnel using a secure channel. Encourage them to change their password on first login.
- Document any customisations you made (changes in `templates/`, additional cron jobs, etc.) so the next admin understands the setup.

With these steps the permit system should be up and running on any modern PHP host without developer intervention.
