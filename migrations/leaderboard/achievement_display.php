<?php
/**
 * Leaderboard: Achievement Display Engine
 * Path: leaderboard/achievement_display.php
 * Display and manage achievement badges
 */

class AchievementDisplay {
    private $pdo;
    private $achievements_config = [
        'first_win' => [
            'name' => 'First Win',
            'emoji' => '🎯',
            'description' => 'Won your first tournament',
            'color' => '#e74c3c'
        ],
        'five_wins' => [
            'name' => '5 Wins',
            'emoji' => '⭐',
            'description' => 'Won 5 tournaments',
            'color' => '#f39c12'
        ],
        'ten_wins' => [
            'name' => '10 Wins',
            'emoji' => '🏅',
            'description' => 'Won 10 tournaments',
            'color' => '#27ae60'
        ],
        'top_10' => [
            'name' => 'Top 10 Ranked',
            'emoji' => '👑',
            'description' => 'Reached top 10 in rankings',
            'color' => '#3498db'
        ],
        'win_streak_5' => [
            'name' => '5-Win Streak',
            'emoji' => '🔥',
            'description' => 'Won 5 consecutive tournaments',
            'color' => '#e67e22'
        ],
        'monthly_champion' => [
            'name' => 'Monthly Champion',
            'emoji' => '🏆',
            'description' => 'Top ranked player in a month',
            'color' => '#9b59b6'
        ]
    ];

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Get all achievements for a player
     */
    public function getPlayerAchievements($player_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                pa.type,
                pa.unlocked_at,
                pa.id
            FROM player_achievements pa
            WHERE pa.player_id = ?
            ORDER BY pa.unlocked_at DESC
        ");
        $stmt->execute([$player_id]);
        $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function($achievement) {
            return array_merge(
                $achievement,
                $this->achievements_config[$achievement['type']] ?? [
                    'name' => ucfirst(str_replace('_', ' ', $achievement['type'])),
                    'emoji' => '⭐',
                    'description' => '',
                    'color' => '#95a5a6'
                ]
            );
        }, $achievements);
    }

    /**
     * Get achievement details
     */
    public function getAchievementDetails($type) {
        return $this->achievements_config[$type] ?? null;
    }

    /**
     * Render achievement badge HTML
     */
    public function renderBadge($achievement, $size = 'medium', $locked = false) {
        $classes = "achievement-badge achievement-badge-{$size}";
        if ($locked) {
            $classes .= " achievement-badge-locked";
        }

        $html = '<div class="' . $classes . '" ';
        $html .= 'style="background-color: ' . htmlspecialchars($achievement['color'] ?? '#95a5a6') . ';"';
        $html .= ' title="' . htmlspecialchars($achievement['description'] ?? '') . '">';
        $html .= '<span class="achievement-emoji">' . ($achievement['emoji'] ?? '⭐') . '</span>';
        $html .= '<span class="achievement-name">' . htmlspecialchars($achievement['name'] ?? 'Unknown') . '</span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render achievement showcase (multiple badges)
     */
    public function renderShowcase($achievements, $limit = 5, $size = 'small') {
        $html = '<div class="achievement-showcase achievement-showcase-' . $size . '">';
        
        $displayed = array_slice($achievements, 0, $limit);
        foreach ($displayed as $achievement) {
            $html .= $this->renderBadge($achievement, $size);
        }

        if (count($achievements) > $limit) {
            $remaining = count($achievements) - $limit;
            $html .= '<div class="achievement-more">';
            $html .= '+' . $remaining . ' more';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Check if player should earn achievement
     */
    public function checkAchievements($player_id, $tournament_id = null) {
        $new_achievements = [];

        // Check first win
        if (!$this->hasAchievement($player_id, 'first_win')) {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as cnt FROM tournament_matches 
                WHERE winner_id = ?
            ");
            $stmt->execute([$player_id]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] >= 1) {
                $this->unlockAchievement($player_id, 'first_win');
                $new_achievements[] = 'first_win';
            }
        }

        // Check five wins
        if (!$this->hasAchievement($player_id, 'five_wins')) {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as cnt FROM tournament_matches 
                WHERE winner_id = ?
            ");
            $stmt->execute([$player_id]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] >= 5) {
                $this->unlockAchievement($player_id, 'five_wins');
                $new_achievements[] = 'five_wins';
            }
        }

        // Check ten wins
        if (!$this->hasAchievement($player_id, 'ten_wins')) {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as cnt FROM tournament_matches 
                WHERE winner_id = ?
            ");
            $stmt->execute([$player_id]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] >= 10) {
                $this->unlockAchievement($player_id, 'ten_wins');
                $new_achievements[] = 'ten_wins';
            }
        }

        // Check top 10 ranked
        if (!$this->hasAchievement($player_id, 'top_10')) {
            $stmt = $this->pdo->prepare("
                SELECT ranking FROM leaderboard 
                WHERE player_id = ? AND YEAR(season) = YEAR(CURDATE())
                ORDER BY season DESC LIMIT 1
            ");
            $stmt->execute([$player_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && $result['ranking'] <= 10) {
                $this->unlockAchievement($player_id, 'top_10');
                $new_achievements[] = 'top_10';
            }
        }

        return $new_achievements;
    }

    /**
     * Check if player has achievement
     */
    private function hasAchievement($player_id, $type) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as cnt FROM player_achievements 
            WHERE player_id = ? AND type = ?
        ");
        $stmt->execute([$player_id, $type]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;
    }

    /**
     * Unlock achievement for player
     */
    public function unlockAchievement($player_id, $type) {
        if ($this->hasAchievement($player_id, $type)) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO player_achievements (player_id, type, unlocked_at)
            VALUES (?, ?, NOW())
        ");
        return $stmt->execute([$player_id, $type]);
    }
}

?>
