<?php
// ============================================================
//  FILE: staff/api/tournament_ops.php
//  Staff bracket-builder actions: check-in, randomize/start,
//  manual matchup swap, referee+court assignment, round naming.
//  Admin/super_admin can also call this (STAFF_ROLES).
// ============================================================
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../tournament/tournament_engine.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn() || !isStaff()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

function respond(array $p, int $code = 200): never {
    http_response_code($code);
    echo json_encode($p);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['ok' => false, 'error' => 'POST required'], 405);
}

verifyCsrf();

$db     = getDB();
$user   = currentUser();
$uid    = (int)$user['id'];
$raw    = file_get_contents('php://input');
$body   = json_decode($raw, true) ?: [];
$action = $body['action'] ?? '';
$engine = new TournamentEngine();

try {
    switch ($action) {

        case 'toggle_checkin':
            $tid = (int)($body['tournament_id'] ?? 0);
            $pid = (int)($body['player_id'] ?? 0);
            $ci  = !empty($body['checked_in']);
            $engine->setCheckedIn($tid, $pid, $ci, $uid);
            respond(['ok' => true]);

        case 'randomize_start':
            $tid  = (int)($body['tournament_id'] ?? 0);
            $mode = ($body['mode'] ?? 'random') === 'rank' ? 'rank' : 'random';
            $bracket = $engine->startTournament($tid, $mode);
            respond(['ok' => true, 'bracket' => $bracket]);

        case 'manual_swap':
            $engine->manualSwapPlayers(
                (int)$body['match_a'], $body['slot_a'],
                (int)$body['match_b'], $body['slot_b'],
                $uid
            );
            respond(['ok' => true]);

        case 'assign_officials':
            $engine->assignMatchOfficials(
                (int)$body['match_id'],
                !empty($body['referee_id']) ? (int)$body['referee_id'] : null,
                !empty($body['court_id']) ? (int)$body['court_id'] : null,
                $uid
            );
            respond(['ok' => true]);

        case 'set_round_name':
            $matchId = (int)($body['match_id'] ?? 0);
            $name    = trim((string)($body['round_name'] ?? ''));
            $db->prepare("UPDATE falcon.tournament_matches SET round_name = ? WHERE id = ?")
               ->execute([$name !== '' ? $name : null, $matchId]);
            respond(['ok' => true]);

        default:
            respond(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    error_log('[staff/tournament_ops] ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'Server error. Please try again.'], 500);
}
