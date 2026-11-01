# Test strategy and reproduction

## Runtime

Validated with PHP 8.4.27, MySQL 8.4.13 and an installed Chrome. PDO uses native prepared statements. PHP/Composer/JavaScript syntax checks are also run. No external SMTP account or real camera device is required for the automated suites.

## Disposable database

Set `TEST_DB_DATABASE` to a database ending in `_test`, with the same dedicated database account configured in `.env`. Each test entry explicitly selects that database before connecting. **The integration runner drops and recreates tables only in that disposable database.** Operational `foodrescue` records are not test fixtures.

Run in order:

```sh
php tests/run.php
php tests/run.php --integration
php tests/workflows.php
PHP_BINARY=php python3 tests/http_smoke.py
```

Use `/opt/homebrew/opt/php@8.4/bin/php` for PHP_BINARY and the PHP commands on this workstation if `php` is not on PATH. Integration migrations are required before workflow/HTTP tests. The first command needs no MySQL server; the others require the configured local server.

## Coverage

- **Unit:** valid/invalid registration, public privileged-role rejection, missing/forged CSRF, anonymous/cross-role/suspended access, all five roles, exact TAMRA example component/weighted scores, hard eligibility/capacity filtering, valid/invalid lifecycle transitions and strict calendar dates.
- **Authentication/database integration:** actual migrations and idempotent rerun, immediate donor/receiver/volunteer access, no approval schema, password hashes, injected privilege fields, duplicate registration rollback, failed/successful login, suspension, hashed/expiring/single-use email/reset tokens, password replacement/session revocation, rate limits and local-only default accounts.
- **Operational workflow:** self-service create/join/edit ownership, receiver preferences, invalid quantity and incomplete/rejected screening, explainable score persistence, donor proposals/receiver declines, transactional reservation, capacity reduction rejection, claimed-listing withdrawal rejection, scheduling/PNG QR, invalid/reused token, volunteer collection, two-party confirmation, actual collected/distributed impact, feedback, failed-pickup reasons/relisting, expiry/reminder idempotency and concurrent allocation.
- **Concurrency:** independent PHP processes and independent database connections race two receivers against one donation, then two different donations against one receiver. Tests assert exactly one valid allocation and no capacity over-allocation.
- **Research/reporting:** deterministic scenario generation and decisions, same eligible denominators for all four algorithms, four persisted runs, anonymous import metadata/PII field rejection, research authorization, admin safety enforcement against old listings, prediction metrics, genuine PDF output, formula-safe CSV and local MIME-mail delivery.
- **HTTP:** real cookies, security headers, CSRF, invalid JSON/structured fields, private-file rejection, all five role logins/pages/cross-role denial/logout, default credentials, absence of credential disclosure on landing/login pages, password-free endpoint absence, actual registration, output escaping and verification resend.

## Browser suite

Install optional `playwright-core` in a temporary directory and use an existing Chrome. The application itself has no Node dependency. On the development machine used here:

```sh
PHP_BINARY=/opt/homebrew/opt/php@8.4/bin/php \
PLAYWRIGHT_MODULE=/private/tmp/foodrescue-browser/node_modules/playwright-core \
node tests/browser.cjs
```

If installing elsewhere, set PLAYWRIGHT_MODULE to the installed module path and CHROME_BINARY if Chrome is in a different location. The test starts the local development runner (including expiry/reminder/mail jobs) against the disposable database and always stops the server/browser. It exercises the visible login form for all five roles, organization forms, receiving category checkboxes, local-time conversions, donation creation, multipart photo upload/authenticated image serving, receiver acceptance, scheduling, QR generation/decoding using jsQR, collection, both confirmations, impact charts, research forms, admin screens and mobile horizontal overflow. It checks uncaught JavaScript errors and saves screenshots under ignored `storage/exports/`.

The browser suite validates a generated QR through the same decoding library used by the camera scanner. It does not exercise a physical camera, a real food handoff or external SMTP delivery.

## Verification boundaries

All fixtures are explicitly test/synthetic data. No measured timing or result is fabricated. Repeated tests may leave synthetic rows in the disposable database; rerun the integration runner to rebuild it. Screenshots are local QA evidence, not real-world impact claims.

Tests establish local software behavior, not domain correctness of safety limits, statistical calibration, broad novelty, globally optimal allocation or production readiness on an untested host. The deployment guide describes external configuration and release checks.
