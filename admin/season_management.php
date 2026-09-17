<?php
// ============================================================
//  FILE: admin/season_management.php
//  Admin season management and reset controls.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/helpers.php';

requireAdmin();
$pageTitle = 'Season Management';
require_once __DIR__ . '/../includes/header.php';

$currentSeason = getCurrentSeason();
$activeSeason = $currentSeason['name'] ?? 'Season ' . date('Y');
$seasonStart = $currentSeason['start_date'] ?? date('Y-01-01');
$seasonEnd = $currentSeason['end_date'] ?? date('Y-12-31');

function getCurrentSeason(): array
{
    try {
        $db = getDB();
        $stmt = $db->query(
            "SELECT name, start_date, end_date
               FROM falcon.seasons
              WHERE archived = FALSE
              ORDER BY start_date DESC
              LIMIT 1"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    } catch (Throwable) {
        // Fallback to current calendar year if seasons are not configured.
    }

    return [
        'name'       => 'Season ' . date('Y'),
        'start_date' => date('Y-01-01'),
        'end_date'   => date('Y-12-31'),
    ];
}
?>

<div class="container-md" style="padding:32px 0;">
  <div class="card">
    <div class="card-header">
      <h1>Season Management</h1>
      <p style="color:var(--color-text-2);">Archive completed seasons or reset leaderboard standings for a fresh start.</p>
    </div>

    <div class="card" style="margin-bottom:20px;">
      <h3>Active season</h3>
      <dl>
        <dt>Season name</dt>
        <dd><?= clean($activeSeason) ?></dd>
        <dt>Start date</dt>
        <dd><?= clean($seasonStart) ?></dd>
        <dt>End date</dt>
        <dd><?= clean($seasonEnd) ?></dd>
      </dl>
    </div>

    <form id="season-actions" method="POST" action="<?= APP_URL ?>/api/season_management_api.php">
      <input type="hidden" name="action" id="season-action-input" value="" />
      <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary" onclick="submitSeasonAction('reset')">Reset leaderboard</button>
        <button type="button" class="btn btn-secondary" onclick="submitSeasonAction('archive')">Archive season</button>
        <button type="button" class="btn btn-tertiary" onclick="submitSeasonAction('recompute')">Recompute ranks</button>
      </div>
    </form>
  </div>
</div>

<script nonce="<?= getCspNonce() ?>">
function submitSeasonAction(action) {
  document.getElementById('season-action-input').value = action;
  document.getElementById('season-actions').submit();
}
</script>
