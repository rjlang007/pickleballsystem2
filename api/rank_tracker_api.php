<?php
// ============================================================
//  FILE: api/rank_tracker_api.php
//  REST API for player rank history and projections.
// ============================================================
require_once __DIR__ . '/_api_helpers.php';
require_once __DIR__ . '/../leaderboard/rank_tracker.php';

header('Content-Type: application/json');

$playerId = isset($_GET['player_id']) ? (int) $_GET['player_id'] : null;
$months   = isset($_GET['months']) ? (int) $_GET['months'] : 6;
$estimate = isset($_GET['estimate']) ? (bool) $_GET['estimate'] : false;

if (!$playerId) {
    http_response_code(400);
    echo json_encode(['error' => 'player_id is required']);
    exit;
}

try {
    $tracker = new RankTracker();
    $data = [
        'history' => $tracker->getRankHistory($playerId, $months),
    ];

    if ($estimate) {
        $data['estimate'] = $tracker->estimateFutureRank($playerId);
    }

    echo json_encode(['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[rank_tracker_api.php] ' . $e->getMessage());
    echo json_encode(['error' => 'Server error. Please try again.']);
}
