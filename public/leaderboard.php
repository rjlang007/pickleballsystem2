<?php
// ============================================================
//  FILE: public/leaderboard.php
//  Season leaderboard — top players ranked by total_points for
//  a given season (year), pulled from falcon.leaderboard.
//
//  Replaces a previous version of this file that was an exact
//  copy of the root index.php redirect stub, causing an infinite
//  redirect loop (public/leaderboard.php -> public/leaderboard.php).
//  root/leaderboard.php still redirects here -- that part was fine,
//  this file just never actually existed as a real page before.
//
//  Note: LeaderboardEngine also gets called with getPlayerRank()
//  and getPlayerStats() elsewhere in the codebase (dashboard
//  widget, player/my_ranking.php) -- those methods don't actually
//  exist on this class yet. This page avoids those and only uses
//  the working getLeaderboard() method.
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

$result  = $engine->getLeaderboard($season, $perPage, $offset);
$players = $result['players'] ?? [];
$total   = $result['total'] ?? 0;
$pages   = max(1, (int)ceil($total / $perPage));

$myId = (int)($_SESSION['user_id'] ?? 0);

$pageTitle = 'Leaderboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;">
  <div class="card">
    <div class="card-header">
      <h1 style="margin:0 0 6px;">🏆 Leaderboard</h1>
      <p style="color:var(--muted);margin:0;">
        Season rankings by total tournament points.
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
        No rankings yet for <?= $season ?>. Rankings appear once tournaments in that season are completed.
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
              <th style="padding:10px 8px;text-align:right;">Tournaments</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($players as $p):
                $rank   = (int)($p['rank'] ?? 0);
                $isMe   = $myId > 0 && (int)$p['player_id'] === $myId;
                $medal  = match ($rank) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => null };
                $name   = htmlspecialchars($p['display_name'] ?: $p['full_name'] ?: $p['username'] ?? 'Player');
            ?>
            <tr style="border-bottom:1px solid var(--border);<?= $isMe ? 'background:rgba(0,229,160,0.06);' : '' ?>">
              <td style="padding:10px 8px;font-weight:600;">
                <?= $medal ?? '#' . $rank ?>
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
                <?= number_format((int)$p['total_tournaments']) ?>
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