<?php
// ============================================================
//  FILE: api/open_play.php
//  Single dispatch endpoint for the Open Play module.
//
//  Player actions  (any logged-in player):
//    POST ?action=join            multipart: tournament_id, skill_level,
//                                 payment_method, reference_no, payment_proof
//    POST ?action=leave           { tournament_id }
//
//  Staff/admin actions (requireStaff — staff, admin, super_admin):
//    POST ?action=queue_status    { tournament_id, player_id, status }
//    POST ?action=draw            { tournament_id, max_games? }
//    POST ?action=update_event    { tournament_id, name, description?, max_players?, format?, game_duration? }
//    POST ?action=cancel_event    { tournament_id }
//    POST ?action=start_match     { match_id }
//    POST ?action=pause_match     { match_id }
//    POST ?action=resume_match    { match_id }
//    POST ?action=adjust_timer    { match_id, delta_seconds }
//    POST ?action=finish_match    { match_id, score_a, score_b, send_to_rest?, confirmed? }
//    POST ?action=correct_score   { match_id, score_a, score_b, confirmed? }
//    POST ?action=cancel_match    { match_id }
//    POST ?action=tiebreak        { tournament_id, player_ids: [] }
//    POST ?action=raffle_spin     { tournament_id, prize_description, exclude_previous_winners? }
//    POST ?action=finalize        { tournament_id }
//
//  finish_match / correct_score may come back with HTTP 409 and
//  errors.needs_confirmation = true when the score looks unusual for
//  pickleball (not to 11/15/21, or not won by 2) — that's not a
//  failure, it's a "are you sure?" checkpoint. Resend the same
//  request with confirmed: true to save it anyway.
//
//  Read (any logged-in user):
//    GET  ?action=roster&tournament_id=X
//    GET  ?action=leaderboard&tournament_id=X
//    GET  ?action=ties&tournament_id=X
//
//  Staff-only reads:
//    GET  ?action=raffle_data&tournament_id=X
//    GET  ?action=raffle_history&tournament_id=X
//
//  All state changes go through OpenPlayEngine so the same rules
//  apply everywhere (staff console, future mobile client, etc).
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/_api_helpers.php';
require_once __DIR__ . '/../tournament/open_play_engine.php';

$engine = new OpenPlayEngine();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Staff-only actions run court/queue operations for everyone at the venue.
$staffActions = [
    'queue_status', 'draw', 'start_match', 'pause_match', 'resume_match',
    'adjust_timer', 'finish_match', 'correct_score', 'cancel_match', 'tiebreak',
    'approve_join', 'reject_join', 'add_player', 'remove_player',
    'confirm_match', 'sweep_no_shows',
    'finalize', 'create', 'update_event', 'cancel_event',
    'raffle_spin', 'raffle_data', 'raffle_history',
];

