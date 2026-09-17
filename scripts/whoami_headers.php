<?php
// ============================================================
//  scripts/whoami_headers.php
//
//  One-time diagnostic: shows exactly what your app sees for the
//  connecting IP right now, on your actual deployment. Use this to
//  fill in TRUSTED_PROXY_IPS correctly instead of guessing.
//
//  WHY THIS EXISTS: which header Railway uses to carry the real
//  client IP (and whether it's the first or last entry in
//  X-Forwarded-For) has changed / been reported inconsistent by
//  Railway's own support during their CDN rollout. Rather than
//  hardcode an assumption that might already be stale by the time
//  you read this, hit this page from your own browser (not curl from
//  this machine) and compare X-Forwarded-For / X-Real-Ip / REMOTE_ADDR
//  against your actual public IP (e.g. from https://ifconfig.me).
//
//  SECURITY: restricted to superadmins. Delete this file once you've
//  set TRUSTED_PROXY_IPS — it has no ongoing purpose and reveals
//  server-side header/network detail.
// ============================================================

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireSuperAdmin();

header('Content-Type: text/plain');

echo "REMOTE_ADDR:            " . ($_SERVER['REMOTE_ADDR'] ?? '(none)') . "\n";
echo "X-Forwarded-For:        " . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '(none)') . "\n";
echo "X-Real-Ip:               " . ($_SERVER['HTTP_X_REAL_IP'] ?? '(none)') . "\n";
echo "X-Envoy-External-Address:" . ($_SERVER['HTTP_X_ENVOY_EXTERNAL_ADDRESS'] ?? '(none)') . "\n";
echo "Fastly-Client-Ip:       " . ($_SERVER['HTTP_FASTLY_CLIENT_IP'] ?? '(none)') . "\n";
echo "CF-Connecting-IP:       " . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '(none)') . "\n";
echo "\n";
echo "Compare the values above to your actual public IP (check https://ifconfig.me\n";
echo "in the same browser). Whichever header/position matches your real IP is what\n";
echo "getClientIp() in includes/security_helpers.php should trust — and REMOTE_ADDR\n";
echo "above is the value to put in TRUSTED_PROXY_IPS (as a single IP or, more\n";
echo "durably, the /8 or /16 range it falls in, since edge IPs rotate).\n";
