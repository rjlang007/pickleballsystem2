<?php
// ============================================================
//  FILE: tournament/open_play_engine.php
//
//  OPEN PLAY ("random pairing") — a second tournament format
//  that sits alongside TournamentEngine's bracket types.
//
//  Where a bracket tournament generates every match up front,
//  Open Play draws matches live, a few at a time, from a pool
//  of checked-in players:
//
//    waiting pool ──(drawRound)──▶ ready matches ──▶ courts
//         ▲                                            │
//         └──────────── finishMatch() returns players ──┘
//
//  Players register the same way as any tournament
//  (falcon.tournament_players — reuse registerPlayer() /
//  withdrawPlayer() from TournamentEngine) with an added
//  skill_level + queue_status. Matches live in their own
//  table, falcon.open_play_matches, because — unlike a bracket
//  — the full set of matches isn't known ahead of time.
//
//  Matchmaking is a straight port of the fairness/skill-balance
//  algorithm from the standalone "Dink Board" open-play app, with
//  one hard rule applied before any of it: a team's skill
//  *composition* (the pair of skill levels making up that team)
//  restricts which team compositions it's allowed to face at all
//  — see OpenPlayEngine::COMPOSITION_MATCHUPS. For example, a
//  beginner+beginner team can only be drawn against another
//  beginner+beginner team; a beginner+advance team can be drawn
//  against another beginner+advance team or an average+average
//  team, and so on. Lineups that break this rule are never
//  candidates, no matter how fair or well-timed they'd otherwise
//  be — this is what actually keeps a 2v2 balanced, since a raw
//  "sum of skill scores" comparison alone would happily approve
//  beginner+advance vs. average+average-looking totals that are
//  numerically equal but not fair to play.
//
//  Among the lineups that pass that rule:
//    1. Prefer players who've played the fewest games per hour
//       since they arrived (so nobody sits out all night while
//       others double/triple dip).
//    2. Among equally-fair options, prefer the most skill-
//       balanced 2v2 split (team score = sum of BEGINNER=1 /
//       AVERAGE=2 / ADVANCE=3) as a tiebreaker.
//    3. Among those, avoid repeating recent partners/opponents.
//    4. Ties broken by who's been waiting longest, then by a
//       genuine random draw ("spin the wheel") — see
//       drawRound()'s $candidates shuffle.
// ============================================================

require_once __DIR__ . '/../config/tournament_config.php';
require_once __DIR__ . '/../includes/helpers.php'; // notifyUser()

/**
 * A score was entered that doesn't look like a normal pickleball
 * result (not to 11/15/21, or not won by 2) — thrown so the caller
 * can show a confirmation step rather than a hard rejection. Staff
 * can legitimately hit this for time-capped games, so it's a soft
 * warning, not a block: call again with $confirmed = true to save it
 * anyway.
 */
class OpenPlayNeedsConfirmationException extends RuntimeException {}

class OpenPlayEngine
{
    private PDO $db;
    private array $config;

    private const SKILL_SCORE = ['beginner' => 1, 'average' => 2, 'advance' => 3];

    /**
     * Which doubles-team "compositions" (the two skill levels making up a
     * team, e.g. beginner+advance) are allowed to face which other
     * compositions. Keyed by a sorted "loScore-hiScore" pair (see
     * compositionKey()). Every possible 2-player team falls into exactly
     * one of these six composition buckets:
     *
     *   1-1  beginner + beginner
     *   1-2  average  + beginner
     *   1-3  beginner + advance
     *   2-2  average  + average
     *   2-3  average  + advance
     *   3-3  advance  + advance
     *
     * Rules (as specced by the club):
     *   - beginner+beginner  only plays beginner+beginner
     *   - beginner+advance   only plays beginner+advance or average+average
     *   - average+average    only plays average+average or beginner+advance
     *   - average+beginner   only plays average+beginner
     *   - average+advance    only plays average+advance
     *   - advance+advance    only plays advance+advance
     */
    private const COMPOSITION_MATCHUPS = [
        '1-1' => ['1-1'],
        '1-3' => ['1-3', '2-2'],
        '2-2' => ['2-2', '1-3'],
        '1-2' => ['1-2'],
        '2-3' => ['2-3'],
        '3-3' => ['3-3'],
    ];

    public function __construct()
    {
        $this->db     = getDB();
        $this->config = require __DIR__ . '/../config/tournament_config.php';
    }

    // ══════════════════════════════════════════════════════════
    // EVENT LIFECYCLE
    // ══════════════════════════════════════════════════════════

