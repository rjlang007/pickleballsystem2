<?php
// ============================================================
//  FILE: includes/rating.php
//
//  Skill rating system for players.
//
//  Uses Elo-like rating system for pickleball skill tracking.
// ============================================================
require_once __DIR__ . '/../config/db.php';

class RatingSystem {
    private const K_FACTOR = 32; // Rating change factor
    private const DEFAULT_RATING = 1200;

    public static function getPlayerRating(int $userId): float {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT rating FROM falcon.player_ratings
            WHERE user_id = ? ORDER BY updated_at DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        $rating = $stmt->fetchColumn();

        return $rating ? (float)$rating : self::DEFAULT_RATING;
    }

    public static function updateRating(int $winnerId, int $loserId, float $score = 1.0): void {
        $db = getDB();

        $winnerRating = self::getPlayerRating($winnerId);
        $loserRating = self::getPlayerRating($loserId);

        // Calculate expected scores
        $expectedWinner = 1 / (1 + pow(10, ($loserRating - $winnerRating) / 400));
        $expectedLoser = 1 - $expectedWinner;

        // Calculate new ratings
        $newWinnerRating = $winnerRating + self::K_FACTOR * ($score - $expectedWinner);
        $newLoserRating = $loserRating + self::K_FACTOR * ((1 - $score) - $expectedLoser);

        // Store new ratings
        $stmt = $db->prepare("
            INSERT INTO falcon.player_ratings (user_id, rating, updated_at)
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$winnerId, $newWinnerRating]);
        $stmt->execute([$loserId, $newLoserRating]);

        // Log the match
        $matchStmt = $db->prepare("
            INSERT INTO falcon.rating_matches
                (winner_id, loser_id, winner_rating_before, loser_rating_before,
                 winner_rating_after, loser_rating_after, played_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $matchStmt->execute([
            $winnerId, $loserId, $winnerRating, $loserRating,
            $newWinnerRating, $newLoserRating
        ]);
    }

    public static function getTopPlayers(int $limit = 10): array {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT u.username, u.full_name, pr.rating,
                   ROW_NUMBER() OVER (ORDER BY pr.rating DESC) as rank
            FROM falcon.player_ratings pr
            JOIN falcon.users u ON u.id = pr.user_id
            WHERE pr.updated_at = (
                SELECT MAX(updated_at) FROM falcon.player_ratings pr2
                WHERE pr2.user_id = pr.user_id
            )
            ORDER BY pr.rating DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}