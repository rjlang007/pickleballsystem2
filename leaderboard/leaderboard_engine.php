<?php
// ============================================================
//  FILE: leaderboard/leaderboard_engine.php
//  Aggregates tournament_scores into the season leaderboard.
//  Called by TournamentEngine::completeTournament().
// ============================================================
if (defined('LEADERBOARD_ENGINE_LOADED')) return;
define('LEADERBOARD_ENGINE_LOADED', true);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/tournament_config.php';

class LeaderboardEngine
{
    private PDO   $db;
    private array $config;

    public function __construct()
    {
        $this->db     = getDB();
        $cfg          = require __DIR__ . '/../config/tournament_config.php';
        $this->config = is_array($cfg) ? $cfg : [];
    }

    // ══════════════════════════════════════════════════════════
    // PROCESS A COMPLETED TOURNAMENT → UPDATE LEADERBOARD
    // ══════════════════════════════════════════════════════════

    /**
     * Read tournament_scores for $tournamentId, upsert into
     * falcon.leaderboard (season = year of tournament start_date),
     * then recompute rank for the whole season.
     *
     * Called inside TournamentEngine::completeTournament() which
     * already holds a transaction, so we do NOT start one here.
     */
    public function processTournamentCompletion(int $tournamentId): void
    {
        // Load tournament to get season year
        $tStmt = $this->db->prepare(
            "SELECT start_date FROM falcon.tournaments WHERE id = :id"
        );
        $tStmt->execute([':id' => $tournamentId]);
        $tournament = $tStmt->fetch();

        if (!$tournament) {
            throw new RuntimeException("Tournament {$tournamentId} not found.");
        }

        $season = $tournament['start_date']
            ? (int) date('Y', strtotime($tournament['start_date']))
            : (int) date('Y');

        // Load all scores for this tournament
        $scoreStmt = $this->db->prepare(
            "SELECT player_id, placement, points
               FROM falcon.tournament_scores
              WHERE tournament_id = :tid"
        );
        $scoreStmt->execute([':tid' => $tournamentId]);
        $scores = $scoreStmt->fetchAll();

        if (empty($scores)) return;

        // Upsert leaderboard rows — increment totals
        $upsert = $this->db->prepare(
            "INSERT INTO falcon.leaderboard
                 (player_id, season, total_points, total_wins, total_tournaments, last_update)
             VALUES
                 (:pid, :season, :pts, :wins, 1, NOW())
             ON CONFLICT (player_id, season)
             DO UPDATE SET
                 total_points      = falcon.leaderboard.total_points      + EXCLUDED.total_points,
                 total_wins        = falcon.leaderboard.total_wins        + EXCLUDED.total_wins,
                 total_tournaments = falcon.leaderboard.total_tournaments + 1,
                 last_update       = NOW()"
        );

        foreach ($scores as $row) {
            $isWin = ((int)$row['placement'] === 1) ? 1 : 0;
            $upsert->execute([
                ':pid'    => (int)$row['player_id'],
                ':season' => $season,
                ':pts'    => (int)$row['points'],
                ':wins'   => $isWin,
            ]);
        }

        // Recompute rank for the entire season
        $this->recomputeSeasonRanks($season);
    }

    // ══════════════════════════════════════════════════════════
    // SEASON LEADERBOARD
    // ══════════════════════════════════════════════════════════

    /**
     * Return paginated season leaderboard with user details.
     *
     * @return array { players: array[], total: int }
     */
    public function getLeaderboard(
        int $season,
        int $limit  = 20,
        int $offset = 0
    ): array {
        // Total count
        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM falcon.leaderboard
              WHERE season = :season"
        );
        $countStmt->execute([':season' => $season]);
        $total = (int)$countStmt->fetchColumn();

