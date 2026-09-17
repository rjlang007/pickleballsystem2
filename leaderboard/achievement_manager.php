<?php
// ============================================================
//  FILE: leaderboard/achievement_manager.php
//  Achievement management helpers.
// ============================================================
if (defined('ACHIEVEMENT_MANAGER_LOADED')) return;
define('ACHIEVEMENT_MANAGER_LOADED', true);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/tournament_config.php';

class AchievementManager
{
    private PDO $db;
    private array $config;

    public function __construct()
    {
        $this->db     = getDB();
        $this->config = require __DIR__ . '/../config/tournament_config.php';
    }

    public static function getAllAchievements(): array
    {
        $config = require __DIR__ . '/../config/tournament_config.php';
        $achievements = $config['achievements'] ?? [];
        return array_map(static function ($key, $value) {
            return array_merge(['key' => $key], $value);
        }, array_keys($achievements), $achievements);
    }

    public static function awardAchievementToPlayer(int $playerId, string $achievementKey): bool
    {
        $config = require __DIR__ . '/../config/tournament_config.php';
        $achievement = $config['achievements'][$achievementKey] ?? null;
        if (!$achievement) {
            return false;
        }

        $db = getDB();
        try {
            $stmt = $db->prepare(
                "INSERT INTO falcon.achievements
                     (player_id, achievement_type, label, description, achieved_at)
                 VALUES
                     (:pid, :type, :label, :desc, NOW())
                 ON CONFLICT (player_id, achievement_type) DO NOTHING"
            );
            return $stmt->execute([
                ':pid'   => $playerId,
                ':type'  => $achievementKey,
                ':label' => $achievement['label'] ?? $achievementKey,
                ':desc'  => $achievement['description'] ?? '',
            ]);
        } catch (Throwable $e) {
            error_log('[AchievementManager] Award failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function getRecentPlayerAchievements(int $playerId, int $limit = 20): array
    {
        $db = getDB();
        $stmt = $db->prepare(
            "SELECT * FROM falcon.achievements
               WHERE player_id = :pid
               ORDER BY achieved_at DESC
               LIMIT :lim"
        );
        $stmt->bindValue(':pid', $playerId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
