# Security Hardening Report — Padol Pickleball Court

Scope requested: CSRF / query consistency, rate limiting, secrets rotation plan.
Audit date: 2026-08-17. Codebase: 215 PHP files.

This document summarizes what was found and fixed, and what's still open.
Nothing here was fixed by guessing — every finding below was confirmed by
reading the actual code path before it was patched.

---

## 1. CSRF & query consistency

### 1a. Forms with zero CSRF protection (fixed)
11 files handled `POST` requests without ever calling `verifyCsrf()`:

- `admin/bulk_import_scores.php`, `admin/leaderboard_admin.php`,
  `admin/payment_settings.php`, `admin/reservations.php`, `admin/schedule.php`,
  `admin/tournament_admin.php`, `admin/tournament_edit.php`,
  `admin/tournament_scoring.php`
- `player/change_password.php`, `player/profile.php`, `player/schedule.php`

**Fix:** added `verifyCsrf()` as the first statement in each POST handler,
and `<?= csrfField() ?>` to every corresponding `<form>` (18 forms total).
For `admin/reservations.php`, which submits several actions via `fetch()` +
`FormData` instead of a native form submit, the CSRF token is now exposed to
JS as `CSRF_TOKEN` and attached to every `FormData` payload.

### 1b. GET-triggerable state changes (fixed — highest severity findings)
Four admin API endpoints read the `action` parameter from **either** POST or
GET (`$_POST['action'] ?? $_GET['action']`) with no CSRF check and no method
restriction:

| File | Action | Impact |
|---|---|---|
| `api/season_management_api.php` | `reset`, `archive`, `recompute` | Takes **no parameters** — a single `<img src="…/season_management_api.php?action=reset">` loaded while an admin is logged in would wipe the current season leaderboard. Worst finding in the audit. |
| `api/achievements_admin.php` | `award` | Award an achievement to an arbitrary player via a crafted link. |
| `api/leaderboard_admin_api.php` | `save_settings`, `adjust_points` | Overwrite leaderboard point configuration. |
| `api/tournament_api.php` | `create`/`update`/`delete`/`start`/`cancel`/`record_match`/… | Full tournament CRUD, unauthenticated-by-link. Currently unused by any caller in the codebase, but was live and reachable. |

**Fix:** each endpoint now requires `REQUEST_METHOD === 'POST'` for every
mutating action, calls `verifyCsrf()`, and only reads `action` from `$_POST`
(read-only actions on `tournament_api.php` remain GET-friendly). Added
`csrfField()` to the three admin views that POST to these endpoints
(`admin/achievements_admin.php`, `admin/season_management.php`,
`admin/leaderboard_settings.php`).

### 1c. Insecure/inconsistent inline CSRF checks (fixed)
- `superadmin/impersonate.php` compared the token with `!==` instead of
  `hash_equals()` (timing side-channel) and never rotated the token after
  use. Now calls the central `verifyCsrf()`.
- `player/map.php` had its own correct `hash_equals()` check but didn't
  rotate the token or rate-limit the endpoint. Brought in line with the
  central pattern and added a rate limit.

### 1d. JSON/fetch API endpoints — Origin defense-in-depth (added)
`api/*.php` endpoints are called via `fetch()`, not HTML forms, so they don't
carry the CSRF form field — they were relying solely on the session
cookie's `SameSite=Lax` attribute to stop cross-site POSTs. Added a new
`verifySameOrigin()` helper (checks `Origin`/`Referer` against the request
host for any non-GET method) as a second, server-side layer, applied to:
`api/wallet.php`, `api/community.php`, `api/food.php`, `api/chat.php`,
`api/chat_rooms.php`, `api/bracket_edit.php`. Also added explicit
POST-only + rate-limit guards to the `send`/`create`/`join`/`leave`/`kick`/
`pin_announce`/`game_invite`/`delete_room` chat-room actions, which
previously accepted their action name from GET as well.

### 1e. Two admin pages bypassed the CSRF system entirely (fixed)
`admin/tournament_admin.php` and `admin/tournament_scoring.php` bootstrapped
themselves with a bare `session_start()` and a hand-rolled
`$_SESSION['role']` check instead of `require config/app.php` +
`requireAdmin()`. Practical effect:
- They used PHP's default file-based session store under the default
  `PHPSESSID` cookie, **not** the app's DB-backed session
  (`config/session.php`, cookie `FALCON_SESS`) that every other page uses.
- `config/security.php` was never loaded, so `csrfToken()`/`verifyCsrf()`
  didn't exist on these pages — 10 forms across the two files posted with
  no CSRF protection, no session-idle-timeout check, and no CSP/HSTS
  headers.

**Fix:** rebootstrapped both files through `config/app.php` +
`requireAdmin()`, added `verifyCsrf()` to both POST handlers, and
`csrfField()` to all 10 forms.

