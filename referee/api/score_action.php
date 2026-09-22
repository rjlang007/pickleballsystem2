<?php
// ============================================================
//  FILE: referee/api/score_action.php
//  Referee live-scoring endpoint.
//   GET  -> current match state (used for polling / reconnect
//           recovery — score state lives server-side only).
//   POST -> mutate state (point, correction, side_out,
//           server_position, complete_game, complete_match,
//           claim). Every mutation is logged to
//           falcon.tournament_match_events so nothing is lost
//           and every change is attributable to a referee.
//
//  All writes commit immediately — no separate "publish" step.
//  Admin/staff/superadmin/kiosk/player screens read the same
//  tournament_matches / tournament_match_events rows on their
//  next poll cycle.
// ============================================================
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../tournament/tournament_engine.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isLoggedIn() || !isReferee()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$db   = getDB();
$user = currentUser();
$uid  = (int)$user['id'];

function respond(array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function loadMatch(PDO $db, int $matchId): ?array {
    $stmt = $db->prepare("
        SELECT m.*,
               t.name AS tournament_name, t.bracket_type,
               p1.full_name AS p1_name, p2.full_name AS p2_name,
               c.name AS court_name
          FROM falcon.tournament_matches m
          JOIN falcon.tournaments t ON t.id = m.tournament_id
     LEFT JOIN falcon.users p1 ON p1.id = m.player1_id
     LEFT JOIN falcon.users p2 ON p2.id = m.player2_id
     LEFT JOIN falcon.courts c ON c.id = m.scheduled_court
         WHERE m.id = ?
    ");
    $stmt->execute([$matchId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function logEvent(PDO $db, int $matchId, int $gameNo, string $type, int $s1, int $s2,
                   ?int $server, ?string $pos, ?string $note, int $uid): void {
    $db->prepare("
        INSERT INTO falcon.tournament_match_events
            (match_id, game_number, event_type, score_player1, score_player2,
             server, server_position, note, created_by)
        VALUES (?,?,?,?,?,?,?,?,?)
    ")->execute([$matchId, $gameNo, $type, $s1, $s2, $server, $pos, $note, $uid]);
}

function assertAssigned(array $match, int $uid): void {
    // A referee may act on a match once it's assigned to them, or if
    // unassigned (self-claim path handles that separately below).
    if (!empty($match['referee_id']) && (int)$match['referee_id'] !== $uid) {
        respond(['ok' => false, 'error' => 'This match is assigned to a different referee.'], 403);
    }
}

$matchId = (int)($_GET['match_id'] ?? $_POST['match_id'] ?? 0);
if ($matchId <= 0) respond(['ok' => false, 'error' => 'match_id required'], 400);

$match = loadMatch($db, $matchId);
if (!$match) respond(['ok' => false, 'error' => 'Match not found'], 404);

// ── GET: poll current state ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(['ok' => true, 'match' => $match]);
}

// ── POST: mutate ────────────────────────────────────────────────
verifyCsrf();

$raw    = file_get_contents('php://input');
$body   = json_decode($raw, true) ?: [];
$action = $body['action'] ?? '';

if (!in_array($match['status'], ['pending', 'in_progress'], true)) {
    respond(['ok' => false, 'error' => 'This match is already ' . $match['status'] . '.'], 409);
}
if (empty($match['player1_id']) || empty($match['player2_id'])) {
    respond(['ok' => false, 'error' => 'Both players must be set before scoring.'], 409);
}

try {
    switch ($action) {

        // Referee claims an unassigned match and starts it.
        case 'claim':
            assertAssigned($match, $uid);
            $db->prepare("
                UPDATE falcon.tournament_matches
                   SET referee_id = COALESCE(referee_id, ?),
                       status     = CASE WHEN status = 'pending' THEN 'in_progress' ELSE status END,
                       started_at = COALESCE(started_at, NOW())
                 WHERE id = ?
            ")->execute([$uid, $matchId]);
            logEvent($db, $matchId, (int)$match['current_game'], 'point',
                (int)$match['score_player1'], (int)$match['score_player2'],
                (int)$match['current_server'], $match['server_position'], 'Match claimed/started', $uid);
            break;

        // +1 / -1 point for a side. Only the serving side scores in
        // side-out rules; if the receiving side wins the rally the
        // caller should send 'side_out' instead. Referee has full
        // discretion via the buttons, so we simply record what is
        // reported and let the referee use Side Out for non-scoring
        // rallies — this keeps the data entry unambiguous.
        case 'point':
            assertAssigned($match, $uid);
            $team  = (int)($body['team'] ?? 0);
            $delta = (int)($body['delta'] ?? 1);
            if (!in_array($team, [1, 2], true)) respond(['ok' => false, 'error' => 'Invalid team'], 400);

            $col = $team === 1 ? 'score_player1' : 'score_player2';
            $new = max(0, (int)$match[$col] + $delta);

            $db->prepare("UPDATE falcon.tournament_matches SET {$col} = ? WHERE id = ?")
               ->execute([$new, $matchId]);

            $s1 = $team === 1 ? $new : (int)$match['score_player1'];
            $s2 = $team === 2 ? $new : (int)$match['score_player2'];
            logEvent($db, $matchId, (int)$match['current_game'], 'point', $s1, $s2,
                (int)$match['current_server'], $match['server_position'], null, $uid);
            break;

        // Manual correction — always logged separately from normal
        // scoring so a referee overwriting a point is never silent.
        case 'correction':
            assertAssigned($match, $uid);
            $s1   = max(0, (int)($body['score_player1'] ?? $match['score_player1']));
            $s2   = max(0, (int)($body['score_player2'] ?? $match['score_player2']));
            $note = trim((string)($body['note'] ?? ''));
            if ($note === '') respond(['ok' => false, 'error' => 'A reason is required for score corrections.'], 400);

            $db->prepare("
                UPDATE falcon.tournament_matches SET score_player1 = ?, score_player2 = ? WHERE id = ?
            ")->execute([$s1, $s2, $matchId]);

            logEvent($db, $matchId, (int)$match['current_game'], 'correction', $s1, $s2,
                (int)$match['current_server'], $match['server_position'], $note, $uid);

            $db->prepare("
                INSERT INTO falcon.tournament_bracket_audit
                    (tournament_id, match_id, actor_id, actor_role, action, before_state, after_state, note)
                VALUES (?,?,?,?, 'score_correction', ?, ?, ?)
            ")->execute([
                $match['tournament_id'], $matchId, $uid, $user['role'],
                json_encode(['score_player1' => $match['score_player1'], 'score_player2' => $match['score_player2']]),
                json_encode(['score_player1' => $s1, 'score_player2' => $s2]),
                $note,
            ]);
            break;

        // Side out: serve passes to the other side, position resets to right.
        case 'side_out':
            assertAssigned($match, $uid);
            $newServer = (int)$match['current_server'] === 1 ? 2 : 1;
            $db->prepare("
                UPDATE falcon.tournament_matches
                   SET current_server = ?, server_position = 'right'
                 WHERE id = ?
            ")->execute([$newServer, $matchId]);
            logEvent($db, $matchId, (int)$match['current_game'], 'side_out',
                (int)$match['score_player1'], (int)$match['score_player2'],
                $newServer, 'right', null, $uid);
            break;

        // Toggle the server's court position (left/right) after a point
        // won on serve, without a side-out.
        case 'server_position':
            assertAssigned($match, $uid);
            $pos = $match['server_position'] === 'right' ? 'left' : 'right';
            $db->prepare("UPDATE falcon.tournament_matches SET server_position = ? WHERE id = ?")
               ->execute([$pos, $matchId]);
            logEvent($db, $matchId, (int)$match['current_game'], 'server_change',
                (int)$match['score_player1'], (int)$match['score_player2'],
                (int)$match['current_server'], $pos, null, $uid);
            break;

        // Close out the current game: bump games_won, reset score,
        // advance current_game, or finish the match if a side has
        // reached games_to_win.
        case 'complete_game':
            assertAssigned($match, $uid);
            $s1 = (int)$match['score_player1'];
            $s2 = (int)$match['score_player2'];
            if ($s1 === $s2) respond(['ok' => false, 'error' => 'Scores are tied — game cannot be completed.'], 400);

            $gameWinner = $s1 > $s2 ? 1 : 2;
            $gw1 = (int)$match['games_won_p1'] + ($gameWinner === 1 ? 1 : 0);
            $gw2 = (int)$match['games_won_p2'] + ($gameWinner === 2 ? 1 : 0);
            $needed = max(1, (int)$match['games_to_win']);

            logEvent($db, $matchId, (int)$match['current_game'], 'game_complete', $s1, $s2,
                null, null, "Game {$match['current_game']} won by team {$gameWinner}", $uid);

            if ($gw1 >= $needed || $gw2 >= $needed) {
                // Match is decided — hand off to TournamentEngine so the
                // bracket advances and standings recompute automatically.
                $winnerId = $gameWinner === 1 ? (int)$match['player1_id'] : (int)$match['player2_id'];
                $db->prepare("
                    UPDATE falcon.tournament_matches
                       SET games_won_p1 = ?, games_won_p2 = ?
                     WHERE id = ?
                ")->execute([$gw1, $gw2, $matchId]);

                $engine = new TournamentEngine();
                $engine->recordMatchResult($matchId, $winnerId, $gw1, $gw2, $uid);

                logEvent($db, $matchId, (int)$match['current_game'], 'match_complete', $gw1, $gw2,
                    null, null, "Match complete — winner: team {$gameWinner}", $uid);
            } else {
                $db->prepare("
                    UPDATE falcon.tournament_matches
                       SET games_won_p1 = ?, games_won_p2 = ?,
                           current_game = current_game + 1,
                           score_player1 = 0, score_player2 = 0,
                           current_server = 1, server_position = 'right'
                     WHERE id = ?
                ")->execute([$gw1, $gw2, $matchId]);
            }
            break;

        default:
            respond(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    error_log('[referee/score_action] ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'Server error. Please try again.'], 500);
}

respond(['ok' => true, 'match' => loadMatch($db, $matchId)]);
