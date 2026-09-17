<?php
// ============================================================
//  FILE: api/tournaments.php
//  Endpoints: GET list, POST create, GET single, PUT update
// ============================================================
require_once __DIR__ . '/_api_helpers.php';
require_once __DIR__ . '/../tournament/tournament_engine.php';

$engine = new TournamentEngine();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

try {
    // GET /api/tournaments[?status=&bracket_type=&featured=]
    if ($method === 'GET' && !$id) {
        $filters = array_filter([
            'status'       => $_GET['status']       ?? null,
            'bracket_type' => $_GET['bracket_type'] ?? null,
            'featured'     => isset($_GET['featured']) ? (bool)$_GET['featured'] : null,
        ], fn($v) => $v !== null);
        apiSuccess($engine->listTournaments($filters));
    }

    // GET /api/tournaments?id=X
    if ($method === 'GET' && $id) {
        $t = $engine->getTournament($id);
        if (!$t) apiError('Tournament not found.', 404);
        $t['players'] = $engine->getTournamentPlayers($id);
        apiSuccess($t);
    }

    // POST /api/tournaments  (admin only)
    if ($method === 'POST') {
        $session = requireAdmin();
        $body    = getRequestBody();
        $t       = $engine->createTournament($body, (int) $session['user_id']);
        apiSuccess($t, 'Tournament created.');
    }

    // PUT /api/tournaments?id=X  (admin only)
    if ($method === 'PUT' && $id) {
        requireAdmin();
        $body   = getRequestBody();
        $action = $body['action'] ?? '';

        switch ($action) {
            case 'open_registration':
                $engine->openRegistration($id);
                apiSuccess(null, 'Registration opened.');
            case 'start':
                $t = $engine->startTournament($id);
                apiSuccess($t, 'Tournament started and bracket generated.');
            case 'complete':
                $session = requireAdmin();
                $engine->completeTournament($id, (int)$session['user_id']);
                apiSuccess(null, 'Tournament completed and leaderboard updated.');
            case 'cancel':
                $engine->cancelTournament($id);
                apiSuccess(null, 'Tournament cancelled.');
            default:
                apiError('Unknown action. Use: open_registration, start, complete, cancel.');
        }
    }

    apiError('Invalid request.', 400);

} catch (InvalidArgumentException $e) {
    apiError($e->getMessage(), 422);
} catch (RuntimeException $e) {
    apiError($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[API/tournaments] ' . $e->getMessage());
    apiError('Internal server error.', 500);
}