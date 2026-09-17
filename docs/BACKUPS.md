# Backups

## What was wrong before

`config/backup.php` defined a bunch of PHP constants (`BACKUP_SCHEDULE`,
`BACKUP_S3_BUCKET`, etc.) that **nothing in the codebase ever read** — not
`scripts/backup.sh` (a separate bash script that doesn't know PHP constants
exist), not anything else. It's been deleted; it was decorative.

`scripts/backup.sh` did work as a script, but: nothing scheduled it, and its
S3 upload was optional — if `BACKUP_S3_BUCKET` was unset (it was, in
`.env.example` there was no S3 config at all), it would write a backup to
`/var/backups/falcon` and call it a success. Railway's disk is ephemeral, so
that backup would be gone on the next redeploy or restart. It's now rewritten
to **refuse to run** without S3-compatible credentials configured, and to
alert on failure via the same webhook as `config/alerting.php`.

## Set up

1. **Get an S3-compatible bucket.** AWS S3, Cloudflare R2, Backblaze B2, or
   your Railway-region-adjacent provider of choice — any of them work, the
   script just needs `BACKUP_S3_ENDPOINT` set for non-AWS providers.
2. **Set these in your Railway env vars** (production environment):
   ```
   BACKUP_ENABLED=true
   BACKUP_S3_BUCKET=your-bucket-name
   BACKUP_S3_REGION=us-east-1        # or your provider's region
   BACKUP_S3_ENDPOINT=               # leave blank for AWS S3; set for R2/B2/etc.
   AWS_ACCESS_KEY_ID=...
   AWS_SECRET_ACCESS_KEY=...
   ALERT_WEBHOOK_URL=...             # optional but recommended — see below
   ```
3. **Schedule it as a Railway Cron Job.** Railway runs cron as a distinct
   service type, not a flag on your web service:
   - Railway dashboard → your project → `+ New` → Empty Service (or
     duplicate the existing web service) → point its start command at
     `scripts/backup.sh`.
   - Service → Settings → **Cron Schedule** → enter `0 2 * * *` (daily,
     2 AM UTC — Railway cron schedules are always UTC, and the minimum
     interval is 5 minutes; daily is more than enough here).
   - Give this service the same env vars as above (Railway lets you share
     variables across services in an environment, or set them per-service).
   - The service must actually exit when the script finishes — it does
     (backup.sh doesn't daemonize) — otherwise Railway skips the next run.
4. **Verify it ran**: Railway's cron services log to the dashboard. Check
   after the first scheduled run, and check your bucket directly.

## Restoring — actually test this

An untested backup is a hypothesis, not a backup. `scripts/restore.sh` exists
so you can verify backups are real:

```bash
# Point DB_* env vars at STAGING (see docs/STAGING_SETUP.md), never prod
./scripts/restore.sh --latest
```

Run this quarterly, on staging, even when nothing's broken. The first time
you actually need it should not be the first time you've run it.