    /** Create a new open-play event. Mirrors TournamentEngine::createTournament(). */
    public function createEvent(array $data, int $adminId): array
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Event name is required.');
        }

        $settings = [
            'format'           => in_array($data['format'] ?? 'doubles', ['singles', 'doubles'], true)
                                    ? $data['format'] : 'doubles',
            'game_duration'    => max(60, (int)($data['game_duration'] ?? 900)),
            'games_per_hour'   => max(1, (int)($data['games_per_hour'] ?? 4)),
            'price'            => max(0, round((float)($data['price'] ?? 0), 2)),
            'registration_closed' => false,
            'point_distribution' => $this->config['point_distribution'],
        ];

        $stmt = $this->db->prepare(
            "INSERT INTO falcon.tournaments
                (name, description, bracket_type, status, max_players, start_date, settings, created_by)
             VALUES (:name, :desc, 'open_play', 'registration_open', :max, :start, :settings::jsonb, :admin)
             RETURNING *"
        );
        $stmt->execute([
            ':name'     => $name,
            ':desc'     => trim((string)($data['description'] ?? '')) ?: null,
            ':max'      => max(4, (int)($data['max_players'] ?? 32)),
            ':start'    => $data['start_date'] ?? date('Y-m-d H:i:s'),
            ':settings' => json_encode($settings),
            ':admin'    => $adminId,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Edit an event's name/description/format/duration/player cap after
     * creation. Duration and format changes only affect the *next* round
     * drawn — matches already created keep the duration_seconds they were
     * given at draw time (it's stored per-row on open_play_matches), so
     * nothing already in progress on a court gets disturbed.
     */
    public function updateEvent(int $tournamentId, array $data, int $actorId): array
    {
        $event = $this->getEvent($tournamentId);
        if (!$event) throw new RuntimeException('Open play event not found.');
        if (in_array($event['status'], ['completed', 'cancelled'], true)) {
            throw new RuntimeException('This event is closed and can no longer be edited.');
        }

        $name = trim((string)($data['name'] ?? $event['name']));
        if ($name === '') throw new RuntimeException('Event name is required.');

        $maxPlayers = isset($data['max_players']) ? max(4, (int)$data['max_players']) : (int)$event['max_players'];
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM falcon.tournament_players WHERE tournament_id = :tid AND status = 'active'"
        );
        $stmt->execute([':tid' => $tournamentId]);
        $activeCount = (int)$stmt->fetchColumn();
        if ($maxPlayers < $activeCount) {
            throw new RuntimeException("Can't set the cap below the {$activeCount} players already in the pool.");
        }

        $settings = json_decode($event['settings'] ?? '{}', true) ?: [];
        if (isset($data['game_duration'])) {
            $settings['game_duration'] = max(60, (int)$data['game_duration']);
        }
        if (isset($data['price'])) {
            $settings['price'] = max(0, round((float)$data['price'], 2));
        }
        if (array_key_exists('start_date', $data)) {
            $startDate = trim((string)$data['start_date']);
        } else {
            $startDate = $event['start_date'] ?? null;
        }
        if (isset($data['format']) && in_array($data['format'], ['singles', 'doubles'], true)) {
            $settings['format'] = $data['format'];
        }

        $this->db->prepare(
            "UPDATE falcon.tournaments
                SET name = :name, description = :desc, max_players = :max, start_date = :start, settings = :settings::jsonb
              WHERE id = :id"
        )->execute([
            ':name' => $name,
            ':desc' => trim((string)($data['description'] ?? ($event['description'] ?? ''))) ?: null,
            ':max'  => $maxPlayers,
            ':start' => $startDate ?: null,
            ':settings' => json_encode($settings),
            ':id'   => $tournamentId,
        ]);

        $this->logAudit($tournamentId, $actorId, 'update_event', ['name' => $name]);
        return $this->getEvent($tournamentId);
    }

    /**
     * Scrap an event outright — distinct from finalizeEvent(): nothing is
     * written to tournament_scores or rolled into the season leaderboard.
     * Any in-flight matches are cancelled and their players are freed up.
     * Irreversible, same as TournamentEngine::cancelTournament() for
     * brackets — mirrors that method's rules (can't cancel something
     * already completed).
     */
    public function pauseEvent(int $tournamentId, int $actorId): array
    {
        $event = $this->getEvent($tournamentId);
        if (!$event) throw new RuntimeException('Open play event not found.');
        if (in_array($event['status'], ['completed', 'cancelled'], true)) {
            throw new RuntimeException('This event is closed and cannot be paused.');
        }
        if ($event['status'] === 'paused') {
            throw new RuntimeException('This event is already paused.');
        }

        $this->db->prepare(
            "UPDATE falcon.tournaments SET status = 'paused' WHERE id = :id"
        )->execute([':id' => $tournamentId]);
        $this->logAudit($tournamentId, $actorId, 'pause_event', []);
        return $this->getEvent($tournamentId);
    }

    public function resumeEvent(int $tournamentId, int $actorId): array
    {
        $event = $this->getEvent($tournamentId);
        if (!$event) throw new RuntimeException('Open play event not found.');
        if (in_array($event['status'], ['completed', 'cancelled'], true)) {
            throw new RuntimeException('This event is closed and cannot be resumed.');
        }
        if ($event['status'] !== 'paused') {
            throw new RuntimeException('Only a paused event can be resumed.');
        }

        $settings = json_decode($event['settings'] ?? '{}', true) ?: [];
        $nextStatus = !empty($settings['registration_closed']) ? 'registration_closed' : 'in_progress';
        $this->db->prepare(
            "UPDATE falcon.tournaments SET status = :status WHERE id = :id"
        )->execute([':status' => $nextStatus, ':id' => $tournamentId]);
        $this->logAudit($tournamentId, $actorId, 'resume_event', []);
        return $this->getEvent($tournamentId);
    }

    public function closeRegistration(int $tournamentId, int $actorId): array
    {
        $event = $this->getEvent($tournamentId);
        if (!$event) throw new RuntimeException('Open play event not found.');
        if (in_array($event['status'], ['completed', 'cancelled'], true)) {
            throw new RuntimeException('This event is already closed.');
        }
        if (!in_array($event['status'], ['registration_open', 'in_progress'], true)) {
            throw new RuntimeException('Registration is already closed or the event is paused.');
        }

        $settings = json_decode($event['settings'] ?? '{}', true) ?: [];
        $settings['registration_closed'] = true;
        $this->db->prepare(
            "UPDATE falcon.tournaments
                SET status = 'registration_closed', settings = :settings::jsonb
              WHERE id = :id"
        )->execute([':settings' => json_encode($settings), ':id' => $tournamentId]);
        $this->logAudit($tournamentId, $actorId, 'close_registration', []);
        return $this->getEvent($tournamentId);
    }

    public function cancelEvent(int $tournamentId, int $actorId): void
    {
        $event = $this->getEvent($tournamentId);
        if (!$event) throw new RuntimeException('Open play event not found.');
        if ($event['status'] === 'completed') throw new RuntimeException('Cannot cancel an event that has already been finalized.');
        if ($event['status'] === 'cancelled') throw new RuntimeException('This event is already cancelled.');

        $this->db->beginTransaction();
        try {
            // Same lock as drawRound()/createTiebreakGame() — don't let a
            // cancel interleave with an in-flight draw for this event.
            $this->db->prepare('SELECT pg_advisory_xact_lock(:key)')->execute([':key' => $tournamentId]);

            $stmt = $this->db->prepare(
                "SELECT id FROM falcon.open_play_matches
                  WHERE tournament_id = :tid AND status IN ('ready','in_progress','paused')"
            );
            $stmt->execute([':tid' => $tournamentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $matchId) {
                $this->db->prepare(
                    "UPDATE falcon.open_play_matches SET status = 'cancelled' WHERE id = :id"
                )->execute([':id' => (int)$matchId]);
            }

            $this->db->prepare(
                "UPDATE falcon.tournament_players SET queue_status = 'left'
                  WHERE tournament_id = :tid AND status != 'withdrawn'"
            )->execute([':tid' => $tournamentId]);

            $this->db->prepare(
                "UPDATE falcon.tournaments SET status = 'cancelled' WHERE id = :id"
            )->execute([':id' => $tournamentId]);

            $eventDate = date('Y-m-d', strtotime((string)($event['start_date'] ?? 'now')));
            $this->db->prepare(
                "INSERT INTO falcon.site_content (section, key, value, updated_at)
                 VALUES ('open_play_schedule', 'closed_for_date', :v, NOW())
                 ON CONFLICT (section, key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()"
            )->execute([':v' => $eventDate]);

            $playerStmt = $this->db->prepare(
                "SELECT player_id FROM falcon.tournament_players WHERE tournament_id = :tid AND status != 'withdrawn'"
            );
            $playerStmt->execute([':tid' => $tournamentId]);
            foreach ($playerStmt->fetchAll(PDO::FETCH_COLUMN) as $playerId) {
                notifyUser(
                    $this->db,
                    (int)$playerId,
                    '🎲 Open Play cancelled',
                    'Tonight’s Open Play session was cancelled. The queue has been closed for this date and the schedule will stay off until an admin re-enables it.',
                    null,
                    APP_URL . '/public/open_play.php'
                );
            }

            $this->logAudit($tournamentId, $actorId, 'cancel_event', ['closed_for_date' => $eventDate]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function closeTonight(int $tournamentId, int $actorId): void
    {
        $event = $this->getEvent($tournamentId);
        if (!$event) throw new RuntimeException('Open play event not found.');
        $this->cancelEvent($tournamentId, $actorId);
        $this->db->prepare(
            "INSERT INTO falcon.site_content (section, key, value, updated_at)
             VALUES ('open_play_schedule', 'closed_for_date', :value, NOW())
             ON CONFLICT (section, key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()"
        )->execute([':value' => date('Y-m-d', strtotime((string)($event['start_date'] ?? 'now')))]);
    }

    public function getEvent(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM falcon.tournaments WHERE id = :id AND bracket_type = 'open_play'"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listEvents(array $filters = []): array
    {
        $sql    = "SELECT * FROM falcon.tournaments WHERE bracket_type = 'open_play'";
        $params = [];
        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }
        $sql .= " ORDER BY start_date DESC NULLS LAST, id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Close the event: derive final standings, write scores, roll into season leaderboard. */
    public function finalizeEvent(int $tournamentId, int $adminId): array
    {
        $event = $this->getEvent($tournamentId);
        if (!$event) throw new RuntimeException('Open play event not found.');
        if ($event['status'] === 'completed') throw new RuntimeException('This event has already been finalized.');
        if ($event['status'] === 'cancelled') throw new RuntimeException('This event was cancelled — cancelled events don\'t get finalized into the season leaderboard.');

        $this->db->beginTransaction();
        try {
            // Same lock key as drawRound() — makes the "any unfinished
            // games?" check and the finalize itself atomic against a draw
            // that might otherwise sneak a new match in between the two.
            $this->db->prepare('SELECT pg_advisory_xact_lock(:key)')->execute([':key' => $tournamentId]);

            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM falcon.open_play_matches
                  WHERE tournament_id = :tid AND status IN ('ready','in_progress','paused')"
            );
            $stmt->execute([':tid' => $tournamentId]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new RuntimeException('Finish or cancel all in-progress games before finalizing.');
            }

            $standings = $this->computeLeaderboard($tournamentId);
            $settings  = json_decode($event['settings'] ?? '{}', true) ?: [];
            $dist      = $settings['point_distribution'] ?? $this->config['point_distribution'];
            $partPts   = (int)($settings['participation_points'] ?? $this->config['participation_points']);

            $placement = 0;
            $prevKey   = null;
            foreach ($standings as $i => $row) {
                $key = implode('|', [$row['wins'], $row['win_pct'], $row['losses'], $row['point_diff']]);
                if ($key !== $prevKey) { $placement = $i + 1; $prevKey = $key; }
                $pts = $dist[$placement] ?? $partPts;

                $this->db->prepare(
                    "INSERT INTO falcon.tournament_scores
                         (tournament_id, player_id, placement, points, note, recorded_by, recorded_at)
                     VALUES (:tid, :pid, :pl, :pts, :note, :admin, NOW())
                     ON CONFLICT (tournament_id, player_id) DO UPDATE SET
                         placement = EXCLUDED.placement, points = EXCLUDED.points,
                         note = EXCLUDED.note, recorded_by = EXCLUDED.recorded_by, recorded_at = NOW()"
                )->execute([
                    ':tid' => $tournamentId, ':pid' => $row['player_id'], ':pl' => $placement,
                    ':pts' => $pts, ':note' => "Open Play — {$row['wins']}W/{$row['losses']}L, diff {$row['point_diff']}",
                    ':admin' => $adminId,
                ]);
            }

            $this->db->prepare(
                "UPDATE falcon.tournaments SET status = 'completed' WHERE id = :id"
            )->execute([':id' => $tournamentId]);

            require_once __DIR__ . '/../leaderboard/leaderboard_engine.php';
            (new LeaderboardEngine())->processTournamentCompletion($tournamentId);

            $this->logAudit($tournamentId, $adminId, 'finalize', ['players' => count($standings)]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $standings;
    }

    // ══════════════════════════════════════════════════════════
    // QUEUE / ROSTER
    // ══════════════════════════════════════════════════════════

    public function joinEvent(int $tournamentId, int $playerId, string $skillLevel = 'average', array $payment = []): void
    {
        $event = $this->getEvent($tournamentId);
        if (!$event) throw new RuntimeException('Open play event not found.');
        $settings = json_decode($event['settings'] ?? '{}', true) ?: [];
        $amount = round((float)($settings['price'] ?? 0), 2);
        if ($amount <= 0) throw new RuntimeException('This Open Play event has no configured price yet.');
        if (!in_array($payment['payment_method'] ?? '', ['gcash', 'bank_transfer', 'cash'], true)) {
            throw new RuntimeException('Select a valid payment method.');
        }
        if (empty($payment['proof_path'])) throw new RuntimeException('Payment proof is required.');

        $pending = $this->db->prepare(
            "SELECT 1 FROM falcon.open_play_payment_requests
              WHERE tournament_id = :tid AND player_id = :pid AND status = 'pending' LIMIT 1"
        );
        $pending->execute([':tid' => $tournamentId, ':pid' => $playerId]);
        if ($pending->fetchColumn()) throw new RuntimeException('Your payment request is already awaiting review.');

        $this->joinEventInternal($tournamentId, $playerId, $skillLevel, false);
        $this->db->prepare(
            "INSERT INTO falcon.open_play_payment_requests
                (tournament_id, player_id, amount, payment_method, reference_no, proof_path)
             VALUES (:tid, :pid, :amount, :method, :reference, :proof)"
        )->execute([
            ':tid' => $tournamentId, ':pid' => $playerId, ':amount' => $amount,
            ':method' => trim((string)($payment['payment_method'] ?? '')),
            ':reference' => trim((string)($payment['reference_no'] ?? '')) ?: null,
            ':proof' => $payment['proof_path'],
        ]);
        notifyOperations($this->db, '💳 Open Play Payment Review', "A player submitted payment proof for '{$event['name']}'.");
    }

    /**
     * Staff adding a player directly at the venue (the "Add Player" box
     * on the control console) already amounts to staff vetting them in
     * person — routing that through the same pending_approval step as a
     * remote self-join would just make staff approve their own action a
     * second time. This skips straight to 'active'/'waiting'.
     */
    public function addPlayerByStaff(int $tournamentId, int $playerId, string $skillLevel, int $actorId): void
    {
        $this->joinEventInternal($tournamentId, $playerId, $skillLevel, true);
        $this->logAudit($tournamentId, $actorId, 'add_player', ['player_id' => $playerId]);
    }

    private function joinEventInternal(int $tournamentId, int $playerId, string $skillLevel, bool $preApproved): void
    {
        $event = $this->getEvent($tournamentId);
        if (!$event) throw new RuntimeException('Open play event not found.');
        if (!in_array($event['status'], ['registration_open', 'in_progress', 'paused'], true)) {
            throw new RuntimeException('This Open Play event is no longer accepting join requests.');
        }

        $skillLevel = in_array($skillLevel, ['beginner', 'average', 'advance'], true) ? $skillLevel : 'average';

        // Enforce the event's player cap — but don't count someone who's
        // already in (this call also covers "re-join after leaving").
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM falcon.tournament_players
              WHERE tournament_id = :tid AND status != 'withdrawn' AND player_id != :pid"
        );
        $stmt->execute([':tid' => $tournamentId, ':pid' => $playerId]);
        if ((int)$stmt->fetchColumn() >= (int)$event['max_players']) {
            throw new RuntimeException('This event is full.');
        }

        $status      = $preApproved ? 'active' : 'pending_approval';
        $queueStatus = $preApproved ? 'waiting' : 'pending_approval';
        $pre         = $preApproved ? 1 : 0;

        // arrival_at/queued_at/arrived_at are left NULL for a fresh
        // pending_approval request — every self-join here requires staff
        // approval, so we don't want the queue-fairness clock (pace =
        // games_played / hours-since-arrival, see drawRound()) to start
        // ticking the moment someone taps "Join" from their phone, maybe
        // hours before they actually show up. approveJoin() below stamps
        // these at the moment staff actually admits them, which is the
        // closest proxy we have for "physically here and waiting." A
        // staff-added (pre-approved) player is, by definition, physically
        // here right now, so their clock starts immediately.
        $this->db->prepare(
            "INSERT INTO falcon.tournament_players
                 (tournament_id, player_id, status, skill_level, queue_status, arrival_at, queued_at, arrived_at)
             VALUES (:tid, :pid, :status, :skill, :qstatus,
                     CASE WHEN :pre1 = 1 THEN NOW() ELSE NULL END,
                     CASE WHEN :pre2 = 1 THEN NOW() ELSE NULL END,
                     CASE WHEN :pre3 = 1 THEN NOW() ELSE NULL END)
             ON CONFLICT (tournament_id, player_id) DO UPDATE SET
                 status = CASE WHEN falcon.tournament_players.status = 'active' THEN 'active' ELSE :status2 END,
                 skill_level = :skill2,
                 queue_status = CASE WHEN falcon.tournament_players.status = 'active' THEN 'waiting' ELSE :qstatus2 END,
                 arrival_at = CASE
                     WHEN falcon.tournament_players.status = 'active' THEN falcon.tournament_players.arrival_at
                     WHEN :pre4 = 1 THEN NOW() ELSE NULL END,
                 queued_at = CASE
                     WHEN falcon.tournament_players.status = 'active' THEN falcon.tournament_players.queued_at
                     WHEN :pre5 = 1 THEN NOW() ELSE NULL END,
                 arrived_at = CASE
                     WHEN falcon.tournament_players.status = 'active' THEN falcon.tournament_players.arrived_at
                     WHEN :pre6 = 1 THEN NOW() ELSE NULL END"
        )->execute([
            ':tid' => $tournamentId, ':pid' => $playerId,
            ':status' => $status, ':qstatus' => $queueStatus, ':skill' => $skillLevel,
            ':status2' => $status, ':qstatus2' => $queueStatus, ':skill2' => $skillLevel,
            ':pre1' => $pre, ':pre2' => $pre, ':pre3' => $pre, ':pre4' => $pre, ':pre5' => $pre, ':pre6' => $pre,
        ]);
    }

    public function approveJoin(int $tournamentId, int $playerId, int $actorId): void
    {
        // arrival_at/arrived_at are still NULL from joinEvent() at this
        // point (see comment there), so these COALESCEs correctly stamp
        // "now" as the moment this player actually enters the queue.
        $stmt = $this->db->prepare(
            "UPDATE falcon.tournament_players
                SET status = 'active', queue_status = 'waiting',
                    arrival_at = COALESCE(arrival_at, arrived_at, NOW()),
                    queued_at = NOW(),
                    arrived_at = COALESCE(arrived_at, NOW())
              WHERE tournament_id = :tid AND player_id = :pid AND status = 'pending_approval'"
        );
        $stmt->execute([':tid' => $tournamentId, ':pid' => $playerId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Join request is no longer pending.');
                $this->db->prepare(
                        "UPDATE falcon.open_play_payment_requests
                                SET status = 'approved', reviewed_by = :actor, reviewed_at = NOW(), updated_at = NOW()
                            WHERE tournament_id = :tid AND player_id = :pid AND status = 'pending'"
                )->execute([':actor' => $actorId, ':tid' => $tournamentId, ':pid' => $playerId]);
        notifyUser($this->db, $playerId, "You're approved for Open Play", 'You are now in the active queue. Watch the live board for your turn.', null, APP_URL . '/public/open_play_live.php?tournament_id=' . $tournamentId);
        $this->logAudit($tournamentId, $actorId, 'approve_join', ['player_id' => $playerId]);
    }

    public function rejectJoin(int $tournamentId, int $playerId, int $actorId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE falcon.tournament_players
                SET status = 'withdrawn', queue_status = 'left'
              WHERE tournament_id = :tid AND player_id = :pid AND status = 'pending_approval'"
        );
        $stmt->execute([':tid' => $tournamentId, ':pid' => $playerId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Join request is no longer pending.');
                $this->db->prepare(
                        "UPDATE falcon.open_play_payment_requests
                                SET status = 'rejected', reviewed_by = :actor, reviewed_at = NOW(), updated_at = NOW()
                            WHERE tournament_id = :tid AND player_id = :pid AND status = 'pending'"
                )->execute([':actor' => $actorId, ':tid' => $tournamentId, ':pid' => $playerId]);
        notifyUser($this->db, $playerId, 'Open Play join request declined', 'Your join request was declined by staff. Please contact the venue if this was unexpected.', null, APP_URL . '/public/open_play.php');
        $this->logAudit($tournamentId, $actorId, 'reject_join', ['player_id' => $playerId]);
    }

    public function leaveEvent(int $tournamentId, int $playerId): void
    {
        $stmt = $this->db->prepare(
            "SELECT queue_status FROM falcon.tournament_players
              WHERE tournament_id = :tid AND player_id = :pid AND status != 'withdrawn'"
        );
        $stmt->execute([':tid' => $tournamentId, ':pid' => $playerId]);
        $queueStatus = $stmt->fetchColumn();
        if ($queueStatus === 'playing' || $queueStatus === 'queued') {
            throw new RuntimeException('You cannot leave after being drawn into a match. Ask staff to cancel or finish the match first.');
        }

        $this->db->prepare(
            "UPDATE falcon.tournament_players SET status = 'withdrawn', queue_status = 'left'
              WHERE tournament_id = :tid AND player_id = :pid"
        )->execute([':tid' => $tournamentId, ':pid' => $playerId]);
    }

    /** Staff can bench a player (bathroom break, injury) without dropping them entirely. */
    public function setQueueStatus(int $tournamentId, int $playerId, string $status, int $actorId): void
    {
        if (!in_array($status, ['waiting', 'resting', 'left'], true)) {
            throw new RuntimeException('Invalid queue status.');
        }
        $current = $this->db->prepare(
            "SELECT status, queue_status FROM falcon.tournament_players
              WHERE tournament_id = :tid AND player_id = :pid"
        );
        $current->execute([':tid' => $tournamentId, ':pid' => $playerId]);
        $player = $current->fetch(PDO::FETCH_ASSOC);
        if (!$player || $player['status'] === 'withdrawn') {
            throw new RuntimeException('Player is not active in this Open Play session.');
        }
        if (in_array($player['queue_status'], ['queued', 'playing'], true)) {
            throw new RuntimeException('A player already assigned to a game cannot be moved from the queue. Cancel or finish that game first.');
        }
        $this->db->prepare(
            "UPDATE falcon.tournament_players
                SET queue_status = CAST(:queue_status AS varchar),
                    queued_at = CASE
                        WHEN CAST(:waiting_status AS varchar) = 'waiting' THEN NOW()
                        ELSE COALESCE(queued_at, NOW())
                    END,
                    arrival_at = COALESCE(arrival_at, arrived_at, NOW())
              WHERE tournament_id = :tid AND player_id = :pid"
        )->execute([
            ':queue_status' => $status,
            ':waiting_status' => $status,
            ':tid' => $tournamentId,
            ':pid' => $playerId,
        ]);
        $this->logAudit($tournamentId, $actorId, 'queue_status', ['player_id' => $playerId, 'status' => $status]);
    }

    public function markResting(int $tournamentId, int $playerId, int $actorId): void
    {
        $this->setQueueStatus($tournamentId, $playerId, 'resting', $actorId);
    }

    public function returnToQueue(int $tournamentId, int $playerId, int $actorId): void
    {
        $this->setQueueStatus($tournamentId, $playerId, 'waiting', $actorId);
    }

    /** Cross-event Open Play record for a player — for their profile / the join page. */
    public function getPlayerHistory(int $playerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.id, t.name, t.status, tp.wins, tp.losses, tp.games_played, ts.placement
               FROM falcon.tournament_players tp
               JOIN falcon.tournaments t ON t.id = tp.tournament_id AND t.bracket_type = 'open_play'
               LEFT JOIN falcon.tournament_scores ts ON ts.tournament_id = t.id AND ts.player_id = tp.player_id
              WHERE tp.player_id = :pid AND tp.status != 'withdrawn' AND tp.games_played > 0
              ORDER BY t.start_date DESC NULLS LAST"
        );
        $stmt->execute([':pid' => $playerId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totals = ['events' => count($events), 'wins' => 0, 'losses' => 0, 'games' => 0, 'firsts' => 0];
        foreach ($events as $e) {
            $totals['wins']   += (int)$e['wins'];
            $totals['losses'] += (int)$e['losses'];
            $totals['games']  += (int)$e['games_played'];
            if ((int)($e['placement'] ?? 0) === 1) $totals['firsts']++;
        }
        return ['events' => $events, 'totals' => $totals];
    }

    public function getRoster(int $tournamentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT tp.*, u.display_name, u.full_name, u.username,
                    pr.id AS payment_request_id, pr.amount AS payment_amount,
                    pr.payment_method, pr.reference_no, pr.proof_path,
                    pr.status AS payment_status, pr.review_note
               FROM falcon.tournament_players tp
               JOIN falcon.users u ON u.id = tp.player_id
          LEFT JOIN falcon.open_play_payment_requests pr
                 ON pr.tournament_id = tp.tournament_id AND pr.player_id = tp.player_id
                AND pr.status = 'pending'
              WHERE tp.tournament_id = :tid AND tp.status IN ('active', 'pending_approval')
              ORDER BY (tp.status = 'pending_approval') DESC, tp.queue_status, tp.arrived_at ASC"
        );
        $stmt->execute([':tid' => $tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getWaitingPool(int $tournamentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT tp.player_id,
                    COALESCE(u.display_name, u.full_name, u.username) AS display_name,
                    u.full_name,
                    CASE WHEN tp.skill_level IN ('beginner', 'average', 'advance')
                        THEN tp.skill_level ELSE 'average' END AS skill_level,
                    tp.games_played,
                    tp.wins, tp.losses,
                    EXTRACT(EPOCH FROM COALESCE(tp.arrival_at, tp.arrived_at, NOW())) AS arrival_epoch,
                    EXTRACT(EPOCH FROM COALESCE(tp.queued_at, tp.arrived_at, NOW())) AS queued_epoch
               FROM falcon.tournament_players tp
               JOIN falcon.users u ON u.id = tp.player_id
                            WHERE tp.tournament_id = :tid
                                AND tp.status != 'withdrawn'
                                AND tp.queue_status = 'waiting'
              ORDER BY COALESCE(tp.queued_at, tp.arrived_at, NOW()) ASC, COALESCE(tp.arrival_at, tp.arrived_at, NOW()) ASC"
        );
        $stmt->execute([':tid' => $tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Recent partners/opponents (last 2 games) — used to discourage repeat matchups. */
    private function getRecentLinks(int $tournamentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT team1_player1_id AS a, team1_player2_id AS b,
                    team2_player1_id AS c, team2_player2_id AS d
               FROM falcon.open_play_matches
              WHERE tournament_id = :tid AND status = 'finished'
              ORDER BY finished_at ASC"
        );
        $stmt->execute([':tid' => $tournamentId]);
        $partners  = [];
        $opponents = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $teamA = array_filter([$m['a'], $m['b']]);
            $teamB = array_filter([$m['c'], $m['d']]);
            foreach ([$teamA, $teamB] as $team) {
                foreach ($team as $p1) {
                    foreach ($team as $p2) {
                        if ($p1 !== $p2) $partners[$p1][$p2] = true;
                    }
                }
            }
            foreach ($teamA as $p1) foreach ($teamB as $p2) { $opponents[$p1][$p2] = true; $opponents[$p2][$p1] = true; }
        }
        return [$partners, $opponents];
    }

    private function getRecentLineups(int $tournamentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT team1_player1_id AS a, team1_player2_id AS b,
                    team2_player1_id AS c, team2_player2_id AS d
               FROM falcon.open_play_matches
              WHERE tournament_id = :tid AND status = 'finished'
              ORDER BY finished_at ASC"
        );
        $stmt->execute([':tid' => $tournamentId]);
        $lineups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $ids = array_values(array_unique(array_filter([$m['a'], $m['b'], $m['c'], $m['d']])));
            if (count($ids) !== 4) continue;
            sort($ids);
            $key = implode('|', $ids);
            $lineups[$key] = ($lineups[$key] ?? 0) + 1;
        }
        return $lineups;
    }

    // ══════════════════════════════════════════════════════════
    // MATCHMAKING ("spin the wheel")
    // ══════════════════════════════════════════════════════════

    /**
     * Fill free courts and maintain a small courtless upcoming buffer.
     * Existing ready and in-progress games are never rebuilt.
     */
    public function drawRound(int $tournamentId, int $actorId, ?int $maxGames = null): array
    {
        $this->sweepNoShows($tournamentId, $actorId);
        // Serialize draws for this event so a double-click (or two staff
        // acting at once) can't compute "free courts" from the same stale
        // snapshot twice and double-book a court or a player. The lock is
        // released automatically when the transaction ends.
        $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT pg_advisory_xact_lock(:key)')->execute([':key' => $tournamentId]);
            $created = $this->drawRoundLocked($tournamentId, $actorId, $maxGames);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $created;
    }

    private function drawRoundLocked(int $tournamentId, int $actorId, ?int $maxGames = null): array
    {
        $event    = $this->getEvent($tournamentId);
        if (!$event) throw new RuntimeException('Open play event not found.');
        if (in_array($event['status'], ['completed', 'cancelled'], true)) {
            throw new RuntimeException('This event is closed — no more rounds can be drawn.');
        }
        if ($event['status'] === 'paused') {
            throw new RuntimeException('Matchmaking is paused for this event. Resume it to draw the next game.');
        }
        $settings = json_decode($event['settings'] ?? '{}', true) ?: [];
        $format   = $settings['format'] ?? 'doubles';
        $duration = (int)($settings['game_duration'] ?? 900);
        $gph      = (float)($settings['games_per_hour'] ?? 4);

        // First draw of the event moves it out of "registration_open" so it
        // shows up under "Happening Now" for players instead of "Open for Signup".
        if ($event['status'] === 'registration_open') {
            $this->db->prepare(
                "UPDATE falcon.tournaments SET status = 'in_progress' WHERE id = :id"
            )->execute([':id' => $tournamentId]);
        }

        $pool = $this->getWaitingPool($tournamentId);
        [$partners, $opponents] = $this->getRecentLinks($tournamentId);
        $recentLineups = $this->getRecentLineups($tournamentId);
        $now = time();
        $freeCourts = $this->getFreeCourtIds($tournamentId);
        $need = $format === 'singles' ? 2 : 4;
        $extraBuffer = min(2, intdiv(max(0, count($pool) - ($need * 2)), $need));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM falcon.open_play_matches
              WHERE tournament_id = :tid AND status = 'ready' AND court_id IS NULL"
        );
        $stmt->execute([':tid' => $tournamentId]);
        $courtlessReady = (int)$stmt->fetchColumn();
        $slots = max(0, count($freeCourts) + $extraBuffer - $courtlessReady);
        if ($maxGames !== null) $slots = min($slots, max(0, $maxGames));
        if ($slots <= 0 || count($pool) < $need) return [];

        $pace = function (array $p) use ($now, $gph) {
            $arrivalEpoch = (float)($p['arrival_epoch'] ?? $p['arrived_epoch'] ?? $now);
            $hours        = max(1 / $gph, ($now - $arrivalEpoch) / 3600);
            return (float)$p['games_played'] / $hours;
        };

        $created = [];
        while (count($created) < $slots && count($pool) >= $need) {
            $game = $format === 'singles'
                ? $this->buildSinglesGame($pool, $pace, $opponents, $recentLineups)
                : $this->buildDoublesGame($pool, $pace, $partners, $opponents, $recentLineups);

            if ($game === null) break;

            $courtId = array_shift($freeCourts) ?: null;
            $matchId = $this->insertMatch($tournamentId, $courtId, $game, $duration, $actorId);
            $created[] = $this->getMatch($matchId);

            $usedIds = array_merge($game['teamA'], $game['teamB']);
            $this->notifyMatchPlayers($usedIds, $created[count($created) - 1]);
            $pool    = array_values(array_filter($pool, fn($p) => !in_array($p['player_id'], $usedIds, true)));

            $this->db->prepare(
                "UPDATE falcon.tournament_players SET queue_status = 'queued'
                  WHERE tournament_id = :tid AND player_id = ANY(:ids)"
            )->execute([':tid' => $tournamentId, ':ids' => '{' . implode(',', $usedIds) . '}']);
        }

        if ($created) {
            $this->logAudit($tournamentId, $actorId, 'draw_round', ['games' => count($created)]);
        }
        return $created;
    }

    /**
     * Evaluate every legal 4-player lineup in the (shuffled) pool and pick
     * the one that is fairest (lowest max games/hour among the four),
     * then most skill-balanced, then least likely to repeat a recent
     * partner/opponent — with genuine randomness breaking any remaining
     * tie. This mirrors the standalone Open Play app's algorithm.
     *
     * A lineup is only "legal" at all if the two teams' skill compositions
     * are allowed to face each other (COMPOSITION_MATCHUPS) — e.g. a
     * beginner+beginner team can never be drawn against a beginner+advance
     * team. That check happens first and disqualifies the pairing entirely,
     * so no amount of fairness/pace advantage can push an unbalanced
     * matchup through. If nothing in the pool satisfies it, this returns
     * null and the round simply draws fewer games until compatible players
     * are available.
     */
    private function buildDoublesGame(array $pool, callable $pace, array $partners, array $opponents, array $recentLineups = []): ?array
    {
        $shuffled = $pool;
        shuffle($shuffled);
        $n = count($shuffled);
        if ($n < 4) return null;

        $best = null;
        for ($a = 0; $a < $n; $a++) {
            for ($b = $a + 1; $b < $n; $b++) {
                for ($c = 0; $c < $n; $c++) {
                    if ($c === $a || $c === $b) continue;
                    for ($d = $c + 1; $d < $n; $d++) {
                        if ($d === $a || $d === $b) continue;

                        $teamA = [$shuffled[$a], $shuffled[$b]];
                        $teamB = [$shuffled[$c], $shuffled[$d]];

                        // Skill-composition rule: e.g. a beginner+beginner team
                        // may only face another beginner+beginner team, a
                        // beginner+advance team may only face beginner+advance
                        // or average+average, etc. — see COMPOSITION_MATCHUPS.
                        // Reject this lineup outright rather than merely
                        // penalizing it, so an unbalanced draw can never win
                        // on pace/fairness alone.
                        if (!$this->compositionsCompatible($teamA, $teamB)) continue;

                        // A complete four-player lineup must never be drawn
                        // again, and players must not face the same opponent
                        // twice. Repeating a teammate is allowed when the
                        // opposing players are different.
                        if (($this->lineupRepeatPenalty($teamA, $teamB, $recentLineups) ?? 0) > 0) continue;
                        if ($this->hasRepeatedOpponent($teamA, $teamB, $opponents)) continue;

                        $all   = array_merge($teamA, $teamB);

                        $maxPace     = max(array_map($pace, $all));
                        $totalPace   = array_sum(array_map($pace, $all));
                        $diff        = abs($this->teamSkill($teamA) - $this->teamSkill($teamB));
                        $repeat      = $this->repeatPenalty($teamA, $teamB, $partners, $opponents);
                        $lineupRepeat = $this->lineupRepeatPenalty($teamA, $teamB, $recentLineups);
                        $waitTime    = max(array_map(fn($p) => (float)($p['queued_epoch'] ?? $p['arrival_epoch'] ?? $p['arrived_epoch'] ?? time()), $all));
                        $arrivalAge  = min(array_map(fn($p) => (float)($p['arrival_epoch'] ?? $p['arrived_epoch'] ?? time()), $all));

                        $cand = compact('teamA', 'teamB', 'maxPace', 'totalPace', 'diff', 'repeat', 'lineupRepeat', 'waitTime', 'arrivalAge');
                        $cand['rand'] = mt_rand();

                        if ($best === null || $this->betterCandidate($cand, $best)) {
                            $best = $cand;
                        }
                    }
                }
            }
        }
        if ($best === null) return null;

        // Coin flip which pair is "Team A" vs "Team B" on the reveal.
        [$ta, $tb] = mt_rand(0, 1) ? [$best['teamA'], $best['teamB']] : [$best['teamB'], $best['teamA']];
        return [
            'teamA' => array_column($ta, 'player_id'),
            'teamB' => array_column($tb, 'player_id'),
            'names' => ['a' => array_column($ta, 'display_name'), 'b' => array_column($tb, 'display_name')],
        ];
    }

    private function buildSinglesGame(array $pool, callable $pace, array $opponents, array $recentLineups = []): ?array
    {
        $shuffled = $pool;
        shuffle($shuffled);
        $n = count($shuffled);
        if ($n < 2) return null;

        $best = null;
        for ($a = 0; $a < $n; $a++) {
            for ($b = $a + 1; $b < $n; $b++) {
                $pair       = [$shuffled[$a], $shuffled[$b]];
                $maxPace    = max(array_map($pace, $pair));
                $totalPace  = array_sum(array_map($pace, $pair));
                $diff       = abs(self::SKILL_SCORE[$pair[0]['skill_level']] - self::SKILL_SCORE[$pair[1]['skill_level']]);
                $repeat     = isset($opponents[$pair[0]['player_id']][$pair[1]['player_id']]) ? 1 : 0;
                $lineupRepeat = $this->lineupRepeatPenalty($pair, [], $recentLineups);
                if (isset($opponents[$pair[0]['player_id']][$pair[1]['player_id']])) continue;
                $waitTime   = max(array_map(fn($p) => (float)($p['queued_epoch'] ?? $p['arrival_epoch'] ?? $p['arrived_epoch'] ?? time()), $pair));
                $arrivalAge = min(array_map(fn($p) => (float)($p['arrival_epoch'] ?? $p['arrived_epoch'] ?? time()), $pair));
                $cand = compact('pair', 'maxPace', 'totalPace', 'diff', 'repeat', 'lineupRepeat', 'waitTime', 'arrivalAge');
                $cand['rand'] = mt_rand();
                if ($best === null || $this->betterCandidate($cand, $best, true)) $best = $cand;
            }
        }
        if ($best === null) return null;
        $pair = $best['pair'];
        if (mt_rand(0, 1)) $pair = array_reverse($pair);
        return [
            'teamA' => [$pair[0]['player_id']],
            'teamB' => [$pair[1]['player_id']],
            'names' => ['a' => [$pair[0]['display_name']], 'b' => [$pair[1]['display_name']]],
        ];
    }

    /** Priority: fairness (pace) > skill balance > freshness (no repeats) > exact lineup repetition > longest wait > earlier arrival > random. */
    private function betterCandidate(array $cand, array $best, bool $singles = false): bool
    {
        if ($cand['maxPace']   !== $best['maxPace'])   return $cand['maxPace']   < $best['maxPace'];
        if ($cand['totalPace'] !== $best['totalPace']) return $cand['totalPace'] < $best['totalPace'];
        if ($cand['repeat']    !== $best['repeat'])    return $cand['repeat']    < $best['repeat'];
        if (($cand['lineupRepeat'] ?? 0) !== ($best['lineupRepeat'] ?? 0)) return ($cand['lineupRepeat'] ?? 0) < ($best['lineupRepeat'] ?? 0);
        if ($cand['diff']      !== $best['diff'])      return $cand['diff']      < $best['diff'];
        if ($cand['waitTime']  !== $best['waitTime'])  return $cand['waitTime']  < $best['waitTime'];
        if (($cand['arrivalAge'] ?? 0) !== ($best['arrivalAge'] ?? 0)) return ($cand['arrivalAge'] ?? 0) < ($best['arrivalAge'] ?? 0);
        return $cand['rand'] < $best['rand'];
    }

    private function teamSkill(array $team): int
    {
        return array_sum(array_map(fn($p) => self::SKILL_SCORE[$p['skill_level']], $team));
    }

    /** Sorted "loScore-hiScore" key identifying a team's skill composition — see COMPOSITION_MATCHUPS. */
    private function compositionKey(array $team): string
    {
        $scores = array_map(fn($p) => self::SKILL_SCORE[$p['skill_level']], $team);
        sort($scores);
        return implode('-', $scores);
    }

    /** Is $teamB an allowed opponent composition for $teamA, per COMPOSITION_MATCHUPS? */
    private function compositionsCompatible(array $teamA, array $teamB): bool
    {
        $keyA = $this->compositionKey($teamA);
        $keyB = $this->compositionKey($teamB);
        return in_array($keyB, self::COMPOSITION_MATCHUPS[$keyA] ?? [], true)
            && in_array($keyA, self::COMPOSITION_MATCHUPS[$keyB] ?? [], true);
    }

    private function repeatPenalty(array $teamA, array $teamB, array $partners, array $opponents): int
    {
        $penalty = 0;
        foreach ([$teamA, $teamB] as $team) {
            [$p1, $p2] = $team;
            if (isset($partners[$p1['player_id']][$p2['player_id']])) $penalty += 4;
        }
        foreach ($teamA as $a) foreach ($teamB as $b) {
            if (isset($opponents[$a['player_id']][$b['player_id']])) $penalty += 1;
        }
        return $penalty;
    }

    /** Return true when any player in one team has already faced a player in the other team. */
    private function hasRepeatedOpponent(array $teamA, array $teamB, array $opponents): bool
    {
        foreach ($teamA as $playerA) {
            foreach ($teamB as $playerB) {
                if (isset($opponents[$playerA['player_id']][$playerB['player_id']])) return true;
            }
        }
        return false;
    }

    private function lineupRepeatPenalty(array $teamA, array $teamB, array $recentLineups): int
    {
        $ids = array_values(array_unique(array_filter(array_merge(
            array_map(fn($p) => (int)$p['player_id'], $teamA),
            array_map(fn($p) => (int)$p['player_id'], $teamB)
        ))));
        if (count($ids) !== 4) return 0;
        sort($ids);
        $key = implode('|', $ids);
        return (int)($recentLineups[$key] ?? 0) * 20;
    }

    /** Ping each player in a newly-drawn match so they don't have to babysit the live board. */
    private function notifyMatchPlayers(array $playerIds, array $match): void
    {
        $courtBit = $match['court_name'] ? "on {$match['court_name']}" : '(court assigning shortly)';
        $link     = 'public/open_play_live.php?tournament_id=' . $match['tournament_id'];
        foreach (array_unique($playerIds) as $pid) {
            try {
                notifyUser($this->db, (int)$pid, '🎲 You\'re up!', "Your open play game is ready $courtBit.", null, $link);
            } catch (Throwable $e) {
                // best-effort — a missed notification shouldn't block the draw
            }
        }
    }

    // ══════════════════════════════════════════════════════════
    // COURTS
    // ══════════════════════════════════════════════════════════

    /**
     * Courts that are physically free for Open Play right now — i.e. not
     * already running an open-play game, AND not off-limits for other
     * reasons your booking system already knows about:
     *   - under maintenance
     *   - covered by a pending/confirmed reservation right now
     *   - occupied by the separate walk-in/credits queue system
     *     (falcon.game_sessions, driven by the QR kiosk) — it shares the
     *     same "open_play" court_slot_modes designation as this module, so
     *     it's checked explicitly rather than assumed away
     *   - explicitly set to "reservation" mode for this exact moment via
     *     Court Mode (falcon.court_slot_modes) — a court with no rule
     *     configured is treated as available, since staff running an
     *     Open Play event shouldn't have to pre-configure every court
     *     just to use it.
     */
    private function getFreeCourtIds(int $tournamentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.id FROM falcon.courts c
              WHERE c.is_active = TRUE
                AND COALESCE(c.is_maintenance, FALSE) = FALSE
                AND c.id NOT IN (
                    SELECT court_id FROM falcon.open_play_matches
                     WHERE tournament_id = :tid AND status IN ('ready','in_progress','paused')
                       AND court_id IS NOT NULL
                )
                AND NOT EXISTS (
                    SELECT 1 FROM falcon.reservations r
                     WHERE r.court_id = c.id
                       AND r.status IN ('pending','confirmed')
                       AND r.slot_date = CURRENT_DATE
                       AND CURRENT_TIME BETWEEN r.slot_time AND r.slot_end
                )
                AND NOT EXISTS (
                    -- Courts already occupied by the separate walk-in/credits
                    -- queue system (falcon.game_sessions, driven by the QR
                    -- kiosk under court/*.php). That system shares the same
                    -- open_play court_slot_modes designation as this one,
                    -- so without this check a court someone just scanned
                    -- into could get double-booked into a drawn match here.
                    SELECT 1 FROM falcon.game_sessions gs
                     WHERE gs.court_id = c.id AND gs.status = 'active'
                )
              ORDER BY c.id"
        );
        $stmt->execute([':tid' => $tournamentId]);
        $courtIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return array_values(array_filter($courtIds, fn($id) => $this->resolvedCourtMode($id) !== 'reservation'));
    }

    /** Mirrors api/court_mode.php's "what mode applies right now" resolution. */
    private function resolvedCourtMode(int $courtId): string
    {
        $dow  = (int)date('w');
        $date = date('Y-m-d');
        $now  = date('H:i:s');

        $stmt = $this->db->prepare(
            "SELECT time_from, time_to, mode, is_whole_day,
                    CASE WHEN slot_date IS NOT NULL THEN 1 ELSE 2 END AS priority
               FROM falcon.court_slot_modes
              WHERE court_id = :cid
                AND (slot_date = :date OR (slot_date IS NULL AND day_of_week = :dow))
              ORDER BY priority ASC, time_from ASC"
        );
        $stmt->execute([':cid' => $courtId, ':date' => $date, ':dow' => $dow]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            if ($m['is_whole_day'] || ($m['time_from'] <= $now && $m['time_to'] > $now)) {
                return $m['mode']; // first (highest-priority) match wins, same as api/court_mode.php
            }
        }
        return 'unconfigured'; // no rule for this slot — Open Play is free to use it
    }

    /** Called after a match finishes, in case a drawn-but-courtless game can now take the freed court. */
    public function assignFreeCourts(int $tournamentId, int $actorId): void
    {
        $free = $this->getFreeCourtIds($tournamentId);
        if (!$free) return;

        $stmt = $this->db->prepare(
            "SELECT id FROM falcon.open_play_matches
              WHERE tournament_id = :tid AND status = 'ready' AND court_id IS NULL
              ORDER BY id ASC"
        );
        $stmt->execute([':tid' => $tournamentId]);
        $waitingMatches = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        foreach ($waitingMatches as $matchId) {
            if (!$free) break;
            $courtId = array_shift($free);
            $this->db->prepare(
                "UPDATE falcon.open_play_matches SET court_id = :cid WHERE id = :id"
            )->execute([':cid' => $courtId, ':id' => $matchId]);
        }

        // Auto-draw more games to fill any courts still free after reassignment.
        if (count($free) > 0) {
            $this->drawRound($tournamentId, $actorId, count($free));
        }
    }

    // ══════════════════════════════════════════════════════════
    // MATCH LIFECYCLE
    // ══════════════════════════════════════════════════════════

    private function insertMatch(int $tid, ?int $courtId, array $game, int $duration, int $actorId): int
    {
        $t = $game['teamA']; $o = $game['teamB'];
        $stmt = $this->db->prepare(
            "SELECT COALESCE(MAX(round_number),0) + 1 FROM falcon.open_play_matches WHERE tournament_id = :tid"
        );
        $stmt->execute([':tid' => $tid]);
        $round = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            "INSERT INTO falcon.open_play_matches
                (tournament_id, round_number, court_id,
                 team1_player1_id, team1_player2_id, team2_player1_id, team2_player2_id,
                 status, duration_seconds, remaining_seconds, created_by)
             VALUES (:tid, :round, :court, :t1, :t2, :o1, :o2, 'ready', :dur, :dur, :actor)
             RETURNING id"
        );
        $stmt->execute([
            ':tid' => $tid, ':round' => $round, ':court' => $courtId,
            ':t1' => $t[0], ':t2' => $t[1] ?? null, ':o1' => $o[0], ':o2' => $o[1] ?? null,
            ':dur' => $duration, ':actor' => $actorId,
        ]);
        return (int)$stmt->fetchColumn();
    }

    /** Mark a player present for a drawn match before the no-show deadline. */
    public function confirmMatch(int $matchId, int $playerId): void
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, t.status AS event_status
               FROM falcon.open_play_matches m
               JOIN falcon.tournaments t ON t.id = m.tournament_id
              WHERE m.id = :mid AND m.status = 'ready'"
        );
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) throw new RuntimeException('This match is no longer awaiting check-in.');

        $players = array_map('intval', array_filter([
            $match['team1_player1_id'], $match['team1_player2_id'],
            $match['team2_player1_id'], $match['team2_player2_id'],
        ]));
        if (!in_array($playerId, $players, true)) {
            throw new RuntimeException('You are not assigned to this match.');
        }

        $this->db->prepare(
            "INSERT INTO falcon.open_play_match_checkins (match_id, player_id)
             VALUES (:mid, :pid) ON CONFLICT (match_id, player_id)
             DO UPDATE SET confirmed_at = NOW()"
        )->execute([':mid' => $matchId, ':pid' => $playerId]);
    }

    /** Cancel expired ready matches and return present players to the queue. */
    public function sweepNoShows(int $tournamentId, int $actorId): int
    {
        $event = $this->getEvent($tournamentId);
        if (!$event) throw new RuntimeException('Open play event not found.');
        $settings = json_decode((string)($event['settings'] ?? '{}'), true) ?: [];
        $timeout = max(1, min(60, (int)($settings['no_show_minutes'] ?? 10)));
        $stmt = $this->db->prepare(
            "SELECT id, team1_player1_id, team1_player2_id, team2_player1_id, team2_player2_id
               FROM falcon.open_play_matches
              WHERE tournament_id = :tid AND status = 'ready'
                AND created_at <= NOW() - (:minutes * INTERVAL '1 minute')"
        );
        $stmt->execute([':tid' => $tournamentId, ':minutes' => $timeout]);
        $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expired as $match) {
            $this->db->beginTransaction();
            try {
                $this->db->prepare("UPDATE falcon.open_play_matches SET status = 'cancelled', no_show_at = NOW() WHERE id = :id AND status = 'ready'")
                    ->execute([':id' => $match['id']]);
                $check = $this->db->prepare("SELECT player_id FROM falcon.open_play_match_checkins WHERE match_id = :mid");
                $check->execute([':mid' => $match['id']]);
                $present = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));
                $all = array_map('intval', array_filter([$match['team1_player1_id'], $match['team1_player2_id'], $match['team2_player1_id'], $match['team2_player2_id']]));
                foreach ($all as $playerId) {
                    $status = in_array($playerId, $present, true) ? 'waiting' : 'resting';
                    $this->db->prepare("UPDATE falcon.tournament_players SET queue_status = CAST(:queue_status AS varchar), queued_at = CASE WHEN CAST(:waiting_status AS varchar) = 'waiting' THEN NOW() ELSE queued_at END WHERE tournament_id = :tid AND player_id = :pid")
                        ->execute([
                            ':queue_status' => $status,
                            ':waiting_status' => $status,
                            ':tid' => $tournamentId,
                            ':pid' => $playerId,
                        ]);
                    notifyUser($this->db, $playerId, 'Open Play check-in closed', in_array($playerId, $present, true) ? 'The match was cancelled because another player did not check in. You are back in the queue.' : 'The match was cancelled because you did not check in before the deadline. Ask staff to return you to the queue.', null, APP_URL . '/public/open_play.php');
                }
                $this->logAudit($tournamentId, $actorId, 'no_show_match', ['match_id' => (int)$match['id'], 'present' => $present]);
                $this->db->commit();
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }
        }
        return count($expired);
    }

    public function getMatch(int $matchId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, c.name AS court_name,
                    COALESCE(u1.display_name, u1.full_name, u1.username) AS t1p1_name,
                    COALESCE(u2.display_name, u2.full_name, u2.username) AS t1p2_name,
                    COALESCE(u3.display_name, u3.full_name, u3.username) AS t2p1_name,
                    COALESCE(u4.display_name, u4.full_name, u4.username) AS t2p2_name
               FROM falcon.open_play_matches m
               LEFT JOIN falcon.courts c ON c.id = m.court_id
               LEFT JOIN falcon.users u1 ON u1.id = m.team1_player1_id
               LEFT JOIN falcon.users u2 ON u2.id = m.team1_player2_id
               LEFT JOIN falcon.users u3 ON u3.id = m.team2_player1_id
               LEFT JOIN falcon.users u4 ON u4.id = m.team2_player2_id
              WHERE m.id = :id"
        );
        $stmt->execute([':id' => $matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function startMatch(int $matchId, int $actorId): array
    {
        $m = $this->requireMatchStatus($matchId, 'ready');
        $this->db->prepare(
            "UPDATE falcon.open_play_matches
                SET status = 'in_progress', started_at = NOW() WHERE id = :id"
        )->execute([':id' => $matchId]);
        $this->setPlayersQueueStatus($m, 'playing');
        $this->logAudit((int)$m['tournament_id'], $actorId, 'start_match', ['match_id' => $matchId]);
        return $this->getMatch($matchId);
    }

    public function pauseMatch(int $matchId, int $actorId): array
    {
        $m = $this->requireMatchStatus($matchId, 'in_progress');
        $elapsed   = time() - strtotime($m['started_at']);
        $remaining = max(0, (int)$m['remaining_seconds'] - $elapsed);
        $this->db->prepare(
            "UPDATE falcon.open_play_matches
                SET status = 'paused', paused_at = NOW(), remaining_seconds = :rem WHERE id = :id"
        )->execute([':rem' => $remaining, ':id' => $matchId]);
        $this->logAudit((int)$m['tournament_id'], $actorId, 'pause_match', ['match_id' => $matchId]);
        return $this->getMatch($matchId);
    }

    public function resumeMatch(int $matchId, int $actorId): array
    {
        $m = $this->requireMatchStatus($matchId, 'paused');
        $this->db->prepare(
            "UPDATE falcon.open_play_matches
                SET status = 'in_progress', started_at = NOW() WHERE id = :id"
        )->execute([':id' => $matchId]);
        $this->logAudit((int)$m['tournament_id'], $actorId, 'resume_match', ['match_id' => $matchId]);
        return $this->getMatch($matchId);
    }

    /** Staff can nudge the timer up/down (e.g. extend a close game). */
    public function adjustTimer(int $matchId, int $deltaSeconds, int $actorId): array
    {
        $m = $this->getMatch($matchId);
        if (!$m) throw new RuntimeException('Match not found.');
        if (!in_array($m['status'], ['ready', 'in_progress', 'paused'], true)) {
            throw new RuntimeException('Only an open match timer can be adjusted.');
        }
        // If running, first fold in whatever time has already elapsed so the
        // adjustment is relative to the clock the staff member is looking at.
        if ($m['status'] === 'in_progress') {
            $elapsed = time() - strtotime($m['started_at']);
            $remaining = max(0, (int)$m['remaining_seconds'] - $elapsed) + $deltaSeconds;
            $this->db->prepare(
                "UPDATE falcon.open_play_matches
                    SET remaining_seconds = GREATEST(0, :rem), started_at = NOW() WHERE id = :id"
            )->execute([':rem' => $remaining, ':id' => $matchId]);
        } else {
            $this->db->prepare(
                "UPDATE falcon.open_play_matches
                    SET remaining_seconds = GREATEST(0, remaining_seconds + :d) WHERE id = :id"
            )->execute([':d' => $deltaSeconds, ':id' => $matchId]);
        }
        $this->logAudit((int)$m['tournament_id'], $actorId, 'adjust_timer', ['match_id' => $matchId, 'delta' => $deltaSeconds]);
        return $this->getMatch($matchId);
    }

    public function finishMatch(int $matchId, int $scoreA, int $scoreB, int $actorId, bool $sendToRest = false, bool $confirmed = false): array
    {
        $m = $this->getMatch($matchId);
        if (!$m) throw new RuntimeException('Match not found.');
        if ($m['status'] !== 'in_progress') {
            throw new RuntimeException("Only an in-progress match can be finished (currently '{$m['status']}').");
        }
        if ($scoreA === $scoreB) {
            throw new RuntimeException('A pickleball game cannot end in a tie — enter a final score.');
        }
        if (!$confirmed && ($warning = $this->scoreWarning($scoreA, $scoreB)) !== null) {
            throw new OpenPlayNeedsConfirmationException($warning);
        }
        $winner = $scoreA > $scoreB ? 1 : 2;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT status FROM falcon.open_play_matches WHERE id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => $matchId]);
            if ($stmt->fetchColumn() !== 'in_progress') {
                throw new RuntimeException('Match was already finished or cancelled.');
            }

            $this->db->prepare(
                "UPDATE falcon.open_play_matches
                    SET status = 'finished', score_team1 = :a, score_team2 = :b,
                        winner_team = :w, finished_at = NOW() WHERE id = :id"
            )->execute([':a' => $scoreA, ':b' => $scoreB, ':w' => $winner, ':id' => $matchId]);

            $teamA = array_filter([$m['team1_player1_id'], $m['team1_player2_id']]);
            $teamB = array_filter([$m['team2_player1_id'], $m['team2_player2_id']]);
            $capped = max(-5, min(5, $scoreA - $scoreB)); // cap blowout impact on point diff, like the source app

            foreach ($teamA as $pid) $this->applyResult((int)$m['tournament_id'], (int)$pid, $winner === 1, $scoreA, $scoreB, $capped);
            foreach ($teamB as $pid) $this->applyResult((int)$m['tournament_id'], (int)$pid, $winner === 2, $scoreB, $scoreA, -$capped);

            $newStatus = $sendToRest ? 'resting' : 'waiting';
            $allIds    = array_merge($teamA, $teamB);
            $this->db->prepare(
                "UPDATE falcon.tournament_players SET queue_status = :s, arrived_at = arrived_at
                  WHERE tournament_id = :tid AND player_id = ANY(:ids)"
            )->execute([':s' => $newStatus, ':tid' => $m['tournament_id'], ':ids' => '{' . implode(',', $allIds) . '}']);

            $this->logAudit((int)$m['tournament_id'], $actorId, 'finish_match',
                ['match_id' => $matchId, 'score' => "$scoreA-$scoreB"]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // The score is already committed at this point. Auto-filling freed
        // courts / drawing the next round is a best-effort follow-up — e.g.
        // if the event got paused mid-match, drawRound() will refuse to
        // draw, but that shouldn't make this call look like it failed when
        // the score was in fact saved successfully.
        try {
            $this->assignFreeCourts((int)$m['tournament_id'], $actorId);
        } catch (Throwable $e) {
            error_log('[OpenPlayEngine] assignFreeCourts after finishMatch failed: ' . $e->getMessage());
        }
        return $this->getMatch($matchId);
    }

    /**
     * Fix a fat-fingered score on a match that's already been marked
     * finished. Reverses the stat deltas the original result applied,
     * then re-applies them with the corrected score — so a player's
     * W/L record and point differential end up exactly as if the
     * correct score had been entered the first time. Only works while
     * the event hasn't been finalized yet (once tournament_scores /
     * the season leaderboard are written, use the existing
     * ScoringEngine::manuallySetScore() correction path instead).
     */
    /**
     * Non-blocking sanity check: flags scores that don't look like a
     * normal pickleball result (not to 11/15/21, or won by less than 2)
     * so the UI can ask "are you sure?" — legitimate for time-capped
     * games, so callers can pass $confirmed=true to save anyway.
     */
    private function scoreWarning(int $a, int $b): ?string
    {
        $winner = max($a, $b);
        $loser  = min($a, $b);
        if ($winner < 1 || $loser < 0) {
            return "$winner-$loser doesn't look like a real score.";
        }
        if (!in_array($winner, [11, 15, 21], true)) {
            return "$winner-$loser — pickleball games are usually played to 11, 15, or 21. Save this score anyway?";
        }
        if ($winner - $loser < 2) {
            return "$winner-$loser isn't a win by 2, which pickleball normally requires. Save this score anyway?";
        }
        return null;
    }

    public function correctScore(int $matchId, int $newScoreA, int $newScoreB, int $actorId, bool $confirmed = false): array
    {
        $m = $this->getMatch($matchId);
        if (!$m) throw new RuntimeException('Match not found.');
        if ($m['status'] !== 'finished') throw new RuntimeException('Only a finished match can have its score corrected.');
        if ($newScoreA === $newScoreB) throw new RuntimeException('A pickleball game cannot end in a tie.');
        if (!$confirmed && ($warning = $this->scoreWarning($newScoreA, $newScoreB)) !== null) {
            throw new OpenPlayNeedsConfirmationException($warning);
        }

        $event = $this->getEvent((int)$m['tournament_id']);
        if ($event && $event['status'] === 'completed') {
            throw new RuntimeException('This event has already been finalized — correct the score from Season Management / Scoring Admin instead.');
        }

        $teamA = array_filter([$m['team1_player1_id'], $m['team1_player2_id']]);
        $teamB = array_filter([$m['team2_player1_id'], $m['team2_player2_id']]);

        $oldWinner  = (int)$m['winner_team'];
        $oldCapped  = max(-5, min(5, (int)$m['score_team1'] - (int)$m['score_team2']));
        $newWinner  = $newScoreA > $newScoreB ? 1 : 2;
        $newCapped  = max(-5, min(5, $newScoreA - $newScoreB));

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT status FROM falcon.open_play_matches WHERE id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => $matchId]);
            if ($stmt->fetchColumn() !== 'finished') {
                throw new RuntimeException('Only a finished match can have its score corrected.');
            }

            // Undo the old result.
            foreach ($teamA as $pid) $this->applyResult((int)$m['tournament_id'], (int)$pid, $oldWinner === 1, -(int)$m['score_team1'], -(int)$m['score_team2'], -$oldCapped, true);
            foreach ($teamB as $pid) $this->applyResult((int)$m['tournament_id'], (int)$pid, $oldWinner === 2, -(int)$m['score_team2'], -(int)$m['score_team1'], $oldCapped, true);

            // Apply the corrected result.
            foreach ($teamA as $pid) $this->applyResult((int)$m['tournament_id'], (int)$pid, $newWinner === 1, $newScoreA, $newScoreB, $newCapped);
            foreach ($teamB as $pid) $this->applyResult((int)$m['tournament_id'], (int)$pid, $newWinner === 2, $newScoreB, $newScoreA, -$newCapped);

            $this->db->prepare(
                "UPDATE falcon.open_play_matches
                    SET score_team1 = :a, score_team2 = :b, winner_team = :w WHERE id = :id"
            )->execute([':a' => $newScoreA, ':b' => $newScoreB, ':w' => $newWinner, ':id' => $matchId]);

            $this->logAudit((int)$m['tournament_id'], $actorId, 'correct_score', [
                'match_id' => $matchId,
                'old' => "{$m['score_team1']}-{$m['score_team2']}", 'new' => "$newScoreA-$newScoreB",
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->getMatch($matchId);
    }

    private function applyResult(int $tid, int $playerId, bool $won, int $for, int $against, int $diff, bool $undo = false): void
    {
        $gameDelta = $undo ? -1 : 1;
        $winDelta  = ($won ? 1 : 0) * $gameDelta;
        $lossDelta = ($won ? 0 : 1) * $gameDelta;
        $this->db->prepare(
            "UPDATE falcon.tournament_players
                SET games_played = games_played + :g,
                    wins   = wins   + :w,
                    losses = losses + :l,
                    points_for     = points_for + :for,
                    points_against = points_against + :against
              WHERE tournament_id = :tid AND player_id = :pid"
        )->execute([
            ':g' => $gameDelta, ':w' => $winDelta, ':l' => $lossDelta,
            ':for' => $for, ':against' => $against, ':tid' => $tid, ':pid' => $playerId,
        ]);
    }

    public function cancelMatch(int $matchId, int $actorId): void
    {
        $m = $this->getMatch($matchId);
        if (!$m) throw new RuntimeException('Match not found.');
        if (!in_array($m['status'], ['ready', 'in_progress', 'paused'], true)) {
            throw new RuntimeException('Only an open match can be cancelled.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT status FROM falcon.open_play_matches WHERE id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => $matchId]);
            if (!in_array($stmt->fetchColumn(), ['ready', 'in_progress', 'paused'], true)) {
                throw new RuntimeException('Match was already finished or cancelled.');
            }

            $this->db->prepare(
                "UPDATE falcon.open_play_matches SET status = 'cancelled' WHERE id = :id"
            )->execute([':id' => $matchId]);
            $this->setPlayersQueueStatus($m, 'waiting');
            $this->logAudit((int)$m['tournament_id'], $actorId, 'cancel_match', ['match_id' => $matchId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Same best-effort follow-up rationale as finishMatch() above — the
        // cancellation itself already committed successfully.
        try {
            $this->assignFreeCourts((int)$m['tournament_id'], $actorId);
        } catch (Throwable $e) {
            error_log('[OpenPlayEngine] assignFreeCourts after cancelMatch failed: ' . $e->getMessage());
        }
    }

    private function setPlayersQueueStatus(array $match, string $status): void
    {
        $ids = array_filter([
            $match['team1_player1_id'], $match['team1_player2_id'],
            $match['team2_player1_id'], $match['team2_player2_id'],
        ]);
        $this->db->prepare(
            "UPDATE falcon.tournament_players SET queue_status = :s
              WHERE tournament_id = :tid AND player_id = ANY(:ids)"
        )->execute([':s' => $status, ':tid' => $match['tournament_id'], ':ids' => '{' . implode(',', $ids) . '}']);
    }

    private function requireMatchStatus(int $matchId, string $expected): array
    {
        $m = $this->getMatch($matchId);
        if (!$m) throw new RuntimeException('Match not found.');
        if ($m['status'] !== $expected) {
            throw new RuntimeException("Match must be '{$expected}' for this action (currently '{$m['status']}').");
        }
        return $m;
    }

    // ══════════════════════════════════════════════════════════
    // TIEBREAKERS
    // ══════════════════════════════════════════════════════════

    /** Groups of players tied on record within the top 3 placements. */
    public function detectPodiumTies(array $standings): array
    {
        $groups = []; $i = 0; $rank = 1; $n = count($standings);
        while ($i < $n) {
            $j = $i + 1;
            while ($j < $n
                && $standings[$j]['wins']       === $standings[$i]['wins']
                && $standings[$j]['win_pct']    === $standings[$i]['win_pct']
                && $standings[$j]['losses']     === $standings[$i]['losses']
                && $standings[$j]['point_diff'] === $standings[$i]['point_diff']
            ) { $j++; }
            $rows = array_slice($standings, $i, $j - $i);
            if (count($rows) > 1 && $rank <= 3) $groups[] = ['rank' => $rank, 'rows' => $rows];
            $rank += count($rows);
            $i = $j;
        }
        return $groups;
    }

    /** A one-off exhibition game between exactly the tied players, to break a podium tie. */
    public function createTiebreakGame(int $tournamentId, array $playerIds, int $actorId): array
    {
        $ids = array_values(array_unique(array_map('intval', $playerIds)));
        if (count($ids) < 2 || count($ids) > 4 || count($ids) % 2 !== 0) {
            throw new RuntimeException('A tiebreaker needs 2 players for singles or 4 players for doubles.');
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM falcon.tournament_players
              WHERE tournament_id = :tid AND player_id = ANY(:ids)
                AND status != 'withdrawn'"
        );
        $stmt->execute([':tid' => $tournamentId, ':ids' => '{' . implode(',', $ids) . '}']);
        if ((int)$stmt->fetchColumn() !== count($ids)) {
            throw new RuntimeException('Every tiebreak player must belong to this event.');
        }

        $this->db->beginTransaction();
        try {
            // Same advisory lock key as drawRound() — both read/assign free
            // courts for this event, so they must not interleave.
            $this->db->prepare('SELECT pg_advisory_xact_lock(:key)')->execute([':key' => $tournamentId]);
            $match = $this->createTiebreakGameLocked($tournamentId, $ids, $actorId);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $match;
    }

    private function createTiebreakGameLocked(int $tournamentId, array $ids, int $actorId): array
    {
        $event = $this->getEvent($tournamentId);
        if (!$event) throw new RuntimeException('Open play event not found.');
        if (in_array($event['status'], ['completed', 'cancelled'], true)) {
            throw new RuntimeException('This event is closed — no more games can be drawn.');
        }

        shuffle($ids);
        $half  = (int)ceil(count($ids) / 2);
        $teamA = array_slice($ids, 0, $half);
        $teamB = array_slice($ids, $half);

        $free    = $this->getFreeCourtIds($tournamentId);
        $courtId = $free[0] ?? null;

        $stmt = $this->db->prepare(
            "INSERT INTO falcon.open_play_matches
                (tournament_id, court_id, round_number, is_tiebreaker,
                 team1_player1_id, team1_player2_id, team2_player1_id, team2_player2_id,
                 status, duration_seconds, remaining_seconds, created_by)
             VALUES (:tid, :court, 0, TRUE, :t1, :t2, :o1, :o2, 'ready', 900, 900, :actor)
             RETURNING id"
        );
        $stmt->execute([
            ':tid' => $tournamentId, ':court' => $courtId,
            ':t1' => $teamA[0], ':t2' => $teamA[1] ?? null, ':o1' => $teamB[0], ':o2' => $teamB[1] ?? null,
            ':actor' => $actorId,
        ]);
        $matchId = (int)$stmt->fetchColumn();

        $this->db->prepare(
            "UPDATE falcon.tournament_players SET queue_status = 'queued'
              WHERE tournament_id = :tid AND player_id = ANY(:ids)"
        )->execute([':tid' => $tournamentId, ':ids' => '{' . implode(',', $ids) . '}']);

        $this->logAudit($tournamentId, $actorId, 'tiebreak_created', ['match_id' => $matchId, 'players' => $ids]);
        $match = $this->getMatch($matchId);
        $this->notifyMatchPlayers($ids, $match);
        return $match;
    }

    // ══════════════════════════════════════════════════════════
    // RAFFLES
    // ══════════════════════════════════════════════════════════

    /** Players currently seated in an active (ready/in_progress/paused) match — the raffle's eligible pool. */
    public function getRaffleParticipants(int $tournamentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT u.id, COALESCE(u.display_name, u.full_name, u.username) AS name
               FROM falcon.open_play_matches m
               JOIN falcon.users u ON u.id IN (m.team1_player1_id, m.team1_player2_id, m.team2_player1_id, m.team2_player2_id)
              WHERE m.tournament_id = :tid AND m.status IN ('ready','in_progress','paused')
              ORDER BY name"
        );
        $stmt->execute([':tid' => $tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Most recent raffle draw for this event, or null if none has been run yet. */
    public function getLatestRaffleDraw(int $tournamentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM falcon.open_play_raffle_draws
              WHERE tournament_id = :tid ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([':tid' => $tournamentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Spin the wheel among players currently on court and record a winner.
     * Deliberately doesn't touch queue_status or the match itself — a raffle
     * is a side prize draw, not a game result.
     */
    public function drawRaffle(int $tournamentId, string $prizeDescription, int $actorId): array
    {
        $event = $this->getEvent($tournamentId);
        if (!$event) throw new RuntimeException('Open play event not found.');
        if (in_array($event['status'], ['completed', 'cancelled'], true)) {
            throw new RuntimeException('This event is closed — no more raffles can be drawn.');
        }

        $prize = trim($prizeDescription);
        if ($prize === '' || mb_strlen($prize) > 160) {
            throw new RuntimeException('A prize description between 1 and 160 characters is required.');
        }

        $participants = $this->getRaffleParticipants($tournamentId);
        if (empty($participants)) {
            throw new RuntimeException('There are no players in an active game to enter in the raffle.');
        }

        $winner = $participants[random_int(0, count($participants) - 1)];

        $stmt = $this->db->prepare(
            "INSERT INTO falcon.open_play_raffle_draws
                (tournament_id, prize_description, winner_player_id, winner_name,
                 participant_ids, participant_names, drawn_by)
             VALUES (:tid, :prize, :wid, :wname, :pids::jsonb, :pnames::jsonb, :actor)
             RETURNING *"
        );
        $stmt->execute([
            ':tid'    => $tournamentId,
            ':prize'  => $prize,
            ':wid'    => $winner['id'],
            ':wname'  => $winner['name'],
            ':pids'   => json_encode(array_column($participants, 'id')),
            ':pnames' => json_encode(array_column($participants, 'name')),
            ':actor'  => $actorId,
        ]);
        $draw = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->logAudit($tournamentId, $actorId, 'raffle_drawn', [
            'prize' => $prize, 'winner_id' => $winner['id'], 'winner_name' => $winner['name'],
        ]);

        return ['draw' => $draw, 'participants' => $participants];
    }

    // ══════════════════════════════════════════════════════════
    // LEADERBOARD / STANDINGS
    // ══════════════════════════════════════════════════════════

    /**
     * Ranking order for continuous Open Play:
     * 1) qualified players before provisional players
     * 2) wins
     * 3) win percentage
     * 4) fewest losses
     * 5) point differential
     * 6) points scored
     * 7) games played as a final stability tie-breaker
     *
     * Arrival time affects queue fairness for matchmaking only. It does not
     * determine leaderboard ranking strength.
     */
    public function computeLeaderboard(int $tournamentId): array
    {
        $minQualifiedGames = 3;

        $stmt = $this->db->prepare(
            "SELECT tp.player_id,
                    COALESCE(u.display_name, u.full_name, u.username) AS display_name,
                    u.full_name, tp.skill_level,
                    tp.wins, tp.losses, tp.games_played, tp.points_for, tp.points_against,
                    tp.queue_status,
                    EXTRACT(EPOCH FROM COALESCE(tp.arrival_at, tp.arrived_at, NOW())) AS arrival_epoch
               FROM falcon.tournament_players tp
               JOIN falcon.users u ON u.id = tp.player_id
              WHERE tp.tournament_id = :tid AND tp.status != 'withdrawn'"
        );
        $stmt->execute([':tid' => $tournamentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matchStmt = $this->db->prepare(
            "SELECT team1_player1_id, team1_player2_id,
                    team2_player1_id, team2_player2_id, winner_team
               FROM falcon.open_play_matches
              WHERE tournament_id = :tid AND status = 'finished'
              ORDER BY finished_at DESC, id DESC"
        );
        $matchStmt->execute([':tid' => $tournamentId]);
        $resultsByPlayer = [];
        foreach ($matchStmt->fetchAll(PDO::FETCH_ASSOC) as $match) {
            $teamA = array_filter([$match['team1_player1_id'], $match['team1_player2_id']]);
            $teamB = array_filter([$match['team2_player1_id'], $match['team2_player2_id']]);
            foreach ($teamA as $playerId) {
                $resultsByPlayer[(string)$playerId][] = (int)$match['winner_team'] === 1;
            }
            foreach ($teamB as $playerId) {
                $resultsByPlayer[(string)$playerId][] = (int)$match['winner_team'] === 2;
            }
        }

        // Sum of finished-match point diffs (each already capped ±5 when recorded).
        $diffStmt = $this->db->prepare(
            "SELECT team1_player1_id p, GREATEST(-5,LEAST(5, score_team1-score_team2)) d
                 FROM falcon.open_play_matches WHERE tournament_id=:tid AND status='finished'
             UNION ALL
             SELECT team1_player2_id, GREATEST(-5,LEAST(5, score_team1-score_team2))
                 FROM falcon.open_play_matches WHERE tournament_id=:tid AND status='finished' AND team1_player2_id IS NOT NULL
             UNION ALL
             SELECT team2_player1_id, GREATEST(-5,LEAST(5, score_team2-score_team1))
                 FROM falcon.open_play_matches WHERE tournament_id=:tid AND status='finished'
             UNION ALL
             SELECT team2_player2_id, GREATEST(-5,LEAST(5, score_team2-score_team1))
                 FROM falcon.open_play_matches WHERE tournament_id=:tid AND status='finished' AND team2_player2_id IS NOT NULL"
        );
        $diffStmt->execute([':tid' => $tournamentId]);
        $diffs = [];
        foreach ($diffStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $diffs[$r['p']] = ($diffs[$r['p']] ?? 0) + (int)$r['d'];
        }

        foreach ($rows as &$r) {
            $r['games_played'] = (int)$r['games_played'];
            $r['win_pct']      = $r['games_played'] > 0 ? round($r['wins'] / $r['games_played'] * 1000) / 10 : 0.0;
            $r['point_diff']   = $diffs[$r['player_id']] ?? 0;
            $r['points_scored']= (int)$r['points_for'];
            $r['points_conceded'] = (int)$r['points_against'];
            $r['arrival_epoch'] = (float)$r['arrival_epoch'];
            $availableHours = max(1 / 60, (time() - $r['arrival_epoch']) / 3600);
            $r['time_since_arrival'] = max(0, time() - (int)$r['arrival_epoch']);
            $r['games_per_hour'] = round($r['games_played'] / $availableHours, 2);
            $r['status'] = $r['queue_status'] ?: 'waiting';
            $results = $resultsByPlayer[(string)$r['player_id']] ?? [];
            $currentStreak = 0;
            $streakType = null;
            foreach ($results as $won) {
                $type = $won ? 'win' : 'loss';
                if ($streakType === null) $streakType = $type;
                if ($type !== $streakType) break;
                $currentStreak++;
            }
            $bestStreak = 0;
            $run = 0;
            foreach ($results as $won) {
                $run = $won ? $run + 1 : 0;
                $bestStreak = max($bestStreak, $run);
            }
            $r['current_streak'] = $currentStreak;
            $r['streak_type'] = $streakType;
            $r['best_streak'] = $bestStreak;
            $r['qualified']    = $r['games_played'] >= $minQualifiedGames;
            $r['games_needed'] = max(0, $minQualifiedGames - $r['games_played']);
            $r['rank_label']   = $r['qualified'] ? 'Qualified' : 'Provisional';
        }
        unset($r);

        usort($rows, function ($a, $b) {
            $aQualified = (int)$a['qualified'];
            $bQualified = (int)$b['qualified'];

            if ($aQualified !== $bQualified) {
                return $bQualified <=> $aQualified;
            }

            return [
                $b['wins'],
                $b['win_pct'],
                -$a['losses'],
                $b['point_diff'],
                $b['points_scored'],
                $b['games_played'],
            ] <=> [
                $a['wins'],
                $a['win_pct'],
                -$b['losses'],
                $a['point_diff'],
                $a['points_scored'],
                $a['games_played'],
            ];
        });

        $rank = 0;
        $previousKey = null;
        foreach ($rows as $index => &$row) {
            $key = implode('|', [
                (int)$row['qualified'], $row['wins'], $row['win_pct'],
                $row['losses'], $row['point_diff'], $row['points_scored'], $row['games_played'],
            ]);
            if ($key !== $previousKey) {
                $rank = $index + 1;
                $previousKey = $key;
            }
            $row['rank'] = $rank;
            $row['is_tied'] = $index > 0 && $rank === $rows[$index - 1]['rank'];
        }
        unset($row);

        return $rows;
    }

    /** Now-playing / up-next / waiting-pool snapshot for the public kiosk display. */
    public function getKioskData(int $tournamentId): array
    {
        $event = $this->getEvent($tournamentId);
        if (!$event) throw new RuntimeException('Open play event not found.');

        $stmt = $this->db->prepare(
            "SELECT m.*, c.name AS court_name,
                    COALESCE(u1.display_name, u1.full_name, u1.username) AS t1p1_name,
                    COALESCE(u2.display_name, u2.full_name, u2.username) AS t1p2_name,
                    COALESCE(u3.display_name, u3.full_name, u3.username) AS t2p1_name,
                    COALESCE(u4.display_name, u4.full_name, u4.username) AS t2p2_name
               FROM falcon.open_play_matches m
               LEFT JOIN falcon.courts c ON c.id = m.court_id
               LEFT JOIN falcon.users u1 ON u1.id = m.team1_player1_id
               LEFT JOIN falcon.users u2 ON u2.id = m.team1_player2_id
               LEFT JOIN falcon.users u3 ON u3.id = m.team2_player1_id
               LEFT JOIN falcon.users u4 ON u4.id = m.team2_player2_id
              WHERE m.tournament_id = :tid AND m.status IN ('ready','in_progress','paused')
              ORDER BY (m.court_id IS NULL), m.court_id, m.id"
        );
        $stmt->execute([':tid' => $tournamentId]);
        $live = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $nowPlaying = array_values(array_filter($live, fn($m) => $m['court_id'] !== null));
        $upNext     = array_values(array_filter($live, fn($m) => $m['court_id'] === null));

        foreach ($nowPlaying as &$m) {
            if ($m['status'] === 'in_progress') {
                $elapsed = time() - strtotime($m['started_at']);
                $m['time_left'] = max(0, (int)$m['remaining_seconds'] - $elapsed);
            } else {
                $m['time_left'] = (int)$m['remaining_seconds'];
            }
        }
        unset($m);

        $waiting = $this->getWaitingPool($tournamentId);

        return [
            'event'        => $event,
            'now_playing'  => $nowPlaying,
            'up_next'      => $upNext,
            'waiting_pool' => $waiting,
            'waiting_count'=> count($waiting),
            'server_time'  => time(),
        ];
    }

    // ══════════════════════════════════════════════════════════
    // AUDIT
    // ══════════════════════════════════════════════════════════

    private function logAudit(int $tournamentId, int $actorId, string $action, array $details = []): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO falcon.tournament_audit_log (tournament_id, actor_id, action, details)
                 VALUES (:tid, :actor, :action, :details::jsonb)"
            )->execute([
                ':tid' => $tournamentId, ':actor' => $actorId, ':action' => $action,
                ':details' => json_encode($details),
            ]);
        } catch (Throwable $e) {
            // best-effort
        }
    }
}
