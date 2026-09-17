<?php
// ============================================================
//  FILE: public/tournaments.php
//  Browse tournaments, join/leave the ones open for registration.
//
//  Same story as public/leaderboard.php: this file previously
//  was an exact copy of the root index.php redirect stub, causing
//  an infinite redirect loop. root/tournaments.php still redirects
//  here (that part is fine) -- this file just never actually
//  existed as a real page before.
//
//  Data + actions use the existing, working TournamentEngine
//  class and its API endpoints (api/tournaments.php for listing,
//  api/tournament_registration.php for join/leave) rather than
//  querying the DB directly, so this page stays in sync with
//  whatever admin tooling already manages tournaments.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../tournament/tournament_engine.php';

requireLogin();

$db     = getDB();
$engine = new TournamentEngine();
$myId   = (int)$_SESSION['user_id'];

// -- Tab filter --------------------------------------------------
$tab     = $_GET['tab'] ?? 'open';
$tabMap  = [
    'open'       => 'registration_open',
    'inprogress' => 'in_progress',
    'completed'  => 'completed',
];
$status  = $tabMap[$tab] ?? 'registration_open';

$tournaments = $engine->listTournaments(['status' => $status]);

// -- Which of these is the current player already registered for? --
$myRegisteredIds = [];
if (!empty($tournaments)) {
    $ids  = array_column($tournaments, 'id');
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT tournament_id FROM falcon.tournament_players
          WHERE player_id = ? AND status != 'withdrawn' AND tournament_id IN ($in)"
    );
    $stmt->execute([$myId, ...$ids]);
    $myRegisteredIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$pageTitle = 'Tournaments';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;">
  <div class="card">
    <div class="card-header">
      <h1 style="margin:0 0 6px;">🎯 Tournaments</h1>
      <p style="color:var(--muted);margin:0;">Join an upcoming tournament or check on ones in progress.</p>
    </div>

    <div id="tt-msg" style="display:none;margin:14px 0;padding:12px 14px;border-radius:8px;font-size:14px;"></div>

    <div style="display:flex;gap:8px;margin:18px 0 22px;flex-wrap:wrap;">
      <?php foreach (['open' => 'Open for Registration', 'inprogress' => 'In Progress', 'completed' => 'Completed'] as $key => $label): ?>
        <a href="?tab=<?= $key ?>"
           style="padding:8px 14px;border-radius:8px;border:1px solid var(--border);text-decoration:none;font-size:14px;
                  color:<?= $tab === $key ? 'var(--bg)' : 'var(--text)' ?>;
                  background:<?= $tab === $key ? 'var(--accent)' : 'transparent' ?>;">
          <?= $label ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($tournaments)): ?>
      <div style="text-align:center;padding:48px 16px;color:var(--muted);">
        No tournaments here right now.
      </div>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
        <?php foreach ($tournaments as $t):
            $tid       = (int)$t['id'];
            $isJoined  = in_array($tid, $myRegisteredIds, true);
            $current   = (int)$t['current_players'];
            $max       = (int)$t['max_players'];
            $isFull    = $current >= $max;
            $canJoin   = $status === 'registration_open' && !$isJoined && !$isFull;
        ?>
        <div class="card" data-tid="<?= $tid ?>" style="margin:0;">
          <div style="display:flex;justify-content:space-between;align-items:start;gap:8px;">
            <h3 style="margin:0;"><?= htmlspecialchars($t['name']) ?></h3>
            <?php if (!empty($t['featured'])): ?>
              <span style="font-size:11px;background:rgba(245,230,66,0.15);color:#f5e642;padding:3px 8px;border-radius:6px;white-space:nowrap;">⭐ Featured</span>
            <?php endif; ?>
          </div>
          <?php if (!empty($t['description'])): ?>
            <p style="color:var(--muted);font-size:13px;margin:8px 0;"><?= htmlspecialchars($t['description']) ?></p>
          <?php endif; ?>
          <div style="display:flex;flex-wrap:wrap;gap:6px 14px;font-size:13px;color:var(--muted);margin:10px 0;">
            <span>📅 <?= $t['start_date'] ? date('M j, Y', strtotime($t['start_date'])) : 'TBD' ?></span>
            <span>👥 <?= $current ?>/<?= $max ?> players</span>
            <span>🏆 <?= ucwords(str_replace('_', ' ', $t['bracket_type'])) ?></span>
          </div>

          <?php if ($status === 'registration_open'): ?>
            <?php if ($isJoined): ?>
              <button class="tt-leave-btn" data-tid="<?= $tid ?>"
                      style="width:100%;padding:9px;border-radius:8px;border:1px solid var(--danger);
                             background:transparent;color:var(--danger);cursor:pointer;font-weight:600;">
                Leave Tournament
              </button>
            <?php elseif ($isFull): ?>
              <button disabled style="width:100%;padding:9px;border-radius:8px;border:1px solid var(--border);
                                       background:var(--surface2);color:var(--muted);">Full</button>
            <?php else: ?>
              <button class="tt-join-btn" data-tid="<?= $tid ?>"
                      style="width:100%;padding:9px;border-radius:8px;border:none;
                             background:var(--accent);color:var(--bg);cursor:pointer;font-weight:600;">
                Join Tournament
              </button>
            <?php endif; ?>
          <?php elseif ($isJoined): ?>
            <div style="text-align:center;font-size:13px;color:var(--accent);">✓ You're registered</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script nonce="<?= csrfNonce() ?>">
function ttShowMsg(text, ok) {
    const el = document.getElementById('tt-msg');
    el.textContent = text;
    el.style.display = 'block';
    el.style.background = ok ? 'rgba(0,229,160,0.12)' : 'rgba(239,68,68,0.12)';
    el.style.color = ok ? 'var(--accent)' : 'var(--danger)';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function ttJoin(tid) {
    try {
        const res = await fetch('<?= APP_URL ?>/api/tournament_registration.php?tournament_id=' + tid, { method: 'POST' });
        const json = await res.json();
        if (!res.ok || !json.success) { ttShowMsg(json.message || 'Could not join.', false); return; }
        ttShowMsg(json.message || 'Joined!', true);
        setTimeout(() => location.reload(), 700);
    } catch (e) { ttShowMsg('Network error joining tournament.', false); }
}

async function ttLeave(tid) {
    if (!confirm('Leave this tournament?')) return;
    try {
        const res = await fetch('<?= APP_URL ?>/api/tournament_registration.php?tournament_id=' + tid, { method: 'DELETE' });
        const json = await res.json();
        if (!res.ok || !json.success) { ttShowMsg(json.message || 'Could not leave.', false); return; }
        ttShowMsg(json.message || 'Left the tournament.', true);
        setTimeout(() => location.reload(), 700);
    } catch (e) { ttShowMsg('Network error leaving tournament.', false); }
}

document.querySelectorAll('.tt-join-btn').forEach(btn => btn.addEventListener('click', () => ttJoin(btn.dataset.tid)));
document.querySelectorAll('.tt-leave-btn').forEach(btn => btn.addEventListener('click', () => ttLeave(btn.dataset.tid)));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>