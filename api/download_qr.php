<?php
// ============================================================
//  FILE: api/download_qr.php  — RETIRED
//
//  Served the player's pass QR as a printable PNG. Player passes
//  are gone with scanner check-in; Open Play runs on the live
//  queue and court access runs on reservations.
//
//  NOTE: this endpoint was only ever about *player passes*.
//  Payment QR codes (wallet top-ups, GCash) are a separate flow
//  and remain fully functional.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

http_response_code(410);
echo json_encode([
    'success'  => false,
    'error'    => 'Player pass QR codes have been retired.',
    'redirect' => appUrl('public/open_play.php'),
]);
