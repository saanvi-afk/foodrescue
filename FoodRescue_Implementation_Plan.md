# FoodRescue --- Complete Implementation Plan

> **Project:** FoodRescue: Intelligent, Time-Aware Food Redistribution
> Platform\
> **Purpose:** Build a working PHP + JavaScript platform that connects
> surplus-food donors with receivers, improves donation
> matching and pickup coordination, and supports reproducible research
> experiments.\
> **Document type:** Software implementation specification for a coding
> agent.

------------------------------------------------------------------------


> **Updated access requirement:** Donor, NGO/Receiver and Volunteer accounts do not require administrator verification, approval, document review or manual activation. Registration creates an active account immediately. Donor/receiver organization setup is self-service, and saving a complete organization profile makes it usable without an approval queue. Admin approval must not be an eligibility condition for listings, discovery, requests, matching or pickup assignment. Normal login, role authorization, capacity constraints and mandatory food-safety screening still apply. Email confirmation, if offered, must not act as an administrator-approval step or block these workflows.

## 1. Project vision

FoodRescue is a web-based platform that helps restaurants, hotels,
bakeries, hostels, event organizers and other donors redistribute
eligible surplus food to NGOs and community organizations.

The project must be more than a basic donation listing website. Its
central technical and research focus is an explainable, time-aware,
multi-factor matching method called **TAMRA** (Time-Aware Multi-factor
Redistribution Algorithm), together with pickup-feasibility estimation
and experimental comparison against simpler baselines.

### Main objectives

1.  Allow donors to list surplus food.
2.  Allow NGOs and community receivers to discover and request
    donations.
3.  Screen donations against configurable food-safety eligibility rules.
4.  Rank eligible donor--receiver matches using TAMRA.
5.  Estimate whether a proposed pickup is feasible.
6.  Coordinate collection, verification and completion.
7.  Notify users about important events and deadlines.
8.  Measure food collected, failed pickups and estimated waste
    prevention.
9.  Provide a research dashboard for repeatable algorithm experiments.
10. Produce documentation and evidence suitable for an academic research
    paper.

**Research integrity:** TAMRA is a proposed method to implement and
evaluate. Do not claim it is novel, superior or validated until a
literature review and experiments support those claims.

------------------------------------------------------------------------

## 2. Technology stack and constraints

  -----------------------------------------------------------------------
  Layer                               Technology
  ----------------------------------- -----------------------------------
  Backend                             Core PHP 8.2+

  Frontend                            HTML5, CSS3, Vanilla JavaScript ES6

  Database                            MySQL 8

  Database access                     PDO with prepared statements

  Browser/server communication        Fetch API / JSON APIs

  Charts                              Chart.js

  Maps                                Leaflet + OpenStreetMap

  Authentication                      PHP Sessions

  Email                               PHPMailer

  QR codes                            Suitable maintained PHP/JavaScript
                                      QR libraries

  Dependency management               Composer

  Testing                             PHPUnit or an equivalent PHP test
                                      setup; browser tests where
                                      practical
  -----------------------------------------------------------------------

### Constraints

-   Use Core PHP; do not replace it with Laravel or another backend
    framework.
-   Use a modular, MVC-inspired structure.
-   Keep business logic out of page templates.
-   Keep TAMRA and research experiments independently testable.
-   Do not put secrets or privileged logic in frontend JavaScript.
-   Use UTC for stored timestamps and convert to the user's local
    timezone for display.
-   Store uploaded files safely; private documents must not be publicly
    accessible.
-   Use environment variables for credentials and secrets.

------------------------------------------------------------------------

## 3. User roles and permissions

Implement five roles with server-side authorization.

  -----------------------------------------------------------------------
  Role                                Responsibilities
  ----------------------------------- -----------------------------------
  Super Admin                         Manage users, organizations,
                                      categories, safety rules, reports
                                      and platform settings

  Donor                               Create and manage food donations,
                                      view matches and track collection

  NGO / Receiver                      Manage receiving capacity and
                                      preferences, discover and accept
                                      donations

  Collection Volunteer                View assigned collections, update
                                      pickup progress and verify
                                      collection

  Researcher                          Access approved datasets, run
                                      experiments and view research
                                      analytics
  -----------------------------------------------------------------------

### Account and organization requirements

