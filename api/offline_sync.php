<?php
// ============================================================
//  FILE: api/offline_sync.php  — RETIRED
//
//  Replayed QR scans that a mobile app had buffered while
//  offline, writing them into falcon.scan_logs. Scanner check-in
//  is retired, so there is nothing to replay — and quietly
//  accepting queued scans would have written check-ins that no
//  longer place anyone on a court.
//
//  Old app builds get an explicit 410 so they stop retrying and
//  can clear their outbox, rather than looping on a 404.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

http_response_code(410);
echo json_encode([
    'success'  => false,
    'error'    => 'Offline scan sync has been retired along with scanner check-in.',
    'discard'  => true,   // tells the client its queued scans will never apply
    'redirect' => appUrl('public/open_play.php'),
]);
