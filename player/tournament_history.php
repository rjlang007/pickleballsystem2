<?php
// ============================================================
//  FILE: player/tournament_history.php
//  Player tournament history page.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

requireLogin();

$playerId = (int) $_SESSION['user_id'];
$db = getDB();

$stmt = $db->prepare(
    "SELECT t.id, t.name, t.bracket_type, t.status, t.start_date, t.end_date,
            ts.placement, ts.points, ts.recorded_at
       FROM falcon.tournament_scores ts
       JOIN falcon.tournaments t ON t.id = ts.tournament_id
      WHERE ts.player_id = :pid
      ORDER BY t.start_date DESC NULLS LAST, ts.recorded_at DESC"
);
$stmt->execute([':pid' => $playerId]);
$history = $stmt->fetchAll();

$pageTitle = 'My Tournament History';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;">
  <div class="card">
    <div class="card-header">
      <h1>My Tournament History</h1>
      <p style="color:var(--color-text-2);">Review your completed tournaments, placements, and points earned.</p>
    </div>

    <?php if (empty($history)): ?>
      <div class="card" style="text-align:center;color:var(--color-text-2);padding:48px 24px;">
        <p>No tournament history found yet.</p>
      </div>
    <?php else: ?>
      <table class="table" style="width:100%;border-collapse:collapse;">
        <thead>
          <tr>
            <th>Tournament</th>
            <th>Status</th>
            <th>Placement</th>
            <th>Points</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $row): ?>
            <tr>
              <td><a href="<?= APP_URL ?>/public/tournament_details.php?id=<?= (int)$row['id'] ?>"><?= clean($row['name']) ?></a></td>
              <td><?= clean(ucfirst($row['status'])) ?></td>
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
