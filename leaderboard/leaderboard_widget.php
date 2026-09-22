<?php
// ============================================================
//  FILE: leaderboard/leaderboard_widget.php
//  Compact "Your Rank" + top-10 widget for the player dashboard.
//
//  Sourced from LeaderboardEngine::getOpenPlayLeaderboard() /
//  getOpenPlayPlayerStanding() — Open Play sessions only (1st = 3
//  pts, 2nd = 2, 3rd = 1, else 0), the same numbers shown on
//  public/leaderboard.php and admin/leaderboard_admin.php.
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/leaderboard_engine.php';

$engine  = new LeaderboardEngine();
$season  = (int) date('Y');

$lbData  = $engine->getOpenPlayLeaderboard($season, 10, 0);
$top10   = $lbData['players'] ?? [];

// Current player's own standing (if logged in) — may be outside
// the top 10 shown below, which is why it's fetched separately.
$myStanding = null;
if (!empty($_SESSION['user_id'])) {
    $uid        = (int) $_SESSION['user_id'];
    $myStanding = $engine->getOpenPlayPlayerStanding($uid, $season);
}
?>
<div class="lb-widget card">
    <div class="lb-widget__header">
        <span class="lb-widget__title">🏆 <?= $season ?> Open Play Leaderboard</span>
        <a href="<?= APP_URL ?>/public/leaderboard.php" class="lb-widget__viewall">View All</a>
    </div>

    <?php if ($myStanding): ?>
    <div class="lb-widget__myrank">
        <span class="lb-widget__mylabel">Your Rank</span>
        <span class="lb-widget__mybadge">
            #<?= (int) $myStanding['rank'] ?><?= !empty($myStanding['tied']) ? ' (tied)' : '' ?>
            of <?= (int) $myStanding['total_players'] ?>
        </span>
        <span class="lb-widget__mypoints">
            <?= number_format((int) $myStanding['total_points']) ?> pts
        </span>
    </div>
    <?php endif; ?>

    <?php if (empty($top10)): ?>
    <div class="lb-widget__empty">
        🏓 No Open Play rankings yet for <?= $season ?> — be the first on the board.
    </div>
    <?php else: ?>
    <ol class="lb-widget__list">
        <?php foreach ($top10 as $player):
            $place      = (int) $player['rank'];
            $medals     = ['🥇','🥈','🥉'];
            $medalPos   = $place <= 3 ? $medals[$place - 1] : "#$place";
            $medalClass = match($place) { 1 => 'gold', 2 => 'silver', 3 => 'bronze', default => '' };
            $name       = htmlspecialchars($player['name'] ?? '?');
            $isMe       = !empty($_SESSION['user_id']) && (int)$player['player_id'] === (int)$_SESSION['user_id'];
        ?>
        <li class="lb-widget__item <?= $medalClass ?> <?= $isMe ? 'is-me' : '' ?>">
            <span class="lb-widget__pos"><?= $medalPos ?><?= !empty($player['tied']) ? ' <sup title="Tied">*</sup>' : '' ?></span>
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

    <a href="<?= APP_URL ?>/public/open_play.php" class="lb-widget__cta">
        ⚡ Join Open Play
    </a>
</div>
