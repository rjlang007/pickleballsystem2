<?php
// ============================================================
//  FILE: tournament/tournament_engine.php
//  Central orchestrator: CRUD, lifecycle, bracket wiring.
//  Depends on: bracket_generator.php, scoring_engine.php,
//              leaderboard/leaderboard_engine.php
// ============================================================
if (defined('TOURNAMENT_ENGINE_LOADED')) return;
define('TOURNAMENT_ENGINE_LOADED', true);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/tournament_config.php';
require_once __DIR__ . '/bracket_generator.php';
require_once __DIR__ . '/../includes/helpers.php'; // notifyUser()

class TournamentEngine
{
    private PDO   $db;
    private array $config;

    public function __construct()
    {
        $this->db = getDB();
        $config   = require __DIR__ . '/../config/tournament_config.php';
        $this->config = is_array($config) ? $config : ($GLOBALS['TOURNAMENT_CONFIG'] ?? []);
    }

    // ══════════════════════════════════════════════════════════
    // TOURNAMENT CRUD
    // ══════════════════════════════════════════════════════════

    /**
     * Create a new tournament (status = draft).
     */
    public function createTournament(array $data, int $adminId): array
    {
        $name        = trim($data['name'] ?? '');
        $type        = $data['bracket_type'] ?? $this->config['default_bracket'];
        $maxPlayers  = (int)($data['max_players'] ?? 16);
        $startDate   = $data['start_date'] ?? null;
        $endDate     = $data['end_date']   ?? null;
        $description = trim($data['description'] ?? '');
        $featured    = !empty($data['featured']);
        $price       = max(0, round((float)($data['price'] ?? 0), 2));

        if ($name === '') {
            throw new InvalidArgumentException('Tournament name is required.');
        }
        if (!array_key_exists($type, $this->config['bracket_types'])) {
            throw new InvalidArgumentException("Invalid bracket type: {$type}");
        }
        if (!in_array($maxPlayers, $this->config['supported_player_counts'], true)) {
            throw new InvalidArgumentException(
                'max_players must be one of: ' . implode(', ', $this->config['supported_player_counts'])
            );
        }

        $settings = [];
        if (!empty($data['point_distribution'])) {
            $settings['point_distribution'] = $data['point_distribution'];
        }
        if (!empty($data['participation_points'])) {
            $settings['participation_points'] = (int)$data['participation_points'];
        }
        if ($type === 'swiss' && !empty($data['swiss_rounds'])) {
            $settings['swiss_rounds'] = min(15, max(1, (int)$data['swiss_rounds']));
        }
        $settings['price'] = $price;

        $stmt = $this->db->prepare(
            "INSERT INTO falcon.tournaments
                 (name, description, bracket_type, max_players,
                  start_date, end_date, created_by, settings, featured)
             VALUES
                 (:name, :desc, :type, :max,
                  :start, :end, :admin, :settings::jsonb, :featured)
             RETURNING *"
        );
        $stmt->execute([
            ':name'     => $name,
            ':desc'     => $description ?: null,
            ':type'     => $type,
            ':max'      => $maxPlayers,
            ':start'    => $startDate   ?: null,
            ':end'      => $endDate     ?: null,
            ':admin'    => $adminId,
            ':settings' => json_encode($settings ?: (object)[]),
            ':featured' => $featured ? 'true' : 'false',
        ]);
        return $stmt->fetch();
    }

    public function updateTournament(int $id, array $data, int $adminId): array
    {
        $tournament = $this->getTournament($id);
        if (!$tournament) throw new RuntimeException('Tournament not found.');
        if (!in_array($tournament['status'], ['draft', 'registration_open'], true)) {
            throw new RuntimeException('Only draft or open-registration tournaments can be edited.');
        }

        $name       = trim((string)($data['name'] ?? ''));
        $type       = $data['bracket_type'] ?? $tournament['bracket_type'];
        $maxPlayers = (int)($data['max_players'] ?? $tournament['max_players']);
        $startDate  = $data['start_date'] ?? null;
        $endDate    = $data['end_date'] ?? null;
        $price      = max(0, round((float)($data['price'] ?? 0), 2));

        if ($name === '') throw new InvalidArgumentException('Tournament name is required.');
        if (!array_key_exists($type, $this->config['bracket_types'])) {
            throw new InvalidArgumentException('Invalid bracket type.');
        }
        if (!in_array($maxPlayers, $this->config['supported_player_counts'], true)) {
            throw new InvalidArgumentException('Invalid player capacity.');
        }
        if ((int)$tournament['current_players'] > $maxPlayers) {
            throw new InvalidArgumentException('Capacity cannot be lower than the current registered players.');
        }

        $settings = json_decode($tournament['settings'] ?? '{}', true) ?: [];
        $settings['price'] = $price;

        $stmt = $this->db->prepare(
            "UPDATE falcon.tournaments
                SET name = :name, description = :description, bracket_type = :type,
                    max_players = :max, start_date = :start, end_date = :end,
                    featured = :featured, settings = :settings::jsonb, updated_at = NOW()
              WHERE id = :id
          RETURNING *"
        );
        $stmt->execute([
            ':name' => $name,
            ':description' => trim((string)($data['description'] ?? '')) ?: null,
            ':type' => $type,
            ':max' => $maxPlayers,
            ':start' => $startDate ?: null,
            ':end' => $endDate ?: null,
            ':featured' => !empty($data['featured']) ? 'true' : 'false',
            ':settings' => json_encode($settings),
            ':id' => $id,
        ]);

        return $stmt->fetch() ?: $this->getTournament($id);
    }

