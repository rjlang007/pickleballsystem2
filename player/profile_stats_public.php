<?php
/**
 * Public: Player Profile Stats
 * Path: player/profile_stats_public.php
 * Public view of player profile and statistics
 */

require_once(__DIR__ . '/../config/app.php');
require_once(__DIR__ . '/../config/db.php');
require_once(__DIR__ . '/../config/session.php');

$player_id = $_GET['id'] ?? $_GET['player_id'] ?? null;

if (!$player_id || !is_numeric($player_id)) {
    header('Location: /public/leaderboard.php');
    exit;
}

// Get player info
$stmt = $pdo->prepare("
    SELECT id, name, avatar_url, email, created_at 
    FROM users 
    WHERE id = ?
");
$stmt->execute([$player_id]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$player) {
    header('Location: /public/leaderboard.php');
    exit;
}

// Get player stats
$stmt = $pdo->prepare("
    SELECT 
        l.ranking,
        l.points,
        (SELECT COUNT(*) FROM tournament_matches WHERE winner_id = ? AND YEAR(created_at) = YEAR(CURDATE())) as wins,
        (SELECT COUNT(*) FROM tournament_matches WHERE 
            (winner_id = ? OR loser_id = ?) AND YEAR(created_at) = YEAR(CURDATE())) as total_matches,
        (SELECT COUNT(DISTINCT tournament_id) FROM tournament_players WHERE player_id = ? AND YEAR(created_at) = YEAR(CURDATE())) as tournament_count
    FROM leaderboard l
    WHERE l.player_id = ? AND YEAR(l.season) = YEAR(CURDATE())
");
$stmt->execute([$player_id, $player_id, $player_id, $player_id, $player_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC) ?? [];

// Calculate win rate
$win_rate = isset($stats['total_matches']) && $stats['total_matches'] > 0 
    ? round(($stats['wins'] / $stats['total_matches']) * 100, 1)
    : 0;

// Get recent tournaments
$stmt = $pdo->prepare("
    SELECT 
        t.id,
        t.name,
        t.bracket_type,
        ts.placement,
        ts.points_earned,
        t.created_at
    FROM tournament_scores ts
    JOIN tournaments t ON ts.tournament_id = t.id
    WHERE ts.player_id = ?
    ORDER BY t.created_at DESC
    LIMIT 5
");
$stmt->execute([$player_id]);
$recent_tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get achievements
$stmt = $pdo->prepare("
    SELECT type, unlocked_at 
    FROM player_achievements 
    WHERE player_id = ?
    ORDER BY unlocked_at DESC
");
$stmt->execute([$player_id]);
$achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($player['name']); ?> - Player Profile</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/profile_stats.css">
    <link rel="stylesheet" href="/assets/css/profile_stats_mobile.css">
    <style nonce="<?= getCspNonce() ?>">
        .profile-container {
            max-width: 900px;
            margin: 20px auto;
            padding: 20px;
        }

        .profile-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin: 0 auto 15px;
            border: 4px solid #fff;
            object-fit: cover;
        }

        .profile-name {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .profile-rank {
            font-size: 18px;
            opacity: 0.9;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: #fff;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
            border-top: 4px solid #3498db;
        }

        .stat-card.wins {
            border-top-color: #27ae60;
        }

        .stat-card.tournaments {
            border-top-color: #e74c3c;
        }

        .stat-card.win-rate {
            border-top-color: #f39c12;
        }

        .stat-label {
            font-size: 12px;
            color: #999;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }

        .section {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        .tournament-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .tournament-item {
            padding: 12px;
            background: #f9f9f9;
            border-radius: 6px;
            border-left: 4px solid #3498db;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .tournament-name {
            font-weight: 500;
            color: #333;
        }

        .tournament-placement {
            background: #3498db;
            color: #fff;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .tournament-placement.first {
            background: #f39c12;
        }

        .achievements-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .achievement-badge {
            background: #f0f0f0;
            padding: 10px;
            border-radius: 6px;
            text-align: center;
            flex: 0 0 auto;
            font-size: 12px;
            font-weight: 600;
        }

        .achievement-emoji {
            font-size: 24px;
            display: block;
            margin-bottom: 5px;
        }

        .no-data {
            color: #999;
            text-align: center;
            padding: 20px;
            font-size: 14px;
        }

        @media (max-width: 600px) {
            .profile-container {
                padding: 12px;
            }

            .profile-hero {
                padding: 20px;
            }

            .profile-name {
                font-size: 22px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .stat-value {
                font-size: 20px;
            }

            .section {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <?php require_once(__DIR__ . '/../includes/header.php'); ?>

    <div class="profile-container">
        <!-- Hero Section -->
        <div class="profile-hero">
            <?php if ($player['avatar_url']): ?>
                <img src="<?php echo htmlspecialchars($player['avatar_url']); ?>" alt="" class="profile-avatar">
            <?php else: ?>
                <div class="profile-avatar" style="background: rgba(255,255,255,0.3); display: flex; align-items: center; justify-content: center;">👤</div>
            <?php endif; ?>
            <div class="profile-name"><?php echo htmlspecialchars($player['name']); ?></div>
            <div class="profile-rank">
                <?php if ($stats['ranking']): ?>
                    Rank #<?php echo $stats['ranking']; ?>
                <?php else: ?>
                    Unranked
                <?php endif; ?>
            </div>
        </div>

        <!-- Statistics Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Points</div>
                <div class="stat-value"><?php echo $stats['points'] ?? 0; ?></div>
            </div>
            <div class="stat-card wins">
                <div class="stat-label">Wins</div>
                <div class="stat-value"><?php echo $stats['wins'] ?? 0; ?></div>
            </div>
            <div class="stat-card tournaments">
                <div class="stat-label">Tournaments</div>
                <div class="stat-value"><?php echo $stats['tournament_count'] ?? 0; ?></div>
            </div>
            <div class="stat-card win-rate">
                <div class="stat-label">Win Rate</div>
                <div class="stat-value"><?php echo $win_rate; ?>%</div>
            </div>
        </div>

        <!-- Recent Tournaments -->
        <div class="section">
            <div class="section-title">🏆 Recent Tournaments</div>
            <?php if (!empty($recent_tournaments)): ?>
                <div class="tournament-list">
                    <?php foreach ($recent_tournaments as $tournament): ?>
                        <div class="tournament-item">
                            <div>
                                <div class="tournament-name">
                                    <?php echo htmlspecialchars($tournament['name']); ?>
                                </div>
                                <div style="font-size: 12px; color: #999;">
                                    <?php echo date('M d, Y', strtotime($tournament['created_at'])); ?>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div class="tournament-placement <?php echo $tournament['placement'] === 1 ? 'first' : ''; ?>">
                                    Place #<?php echo $tournament['placement']; ?>
                                </div>
                                <div style="font-size: 12px; color: #666; margin-top: 5px;">
                                    +<?php echo $tournament['points_earned']; ?> pts
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-data">No tournament history yet</div>
            <?php endif; ?>
        </div>

        <!-- Achievements -->
        <?php if (!empty($achievements)): ?>
            <div class="section">
                <div class="section-title">🎖️ Achievements</div>
                <div class="achievements-container">
                    <?php foreach ($achievements as $achievement): ?>
                        <div class="achievement-badge">
                            <div class="achievement-emoji">
                                <?php
                                    $emojis = [
                                        'first_win' => '🎯',
                                        'five_wins' => '⭐',
                                        'ten_wins' => '🏅',
                                        'top_10' => '👑',
                                        'win_streak_5' => '🔥',
                                        'monthly_champion' => '🏆'
                                    ];
                                    echo $emojis[$achievement['type']] ?? '⭐';
                                ?>
                            </div>
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $achievement['type']))); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php require_once(__DIR__ . '/../includes/footer.php'); ?>
</body>
</html>
