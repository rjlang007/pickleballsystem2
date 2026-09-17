<?php
// ============================================================
//  FILE: api/tournament_api.php
//  REST API endpoints for tournament operations.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../tournament/tournament_engine.php';
require_once __DIR__ . '/../includes/helpers.php';

requireAdmin();

// SECURITY FIX: every case above except get_tournament/list_tournaments
// mutates data (create/delete/start/cancel a tournament, record match
// results, add/remove players...) and previously accepted `action` from
// GET with no CSRF check — a crafted link could delete or cancel a
// tournament with zero form interaction. Read-only actions stay
// GET-friendly; everything else now requires POST + a valid CSRF token.
$readOnlyActions = ['get_tournament', 'list_tournaments'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (!in_array($action, $readOnlyActions, true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }
    verifyCsrf();
    if (!checkRateLimit('tournament_api_' . (int)($_SESSION['user_id'] ?? 0), 30, 60)) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait.']);
        exit;
    }
    $action = $_POST['action'] ?? '';
}
$engine = new TournamentEngine();
$adminId = (int) ($_SESSION['user_id'] ?? 0);

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'create':
            $data = [
                'name'         => trim($_POST['name'] ?? ''),
                'description'  => trim($_POST['description'] ?? ''),
                'bracket_type' => $_POST['bracket_type'] ?? 'single_elimination',
                'max_players'  => (int)($_POST['max_players'] ?? 16),
                'start_date'   => !empty($_POST['start_date']) ? $_POST['start_date'] : null,
                'end_date'     => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                'featured'     => !empty($_POST['featured']),
            ];

            $tournament = $engine->createTournament($data, $adminId);
            echo json_encode(['success' => true, 'tournament' => $tournament]);
            break;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('Tournament ID required.');

            $data = [
                'name'         => trim($_POST['name'] ?? ''),
                'description'  => trim($_POST['description'] ?? ''),
                'bracket_type' => $_POST['bracket_type'] ?? null,
                'max_players'  => (int)($_POST['max_players'] ?? 0),
                'start_date'   => !empty($_POST['start_date']) ? $_POST['start_date'] : null,
                'end_date'     => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                'featured'     => isset($_POST['featured']) ? (bool)$_POST['featured'] : null,
            ];

            $engine->updateTournament($id, $data, $adminId);
            $tournament = $engine->getTournament($id);
            echo json_encode(['success' => true, 'tournament' => $tournament]);
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('Tournament ID required.');

            $engine->deleteTournament($id);
            echo json_encode(['success' => true]);
            break;

        case 'open_registration':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('Tournament ID required.');

            $engine->openRegistration($id);
            $tournament = $engine->getTournament($id);
            echo json_encode(['success' => true, 'tournament' => $tournament]);
            break;

        case 'start':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('Tournament ID required.');

            $bracket = $engine->startTournament($id);
            $tournament = $engine->getTournament($id);
            echo json_encode(['success' => true, 'tournament' => $tournament, 'bracket' => $bracket]);
            break;

        case 'complete':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('Tournament ID required.');

            $engine->completeTournament($id, $adminId);
            $tournament = $engine->getTournament($id);
            echo json_encode(['success' => true, 'tournament' => $tournament]);
            break;

        case 'cancel':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('Tournament ID required.');

            $engine->cancelTournament($id);
            $tournament = $engine->getTournament($id);
            echo json_encode(['success' => true, 'tournament' => $tournament]);
            break;

        case 'regenerate_bracket':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('Tournament ID required.');

            $bracket = $engine->regenerateBracket($id);
            echo json_encode(['success' => true, 'bracket' => $bracket]);
            break;

        case 'record_match':
            $matchId = (int)($_POST['match_id'] ?? 0);
            $winnerId = (int)($_POST['winner_id'] ?? 0);
            $scoreP1 = (int)($_POST['score_player1'] ?? 0);
            $scoreP2 = (int)($_POST['score_player2'] ?? 0);

            if (!$matchId || !$winnerId) {
                throw new RuntimeException('Match ID and winner ID required.');
            }

            $engine->recordMatchResult($matchId, $winnerId, $scoreP1, $scoreP2, $adminId);
            echo json_encode(['success' => true]);
            break;

        case 'add_player':
            $tournamentId = (int)($_POST['tournament_id'] ?? 0);
            $playerId = (int)($_POST['player_id'] ?? 0);

            if (!$tournamentId || !$playerId) {
                throw new RuntimeException('Tournament ID and Player ID required.');
            }

            $engine->addPlayer($tournamentId, $playerId);
            echo json_encode(['success' => true]);
            break;

        case 'remove_player':
            $tournamentId = (int)($_POST['tournament_id'] ?? 0);
            $playerId = (int)($_POST['player_id'] ?? 0);

            if (!$tournamentId || !$playerId) {
                throw new RuntimeException('Tournament ID and Player ID required.');
            }

            $engine->removePlayer($tournamentId, $playerId);
            echo json_encode(['success' => true]);
            break;

        case 'get_tournament':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) throw new RuntimeException('Tournament ID required.');

            $tournament = $engine->getTournament($id);
            if (!$tournament) throw new RuntimeException('Tournament not found.');

            echo json_encode(['success' => true, 'tournament' => $tournament]);
            break;

        case 'list_tournaments':
            $filters = [];
            if (isset($_GET['status'])) $filters['status'] = $_GET['status'];
            if (isset($_GET['bracket_type'])) $filters['bracket_type'] = $_GET['bracket_type'];
            if (isset($_GET['featured'])) $filters['featured'] = (bool)$_GET['featured'];

            $tournaments = $engine->listTournaments($filters);
            echo json_encode(['success' => true, 'tournaments' => $tournaments]);
            break;

        default:
            throw new RuntimeException('Invalid action.');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}