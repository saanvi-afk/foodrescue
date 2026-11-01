# FoodRescue HTTP API

All protected endpoints use the PHP session cookie. JSON responses use `{"ok":true,"data":{...},"csrf":"..."}` or `{"ok":false,"error":{"status":422,"message":"..."}}`. Mutations require `X-CSRF-Token` from the page's `csrf-token` meta tag. Send same-origin credentials and `Content-Type: application/json`; uploads use multipart FormData without manually setting Content-Type. Session rotation returns a new CSRF value.

## Authentication

All authentication routes use POST. Public registration accepts only donor, receiver and volunteer. Account role and activation are server-controlled. Registration activates immediately; neither email confirmation nor admin approval gates operational access. Demo accounts use the normal login endpoint and have confirmed emails.

| Endpoint | Fields | Access |
| --- | --- | --- |
| `/api/auth/register` | name, email, password, role | Public |
| `/api/auth/login` | email, password | Public |
| `/api/auth/logout` | none | Signed in |
| `/api/auth/forgot-password` | email | Public |
| `/api/auth/reset-password` | token, password | Public |
| `/api/auth/verify-email` | token | Public |
| `/api/auth/resend-verification` | none | Signed in |

There is no `/api/auth/demo-login` route. Passwords require 12–72 bytes. Password-reset links expire in one hour; email-confirmation links in 24 hours. Token hashes are stored separately from private delivery messages. Invalid, expired and reused tokens are rejected. Password reset revokes prior sessions.

## Organization and profile

| Method | Endpoint | Fields / behavior | Access |
| --- | --- | --- | --- |
| POST | `/api/organizations/save` | name, address, latitude, longitude, optional contact_phone, registration_number; optional id to edit | Donor/receiver; creator alone can edit |
| POST | `/api/organizations/join` | organization_id | Donor/receiver, matching organization type |
| POST | `/api/receivers/save` | organization_id, capacity_kg, category_ids array, latitude, longitude, max_distance_km, transport_available, collection_available, available_from, available_until | Receiver linked to organization |
| POST | `/api/profile/save` | name, phone, timezone; volunteer also available, transport | Own account |
| POST | `/api/uploads` | multipart kind=donation/document/profile, id where applicable, file | Listing owner / organization creator / own profile |
| GET | `/files/{donation|document|profile}/{id}` | Authenticated attachment | Listing audience / document creator / own photo |

Organization creation immediately links its creator. Linking an existing organization is self-service. Membership grants no admin powers or rights to edit another member's donations. Supporting documents remain accessible only to the organization creator. Uploads accept JPEG/PNG/WebP, plus PDF for documents, up to 5 MB; images must be valid with at most 20 million pixels. Files live outside the public document root.

## Donation and matching

| Method | Endpoint | Fields / behavior | Access |
| --- | --- | --- | --- |
| GET | `/api/donations` | Current public listing summaries plus own listings | Donor/receiver/admin |
| GET | `/api/donations/{id}` | Donation details | Owner, eligible listing audience/participant, admin |
| POST | `/api/donations` | Create or update with optional id | Donor linked to donor organization |
| PUT/POST | `/api/donations/{id}` | Update unclaimed donation | Owner |
| POST | `/api/donations/{id}/withdraw` | No fields | Owner, valid unclaimed state |
| POST | `/api/donations/{id}/relist` | Current safety rules must pass | Owner, failed/cancelled state |
| GET | `/api/receivers` | Receiver matching inputs | Donor/admin |
| GET | `/api/matching/recommendations/{id}` | Ranked components, feasibility and exclusions, immutable configuration snapshot | Owner/receiver/admin |
| POST | `/api/matching/propose` | match_id; notifies eligible receiver without allocation | Donor owner |
| POST | `/api/matching/reject` | match_id; records decision and notifies donor | Receiver candidate |
| POST | `/api/matching/accept` | donation_id; full allocation and capacity reservation | Receiver |

Donation payload example (replace times with current valid ISO timestamps):

```json
{
  "organization_id": "1", "category_id": "2", "name": "Bread",
  "description": "Unused bread prepared for donation", "quantity": "5", "unit": "kg",
  "prepared_at": "2026-11-01T08:00:00Z", "use_by": "2026-11-01T18:00:00Z",
  "deadline": "2026-11-01T16:00:00Z", "address": "Collection kitchen",
  "latitude": "28.61", "longitude": "77.21", "storage_method": "ambient",
  "temperature_c": "25", "packaging": "intact", "ingredients": "Wheat flour, water",
  "allergens": "Wheat", "handling_declared": "1", "unserved": "1", "contamination_free": "1"
}
```