---

## 2. Rate limiting

### 2a. Race condition in the rate limiter (fixed)
The original `checkRateLimit()`/`shouldRateLimit()` did a plain
read-file → check → write-file cycle with no locking. Concurrent requests
(e.g. a scripted burst of parallel login attempts) could all read the same
"under limit" state before any of them wrote back, letting an attacker
exceed the limit by firing requests in parallel instead of serially.

**Fix:** rewrote the core as `_rateLimitCore()`, which opens the bucket file
once and holds an exclusive `flock()` for the entire read-modify-write
cycle. Both public function names (`checkRateLimit`, `shouldRateLimit`) are
now thin wrappers over the same atomic core, so no caller needed to change.
Added `checkCompositeRateLimit()` for endpoints that want both an IP-scoped
and an account-scoped limit at once (useful for login/OTP where you want to
catch both "one IP hammering many accounts" and "many IPs hammering one
account").

### 2b. Endpoints that were missing a limiter (fixed)
- `api/mobile.php` — Bearer-token auth endpoint had **no rate limit at
  all** on token verification, meaning the `qr_token` (a bare lookup key,
  no separate hashing/pepper) was brute-forceable by request volume alone.
  Added a 30-req/60s IP-scoped limit ahead of the token lookup.
- `api/bracket_edit.php`, `api/tournament_api.php`, `api/leaderboard_admin_api.php`,
  `api/achievements_admin.php`, `api/season_management_api.php`,
  `api/chat.php` (`send`), `player/map.php`, `admin/tournament_admin.php`,
  `admin/tournament_scoring.php`, `superadmin/impersonate.php` — all now
  have a `checkRateLimit()` call sized to the sensitivity of the action
  (10/5min for season reset and impersonation; 30–60/min for routine admin
  writes and chat).

### 2c. Already solid (left as-is)
`auth/login.php`, `auth/register.php`, `auth/forgot_password.php`,
`auth/reset_password.php`, and `auth/verify_email.php` already had layered,
sensible rate limiting (a general per-IP cap plus a tighter per-action
cooldown) and correct CSRF checks. No changes needed there.

---

## 3. Other issues found along the way (flagged, not all fixed — see below)

- **`player/map.php`** leaked raw `PDOException::getMessage()` (schema/
  column detail) straight into the JSON response on save failure. Fixed —
  now logs server-side and returns a generic message. A repo-wide grep
  found **14 more places** doing the same thing (`echo … . $e->getMessage()`
  in an API/JSON response) — not fixed in this pass, listed as a follow-up.
- **CSP nonce inconsistency**: `config/security.php` emits a per-request CSP
  with `script-src 'self' 'nonce-{$nonce}' …` (no `'unsafe-inline'`), which
  means any inline `<script>` block *without* that nonce is silently
  blocked by the browser in production. A repo scan found 19 of 35 files
  with inline `<script>` blocks that don't set the nonce attribute — some
  of that inline JS may already be dead in production. Worth auditing
  separately; out of scope for this pass since fixing it means touching
  ~19 view files' rendering, not the security layer itself.
- **`api/chat.php` PHPSESSID fallback — flag this as urgent, separate from
  the requested scope.** Around line 58, there's an "auth recovery" block
  that reads a client-supplied `$_COOKIE['PHPSESSID']` value, calls
  `session_id($_COOKIE['PHPSESSID'])`, starts a session under that
  attacker-influenced ID, and copies `user_id`/`role`/etc. from it into the
  live session. Trusting a client-supplied session identifier like this is
  a textbook session-fixation pattern. **I did not touch this** — it's
  outside "CSRF/rate limiting/secrets" and changing session-identity logic
  without more context on why it was added (probably a legacy-session
  migration shim) risks breaking real users mid-fix. Recommend either
  removing the block entirely or replacing it with a one-time,
  server-side-only migration path (e.g. a signed upgrade token, not a raw
  client-controlled session ID) — happy to do this in a follow-up if you
  want it scoped separately.
- **`api/brackets.php`** never `require`s `config/app.php` directly (only
  `_api_helpers.php` + `tournament_engine.php`) — worth double-checking in
  a browser that `requireAdmin()` there is actually seeing a started
  session on every deploy target; didn't find a live bug, but the require
  chain is fragile enough to flag.

---

## 4. Files changed

```
config/security.php                    (rate limiter rewrite, verifySameOrigin(), composite limiter)
superadmin/impersonate.php             (hash_equals CSRF, rate limit)
player/map.php                         (CSRF rotation, rate limit, no error leak)
admin/tournament_admin.php             (proper bootstrap, CSRF, rate limit, 7 forms)
admin/tournament_scoring.php           (proper bootstrap, CSRF, rate limit, 2 forms)
admin/bulk_import_scores.php           (CSRF, 2 forms)
admin/leaderboard_admin.php            (CSRF, 1 form)
admin/payment_settings.php             (CSRF, 4 forms)
admin/reservations.php                 (CSRF, 1 form + 3 fetch() call sites)
admin/schedule.php                     (CSRF, 3 forms)
admin/tournament_edit.php              (CSRF, 1 form)
admin/achievements_admin.php           (CSRF field on form)
admin/leaderboard_settings.php         (CSRF field on form)
player/change_password.php             (CSRF, 1 form)
player/profile.php                     (CSRF, 1 form)
player/schedule.php                    (CSRF, 2 forms)
api/achievements_admin.php             (POST-only, CSRF, rate limit)
api/season_management_api.php          (POST-only, CSRF, rate limit)
api/leaderboard_admin_api.php          (POST-only, CSRF, rate limit)
api/tournament_api.php                 (POST-only for mutating actions, CSRF, rate limit)
api/wallet.php                         (same-origin check)
api/community.php                      (same-origin check)
api/food.php                           (same-origin check)
api/chat.php                           (POST-only send, same-origin, rate limit)
api/chat_rooms.php                     (POST-only for 8 mutating actions, same-origin)
api/bracket_edit.php                   (same-origin, rate limit)
api/mobile.php                         (rate limit on token auth)
```

All 215 files pass `php -l` after these changes.

---

## 5. Follow-up pass — error message leaks (2026-09-22)

Closes the item flagged in §3 above ("14 more places" echoing raw
`$e->getMessage()` into an API/JSON response). Found and fixed every
live (non-`migrations/`) occurrence:

| File | Fix |
|---|---|
| `api/bracket_edit.php` | generic message on `catch (Exception)`; real message still `error_log`'d |
| `api/brackets.php` | generic message on `catch (Throwable)`; added `error_log` (was missing) |
| `api/wallet.php` | withdrawal PATCH now `error_log`s the `RuntimeException` too (message itself was already a static, safe string — not a DB/schema leak) |
| `admin/manual_add_player.php` | generic message on `catch (PDOException)` |
| `admin/content_manager.php` | generic message on all 3 sites (2 Cloudinary-upload catches, 1 general `Throwable`) |
| `court/end_game.php` | generic message on `catch (PDOException)` |
| `court/game_ticker.php` | generic message on both internal-return and top-level ticker error paths |
| `court/game_alert.php` | the `detail` field (explicitly commented "visible in Network tab during dev") is now only added when `!IS_PRODUCTION` |
| `referee/api/score_action.php` | generic message on `catch (Throwable)` |
| `staff/api/tournament_ops.php` | generic message on `catch (Throwable)`; also corrected the HTTP status from 400 to 500, since this branch is an unexpected server error, not a client validation error |

Left untouched, intentionally: `includes/booking_state_machine.php`'s
`catch (RuntimeException $e) { … 'message' => $e->getMessage() }` — that
exception is only ever thrown internally by the same class with curated,
user-safe text (insufficient funds, forbidden, conflict, etc.), which is
the correct pattern the rest of the codebase should follow for
intentional validation errors. The difference from the leaks above is
the catch type: a narrow, app-defined exception with a safe message is
fine to surface; a broad `Exception`/`Throwable`/`PDOException` catch is
not, since it can carry raw SQL/driver text.

Not fixed here — duplicate copies of `end_game.php`, `game_ticker.php`,
`score_action.php`, and `tournament_ops.php` also exist under
`migrations/court/`, `migrations/referee/api/`, and
`migrations/staff/api/` with the same leak, unpatched. These look like
stale snapshots rather than live, routed code — worth confirming nothing
in production actually serves from `migrations/` and then deleting that
whole duplicate tree (see the "legacy/duplicate code" item in the
project review — this is a concrete example of why that cleanup
matters).

---

## 6. Follow-up pass — CSP inline-script nonce audit (2026-09-22)

Closes the other item flagged in §3 ("19 of 35 files with inline
`<script>` blocks that don't set the nonce attribute"). Re-scanned the
whole codebase (every `.php`/`.html` file, excluding `vendor` code and
the `migrations/` duplicate tree, which is being removed separately —
see `cleanup-migrations-duplicate.sh`).

**Result: only one real gap left**, `public/index.php` — a small inline
script that sets `window.APP_URL`. Fixed by adding
`nonce="<?= getCspNonce() ?>"`, matching the pattern already used
correctly everywhere else in that same file. It's on the public landing
page, so this was silently broken for every visitor (the script just
didn't run under CSP), not just an internal/admin-only issue.

The other two apparent hits from the original "19 of 35" count were
false positives on closer read: `player/map.php` only had the string
`<script>` inside a code *comment* describing the nonce pattern, and
`includes/PHPMailer/src/PHPMailer.php` matched on an unrelated regex
literal (`(?P<script>...)`), not an HTML tag. So it looks like most of
the 19 originally-flagged files were already fixed in a later pass —
this one was the only one still outstanding.

---

## 7. Cleanup pass — dead weight and build-path drift (2026-09-22)

- **Ran `cleanup-migrations-duplicate.sh`.** Removed the ~26MB accidental
  full-project copy that had been sitting inside `migrations/` (see the
  script's own header comment for the full list). `migrations/` now
  contains only the real `*.sql` chain and
  `migrations/migrations/run_migrations.php`. This also deleted the
  duplicate `migrations/.env`, which still held the **original**,
  pre-rotation DB password — a second copy of an already-flagged
  credential that no cleanup step had reached until now.
- **Deleted `includes/qr.php` (and its `gen_qr.py` companion).** Confirmed
  by repo-wide grep that nothing live references `PlayerQR` — the
  "player QR pass" feature it supported was retired in favor of the Open
  Play queue (`player/my_qr.php` is a redirect stub for old bookmarks).
  The file also hardcoded a developer's local Windows Python path, so had
  anything still called it, every request in production would have fallen
  through to shipping data out to a third-party API (qrserver.com) instead
  of failing loudly.
- **`nixpacks.toml` build-path drift (fixed).** `railway.json` pins the
  build to the Dockerfile, which already does
  `rm -f create_admins.php generate_hash.php` (two unauthenticated,
  privileged-account-creation/debug scripts) before shipping. The
  `nixpacks.toml` fallback build path did not do this — if it were ever
  used instead of the Dockerfile (a different Railway setting, a local
  nixpacks build, etc.), both files would go live in the web root with no
  auth guard. Added the same `rm -f` to `nixpacks.toml`'s build phase so
  neither build path can regress independently of the other.
- **Removed `tmp/op_test.php`**, a local ad-hoc test harness that
  hardcoded a Windows path and forged a `super_admin` session. It was
  already unreachable in production (nginx and `.htaccess` both block
  `/tmp/`), so this was housekeeping, not a live exposure fix.
- **Not done here, still open:** rotating `DB_PASS` again. The `.env`
  uploaded in this pass contains a live password that is now itself
  exposed the same way the previous one was — see
  `SECRETS_ROTATION_PLAN.md` §0 for the rotation steps, which apply
  identically to this new value.

---

## 8. Cleanup pass — stale duplicate feature code, dead broadcaster (2026-09-22)

- **Removed a second stray duplicate tree**, this one under `scripts/`
  (`scripts/admin/`, `scripts/player/`, `scripts/api/`, `scripts/includes/`,
  `scripts/migrations/`, `scripts/GUIDE.md`) — an older, out-of-date
  snapshot of the food-ordering feature (missing the `rejected` order
  status, a different food-category schema, no `verifySameOrigin()` call
  in its `api/food.php`). Confirmed via `diff` against the real
  `admin/food_*.php` / `player/food_*.php` / `api/food.php` that these were
  genuinely stale, not an active alternate version. `nginx.conf` already
  blocks all of `/scripts/`, so this wasn't reachable in production, but
  it's the same "accidental duplicate tree" pattern already fixed once in
  `migrations/` (§7) — same cause, same fix. Kept the real ops tooling
  that also lives in `scripts/`: `deploy.sh`, `backup.sh`, `restore.sh`,
  `package.sh`, `reset_superadmin.php`, `verify_open_play_schema.php`,
  `whoami_headers.php`, and `rotate_db_password.sql` (this last one is
  exactly the tool needed for the still-open DB-password rotation above —
  it already has both a quick-rotation option and a "move off the
  `postgres` superuser onto a scoped app role" option written out).
- **`includes/websocket.php` / `api/websocket.php` — documented as
  non-functional, not removed.** `WebSocketBroadcaster` is instantiated
  fresh on every PHP-FPM request, so its `$clients` list is always empty
  by the time anything tries to broadcast — the two live call sites in
  `court/game_ticker.php` (game start / game end) run real DB queries for
  zero delivered effect. This isn't a security bug, but it was
  undocumented dead weight that looks like working real-time
  infrastructure and isn't; a future dev could easily build a feature on
  top of it assuming it works. Added a clear notice at the top of
  `includes/websocket.php` explaining why, so it doesn't get load-bearing
  by accident before a real WebSocket server is wired up.
- **`api/websocket.php`'s `broadcast_test` action was unauthenticated**
  (anyone could hit it and trigger a real DB query, repeatedly, with no
  rate limit). Currently harmless in effect (see above — it broadcasts to
  nobody), but bad hygiene either way: gated it behind `requireAdmin()`
  plus a 10/60s rate limit, consistent with how every other
  state-touching endpoint in this app is guarded.
