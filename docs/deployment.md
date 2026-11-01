# Deployment and operations

## Runtime

Use PHP 8.4+ with PDO MySQL, GD, mbstring, fileinfo, DOM/XML and OpenSSL, plus MySQL 8+. Install locked dependencies with `composer install --no-dev --optimize-autoloader`. Composer installs PHPMailer, Endroid QR and Dompdf; frontend libraries are included locally.

Keep `.env` private and point it to a dedicated database user. Configure `APP_ENV=production`, `DEMO_MODE=false`, `SESSION_SECURE=true`, the public HTTPS `APP_URL`, an appropriate `SESSION_IDLE_SECONDS`, and real SMTP settings. Demo credentials are refused outside local demo mode. Do not publish demo-enabled access to a public production instance.

The application does not require admin verification of donor/receiver/volunteer accounts. Moderation/suspension is an account-management feature, not an onboarding approval gate. Safety policies still require domain validation before real food operations.

## Database and filesystem

Back up before running `php scripts/migrate.php`. Migrations are forward-only and logged by filename; MySQL DDL implicitly commits. If a DDL migration fails partway, repair/restore before rerunning it. Do not import `database/schema.sql` into a database already managed by migrations. Never run destructive integration tests against operational data.

Set the web/PHP user write permissions only for required storage directories and session storage. Keep uploads under `storage/private`, outside the document root, with no execution permission. Protect `.env`, logs, backups, mail outbox data and generated exports. Maintain retention/deletion policies for credentials/tokens, account records, uploads and mail delivery messages; raw delivery links are sensitive even though token tables store hashes.

## Web server

Use PHP-FPM with Nginx or Apache. The document root must be `<project>/public`. Route nonexistent resources to `index.php`, disable directory listing and prevent execution of arbitrary uploaded files. In PHP use `display_errors=Off`, private error logging, `upload_max_filesize=5M` and `post_max_size=6M`. Enforce request limits, TLS and general abuse protection at the edge; proxy IP headers require a deliberate trust policy. The application uses REMOTE_ADDR directly.

Example Nginx skeleton (adapt paths and PHP-FPM socket to your server):

```nginx
server {
    listen 443 ssl;
    server_name foodrescue.example.org;
    root /srv/foodrescue/public;
    index index.php;
    client_max_body_size 6m;
    # Configure TLS certificate/key for your actual hostname.
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; }
}
```

## Background jobs

Run the following every minute using absolute paths and a restricted service account:

```cron
* * * * * /usr/bin/php /srv/foodrescue/scripts/expire_donations.php >> /srv/foodrescue/storage/logs/jobs.log 2>&1
* * * * * /usr/bin/php /srv/foodrescue/scripts/send_reminders.php >> /srv/foodrescue/storage/logs/jobs.log 2>&1
* * * * * /usr/bin/php /srv/foodrescue/scripts/send_mail.php >> /srv/foodrescue/storage/logs/jobs.log 2>&1
```

Expiry and reminder reruns have stable side effects. The email worker uses a per-database lock and stable message IDs; SMTP may be ambiguous after a remote acceptance/network failure, so treat delivery as retryable rather than claiming exactly-once email. Failed rows record attempts and a private error, with a five-attempt limit. Monitor queue depth/exhausted rows, job failures, disk usage and application errors.

`scripts/serve.php` runs the same jobs every 30 seconds for local demonstrations. No cron modification or persistent host service was installed automatically by this implementation.

## External integrations and release checks

- Real SMTP needs operator-provided credentials and an actual delivery test; local tests use private MIME files.
- OpenStreetMap tiles require internet and appropriate provider usage. Maps keep coordinates and external-map links usable when tiles are unavailable. Travel estimates use assumptions, not a paid/live routing service.
- Camera scanning needs localhost or HTTPS and camera permission; manual token entry remains available.
- Research results are simulated. Collect permitted, anonymized labelled observations before fitting or evaluating trained predictors or claiming real-world gains.
- Domain-review category-specific food-screening thresholds and handling practices before operating a real food network. This software does not certify food safety or replace regulatory obligations.
- Run the documented tests on staging, exercise backup restoration, verify SMTP and scheduled jobs, and check production permissions and TLS before public release.
