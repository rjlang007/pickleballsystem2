<?php
// ============================================================
//  FILE: player/my_ranking.php
//  Personal rank tracker page.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../leaderboard/leaderboard_engine.php';

requireLogin();

$playerId = (int) $_SESSION['user_id'];
$engine   = new LeaderboardEngine();
$stats    = $engine->getPlayerStats($playerId);

$pageTitle = 'My Ranking';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;">
  <div class="card">
    <div class="card-header">
      <h1>My Ranking</h1>
      <p style="color:var(--color-text-2);">Track your current season rank and recent performance.</p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;">
      <div class="card card-gold">
        <h2 style="margin:0;"><?= $stats['aggregate']['rank'] ?: '—' ?></h2>
        <p style="margin:8px 0 0;color:var(--color-text-2);">Season rank</p>
      </div>
      <div class="card">
        <h2><?= (int)$stats['aggregate']['total_points'] ?></h2>
        <p style="margin:8px 0 0;color:var(--color-text-2);">Season points</p>
      </div>
      <div class="card">
        <h2><?= (int)$stats['win_streak'] ?></h2>
        <p style="margin:8px 0 0;color:var(--color-text-2);">Active win streak</p>
      </div>
    </div>

    <div class="mt-lg card">
      <h3>Rank History</h3>
      <p style="color:var(--color-text-2);">A snapshot of your performance this season.</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;">
        <div class="card">
          <p style="margin:.5rem 0 0;color:var(--color-text-2);">Tournaments Played</p>
          <p style="font-size:1.4rem;font-weight:700;margin:.25rem 0 0;"><?= (int)$stats['aggregate']['total_tournaments'] ?></p>
        </div>
        <div class="card">
          <p style="margin:.5rem 0 0;color:var(--color-text-2);">Wins</p>
          <p style="font-size:1.4rem;font-weight:700;margin:.25rem 0 0;"><?= (int)$stats['aggregate']['total_wins'] ?></p>
        </div>
        <div class="card">
          <p style="margin:.5rem 0 0;color:var(--color-text-2);">Losses</p>
          <p style="font-size:1.4rem;font-weight:700;margin:.25rem 0 0;"><?= (int)$stats['aggregate']['total_losses'] ?></p>
        </div>
      </div>
    </div>
  </div>
</div>
