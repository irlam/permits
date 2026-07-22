# Production sign-off checklist

## Completed in code

- Internal permit mutation routes require an authenticated, active user and an appropriate role.
- Approval, rejection and closure enforce valid status transitions and use atomic updates.
- Uploads are MIME-verified, size-limited, randomly named, and protected from script execution.
- Public work-start requests require the unguessable public link.
- Push subscriptions are tied to the authenticated session user.
- Public errors are generic; detailed errors are written only to server logs.
- Dependencies are locked, audited and tested in CI.
- Local secrets, database copies, backups and installed dependencies are excluded from Git.

## Required deployment actions

1. Rotate every database, mail, VAPID and API credential that has ever appeared in Git history.
2. Purge `.env` and historical SQL/database files from the remote Git history using an approved history-rewrite procedure.
3. Copy `.env.example` to `.env` on the server and insert only newly rotated credentials.
4. For an existing MySQL database, import `database/imports/2026-07-production-hardening.sql`.
5. Run `composer install --no-dev --classmap-authoritative` and `php bin/migrate.php`.
6. Ensure the web server honours the root and `uploads/.htaccess` files (or reproduce those restrictions in Nginx/IIS).
7. Configure HTTPS and confirm `SESSION_COOKIE_SECURE=true`.
8. Configure and monitor the documented queue, notification, expiry and status-update scheduled jobs.
9. Add CSRF enforcement to all state-changing HTML and JSON requests before final public launch.
10. Complete browser acceptance testing against an isolated copy of the production database.

## Verification commands

```text
composer validate --strict
composer audit
composer check
```

The application is not finally signed off until all required deployment actions, especially credential rotation, Git-history purge, CSRF enforcement and browser acceptance testing, are complete.
