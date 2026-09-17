<?php
// ============================================================
//  FILE: public/tournament_details.php
//  Read-only overview of a single tournament: info, registered
//  players, and a link into the bracket. Linked from:
//    - tournament/tournament_engine.php (match notifications)
//    - admin/tournament_admin.php ("View" in the table)
//    - player/tournament_history.php (past tournament names)
//
//  Same TournamentEngine calls used by public/tournaments.php,
//  just scoped to one tournament instead of a filtered list.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../tournament/tournament_engine.php';

requireLogin();

$engine = new TournamentEngine();
$myId   = (int)$_SESSION['user_id'];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('error', 'Invalid tournament.');
    redirect('public/tournaments.php');
}

$tournament = $engine->getTournament($id);
if (!$tournament) {
    setFlash('error', 'Tournament not found.');
    redirect('public/tournaments.php');
}

$players    = $engine->getTournamentPlayers($id);
$isRegistered = in_array($myId, array_column($players, 'player_id'), true);

$statusBadge = [
    'draft'             => ['label' => 'Draft',             'class' => 'badge-muted'],
    'registration_open' => ['label' => 'Registration Open',  'class' => 'badge-success'],
    'in_progress'       => ['label' => 'In Progress',        'class' => 'badge-info'],
    'completed'         => ['label' => 'Completed',          'class' => 'badge-muted'],
    'cancelled'         => ['label' => 'Cancelled',          'class' => 'badge-danger'],
][$tournament['status']] ?? ['label' => ucfirst($tournament['status']), 'class' => 'badge-muted'];

$pageTitle = $tournament['name'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;">

  <div class="card" style="margin-bottom:16px;">
    <div class="card-header flex-between">
      <div>
        <h1 style="margin:0 0 6px;">🎯 <?= clean($tournament['name']) ?></h1>
        <span class="badge <?= $statusBadge['class'] ?>"><?= $statusBadge['label'] ?></span>
      </div>
      <?php if (in_array($tournament['status'], ['in_progress', 'completed'], true)): ?>
        <a href="<?= APP_URL ?>/public/bracket_viewer.php?id=<?= $id ?>" class="btn-outline btn-sm">View Bracket →</a>
      <?php endif; ?>
    </div>

    <?php if (!empty($tournament['description'])): ?>
      <p style="color:var(--muted);margin:12px 0 0;"><?= nl2br(clean($tournament['description'])) ?></p>
    <?php endif; ?>

    <div style="display:flex;flex-wrap:wrap;gap:24px;margin-top:16px;padding-top:16px;border-top:1px solid var(--border,rgba(255,255,255,0.08));">
      <div>
        <div style="color:var(--muted);font-size:12px;">Format</div>
        <div style="font-weight:600;"><?= clean(ucwords(str_replace('_', ' ', $tournament['bracket_type']))) ?></div>
      </div>
      <div>
        <div style="color:var(--muted);font-size:12px;">Players</div>
        <div style="font-weight:600;"><?= (int)$tournament['current_players'] ?> / <?= (int)$tournament['max_players'] ?></div>
      </div>
      <?php if (!empty($tournament['start_date'])): ?>
      <div>
        <div style="color:var(--muted);font-size:12px;">Starts</div>
        <div style="font-weight:600;"><?= date('M j, Y', strtotime($tournament['start_date'])) ?></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($tournament['created_by_name'])): ?>
      <div>
        <div style="color:var(--muted);font-size:12px;">Organized by</div>
        <div style="font-weight:600;"><?= clean($tournament['created_by_name']) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($tournament['status'] === 'registration_open'): ?>
      <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border,rgba(255,255,255,0.08));">
        <?php if ($isRegistered): ?>
          <span class="badge badge-success">✓ You're registered</span>
        <?php else: ?>
          <a href="<?= APP_URL ?>/public/tournaments.php" class="btn-primary btn-sm">Join from Tournaments →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header"><h2 style="margin:0;">Registered Players (<?= count($players) ?>)</h2></div>
    <?php if (empty($players)): ?>
      <p style="color:var(--muted);padding:16px 0;">No one has registered yet.</p>
    <?php else: ?>
      <div style="padding:4px 0;">
        <?php foreach ($players as $p): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border,rgba(255,255,255,0.06));">
            <div>
              <?= clean($p['display_name'] ?: $p['full_name'] ?: $p['username']) ?>
              <?php if ((int)$p['player_id'] === $myId): ?><span class="badge badge-info" style="margin-left:6px;">You</span><?php endif; ?>
            </div>
            <div style="color:var(--muted);font-size:12px;">
              <?= $p['seed'] ? 'Seed #' . (int)$p['seed'] : '' ?>
              <?php if (($p['status'] ?? '') !== 'registered'): ?>
                <span class="badge badge-muted" style="margin-left:6px;"><?= ucfirst($p['status']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
