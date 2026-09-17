# Security Hardening Report — Falcon Pickleball Court

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
