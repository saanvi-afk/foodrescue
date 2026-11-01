> Historical Phase 1 report. For the completed operational implementation, see [implementation-status.md](implementation-status.md) and the current README.

# Phase 1 implementation report

Status: implemented and tested locally. Phase 2 has not started. The plan includes subsequent user-requested access-policy changes documented below.

## Functionality

- Core PHP MVC-inspired structure with explicit routes, controllers, services, repository, middleware, validators and escaped views.
- Environment configuration, PDO native prepared statements, UTC database sessions and versioned forward migrations.
- Registration, login/logout, password hashing, CSRF, session rotation, database-backed authentication rate limits and account activity auditing.
- Server-controlled roles, privileged CLI account provisioning, suspension enforcement and five separate authorized account workspaces.
- Email-verification and password-reset flows with hashed, expiring single-use tokens, serialized consumption, private mail outbox and session revocation after reset.
- Responsive forest-green landing page, account forms, per-role workspaces, account details, organization empty states and account activity. Working Fetch forms with pending, error and success feedback.
- Architecture, ER diagram, API documentation, installation and deployment guidance.

## Database changes

`001_foundation.sql` creates roles, users, user_profiles, organizations, organization_documents, account_tokens, auth_rate_limits, audit_logs and mail_outbox. The migration runner adds its tracking table. Role seeds create all five roles. `database/schema.sql` is a consolidated snapshot; use the migration runner for tracked installations, not both methods on the same database.

No operational donation, matching, collection or research records are fabricated. Future operational tables will be introduced alongside their business rules in the relevant phase.

## Verification

- PHP 8.4.27 / MySQL 8.4.13 exercised locally.
- 20 PHP unit/integration tests passed on the disposable `foodrescue_test` database.
- 39 real HTTP checks passed, including all five role logins, role-specific pages, cross-role denial, logout, actual registration, CSRF, invalid JSON, field injection, XSS escaping and private-file access rejection.
- All 25 application/CLI/test PHP files passed syntax validation.
- JavaScript passed `node --check`; Composer manifest passed `composer validate --strict`.
- MySQL migrations applied successfully and repeat execution made no duplicate changes.

The HTTP suite provisions test-only accounts in the disposable database, starts a temporary PHP server, and always stops that server. It requires Python 3 and the PHP executable configured through `PHP_BINARY` when not on PATH. Run the PHP integration suite before the HTTP suite to recreate test tables.

## Limitations and next steps

- Browser layout, keyboard and visual QA have not been performed. HTTP success does not establish visual correctness.
- Email is queued privately in MySQL; SMTP/PHPMailer delivery is deferred to Phase 6. Local link retrieval is documented in the README.
- Organization setup, document upload, profile editing, administrative account controls, food workflows and research functionality are reserved for subsequent phases. No controls for those unfinished operations are presented as working features.
- Do not deploy the development server publicly. Production session lifetimes, email delivery, web-server configuration, monitoring and release hardening remain required.
- Foundation unit and HTTP tests do not establish later-phase concurrency/allocation, safety, prediction or QR behavior.
- During this session PHP, MySQL and Composer were installed with approval. A project-isolated MySQL data directory lives in ignored `.runtime/mysql-test`, with local configuration in ignored `.env`. Its application password is generated and never included in documentation. The isolated MySQL port is 3307. Servers used for validation are stopped at handoff.

To restart the isolated MySQL instance for the generated local `.env`:

```sh
/opt/homebrew/opt/mysql@8.4/bin/mysqld --no-defaults --datadir="$PWD/.runtime/mysql-test" --socket="$PWD/.runtime/mysql.sock" --port=3307 --bind-address=127.0.0.1 --mysqlx=OFF --pid-file="$PWD/.runtime/mysql.pid" --log-error="$PWD/.runtime/mysql.log"
```

Then in another terminal run `php -S 127.0.0.1:8080 -t public public/router.php`.

## Phase gate

The implementation plan requires: “At the end of each phase … Wait for approval before proceeding to the next phase.” Phase 2 donation workflows require the user's approval before work proceeds.

## Files created

- `.env.example`
- `.gitignore`
- `README.md`
- `app/Controllers/AuthController.php`
- `app/Controllers/DashboardController.php`
- `app/Core/Database.php`
- `app/Core/Environment.php`
- `app/Core/HttpException.php`
- `app/Core/Session.php`
- `app/Core/View.php`
- `app/Middleware/Authorize.php`
- `app/Models/UserRepository.php`
- `app/Services/AuthService.php`
- `app/Validators/RegistrationValidator.php`
- `composer.json`
- `config/bootstrap.php`
- `config/database.php`
- `database/migrations/001_foundation.sql`
- `database/schema.sql`
- `database/seeds/roles.sql`
- `docs/api.md`
- `docs/architecture.md`
- `public/.htaccess`
- `public/assets/css/app.css`
- `public/assets/js/app.js`
- `public/index.php`
- `public/router.php`
- `resources/views/auth/form.php`
- `resources/views/dashboard/index.php`
- `resources/views/error.php`
- `resources/views/landing.php`
- `resources/views/layout.php`
- `routes/api.php`
- `routes/web.php`
- `scripts/create_user.php`
- `scripts/migrate.php`
- `storage/exports/.gitkeep`
- `storage/logs/.gitkeep`
- `storage/private/.gitkeep`
- `tests/http_smoke.py`
- `tests/run.php`
- `docs/phase-1.md` (this report)

## Local demo follow-up

Added pre-verified accounts for all five roles and a shared login password, listed on the normal login page. `app/Services/DemoService.php` and `scripts/seed_demo.php` provision/reset only reserved demo accounts. Known demo credentials are disabled outside local demo mode. A user clarification restored ordinary email/password sign-in and removed the password-free role entry endpoint and buttons. `scripts/db.php` provides a read-only CLI view using existing environment credentials. Existing non-demo accounts are preserved. No schema change was required.

The README now explains why public registration offers three roles, how all five account roles work, and which operational features remain unimplemented. Organization creation/linking is not available in Phase 1.

Validation after restoring password login: 24 PHP unit/integration tests and 51 HTTP checks passed; all PHP syntax checks passed. HTTP checks confirmed the password-free endpoint returns 404 and all five default credentials work through the normal login endpoint.

## Immediate access policy update

Per the user request, donors, NGOs/receivers and volunteers require no admin verification, document review, approval queue or manual activation. Their accounts are active on registration, and self-service organization profiles must be usable immediately when implemented. Updated the implementation plan, README, architecture, dashboard text and organization queries accordingly. Migration `002_remove_organization_approval.sql` removes organization verification status, reviewer and review timestamp fields; it was applied successfully to the local database. Organization setup itself remains a subsequent-phase feature. Login, role permissions and food-safety screening remain required.

Validation: 26 PHP unit/integration tests and 51 HTTP checks passed; changed PHP files passed syntax checks. New tests confirm immediate donor/receiver/volunteer access before email confirmation and the absence of organization approval fields. This update does not add a new phase or organization-setup UI.
