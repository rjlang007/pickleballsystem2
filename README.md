# Table & Chair Rental Business Management System

Full build per `MASTER-PROMPT-rental-system.md`, covering all 5 phases from Section 9:

- [x] **Phase 1 — Foundation**: schema (`db/schema.sql`), auth + 4 roles (`routes/auth.js`, `middleware/auth.js`), inventory + ownership (`routes/inventory.js`, `routes/owners.js`)
- [x] **Phase 2 — Core booking loop**: booking engine + calendar/availability + manual approval (`routes/bookings.js`), earnings split (`services/earnings.js`), payments (`routes/payments.js`)
- [x] **Phase 3 — Operations**: delivery staff scheduling (`routes/staff.js`), real-time layer (`websocket.js`), chat (`routes/chat.js`)
- [x] **Phase 4 — Customer-facing & growth**: landing page (`public/`), notifications (`services/notify.js`), loyalty/vouchers/referrals/reviews (`routes/loyalty.js`, `services/loyalty.js`)
- [x] **Phase 5 — Business intelligence**: expense tracking + delivery zones (`routes/settings.js`), reports (`routes/reports.js`), audit log (built into every write endpoint), automated backups (`scripts/backup.sh`, `scripts/restore-test.sh`), security hardening pass (`SECURITY.md`)
- [x] **Section 8 — T&Cs e-signature step**: live-rendered terms from admin-editable settings, server-enforced acceptance gate on every customer booking, exact wording snapshotted onto the booking row

## Setup

1. Provision PostgreSQL (Supabase, Railway, or Neon — per Section 6, cheaper/less maintenance than self-hosting, and keeps you off a business-registered hosting footprint).
2. `cp .env.example .env` and fill in `DATABASE_URL` and a random `JWT_SECRET` (`openssl rand -hex 32`).
3. Install and load the schema:
   ```bash
   npm install
   npm run db:setup      # runs db/schema.sql then db/seed.sql
   npm run dev            # API + WebSocket + landing page on http://localhost:3000
   ```
4. Bootstrap your own admin account directly in the DB (the `/auth/users` endpoint needs an existing admin token, so the very first admin has to be inserted manually):
   ```bash
   node -e "require('bcrypt').hash('yourpassword', 12).then(console.log)"
   ```
   ```sql
   INSERT INTO users (role, email, password_hash) VALUES ('admin', 'you@example.com', '<bcrypt hash>');
   ```
   Log in via `POST /auth/login`, then use that token to create Mother's, Wife's, staff, and any other accounts through `POST /auth/users`. Customers self-register through `POST /auth/register`.

### Migrating an existing database (already ran `db:setup` before)

`npm run db:setup` only applies `schema.sql` on a fresh database — it won't
retroactively add new columns to a database that's already running. If
you're upgrading an existing deployment to pick up the delivery-map feature
(`delivery_lat`/`delivery_lng` on `bookings`), run this once against your
live DB:

```sql
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS delivery_lat NUMERIC;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS delivery_lng NUMERIC;
```

Existing bookings will just have `NULL` coordinates (no pin on the map,
address text still shows) until they're re-booked or you backfill them
manually — nothing else about them changes, and no existing totals/records
are touched.

## Defaults applied (all editable, nothing is locked in)

To get this running today, every open number from Section 10 was given a sensible
placeholder default. **None of these require touching code to change** — each one
is a live `PATCH`/edit away, any time:

