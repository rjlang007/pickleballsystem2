<?php
// ============================================================
//  FILE: admin/kiosk.php  — RETIRED
//
//  This was the legacy per-court kiosk driven by the QR/scanner
//  check-in tables (falcon.game_sessions + falcon.game_queue).
//  That queue no longer exists as a live workflow, and running it
//  alongside the Open Play board meant two screens showing two
//  different queues.
//
//  There is now exactly one kiosk: staff/open_play_kiosk.php,
//  fed by api/open_play_kiosk.php. Old bookmarks, TV browsers and
//  saved shortcuts land there instead of a dead screen.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../tournament/open_play_engine.php';
requireStaff();

// Carry the caller straight to tonight's event when there is one, so a
// TV pointed at the old URL keeps showing live games without a click.
$tid = 0;
try {
    $tid = (new OpenPlayEngine())->getActiveEventId();
} catch (Throwable $e) {
    error_log('[admin/kiosk] active event lookup failed: ' . $e->getMessage());
}

redirect('staff/open_play_kiosk.php' . ($tid ? '?tournament_id=' . $tid : ''));
