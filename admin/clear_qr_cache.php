<?php
// ============================================================
//  FILE: admin/clear_qr_cache.php  — RETIRED
//
//  Wiped cached pass QR / barcode PNGs out of the temp directory
//  when their rendering size changed. No pass images are
//  generated anymore, so there is no cache to clear.
//
//  Payment QR images (top-ups / GCash) are uploaded assets, not
//  generated ones, and were never handled here.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

setFlash('info', 'Pass QR generation was retired — there is no QR cache to clear.');
redirect('admin/dashboard.php');