    /**
     * List tournaments with optional filters.
     * Adds current_players count for each.
     */
    public function listTournaments(array $filters = []): array
    {
        $where  = ['1=1'];
        $params = [];

        // Open Play is a live queue, not a bracket tournament. Keep it out
        // of tournament listings unless a caller explicitly requests it.
        if (($filters['include_open_play'] ?? false) !== true) {
            $where[] = "t.bracket_type <> 'open_play'";
        }

        if (!empty($filters['status'])) {
            $where[]           = 't.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['bracket_type'])) {
            $where[]                 = 'bracket_type = :bracket_type';
            $params[':bracket_type'] = $filters['bracket_type'];
        }
        if (isset($filters['featured']) && $filters['featured'] !== null) {
            $where[]             = 'featured = :featured';
            $params[':featured'] = $filters['featured'] ? 'true' : 'false';
        }

        $sql = "
            SELECT t.*,
                   COUNT(tp.id) AS current_players,
                   u.username   AS created_by_name
              FROM falcon.tournaments t
         LEFT JOIN falcon.tournament_players tp
                ON tp.tournament_id = t.id
               AND tp.status NOT IN ('withdrawn')
         LEFT JOIN falcon.users u ON u.id = t.created_by
             WHERE " . implode(' AND ', $where) . "
          GROUP BY t.id, u.username
          ORDER BY t.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get a single tournament by ID.
     */
    public function getTournament(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*,
                    COUNT(tp.id) AS current_players,
                    u.username   AS created_by_name
               FROM falcon.tournaments t
          LEFT JOIN falcon.tournament_players tp
                 ON tp.tournament_id = t.id AND tp.status NOT IN ('withdrawn')
          LEFT JOIN falcon.users u ON u.id = t.created_by
              WHERE t.id = :id
           GROUP BY t.id, u.username"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ══════════════════════════════════════════════════════════
    // LIFECYCLE TRANSITIONS
    // ══════════════════════════════════════════════════════════

    /**
     * draft → registration_open
     */
    public function openRegistration(int $id): void
    {
        $this->requireStatus($id, 'draft', 'open_registration');
        $this->db->prepare(
            "UPDATE falcon.tournaments SET status = 'registration_open' WHERE id = :id"
        )->execute([':id' => $id]);
    }

    /**
     * registration_open → in_progress
     * Seeds players by leaderboard rank and generates bracket.
     */
    public function startTournament(int $id): array
    {
        $t = $this->requireStatus($id, 'registration_open', 'start');

        $players = $this->getRegisteredPlayerIds($id);
        $count   = count($players);

        if ($count < $this->config['min_players_for_tournament']) {
            throw new RuntimeException(
                "Need at least {$this->config['min_players_for_tournament']} players to start. Have {$count}."
            );
        }

        $seeded = $this->seedPlayersByRank($id, $players);

        $this->db->beginTransaction();
        try {
            foreach ($seeded as $seed => $playerId) {
                $this->db->prepare(
                    "UPDATE falcon.tournament_players
                        SET seed = :seed, status = 'active'
                      WHERE tournament_id = :tid AND player_id = :pid"
                )->execute([':seed' => $seed + 1, ':tid' => $id, ':pid' => $playerId]);
            }

            $settings = json_decode($t['settings'] ?? '{}', true);
            $options  = [];
            if ($t['bracket_type'] === 'swiss') {
                $options['swiss_rounds'] = $settings['swiss_rounds'] ?? $this->config['swiss_default_rounds'];
            }

            $matches = BracketGenerator::generate($id, $seeded, $t['bracket_type'], $options);
            $this->insertMatches($matches);

            $this->db->prepare(
                "UPDATE falcon.tournaments SET status = 'in_progress' WHERE id = :id"
            )->execute([':id' => $id]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->getBracket($id);
    }

    /**
     * in_progress → completed
     * Triggers scoring engine → leaderboard engine.
     */
    public function completeTournament(int $id, int $adminId): void
    {
        $this->requireStatus($id, 'in_progress', 'complete');

        require_once __DIR__ . '/scoring_engine.php';
        require_once __DIR__ . '/../leaderboard/leaderboard_engine.php';

        $this->db->beginTransaction();
        try {
            $scoring = new ScoringEngine();
            $scoring->processPlacements($id, $adminId);

            $lb = new LeaderboardEngine();
            $lb->processTournamentCompletion($id);

            $this->db->prepare(
                "UPDATE falcon.tournaments SET status = 'completed' WHERE id = :id"
            )->execute([':id' => $id]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Cancel any non-completed tournament.
     */
    public function cancelTournament(int $id): void
    {
        $t = $this->getTournament($id);
        if (!$t) throw new RuntimeException("Tournament {$id} not found.");
        if ($t['status'] === 'completed') {
            throw new RuntimeException('Cannot cancel a completed tournament.');
        }
        $this->db->prepare(
            "UPDATE falcon.tournaments SET status = 'cancelled' WHERE id = :id"
        )->execute([':id' => $id]);
    }

    // ══════════════════════════════════════════════════════════
    // BRACKET MANAGEMENT — regenerate (Phase 11A)
    // ══════════════════════════════════════════════════════════

    /**
     * Wipe all existing matches for an in-progress tournament and
     * rebuild the bracket from the current player seeds.
     *
     * WARNING: this destroys all recorded match scores.
     * The admin UI requires a confirmation dialog before calling this.
     *
     * @return array  The freshly-generated bracket (match rows with player names).
     */
    public function regenerateBracket(int $id): array
    {
        $t = $this->getTournament($id);
        if (!$t) throw new RuntimeException("Tournament {$id} not found.");
        if ($t['status'] !== 'in_progress') {
            throw new RuntimeException(
                'Can only regenerate bracket for in-progress tournaments.'
            );
        }

        $this->db->beginTransaction();
        try {
            // Delete all existing matches
            $this->db->prepare(
                "DELETE FROM falcon.tournament_matches WHERE tournament_id = :id"
            )->execute([':id' => $id]);

            // Re-fetch players with their current seeds
            $players = $this->getRegisteredPlayerIds($id);
            $seeded  = $this->seedPlayersByRank($id, $players);

            // Re-assign seeds in tournament_players
            foreach ($seeded as $seed => $playerId) {
                $this->db->prepare(
                    "UPDATE falcon.tournament_players
                        SET seed = :seed
                      WHERE tournament_id = :tid AND player_id = :pid"
                )->execute([':seed' => $seed + 1, ':tid' => $id, ':pid' => $playerId]);
            }

            $settings = json_decode($t['settings'] ?? '{}', true);
            $options  = [];
            if ($t['bracket_type'] === 'swiss') {
                $options['swiss_rounds'] = $settings['swiss_rounds']
                    ?? $this->config['swiss_default_rounds'];
            }

            $matches = BracketGenerator::generate($id, $seeded, $t['bracket_type'], $options);
            $this->insertMatches($matches);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->getBracket($id);
    }

    // ══════════════════════════════════════════════════════════
    // PLAYER REGISTRATION
    // ══════════════════════════════════════════════════════════

    /**
     * Register a player for a tournament.
     */
    public function registerPlayer(int $tournamentId, int $playerId): void
    {
        $t = $this->getTournament($tournamentId);
        if (!$t) throw new RuntimeException('Tournament not found.');
        if ($t['bracket_type'] === 'open_play') {
            throw new RuntimeException('Open Play uses the separate Open Play registration page.');
        }
        if ($t['status'] !== 'registration_open') {
            throw new RuntimeException('Registration is not open for this tournament.');
        }
        if ((int)$t['current_players'] >= (int)$t['max_players']) {
            throw new RuntimeException('Tournament is full.');
        }

        $existing = $this->db->prepare(
            "SELECT id, status FROM falcon.tournament_players
              WHERE tournament_id = :tid AND player_id = :pid
              LIMIT 1"
        );
        $existing->execute([':tid' => $tournamentId, ':pid' => $playerId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if ($row['status'] !== 'withdrawn') {
                throw new RuntimeException('You are already registered for this tournament.');
            }

            $this->db->prepare(
                "UPDATE falcon.tournament_players
                    SET status = 'registered', joined_at = NOW(), seed = NULL
                  WHERE id = :id"
            )->execute([':id' => $row['id']]);
            return;
        }

        $this->db->prepare(
            "INSERT INTO falcon.tournament_players
                 (tournament_id, player_id, status)
             VALUES (:tid, :pid, 'registered')"
        )->execute([':tid' => $tournamentId, ':pid' => $playerId]);

        try {
            notifyOperations(
                $this->db,
                '🏆 New Tournament Entry',
                "A player joined tournament '{$t['name']}'."
            );
        } catch (Throwable $e) {
            error_log('[Tournament] entry notification failed: ' . $e->getMessage());
        }
    }

    /**
     * Withdraw a player from a tournament.
     */
    public function withdrawPlayer(int $tournamentId, int $playerId): void
    {
        $this->db->prepare(
            "UPDATE falcon.tournament_players
                SET status = 'withdrawn'
              WHERE tournament_id = :tid AND player_id = :pid"
        )->execute([':tid' => $tournamentId, ':pid' => $playerId]);
    }

    /**
     * Add a player to a tournament (alias for registerPlayer).
     */
    public function addPlayer(int $tournamentId, int $playerId): void
    {
        $this->registerPlayer($tournamentId, $playerId);
    }

    /**
     * Remove a player from a tournament (alias for withdrawPlayer).
     */
    public function removePlayer(int $tournamentId, int $playerId): void
    {
        $this->withdrawPlayer($tournamentId, $playerId);
    }

    /**
     * Get all players registered in a tournament (with user details).
     */
    public function getTournamentPlayers(int $tournamentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT tp.*, tp.player_id,
                    u.username, u.full_name, u.display_name, u.avatar_url,
                    COALESCE(lb.total_points, 0) AS season_points,
                    COALESCE(lb.rank, 9999)       AS season_rank
               FROM falcon.tournament_players tp
               JOIN falcon.users u ON u.id = tp.player_id
          LEFT JOIN falcon.leaderboard lb
                 ON lb.player_id = tp.player_id
                AND lb.season    = :season
              WHERE tp.tournament_id = :tid
                AND tp.status NOT IN ('withdrawn')
              ORDER BY tp.seed ASC NULLS LAST, u.full_name ASC"
        );
        $stmt->execute([
            ':tid'    => $tournamentId,
            ':season' => (int) date('Y'),
        ]);
        return $stmt->fetchAll();
    }

    // ══════════════════════════════════════════════════════════
    // BRACKET & MATCH MANAGEMENT
    // ══════════════════════════════════════════════════════════

    /**
     * Get all matches for a tournament, enriched with player names.
     */
    public function getBracket(int $tournamentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*,
                    p1.username     AS player1_username,
                    p1.full_name    AS player1_name,
                    p1.display_name AS player1_display,
                    p2.username     AS player2_username,
                    p2.full_name    AS player2_name,
                    p2.display_name AS player2_display,
                    w.username      AS winner_username,
                    w.full_name     AS winner_name
               FROM falcon.tournament_matches m
          LEFT JOIN falcon.users p1 ON p1.id = m.player1_id
          LEFT JOIN falcon.users p2 ON p2.id = m.player2_id
          LEFT JOIN falcon.users w  ON w.id  = m.winner_id
              WHERE m.tournament_id = :tid
              ORDER BY m.bracket_round ASC, m.match_number ASC"
        );
        $stmt->execute([':tid' => $tournamentId]);
        return $stmt->fetchAll();
    }

    /**
     * Record the result of a completed match.
     * Automatically advances winner to the next match slot in
     * single-elimination brackets.
     */
    public function recordMatchResult(
        int $matchId,
        int $winnerId,
        int $scoreP1,
        int $scoreP2,
        int $adminId
    ): void {
        $stmt = $this->db->prepare(
            "SELECT * FROM falcon.tournament_matches WHERE id = :id"
        );
        $stmt->execute([':id' => $matchId]);
        $match = $stmt->fetch();
        if (!$match) throw new RuntimeException("Match {$matchId} not found.");
        if ($match['status'] === 'completed') {
            throw new RuntimeException('This match has already been recorded.');
        }

        $loserId = ($winnerId === (int)$match['player1_id'])
            ? (int)$match['player2_id']
            : (int)$match['player1_id'];

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "UPDATE falcon.tournament_matches
                    SET winner_id      = :winner,
                        score_player1  = :s1,
                        score_player2  = :s2,
                        status         = 'completed',
                        recorded_by    = :admin,
                        recorded_at    = NOW()
                  WHERE id = :id"
            )->execute([
                ':winner' => $winnerId,
                ':s1'     => $scoreP1,
                ':s2'     => $scoreP2,
                ':admin'  => $adminId,
                ':id'     => $matchId,
            ]);

            $tournament = $this->getTournament((int)$match['tournament_id']);
            if (in_array($tournament['bracket_type'], ['single_elimination', 'double_elimination'], true)) {
                $this->db->prepare(
                    "UPDATE falcon.tournament_players
                        SET status = 'eliminated'
                      WHERE tournament_id = :tid AND player_id = :pid"
                )->execute([':tid' => $match['tournament_id'], ':pid' => $loserId]);
            }

            if ($tournament['bracket_type'] === 'single_elimination') {
                $this->advanceWinnerSingleElim(
                    (int)$match['tournament_id'],
                    (int)$match['bracket_round'],
                    (int)$match['match_number'],
                    $winnerId
                );
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ══════════════════════════════════════════════════════════
    // TOURNAMENT-DAY CHECK-IN
    // ══════════════════════════════════════════════════════════

    /**
     * Mark a registered player as checked-in (or undo it) on tournament day.
     * Checked-in state is what randomizeAndStart() seeds from when any
     * check-ins exist, so a no-show never gets auto-placed in the bracket.
     */
    public function setPlayerCheckIn(int $tournamentId, int $playerId, bool $checkedIn, int $actorId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE falcon.tournament_players
                SET checked_in    = :checked::boolean,
                    checked_in_at = CASE WHEN :checked2::boolean THEN NOW() ELSE NULL END,
                    checked_in_by = CASE WHEN :checked3::boolean THEN :actor ELSE NULL END
              WHERE tournament_id = :tid AND player_id = :pid"
        );
        $stmt->execute([
            ':checked'  => $checkedIn ? 'true' : 'false',
            ':checked2' => $checkedIn ? 'true' : 'false',
            ':checked3' => $checkedIn ? 'true' : 'false',
            ':actor'    => $actorId,
            ':tid'      => $tournamentId,
            ':pid'      => $playerId,
        ]);
        $this->logAudit($tournamentId, null, $actorId, $checkedIn ? 'player_checked_in' : 'player_check_in_undone', [
            'player_id' => $playerId,
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // RANDOMIZED BRACKET GENERATION (staff walk-in-day tournaments)
    // ══════════════════════════════════════════════════════════

    /**
     * registration_open → in_progress, shuffling entrants randomly
     * instead of seeding by leaderboard rank. If any player has been
     * checked in, only checked-in players are placed in the bracket
     * (no-shows are silently excluded); otherwise falls back to every
     * registered player so tournaments that skip check-in still work.
     */
    public function randomizeAndStart(int $tournamentId, int $actorId): array
    {
        $t = $this->requireStatus($tournamentId, 'registration_open', 'randomize and start');

        $players = $this->getEntrantPlayerIds($tournamentId);
        $count   = count($players);

        if ($count < $this->config['min_players_for_tournament']) {
            throw new RuntimeException(
                "Need at least {$this->config['min_players_for_tournament']} checked-in players to start. Have {$count}."
            );
        }

        shuffle($players);

        $this->db->beginTransaction();
        try {
            foreach ($players as $seed => $playerId) {
                $this->db->prepare(
                    "UPDATE falcon.tournament_players
                        SET seed = :seed, status = 'active'
                      WHERE tournament_id = :tid AND player_id = :pid"
                )->execute([':seed' => $seed + 1, ':tid' => $tournamentId, ':pid' => $playerId]);
            }

            $settings = json_decode($t['settings'] ?? '{}', true);
            $options  = [];
            if ($t['bracket_type'] === 'swiss') {
                $options['swiss_rounds'] = $settings['swiss_rounds'] ?? $this->config['swiss_default_rounds'];
            }

            $matches = BracketGenerator::generate($tournamentId, $players, $t['bracket_type'], $options);
            $this->insertMatches($matches);

            $this->db->prepare(
                "UPDATE falcon.tournaments SET status = 'in_progress' WHERE id = :id"
            )->execute([':id' => $tournamentId]);

            $this->logAudit($tournamentId, null, $actorId, 'bracket_randomized', [
                'entrant_count' => $count,
            ]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Let every Round 1 entrant know who they're playing.
        $round1 = $this->db->prepare(
            "SELECT id FROM falcon.tournament_matches
              WHERE tournament_id = :tid AND bracket_round = 1
                AND player1_id IS NOT NULL AND player2_id IS NOT NULL"
        );
        $round1->execute([':tid' => $tournamentId]);
        foreach ($round1->fetchAll() as $r1) {
            $this->notifyMatchReady((int)$r1['id'], 'opponent_set');
        }

        return $this->getBracket($tournamentId);
    }

    /**
     * Registered players to seed from: checked-in only if check-in has
     * been used at all for this tournament, otherwise every registrant.
     */
    private function getEntrantPlayerIds(int $tournamentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM falcon.tournament_players
              WHERE tournament_id = :tid AND status NOT IN ('withdrawn') AND checked_in = TRUE"
        );
        $stmt->execute([':tid' => $tournamentId]);
        $checkedInCount = (int) $stmt->fetchColumn();

        $sql = "SELECT player_id FROM falcon.tournament_players
                  WHERE tournament_id = :tid AND status NOT IN ('withdrawn')";
        if ($checkedInCount > 0) {
            $sql .= " AND checked_in = TRUE";
        }
        $sql .= " ORDER BY joined_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tid' => $tournamentId]);
        return array_column($stmt->fetchAll(), 'player_id');
    }

    // ══════════════════════════════════════════════════════════
    // REFEREE / COURT ASSIGNMENT + MANUAL MATCHUP OVERRIDE
    // ══════════════════════════════════════════════════════════

    /** List active courts a match can be assigned to. */
    public function getActiveCourts(): array
    {
        return $this->db->query(
            "SELECT id, name FROM falcon.courts WHERE is_active = TRUE ORDER BY name ASC"
        )->fetchAll();
    }

    /** List users who can referee (referee, admin, super_admin). */
    public function getAvailableReferees(): array
    {
        $placeholders = implode(',', array_fill(0, count(REFEREE_ROLES), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, username, full_name, display_name
               FROM falcon.users
              WHERE role IN ({$placeholders}) AND is_active = TRUE
              ORDER BY full_name ASC NULLS LAST, username ASC"
        );
        $stmt->execute(REFEREE_ROLES);
        return $stmt->fetchAll();
    }

    /**
     * Assign (or clear) a referee and/or court for a match. Either
     * argument can be null to leave that field unassigned/unchanged-clear.
     */
    public function assignRefereeCourt(int $matchId, ?int $refereeId, ?int $courtId, int $actorId): void
    {
        $stmt = $this->db->prepare("SELECT tournament_id FROM falcon.tournament_matches WHERE id = :id");
        $stmt->execute([':id' => $matchId]);
        $match = $stmt->fetch();
        if (!$match) throw new RuntimeException("Match {$matchId} not found.");

        $this->db->prepare(
            "UPDATE falcon.tournament_matches
                SET referee_id = :ref, court_id = :court
              WHERE id = :id"
        )->execute([':ref' => $refereeId, ':court' => $courtId, ':id' => $matchId]);

        $this->logAudit((int)$match['tournament_id'], $matchId, $actorId, 'referee_court_assigned', [
            'referee_id' => $refereeId,
            'court_id'   => $courtId,
        ]);

        if ($courtId) {
            $this->notifyMatchReady($matchId, 'court_assigned');
        }
    }

    /**
     * Manually override who plays whom in a specific match — e.g. to
     * avoid two teammates facing off in round 1. Only allowed before a
     * result has been recorded. Swapped-out players are not removed
     * from the tournament, just relocated to this match slot.
     */
    public function overrideMatchup(int $matchId, ?int $player1Id, ?int $player2Id, int $actorId): void
    {
        $stmt = $this->db->prepare("SELECT * FROM falcon.tournament_matches WHERE id = :id");
        $stmt->execute([':id' => $matchId]);
        $match = $stmt->fetch();
        if (!$match) throw new RuntimeException("Match {$matchId} not found.");
        if ($match['status'] === 'completed') {
            throw new RuntimeException('Cannot edit a matchup that has already been recorded.');
        }

        $tournamentId = (int) $match['tournament_id'];
        foreach ([$player1Id, $player2Id] as $pid) {
            if ($pid === null) continue;
            $chk = $this->db->prepare(
                "SELECT 1 FROM falcon.tournament_players
                  WHERE tournament_id = :tid AND player_id = :pid AND status NOT IN ('withdrawn')"
            );
            $chk->execute([':tid' => $tournamentId, ':pid' => $pid]);
            if (!$chk->fetchColumn()) {
                throw new RuntimeException("Player {$pid} is not registered in this tournament.");
            }
        }
        if ($player1Id !== null && $player1Id === $player2Id) {
            throw new RuntimeException('A player cannot be matched against themselves.');
        }

        $status = ($player1Id !== null && $player2Id !== null) ? 'pending' : $match['status'];

        $this->db->prepare(
            "UPDATE falcon.tournament_matches
                SET player1_id = :p1, player2_id = :p2, status = :status
              WHERE id = :id"
        )->execute([':p1' => $player1Id, ':p2' => $player2Id, ':status' => $status, ':id' => $matchId]);

        $this->logAudit($tournamentId, $matchId, $actorId, 'matchup_overridden', [
            'from' => ['player1_id' => $match['player1_id'], 'player2_id' => $match['player2_id']],
            'to'   => ['player1_id' => $player1Id, 'player2_id' => $player2Id],
        ]);
    }

    /** Write one row to the audit trail. Never throws — logging must not break the calling action. */
    private function logAudit(int $tournamentId, ?int $matchId, int $actorId, string $action, array $details = []): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO falcon.tournament_audit_log (tournament_id, match_id, actor_id, action, details)
                 VALUES (:tid, :mid, :actor, :action, :details::jsonb)"
            )->execute([
                ':tid'     => $tournamentId,
                ':mid'     => $matchId,
                ':actor'   => $actorId,
                ':action'  => $action,
                ':details' => json_encode($details),
            ]);
        } catch (Throwable $e) {
            // audit logging is best-effort
        }
    }

    // ══════════════════════════════════════════════════════════
    // LIVE REFEREE SCORING
    // ══════════════════════════════════════════════════════════

    /**
     * Fetch a match for the scoring form, enriched with player and
     * tournament info. Returns null if not found.
     */
    public function getMatchForScoring(int $matchId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*,
                    t.name AS tournament_name, t.bracket_type,
                    p1.full_name AS p1_name, p1.display_name AS p1_display,
                    p2.full_name AS p2_name, p2.display_name AS p2_display,
                    c.name AS court_name
               FROM falcon.tournament_matches m
               JOIN falcon.tournaments t ON t.id = m.tournament_id
          LEFT JOIN falcon.users  p1 ON p1.id = m.player1_id
          LEFT JOIN falcon.users  p2 ON p2.id = m.player2_id
          LEFT JOIN falcon.courts c  ON c.id = m.court_id
              WHERE m.id = :id"
        );
        $stmt->execute([':id' => $matchId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Point-by-point / correction history for a match, oldest first. */
    public function getMatchEvents(int $matchId): array
    {
        $stmt = $this->db->prepare(
            "SELECT e.*, u.username AS actor_username
               FROM falcon.tournament_match_events e
          LEFT JOIN falcon.users u ON u.id = e.actor_id
              WHERE e.match_id = :id
              ORDER BY e.created_at ASC, e.id ASC"
        );
        $stmt->execute([':id' => $matchId]);
        return $stmt->fetchAll();
    }

    /**
     * Adjust one player's live score by +1/-1 (never below 0). Writes
     * immediately to tournament_matches AND to the event log, and
     * flips status pending → in_progress on the first point, so
     * nothing depends on a separate "start match" step and nothing
     * is lost if the referee's device disconnects mid-match.
     */
    public function recordPoint(int $matchId, int $playerSlot, int $delta, int $actorId): array
    {
        if (!in_array($playerSlot, [1, 2], true)) {
            throw new InvalidArgumentException('playerSlot must be 1 or 2.');
        }
        $match = $this->getMatchForScoring($matchId);
        if (!$match) throw new RuntimeException("Match {$matchId} not found.");
        if ($match['status'] === 'completed') {
            throw new RuntimeException('This match is already complete. Use a correction to change the score.');
        }

        $col   = $playerSlot === 1 ? 'score_player1' : 'score_player2';
        $other = $playerSlot === 1 ? 'score_player2' : 'score_player1';
        $newVal = max(0, (int)$match[$col] + $delta);

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "UPDATE falcon.tournament_matches
                    SET {$col} = :val,
                        status = CASE WHEN status = 'pending' THEN 'in_progress' ELSE status END
                  WHERE id = :id"
            )->execute([':val' => $newVal, ':id' => $matchId]);

            $s1 = $playerSlot === 1 ? $newVal : (int)$match['score_player1'];
            $s2 = $playerSlot === 2 ? $newVal : (int)$match['score_player2'];

            $this->logMatchEvent($matchId, (int)$match['tournament_id'], 'point', $playerSlot, $s1, $s2, $actorId);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['score_player1' => $s1, 'score_player2' => $s2, 'status' => 'in_progress'];
    }

    /** Set who's serving and from which side. */
    public function setServer(int $matchId, int $servingPlayer, string $side, int $actorId): void
    {
        if (!in_array($servingPlayer, [1, 2], true)) {
            throw new InvalidArgumentException('servingPlayer must be 1 or 2.');
        }
        if (!in_array($side, ['left', 'right'], true)) {
            throw new InvalidArgumentException('side must be left or right.');
        }
        $match = $this->getMatchForScoring($matchId);
        if (!$match) throw new RuntimeException("Match {$matchId} not found.");

        $this->db->prepare(
            "UPDATE falcon.tournament_matches
                SET serving_player = :sp, serving_side = :side
              WHERE id = :id"
        )->execute([':sp' => $servingPlayer, ':side' => $side, ':id' => $matchId]);

        $this->logMatchEvent(
            $matchId, (int)$match['tournament_id'], 'side_out', $servingPlayer,
            (int)$match['score_player1'], (int)$match['score_player2'], $actorId,
            "serving from {$side}"
        );
    }

    /**
     * Manually correct the score after the fact (typo, missed point,
     * disputed call). Unlike recordPoint(), this can be used after
     * completion too — but it's always logged as a correction, never
     * a silent overwrite, so there's a clear record for dispute resolution.
     */
    public function correctScore(int $matchId, int $scoreP1, int $scoreP2, int $actorId, string $note = ''): void
    {
        $match = $this->getMatchForScoring($matchId);
        if (!$match) throw new RuntimeException("Match {$matchId} not found.");
        if ($scoreP1 < 0 || $scoreP2 < 0) {
            throw new InvalidArgumentException('Scores cannot be negative.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "UPDATE falcon.tournament_matches
                    SET score_player1 = :s1, score_player2 = :s2
                  WHERE id = :id"
            )->execute([':s1' => $scoreP1, ':s2' => $scoreP2, ':id' => $matchId]);

            $this->logMatchEvent(
                $matchId, (int)$match['tournament_id'], 'correction', null, $scoreP1, $scoreP2, $actorId,
                $note ?: "corrected from {$match['score_player1']}-{$match['score_player2']}"
            );

            $this->logAudit((int)$match['tournament_id'], $matchId, $actorId, 'score_corrected', [
                'from' => ['p1' => (int)$match['score_player1'], 'p2' => (int)$match['score_player2']],
                'to'   => ['p1' => $scoreP1, 'p2' => $scoreP2],
                'note' => $note,
            ]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Finish the match using its current live score. Winner is
     * whoever has the higher score; ties must be resolved (one more
     * point) before this can be called. Delegates to
     * recordMatchResult() so bracket advancement stays in one place.
     */
    public function completeMatch(int $matchId, int $actorId): void
    {
        $match = $this->getMatchForScoring($matchId);
        if (!$match) throw new RuntimeException("Match {$matchId} not found.");
        if ($match['status'] === 'completed') {
            throw new RuntimeException('This match has already been recorded.');
        }
        if (!$match['player1_id'] || !$match['player2_id']) {
            throw new RuntimeException('Both players must be set before completing a match.');
        }

        $s1 = (int)$match['score_player1'];
        $s2 = (int)$match['score_player2'];
        if ($s1 === $s2) {
            throw new RuntimeException('Scores are tied — record one more point before completing the match.');
        }

        $winnerId = $s1 > $s2 ? (int)$match['player1_id'] : (int)$match['player2_id'];

        $this->logMatchEvent($matchId, (int)$match['tournament_id'], 'match_complete', null, $s1, $s2, $actorId);
        $this->recordMatchResult($matchId, $winnerId, $s1, $s2, $actorId);
    }

    /** Append one row to the per-point/correction event log. Never throws. */
    private function logMatchEvent(
        int $matchId, int $tournamentId, string $type, ?int $playerSlot,
        int $s1, int $s2, int $actorId, string $note = ''
    ): void {
        try {
            $this->db->prepare(
                "INSERT INTO falcon.tournament_match_events
                     (match_id, tournament_id, event_type, player_slot, score_player1, score_player2, note, actor_id)
                 VALUES (:mid, :tid, :type, :slot, :s1, :s2, :note, :actor)"
            )->execute([
                ':mid'   => $matchId,
                ':tid'   => $tournamentId,
                ':type'  => $type,
                ':slot'  => $playerSlot,
                ':s1'    => $s1,
                ':s2'    => $s2,
                ':note'  => $note ?: null,
                ':actor' => $actorId,
            ]);
        } catch (Throwable $e) {
            // event logging is best-effort — must never block scoring
        }
    }

    /**
     * Notify both players of a match once it's fully set (both slots
     * filled) — "you're up next" for a player who may be off-court
     * between rounds. Safe to call any time a match's players/court
     * change; it no-ops if either slot is still empty. Never throws.
     */
    private function notifyMatchReady(int $matchId, string $reason = 'ready'): void
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT m.player1_id, m.player2_id, m.bracket_round, m.tournament_id,
                        t.name AS tournament_name,
                        COALESCE(p1.display_name, p1.full_name) AS p1_name,
                        COALESCE(p2.display_name, p2.full_name) AS p2_name,
                        c.name AS court_name
                   FROM falcon.tournament_matches m
                   JOIN falcon.tournaments t ON t.id = m.tournament_id
              LEFT JOIN falcon.users p1 ON p1.id = m.player1_id
              LEFT JOIN falcon.users p2 ON p2.id = m.player2_id
              LEFT JOIN falcon.courts c ON c.id = m.court_id
                  WHERE m.id = :id"
            );
            $stmt->execute([':id' => $matchId]);
            $m = $stmt->fetch();
            if (!$m || !$m['player1_id'] || !$m['player2_id']) return;

            $db   = $this->db;
            $link = APP_URL . '/public/tournament_details.php?id=' . (int)$m['tournament_id'];
            $round = (int)$m['bracket_round'];

            if ($reason === 'court_assigned' && $m['court_name']) {
                $title = "🏓 Court assigned — {$m['tournament_name']}";
                $msg1  = "Round {$round} vs {$m['p2_name']} is on {$m['court_name']}. Head over when you're ready.";
                $msg2  = "Round {$round} vs {$m['p1_name']} is on {$m['court_name']}. Head over when you're ready.";
            } else {
                $title = "🏆 You're up next — {$m['tournament_name']}";
                $msg1  = "Your Round {$round} opponent is set: {$m['p2_name']}. Stay nearby — you may be called to a court soon.";
                $msg2  = "Your Round {$round} opponent is set: {$m['p1_name']}. Stay nearby — you may be called to a court soon.";
            }

            notifyUser($db, (int)$m['player1_id'], $title, $msg1, null, $link);
            notifyUser($db, (int)$m['player2_id'], $title, $msg2, null, $link);
        } catch (Throwable $e) {
            // notifications are best-effort — must never block bracket/scoring flow
        }
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════

    private function requireStatus(int $id, string $expected, string $action): array
    {
        $t = $this->getTournament($id);
        if (!$t) throw new RuntimeException("Tournament {$id} not found.");
        if ($t['status'] !== $expected) {
            throw new RuntimeException(
                "Cannot {$action}: tournament is '{$t['status']}', expected '{$expected}'."
            );
        }
        return $t;
    }

    private function getRegisteredPlayerIds(int $tournamentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT player_id FROM falcon.tournament_players
              WHERE tournament_id = :tid AND status NOT IN ('withdrawn')
              ORDER BY seed ASC NULLS LAST, joined_at ASC"
        );
        $stmt->execute([':tid' => $tournamentId]);
        return array_column($stmt->fetchAll(), 'player_id');
    }

    private function seedPlayersByRank(int $tournamentId, array $playerIds): array
    {
        if (empty($playerIds)) return [];

        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT player_id, COALESCE(total_points, 0) AS pts
               FROM falcon.leaderboard
              WHERE player_id IN ({$placeholders})
                AND season = ?"
        );
        $params   = array_merge($playerIds, [(int) date('Y')]);
        $stmt->execute($params);
        $ranked   = array_column($stmt->fetchAll(), 'pts', 'player_id');

        usort($playerIds, function ($a, $b) use ($ranked) {
            $pa = $ranked[$a] ?? -1;
            $pb = $ranked[$b] ?? -1;
            return $pb <=> $pa;
        });

        return array_values($playerIds);
    }

    private function insertMatches(array $matches): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO falcon.tournament_matches
                 (tournament_id, bracket_round, match_number, bracket_section,
                  player1_id, player2_id, status)
             VALUES
                 (:tid, :round, :match_no, :section,
                  :p1, :p2, :status)"
        );
        foreach ($matches as $m) {
            $stmt->execute([
                ':tid'     => $m['tournament_id'],
                ':round'   => $m['bracket_round'],
                ':match_no'=> $m['match_number'],
                ':section' => $m['bracket_section'] ?? 'main',
                ':p1'      => $m['player1_id'],
                ':p2'      => $m['player2_id'],
                ':status'  => $m['status'] ?? 'pending',
            ]);
        }
    }

    /**
     * Advance the winner of a single-elim match into the correct
     * slot of the next-round match.
     *
     * Pairing rule: match N in round R → ceil(N/2) in round R+1.
     * Odd match numbers → player1_id slot; even → player2_id slot.
     */
    private function advanceWinnerSingleElim(
        int $tournamentId,
        int $completedRound,
        int $completedMatchNo,
        int $winnerId
    ): void {
        $nextRound   = $completedRound + 1;
        $nextMatchNo = (int) ceil($completedMatchNo / 2);
        $slot        = ($completedMatchNo % 2 === 1) ? 'player1_id' : 'player2_id';

        $stmt = $this->db->prepare(
            "SELECT id FROM falcon.tournament_matches
              WHERE tournament_id = :tid
                AND bracket_round = :round
                AND match_number  = :matchno
                AND bracket_section IN ('main','winners')
              LIMIT 1"
        );
        $stmt->execute([
            ':tid'     => $tournamentId,
            ':round'   => $nextRound,
            ':matchno' => $nextMatchNo,
        ]);
        $nextMatch = $stmt->fetch();
        if (!$nextMatch) return;

        $this->db->prepare(
            "UPDATE falcon.tournament_matches
                SET {$slot} = :winner,
                    status  = CASE
                                WHEN player1_id IS NOT NULL AND player2_id IS NOT NULL THEN 'pending'
                                ELSE status
                              END
              WHERE id = :id"
        )->execute([':winner' => $winnerId, ':id' => $nextMatch['id']]);

        $this->notifyMatchReady((int)$nextMatch['id'], 'opponent_set');
    }
}