-   Registration and login
-   Logout
-   Secure password hashing
-   Password reset
-   Email verification
-   Role-based access control
-   Profile editing and photograph
-   Organization details
-   Optional organization supporting documents; no approval requirement
-   Self-service organization setup with immediate access
-   Immediate account activation on registration; administrative suspension for account management
-   Login/security activity logs

Users must not be able to change their own role or account suspension status
through a crafted request.

------------------------------------------------------------------------

## 4. Functional modules

### Module A --- Authentication and account management

1.  User registration.
2.  Login and logout.
3.  Password hashing with PHP password functions.
4.  Forgot-password and reset-password workflow.
5.  Role-based access control.
6.  Profile editing.
7.  Profile photograph upload.
8.  Organization details management.
9.  Email verification.
10. Account suspension and activation.
11. Self-service organization profile status (no approval queue).
12. Login and security activity logs.

### Module B --- Donor management

1.  Donor dashboard.
2.  Create a donation.
3.  Edit eligible donation details.
4.  Withdraw an unclaimed donation.
5.  Upload multiple food photographs.
6.  Enter food name and description.
7.  Select food category.
8.  Enter quantity and unit.
9.  Record preparation date and time.
10. Record storage conditions.
11. Set pickup location and coordinates.
12. Set pickup deadline.
13. View donation status.
14. View matching recommendations.
15. View donation history.
16. View successful redistribution statistics.

### Module C --- Receiver / NGO management

1.  NGO or receiver registration.
2.  Self-service organization setup without admin approval.
3.  Organization profile.
4.  Service location and coordinates.
5.  Maximum receiving capacity.
6.  Accepted food categories.
7.  Operating hours.
8.  Transportation availability.
9.  Collection availability.
10. Available donation discovery.
11. Recommended donation list.
12. Donation request.
13. Accept or reject a proposed match.
14. Pickup schedule management.
15. Collection confirmation.
16. Donation history.
17. Donor/receiver feedback.

### Module D --- Food safety and eligibility

1.  Record preparation date and time.
2.  Record food type/category.
3.  Record storage method.
4.  Record temperature information where relevant.
5.  Record packaging condition.
6.  Record allergen information.
7.  Record donor handling declaration.
8.  Apply configurable category-specific eligibility rules.
9.  Flag missing safety information.
10. Flag donations requiring manual review.
11. Reject donations that fail mandatory eligibility rules.
12. Maintain safety audit records.
13. Record rejection reasons.
14. Track inspection history.

**Safety constraint:** This is a screening and traceability tool, not a
food-safety certification system. Rules must be based on applicable
FSSAI requirements and relevant food-handling guidance. A matching score
or prediction must never override a mandatory safety rejection. Do not
claim food is safe based only on an image, a score or a preparation
timestamp.

### Module E --- TAMRA intelligent matching engine

**Research status:** Proposed multi-factor matching approach. Its
performance and novelty must be evaluated, not assumed.

#### Inputs

-   Donation deadline and time remaining
-   Distance between donor and receiver
-   Donation quantity
-   Receiver capacity
-   Food category compatibility
-   Receiver availability
-   Transportation availability
-   Estimated travel time
-   Historical pickup reliability

#### Initial scoring equation

\[ S(d,r)=w_1U+w_2D+w_3C+w_4A+w_5T+w_6H \]

Where:

  Variable   Meaning
  ---------- ----------------------------------------
  U          Donation urgency
  D          Distance suitability
  C          Receiver capacity suitability
  A          Food category / receiver compatibility
  T          Pickup-time feasibility
  H          Historical pickup reliability

Normalize each component to the range 0--1. Initial weights:

  Parameter                  Initial weight
  ------------------------ ----------------
  Urgency                              0.25
  Distance                             0.20
  Capacity                             0.15
  Compatibility                        0.15
  Pickup feasibility                   0.15
  Historical reliability               0.10
  **Total**                        **1.00**

These weights are starting experimental parameters, not scientifically
established optimal values. Make them configurable and versioned.

#### Required TAMRA functionality

1.  Retrieve available donations.
2.  Retrieve eligible receivers.
3.  Filter receivers that violate hard constraints.
4.  Filter incompatible categories.
5.  Check receiver capacity.
6.  Calculate donation urgency.
7.  Calculate distance suitability.
8.  Calculate capacity suitability.
9.  Calculate category compatibility.
10. Estimate pickup-time feasibility.
11. Calculate historical reliability where sufficient data exists.
12. Generate component and overall scores.
13. Rank eligible receivers.
14. Recommend the best receiver.
15. Recommend alternative receivers.
16. Explain why each receiver was recommended.
17. Store algorithm scores and parameters.
18. Allow authorized administrators/researchers to adjust weights.
19. Store matching decisions for research analysis.