| Item | Default | How to change |
|---|---|---|
| 6ft table (Lifetime foldable) rate | ₱225 *(placeholder — set your real rate)* | `PATCH /inventory/item-types/:id` |
| 4ft table (Lifetime foldable) rate | ₱165 *(placeholder — set your real rate)* | `PATCH /inventory/item-types/:id` |
| Monoblock chair rate | ₱12 *(placeholder — set your real rate)* | `PATCH /inventory/item-types/:id` |
| Tablecloth / skirting / table cover rates | ₱30 / ₱80 / ₱30 *(placeholders)* | `PATCH /settings/addons/:id` |
| Starting stock | 4× 6ft table, 2× 4ft table, 30 chairs | `POST /inventory/batches` for more |
| Investment % (Randall/Mother/Wife) | 33.33% / 33.33% / 33.34% — **placeholder equal split, replace with your real capital shares** | `PATCH /owners/:id` |
| Management fee % (Randall, for running the business) | 20% | `PATCH /settings/business` |
| Deposit % | 50%, **delivery bookings only**, requested only after Randall accepts the order | `PATCH /settings/business` |
| Damage deposit | None collected | — |
| Late return fee | ₱100/hour *(kept by Randall as personal income)* | `PATCH /settings/business` |
| Cleaning fee | ₱100/item *(kept by Randall as personal income)* | `PATCH /settings/business` |
| Cancellation window | Full refund if cancelled 1+ day before event/delivery; no refund inside 1 day | `PATCH /settings/business` |
| Delivery zones (Poblacion, Polomolok) | 0–2.8km (Brgy. Poblacion/Cannery): ₱50 · 2.8–3.5km: ₱70 · 3.5–5km: ₱100 · beyond 5km: pickup only | `POST`/`PATCH /settings/delivery-zones` |
| Pickup | Always free, any distance | — |
| Business coordinates (`BUSINESS_LAT`/`BUSINESS_LNG`) | Poblacion, Polomolok, South Cotabato (≈6.2196, 125.0693) | Edit `.env` directly, then restart the server |
| `JWT_SECRET` | Real random value, already generated — safe to use as-is | No action needed |
| SMTP / SMS credentials | Left blank — currently logs instead of sending | Edit `.env` once you have a provider account |

`.env` ships with a freshly generated `JWT_SECRET` (safe to use as-is) but a
placeholder `DATABASE_URL` — fill in your real Postgres user/password/host
before running anything. **A previous copy of this project had a real DB
password and JWT secret checked into `.env`; if you ever deployed from that
copy, rotate both credentials — don't just overwrite the file.**

## Admin Console

A full admin dashboard now lives at `/admin` (or `/admin.html`), covering
every screen in Section M's Admin sitemap: Dashboard, Bookings (list +
detail drawer with approve/reject, staff assignment, ID collateral, damage
logging), Inventory (item types, batches, units), Customers (risk flags +
internal notes), Delivery Staff (rates, schedules, payouts), Earnings
(per-owner breakdown + mark-paid), Expenses, Reports, Chat Inbox, and
Settings (deposit/fee/refund rules, delivery zones, add-ons). It's a
separate login from the customer-facing site — only `role: 'admin'`
accounts can get past the login screen; log in with the admin account you
bootstrapped in Setup step 4. Real-time updates (new bookings, chat, job
status) arrive over the same WebSocket layer as the customer site.

## Module map

