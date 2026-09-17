# Falcon Pickleball Court — Local Setup & Usage Guide

## 1. The one URL to always use

Bookmark this and always start here — never type a shorter path by hand:

```
http://localhost/System/pickleball/pickleball-fixed/
```

Every page you need is reached by clicking links **from this homepage**, not by
typing URLs. If your browser ever shows something like `localhost/public/...`
or `localhost/auth/...` (missing the `System/pickleball/pickleball-fixed`
folder), that page will always 404 — it's a different, non-existent location
on your Apache server, not a bug in the app. This has come up a lot in this
conversation and every time the actual page existed and worked once visited
through the correct base URL — so if it happens again, check your bookmarks
bar and browser autocomplete history first.

## 2. Accounts

### Admin
- Username: `randall`
- Role: `admin` (promoted via SQL)
- If you forget the password again, ask to have a new bcrypt hash generated
  and apply it with:
  ```powershell
  psql -h localhost -U postgres -d falcon -c "UPDATE falcon.users SET password_hash='<hash>' WHERE username='randall';"
  ```

### Customer (player)
Just use the **Register** page — new accounts default to `role = player`
automatically. No SQL needed. Create as many test customer accounts as you
like this way.

### Promoting any user to admin
```powershell
psql -h localhost -U postgres -d falcon -c "UPDATE falcon.users SET role='admin' WHERE username='THEIR_USERNAME';"
```

## 3. Database migrations — run once, in this order

Your database only had `001_initial_schema.sql` applied originally, which is
what caused most of the errors fixed in this conversation. If you ever
rebuild the database from scratch, run every file below, in order, from the
`migrations` folder:

```powershell
cd C:\xampp\htdocs\System\pickleball\pickleball-fixed\migrations
psql -h localhost -U postgres -d falcon -f 001_initial_schema.sql
psql -h localhost -U postgres -d falcon -f 002_missing_tables.sql
psql -h localhost -U postgres -d falcon -f 002_tournament_enhancement.sql
psql -h localhost -U postgres -d falcon -f 003_patches.sql
psql -h localhost -U postgres -d falcon -f 004_add_subscription_schema.sql
psql -h localhost -U postgres -d falcon -f 005_fix_avatar_columns.sql
psql -h localhost -U postgres -d falcon -f 005b_fix_users_columns.sql
psql -h localhost -U postgres -d falcon -f 006_add_chat_rooms_schema.sql
psql -h localhost -U postgres -d falcon -f 007_seed_training_shop.sql
psql -h localhost -U postgres -d falcon -f 008_fix_reservations_columns.sql
psql -h localhost -U postgres -d falcon -f tournament_tables_patch.sql
psql -h localhost -U postgres -d falcon -f v2_multi_court.sql
psql -h localhost -U postgres -d falcon -f v2_fix_view_notx.sql
psql -h localhost -U postgres -d falcon -f v3_refresh_court_status_view.sql
psql -h localhost -U postgres -d falcon -f 009_missing_columns_patch.sql
psql -h localhost -U postgres -d falcon -f 010_food_ordering.sql
psql -h localhost -U postgres -d falcon -f 012_food_categories.sql
```

(`011_seed_food_menu.sql` is optional — run it only if you want a starter
menu to test with.)

**Skip** `2026_05_05_create_tournament_tables.sql` — it's written in MySQL
syntax and will error against Postgres. It's unused leftover.

A handful of `ERROR: role "falcon_app" does not exist` lines are expected
and harmless — they're `GRANT` statements for a database role your local
setup doesn't use.

## 4. What's already been fixed in this codebase

| Area | Problem | Fix |
|---|---|---|
| `auth/register.php` | Missing `$` before `pageTitle`, fatal syntax error | Added `$` |
| `auth/login.php`, `auth/subscription.php` | Redirect URLs doubled up (`APP_URL` prepended twice) | Pass relative paths to `redirect()` |
| `migrations/004_add_subscription_schema.sql` | Typo'd schema name `town.users` instead of `falcon.users` | Fixed all 7 occurrences |
| `court/scanner.php` | Clock JS referenced missing `#clock`/`#dateline` elements | Made null-safe |
| `admin/export_csv.php` | Called nonexistent `generateCsrf()` | Changed to `csrfToken()` |
| `admin/tournament_admin.php`, `public/tournaments.php` | Hardcoded broken path `/pickleball/public/...` | Uses `APP_URL` now |
| Database | Several columns/tables the code expects were never created (`display_name`, `gcash_ref_no`, `screenshot_path`, `court_photos`, chat columns, `transactions` columns, etc.) | Added `migrations/009_missing_columns_patch.sql` |
| `index.php` (project root) | Didn't exist | Added, redirects to `public/index.php` |

## 5. Food ordering (mini-restaurant)

Added a full food/drinks ordering module on top of the wallet system:

- **Players** browse the menu and order at `player/food_menu.php`, choosing
  pickup at the counter or delivery to their court, and paying by wallet
  (deducted instantly) or cash at the counter. They track status and their
  order number at `player/food_orders.php`.
- **Admin** sets menu items and prices at `admin/food_menu.php`, and manages
  categories (add / rename / reorder / delete) from the "🏷️ Manage
  Categories" button on that same page — categories are now a managed list
  (`falcon.food_categories`), not free-text, so there's no more risk of
  ending up with duplicate categories like "Drinks" and "drinks" from a
  typo. A category can only be deleted once no menu items use it.
- **Admin/staff** run the live order queue and advance
  pending → preparing → ready → completed (or cancel, which auto-refunds
  wallet payments) at `admin/food_orders.php`. Cash orders get a "Mark Paid"
  button once collected at the counter.
- Backend: `api/food.php`. Schema: `migrations/010_food_ordering.sql` +
  `migrations/012_food_categories.sql` (run both — see step 3 above — or
  the pages will error with "relation falcon.food_items does not exist" /
  category-related errors). `012` auto-imports any category text already
  in `food_items` into the new managed list, so it's safe to run even on
  an install with existing menu data.
- **Stuck-order alerts for staff**: the live queue (`admin/food_orders.php`)
  now flags any pending/preparing order sitting for 8+ minutes — a pulsing
  red border on the card, an "⚠️ waiting" badge showing exact age, a banner
  at the top of the page, and the browser tab title gets a `(N)` prefix so
  staff notice even from another tab. Age is computed server-side in
  `api/food.php` (`age_minutes` on the queue endpoint) so it's not affected
  by the staff device's clock. The threshold is the `STUCK_MINUTES` constant
  at the top of `admin/food_orders.php`'s script — change it there if 8
  minutes isn't right for your kitchen.
  a "🍔 Food Revenue" stat card, a combined "Total Revenue" figure (court +
  food), a daily food-revenue chart, a paid/cancelled/unique-customers
  breakdown, and a "Top Food Items" best-sellers table — all scoped to the
  month you're viewing. A new "🍔 Food Orders CSV" export was added to
  `admin/export_csv.php` alongside the existing ones (one row per order,
  items listed inline, e.g. "Iced Coffee x2, Fries x1").
- Uploaded menu photos are saved to `Uploads/food/` (created automatically
  on first upload; already covered by `Uploads/.htaccess`).

## 6. Reporting new bugs going forward

When something errors, the fastest way to get it fixed is to paste:
1. The exact error text from the browser console / page.
2. The full URL in the address bar at the time.

That's usually enough to trace it straight to the file and line.