#### Matching safeguards

-   Apply mandatory eligibility and capacity constraints before ranking.
-   Treat unavailable or missing data explicitly; do not silently assign
    favorable scores.
-   Avoid treating a lack of history as poor reliability. Define and
    document a neutral cold-start policy.
-   Prevent two receivers from claiming the same donation.
-   Account for competing donations and receiver capacity across
    simultaneous requests.
-   Use database transactions and appropriate row locking for
    allocation.
-   Preserve the algorithm version and inputs used for each
    recommendation.

A simple independent ranking does not guarantee globally optimal
allocation. Document this limitation and consider a batch
allocation/optimization extension if time permits.

### Module F --- Pickup-feasibility prediction

Estimate whether a receiver is likely to collect a donation successfully
before the relevant deadline.

#### Candidate input features

-   Distance
-   Remaining collection time
-   Receiver availability
-   Transportation availability
-   Previous acceptance rate
-   Previous successful pickup rate
-   Average pickup delay
-   Current active collection workload

#### Functionalities

1.  Calculate pickup feasibility.
2.  Generate a probability or clearly labelled feasibility score.
3.  Categorize feasibility as high, medium or low using documented
    thresholds.
4.  Display factors influencing the estimate.
5.  Suggest alternative receivers.
6.  Flag likely-to-fail pickups.
7.  Recalculate when relevant conditions change.
8.  Store predicted outcomes.
9.  Store actual outcomes.
10. Evaluate predictions against actual outcomes.

#### Implementation stages

-   Version 1: transparent rule-based baseline.
-   Version 2: logistic regression if enough labelled observations
    exist.
-   Version 3: compare alternative models only if data volume and
    quality justify it.

Do not present a heuristic score as a statistically calibrated
probability. Do not train or evaluate on leaked future information. Use
time-aware or grouped train/test splits where appropriate.

### Module G --- Pickup and logistics management

1.  Create pickup schedules.
2.  Assign collection volunteers.
3.  Assign a receiver to a donation.
4.  Display pickup location on a map.
5.  Calculate approximate travel distance.
6.  Estimate travel duration where a suitable routing service is
    available.
7.  Generate QR pickup tokens.
8.  Verify pickup using QR scanning.
9.  Record collection time.
10. Confirm collection by relevant parties.
11. Record actual collected quantity.
12. Report failed pickups.
13. Record failure reasons.
14. Reschedule eligible pickups.
15. Maintain pickup history.
16. Track the donation lifecycle.

#### Suggested donation lifecycle

`Created → Safety Screening → Available → Matched/Accepted → Pickup Scheduled → Collected → Completed`

Alternative terminal or exception states:

`Rejected`, `Withdrawn`, `Expired`, `Failed Pickup`, `Cancelled`.

Define valid state transitions centrally in backend business logic. Do
not let clients submit arbitrary status changes.

### Module H --- Notifications

1.  New donation notification.
2.  New matching recommendation.
3.  Donation acceptance notification.
4.  Donation rejection notification.
5.  Pickup reminder.
6.  Approaching-deadline alert.
7.  Failed-pickup notification.
8.  Successful-collection notification.
9.  Organization-profile update notification.
10. Email notifications.
11. In-app notifications.
12. Read/unread tracking.

Use database-backed notifications. Use a scheduled PHP CLI script or
cron job for reminders and expiry processing; do not depend on a browser
tab remaining open. Make scheduled jobs safe to rerun without duplicate
side effects.

### Module I --- Impact analytics

1.  Total food offered.
2.  Total food accepted.
3.  Total food collected.
4.  Total food rejected.
5.  Total expired donations.
6.  Successful pickup count.
7.  Failed pickup count.
8.  Estimated meals provided.
9.  Estimated food waste prevented.
10. Donor contribution rankings.
11. Receiver contribution rankings.
12. Monthly donation trends.
13. Food category distribution.
14. Geographical distribution.
15. Pickup success rate.
16. CSV and PDF exports.

Clearly distinguish food offered, accepted, collected and distributed.
Do not count every listing as rescued food. Document assumptions and
conversion factors for estimated meals and environmental impact; label
estimates as estimates.

### Module J --- Research and experimentation dashboard