| Area | File(s) | Notes |
|---|---|---|
| Schema | `db/schema.sql`, `db/seed.sql` | All 22 tables from Section 7, indexed per Section 6. Seeded with You/Mother/Wife/Business owners and your actual starting item costs/rates. |
| Auth & roles | `routes/auth.js`, `middleware/auth.js`, `utils/auth.js` | bcrypt + short-lived JWT + revocable refresh tokens in `sessions`, login rate-limiting, server-side RBAC on every route (Section 6/L). |
| Inventory & ownership | `routes/inventory.js`, `routes/owners.js` | Batches → auto-generated trackable units, availability formula (5A), per-owner earnings dashboard with running totals + pending payouts. |
| Bookings | `routes/bookings.js` | Full status lifecycle (`inquiry → pending_review → confirmed/rejected → ongoing → completed/cancelled`), price calc (5B), crew suggestion (5C), blocked-customer check (5F), server-revalidated availability on submit. `PATCH /:id/status` enforces valid transitions server-side (`confirmed→ongoing→completed`, `confirmed/ongoing→cancelled`) — a booking that was never approved cannot be pushed straight to `completed` and cannot fire the earnings split twice.
| Earnings | `services/earnings.js` | Algorithm 5D v2 — every completed booking's revenue minus its operational costs (delivery/labor + logged fuel/maintenance/travel expenses) is one shared profit pool. Randall (`runs_business = true`) takes a flat management fee off the top; what's left is split among all owners by `investment_percent` — by how much capital each person put in, not by whose stock fulfilled the order. Late/cleaning fees are written separately as Randall's own personal income, never split. Fires automatically when a booking is marked `completed`. |
| Payments | `routes/payments.js` | GCash/Maya/bank transfer tracking, proof-of-payment upload (via `multer`, stored outside the public web root, served only through an authenticated route), partial payments, refund status. |
| Delivery staff | `routes/staff.js` | Schedules, job assignment, pay-per-job, status buttons (`en_route/delivered/picked_up`), ID-held/returned checkboxes, own-payout view. |
| Real-time | `websocket.js`, `services/notify.js` | Socket.IO rooms for admin dashboard, per-date calendar, per-customer chat, per-staff job feed, per-user notifications. Falls back to polling automatically on flaky connections (Section 6). |
| Chat | `routes/chat.js` | Threaded per-customer, admin sees all threads, live via WebSocket. |
| Loyalty & growth | `routes/loyalty.js`, `services/loyalty.js` | Auto-counts completions, triggers vouchers at thresholds (5E), referral rewards, post-booking reviews. |
| Reports & expenses | `routes/reports.js`, `routes/settings.js` | Revenue per owner, most-rented items, busiest months, retention, expense-vs-profit, delivery zone/fee config. |
| T&Cs (Section 8) | `routes/settings.js` (`business_settings`, `/settings/terms`), `routes/bookings.js` | Deposit %, damage deposit, fees, and refund windows are admin-editable; the terms text renders live from them and a customer's exact acceptance is snapshotted onto their booking. |
| Backups | `scripts/backup.sh`, `scripts/restore-test.sh` | Daily `pg_dump` + gzip + 30-day retention, with a restore-verification script per Section 6's "test restoring a backup before going live." |
| Security | `SECURITY.md`, `server.js` (rate limiting, HTTPS redirect) | Full hardening checklist against Section 6, with what's automated vs. what still needs a manual step from you (real `JWT_SECRET`, first restore test, admin 2FA). |
| Landing page | `public/index.html`, `app.js`, `style.css` | Public browse + live availability + booking + chat widget + account area — plain HTML/JS talking to the API (no build step needed). Has its own visual identity, distinct from the admin/owner/delivery portals — grounded in the actual props of a PH backyard rental (monobloc chair red, banig-mat green, bunting, tent canvas), Baloo 2 + Work Sans type, an original hero illustration in SVG. |
| Admin / Owner / Delivery portals | `public/admin.*`, `owner.*`, `delivery.*` | Each has its own deliberate visual identity: Admin reads as a kraft-tag ledger (paper tones, Fraunces serif, JetBrains Mono for figures), Owner as a personal passbook (blush paper, plum accent), Delivery as a hi-vis work tool built for a phone screen in direct sunlight (navy/amber, big tap targets, bottom tab bar). |
| Delivery staff map | `public/delivery.html`, `delivery.js`, `routes/bookings.js`, `routes/staff.js` | "Map" tab in the delivery portal — plots all of a staff member's upcoming delivery stops (who + where) as pins on a self-hosted Leaflet + OpenStreetMap map. Coordinates are geocoded once via Nominatim at booking time and stored on the booking, never re-geocoded live. No routing/live GPS — each pin deep-links out to Google Maps directions instead. Status is still entered manually by the staff member (`en_route` → `delivered`/`picked_up` → `completed`), same as before. |
| Audit trail | `audit_log` table, written from every admin mutation | Who approved/rejected, who changed a price, who marked something damaged. |

## Deliberately not automated (needs your own provider keys)

