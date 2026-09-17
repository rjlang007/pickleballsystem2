<?php
// ============================================================
//  FILE: leaderboard/leaderboard_widget.php
//  Compact leaderboard widget for dashboard/sidebar
//  Fixed: getLeaderboard() returns ['players'=>[...], 'total'=>...]
//         getPlayerRank() now exists in engine
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/leaderboard_engine.php';

$engine  = new LeaderboardEngine();
$season  = (int) date('Y');

// getLeaderboard() returns an array with a 'players' key — not a flat list
$lbData  = $engine->getLeaderboard($season, 10, 0);
$top10   = $lbData['players'] ?? [];

// Current player rank (if logged in)
$myRank  = null;
$myStats = null;
if (!empty($_SESSION['user_id'])) {
    $uid     = (int)$_SESSION['user_id'];
    $myRank  = $engine->getPlayerRank($uid, $season);
    $myStats = $engine->getPlayerStats($uid, $season);
}
?>
<div class="lb-widget card">
    <div class="lb-widget__header">
        <span class="lb-widget__title">🏆 <?= $season ?> Leaderboard</span>
        <a href="<?= APP_URL ?>/public/leaderboard.php" class="lb-widget__viewall">View All</a>
    </div>

    <?php if ($myRank): ?>
    <div class="lb-widget__myrank">
        <span class="lb-widget__mylabel">Your Rank</span>
        <span class="lb-widget__mybadge">#<?= $myRank ?></span>
        <span class="lb-widget__mypoints">
            <?= number_format((int)($myStats['aggregate']['total_points'] ?? 0)) ?> pts
        </span>
    </div>
    <?php endif; ?>

    <?php if (empty($top10)): ?>
    <div style="text-align:center;padding:20px 10px;color:var(--muted);font-size:13px;">
        No rankings yet for <?= $season ?>.
    </div>
    <?php else: ?>
    <ol class="lb-widget__list">
        <?php foreach ($top10 as $i => $player):
            $place     = $i + 1;
            $medals    = ['🥇','🥈','🥉'];
            $medalPos  = $place <= 3 ? $medals[$place - 1] : "#$place";
            $medalClass = match($place) { 1 => 'gold', 2 => 'silver', 3 => 'bronze', default => '' };
            // display_name is resolved server-side via SQL COALESCE in the engine
            $name  = htmlspecialchars($player['display_name'] ?: $player['full_name'] ?: $player['username'] ?? '?');
            $isMe  = !empty($_SESSION['user_id']) && (int)$player['player_id'] === (int)$_SESSION['user_id'];
        ?>
        <li class="lb-widget__item <?= $medalClass ?> <?= $isMe ? 'is-me' : '' ?>">
            <span class="lb-widget__pos"><?= $medalPos ?></span>
            <?php if (!empty($player['avatar_url'])): ?>
                <img src="<?= htmlspecialchars($player['avatar_url']) ?>"
                     alt="<?= $name ?>" class="lb-widget__avatar">
            <?php else: ?>
                <span class="lb-widget__avatar lb-widget__avatar--initials">
                    <?= strtoupper(substr($name, 0, 1)) ?>
                </span>
            <?php endif; ?>
            <span class="lb-widget__name"><?= $name ?></span>
            <span class="lb-widget__pts"><?= number_format((int)$player['total_points']) ?></span>
        </li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>

    <a href="<?= APP_URL ?>/public/tournaments.php" class="lb-widget__cta">
        ⚡ Join a Tournament
    </a>
</div>