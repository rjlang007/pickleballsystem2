<?php
// ============================================================
//  FILE: api/leaderboard.php
// ============================================================
require_once __DIR__ . '/_api_helpers.php';
require_once __DIR__ . '/../leaderboard/leaderboard_engine.php';

routeMethod('GET');
$engine = new LeaderboardEngine();

$playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : null;
$monthly  = isset($_GET['monthly']);
$season   = isset($_GET['season'])  ? (int)$_GET['season']  : null;
$month    = isset($_GET['month'])   ? (int)$_GET['month']   : null;
$limit    = isset($_GET['limit'])   ? (int)$_GET['limit']   : 0;
$offset   = isset($_GET['offset'])  ? (int)$_GET['offset']  : 0;

try {
    if ($playerId) {
        apiSuccess($engine->getPlayerStats($playerId, $season));
    }
    if ($monthly) {
        apiSuccess($engine->getMonthlyLeaderboard($season ?? (int)date('Y'), $month));
    }
    apiSuccess($engine->getLeaderboard($season, $limit, $offset));
} catch (Throwable $e) {
    error_log('[API/leaderboard] ' . $e->getMessage());
    apiError('Internal server error.', 500);
}