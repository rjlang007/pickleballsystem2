<?php
// ============================================================
//  FILE: court/process_scan.php  — RETIRED
//
//  This was the scan ingestion endpoint: it took a player's QR /
//  barcode token, found them a court, charged their wallet and
//  started a game session. Scanner check-in is removed; players
//  are placed on courts through the Open Play queue
//  (api/open_play.php + tournament/open_play_engine.php) or by a
//  court reservation.
//
//  Answers as JSON rather than redirecting: this URL was only ever
//  called by fetch(), and any old client still polling it should
//  get a parseable error instead of an HTML page.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

http_response_code(410);
echo json_encode([
    'success'  => false,
    'status'   => 'retired',
    'message'  => 'Scanner check-in has been retired. Add the player from the Open Play console.',
    'redirect' => appUrl('staff/open_play_control.php'),
]);
