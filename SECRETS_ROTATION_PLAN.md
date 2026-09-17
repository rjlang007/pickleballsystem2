# Secrets Rotation Plan — Padol Pickleball Court

## 0. Do this first, today

Your `.env` file — including the live database password — was included
inside the `pickleball-fixed.zip` you uploaded to this chat. Anything sent
to me (or to any zip you shared elsewhere: email, Slack, a git repo, a
support ticket) should now be treated as **potentially exposed**, regardless
of how the tool or channel handles it internally. The cheapest fix is
always just to rotate the credential — don't spend time trying to figure
out whether a given exposure was "real," rotate and move on.

**Rotate the DB password now** (`DB_PASS=bombit123` in the uploaded `.env`):

1. In Postgres: `ALTER USER postgres WITH PASSWORD '<new-strong-password>';`
   (or, better, stop using the `postgres` superuser for the app — see §2.)
2. Update `DB_PASS` (and `DATABASE_URL` if you use the Railway
   connection-string form) in your **actual deployment environment**
   (Railway env vars / hosting panel), not just the local `.env`.
3. Restart the app so it picks up the new credential.
4. Delete or `.gitignore` the `.env` file from anywhere it currently sits
   outside your live deployment's env-var store — it should never be
   committed to a repo, zipped up, or emailed.

This is the one item on this whole page that's time-sensitive. Everything
below is important but not "do it in the next hour" urgent.

---

## 1. Inventory of secrets in this codebase

| Secret | Where it's read | Current state | Rotation urgency |
|---|---|---|---|
| `DB_PASS` | `config/db.php` via `getenv('DB_PASS')` | Weak (`bombit123`), and now exposed via this upload | **Immediate** — see §0 |
| `PAYMONGO_SECRET_KEY` | `config/app.php` | Empty in the uploaded `.env` (not yet configured) | Rotate on any suspected leak; not urgent until you go live with PayMongo |
| `PAYMONGO_WEBHOOK_SECRET` | `config/app.php`, verified in `api/paymongo_webhook.php` via HMAC-SHA256 | Empty in `.env`. Code correctly **fails closed** in production if unset (`return !IS_PRODUCTION`) — good — but this means webhooks are currently unverifiable until it's set | Set before going live; rotate quarterly or on leak |
| `CLOUDINARY_API_SECRET` / `CLOUDINARY_API_KEY` | `config/cloudinary.php` | Empty in `.env` (not yet configured) | Rotate on leak; not urgent until configured |
| Session cookie (`FALCON_SESS`) | `config/session.php` | Not a static secret — PHP generates a fresh random session ID per login, stored server-side in `falcon.php_sessions` | N/A — but see §3 for exposure response |
| CSRF tokens | `config/security.php` | Not a static secret — 32 random bytes per session, regenerated after each verified use | N/A |
| `TRUSTED_PROXY_IPS` | `includes/security_helpers.php` | Not a secret, but currently empty — see note in §4 | N/A |

Nothing else in the codebase reads an API key or credential from `getenv()`
— this is a reasonably contained secret surface, which makes rotation
manageable.

---

## 2. Reduce blast radius, not just rotate

Rotating a leaked password just gives you the same problem again next time
someone zips up the project. Two structural changes make future exposures
cheaper:

- **Don't run the app as the `postgres` superuser.** Create a dedicated
  role scoped to the `falcon` schema:
  ```sql
  CREATE ROLE falcon_app LOGIN PASSWORD '<new-strong-password>';
  GRANT USAGE ON SCHEMA falcon TO falcon_app;
  GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA falcon TO falcon_app;
  GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA falcon TO falcon_app;
  ALTER DEFAULT PRIVILEGES IN SCHEMA falcon
      GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO falcon_app;
  ```
  A leaked `falcon_app` password can't touch other databases on the same
  Postgres instance or run `CREATE`/`DROP` outside what the app needs.
- **Keep a real `.env.example`** (no values, just key names) in the repo,
  and confirm `.env` itself is in `.gitignore` — it currently is not
  guaranteed by anything in this zip; double-check before your next commit.

---

## 3. Standing rotation cadence (once live)

| Secret | Cadence | Trigger for out-of-cycle rotation |
|---|---|---|
| DB password | Every 90 days | Any dev/laptop with `.env` access changes, any suspected leak, any offboarded team member |
| PayMongo secret + webhook secret | Every 180 days, or per PayMongo's own recommendation | Suspected leak, PayMongo security notice |
| Cloudinary API secret | Every 180 days | Suspected leak |
| All of the above | Immediately | Any time one is pasted into a chat tool, ticket, email, or shared outside the deployment's secret store |

For each rotation: update the value in the hosting platform's env-var
store first, redeploy/restart, confirm the app is healthy, *then* revoke
the old credential at the provider (DB user password change, PayMongo
dashboard key rotation, Cloudinary key rotation) so there's no gap where
neither credential works.

---

## 4. Two adjacent items worth closing out while you're in here

- **`TRUSTED_PROXY_IPS` is empty.** If this app sits behind Railway's
  proxy (or any reverse proxy) in production, `getClientIp()` /
  IP-based rate limiting and ban logic (`includes/security_helpers.php`)
  needs to know which `X-Forwarded-For` hops to trust, or every request
  looks like it's coming from the same proxy IP — which would make the
  per-IP rate limits in this report (login, chat, admin actions, etc.)
  effectively rate-limit *all users combined* instead of each attacker
  individually. Set it to your proxy's IP range once you know your
  hosting topology.
- **`IS_PRODUCTION`** is derived from `RAILWAY_ENVIRONMENT` or
  `IS_PRODUCTION` env vars (`config/app.php`). Confirm whichever one your
  deploy actually sets — the `.env` in this zip has `IS_PRODUCTION=0` and
  no `RAILWAY_ENVIRONMENT`, so if that file is ever accidentally used as
  the real production env, HSTS/HTTPS-redirect and the PayMongo
  fail-closed check would both silently downgrade to dev behavior.