Restrict this dashboard to authorized researchers.

#### Experiment functionality

1.  Create an experiment.
2.  Define experiment parameters.
3.  Select a dataset.
4.  Run nearest-receiver baseline.
5.  Run earliest-deadline-first baseline.
6.  Run highest-capacity baseline.
7.  Run TAMRA.
8.  Compare approaches.
9.  Calculate pickup success rate.
10. Calculate expired-donation rate.
11. Calculate average matching time.
12. Calculate average distance.
13. Calculate receiver capacity utilization.
14. Measure algorithm execution time.
15. Generate comparative charts.
16. Export experiment results.
17. View historical runs.
18. Save algorithm configurations.

#### Required baseline comparisons

  Experiment   Approach
  ------------ ------------------------------------
  E1           Nearest eligible receiver
  E2           Earliest deadline first
  E3           Highest suitable receiver capacity
  E4           TAMRA multi-factor ranking

Use the same dataset, eligibility constraints, scenario assumptions and
simulation conditions for fair comparisons. Record random seeds for
stochastic simulations.

------------------------------------------------------------------------

## 5. Database architecture

Create a normalized MySQL schema. Provide migrations, seed data and an
ER diagram.

  Table                      Purpose
  -------------------------- -------------------------------------
  roles                      Application roles
  users                      Authentication and accounts
  organizations              Donor and NGO organization details
  organization_documents     Optional supporting documents
  user_profiles              Personal profile details
  food_categories            Food classification
  donations                  Main donation records
  donation_images            Donation photographs
  food_safety_records        Safety declarations and inspections
  safety_rules               Configurable eligibility rules
  receivers                  Receiver capacity and preferences
  receiver_categories        Accepted food categories
  receiver_availability      Collection availability
  matches                    Matching results
  match_scores               Individual scoring components
  algorithm_configurations   TAMRA weights and versions
  pickup_schedules           Collection arrangements
  volunteers                 Volunteer details
  pickup_verifications       QR verification records
  pickup_events              Pickup lifecycle history
  pickup_failures            Failed collection records
  notifications              User notifications
  reviews                    Donor and receiver feedback
  impact_records             Redistribution impact measurements
  datasets                   Dataset metadata
  experiments                Research experiment metadata
  experiment_runs            Individual algorithm runs
  experiment_results         Measured results
  audit_logs                 Important system activity

### Database requirements

-   Use foreign keys and appropriate indexes.
-   Use transactions for donation acceptance and capacity reservation.
-   Use suitable DECIMAL types for quantities.
-   Store timestamps consistently in UTC.
-   Store latitude and longitude as numeric values.
-   Use controlled status values or lookup tables.
-   Preserve historical donation and experiment records.
-   Use soft deletion for accounts where appropriate.
-   Prevent duplicate acceptance of the same donation.
-   Prevent capacity over-allocation during concurrent requests.
-   Add unique constraints where needed.
-   Document all relationships and important constraints.

------------------------------------------------------------------------

## 6. Suggested project folder structure

``` text
foodrescue/
├── app/
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── DonationController.php
│   │   ├── ReceiverController.php
│   │   ├── MatchingController.php
│   │   ├── PickupController.php
│   │   ├── NotificationController.php
│   │   ├── AdminController.php
│   │   └── ResearchController.php
│   ├── Models/
│   ├── Services/
│   │   ├── TAMRAService.php
│   │   ├── FeasibilityService.php
│   │   ├── SafetyService.php
│   │   ├── NotificationService.php
│   │   └── ImpactService.php
│   ├── Middleware/
│   ├── Validators/
│   └── Helpers/
├── config/
│   ├── database.php
│   ├── app.php
│   └── mail.php
├── database/
│   ├── migrations/
│   ├── seeds/
│   └── schema.sql
├── public/
│   ├── index.php
│   ├── assets/
│   │   ├── css/
│   │   ├── js/
│   │   └── images/
│   └── .htaccess
├── resources/
│   └── views/
│       ├── auth/
│       ├── donor/
│       ├── receiver/
│       ├── volunteer/
│       ├── admin/
│       └── research/
├── routes/
│   ├── web.php
│   └── api.php
├── storage/
│   ├── logs/
│   ├── exports/
│   └── private/
├── scripts/
│   ├── send_reminders.php
│   ├── expire_donations.php
│   └── run_experiments.php
├── tests/
├── docs/
├── .env.example
├── composer.json
└── README.md
```

