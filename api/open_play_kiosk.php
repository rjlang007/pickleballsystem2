<?php
// ============================================================
//  FILE: api/open_play_kiosk.php
//  JSON feed for the official Open Play live board — the single
//  source of truth used by the TV kiosk (staff/open_play_kiosk.php),
//  the staff console (staff/open_play_control.php) and the
//  player-facing live view (public/open_play_live.php).
//
//  tournament_id is OPTIONAL. When omitted the currently running
//  Open Play event is resolved automatically, so the kiosk can be
//  bookmarked on a TV once and keep working night after night.
//
//  Any logged-in user may read it (same visibility as the public
//  roster on public/tournaments.php); only staff can act on it via
//  api/open_play.php.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/_api_helpers.php';
require_once __DIR__ . '/../tournament/open_play_engine.php';

requireAuth();

header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    $engine = new OpenPlayEngine();
    $tid    = (int)($_GET['tournament_id'] ?? 0);

    if (!$tid) {
        $tid = $engine->getActiveEventId();
    }

    if (!$tid) {
        // No live event tonight — a valid, renderable empty state
        // rather than an error the kiosk has to special-case.
        apiSuccess([
            'event'            => null,
            'now_playing'      => [],
            'up_next'          => [],
            'waiting_pool'     => [],
            'waiting_count'    => 0,
            'courts'           => [],
            'court_count'      => 0,
            'courts_available' => 0,
            'players_per_game' => 4,
            'game_duration'    => 900,
            'next_free_secs'   => 0,
            'event_paused'     => false,
            'no_event'         => true,
            'server_time'      => time(),
        ]);
    }

    apiSuccess($engine->getKioskData($tid));
} catch (RuntimeException $e) {
    apiError($e->getMessage(), 404);
} catch (Throwable $e) {
    error_log('[API/open_play_kiosk] ' . $e->getMessage());
    apiError('Internal server error.', 500);
}
