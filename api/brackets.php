<?php
// ============================================================
//  FILE: api/brackets.php
//
//  FIX (this pass): this file used to only require _api_helpers.php +
//  tournament_engine.php, never config/app.php. That means session_start()
//  never ran and isLoggedIn()/$_SESSION weren't even defined on this
//  request, so the POST branch's requireAdmin() call fataled with
//  "Call to undefined function isLoggedIn()" for every admin, every time.
//  The GET (read-only bracket view) branch happened to still work because
//  it never touches auth. Bootstrapping through config/app.php fixes the
//  POST path and also brings in CSRF/rate-limiting like every other
//  mutating api/*.php endpoint.
// ============================================================
require_once __DIR__ . '/../config/app.php';
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
        verifySameOrigin();
        if (!checkRateLimit('bracket_start_' . (int)($session['user_id'] ?? 0), 20, 60)) {
            apiError('Too many requests. Please wait.', 429);
        }
        $body    = getRequestBody();
        $tid     = (int)($body['tournament_id'] ?? 0);
        if (!$tid) apiError('tournament_id required.');
        $t = $engine->startTournament($tid);
        apiSuccess($engine->getBracket($tid), 'Bracket generated.');
    }
    apiError('Invalid request.', 400);
} catch (Throwable $e) {
    error_log('[api/brackets] ' . $e->getMessage());
    apiError('Server error. Please try again.');
}