The agent may refine this structure, but must preserve separation
between controllers, models, services, views, routes and research code.
Uploaded files should not be placed in a publicly executable directory.

------------------------------------------------------------------------

## 7. Suggested JSON API endpoints

  Method   Endpoint                               Purpose
  -------- -------------------------------------- -------------------------
  POST     `/api/auth/register`                   Register
  POST     `/api/auth/login`                      Login
  GET      `/api/donations`                       List donations
  POST     `/api/donations`                       Create donation
  GET      `/api/donations/{id}`                  Donation details
  PUT      `/api/donations/{id}`                  Update donation
  POST     `/api/donations/{id}/withdraw`         Withdraw donation
  GET      `/api/receivers`                       List eligible receivers
  GET      `/api/matching/recommendations/{id}`   TAMRA recommendations
  POST     `/api/matching/accept`                 Accept match
  POST     `/api/pickups`                         Schedule pickup
  POST     `/api/pickups/verify`                  Verify QR token
  POST     `/api/pickups/complete`                Complete pickup
  GET      `/api/notifications`                   Fetch notifications
  GET      `/api/analytics/impact`                Impact statistics
  POST     `/api/research/experiments`            Create experiment
  POST     `/api/research/experiments/run`        Execute experiment
  GET      `/api/research/experiments/{id}`       Experiment results

All endpoints must enforce authentication, authorization, ownership,
validation and appropriate rate limits. Use consistent JSON response
formats and HTTP status codes. Document request and response examples.

------------------------------------------------------------------------

## 8. UI/UX requirements

The application should feel like a modern sustainability technology
product, not a generic administration template.

### Design direction

-   Forest green, emerald and off-white palette.
-   Warm orange for urgency indicators.
-   Inter or Plus Jakarta Sans typography.
-   Clean sidebar navigation.
-   Responsive cards and tables.
-   Clear status badges.
-   Interactive charts and maps.
-   Toast notifications.
-   Skeleton loading states.
-   Useful empty states.
-   Accessible labels, contrast and keyboard navigation.
-   Mobile, tablet and desktop support.

### Required screens

1.  Landing page.
2.  Registration.
3.  Login.
4.  Donor dashboard.
5.  Create donation.
6.  Donation details.
7.  Receiver dashboard.
8.  Matching results.
9.  Pickup management.
10. Notifications.
11. Impact dashboard.
12. Admin dashboard.
13. Self-service organization settings.
14. Research dashboard.
15. Experiment details.
16. Profile settings.

All visible controls must work. Include loading, success, error, empty
and confirmation states. Do not use fake buttons or static mock data in
completed workflows.

------------------------------------------------------------------------

## 9. Research methodology and evaluation

The application is also a research testbed. Keep experiments isolated
from live operational records.

### Research questions to investigate

-   Does multi-factor matching improve successful pickup rates compared
    with nearest-receiver matching?
-   Does deadline-aware prioritization reduce expired or uncollected
    donations?
-   Does pickup-feasibility estimation help identify risky assignments?
-   What trade-offs exist between distance, urgency, capacity
    utilization and successful collection?

### Candidate metrics

  -----------------------------------------------------------------------
  Metric                              Definition
  ----------------------------------- -----------------------------------
  Successful pickup rate              Completed pickups divided by
                                      eligible accepted assignments

  Expired donation rate               Donations expiring uncollected
                                      divided by eligible listed
                                      donations

  Average matching time               Time from matching request to
                                      recommendation

  Average travel distance             Mean estimated distance for
                                      completed pickups

  Capacity utilization                Used receiver capacity relative to
                                      available capacity

  Algorithm execution time            Time to compute recommendations

  Precision / Recall / F1             Classification performance for
                                      pickup prediction

  ROC-AUC                             Ranking discrimination for binary
                                      pickup outcomes

  Brier score                         Probability calibration error
  -----------------------------------------------------------------------

Define denominators and exclusion rules precisely before running
experiments.

### Dataset strategy

Support three clearly labelled data sources:

1.  Simulated donation and receiver records.
2.  Anonymized historical records.
3.  Real pilot data collected with permission.

Every dataset must have metadata describing source, collection period,
record count, preprocessing, missing values, consent/permission status
and limitations.

### Simulation plan

Start with scenarios such as:

-   100 donations.
-   20 receivers.
-   Multiple locations.
-   Different food categories.
-   Different deadlines.
-   Different receiver capacities.
-   Different availability windows.
-   Different transport availability.

