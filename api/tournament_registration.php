<?php
// ============================================================
//  FILE: api/tournament_registration.php
// ============================================================
require_once __DIR__ . '/_api_helpers.php';
require_once __DIR__ . '/../tournament/tournament_engine.php';

$engine  = new TournamentEngine();
$method  = $_SERVER['REQUEST_METHOD'];
$tid     = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : null;
if (!$tid) apiError('tournament_id required.');

try {
    $session = requireAuth();
    $pid     = (int) $session['user_id'];

    // POST = join
    if ($method === 'POST') {
        $engine->registerPlayer($tid, $pid);
        apiSuccess(null, 'Successfully joined tournament.');
    }

    // DELETE = leave
    if ($method === 'DELETE') {
        $engine->withdrawPlayer($tid, $pid);
        apiSuccess(null, 'Successfully withdrawn from tournament.');
    }

    // GET = player list
    if ($method === 'GET') {
        apiSuccess($engine->getTournamentPlayers($tid));
    }

    apiError('Method not allowed.', 405);
} catch (RuntimeException $e) {
    apiError($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[API/registration] ' . $e->getMessage());
    apiError('Internal server error.', 500);
}