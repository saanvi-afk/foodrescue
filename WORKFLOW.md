# FoodRescue: Website Workflow and User Guide

This guide explains how to run FoodRescue and demonstrate every main workflow. Donors, NGOs/receivers and volunteers do **not** need administrator approval. Use the ordinary email/password login page; default local demo credentials are documented only in [README.md](README.md#default-login-accounts).

## 1. Run the website locally

For the existing macOS workspace, with MySQL running:

```sh
cd /Users/saanvi/Desktop/webtech
/opt/homebrew/opt/php@8.4/bin/php scripts/serve.php
```

Open **http://localhost:8080/login**. Keep the terminal open; press **Ctrl+C** to stop. The runner processes expiry, reminders and queued email every 30 seconds, independently of browser tabs. Stop an existing server before starting another on port 8080.

If the configured local MySQL instance is stopped, start it in a separate terminal:

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

These commands describe the already configured development machine. For a new clone, follow [New installation](README.md#new-installation): install PHP 8.4+/MySQL/Composer, create `.env` from `.env.example`, create the databases and dedicated user, then run:

```sh
composer install
php scripts/migrate.php
php scripts/seed_demo.php
php scripts/serve.php
```

The repository contains schema/migrations and seed scripts, not private credentials, uploads or a copy of the local database. The `.runtime` MySQL data directory is created locally and is not part of a clone.

## 2. Understand the five roles

| Role | Main work | Initial screen/tools |
| --- | --- | --- |
| Donor | Offer surplus food and coordinate handoffs | Organizations, My donations, Pickups, Impact |
| NGO / Receiver | Configure receiving capacity, accept food and record distribution | Organizations, Capacity & availability, Discover food, Pickups |
| Volunteer | Collect assigned donations and verify handoffs | Profile, Pickups |
| Super Admin | Maintain account access, food policies and configuration | Platform management |
| Researcher | Evaluate matching methods on isolated datasets | Experiments |

Public registration offers Donor, Receiver and Volunteer. Admin and Researcher are provisioned separately; their demo accounts already exist after seeding. Your login email determines your role automatically. There is no password-free role-entry endpoint and no account-approval queue.

When demonstrating multiple roles, use separate browser profiles or a normal window for Donor and a private window for Receiver. Ordinary tabs share the same session. Multiple windows within the same private browsing session may also share cookies. Alternatively, sign out before switching accounts.

## 3. Donor: create or link an organization

1. Sign in as Donor using the README credentials.
2. Open **Organizations**.
3. Enter the organization name, service address and latitude/longitude.
4. Add optional phone or registration/license reference if desired.
5. Click **Create and link organization**.

The organization becomes usable immediately. To use an existing donor organization, select it under **Link an existing organization** and submit.

The organization creator can edit details and upload optional PDF/image documents. Supporting documents stay private to the creator. Other linked members manage their own listings; they cannot edit another member's donations or the creator's organization details. No document upload or administrator review is required to proceed.

## 4. Receiver: create an organization and set preferences

1. Sign in as NGO/Receiver.
2. Open **Organizations** and create or link a receiving organization.
3. Open **Capacity & availability**.
4. Select the linked receiving organization.
5. Enter maximum concurrent capacity in kilograms.
6. Select accepted food categories.
7. Set transportation and collection availability.
8. Enter collection-base coordinates and maximum radius.
9. Set available-from and available-until dates/times.
10. Save the preferences.

Capacity is reserved across active pickups. You cannot reduce capacity below the amount already reserved. Each receiver account has its own capacity budget, even when multiple accounts share an organization.

For a fictional demonstration, use 20 kg capacity, accept Bakery, enable collection and transportation, and set an availability window from now until tomorrow. Use nearby donor and receiver coordinates, such as `28.61, 77.21` for both.

## 5. Optional volunteer setup

1. Sign in as Volunteer.
2. Open **Profile**.
3. Set availability and transport description.
4. Save.

Available volunteers appear in the pickup scheduling selector. The seeded demo volunteer starts available. Volunteers see assigned pickups, not all donations or another volunteer's assignments. The receiver can collect directly, so a volunteer is optional.

## 6. Donor: create a donation

1. Open **My donations → Share surplus food**.
2. Select the donor organization.
3. Enter food name, description and category.
4. Enter quantity and unit. Kilograms/grams convert automatically; portions/items require a weight per unit.
5. Record preparation/packing time, use-by and latest pickup time.
6. Record storage method, measured temperature where applicable and packaging condition.
7. Enter ingredients and allergens.
8. Complete handling declarations accurately.
9. Enter pickup address and coordinates.
10. Submit to record the listing and screen eligibility.

Dates are entered in your browser's local timezone and stored in UTC. Pickup deadline cannot be later than use-by. A listing can be edited only while unclaimed. An unclaimed listing can also be withdrawn.

For a fictional demonstration, use 10 kg of bread, Bakery, preparation 30 minutes ago, pickup deadline six hours ahead and use-by eight hours ahead. These are test inputs, not real-food shelf-life guidance. Complete the fictional ingredients/allergen/handling details consistently.

## 7. Read the safety-screening result

| State | Meaning | Next action |
| --- | --- | --- |
| Available | Recorded declarations pass the configured checks | Review matches or wait for acceptance |
| Review | Required information needs completion/correction | Edit the unclaimed listing and re-screen |
| Rejected | A mandatory eligibility check failed | Inspect the reason; correct only if the underlying facts support correction |

Donation details show the screening outcome, reasons, rule version and source. Photos can be uploaded after creation; multiple photographs are stored separately.

Screening is a declaration/traceability tool, not food-safety certification. A photograph or matching score cannot override a mandatory failure. Category policies require applicable domain guidance before real operation. Food-information review is separate from account onboarding; there is no donor/NGO/volunteer admin-approval step.

## 8. Review TAMRA recommendations

Open donation details to see eligible receivers and explainable scores. Before ranking, mandatory safety, account, category, capacity, radius, availability and collection-time constraints must pass.

TAMRA considers urgency, distance, capacity fit, category compatibility, pickup feasibility and historical reliability. Each component and the overall score are shown. New receivers get a neutral historical-reliability value until sufficient outcomes exist.

The donor can click **Notify this receiver** to send a recommendation. This records a proposal and notification but does not reserve the food. Exclusions explain why a candidate is not eligible. Feasibility is a transparent heuristic, not a calibrated probability; travel estimates are approximate rather than live traffic/routing results.

## 9. Receiver: discover, accept or decline

1. Open **Discover food**.
2. Inspect the listing, handling details, deadline, location and matching explanation.
3. Accept the full donation or decline the recommendation.

Acceptance reserves the donation and its kilograms against receiving capacity. A transaction and database constraints prevent two receivers from claiming the same food or simultaneous requests exceeding capacity. Declining does not reserve food and notifies the donor.

Example: accepting 10 kg with a 20 kg capacity budget reserves 10 kg. If no eligible match appears, check category selection, remaining capacity, availability window, collection radius, location and deadline.

## 10. Schedule the pickup and save its token

1. Donor or Receiver opens **Pickups**.
2. Select the accepted pickup.
3. Choose a future collection-start time within the remaining feasible window.
4. Choose an available volunteer or receiver self-collection.
5. Click **Schedule and generate one-use QR**.
6. Save the raw token or download the QR image before leaving.

Rescheduling replaces the token and invalidates the old one. Maps and external links show the pickup location. Scheduling must leave enough estimated travel/handling time within availability and the deadline.

## 11. Record actual collection

1. Receiver or assigned Volunteer opens the scheduled pickup.
2. Choose **Verify token and record collection**.
3. Scan the QR with the camera or paste the token.
4. Enter actual collected kilograms, no more than offered.
5. Submit.

The donor cannot perform this verification. Invalid, expired or reused tokens are rejected. Camera scanning needs localhost/HTTPS and browser permission; manual token entry remains available. The token is stored as a hash and can be consumed once.

For the fictional 10 kg listing, recording 8 kg collected preserves the distinction between offered and actually collected food.

## 12. Confirm completion and distribution

1. Donor opens the collected pickup and confirms the handoff.
2. Receiver opens it, records actual distributed kilograms and confirms.
3. Refresh **Pickups** after the other party acts.
4. Once both confirmations exist, the pickup becomes **Completed** and reserved capacity is released.

Distribution cannot exceed collected weight. The receiver can update distributed quantity later. Donor/receiver participants can leave feedback on completed pickups.

## 13. Handle failure, cancellation, withdrawal and expiry

- Before collection, a participant can report a failed pickup and provide a reason.
- Donor/Receiver can cancel an accepted/scheduled pickup.
- Failed/cancelled pickups release reserved capacity.
- The donor can re-screen and relist eligible failed/cancelled food before its deadline.
- An unclaimed listing can be withdrawn.
- Available or uncollected assigned food expires after the deadline; reserved capacity is released where applicable.
- Collected/completed food is not reclaimed or expired as an available listing.

The central lifecycle logic limits valid transitions. Expiry/reminder jobs are safe to rerun without duplicate effects.

## 14. Read notifications and understand email

**Notifications** shows matching, donation, pickup and deadline activity for the signed-in account. Open the relevant link or mark a message as read. Refresh the page to see new events.

The local development runner checks reminders, expiry and mail every 30 seconds. Local email is queued in MySQL and delivered as private MIME files under `storage/private/`, not to an external inbox. SMTP delivery needs operator-provided credentials. Default demo accounts are already email-verified; normal operational access is not gated on email/admin approval.

## 15. Review impact and export data

**Impact** separates offered, accepted, collected and distributed quantities. It also includes pickup outcomes, trends, category distribution, scoped contributions, approximate geography and prediction evaluation.

With the fictional example, the record can show 10 kg offered, 10 kg accepted, 8 kg collected and 8 kg distributed. Offered food is never automatically counted as collected food. A receiver whose pickup failed does not get credit for another receiver's later collection.

Meal numbers use a configurable kilograms-per-meal estimate, not a verified count of people served. CSV/JSON/PDF exports use the signed-in account's scope. Researchers receive anonymized aggregates rather than contributor identities or operational coordinates.

## 16. Admin: manage the platform

Open **Platform management** to:

- Suspend/restore accounts.
- Add, rename or deactivate food categories.
- Save new versions of category safety rules with source guidance.
- Version matching weights and assumptions.
- Configure the meal-estimate conversion factor.
- Inspect security/admin audits and mail-queue status.

Administrators do not approve donor, NGO or volunteer registrations. Rules/configurations are versioned so historical recommendations remain explainable.

## 17. Researcher: run reproducible experiments

1. Open **Experiments**.
2. Generate a simulated dataset, for example 100 donations, 20 receivers and seed 42; or import an anonymous permitted scenario using the documented schema.
3. Select the dataset and an immutable TAMRA configuration.
4. Enter an experiment name and shared outcome seed.
5. Run all four approaches: nearest eligible receiver, earliest deadline first, highest suitable capacity and TAMRA.
6. Inspect comparative charts, metrics, decisions and measured execution time.
7. Repeat with different outcome seeds to inspect means and variability.
8. Export decisions as CSV, charts as PNG, reports as PDF, or the full reproducibility bundle as JSON.

All algorithms share hard constraints and scenario/outcome inputs. Experiments are isolated from operational allocations. Outcomes are explicitly simulated, including runs using imported input scenarios. They are not evidence of observed pilot performance, novelty or superiority. See [research methodology](docs/research.md).

## 18. Profile and account recovery

**Profile** lets you change your name, phone, preferred timezone and photograph. Volunteers also manage availability/transport here. Date display follows the browser's local timezone.

Forgot-password uses the recovery form. Recovery and email-confirmation links expire and are single-use. A password reset invalidates previous sessions. In local log-mail mode, retrieve delivery links from private mail files/outbox as described in the README.

## 19. Inspect stored data

Website changes persist automatically in MySQL. In the existing workspace:

```sh
/opt/homebrew/opt/php@8.4/bin/php scripts/db.php
/opt/homebrew/opt/php@8.4/bin/php scripts/db.php organizations
/opt/homebrew/opt/php@8.4/bin/php scripts/db.php donations
/opt/homebrew/opt/php@8.4/bin/php scripts/db.php pickup_schedules
/opt/homebrew/opt/php@8.4/bin/php scripts/db.php impact_records
/opt/homebrew/opt/php@8.4/bin/php scripts/db.php experiment_runs
```

The inspector reads `.env`, lists table counts or up to 50 rows, and hides password/token hashes and delivery-message bodies. Database credentials are separate from website demo credentials. See the README for a desktop SQL-client connection or SQL terminal commands.

## 20. Reset the local demonstration

Only when you intend to delete entered local data:

```sh
/opt/homebrew/opt/php@8.4/bin/php scripts/reset_demo.php --confirm-reset
```

The reset requires local demo mode. It deletes entered accounts/organizations/donations/pickups/research records, notifications, uploads, local mail, logs and exports. It preserves schema/migration history and restores default configuration plus five verified demo accounts. Sign in again afterwards.

## Short presentation order

Donor organization → Receiver organization/preferences → Donor food listing → Safety result and matches → Receiver acceptance → Pickup scheduling/QR → Receiver/Volunteer collection → Both confirmations → Impact → Optional Admin tools → Optional Research comparison.
