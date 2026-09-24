# Open Play — automatic daily post

A new Open Play post is created automatically every day. No staff action is needed.

## How it works

`tournament/open_play_scheduler.php` → `runOpenPlayScheduler()` does one pass:

1. **No-show sweep** on live Open Play events.
2. **Close finished sessions.** A session is finalized (standings written, podium added to the season
   leaderboard) once its end time + grace period has passed **and** no game is still on a court.
   The older 4 AM safety net still applies: anything left from a previous business day is closed
   even if games are marked live (those games are cancelled first, without drawing replacements).
3. **Post the next session.** Looks at today's business date, then tomorrow's:
   - a session that is still open → nothing to do;
   - a session that has ended (finalized, cancelled, or past end time + grace) → look at the next date;
   - a date with no post whose window has not passed → create it, then stop.

So the next post goes up right after the previous session ends, and there is always one live post.

"Business date" rolls over at **4:00 AM Asia/Manila**, so a session running past midnight still belongs to
the previous day.

## What triggers it

| Trigger | When |
|---|---|
| `scripts/open_play_cron.php` (started by `start.sh`) | every 60 s, independent of site traffic |
| `ensureNightlyOpenPlayEvent()` in `config/app.php` | on page loads, throttled to once per minute (fallback) |
| Admin → Open Play Settings → **Check & post now** | on demand |

A Postgres advisory lock (`OPEN_PLAY_LOCK_KEY`) guarantees only one run at a time, so a post can never be duplicated.

**Hosting without `start.sh`** (e.g. cPanel): add a cron entry, every minute:

    * * * * * php /path/to/scripts/open_play_cron.php

## Settings (Admin → Open Play Settings)

- **Enabled** (default on) — pauses/resumes posting. Never touches a session already posted or running.
- **Auto-close after end time** (default on) and **grace period** (default 30 min).
- **Notify last session's players** (default on) — in-app notification when the next post goes up.
- Per-weekday start/end, capacity, format, courts, and **registration fee**.

> Players can only join an event that has a fee greater than 0. Set the fee, or nobody can sign up.

## Cancelling a night

Cancelling a post (or "close tonight") closes **only that date**. Tomorrow's post is still created automatically.
"Re-open cancelled date" un-closes it.

## Checking it is alive

The settings page shows "Auto-poster is running (last check Ns ago)". Container logs show
`[open_play_scheduler] posted Open Play #…` / `auto-finalized …` whenever something happens.
