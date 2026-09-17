# Staging environment

Right now there's one Railway environment (`production`) and it holds real
wallet balances, real PayMongo transactions, and real players' data. Testing
anything — a new feature, a migration, `scripts/restore.sh` — against that is
one wrong `DELETE` away from a bad day.

## Set up (Railway)

1. **Railway dashboard → your project → Environments → New Environment**,
   name it `staging`. This clones your services' config but does **not**
   clone data.
2. **Add a separate Postgres service** to the staging environment (don't
   point staging at the production database — that defeats the purpose).
   Railway → staging environment → `+ New` → Database → PostgreSQL.
3. **Seed staging with a scrubbed copy of prod**, not live customer data:
   ```bash
   # from your machine, against a recent backup (see docs/BACKUPS.md)
   ./scripts/restore.sh --latest   # but with DB_* env vars pointed at STAGING
   ```
   Then scrub anything sensitive — at minimum, PayMongo keys/webhook secret
   should be staging/test-mode keys (PayMongo has a test mode — use it, don't
   run test bookings through live payment processing), and consider nulling
   out real emails/phone numbers so accidental notification sends don't hit
   real players.
4. **Set env vars for the staging service**: same as production's `.env.example`
   but with staging's DB credentials, `RAILWAY_ENVIRONMENT` left as whatever
   Railway sets it to for that environment (Railway sets this automatically —
   don't hardcode it), and PayMongo test-mode keys. `IS_PRODUCTION` now
   correctly resolves to `false` here (see the fix in `config/app.php` —
   previously any non-empty `RAILWAY_ENVIRONMENT` was treated as production,
   which would have broken this).
5. **Point CI/manual deploys at staging first.** Railway lets you promote a
   deployment from staging to production once it's checked out, or you can
   just `git push` to a staging-tracked branch if you wire that up in
   Railway's GitHub integration settings.

## What staging is for

- Trying `scripts/restore.sh` without risking production data — you should
  actually run a restore drill here every quarter, not just trust the backup
  exists.
- Testing migrations before they touch real bookings/wallets.
- PayMongo test-mode payment flows end-to-end.
- Anything from the feature roadmap (waitlists, recurring bookings, etc.)
  before it touches real court owners.

## What it's not

A staging environment with production data in it isn't staging, it's a
second production with worse monitoring. Keep real payment credentials and
real customer PII out of it.