These are starting simulation parameters, not a claim about real-world
scale. Allow the researcher to configure scenario sizes and random
seeds.

### Fair comparison requirements

-   Use identical scenario inputs for every algorithm.
-   Apply identical mandatory safety and capacity constraints.
-   Record algorithm version and parameters.
-   Repeat stochastic scenarios with documented seeds.
-   Report averages and variability across runs.
-   Include failure cases and limitations.
-   Do not present simulated outcomes as real-world evidence.
-   Do not fabricate metrics or research findings.

### Research outputs

Allow export of:

-   Experiment configuration.
-   Dataset description.
-   Matching decisions.
-   Performance metrics.
-   Comparison charts.
-   CSV files.
-   PDF experiment reports.

------------------------------------------------------------------------

## 10. Development roadmap

Suggested eight-week schedule for one developer or a small team. Adjust
based on scope and experience.

  -----------------------------------------------------------------------
  Week                    Phase                   Deliverable
  ----------------------- ----------------------- -----------------------
  1                       Foundation              Architecture, schema,
                                                  authentication and
                                                  role-based dashboards

  2                       Donations               Donor workflow, uploads
                                                  and safety information

  3                       Receivers               NGO registration,
                                                  profile setup, capacity
                                                  and discovery

  4                       TAMRA                   Eligibility filtering,
                                                  scoring, ranking and
                                                  explanations

  5                       Prediction and          Feasibility estimates,
                          logistics               scheduling, maps and QR
                                                  verification

  6                       Notifications and       Reminders, impact
                          analytics               metrics and reports

  7                       Research                Baselines, simulator,
                                                  experiments and
                                                  comparison dashboard

  8                       Quality and release     Security, integration
                                                  tests, documentation
                                                  and deployment
  -----------------------------------------------------------------------

### Phase 1 --- Foundation

-   Create project structure.
-   Configure environment variables.
-   Design schema and ER diagram.
-   Create migrations and seed data.
-   Implement authentication.
-   Implement authorization middleware.
-   Build initial responsive layouts.

### Phase 2 --- Donation workflow

-   Implement donor profiles.
-   Implement donation creation and editing.
-   Implement secure image upload.
-   Implement food categories.
-   Implement safety declarations and screening.

### Phase 3 --- Receiver workflow

-   Implement NGO registration and self-service organization setup without admin approval.
-   Implement receiver capacity and preferences.
-   Implement availability.
-   Implement donation discovery and requests.

### Phase 4 --- TAMRA

-   Implement eligibility filtering.
-   Implement normalization and component scores.
-   Implement ranking and explanations.
-   Implement versioned configurations.
-   Add deterministic unit tests.

### Phase 5 --- Pickup and prediction

-   Implement feasibility baseline.
-   Implement scheduling and volunteer assignment.
-   Implement map display and travel estimates where available.
-   Implement QR verification.
-   Implement pickup status transitions and failure handling.

### Phase 6 --- Notifications and analytics

-   Implement in-app notifications.
-   Configure email.
-   Implement idempotent scheduled reminders and expiry jobs.
-   Implement impact calculations and reports.

### Phase 7 --- Research

-   Implement baseline algorithms.
-   Implement configurable simulation.
-   Implement experiment runner.
-   Implement metrics and comparisons.
-   Implement export and experiment history.

### Phase 8 --- Testing and release

-   Complete security and integration testing.
-   Test concurrent allocation.
-   Test scheduled jobs.
-   Fix defects.
-   Complete documentation.
-   Prepare deployment and demo data.

------------------------------------------------------------------------

## 11. Testing requirements

The project is not complete merely because pages load. Implement
automated tests for business rules and critical workflows.

  -----------------------------------------------------------------------
  Test case                           Expected result
  ----------------------------------- -----------------------------------
  Invalid login                       Authentication rejected

  Unauthorized dashboard access       Access denied

  Invalid donation quantity           Validation error

  Incomplete safety information       Donation flagged or held for review

  Safety-ineligible donation          Excluded from matching

  Receiver capacity exceeded          Allocation rejected

  Two receivers accept simultaneously Only one valid allocation succeeds

  Donation deadline passes            Correct expiry transition

  Invalid QR token                    Verification rejected

  Reused QR token                     Duplicate verification rejected

  Pickup completed                    Correct status and quantity
                                      recorded

  Failed pickup                       Failure reason recorded

  TAMRA input                         Expected component scores

  Repeated deterministic experiment   Same results for identical inputs

  Impact calculation                  Only qualifying collected
                                      quantities counted
  -----------------------------------------------------------------------

