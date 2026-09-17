<?php
// ============================================================
//  FILE: api/brackets.php
// ============================================================
require_once __DIR__ . '/_api_helpers.php';
require_once __DIR__ . '/../tournament/tournament_engine.php';

routeMethod('GET', 'POST');
$engine = new TournamentEngine();
$tid    = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $tid) {
        apiSuccess($engine->getBracket($tid));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $session = requireAdmin();
        $body    = getRequestBody();
        $tid     = (int)($body['tournament_id'] ?? 0);
        if (!$tid) apiError('tournament_id required.');
        $t = $engine->startTournament($tid);
        apiSuccess($engine->getBracket($tid), 'Bracket generated.');
    }
    apiError('Invalid request.', 400);
} catch (Throwable $e) {
    apiError($e->getMessage());
}