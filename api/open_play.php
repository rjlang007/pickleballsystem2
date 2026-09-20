<?php
// ============================================================
//  FILE: api/open_play.php
//  Single dispatch endpoint for the Open Play module.
//
//  Player actions  (any logged-in player):
//    POST ?action=join            { tournament_id, skill_level }
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
    'approve_join', 'reject_join',
    'confirm_match', 'sweep_no_shows',
    'finalize', 'create', 'update_event', 'cancel_event',
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
            $engine->joinEvent($tid, $actorId, $skill);
            apiSuccess(null, 'Joined the open play queue.');

        case 'leave':
            routeMethod('POST');
            $tid = (int)($body['tournament_id'] ?? 0);
            if (!$tid) apiError('tournament_id required.');
            $engine->leaveEvent($tid, $actorId);
            apiSuccess(null, 'Left the event.');

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
            $engine->setQueueStatus(
                (int)($body['tournament_id'] ?? 0),
                (int)($body['player_id'] ?? 0),
                (string)($body['status'] ?? ''),
                $actorId
            );
            apiSuccess(null, 'Updated.');

        case 'approve_join':
            routeMethod('POST');
            $engine->approveJoin((int)($body['tournament_id'] ?? 0), (int)($body['player_id'] ?? 0), $actorId);
            apiSuccess(null, 'Join request approved.');

        case 'reject_join':
            routeMethod('POST');
            $engine->rejectJoin((int)($body['tournament_id'] ?? 0), (int)($body['player_id'] ?? 0), $actorId);
            apiSuccess(null, 'Join request rejected.');

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
