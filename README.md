# FoodRescue

FoodRescue is a Core PHP, MySQL and Vanilla JavaScript platform for surplus-food redistribution. It includes self-service organizations, donor and receiver workflows, explainable TAMRA matching, pickup coordination, QR verification, notifications, recorded impact, administration and reproducible research experiments.

**Donors, NGOs/receivers and volunteers need no admin approval.** The normal email/password login remains. The five local demo accounts are already email-verified.

For a detailed step-by-step demonstration, see [the website workflow guide](WORKFLOW.md).

## Start this existing workspace

PHP 8.4+, MySQL 8.4 and Composer have been installed. The local `.env` and database are configured. Do not replace `.env` with the example file in this existing setup.

If MySQL is already running on port 3307, skip the first command. Otherwise, start it in a separate terminal:

```sh
cd /Users/saanvi/Desktop/webtech
/opt/homebrew/opt/mysql@8.4/bin/mysqld \
  --no-defaults \
  --datadir="$PWD/.runtime/mysql-test" \
  --socket="$PWD/.runtime/mysql.sock" \
  --port=3307 --bind-address=127.0.0.1 --mysqlx=OFF \
  --pid-file="$PWD/.runtime/mysql.pid" \
  --log-error="$PWD/.runtime/mysql.log"
```

Then start the application:

```sh
cd /Users/saanvi/Desktop/webtech
/opt/homebrew/opt/php@8.4/bin/php scripts/serve.php
```

Open **http://localhost:8080/login**. The development runner also processes expiry, reminders and local email every 30 seconds. Press Ctrl+C to stop it. If another server already uses port 8080, stop that server first or run `php scripts/serve.php 8081` and set `APP_URL=http://localhost:8081` in `.env` for email links.

## Default login accounts

Every demo account uses **`Demo12345678!`** as its password:

| Role | Login email | Main tools |
| --- | --- | --- |
| Donor | `donor@foodrescue.test` | Organizations, food listings, matches, pickups, impact |
| NGO / Receiver | `receiver@foodrescue.test` | Organizations, capacity, discovery, collection, distribution |
| Volunteer | `volunteer@foodrescue.test` | Availability, assigned pickups, QR/token collection |
| Super Admin | `admin@foodrescue.test` | Accounts, categories, screening rules, algorithm settings, audit |
| Researcher | `researcher@foodrescue.test` | Datasets, simulations, baseline comparisons, research exports |

Credentials are kept in this README for your reference; the landing and login pages do not display them. Enter the email and password on the login form. Your account determines the workspace automatically; there is no password-free role-entry endpoint. Registration offers Donor, Receiver and Volunteer. Admin and Researcher accounts are provisioned separately and are not part of the normal donor-to-receiver flow.

Demo accounts require `APP_ENV=local` and `DEMO_MODE=true`. To restore their credentials, run `php scripts/seed_demo.php`. It resets only the five reserved demo accounts, preserves their IDs and recorded activity, and makes the demo volunteer available. It does not fabricate completed pickups or impact. Normal accounts remain untouched.

## Start again with a clean demo

After a clean-demo reset, only the five verified default demo accounts remain. Organizations, donations, pickups, experiments, notifications and stored uploads have been removed. Default categories, screening rules and matching settings are restored. Sign in again to start a new demonstration.

To repeat this reset later, run the following **only when you intend to delete the entered local data**:

```sh
php scripts/reset_demo.php --confirm-reset
```

The reset requires local demo mode, keeps the schema/migration history, and restores the five demo accounts. It deletes other accounts and operational/research records, as well as private uploads, local mail, logs and generated exports. Existing browser sessions become invalid. Website credentials and database configuration remain separate.

## Demonstrate the complete food workflow

Use separate browser profiles/private windows for the donor and receiver, or sign out between roles.

