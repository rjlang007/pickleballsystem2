<?php
// ============================================================
//  FILE: leaderboard/achievement_system.php
//  Purpose: Check and award achievement badges
// ============================================================
if (defined('ACHIEVEMENT_SYSTEM_LOADED')) return;
define('ACHIEVEMENT_SYSTEM_LOADED', true);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/tournament_config.php';

class AchievementSystem
{
    private PDO   $db;
    private array $config;

    public function __construct()
    {
        $this->db     = getDB();
        $this->config = require __DIR__ . '/../config/tournament_config.php';
    }

    public function checkAndAward(int $playerId, int $tournamentId): array
    {
        $awarded = [];
        $stats   = $this->getPlayerStats($playerId);

        $checks = [
            'first_win'      => $stats['total_wins'] >= 1,
            'five_wins'      => $stats['total_wins'] >= 5,
            'ten_wins'       => $stats['total_wins'] >= 10,
            'top_10'         => $stats['rank'] !== null && $stats['rank'] <= 10,
            'top_3'          => $stats['rank'] !== null && $stats['rank'] <= 3,
            'win_streak_3'   => $stats['win_streak'] >= 3,
            'win_streak_5'   => $stats['win_streak'] >= 5,
            'participated_10'=> $stats['total_tournaments'] >= 10,
        ];

        foreach ($checks as $type => $earned) {
            if ($earned && $this->award($playerId, $type, $tournamentId)) {
                $awarded[] = $type;
            }
        }

        return $awarded;
    }

    public function award(int $playerId, string $type, int $tournamentId = null): bool
    {
        if (!isset($this->config['achievements'][$type])) return false;

        $def  = $this->config['achievements'][$type];
        $stmt = $this->db->prepare(
            "INSERT INTO falcon.achievements (player_id, achievement_type, description, tournament_id)
             VALUES (:pid, :type, :desc, :tid)
             ON CONFLICT (player_id, achievement_type) DO NOTHING"
        );
        $stmt->execute([
            ':pid'  => $playerId,
            ':type' => $type,
            ':desc' => $def['description'],
            ':tid'  => $tournamentId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function getPlayerAchievements(int $playerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, t.name AS tournament_name
               FROM falcon.achievements a
          LEFT JOIN falcon.tournaments t ON t.id = a.tournament_id
              WHERE a.player_id = :pid
              ORDER BY a.achieved_at DESC"
        );
        $stmt->execute([':pid' => $playerId]);
        $rows = $stmt->fetchAll();

        // Merge in icon from config
        return array_map(function ($row) {
            $def = $this->config['achievements'][$row['achievement_type']] ?? [];
            $row['icon'] = $def['icon'] ?? '🏅';
            $row['name'] = $def['name'] ?? $row['achievement_type'];
            return $row;
        }, $rows);
    }

    private function getPlayerStats(int $playerId): array
    {
        $season = (int) date('Y');

        $lb = $this->db->prepare(
            "SELECT total_wins, total_tournaments, rank
               FROM falcon.leaderboard
              WHERE player_id = :pid AND season = :season"
        );
        $lb->execute([':pid' => $playerId, ':season' => $season]);
        $row = $lb->fetch() ?: ['total_wins' => 0, 'total_tournaments' => 0, 'rank' => null];

        // Win streak
        $streak = $this->db->prepare(
            "SELECT ts.placement
               FROM falcon.tournament_scores ts
               JOIN falcon.tournaments t ON t.id = ts.tournament_id
              WHERE ts.player_id = :pid AND t.status = 'completed'
              ORDER BY t.end_date DESC NULLS LAST LIMIT 10"
        );
        $streak->execute([':pid' => $playerId]);
        $results     = $streak->fetchAll(PDO::FETCH_COLUMN, 0);
        $winStreak   = 0;
        foreach ($results as $p) {
            if ((int)$p === 1) $winStreak++;
            else break;
        }
        $row['win_streak'] = $winStreak;
        return $row;
    }
}