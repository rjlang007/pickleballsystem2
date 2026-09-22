# Padol Pickleball Court — Management System

A PHP/PostgreSQL web application for running a pickleball court facility:
player wallets & top-ups, court reservations, open-play queues, tournaments,
a food/drinks ordering module, staff/referee/admin/superadmin portals, and
an in-house payment + QR-scanning workflow.

> **Note:** this README replaces a previous version that described an
> unrelated table-and-chair rental project (a leftover from a different
> template). Everything below reflects the actual codebase in this repo.

## Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8+ (plain PHP, no framework) |
| Database | PostgreSQL 13+ |
| Web server | Nginx (reverse proxy) + PHP-FPM |
| Frontend | HTML/CSS/JavaScript, mobile-responsive |
| Hosting | Docker / Railway (see `Dockerfile`, `docker-compose.yml`, `railway.json`, `nixpacks.toml`) |
| Optional integrations | Cloudinary (image hosting), PayMongo (payment webhook), SMTP email |

## Project structure

| Folder | Purpose |
|---|---|
| `auth/` | Login, registration, password reset, email verification |
| `player/` | Player dashboard: bookings, wallet/top-up, food ordering, QR code, rankings, notifications |
| `admin/` | Admin console: courts, players, staff, payments, tournaments, food menu, reports, kiosk |
| `staff/` | Staff tools: open-play control, kiosk, queue, raffles |
| `referee/` | Match scoring |
| `superadmin/` | Superadmin: user management, impersonation, system health, activity monitor |
| `court/` | Court-facing scanner/kiosk screens |
| `api/` | JSON API endpoints (bookings, wallet, tournaments, chat, food, webhooks, etc.) |
| `tournament/` | Tournament/open-play/bracket/scoring engines |
| `config/` | App bootstrap (`app.php`), security bootstrap (`security.php`), DB connection, error handling |
| `includes/` | Shared PHP includes/helpers |
| `migrations/` | Ordered SQL migrations (run against PostgreSQL — see `GUIDE.md` for the exact order) |
| `mobile/` | Mobile-specific endpoints |
| `leaderboard/` | Public leaderboard pages |
| `scripts/` | Backup/restore and maintenance scripts |
| `Uploads/` | User-uploaded files (avatars, payment proofs, QR codes, food photos) |
| `docs/` | Client-facing documentation package (owner manual, player manual, architecture, UAT sign-off, etc.) |

## Getting started (local development)

1. Install PHP 8+, PostgreSQL 13+, and a web server (or use the included
   Docker setup).
2. Copy the environment template and fill in real values:
   ```bash
   cp .env.example .env
   ```
   Key variables: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
   (or `DATABASE_URL`), `APP_URL`, `APP_ENV`, `SMTP_*` (email), and
   `ALERT_WEBHOOK_URL` (optional error alerting).
3. Create the database and run the migrations in `migrations/` **in order**.
   See `GUIDE.md` for the exact file order and the one migration to skip
   (`2026_05_05_create_tournament_tables.sql` — unused MySQL-syntax leftover).
4. Serve the project root as your web root (e.g.
   `http://localhost/System/pickleball/pickleball-fixed/` if using XAMPP) —
   always start from that base URL rather than a subfolder, or routes will
   404.
5. Promote your first user to admin directly in the database:
   ```sql
   UPDATE falcon.users SET role='admin' WHERE username='your_username';
   ```
   New self-registered accounts default to the `player` role.

For Docker/Railway deployment, see `Dockerfile`, `docker-compose.yml`,
`railway.json`, `entrypoint.sh`, and `start.sh`.

### Railway PostgreSQL connection

The web service must be connected to the Railway PostgreSQL service. In the
web service's Variables tab, add Railway references for either `DATABASE_URL`
or all of `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, and `PGPASSWORD` from the
PostgreSQL service. Do not set `DB_HOST` to `localhost` in production; that
points back to the web container, not PostgreSQL. The container now stops with
an explicit configuration error when production starts without a real database
host.

## Core features

- **Player accounts & wallet** — registration with email verification,
  GCash/bank top-ups with proof-of-payment upload, QR code for in-person
  scanning.
- **Court reservations & open play** — booking flow plus a walk-in queue
  system with auto-start once enough players join.
- **Tournaments** — bracket generation, scoring, and an open-play
  scheduler/engine (`tournament/`).
- **Food & drinks ordering** — players order from a menu (pickup or
  court delivery, wallet or cash), staff run a live order queue with
  stuck-order alerts, admin manages the menu and categories.
- **Staff/referee/admin/superadmin roles** — separate portals with
  role-based access control, audit logging, and (for superadmin) user
  impersonation and system health monitoring.
- **Payments** — PayMongo webhook integration plus manual payment
  verification (GCash/bank transfer screenshots).
- **Notifications & alerts** — email via SMTP, plus optional webhook
  alerting (Slack/Discord/Teams-compatible) on server errors.

## Documentation

More detailed docs live in `docs/` and the root of the repo:

- `GUIDE.md` — local setup notes, accounts, migration order, and a log of
  fixes already applied to this codebase.
- `docs/OWNERS_MANUAL.md` — non-technical guide for the facility owner.
- `docs/PLAYER_MANUAL.md` — guide for players.
- `docs/SYSTEM_ARCHITECTURE.md` — technical reference (schema, API,
  security, deployment).
- `docs/MAINTENANCE_GUIDE.md`, `docs/BACKUPS.md`, `docs/ALERTING.md` —
  operational runbooks.
- `SECURITY_HARDENING_REPORT.md`, `SECRETS_ROTATION_PLAN.md` — security
  review notes and credential-rotation guidance.
- `EMAIL_VERIFICATION_CHANGELOG.md` — history of the email verification
  feature.

## Known housekeeping items

- The `migrations/` folder has grown organically (numbered migrations plus
  several `v2_`/`v3_`/patch files) — see `GUIDE.md` for the required run
  order.
- Several legacy/duplicate script folders exist from earlier iterations of
  the project; confirm what's actually referenced in production before
  deleting anything.
- Live environment values (payment keys, SMTP credentials, webhook
  secrets, `APP_URL`) still need to be set for your actual deployment —
  see `.env.example` for the full list.
