<?php
// ============================================================
//  FILE: admin/leaderboard_settings.php
//  Admin page for leaderboard point configuration.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/tournament_config.php';
require_once __DIR__ . '/../includes/helpers.php';

requireAdmin();

$config = require __DIR__ . '/../config/tournament_config.php';
$pageTitle = 'Leaderboard Settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;">
  <div class="card">
    <div class="card-header">
      <h1>Leaderboard Settings</h1>
      <p style="color:var(--color-text-2);">Update point distribution and season scoring settings.</p>
    </div>

    <form id="leaderboard-settings-form" method="POST" action="<?= APP_URL ?>/api/leaderboard_admin_api.php?action=save_settings">
      <input type="hidden" name="action" value="save_settings" />
      <?= csrfField() ?>
      <div style="display:grid;gap:20px;">
        <div class="card">
          <h3>Point distribution</h3>
          <p style="color:var(--color-text-2);">Enter comma-separated point values for placement positions.</p>
          <label class="form-label">Points per placement</label>
          <input type="text" name="point_distribution" value="<?= clean(implode(',', $config['point_distribution'] ?? [100, 75, 50, 30, 20])) ?>" class="form-input" />
          <p style="color:var(--color-text-3);font-size:.9rem;margin-top:8px;">Example: 100,75,50,30,20</p>
        </div>

        <div class="card">
          <h3>Participation & bonus</h3>
          <label class="form-label">Participation points</label>
          <input type="number" name="participation_points" value="<?= (int)($config['participation_points'] ?? 5) ?>" class="form-input" />
          <label class="form-label" style="margin-top:16px;">Swiss default rounds</label>
          <input type="number" name="swiss_rounds" value="<?= (int)($config['swiss_default_rounds'] ?? 4) ?>" class="form-input" />
        </div>

        <div class="card">
          <h3>Season settings</h3>
          <p style="color:var(--color-text-2);">These values are read from config and control leaderboard behavior.</p>
          <ul style="padding-left:20px;color:var(--color-text-2);">
            <li>Leaderboard page size: <?= (int)($config['leaderboard_page_size'] ?? 50) ?></li>
            <li>Leaderboard cache TTL: <?= (int)($config['leaderboard_cache_ttl'] ?? 300) ?> seconds</li>
            <li>Supported player counts: <?= implode(', ', $config['supported_player_counts'] ?? [4, 8, 16, 32]) ?></li>
          </ul>
        </div>
      </div>

      <div style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary">Save Settings</button>
        <button type="button" class="btn btn-secondary" onclick="window.location.href='<?= APP_URL ?>/admin/season_management.php'">Season Management</button>
      </div>
    </form>
  </div>
</div>

<script src="<?= APP_URL ?>/assets/js/admin_settings.js"></script>
