<?php
// ============================================================
//  FILE: api/matches.php
// ============================================================
require_once __DIR__ . '/_api_helpers.php';
require_once __DIR__ . '/../tournament/tournament_engine.php';

$engine = new TournamentEngine();
$method = $_SERVER['REQUEST_METHOD'];
$matchId = isset($_GET['match_id']) ? (int)$_GET['match_id'] : null;
$tid     = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : null;

try {
    // GET /api/matches?tournament_id=X
    if ($method === 'GET' && $tid) {
        apiSuccess($engine->getBracket($tid));
    }

    // POST /api/matches?match_id=X  — record result (admin)
    if ($method === 'POST' && $matchId) {
        $session = requireAdmin();
        $body    = getRequestBody();

        $required = ['winner_id', 'score_player1', 'score_player2'];
        foreach ($required as $f) {
            if (!isset($body[$f])) apiError("Field '{$f}' is required.");
        }

        $engine->recordMatchResult(
            $matchId,
            (int) $body['winner_id'],
            (int) $body['score_player1'],
            (int) $body['score_player2'],
            (int) $session['user_id']
        );
        apiSuccess(null, 'Match result recorded.');
    }

    apiError('Invalid request.', 400);
} catch (RuntimeException $e) {
    apiError($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[API/matches] ' . $e->getMessage());
    apiError('Internal server error.', 500);
}