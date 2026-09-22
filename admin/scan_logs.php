<?php
// ============================================================
//  FILE: admin/scan_logs.php  — RETIRED
//
//  Audit trail of QR/barcode scans at the door. With scanner
//  check-in removed nothing writes to falcon.scan_logs anymore,
//  so this page could only ever show a frozen history.
//
//  The equivalent live record is now the Open Play console, which
//  tracks who joined, who is queued and who played each round.
//  Historical scan rows are left untouched in the database for
//  anyone who needs to query them directly.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

setFlash('info', 'Scan logs were retired with scanner check-in. Open Play activity is tracked here.');
redirect('staff/open_play_control.php');
