<?php
/**
 * Leaderboard: Player Comparison Engine
 * Path: leaderboard/player_comparison.php
 * Compare multiple players' statistics
 */

class PlayerComparison {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Compare multiple players
     */
    public function comparePlayers($player_ids, $season = null) {
        if (!is_array($player_ids) || empty($player_ids)) {
            return null;
        }

        $season = $season ?? date('Y');
        $placeholders = implode(',', array_fill(0, count($player_ids), '?'));

        $stmt = $this->pdo->prepare("
            SELECT 
                l.player_id,
                u.name,
                u.avatar_url,
                l.ranking,
                l.points,
                (SELECT COUNT(*) FROM tournament_matches WHERE winner_id = u.id AND YEAR(created_at) = ?) as wins,
                (SELECT COUNT(*) FROM tournament_matches WHERE 
                    (winner_id = u.id OR loser_id = u.id) AND YEAR(created_at) = ?) as total_matches,
                (SELECT COUNT(DISTINCT tournament_id) FROM tournament_players WHERE player_id = u.id AND YEAR(created_at) = ?) as tournament_count
            FROM leaderboard l
            JOIN users u ON l.player_id = u.id
            WHERE l.player_id IN ($placeholders) AND YEAR(l.season) = ?
        ");

        $params = array_merge([$season, $season, $season], $player_ids, [$season]);
        $stmt->execute($params);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enhance with calculated fields
        $enhanced = array_map(function($player) {
            $player['win_rate'] = $player['total_matches'] > 0 
                ? round(($player['wins'] / $player['total_matches']) * 100, 1)
                : 0;
            $player['avg_placement'] = $player['tournament_count'] > 0 
                ? 'TBD'
                : 'N/A';
            return $player;
        }, $players);

        return $enhanced;
    }

    /**
     * Get head-to-head stats
     */
    public function getHeadToHead($player1_id, $player2_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_matches,
                SUM(CASE WHEN winner_id = ? THEN 1 ELSE 0 END) as player1_wins,
                SUM(CASE WHEN winner_id = ? THEN 1 ELSE 0 END) as player2_wins
            FROM tournament_matches
            WHERE 
                (player1_id = ? AND player2_id = ?) OR
                (player1_id = ? AND player2_id = ?) OR
                (winner_id = ? AND (player1_id = ? OR player2_id = ?)) OR
                (winner_id = ? AND (player1_id = ? OR player2_id = ?))
        ");

        $stmt->execute([
            $player1_id, $player2_id,
            $player1_id, $player2_id,
            $player2_id, $player1_id,
            $player1_id, $player1_id, $player2_id,
            $player2_id, $player1_id, $player2_id
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Rank comparison
     */
    public function compareRanks($player_ids, $season = null) {
        if (!is_array($player_ids) || empty($player_ids)) {
            return null;
        }

        $season = $season ?? date('Y');
        $placeholders = implode(',', array_fill(0, count($player_ids), '?'));

        $stmt = $this->pdo->prepare("
            SELECT 
                player_id,
                DATE_FORMAT(season, '%Y-%m') as month,
                ranking,
                points
            FROM leaderboard
            WHERE player_id IN ($placeholders) AND YEAR(season) = ?
            ORDER BY season, ranking
        ");

        $params = array_merge($player_ids, [$season]);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Render comparison table HTML
     */
    public function renderComparisonTable($players) {
        $html = '<table class="player-comparison-table">';
        $html .= '<thead><tr>';
        $html .= '<th>Statistic</th>';
        foreach ($players as $player) {
            $html .= '<th>' . htmlspecialchars($player['name']) . '</th>';
        }
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        // Stats to compare
        $stats = [
            ['label' => 'Ranking', 'key' => 'ranking'],
            ['label' => 'Points', 'key' => 'points'],
            ['label' => 'Wins', 'key' => 'wins'],
            ['label' => 'Win Rate', 'key' => 'win_rate', 'format' => 'percent'],
            ['label' => 'Tournaments', 'key' => 'tournament_count'],
            ['label' => 'Total Matches', 'key' => 'total_matches'],
        ];

        foreach ($stats as $stat) {
            $html .= '<tr>';
            $html .= '<td class="stat-label">' . htmlspecialchars($stat['label']) . '</td>';
            
            foreach ($players as $player) {
                $value = $player[$stat['key']] ?? 'N/A';
                
                if ($stat['format'] === 'percent') {
                    $value = $value . '%';
                }
                
                $html .= '<td class="stat-value">' . htmlspecialchars($value) . '</td>';
            }
            
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Find most improved player
     */
    public function findMostImproved($player_ids, $season = null) {
        if (!is_array($player_ids) || empty($player_ids)) {
            return null;
        }

        $season = $season ?? date('Y');
        $placeholders = implode(',', array_fill(0, count($player_ids), '?'));

        // Compare current month ranking to last month
        $stmt = $this->pdo->prepare("
            SELECT 
                player_id,
                SUM(CASE WHEN ranking <= 10 THEN 1 ELSE 0 END) as top_10_count,
                MAX(ranking) as worst_ranking,
                MIN(ranking) as best_ranking
            FROM leaderboard
            WHERE player_id IN ($placeholders) AND YEAR(season) = ?
            GROUP BY player_id
            ORDER BY best_ranking ASC
        ");

        $params = array_merge($player_ids, [$season]);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

?>
