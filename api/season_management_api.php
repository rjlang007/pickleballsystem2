<?php
// ============================================================
//  FILE: api/season_management_api.php
//  Admin season management API.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../leaderboard/rank_tracker.php';

requireAdmin();

// SECURITY FIX: this is the most severe of the group — `reset`/`archive`/
// `recompute` take no parameters at all, so with the old GET fallback and
// no CSRF check, a single crafted link or auto-loading <img> tag visited
// by a logged-in admin could wipe the current season's leaderboard with
// zero user interaction beyond the page load. Now POST-only + CSRF-verified.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
verifyCsrf();
if (!checkRateLimit('season_mgmt_' . (int)($_SESSION['user_id'] ?? 0), 10, 300)) {
    http_response_code(429);
    exit('Too many requests. Please wait a few minutes before trying again.');
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'reset':
        RankTracker::resetSeason();
        setFlash('success', 'The current season leaderboard has been reset.');
        break;
    case 'archive':
        RankTracker::archiveSeason();
        setFlash('success', 'The current season has been archived successfully.');
        break;
    case 'recompute':
        RankTracker::recomputeRanks();
        setFlash('success', 'Ranks were recomputed for the active season.');
        break;
    default:
        http_response_code(400);
        echo 'Invalid season management action.';
        exit;
}

header('Location: ' . APP_URL . '/admin/season_management.php');
exit;
