# Error alerting

## What was wrong before

`config/error_handler.php` defined a reasonable-looking error/exception
handler — and was never `require`'d anywhere. It had zero effect. The
handlers actually in force were three separate ones inline in
`config/app.php` (a PHP-error handler, an exception handler, and a shutdown
function for fatals) — these were working correctly for logging and for
hiding stack traces from users in production, but had no alerting: a broken
booking flow would log quietly to `storage/logs/php_errors.log` and nobody
would know until a customer complained.

## What changed

`config/alerting.php` adds a shared `alertOps()` helper, now called from all
three of the real handlers in `config/app.php` — but only for severities that
mean something is actually broken (`E_ERROR`, uncaught exceptions, fatals),
not every notice or warning. Each distinct error (same file + line +
message) only alerts once per 10 minutes by default
(`ALERT_DEDUPE_WINDOW_SECONDS`), so a loop of the same failing request
doesn't turn into a wall of pings. `config/error_handler.php` was deleted —
it was dead code duplicating what `config/app.php` already does live.

## Set up

1. Create a Slack incoming webhook (Slack → your workspace → Apps → Incoming
   Webhooks → Add to Slack → pick a channel → copy the Webhook URL). Discord,
   Teams, Google Chat, or a custom endpoint all work too, as long as they
   accept a `POST` of `{"text": "..."}`.
2. Set `ALERT_WEBHOOK_URL` in your Railway env vars.
3. That's it — no code change needed. Leave it blank to disable; errors still
   get logged to `storage/logs/php_errors.log` either way.

## Testing it

Trigger a deliberate error against staging (not production) — e.g. a bad DB
credential temporarily, or throw from a test route — and confirm the alert
shows up. Silent alerting is the same failure mode as no alerting.