try {
    // FIX (this pass): every other mutating fetch()-based API (wallet,
    // community, food, chat, chat_rooms, bracket_edit) got a
    // verifySameOrigin() + rate-limit pass in the security hardening round;
    // this dispatcher was added later (open play is a newer module) and
    // was missed, leaving score entry / match control / event finalize
    // reachable cross-site with nothing but the session cookie. Bringing
    // it in line with the rest of api/*.php.
    verifySameOrigin();
    if (in_array($action, $staffActions, true)) {
        $session = requireStaffApi();
    } else {
        $session = requireAuth();
    }
    $actorId = (int)$session['user_id'];
    if (!checkRateLimit('api_open_play_' . $actorId, 60, 60)) {
        apiError('Too many requests. Please wait.', 429);
    }
    $body    = getRequestBody();

    switch ($action) {

        // ── Player self-service ────────────────────────────────
        case 'join':
            routeMethod('POST');
            $tid   = (int)($body['tournament_id'] ?? 0);
            $skill = (string)($body['skill_level'] ?? 'average');
            if (!$tid) apiError('tournament_id required.');
            $event = $engine->getEvent($tid);
            if (!$event) apiError('Open Play event not found.', 404);
            $settings = json_decode($event['settings'] ?? '{}', true) ?: [];
            $amount = round((float)($settings['price'] ?? 0), 2);
            if ($amount <= 0) {
                $engine->joinEvent($tid, $actorId, $skill);
                apiSuccess(null, 'Joined the free Open Play queue.');
            }
            if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
                apiError('Upload payment_proof for a paid Open Play event.');
            }
            $file = $_FILES['payment_proof'];
            if ((int)$file['size'] > 5 * 1024 * 1024) apiError('Payment proof must be 5 MB or smaller.');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime])) apiError('Payment proof must be a JPG, PNG, or WebP image.');
            $uploadDir = __DIR__ . '/../uploads/open_play_payments';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true)) apiError('Could not prepare payment upload storage.', 500);
            $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) apiError('Could not save payment proof.', 500);
            $engine->joinEvent($tid, $actorId, $skill, [
                'payment_method' => $body['payment_method'] ?? '',
                'reference_no' => $body['reference_no'] ?? '',
                'proof_path' => 'uploads/open_play_payments/' . $filename,
            ]);
            apiSuccess(null, 'Payment proof submitted. You are now in the Open Play queue.');

        case 'leave':
            routeMethod('POST');
            $tid = (int)($body['tournament_id'] ?? 0);
            if (!$tid) apiError('tournament_id required.');
            $engine->leaveEvent($tid, $actorId);
            apiSuccess(null, 'Left the event.');

        case 'rest':
            routeMethod('POST');
            $tid = (int)($body['tournament_id'] ?? 0);
            if (!$tid) apiError('tournament_id required.');
            $engine->markResting($tid, $actorId, $actorId);
            apiSuccess($engine->getPlayerEventStatus($tid, $actorId), 'You took a break.');

        case 'return':
            routeMethod('POST');
            $tid = (int)($body['tournament_id'] ?? 0);
            if (!$tid) apiError('tournament_id required.');
            $engine->returnToQueue($tid, $actorId, $actorId);
            apiSuccess($engine->getPlayerEventStatus($tid, $actorId), 'You rejoined the queue.');

        case 'confirm_match':
            routeMethod('POST');
            $engine->confirmMatch((int)($body['match_id'] ?? 0), $actorId);
            apiSuccess(null, 'Check-in confirmed.');

        case 'sweep_no_shows':
            routeMethod('POST');
            apiSuccess(['cancelled' => $engine->sweepNoShows((int)($body['tournament_id'] ?? 0), $actorId)]);

        // ── Staff: event + roster ───────────────────────────────
        case 'create':
            routeMethod('POST');
            apiSuccess($engine->createEvent($body, $actorId), 'Open play event created.');

        case 'update_event':
            routeMethod('POST');
            apiSuccess($engine->updateEvent((int)($body['tournament_id'] ?? 0), $body, $actorId), 'Event updated.');

        case 'cancel_event':
            routeMethod('POST');
            $engine->cancelEvent((int)($body['tournament_id'] ?? 0), $actorId);
            apiSuccess(null, 'Event cancelled.');

        case 'queue_status':
            routeMethod('POST');
            $tid = (int)($body['tournament_id'] ?? 0);
            $engine->setQueueStatus(
                $tid,
                (int)($body['player_id'] ?? 0),
                (string)($body['status'] ?? ''),
                $actorId
            );
            apiSuccess($tid ? $engine->getRoster($tid) : null, 'Updated.');

        case 'approve_join':
            routeMethod('POST');
            $tid = (int)($body['tournament_id'] ?? 0);
            $engine->approveJoin($tid, (int)($body['player_id'] ?? 0), $actorId);
            apiSuccess($tid ? $engine->getRoster($tid) : null, 'Join request approved.');

        case 'reject_join':
            routeMethod('POST');
            $tid = (int)($body['tournament_id'] ?? 0);
            $engine->rejectJoin($tid, (int)($body['player_id'] ?? 0), $actorId);
            apiSuccess($tid ? $engine->getRoster($tid) : null, 'Join request rejected.');

        case 'add_player':
            routeMethod('POST');
            $tid = (int)($body['tournament_id'] ?? 0);
            if (!$tid) apiError('tournament_id required.');
            $skillLevel = (string)($body['skill_level'] ?? 'average');
            $playerId = (int)($body['player_id'] ?? 0);
            if ($playerId > 0) {
                $engine->addPlayerByStaff($tid, $playerId, $skillLevel, $actorId);
            } else {
                $name = trim((string)($body['player_name_lookup'] ?? ''));
                if ($name === '') apiError('Enter a player name.');
                $engine->addGuestByStaff($tid, $name, $skillLevel, $actorId);
            }
            apiSuccess($engine->getRoster($tid), 'Player registered successfully.');

        case 'remove_player':
            routeMethod('POST');
            $tid = (int)($body['tournament_id'] ?? 0);
            if (!$tid) apiError('tournament_id required.');
            $engine->leaveEvent($tid, (int)($body['player_id'] ?? 0));
            apiSuccess($engine->getRoster($tid), 'Player removed from registration.');

        // ── Staff: matchmaking / draw ────────────────────────────
        case 'draw':
            routeMethod('POST');
            $tid = (int)($body['tournament_id'] ?? 0);
            if (!$tid) apiError('tournament_id required.');
            $max = isset($body['max_games']) ? (int)$body['max_games'] : null;
            apiSuccess($engine->drawRound($tid, $actorId, $max), 'Round drawn.');

        // ── Staff: match/court control ───────────────────────────
        case 'start_match':
            routeMethod('POST');
            apiSuccess($engine->startMatch((int)($body['match_id'] ?? 0), $actorId), 'Match started.');

        case 'pause_match':
            routeMethod('POST');
            apiSuccess($engine->pauseMatch((int)($body['match_id'] ?? 0), $actorId), 'Match paused.');

        case 'resume_match':
            routeMethod('POST');
            apiSuccess($engine->resumeMatch((int)($body['match_id'] ?? 0), $actorId), 'Match resumed.');

        case 'adjust_timer':
            routeMethod('POST');
            apiSuccess($engine->adjustTimer(
                (int)($body['match_id'] ?? 0),
                (int)($body['delta_seconds'] ?? 0),
                $actorId
            ), 'Timer adjusted.');

        case 'finish_match':
            routeMethod('POST');
            apiSuccess($engine->finishMatch(
                (int)($body['match_id'] ?? 0),
                (int)($body['score_a'] ?? -1),
                (int)($body['score_b'] ?? -1),
                $actorId,
                (bool)($body['send_to_rest'] ?? false),
                (bool)($body['confirmed'] ?? false)
            ), 'Match recorded.');

        case 'correct_score':
            routeMethod('POST');
            apiSuccess($engine->correctScore(
                (int)($body['match_id'] ?? 0),
                (int)($body['score_a'] ?? -1),
                (int)($body['score_b'] ?? -1),
                $actorId,
                (bool)($body['confirmed'] ?? false)
            ), 'Score corrected.');

        case 'cancel_match':
            routeMethod('POST');
            $engine->cancelMatch((int)($body['match_id'] ?? 0), $actorId);
            apiSuccess(null, 'Match cancelled.');

        // ── Staff: finals ─────────────────────────────────────────
        case 'tiebreak':
            routeMethod('POST');
            apiSuccess($engine->createTiebreakGame(
                (int)($body['tournament_id'] ?? 0),
                (array)($body['player_ids'] ?? []),
                $actorId
            ), 'Tiebreaker game created.');

        case 'raffle_data':
            routeMethod('GET');
            $tid = (int)($_GET['tournament_id'] ?? 0);
            apiSuccess([
                'participants' => $engine->getRaffleParticipants($tid),
                'latest_draw'  => $engine->getLatestRaffleDraw($tid),
            ]);

        case 'raffle_spin':
            routeMethod('POST');
            apiSuccess($engine->drawRaffle(
                (int)($body['tournament_id'] ?? 0),
                (string)($body['prize_description'] ?? ''),
                $actorId,
                (bool)($body['exclude_previous_winners'] ?? false)
            ), 'Raffle drawn.');

        case 'raffle_history':
            routeMethod('GET');
            $tid = (int)($_GET['tournament_id'] ?? 0);
            apiSuccess($engine->getRaffleHistory($tid));

        case 'finalize':
            routeMethod('POST');
            apiSuccess($engine->finalizeEvent((int)($body['tournament_id'] ?? 0), $actorId), 'Event finalized.');

        // ── Reads ──────────────────────────────────────────────
        case 'roster':
            routeMethod('GET');
            $tid = (int)($_GET['tournament_id'] ?? 0);
            apiSuccess($engine->getRoster($tid));

        case 'leaderboard':
            routeMethod('GET');
            $tid = (int)($_GET['tournament_id'] ?? 0);
            apiSuccess($engine->computeLeaderboard($tid));

        case 'ties':
            routeMethod('GET');
            $tid = (int)($_GET['tournament_id'] ?? 0);
            apiSuccess($engine->detectPodiumTies($engine->computeLeaderboard($tid)));

        case 'player_status':
            routeMethod('GET');
            $tid = (int)($_GET['tournament_id'] ?? 0);
            if (!$tid) apiError('tournament_id required.');
            apiSuccess($engine->getPlayerEventStatus($tid, $actorId));

        default:
            apiError('Unknown action.', 404);
    }
} catch (OpenPlayNeedsConfirmationException $e) {
    apiError($e->getMessage(), 409, ['needs_confirmation' => true]);
} catch (RuntimeException $e) {
    apiError($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[API/open_play] ' . $e->getMessage());
    apiError('Internal server error.', 500);
}

/**
 * JSON-safe equivalent of config/security.php's requireStaff() — that
 * one redirects on failure (fine for a page), which would corrupt a
 * fetch() response here. Re-checks the live role from the DB the same
 * way, but fails with a proper 401/403 JSON body instead.
 */
function requireStaffApi(): array
{
    if (!isLoggedIn()) apiError('Authentication required.', 401);
    $liveRole = currentUserRole();
    if (!in_array($liveRole, STAFF_ROLES, true)) apiError('Staff access required.', 403);
    $_SESSION['role'] = $liveRole;
    return $_SESSION;
}
