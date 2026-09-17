<?php
// ============================================================
//  FILE: leaderboard/rank_tracker.php
//  Provides rank history and trend helpers.
// ============================================================
if (defined('RANK_TRACKER_LOADED')) return;
define('RANK_TRACKER_LOADED', true);

require_once __DIR__ . '/leaderboard_engine.php';
require_once __DIR__ . '/../config/db.php';

class RankTracker
{
    private PDO $db;
    private LeaderboardEngine $leaderboard;

    public function __construct()
    {
        $this->db = getDB();
        $this->leaderboard = new LeaderboardEngine();
    }

    public static function resetSeason(int $season = null): int
    {
        $season = $season ?? (int) date('Y');
        $adminId = (int) ($_SESSION['user_id'] ?? 0);
        $engine = new LeaderboardEngine();
        return $engine->resetSeason($season, $adminId);
    }

    public static function archiveSeason(int $season = null): bool
    {
        $season = $season ?? (int) date('Y');
        try {
            $db = getDB();
            $stmt = $db->prepare(
                "UPDATE falcon.seasons
                    SET archived = TRUE
                  WHERE year = :season"
            );
            return $stmt->execute([':season' => $season]);
        } catch (Throwable $e) {
            error_log('[RankTracker] Archive failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function recomputeRanks(int $season = null): void
    {
        $season = $season ?? (int) date('Y');
        $engine = new LeaderboardEngine();
        $engine->recomputeRanks($season);
    }

    public function getRankHistory(int $playerId, int $months = 6): array
    {
        $sql = "SELECT rank, total_points, last_updated
                  FROM falcon.leaderboard_history
                 WHERE player_id = :pid
                   AND date_trunc('month', last_updated) >= date_trunc('month', NOW() - INTERVAL ':months months')
              ORDER BY last_updated ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':pid' => $playerId, ':months' => $months]);
        return $stmt->fetchAll();
    }

    public function estimateFutureRank(int $playerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT rank, total_points, last_updated
               FROM falcon.leaderboard_history
              WHERE player_id = :pid
              ORDER BY last_updated DESC
              LIMIT 5"
        );
        $stmt->execute([':pid' => $playerId]);
        $history = $stmt->fetchAll();

        return [
            'history' => $history,
            'estimate' => end($history)['rank'] ?? null,
        ];
    }
}
