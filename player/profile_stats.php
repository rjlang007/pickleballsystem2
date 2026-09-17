<?php
// ============================================================
//  FILE: player/profile_stats.php
//  Player statistics and achievement profile.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../leaderboard/leaderboard_engine.php';
require_once __DIR__ . '/../leaderboard/achievement_system.php';

requireLogin();

$playerId = isset($_GET['player_id']) ? (int) $_GET['player_id'] : (int) $_SESSION['user_id'];
$engine   = new LeaderboardEngine();
$stats    = $engine->getPlayerStats($playerId);

$pageTitle = 'Player Profile';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;">
  <div class="card">
    <div class="card-header">
      <h1>Player Profile</h1>
      <p style="color:var(--color-text-2);">Career and season statistics for this player.</p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;">
      <div class="card card-gold">
        <h3>Current Rank</h3>
        <p style="font-size:2.5rem;margin:0;"><?= $stats['aggregate']['rank'] ?: '—' ?></p>
        <p style="color:var(--color-text-2);">Season points: <?= (int)$stats['aggregate']['total_points'] ?></p>
      </div>
      <div class="card">
        <h3>Season Totals</h3>
        <ul style="list-style:none;padding:0;margin:0;"> 
          <li>Wins: <?= (int)$stats['aggregate']['total_wins'] ?></li>
          <li>Losses: <?= (int)$stats['aggregate']['total_losses'] ?></li>
          <li>Tournaments: <?= (int)$stats['aggregate']['total_tournaments'] ?></li>
          <li>Win Streak: <?= (int)$stats['win_streak'] ?></li>
        </ul>
      </div>
      <div class="card">
        <h3>Achievements</h3>
        <?php if (empty($stats['achievements'])): ?>
          <p style="color:var(--color-text-2);">No achievements earned yet.</p>
        <?php else: ?>
          <ul style="list-style:none;padding:0;margin:0;display:grid;gap:10px;">
            <?php foreach ($stats['achievements'] as $achievement): ?>
              <li class="badge badge-secondary" style="display:flex;align-items:center;gap:8px;">
                <?= clean($achievement['icon'] ?? '🏅') ?>
                <strong><?= clean($achievement['name'] ?? $achievement['achievement_type']) ?></strong>
                <span style="color:var(--color-text-3);font-size:.85rem;"><?= clean(date('M j, Y', strtotime($achievement['achieved_at']))) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <div class="mt-lg card">
      <h3>Recent Tournaments</h3>
      <?php if (empty($stats['history'])): ?>
        <p style="color:var(--color-text-2);">No completed tournament history found.</p>
      <?php else: ?>
        <table class="table" style="width:100%;border-collapse:collapse;">
          <thead>
            <tr>
              <th>Tournament</th>
              <th>Placement</th>
              <th>Points</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($stats['history'] as $row): ?>
              <tr>
                <td><?= clean($row['tournament_name']) ?></td>
                <td><?= (int)$row['placement'] ?></td>
                <td><?= (int)$row['points'] ?></td>
                <td><?= clean(date('M j, Y', strtotime($row['start_date'] ?? $row['recorded_at'] ?? 'now'))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
