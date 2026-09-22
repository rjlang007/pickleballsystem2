<?php
// ============================================================
//  FILE: admin/kiosk_data.php  — RETIRED
//
//  Live feed for the legacy scanner-driven court kiosk
//  (admin/kiosk.php). Both are retired: the Open Play board is
//  the single source of truth and is served by
//  api/open_play_kiosk.php.
//
//  Kept as a stub so any cached page still polling this URL gets
//  a clear, non-crashing answer instead of a 404 HTML page landing
//  in a JSON parser.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

http_response_code(410);
echo json_encode([
    'success'  => false,
    'error'    => 'The legacy court kiosk feed has been retired. Use api/open_play_kiosk.php.',
    'redirect' => appUrl('staff/open_play_kiosk.php'),
]);
