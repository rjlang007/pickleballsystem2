<?php
// ============================================================
//  FILE: public/bracket_viewer.php
//  Read-only bracket display for a tournament. Linked from
//  admin/tournament_admin.php's "👁 View Bracket" button and
//  public/tournament_details.php once a tournament is underway.
//
//  Uses TournamentEngine::getBracket(), the same data source the
//  admin scoring tools use, grouped by round (and by bracket
//  section for double-elimination). No editing here -- score
//  correction stays in the admin/referee tools.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../tournament/tournament_engine.php';

requireLogin();

$engine = new TournamentEngine();

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

$matches = $engine->getBracket($id);

// Group matches by bracket_section, then by round, preserving order.
$sections = [];
foreach ($matches as $m) {
    $section = $m['bracket_section'] ?: 'main';
    $round   = (int)$m['bracket_round'];
    $sections[$section][$round][] = $m;
}

function bv_playerLabel(?string $display, ?string $full, ?string $username): string
{
    if ($username === null) return 'TBD';
    return $display ?: $full ?: $username;
}

$pageTitle = $tournament['name'] . ' — Bracket';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;max-width:1100px;">

  <div class="card" style="margin-bottom:16px;">
    <div class="card-header flex-between">
      <div>
        <h1 style="margin:0 0 6px;">🏆 <?= clean($tournament['name']) ?> — Bracket</h1>
        <p style="color:var(--muted);margin:0;">
          <?= clean(ucwords(str_replace('_', ' ', $tournament['bracket_type']))) ?> ·
          <?= (int)$tournament['current_players'] ?> players
        </p>
      </div>
      <a href="<?= APP_URL ?>/public/tournament_details.php?id=<?= $id ?>" class="btn-outline btn-sm">← Tournament Details</a>
    </div>
  </div>

  <?php if (empty($matches)): ?>
    <div class="card">
      <p style="color:var(--muted);padding:16px 0;text-align:center;">
        The bracket hasn't been generated yet — it appears once the tournament starts.
      </p>
    </div>
  <?php else: ?>
    <?php foreach ($sections as $sectionName => $rounds): ksort($rounds); ?>
      <div class="card" style="margin-bottom:16px;overflow-x:auto;">
        <?php if (count($sections) > 1): ?>
          <div class="card-header"><h2 style="margin:0;"><?= clean(ucwords(str_replace('_', ' ', $sectionName))) ?> Bracket</h2></div>
        <?php endif; ?>

        <div style="display:flex;gap:24px;padding:8px 0;min-width:max-content;">
          <?php foreach ($rounds as $roundNum => $roundMatches): ?>
            <div style="min-width:220px;">
              <div style="font-weight:600;color:var(--muted);font-size:13px;text-transform:uppercase;letter-spacing:0.03em;margin-bottom:10px;">
                Round <?= $roundNum ?>
              </div>
              <?php foreach ($roundMatches as $m):
                $p1 = bv_playerLabel($m['player1_display'], $m['player1_name'], $m['player1_username']);
                $p2 = bv_playerLabel($m['player2_display'], $m['player2_name'], $m['player2_username']);
                $p1Won = $m['winner_id'] !== null && (int)$m['winner_id'] === (int)$m['player1_id'];
                $p2Won = $m['winner_id'] !== null && (int)$m['winner_id'] === (int)$m['player2_id'];
              ?>
              <div style="border:1px solid var(--border,rgba(255,255,255,0.1));border-radius:10px;padding:10px 12px;margin-bottom:12px;background:rgba(255,255,255,0.02);">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;<?= $p1Won ? 'font-weight:700;' : '' ?>">
                  <span><?= clean($p1) ?></span>
                  <span style="color:var(--muted);"><?= $m['status'] !== 'pending' ? (int)$m['score_player1'] : '' ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;<?= $p2Won ? 'font-weight:700;' : '' ?>">
                  <span><?= clean($p2) ?></span>
                  <span style="color:var(--muted);"><?= $m['status'] !== 'pending' ? (int)$m['score_player2'] : '' ?></span>
                </div>
                <div style="margin-top:6px;">
                  <?php
                    $statusStyle = [
                      'pending'     => 'badge-muted',
                      'in_progress' => 'badge-info',
                      'completed'   => 'badge-success',
                    ][$m['status']] ?? 'badge-muted';
                  ?>
                  <span class="badge <?= $statusStyle ?>" style="font-size:10px;"><?= ucfirst(str_replace('_', ' ', $m['status'])) ?></span>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
