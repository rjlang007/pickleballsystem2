<?php
// ============================================================
//  FILE: player/my_qr.php  — RETIRED
//
//  Player QR passes are gone. Getting on a court is now done by
//  joining the live Open Play queue (or by booking a court under
//  Court Reservations) — there is nothing to scan.
//
//  This stub stays in place so phones with the old page saved to
//  their home screen land on Open Play instead of a 404.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

setFlash('info', 'QR passes have been replaced by the live Open Play queue — join below.');
redirect('public/open_play.php');
