<?php
// ============================================================
//  FILE: api/get_user_token.php  — RETIRED
//
//  Handed the caller their scannable pass token. Nothing scans
//  tokens anymore, and continuing to serve them would leave a
//  live credential for a workflow that no longer has an owner.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

http_response_code(410);
echo json_encode([
    'success'  => false,
    'error'    => 'Pass tokens have been retired. Join the Open Play queue instead.',
    'redirect' => appUrl('public/open_play.php'),
]);
