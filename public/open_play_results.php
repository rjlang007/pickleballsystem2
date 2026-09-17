<?php
// ============================================================
//  FILE: public/open_play_results.php
//  Final standings for a completed (or in-progress) Open Play
//  event, with a podium for 1st/2nd/3rd and a "Download as
//  Image" button (client-side html2canvas, same CDN-loading
//  pattern already used elsewhere in this app for optional
//  enhancements).
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../tournament/open_play_engine.php';

requireLogin();

$tid    = (int)($_GET['tournament_id'] ?? 0);
$engine = new OpenPlayEngine();
$event  = $tid ? $engine->getEvent($tid) : null;
if (!$event) { redirect('public/open_play.php'); }

$standings = $engine->computeLeaderboard($tid);
$ties      = $engine->detectPodiumTies($standings);

$pageTitle = $event['name'] . ' — Results';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;">
  <div class="card" id="resultsCard">
    <div class="card-header" style="text-align:center;">
      <div style="color:var(--muted);text-transform:uppercase;letter-spacing:2px;font-size:12px;">Padol Pickleball Court — Open Play</div>
      <h1 style="margin:6px 0;">🏆 <?= clean($event['name']) ?></h1>
      <p style="color:var(--muted);"><?= $event['status'] === 'completed' ? 'Final Results' : 'Standings So Far' ?></p>
    </div>

    <?php if (!empty($ties)): ?>
      <div class="alert alert-warning" style="margin:12px 0;">
        ⚠️ There's a tie in the top 3 — a tiebreaker game may be needed before final placements are official.
        Ask staff to run one from the Open Play control console.
      </div>
    <?php endif; ?>

    <?php if (count($standings) >= 1): ?>
    <div style="display:flex;justify-content:center;align-items:flex-end;gap:16px;margin:28px 0;flex-wrap:wrap;">
      <?php
        $podiumOrder = [1 => 1, 0 => 0, 2 => 2]; // render 2nd, 1st, 3rd left-to-right
        $heights     = [0 => 140, 1 => 100, 2 => 80];
        $medals      = [0 => '🥇', 1 => '🥈', 2 => '🥉'];
      ?>
      <?php foreach ([1, 0, 2] as $i): if (!isset($standings[$i])) continue; $s = $standings[$i]; ?>
        <div style="text-align:center;width:120px;">
          <div style="font-size:32px;"><?= $medals[$i] ?></div>
          <div style="font-weight:800;font-size:14px;margin:4px 0;"><?= clean($s['display_name'] ?? $s['full_name'] ?? 'Player') ?></div>
          <div style="color:var(--muted);font-size:12px;"><?= (int)$s['wins'] ?>W - <?= (int)$s['losses'] ?>L</div>
          <div style="background:var(--surface2);border:1px solid var(--border);border-radius:8px 8px 0 0;
                      height:<?= $heights[$i] ?>px;display:flex;align-items:flex-start;justify-content:center;
                      padding-top:8px;margin-top:8px;font-family:'Bebas Neue',sans-serif;font-size:28px;color:var(--accent);">
            <?= $i + 1 ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <table class="table">
      <thead><tr><th>#</th><th>Player</th><th>Skill</th><th>W</th><th>L</th><th>Win%</th><th>Diff</th></tr></thead>
      <tbody>
      <?php foreach ($standings as $i => $s): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= clean($s['display_name'] ?? $s['full_name'] ?? 'Player') ?></td>
          <td><span class="badge badge-info"><?= ucfirst($s['skill_level']) ?></span></td>
          <td><?= (int)$s['wins'] ?></td>
          <td><?= (int)$s['losses'] ?></td>
          <td><?= $s['win_pct'] ?>%</td>
          <td><?= $s['point_diff'] > 0 ? '+' : '' ?><?= $s['point_diff'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($standings)): ?>
        <tr><td colspan="7" class="text-muted">No games recorded yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div style="text-align:center;margin-top:16px;">
    <button class="btn btn-primary" id="downloadBtn">⬇️ Download as Image</button>
    <a class="btn" href="<?= APP_URL ?>/public/open_play.php">← Back to Open Play</a>
  </div>
</div>

<script src="https://unpkg.com/html2canvas@1.4.1/dist/html2canvas.min.js" nonce="<?= getCspNonce() ?>"></script>
<script nonce="<?= getCspNonce() ?>">
document.getElementById('downloadBtn').addEventListener('click', function () {
    const btn = this;
    btn.disabled = true;
    btn.textContent = 'Rendering…';
    html2canvas(document.getElementById('resultsCard'), {
        backgroundColor: getComputedStyle(document.body).getPropertyValue('--bg') || '#0a1520',
        scale: 2,
    }).then(canvas => {
        const link = document.createElement('a');
        link.download = <?= json_encode(preg_replace('/[^a-z0-9]+/i', '_', $event['name'])) ?> + '_results.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
        btn.disabled = false;
        btn.textContent = '⬇️ Download as Image';
    }).catch(() => {
        btn.disabled = false;
        btn.textContent = '⬇️ Download as Image';
        alert('Could not render the image — try a screenshot instead.');
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