- **SMS/email/Messenger sending** — `services/notify.js` now actually dispatches: email via SMTP (`nodemailer`) and SMS via a generic provider webhook, both fully wired into booking confirmation/rejection (`routes/bookings.js`) and staff job assignment (`routes/staff.js`), plus a daily 8 AM cron job (`services/reminders.js`) for day-before, return-due, and overdue alerts. **You still need to supply real credentials** (`SMTP_HOST`/`SMTP_USER`/`SMTP_PASS` and `SMS_API_URL`/`SMS_API_KEY` in `.env`) — until then it logs what it would have sent instead of failing, so bookings never break because a notification provider isn't set up yet.
- **Delivery zone/fee calculator by address** — built and working: `GET /settings/delivery-quote?address=...` geocodes the address via OpenStreetMap's Nominatim (free, no key needed) and computes straight-line distance from your shop (`BUSINESS_LAT`/`BUSINESS_LNG` in `.env`) to price against your delivery zones. Straight-line distance approximates actual driving distance — fine for zone-tier pricing, not turn-by-turn routing. **Note:** this still couldn't be exercised against the live Nominatim service — not from the build sandbox (network locked to package registries) and not from the general web tool either (Nominatim's `robots.txt` disallows automated fetches to the API endpoint, so it can't be reached from an automated context at all, ours or anyone else's). Cross-checked the response shape (`lat`/`lon`/`display_name` fields) against Nominatim's published docs and the code matches exactly — but a real live call still needs to happen once from your deployed server (a normal server-side `fetch`, not a scraping bot, so it isn't affected by the same block) before you trust it in production.
- **PayMongo / payment gateway** — payments are tracked manually (GCash/Maya/bank transfer + proof upload) per Section 1; swapping in PayMongo later is additive, not a rewrite.
- **Live GPS tracking** — explicitly marked "optional, later-stage" in Section 4; not built.

## Changelog

- **Added:** Account-management feature — admin-only creation of Owner, Delivery Staff, and
  Customer accounts, plus change-password for every account type.
  - `POST /auth/accounts` (admin only) — creates the business record (owner/staff/customer
    profile) and its login together in one transaction, so "add a new delivery crew member" is
    one admin action instead of two separate ones (create the staff profile, then create the
    login and link it). Existing `POST /owners`, `POST /staff`, and `POST /auth/users` are
    untouched and still work independently.
  - `GET /auth/users` (admin only) — lists every login account with its role and the linked
    owner/staff/customer's name resolved, for the new admin "User Accounts" screen.
  - `PATCH /auth/users/:id/deactivate` / `.../reactivate` (admin only) — revokes or restores a
    login without touching the underlying business record; deactivating also kills that user's
    active sessions. An admin can't deactivate their own account (avoids a self-lockout).
  - `PATCH /auth/password` already existed and was already wired into `owner.js`/`delivery.js`;
    it's now also wired into the customer app (`app.js`, in the Account tab) and the admin panel
    (`admin.js`, Settings → User Accounts), so every one of the 4 account types can change its
    own password from its own UI.
  - New "User Accounts" tab under admin Settings: change-your-own-password, a create-account
    form (fields adjust to the selected account type), and a table of existing accounts with
    deactivate/reactivate.
- **Fixed:** `services/notification-triggers.js` existed (booking status, payment-received,
  damage-charge, and ID-returned customer emails/SMS) but was never `require`d anywhere —
  none of it ever ran. Wired it into `routes/bookings.js` (payment submission, the
  ongoing/completed status transitions, and ID-collateral return) and `routes/damage.js`
  (damage charge assessed). `confirmed`/`rejected` were already covered separately via
  `notifyCustomer` in `PATCH /bookings/:id/review`, so those two are left as-is to avoid a
  double notification. All sends remain best-effort — a failed email/SMS never blocks the
  underlying booking/payment/damage action.
- **Fixed:** `db/seed.sql` had no protection against being run twice — none of the tables it
  inserts into (`owners`, `item_types`, `addons`, `delivery_zones`, `inventory_batches`,
  `inventory_units`) have a `UNIQUE` constraint on name, so a second run wouldn't error, it
  would silently double every row (duplicate owners, doubled stock counts, etc.) with no
  warning. Added `WHERE NOT EXISTS` guards so it's now safe to re-run.
- **Added:** `scripts/bootstrap-admin.js` — creates (or resets the password of) the first
  admin account in a single command instead of the 3 separate manual steps (hash password,
  hand-craft an `INSERT`, verify it worked). Prompts for email/password if you don't pass
  them as arguments, so the password never lands in PowerShell history.
- **Added:** `scripts/restore-test.js` — Windows/PowerShell-native counterpart to
  `scripts/restore-test.sh` (which needs bash/WSL/Git Bash and won't run in plain
  PowerShell), matching the approach `scripts/backup.js` already used for backups. Restores
  the latest backup into `TEST_DATABASE_URL` and refuses to run if that's ever equal to
  `DATABASE_URL`.
- **Added:** Event-day weather forecast + advisory (`services/weather.js`, `routes/weather.js`,
  `GET /weather/forecast?lat=&lng=&date=`) — new feature, not in the original build. Uses
  Open-Meteo (free, no API key) to show rain/wind/heat risk for outdoor table/chair/tent setups.
  Surfaced on the customer booking form (event-date picker) and in the admin booking drawer.
  Only meaningful within ~16 days of the event; degrades gracefully (never blocks booking) if the
  forecast provider is unreachable.
- **Fixed:** `services/receipt.js` was an accidental duplicate of `services/quotation.js` — it did
  not export `renderReceiptHtml`, even though `routes/quotation.js` already imported and called it.
  The `/quotation/receipt-html` endpoint would have crashed on first real use. Rewritten as an
  actual printable HTML quotation/receipt renderer (browser "Print → Save as PDF" gives a PDF, no
  extra dependency needed).
- **Fixed:** `routes/quotation.js`, `routes/dashboard.js`, and `routes/availability.js` (previously
  shipped as unmounted `*.example.js` files) were never wired into `server.js` — so
  `/quotation/preview`, `/quotation/official`, `/quotation/receipt-html`, `/dashboard/admin`,
  `/dashboard/owner/:id`, `/dashboard/staff/:id`, and `/availability/:itemTypeId` did not actually
  exist despite being documented in this README's module map. Promoted to real routes and mounted.
  (`routes/availability.example.js`'s duplicate `POST /bookings` handler was intentionally dropped —
  `routes/bookings.js` already owns booking creation.)
- **Fixed:** `GET /bookings/:id` returned a booking's items and assigned staff but not its add-ons
  (`booking_addons` — tablecloths/skirting/etc.), even though they're charged and stored. A
  reconstructed receipt/quotation for an existing booking was incomplete without this.
- **Added:** Customer-facing quotation/receipt access — a "View quotation breakdown" button while
  building a booking, and a "View quotation" / "View receipt" button on each booking in "My
  Bookings" (`public/app.js`). Admin gets a matching "Print quotation" / "Print receipt" button in
  the booking drawer (`public/admin.js`). Receipts for *existing* bookings are rendered from the
  booking's own stored numbers (not recalculated against current rates), so they always reflect
  what was actually charged.
- **Changed:** `GET /health` now checks the actual DB connection (previously always returned
  `{status:'ok'}` even if the database was down) — matches the DB-aware version that was already
  written but unmounted as `routes/health.example.js`.

- **Added:** Delivery staff "Map" tab — plots all upcoming delivery stops (who + where)
  on a self-hosted Leaflet + OpenStreetMap map. Bookings are now geocoded once at
  creation time (before the DB transaction opens, so an external HTTP call never holds
  a row lock) and the coordinates are stored on the booking, not re-fetched live.
  Turn-by-turn routing was deliberately not built — each pin deep-links to Google Maps
  directions instead. See "Migrating an existing database" above if you're upgrading
  an existing deployment.
- **Fixed:** Owner accounts (Mother/Wife) had two write paths that shouldn't have
  existed for a monitor-only role — `POST /inventory/batches` accepted `owner` and let
  them add their own inventory, and `PATCH /owners/:id` let them edit their own
  `contact_info`. Both are now admin-only; owners are GET-only everywhere in the system.
- **Fixed:** Addon pricing (`routes/settings.js`) allowed the `owner` role to set a
  `rate` via `POST /settings/addons`, and there was no `PATCH /settings/addons/:id` at
  all despite the README's defaults table implying one existed. Both are now
  admin-only, matching how item-type rates and business_settings already worked, with
  the same audit-log pattern.
- **Fixed:** `/auth/login`'s error handler returned `err.message` directly to the client
  on any unexpected failure (e.g. a DB hiccup) — the one place in the codebase that
  contradicted its own "never leak internals to the client" rule (every other route's
  error handler, and the global one in `server.js`, already withheld this). Removed
  the leaked detail; the server-side `console.error` log is untouched.
- **Fixed:** password strength (minimum 8 characters) was only enforced on the
  password-*change* endpoint — public self-registration (`/auth/register`) and
  admin-created accounts (`/auth/users`) accepted any non-empty password. Added the
  same 8-character minimum to both, tested against real requests (weak password →
  400, strong password → 201, confirmed for both registration and admin-created
  accounts).
- **Fixed:** `websocket.js` had its own hardcoded wildcard CORS (`origin: '*'`),
  separate from and inconsistent with the HTTP API's CORS handling. Wired it to the
  same `CORS_ORIGIN` env var added earlier, so setting one value now locks down both
  the REST API and the real-time layer together.
- Reviewed the WebSocket auth layer (`websocket.js`) — JWT required on connect, rooms
  scoped correctly by role and linked owner/staff/customer id, and the one client-
  triggered room join (`watch_chat`) is admin-gated. No issues found.
- **Fixed:** two spots in `public/app.js` rendered the signed-in customer's own
  name/email into the page via template-string interpolation without escaping
  (`escapeHtml()` already existed and is used everywhere else in that file — these
  two calls were just missing it). Low real-world severity (self-XSS at worst, since
  every other page that displays customer names to other users — the admin customer
  table, chat, review notes — was already confirmed properly escaped), but worth
  closing given the access token lives in `localStorage`, where any successful XSS
  becomes a full session takeover. Fixed by wrapping both in `escapeHtml()`.
- **Fixed:** CORS was wide open (`app.use(cors())`, reflects any origin) with no way
  to restrict it. Added a `CORS_ORIGIN` env var (comma-separated allow-list) — unset
  it behaves exactly as before for local dev, set it once deployed to stop arbitrary
  third-party sites from calling the API. Verified both the default permissive
  behavior and the restricted behavior directly against a running server.
- **Fixed:** `.env` was missing `SMS_API_URL`/`SMS_API_KEY`/`SMS_SENDER_NAME`,
  `GEOCODER_USER_AGENT`, and `DISABLE_CRON` — present in `.env.example` and read by
  `services/notify.js`/`utils/geocode.js`/`services/reminders.js`, but silently absent
  from the real `.env`. Code degrades gracefully without them (SMS/geocoding just log
  instead of failing), but added them with clear placeholders so nothing is
  accidentally skipped once you do have real credentials — in particular,
  Nominatim's geocoding API requires a real contact email in its User-Agent per their
  usage policy, and the placeholder default won't satisfy that in production.
- Audited every route/service file for SQL injection risk (all dynamic `WHERE`
  clauses use parameterized `$1, $2...` placeholders — no string-concatenated user
  input reaches a query), ran `node --check` across every `.js` file in the project
  (all pass), and reviewed the authenticated file-download route in
  `routes/payments.js` (filename regex validation + path-traversal guard + per-booking
  ownership check — all present and correct). No issues found in any of these.
- **Fixed (critical):** `db/schema.sql` — the file `npm run db:setup` actually runs — was
  a stale pre-migration copy missing several columns that `services/earnings.js`
  and `routes/owners.js` query directly. A fresh install following this README's
  own setup steps would create a database missing columns, and the first completed
  booking would throw a `column does not exist` error instead of calculating payouts.
  `db/schema.sql` now contains all required columns natively; the standalone
  `phase1_migration.sql` and `phase1b_migration.sql` files (and the duplicate
  root-level `schema.sql`) have been removed since their changes are now baked into
  the one canonical schema. Verified by loading the merged schema into a real
  PostgreSQL 16 instance, running the full booking → complete → earnings-split flow
  through `services/earnings.js` directly, and confirming the correct rows
  (investment-share per owner + personal-fee-income for Randall, where applicable)
  were written.
- **Changed (per owner request):** the earnings split moved from "credit whoever's
  purchase batch fulfilled the order, plus a flat service fee credited to the primary
  owner" to a pure investment-percentage model: every booking's shared profit pool
  (after Randall's flat management fee) is split among all owners by
  `owners.investment_percent`, regardless of whose stock was rented. `db/schema.sql`,
  `services/earnings.js`, `routes/owners.js`, `routes/settings.js`,
  `routes/reports.js`, and both dashboards were updated together so nothing reads a
  stale field name. `db/seed.sql` seeds a placeholder equal 3-way split
  (33.33/33.33/33.34%) — **replace this with each owner's real investment share**
  via `PATCH /owners/:id` before accepting real bookings.
- **Fixed:** `.env`'s `DATABASE_URL` contained a real-looking database password and
  `JWT_SECRET` a real generated secret — both have been replaced (fresh `JWT_SECRET`,
  placeholder `DATABASE_URL`). Also added a `.gitignore` (previously missing entirely),
  excluding `.env`, `node_modules/`, and `uploads/*` from version control.
- **Fixed:** 7 dependency vulnerabilities flagged by `npm audit` (1 critical — `tar`,
  via `bcrypt`'s installer chain; 4 high — `nodemailer` SMTP/SSRF issues; 2 moderate —
  `uuid` via `node-cron`), by upgrading `bcrypt` (5→6), `nodemailer` (6→9), and
  `node-cron` (3→4). `npm audit` now reports 0 vulnerabilities. All three upgrades were
  smoke-tested directly (`bcrypt.hash`/`compare` round-trip, `node-cron` schedule
  validation, `nodemailer.createTransport`) and the full server was started end-to-end
  against a real Postgres instance — admin login, JWT issuance, an authenticated
  request, and a 401 on a missing token all verified working.
- Removed leftover dev artifacts (`Sample.zip`) that had no place in a deploy package.
- **Fixed:** `bookings.labor_fee` was hardcoded to `0` at booking creation and never
  updated when staff were actually assigned (`routes/staff.js`'s `/assign` endpoint
  only wrote to `booking_staff.pay_amount`, never rolled it up to the booking row).
  This meant the earnings split was silently treating labor cost as `$0` on every
  completed booking, even when real crew pay had been assigned — inflating the
  shared profit pool everyone's investment share is calculated from.
  `/staff/assign` now recomputes `bookings.labor_fee` (and `total_due`) from the
  sum of all assigned staff pay for that booking inside a transaction, every time a
  staff assignment is created or updated. Verified end-to-end against a real
  Postgres instance: a booking with real crew pay assigned now correctly reduces
  the adjusted gross (and therefore every owner's investment share) by that amount.

## Open items carried over from the master prompt (Section 10)

Still unresolved in the data, on purpose — fill these in once you have real numbers:
- Table/chair/tablecloth rates are seeded as placeholders in `db/seed.sql` — set your real rates via `PATCH /inventory/item-types/:id` and `PATCH /settings/addons/:id`.
- **Investment % is seeded as a placeholder equal 3-way split (33.33/33.33/33.34%)** — replace with each owner's real share of total capital invested via `PATCH /owners/:id` before accepting real bookings; this drives every future payout.
- Management fee % (Randall's cut for running the business) is seeded at 20% — adjust via `PATCH /settings/business`.
- Deposit %, delivery zone fees, and the cancellation cutoff aren't hardcoded anywhere — they're admin-configurable via `routes/settings.js`, exactly so you can finalize them without a code change.
- Whether Mother/Wife get their own login: the system supports it (that's what the `owner` role is), you just need to decide whether to actually hand out the credentials.

## Automated tests

`npm test` runs a Jest unit suite focused on the two places a silent bug
costs real money: the booking status-transition rules and the earnings-
split algorithm.

- **`utils/bookingStatus.js`** — the confirmed→ongoing→completed/cancelled
  transition rules used to live only inline inside `routes/bookings.js`.
  They've been pulled out into their own module so they're testable without
  spinning up Express or Postgres, and `routes/bookings.js` now imports
  from it instead of keeping its own copy. `tests/unit/bookingStatus.test.js`
  covers every allowed transition and, more importantly, every transition
  that must be *blocked* — e.g. a booking that was never approved reaching
  `completed` directly, or `completed` firing the earnings split a second
  time.
- **`services/earnings.js`** (Algorithm 5D v2) — `tests/unit/earnings.test.js`
  exercises the actual payout math against a mocked Postgres client (no
  live database needed to run these): operational costs (delivery/labor +
  logged expenses) reducing the shared pool before any split, the
  management fee coming off the top for the `runs_business` owner, the
  investment-percentage split across an uneven 3-way ownership split, the
  "no owner flagged `runs_business`" skip-not-guess behavior, late/cleaning
  fees landing in a separate personal-income row that never touches the
  shared pool, the documented 20% fallback when `business_settings` has no
  row, and that every rounded monetary field stays at exactly 2 decimal
  places even with inputs that trigger classic floating-point drift (e.g.
  `0.1 * 3`).

Run it with:
```bash
npm test          # single run
npm run test:watch # re-runs on file change
```
28 tests, all passing as of this addition. This covers the money-critical
logic layer; it doesn't replace the manual end-to-end smoke test described
below, or add integration/route-level tests against a real database — both
are good next additions if this grows further.

## PWA (installable, mobile-first customer landing page)

The customer-facing landing page (`public/index.html`, `app.js`,
`style.css`) is now an installable Progressive Web App:

- **`public/manifest.json`** — name, brand colors (`#0C3B2A` ivy green /
  `#F7F8F5` court white, matching `style.css`'s own `:root` variables),
  and icon set, so "Add to Home Screen" produces a real app-like icon
  instead of a generic browser bookmark.
- **`public/icons/`** — a brand-matched icon set generated directly from
  the site's own palette (192/512px standard, 192/512px maskable for
  Android's adaptive-icon masking, and a 180px Apple touch icon).
- **`public/sw.js`** — a deliberately conservative service worker.
  Because this app moves real money and real bookings, it **never**
  caches anything under `/auth`, `/bookings`, `/inventory`, `/payments`,
  `/chat`, `/socket.io`, or any other live-data route — those always hit
  the network, exactly as if no service worker were installed. It only
  caches the static app shell (HTML/CSS/JS/icons), so returning visitors
  get an instant load, and the site is technically installable.
- **`public/offline.html`** — a small, fully self-contained fallback page
  (no external font/script requests, so it can't itself fail to render
  offline) shown for a full-page navigation when there's genuinely no
  connection — explains that live pricing/availability needs a connection,
  with a retry button.
- Registration lives in `public/app.js` (not an inline `<script>` in
  `index.html`) because the default `helmet()` CSP already in `server.js`
  blocks inline scripts — this keeps it same-origin and CSP-clean without
  loosening the security policy.

The admin, owner, and delivery portals (`public/admin.*`, `owner.*`,
`delivery.*`) are deliberately **not** part of this PWA scope — they're
staff tools typically used on a desktop or a managed device, whereas the
landing page is where actual customer mobile traffic lives.

## Running it day-to-day

Once deployed, the practical tip from Section 9 still applies: test the full loop — request → payment → approval → delivery assignment → ID collateral → completion → earnings split — with your own account and one fake customer/staff member before pointing real customers at it.
