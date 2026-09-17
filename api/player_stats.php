<?php
// ============================================================
//  FILE: api/player_stats.php
//  REST API for player statistics.
// ============================================================
require_once __DIR__ . '/_api_helpers.php';
require_once __DIR__ . '/../leaderboard/player_stats_engine.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$playerId = isset($_GET['player_id']) ? (int) $_GET['player_id'] : null;
$season   = isset($_GET['season']) ? (int) $_GET['season'] : date('Y');
$type     = isset($_GET['type']) ? $_GET['type'] : 'overview';
$opponentId = isset($_GET['opponent_id']) ? (int) $_GET['opponent_id'] : null;

if (!$playerId) {
    http_response_code(400);
    echo json_encode(['error' => 'player_id is required']);
    exit;
}

try {
    $engine = new PlayerStatsEngine();
    
    if ($type === 'overview') {
        $data = $engine->getPlayerSeasonStats($playerId, $season);
    } elseif ($type === 'history') {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
        $data = $engine->getTournamentHistory($playerId, $limit, $offset);
    } elseif ($type === 'achievements') {
        $data = $engine->getPlayerAchievements($playerId);
    } elseif ($type === 'ranking_history') {
        $months = isset($_GET['months']) ? (int) $_GET['months'] : 6;
        $data = $engine->getRankingHistory($playerId, $months);
    } elseif ($type === 'head_to_head' && $opponentId) {
        $data = $engine->getHeadToHeadStats($playerId, $opponentId, $season);
    } else {
        throw new Exception('Invalid type or missing opponent_id for head_to_head');
    }
    
    echo json_encode(['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[player_stats.php] ' . $e->getMessage());
    echo json_encode(['error' => 'Server error. Please try again.']);
}
