<?php
// ============================================================
//  FILE: api/open_play_kiosk.php
//  JSON feed for the live board — used by both the staff TV
//  kiosk (staff/open_play_kiosk.php) and the player-facing live
//  view (public/open_play_live.php). Any logged-in user may
//  read it (same visibility as the public roster on
//  public/tournaments.php); only staff can act on it via
//  api/open_play.php.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/_api_helpers.php';
require_once __DIR__ . '/../tournament/open_play_engine.php';

requireAuth();

header('Cache-Control: no-cache, no-store, must-revalidate');

$tid = (int)($_GET['tournament_id'] ?? 0);
if (!$tid) apiError('tournament_id required.', 400);

try {
    $engine = new OpenPlayEngine();
    apiSuccess($engine->getKioskData($tid));
} catch (RuntimeException $e) {
    apiError($e->getMessage(), 404);
} catch (Throwable $e) {
    error_log('[API/open_play_kiosk] ' . $e->getMessage());
    apiError('Internal server error.', 500);
}
