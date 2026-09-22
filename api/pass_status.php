<?php
// ============================================================
//  FILE: api/pass_status.php  — RETIRED
//
//  Polled every 5s by the old player/my_qr.php page to flip the
//  pass from inactive to active the moment credits landed. Both
//  the page and the pass concept are retired.
//
//  Wallet balance itself is unaffected — see api/wallet.php.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

http_response_code(410);
echo json_encode([
    'success'  => false,
    'error'    => 'Player passes have been retired.',
    'redirect' => appUrl('public/open_play.php'),
]);
