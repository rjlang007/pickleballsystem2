<?php
// ============================================================
//  FILE: admin/achievements_admin.php
//  Admin page for achievement configuration and manual unlocks.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../leaderboard/achievement_manager.php';

requireAdmin();
$pageTitle = 'Achievement Management';
require_once __DIR__ . '/../includes/header.php';

$allAchievements = AchievementManager::getAllAchievements();
$players = fetchPlayerList();

function fetchPlayerList(): array
{
    try {
        $db = getDB();
        $stmt = $db->query(
            "SELECT id, COALESCE(display_name, username, full_name) AS display_name
               FROM falcon.users
              WHERE role = 'player' AND is_active = TRUE
              ORDER BY display_name ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
}
?>

<div class="container-md" style="padding:32px 0;">
  <div class="card">
    <div class="card-header">
      <h1>Achievement Management</h1>
      <p style="color:var(--color-text-2);">Review configured achievements and award them manually to players.</p>
    </div>

    <div class="achievement-grid">
      <?php foreach ($allAchievements as $achievement): ?>
        <div class="achievement-card">
          <div class="achievement-header">
            <div class="achievement-icon"><?= clean($achievement['icon'] ?? '🏅') ?></div>
            <div>
              <h2 class="achievement-title"><?= clean($achievement['title']) ?></h2>
              <p class="achievement-desc"><?= clean($achievement['description']) ?></p>
            </div>
          </div>
          <div class="achievement-meta">
            <span>Criteria: <?= clean($achievement['criteria']) ?></span>
            <span>Status: <?= clean($achievement['status'] ?? 'active') ?></span>
          </div>
          <form method="POST" action="<?= APP_URL ?>/api/achievements_admin.php" style="margin-top:16px;">
            <input type="hidden" name="action" value="award" />
            <input type="hidden" name="achievement_key" value="<?= clean($achievement['key']) ?>" />
            <?= csrfField() ?>
            <label class="form-label">Player</label>
            <select name="player_id" class="form-input">
              <?php foreach ($players as $player): ?>
                <option value="<?= (int)$player['id'] ?>"><?= clean($player['display_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary" style="margin-top:10px;">Award Achievement</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
