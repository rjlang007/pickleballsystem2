<?php
// ============================================================
//  FILE: leaderboard/player_stats_engine.php
//  Player-centric leaderboard and history helpers.
// ============================================================
if (defined('PLAYER_STATS_ENGINE_LOADED')) return;
define('PLAYER_STATS_ENGINE_LOADED', true);

require_once __DIR__ . '/leaderboard_engine.php';

class PlayerStatsEngine
{
    private LeaderboardEngine $leaderboard;

    public function __construct()
    {
        $this->leaderboard = new LeaderboardEngine();
    }

    public function getPlayerSeasonStats(int $playerId, ?int $season = null): array
    {
        return $this->leaderboard->getPlayerStats($playerId, $season);
    }

    public function getPlayerHistory(int $playerId, ?int $season = null): array
    {
        $stats = $this->leaderboard->getPlayerStats($playerId, $season);
        return $stats['history'] ?? [];
    }

    public function getPlayerAchievements(int $playerId): array
    {
        require_once __DIR__ . '/achievement_system.php';
        $ach = new AchievementSystem();
        return $ach->getPlayerAchievements($playerId);
    }
}