1. **Donor → Organizations:** create an organization with its address and coordinates, or link an existing donor organization. No approval is needed. The creator can edit its details and upload optional private documents; linked members manage their own listings.
2. **Receiver → Organizations:** create or link a receiving organization. Then open **Capacity & availability** and save kilograms of concurrent capacity, accepted categories, transport, base coordinates, radius and a collection window.
3. **Donor → My donations → Share surplus food:** enter quantity, category, preparation time, use-by, pickup deadline, ingredients, allergens, packaging and handling declarations. Times use your browser's timezone. All quantities are converted to kilograms; portions/items require a weight per unit.
4. **Donation details:** inspect screening results, add photographs and review explainable receiver scores. You can notify an eligible receiver of a recommendation. Missing safety information holds a listing; mandatory failures reject it. Correct an unclaimed listing through **Edit listing**.
5. **Receiver → Discover food:** inspect matching donations, accept one, or decline a recommendation. Acceptance atomically reserves the whole listing and receiver capacity. Two receivers cannot claim the same donation.
6. **Donor or Receiver → Pickups:** choose a pickup time and optionally assign an available volunteer. Save the generated one-use token/QR. Rescheduling replaces the token. Camera scanning works on localhost or HTTPS; manual token entry is always available.
7. **Receiver or assigned Volunteer:** enter/scan the token and record actual collected kilograms. Invalid, expired and reused tokens are rejected.
8. **Donor and Receiver:** each confirms collection. The receiver records actual distributed kilograms; distribution can be updated later. Both confirmations complete the pickup and release reserved capacity. Participants can leave feedback.
9. **Impact:** view offered, accepted, collected and distributed quantities separately; inspect trends, geography and prediction evaluation; export CSV/JSON/PDF. Estimated meals use an explicit configurable kg-per-meal assumption.

Failed/cancelled pickups release capacity. Donors can re-screen and relist eligible food before its deadline. Expiry and reminders run through the background jobs, independently of browser tabs. In-app notifications link to relevant activity.

## Admin and researcher workflows

**Admin → Platform management** can suspend/restore accounts, add/deactivate food categories, version category-specific screening rules, configure matching weights and meal estimates, and inspect audit/email-queue status. There is no donor/NGO/volunteer approval queue.

**Researcher → Experiments** can generate a seeded synthetic dataset or import anonymous historical/permitted pilot input scenarios, select an immutable TAMRA configuration and run nearest-receiver, earliest-deadline-first, highest-capacity and TAMRA comparisons. Runs store input hashes, configuration, seed, decisions, metrics and measured execution time. Repeat with different seeds for a mean/standard-deviation summary. Export decisions as CSV, charts as PNG, reports as PDF, or the full reproducibility bundle as JSON.

Experiment **outcomes are simulated**, including runs using imported input scenarios. They do not establish observed pilot performance, novelty or superiority. The implemented predictor is the planned transparent heuristic baseline; a trained model requires sufficient labelled observations. See [research methodology](docs/research.md).

## View the database and updated records

The existing local connection is:

| Setting | Value |
| --- | --- |
| Host | `127.0.0.1` |
| Port | `3307` |
| Database | `foodrescue` |
| Username | `foodrescue` |
| Password | `DB_PASSWORD` in your private `.env` |

These are database credentials, separate from website demo credentials. You can enter them in a MySQL desktop client.

The CLI inspector reads `.env`, so no password entry is needed:

```sh
/opt/homebrew/opt/php@8.4/bin/php scripts/db.php
/opt/homebrew/opt/php@8.4/bin/php scripts/db.php organizations
/opt/homebrew/opt/php@8.4/bin/php scripts/db.php organization_members
/opt/homebrew/opt/php@8.4/bin/php scripts/db.php donations
/opt/homebrew/opt/php@8.4/bin/php scripts/db.php pickup_schedules
/opt/homebrew/opt/php@8.4/bin/php scripts/db.php impact_records
/opt/homebrew/opt/php@8.4/bin/php scripts/db.php experiment_runs
```

The first command lists tables/counts; a table argument prints up to 50 rows. Password hashes, token hashes and mail bodies are hidden. Repeat a command after a website action to see its stored changes.

For unrestricted SQL access:

```sh
/opt/homebrew/opt/mysql@8.4/bin/mysql -h 127.0.0.1 -P 3307 -u foodrescue -p foodrescue
```