        // Paginated rows
        $stmt = $this->db->prepare(
            "SELECT lb.player_id,
                    lb.season,
                    lb.total_points,
                    lb.total_wins,
                    lb.total_tournaments,
                    lb.rank,
                    lb.last_update AS updated_at,
                    COALESCE(u.display_name, u.full_name, u.username) AS display_name,
                    u.full_name,
                    u.username
               FROM falcon.leaderboard lb
               JOIN falcon.users u ON u.id = lb.player_id
              WHERE lb.season = :season
              ORDER BY lb.rank ASC, lb.total_points DESC
              LIMIT  :lim
              OFFSET :off"
        );
        $stmt->bindValue(':season', $season, PDO::PARAM_INT);
        $stmt->bindValue(':lim',    $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off',    $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'players' => $stmt->fetchAll(),
            'total'   => $total,
        ];
    }

    // ══════════════════════════════════════════════════════════
    // MONTHLY LEADERBOARD
    // ══════════════════════════════════════════════════════════

    /**
     * Return players ranked by points earned from tournaments
     * whose start_date falls in the given month/year.
     *
     * @return array { players: array[] }
     */
    public function getMonthlyLeaderboard(int $season, int $month): array
    {
        $stmt = $this->db->prepare(
            "SELECT ts.player_id,
                    SUM(ts.points)              AS total_points,
                    SUM(CASE WHEN ts.placement = 1 THEN 1 ELSE 0 END) AS wins,
                    COUNT(DISTINCT ts.tournament_id)                   AS tournaments,
                    COALESCE(u.display_name, u.full_name, u.username)  AS display_name,
                    u.full_name,
                    u.username,
                    RANK() OVER (ORDER BY SUM(ts.points) DESC)         AS rank
               FROM falcon.tournament_scores ts
               JOIN falcon.tournaments t ON t.id = ts.tournament_id
               JOIN falcon.users       u ON u.id = ts.player_id
              WHERE t.status = 'completed'
                AND EXTRACT(YEAR  FROM t.start_date) = :season
                AND EXTRACT(MONTH FROM t.start_date) = :month
              GROUP BY ts.player_id, u.display_name, u.full_name, u.username
              ORDER BY total_points DESC, wins DESC"
        );
        $stmt->execute([':season' => $season, ':month' => $month]);

        return ['players' => $stmt->fetchAll()];
    }

    // ══════════════════════════════════════════════════════════
    // PLAYER-CENTRIC HELPERS
    // ══════════════════════════════════════════════════════════

    /**
     * Return a single player's rank within a season, or null if
     * they have no leaderboard row for that season yet.
     */
    public function getPlayerRank(int $playerId, ?int $season = null): ?int
    {
        $season = $season ?? (int) date('Y');

        $stmt = $this->db->prepare(
            "SELECT rank
               FROM falcon.leaderboard
              WHERE player_id = :pid AND season = :season"
        );
        $stmt->execute([':pid' => $playerId, ':season' => $season]);
        $rank = $stmt->fetchColumn();

        return ($rank !== false && $rank !== null) ? (int) $rank : null;
    }

    /**
     * Return a full stats bundle for a player: season aggregate
     * totals, current win streak, earned achievements, and recent
     * tournament history.
     *
     * @return array {
     *     aggregate: array{rank:?int, total_points:int, total_wins:int,
     *                      total_losses:int, total_tournaments:int},
     *     win_streak: int,
     *     achievements: array[],
     *     history: array[]
     * }
     */
    public function getPlayerStats(int $playerId, ?int $season = null): array
    {
        $season = $season ?? (int) date('Y');

        // Season aggregate from the leaderboard table
        $lbStmt = $this->db->prepare(
            "SELECT total_points, total_wins, total_tournaments, rank
               FROM falcon.leaderboard
              WHERE player_id = :pid AND season = :season"
        );
        $lbStmt->execute([':pid' => $playerId, ':season' => $season]);
        $row = $lbStmt->fetch() ?: [
            'total_points'      => 0,
            'total_wins'        => 0,
            'total_tournaments' => 0,
            'rank'              => null,
        ];

        $aggregate = [
            'rank'              => $row['rank'] !== null ? (int) $row['rank'] : null,
            'total_points'      => (int) $row['total_points'],
            'total_wins'        => (int) $row['total_wins'],
            'total_tournaments' => (int) $row['total_tournaments'],
            // Not tracked as its own column — derived from tournaments
            // played vs. tournaments won.
            'total_losses'      => max(0, (int) $row['total_tournaments'] - (int) $row['total_wins']),
        ];

        // Win streak: walk the player's most recent completed
        // tournaments (newest first) and count consecutive 1st-place
        // finishes.
        $streakStmt = $this->db->prepare(
            "SELECT ts.placement
               FROM falcon.tournament_scores ts
               JOIN falcon.tournaments t ON t.id = ts.tournament_id
              WHERE ts.player_id = :pid AND t.status = 'completed'
              ORDER BY t.end_date DESC NULLS LAST
              LIMIT 10"
        );
        $streakStmt->execute([':pid' => $playerId]);
        $winStreak = 0;
        foreach ($streakStmt->fetchAll(PDO::FETCH_COLUMN, 0) as $placement) {
            if ((int) $placement === 1) {
                $winStreak++;
            } else {
                break;
            }
        }

        // Achievements earned by the player
        require_once __DIR__ . '/achievement_system.php';
        $achievements = (new AchievementSystem())->getPlayerAchievements($playerId);

        // Recent tournament history
        $historyStmt = $this->db->prepare(
            "SELECT t.name AS tournament_name,
                    ts.placement,
                    ts.points,
                    t.start_date,
                    t.end_date
               FROM falcon.tournament_scores ts
               JOIN falcon.tournaments t ON t.id = ts.tournament_id
              WHERE ts.player_id = :pid AND t.status = 'completed'
              ORDER BY t.end_date DESC NULLS LAST
              LIMIT 10"
        );
        $historyStmt->execute([':pid' => $playerId]);
        $history = $historyStmt->fetchAll();

        return [
            'aggregate'    => $aggregate,
            'win_streak'   => $winStreak,
            'achievements' => $achievements,
            'history'      => $history,
        ];
    }

    // ══════════════════════════════════════════════════════════
    // OPEN PLAY LEADERBOARD (season totals from Open Play only)
    // ══════════════════════════════════════════════════════════
    // Unlike getLeaderboard() above (which reads falcon.leaderboard
    // and mixes in regular bracket-tournament points), everything
    // in this section is computed live and exclusively from
    // Open Play sessions: falcon.tournament_scores joined to
    // falcon.tournaments where bracket_type = 'open_play' and
    // status = 'completed' (1st = 3 pts, 2nd = 2, 3rd = 1, else 0
    // — see config/tournament_config.php open_play_point_distribution),
    // plus any manual deltas from
    // falcon.open_play_leaderboard_adjustments.
    //
    // This is the single source of truth shared by
    // admin/leaderboard_admin.php, public/leaderboard.php, the
    // dashboard "Your Rank" widget, and the rank-change
    // notification triggered from OpenPlayEngine::finalizeEvent(),
    // so every one of those surfaces always agrees on the same
    // numbers.

    /**
     * Every player's Open Play standing for a season, keyed by
     * player_id. Each entry: {player_id, total_points, total_wins,
     * total_events, rank, tied}. 'tied' is true when one or more
     * other players share the exact same rank.
     */
    public function getOpenPlaySeasonStandings(int $season): array
    {
        $stmt = $this->db->prepare(
            "WITH op_scores AS (
                 SELECT ts.player_id,
                        SUM(ts.points)::int                                    AS op_points,
                        SUM(CASE WHEN ts.placement = 1 THEN 1 ELSE 0 END)::int AS op_wins,
                        COUNT(DISTINCT ts.tournament_id)::int                  AS op_events
                   FROM falcon.tournament_scores ts
                   JOIN falcon.tournaments t ON t.id = ts.tournament_id
                  WHERE t.bracket_type = 'open_play'
                    AND t.status = 'completed'
                    AND EXTRACT(YEAR FROM COALESCE(t.end_date, t.start_date)) = :season
                                     AND NOT EXISTS (
                                             SELECT 1
                                                 FROM falcon.open_play_leaderboard_exclusions ex
                                                WHERE ex.player_id = ts.player_id AND ex.season = :season3
                                     )
                  GROUP BY ts.player_id
             ),
             adj AS (
                 SELECT player_id, SUM(points_delta)::int AS adj_points
                   FROM falcon.open_play_leaderboard_adjustments
                  WHERE season = :season2
                                        AND NOT EXISTS (
                                                SELECT 1
                                                    FROM falcon.open_play_leaderboard_exclusions ex
                                                 WHERE ex.player_id = falcon.open_play_leaderboard_adjustments.player_id
                                                     AND ex.season = :season4
                                        )
                  GROUP BY player_id
             )
             SELECT COALESCE(op.player_id, adj.player_id)                  AS player_id,
                    COALESCE(op.op_points, 0) + COALESCE(adj.adj_points, 0) AS total_points,
                    COALESCE(op.op_wins, 0)                                 AS total_wins,
                    COALESCE(op.op_events, 0)                               AS total_events,
                    RANK() OVER (
                        ORDER BY COALESCE(op.op_points, 0) + COALESCE(adj.adj_points, 0) DESC,
                                 COALESCE(op.op_wins, 0) DESC
                    ) AS rnk
               FROM op_scores op
               FULL OUTER JOIN adj ON adj.player_id = op.player_id"
        );
        $stmt->execute([
            ':season'  => $season,
            ':season2' => $season,
            ':season3' => $season,
            ':season4' => $season,
        ]);
        $rows = $stmt->fetchAll();

        $byRank = [];
        foreach ($rows as $r) {
            $byRank[(int) $r['rnk']][] = $r['player_id'];
        }

        $standings = [];
        foreach ($rows as $r) {
            $rnk = (int) $r['rnk'];
            $pid = (int) $r['player_id'];
            $standings[$pid] = [
                'player_id'    => $pid,
                'total_points' => (int) $r['total_points'],
                'total_wins'   => (int) $r['total_wins'],
                'total_events' => (int) $r['total_events'],
                'rank'         => $rnk,
                'tied'         => count($byRank[$rnk]) > 1,
            ];
        }
        return $standings;
    }

    /**
     * Paginated, optionally name-filtered Open Play leaderboard with
     * display info attached, for admin/public listing pages.
     *
     * @return array{players: array[], total: int}
     */
    public function getOpenPlayLeaderboard(int $season, int $limit = 50, int $offset = 0, string $search = ''): array
    {
        $standings = $this->getOpenPlaySeasonStandings($season);
        if (empty($standings)) {
            return ['players' => [], 'total' => 0];
        }

        $ids          = array_keys($standings);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql          = "SELECT id AS player_id,
                                 COALESCE(display_name, full_name, username) AS name,
                                 full_name, username, avatar AS avatar_url
                            FROM falcon.users
                           WHERE id IN ($placeholders)";
        $bindArgs = $ids;
        if ($search !== '') {
            $sql       .= " AND (full_name ILIKE ? OR username ILIKE ?)";
            $bindArgs[] = "%{$search}%";
            $bindArgs[] = "%{$search}%";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindArgs);

        $merged = [];
        foreach ($stmt->fetchAll() as $u) {
            $pid = (int) $u['player_id'];
            if (!isset($standings[$pid])) continue;
            $merged[] = array_merge($standings[$pid], $u);
        }

        usort($merged, function ($a, $b) {
            if ($a['rank'] !== $b['rank']) return $a['rank'] <=> $b['rank'];
            return strcasecmp($a['name'], $b['name']);
        });

        return [
            'players' => array_slice($merged, $offset, $limit),
            'total'   => count($merged),
        ];
    }

    /**
     * A single player's Open Play standing for a season, or null if
     * they have no Open Play points/events/adjustments that season.
     * Includes 'total_players' — how many players are on the board
     * at all, for widgets like "Your Rank: #4 of 27".
     */
    public function getOpenPlayPlayerStanding(int $playerId, ?int $season = null): ?array
    {
        $season    = $season ?? (int) date('Y');
        $standings = $this->getOpenPlaySeasonStandings($season);
        $row       = $standings[$playerId] ?? null;
        if ($row === null) {
            return null;
        }
        $row['total_players'] = count($standings);
        return $row;
    }

    // ══════════════════════════════════════════════════════════
    // MANUAL POINT ADJUSTMENT (admin tool)
    // ══════════════════════════════════════════════════════════

    /**
     * Manually add/subtract points for a player in a season
     * (creates the season row if the player doesn't have one yet),
     * then recomputes ranks for that season. Used by
     * admin/leaderboard_admin.php.
     */
    public function adjustPoints(int $playerId, int $season, int $delta): void
    {
        $this->db->prepare(
            "INSERT INTO falcon.leaderboard
                 (player_id, season, total_points, total_wins, total_tournaments, last_update)
             VALUES (:pid, :season, GREATEST(0, :delta), 0, 0, NOW())
             ON CONFLICT (player_id, season)
             DO UPDATE SET
                 total_points = GREATEST(0, falcon.leaderboard.total_points + :delta2),
                 last_update  = NOW()"
        )->execute([':pid' => $playerId, ':season' => $season, ':delta' => $delta, ':delta2' => $delta]);

        $this->recomputeSeasonRanks($season);
    }

    // ══════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════

    /**
     * Recompute the `rank` column for all rows in a given season.
     * Uses a window function: RANK() OVER (PARTITION BY season ORDER BY total_points DESC).
     */
    private function recomputeSeasonRanks(int $season): void
    {
        $this->db->prepare(
            "UPDATE falcon.leaderboard lb
                SET rank = sub.r
               FROM (
                   SELECT id,
                          RANK() OVER (
                              PARTITION BY season
                              ORDER BY total_points DESC,
                                       total_wins   DESC
                          ) AS r
                     FROM falcon.leaderboard
                    WHERE season = :season
               ) sub
              WHERE lb.id = sub.id
                AND lb.season = :season2"
        )->execute([':season' => $season, ':season2' => $season]);
    }
}