### Security requirements

-   PDO prepared statements.
-   CSRF protection.
-   Context-aware output escaping against XSS.
-   Server-side validation.
-   Secure password hashing.
-   Session ID regeneration after login.
-   Login rate limiting.
-   Secure file upload validation and storage.
-   Authorization checks on every protected endpoint.
-   No secrets in frontend JavaScript.
-   No sensitive personal information in research exports.
-   Audit logs for important administrative actions.
-   Secure password-reset tokens with expiry and single-use semantics.

------------------------------------------------------------------------

## 12. Final deliverables

### Application

-   Complete PHP backend.
-   Responsive JavaScript frontend.
-   MySQL database and migrations.
-   Five role-based dashboards.
-   Functional donation and receiver workflows.
-   Food safety screening.
-   TAMRA matching engine.
-   Pickup-feasibility component.
-   Pickup scheduling and QR verification.
-   Notifications.
-   Analytics and reports.
-   Research dashboard.

### Research

-   Literature review notes and references.
-   Clearly stated research gap and questions.
-   Algorithm specification.
-   Baseline implementations.
-   Dataset documentation.
-   Reproducible experiment runner.
-   Evaluation metrics.
-   Comparative results.
-   Limitations and future work.

### Documentation and deployment

-   ER diagram.
-   System architecture diagram.
-   API documentation.
-   Database SQL/migration files.
-   Installation instructions.
-   `.env.example`.
-   Test documentation.
-   Demo accounts and sample data.
-   README.
-   Deployment instructions.

------------------------------------------------------------------------

## 13. Master instructions for the coding agent

You are a senior full-stack PHP developer, software architect, database
designer and research-oriented algorithm engineer.

Build the complete FoodRescue application according to this
specification.

### Non-negotiable requirements

1.  Use Core PHP 8.2+ and MySQL 8.
2.  Use PDO prepared statements.
3.  Use Vanilla JavaScript and Fetch API for interactive frontend
    functionality.
4.  Follow a modular MVC-inspired architecture.
5.  Implement all five user roles.
6.  Make every visible form and button functional.
7.  Implement the complete donation lifecycle.
8.  Implement food safety screening and traceability.
9.  Implement TAMRA with explainable component scores.
10. Implement the baseline matching algorithms.
11. Implement pickup feasibility and its evaluation.
12. Implement pickup scheduling and QR verification.
13. Implement notifications and impact analytics.
14. Implement reproducible research experiments.
15. Write automated tests and complete documentation.
16. Never fabricate data, experiment results or research claims.
17. Never let an algorithm override mandatory safety rules.
18. Never leave placeholder functionality in a workflow marked complete.

### Updated delivery authorization

The user has explicitly authorized completion of all remaining phases in one continuous implementation. Continue phase by phase and report results, without waiting for another approval between phases. Donors, NGOs and volunteers require no admin approval. Keep the normal login page and the five default demo credentials. Admin and Researcher tools remain separate from the everyday donation-to-pickup flow.

### Required development workflow

Work phase by phase.

At the end of each phase:

1.  List files created or modified.
2.  Explain functionality implemented.
3.  Provide database changes.
4.  Run relevant tests and report results.
5.  List outstanding issues and known limitations.
6.  Continue to the next phase under the user’s all-phases authorization; report material limitations honestly.

Do not generate the entire application in one enormous response. Create
actual project files, run the application and tests, and fix errors
rather than merely describing code.

### Definition of done

The project is complete only when:

-   All five roles work.
-   Donation creation and redistribution work end to end.
-   Safety screening works.
-   TAMRA produces explainable recommendations.
-   Pickup-feasibility predictions can be evaluated.
-   Pickup scheduling and verification work.
-   Notifications work.
-   Analytics use recorded data.
-   Research experiments are reproducible.
-   Baseline comparisons work.
-   Security and integration tests pass.
-   Documentation is complete.
-   The application can be installed and run locally.

### Start instruction

**The initial Phase 1 foundation is complete. The user has now authorized completion of all operational, research, testing and documentation phases without an intermediate approval pause.**

Continue with the implemented architecture and the updated self-service access policy. Report validation and any remaining external configuration requirements.
