# System architecture

FoodRescue uses Core PHP 8.4+, MySQL/InnoDB, native PDO prepared statements and server-side session authorization. HTML is rendered by escaped PHP views; Vanilla JavaScript uses Fetch for mutations. Business rules live in services, database access in repository classes, HTTP coordination in controllers, and presentation in views. The custom PSR-4 bootstrap also loads Composer libraries for SMTP, QR and PDF output.

```mermaid
flowchart LR
 Browser[HTML / CSS / Fetch / Leaflet / Chart.js] --> Front[public/index.php]
 Front --> Guards[Session / CSRF / role authorization]
 Guards --> Auth[AuthController]
 Guards --> API[OperationsController]
 Guards --> Pages[PageController / DashboardController]
 Pages --> Views[Escaped views]
 API --> Domain[Organization / Donation / Matching / Pickup / Admin / Research services]
 Domain --> Engine[TAMRA / Safety / Feasibility / Lifecycle]
 Domain --> Store[PDO repositories]
 Store --> DB[(MySQL)]
 CLI[CLI jobs / local development runner] --> Jobs[Expiry / Reminders / Mail]
 Jobs --> DB
 Domain --> Private[Non-public uploads / email files]
```

## Access boundaries

- Public registration offers donor, receiver and volunteer, immediately active. No admin approval, verification queue or manual activation exists. Donor/receiver organizations are self-service.
- Organization membership grants access to its public operational profile, not other creators' editing privileges. Only an organization creator can edit its details and access supporting documents. Each donor owns their listings; each receiver has their own capacity budget even when members share an organization.
- Normal login is required. Five demo accounts are pre-verified and enabled only in local demo mode. Their email/password form remains the sole entry. Admin and researcher tools sit outside the donation/pickup flow.
- Role and ownership checks protect every operational route; a researcher cannot browse operational listings or retrieve contributors' identities through impact exports. Private documents and own profile images have explicit file permissions at HTTP delivery.
- Email confirmation is optional for operational access. Password resets revoke existing sessions. Session IDs/CSRF rotate on login; idle sessions expire. Unknown recovery emails get the same response.
- Stored timestamps and matching calculations use UTC. Browser forms convert local datetime values to ISO UTC before submission; rendered timestamps convert back to browser-local time.

## Data consistency

Donations use kilograms as the canonical capacity unit, while preserving entered quantity/unit and conversion factor. Monetary/value claims are not inferred. Screening outcomes and original inputs are audited. Mandatory failures cannot be overridden by TAMRA.

TAMRA/feasibility run as independent deterministic services. Configuration rows and rule rows are immutable versions. Match snapshots retain inputs, components and assumptions. Proposals/declines/acceptance decisions are recorded separately. Allocation locks donation then receiver before creating a pickup; capacity-changing pickup transitions and expiry follow the same ordering. InnoDB checks and a generated unique active donation ID provide database enforcement too.

A pickup moves accepted → scheduled → collected → completed; failure, cancellation and expiry release reservations. Collected/completed pickups cannot be expired/reclaimed. One-use pickup tokens are hashed; QR generation exposes the raw token only in the scheduling response. Both donor and receiver confirm completion, and impact records retain collected/distributed amounts separately.

Research datasets and experiments are separate from operational tables. Imported scenario fields are whitelisted; live account details are never copied to research exports. All algorithms receive identical hard constraints and scenario/outcome seeds. Simulated results are labeled; execution time is measured rather than fabricated.

## Full relationship diagram

```mermaid
erDiagram
 roles ||--o{ users : role_id
 users ||--o| user_profiles : user_id
 users ||--o{ organizations : owner_user_id
 organizations ||--o{ organization_documents : organization_id
 users ||--o{ account_tokens : user_id
 users ||--o{ audit_logs : user_id
 organizations ||--o{ organization_members : organization_id
 users ||--o{ organization_members : user_id
 food_categories ||--o{ safety_rules : category_id
 users ||--o{ safety_rules : created_by
 users ||--o{ donations : donor_id
 organizations ||--o{ donations : organization_id
 food_categories ||--o{ donations : category_id
 safety_rules ||--o{ donations : safety_rule_id
 donations ||--o{ food_safety_records : donation_id
 safety_rules ||--o{ food_safety_records : rule_id
 users ||--o{ food_safety_records : actor_id
 donations ||--o{ donation_images : donation_id
 users ||--o{ receivers : user_id
 organizations ||--o{ receivers : organization_id
 receivers ||--o{ receiver_categories : receiver_id
 food_categories ||--o{ receiver_categories : category_id
 receivers ||--o{ receiver_availability : receiver_id
 users ||--o{ algorithm_configurations : created_by
 donations ||--o{ matches : donation_id
 receivers ||--o{ matches : receiver_id
 algorithm_configurations ||--o{ matches : configuration_id
 matches ||--o{ match_scores : match_id
 donations ||--o{ pickup_schedules : donation_id
 receivers ||--o{ pickup_schedules : receiver_id
 matches ||--o{ pickup_schedules : match_id
 users ||--o{ pickup_schedules : volunteer_id
 users ||--o| volunteers : user_id
 pickup_schedules ||--o{ pickup_verifications : pickup_id
 users ||--o{ pickup_verifications : verified_by
 donations ||--o{ pickup_events : donation_id
 pickup_schedules ||--o{ pickup_events : pickup_id
 users ||--o{ pickup_events : actor_id
 pickup_schedules ||--o{ pickup_failures : pickup_id
 users ||--o{ notifications : user_id
 pickup_schedules ||--o{ reviews : pickup_id
 users ||--o{ reviews : author_id
 pickup_schedules ||--o| impact_records : pickup_id
 users ||--o{ datasets : owner_id
 users ||--o{ experiments : owner_id
 datasets ||--o{ experiments : dataset_id
 algorithm_configurations ||--o{ experiments : configuration_id
 experiments ||--o{ experiment_runs : experiment_id
 experiment_runs ||--o{ experiment_results : run_id
 users ||--o{ platform_settings : updated_by
 matches ||--o{ match_decisions : match_id
 users ||--o{ match_decisions : actor_id
```

The `migrations` bookkeeping table tracks forward SQL files. `auth_rate_limits`, `mail_outbox` and `platform_settings` are standalone infrastructure/configuration tables. All other relationships are enforced with foreign keys. The previous organization-review columns were removed in migration 002; they are not part of the current schema.

## Deployment boundaries

Only `public/` is a document root. Private uploads, credentials, Composer code, CLI scripts and exports are outside it. Uploaded content cannot be executed through application routes. Production must use HTTPS/PHP-FPM, external SMTP configuration and scheduled CLI jobs; the local runner starts those jobs alongside PHP’s development server. See deployment.md.
