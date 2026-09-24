<?php
// ============================================================
//  FILE: scripts/open_play_cron.php
//
//  Background worker for the daily Open Play post. start.sh runs this
//  once a minute for the life of the container, so the next post goes
//  up (and finished sessions get closed) even when nobody is browsing
//  the site. One run = one pass of runOpenPlayScheduler().
//
//  CLI only. Manual run:   php scripts/open_play_cron.php
//  Shared hosting (no start.sh): add a cron entry, every minute —
//      * * * * * php /path/to/scripts/open_play_cron.php
//
//  Prints a line only when something happened (or went wrong), so the
//  container log stays quiet the rest of the time.
// ============================================================

if (PHP_SAPI !== 'cli') {
    // This file sits under the web root — never let it run over HTTP.
    http_response_code(404);
    exit;
}

define('OPEN_PLAY_CRON', true); // tells config/app.php not to also fire the page-load hook

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../tournament/open_play_scheduler.php';

try {
    $r = runOpenPlayScheduler(true);
} catch (Throwable $e) {
    fwrite(STDERR, '[open_play_cron] ' . date('c') . ' failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!empty($r['created']) || !empty($r['finalized'])) {
    echo '[open_play_cron] ' . date('c')
        . ' posted=' . json_encode($r['created'])
        . ' finalized=' . json_encode($r['finalized'])
        . ' (' . $r['reason'] . ')' . PHP_EOL;
} elseif (strpos((string)$r['reason'], 'error') === 0) {
    fwrite(STDERR, '[open_play_cron] ' . date('c') . ' ' . $r['reason'] . PHP_EOL);
    exit(1);
}
exit(0);
