<?php
// ============================================================
//  FILE: court/scanner.php  — RETIRED
//
//  The multi-court QR/barcode scanner station is gone. Live floor
//  operations — who is playing, who is next, starting and finishing
//  games — all run from the Open Play console, which is the system
//  of record for the queue.
//
//  Redirects rather than 404s so the scanner PC's pinned tab,
//  kiosk browsers and staff bookmarks all land somewhere useful.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireStaff();

redirect('staff/open_play_control.php');
