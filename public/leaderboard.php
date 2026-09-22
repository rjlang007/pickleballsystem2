<?php
// ============================================================
//  FILE: public/leaderboard.php
//  Season leaderboard — Open Play only. Every player's score is
//  the sum of points earned across every finalized Open Play
//  session this season (1st = 3, 2nd = 2, 3rd = 1, else 0 — see
//  config/tournament_config.php open_play_point_distribution).
//
//  Uses LeaderboardEngine::getOpenPlayLeaderboard(), the same
//  method admin/leaderboard_admin.php and the dashboard "Your
//  Rank" widget use, so every page always agrees on the same
//  numbers — this used to read the generic falcon.leaderboard
//  table (which also mixes in unrelated bracket-tournament
//  points on a different point scale); it no longer does.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../leaderboard/leaderboard_engine.php';

requireLogin();

$engine = new LeaderboardEngine();

// -- Season selector (defaults to current year) --------------
$currentYear = (int)date('Y');
$season      = (int)($_GET['season'] ?? $currentYear);
if ($season < 2000 || $season > $currentYear + 1) {
    $season = $currentYear;
}

// A small, reasonable range of seasons to offer in the picker --
// current year back to whenever the app might first have data.
$seasonOptions = range($currentYear, max(2024, $currentYear - 4));

$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$result  = $engine->getOpenPlayLeaderboard($season, $perPage, $offset);
$players = $result['players'] ?? [];
$total   = $result['total'] ?? 0;
$pages   = max(1, (int)ceil($total / $perPage));

$myId = (int)($_SESSION['user_id'] ?? 0);

$pageTitle = 'Leaderboard';
require_once __DIR__ . '/../includes/header.php';
?>
<style nonce="<?= getCspNonce() ?>">
    .op-lb-row--top1 { background: linear-gradient(90deg, rgba(224,161,0,0.10), transparent 70%); }
    .op-lb-row--top2 { background: linear-gradient(90deg, rgba(138,143,152,0.10), transparent 70%); }
    .op-lb-row--top3 { background: linear-gradient(90deg, rgba(181,101,29,0.10), transparent 70%); }
    .op-lb-tie {
        display: inline-block;
        margin-left: 6px;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .03em;
        text-transform: uppercase;
        color: var(--muted);
        border: 1px solid var(--border);
        border-radius: 999px;
        padding: 1px 6px;
        vertical-align: middle;
    }
</style>

<div class="container-md" style="padding:32px 0;">
  <div class="card">
    <div class="card-header">
      <h1 style="margin:0 0 6px;">🏆 Leaderboard</h1>
      <p style="color:var(--muted);margin:0;">
        Open Play season rankings — 1st = 3 pts · 2nd = 2 pts · 3rd = 1 pt, added up across every session.
      </p>
    </div>

    <form method="get" style="display:flex;gap:10px;align-items:center;margin:18px 0 22px;flex-wrap:wrap;">
      <label for="season-select" style="color:var(--muted);font-size:14px;">Season</label>
      <select id="season-select" name="season" onchange="this.form.submit()"
              style="background:var(--surface2);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:8px 12px;">
        <?php foreach ($seasonOptions as $yr): ?>
          <option value="<?= $yr ?>" <?= $yr === $season ? 'selected' : '' ?>><?= $yr ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <?php if (empty($players)): ?>
      <div style="text-align:center;padding:48px 16px;color:var(--muted);">
        No Open Play rankings yet for <?= $season ?>. Rankings appear once an Open Play session is finalized.
      </div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
          <thead>
            <tr style="border-bottom:1px solid var(--border);text-align:left;color:var(--muted);font-size:13px;">
              <th style="padding:10px 8px;">#</th>
              <th style="padding:10px 8px;">Player</th>
              <th style="padding:10px 8px;text-align:right;">Points</th>
              <th style="padding:10px 8px;text-align:right;">Wins</th>
              <th style="padding:10px 8px;text-align:right;">Open Plays</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($players as $p):
                $rank      = (int)($p['rank'] ?? 0);
                $isMe      = $myId > 0 && (int)$p['player_id'] === $myId;
                $medal     = match ($rank) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => null };
                $podiumCls = $rank === 1 ? 'op-lb-row--top1' : ($rank === 2 ? 'op-lb-row--top2' : ($rank === 3 ? 'op-lb-row--top3' : ''));
                $name      = htmlspecialchars($p['name'] ?? 'Player');
            ?>
            <tr class="<?= $podiumCls ?>" style="border-bottom:1px solid var(--border);<?= $isMe ? 'background:rgba(0,229,160,0.06);' : '' ?>">
              <td style="padding:10px 8px;font-weight:600;">
                <?= $medal ?? '#' . $rank ?><?php if (!empty($p['tied'])): ?><span class="op-lb-tie">Tied</span><?php endif; ?>
              </td>
              <td style="padding:10px 8px;">
                <?= $name ?><?= $isMe ? ' <span style="color:var(--accent);font-size:12px;">(you)</span>' : '' ?>
              </td>
              <td style="padding:10px 8px;text-align:right;font-weight:600;">
                <?= number_format((int)$p['total_points']) ?>
              </td>
              <td style="padding:10px 8px;text-align:right;color:var(--muted);">
                <?= number_format((int)$p['total_wins']) ?>
              </td>
              <td style="padding:10px 8px;text-align:right;color:var(--muted);">
                <?= number_format((int)$p['total_events']) ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pages > 1): ?>
      <div style="display:flex;justify-content:center;gap:8px;margin-top:22px;">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
          <a href="?season=<?= $season ?>&page=<?= $i ?>"
             style="padding:6px 12px;border-radius:8px;border:1px solid var(--border);
                    color:<?= $i === $page ? 'var(--bg)' : 'var(--text)' ?>;
                    background:<?= $i === $page ? 'var(--accent)' : 'transparent' ?>;
                    text-decoration:none;font-size:14px;">
            <?= $i ?>
          </a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
