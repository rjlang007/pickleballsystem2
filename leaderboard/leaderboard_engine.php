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