Enter `DB_PASSWORD` from `.env` at the prompt:

```sql
SHOW TABLES;
SELECT id, name, email, status FROM users;
SELECT id, name, quantity_kg, status, deadline FROM donations ORDER BY id DESC;
SELECT id, donation_id, status, collected_kg FROM pickup_schedules ORDER BY id DESC;
SELECT * FROM impact_records;
```

The MySQL data directory is `.runtime/mysql-test/`; despite its directory name, the application database is `foodrescue`. Automated tests use the separate `foodrescue_test` database. Do not edit MySQL's internal data files.

## Email and scheduled jobs

Local mode uses `MAIL_TRANSPORT=log`. Messages are queued in `mail_outbox`, and the runner writes delivered MIME messages to private `storage/private/mail-<id>.eml` files. Verification/reset links can be read there or in the database outbox. These links are secrets. Email confirmation does not block ordinary donor/receiver/volunteer workflows; the demo accounts are pre-verified.

SMTP delivery is implemented with PHPMailer. To send real mail, configure `MAIL_TRANSPORT=smtp`, `MAIL_FROM`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD` and `SMTP_ENCRYPTION` in `.env`. No external SMTP credentials are bundled, and real external delivery has not been verified in this workspace. Failed messages retain retry counts/errors and stop after five attempts; an operator can reset an exhausted row's attempts after fixing configuration. SMTP cannot guarantee exactly-once delivery after an ambiguous remote failure; stable message IDs help identify retries.

Jobs can also run separately:

```sh
php scripts/expire_donations.php
php scripts/send_reminders.php
php scripts/send_mail.php
```

On production hosting, schedule these CLI scripts every minute using cron (with absolute PHP/script paths). Expiry/reminders are idempotent, and the mail worker uses a database lock. `scripts/serve.php` is a local development convenience, not a production web server.

## New installation

Requires PHP **8.4+**, PDO MySQL, GD, mbstring, MySQL 8+, and Composer. JavaScript libraries are included locally; Node is needed only for optional browser tests, not for running the website.

1. Copy `.env.example` to `.env` and configure database credentials and `APP_URL`.
2. Create the application and disposable test databases plus a dedicated MySQL user:

   ```sql
   CREATE DATABASE foodrescue CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE foodrescue_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'foodrescue'@'127.0.0.1' IDENTIFIED BY 'your-unique-database-password';
   GRANT ALL PRIVILEGES ON foodrescue.* TO 'foodrescue'@'127.0.0.1';
   GRANT ALL PRIVILEGES ON foodrescue_test.* TO 'foodrescue'@'127.0.0.1';
   ```

3. Run `composer install`, `php scripts/migrate.php` and `php scripts/seed_demo.php`.
4. Run `php scripts/serve.php` and open the printed URL.

For regular privileged accounts, use `php scripts/create_user.php "Name" user@example.test researcher` (or `super_admin`). The password is read from stdin. All stored timestamps use UTC.

## Tests and documentation

```sh
php tests/run.php
php tests/run.php --integration
php tests/workflows.php
PHP_BINARY=php python3 tests/http_smoke.py
composer validate --strict
node --check public/assets/js/app.js
```

Run integration tests before workflow/HTTP tests. They rebuild **only** the explicitly configured disposable database ending in `_test`. Never point tests at operational records. Browser tests use an existing Chrome and optional `playwright-core`; see [testing](docs/testing.md).

- [Architecture and full ER diagram](docs/architecture.md)
- [JSON API and access rules](docs/api.md)
- [Algorithm, datasets and research methodology](docs/research.md)
- [Implementation and validation report](docs/implementation-status.md)
- [Deployment](docs/deployment.md)
- [Original implementation plan, with user amendments](FoodRescue_Implementation_Plan.md)

The application is a screening/coordination tool, not food-safety certification. Routing uses documented straight-line/detour assumptions; it does not include live traffic or route optimization. TAMRA is a greedy experimental ranking, not a globally optimal allocator. Production use still requires deployment configuration, externally validated category policies and appropriate operational handling practices.
