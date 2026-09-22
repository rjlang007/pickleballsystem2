<?php
// ============================================================
//  FILE: admin/kiosk_tournament_data.php
//  Tournament-mode polling endpoint for the kiosk display —
//  sibling to kiosk_data.php, same 10s polling cadence, but a
//  separate JSON payload so the working walk-in-queue polling in
//  kiosk_data.php is now retired (see that file).
//
//  Returns whether the given court currently has a tournament
//  match assigned to it (pending or in_progress), and if so: both
//  players, live score, who's serving, the round name (e.g.
//  "Semifinal"), and whether this is a "big moment" match
//  (semifinal/final) that should get the dramatic full-screen
//  kiosk layout.
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Same access level as the page itself (the kiosk pages use
// requireStaff()) — the retired kiosk_data.php only checked isAdmin(), which
// would 403 out staff-role users; don't repeat that here.
if (!isLoggedIn() || !in_array(currentUserRole(), STAFF_ROLES, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$db      = getDB();
$courtId = (int)($_GET['court_id'] ?? 0);

$empty = [
    'has_match'    => false,
    'court_id'     => $courtId,
    'server_time'  => time(),
];

if (!$courtId) {
    echo json_encode($empty);
    exit;
}

$match = $db->prepare("
    SELECT m.id, m.tournament_id, m.bracket_round, m.bracket_section, m.status,
           m.score_player1, m.score_player2, m.serving_player, m.serving_side,
           t.name AS tournament_name, t.bracket_type,
           COALESCE(p1.display_name, p1.full_name) AS p1_name,
           COALESCE(p2.display_name, p2.full_name) AS p2_name
      FROM falcon.tournament_matches m
      JOIN falcon.tournaments t ON t.id = m.tournament_id
 LEFT JOIN falcon.users p1 ON p1.id = m.player1_id
 LEFT JOIN falcon.users p2 ON p2.id = m.player2_id
     WHERE m.court_id = :cid
       AND m.status IN ('pending', 'in_progress')
     ORDER BY (m.status = 'in_progress') DESC, m.bracket_round DESC
     LIMIT 1
");
$match->execute([':cid' => $courtId]);
$m = $match->fetch();

if (!$m) {
    echo json_encode($empty);
    exit;
}

// Round name from bracket depth (single/double elimination only —
// round-robin/swiss don't have a meaningful "final" round).
$roundName  = 'Round ' . (int)$m['bracket_round'];
$bigMoment  = false;

if (in_array($m['bracket_type'], ['single_elimination', 'double_elimination'], true)
    && $m['bracket_section'] === 'main') {
    $stmt = $db->prepare("
        SELECT MAX(bracket_round) FROM falcon.tournament_matches
         WHERE tournament_id = :tid AND bracket_section = 'main'
    ");
    $stmt->execute([':tid' => $m['tournament_id']]);
    $maxRound = (int)$stmt->fetchColumn();

    $round = (int)$m['bracket_round'];
    if ($maxRound > 0) {
        if ($round === $maxRound) {
            $roundName = 'Championship';
            $bigMoment = true;
        } elseif ($round === $maxRound - 1) {
            $roundName = 'Semifinal';
            $bigMoment = true;
        } elseif ($round === $maxRound - 2) {
            $roundName = 'Quarterfinal';
        }
    }
}

echo json_encode([
    'has_match'       => true,
    'court_id'        => $courtId,
    'match_id'        => (int)$m['id'],
    'tournament_name' => $m['tournament_name'],
    'round_name'      => $roundName,
    'big_moment'      => $bigMoment,
    'status'          => $m['status'],
    'p1_name'         => $m['p1_name'] ?: 'TBD',
    'p2_name'         => $m['p2_name'] ?: 'TBD',
    'score_player1'   => (int)$m['score_player1'],
    'score_player2'   => (int)$m['score_player2'],
    'serving_player'  => $m['serving_player'] !== null ? (int)$m['serving_player'] : null,
    'serving_side'    => $m['serving_side'],
    'server_time'     => time(),
]);
