<?php
// ============================================================
//  FILE: player/my_barcode.php  — RETIRED
//
//  The scannable barcode pass was the fallback for players whose
//  phone screen wouldn't read as a QR code. Both are retired with
//  the scanner check-in flow; Open Play is now queue-based.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

setFlash('info', 'Barcode passes have been replaced by the live Open Play queue — join below.');
redirect('public/open_play.php');