Units: kg, g, portions or items. Portions/items require unit_mass_kg. Storage methods: ambient, chilled, frozen, hot. Packaging: intact, unknown or damaged. Safety checks create available/review/rejected listings. Clients cannot submit arbitrary status changes. Food suitability is rechecked at matching, acceptance, scheduling and collection against current rules; a score never overrides rejection.

## Pickup lifecycle

| POST endpoint | Fields / result | Permission |
| --- | --- | --- |
| `/api/pickups` or `/api/pickups/schedule` | id, scheduled_at, optional volunteer_id; returns token and QR PNG data URI once | Donor or receiver participant |
| `/api/pickups/verify` | id, token, collected_kg | Receiver or assigned volunteer |
| `/api/pickups/complete` | id; receiver also distributed_kg | Donor and receiver each confirm |
| `/api/pickups/distributed` | id, distributed_kg | Receiver after collection |
| `/api/pickups/fail` | id, reason | Donor/receiver/assigned volunteer |
| `/api/pickups/cancel` | id, reason | Donor/receiver |
| `/api/reviews` | id (pickup), rating 1–5, comment | Completed-pickup donor/receiver |

Collection quantity cannot exceed offered kg; distribution cannot exceed collected kg. Active/completed allocation uniqueness is also enforced in MySQL. Allocation and all capacity-changing transitions use transactions and a consistent donation → receiver → pickup locking order. Rescheduling invalidates the old token. Failed/cancelled/expired pickups release capacity; completed pickups release it after both confirmations. Collected food is not expired by the listing-expiry job.

## Notifications, impact and administration

| Method | Endpoint | Access / payload |
| --- | --- | --- |
| GET | `/api/notifications` | Own newest 100 notifications |
| POST | `/api/notifications/read` | Own id |
| GET | `/api/analytics/impact` | Own donor/receiver/volunteer scope; admin aggregate; researcher anonymized aggregates |
| POST | `/api/admin/account` | Admin; id, status active/suspended |
| POST | `/api/admin/category` | Admin; name; optional id and active |
| POST | `/api/admin/safety` | Admin; category_id, require_temperature, optional max_age_minutes/min_temperature/max_temperature, source_url |
| POST | `/api/admin/settings` | Admin; meal_mass_kg |
| POST | `/api/settings/algorithm` | Admin/researcher; version, six weights and speed_kmh/handling_minutes/urgency_horizon_minutes |

Researchers do not receive contributor names or operational coordinates in impact exports. No approval endpoint exists. New safety rules/configurations are versioned rather than overwriting historical scoring inputs.

## Research and exports

| Method | Endpoint | Fields / permission |
| --- | --- | --- |
| POST | `/api/research/datasets` | Researcher; name, donations 1–500, receivers 1–100, seed |
| POST | `/api/research/datasets/import` | Researcher; name, source anonymized/pilot, collection_period, permission_status, limitations, preprocessing, payload JSON string |
| POST | `/api/research/experiments` or `/api/research/experiments/run` | Researcher; name, dataset_id, configuration_id, seed |
| GET | `/api/research/experiments/{id}` | Owner researcher; configuration, dataset snapshot/hash, runs, metrics, decisions, replicate summary |
| GET | `/exports/impact/0.{csv|json|pdf}` | Same scope as own impact |
| GET | `/exports/experiment/{id}.{csv|json|pdf}` | Owner researcher |

Research is isolated from operational allocations. CSV escapes formula prefixes. PDF reports summarize results and include a comparison bar chart; full JSON retains datasets, parameters and decisions. See research.md for the anonymous import schema and metric denominators.

## Status codes

200 success (registration 201); 400 malformed JSON; 401 authentication required/failed; 403 role/ownership denial; 404 missing route/resource; 405 unsupported method; 409 stale state/capacity conflict; 413 oversized body; 419 invalid CSRF; 422 invalid fields, failed eligibility or invalid token; 429 rate limit; 503 unexpected infrastructure failure. Errors never return SQL, secrets or stack traces.

Authentication uses fixed 15-minute identity/IP windows; successful login removes its own attempt from those counters. Operational requests have a separate 240-request window per identity/IP. Configure edge limits and trusted proxies separately for production.
