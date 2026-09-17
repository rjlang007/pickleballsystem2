<?php
// ============================================================
//  FILE: referee/score_ajax.php
//  Live scoring actions for referee/score_match.php.
//  Every action writes straight to the DB (no separate "publish"
//  step) so the update shows up on other dashboards/kiosk within
//  one polling cycle, and nothing is lost if this device reloads.
//
//  POST JSON:
//    { action: "point",     match_id, player_slot: 1|2, delta: 1|-1 }
//    { action: "server",    match_id, player_slot: 1|2, side: "left"|"right" }
//    { action: "correct",   match_id, score_player1, score_player2, note? }
//    { action: "complete",  match_id }
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST required.']);
    exit;
}
if (!isLoggedIn() || !in_array(currentUserRole(), REFEREE_ROLES, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Referee access required.']);
    exit;
}

require_once __DIR__ . '/../tournament/tournament_engine.php';

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$action   = $body['action']   ?? '';
$matchId  = isset($body['match_id']) ? (int)$body['match_id'] : 0;
$actorId  = (int)($_SESSION['user_id'] ?? 0);

if (!$matchId) {
    echo json_encode(['ok' => false, 'message' => 'Missing match_id.']);
    exit;
}

$engine = new TournamentEngine();
$match  = $engine->getMatchForScoring($matchId);
if (!$match) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Match not found.']);
    exit;
}

// A referee may only score their own assigned match; admin/super_admin can score any match.
$isOwner = (int)($match['referee_id'] ?? 0) === $actorId;
if (!$isOwner && !in_array(currentUserRole(), ADMIN_ROLES, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'You are not assigned to this match.']);
    exit;
}

try {
    switch ($action) {
        case 'point':
            $slot  = (int)($body['player_slot'] ?? 0);
            $delta = (int)($body['delta'] ?? 0);
            if (!in_array($delta, [1, -1], true)) {
                throw new InvalidArgumentException('delta must be 1 or -1.');
            }
            $result = $engine->recordPoint($matchId, $slot, $delta, $actorId);
            echo json_encode(['ok' => true] + $result);
            break;

        case 'server':
            $slot = (int)($body['player_slot'] ?? 0);
            $side = trim($body['side'] ?? '');
            $engine->setServer($matchId, $slot, $side, $actorId);
            echo json_encode(['ok' => true, 'serving_player' => $slot, 'serving_side' => $side]);
            break;

        case 'correct':
            $s1   = (int)($body['score_player1'] ?? 0);
            $s2   = (int)($body['score_player2'] ?? 0);
            $note = trim($body['note'] ?? '');
            $engine->correctScore($matchId, $s1, $s2, $actorId, $note);
            echo json_encode(['ok' => true, 'score_player1' => $s1, 'score_player2' => $s2]);
            break;

        case 'complete':
            $engine->completeMatch($matchId, $actorId);
            echo json_encode(['ok' => true, 'status' => 'completed']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    http_response_code(400);
    error_log('[migrations/referee/score_ajax] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Unable to save the score.']);
}
