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