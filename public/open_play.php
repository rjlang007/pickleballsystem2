<?php
// ============================================================
//  FILE: public/open_play.php
//  Player-facing Open Play landing page — browse events, join
//  with a self-rated skill level, see your spot in the queue,
//  and jump into the live board. Mirrors public/tournaments.php
//  (same header/footer, same card styling) but for the
//  random-pairing format instead of brackets.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../tournament/open_play_engine.php';

requireLogin();

$engine = new OpenPlayEngine();
$myId   = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $tid    = (int)($_POST['tournament_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'join') {
            $engine->joinEvent($tid, $myId, $_POST['skill_level'] ?? 'average');
            setFlash('success', "🎲 You're in the pool! Watch the live board to see when you're up.");
        } elseif ($action === 'leave') {
            $engine->leaveEvent($tid, $myId);
            setFlash('success', 'You left the event.');
        } elseif ($action === 'rest') {
            $engine->markResting($tid, $myId, $myId);
            setFlash('success', 'You took a break and left the active queue.');
        } elseif ($action === 'return') {
            $engine->returnToQueue($tid, $myId, $myId);
            setFlash('success', 'You rejoined the queue.');
        }
    } catch (Throwable $e) {
        setFlash('error', '⚠️ ' . $e->getMessage());
    }
    redirect('public/open_play.php');
}

$tab    = $_GET['tab'] ?? 'open';
$allEvents = $engine->listEvents();
if ($tab === 'done') {
  $events = array_values(array_filter($allEvents, static fn($event) => $event['status'] === 'completed'));
} elseif ($tab === 'live') {
  $events = array_values(array_filter($allEvents, static fn($event) => in_array($event['status'], ['in_progress', 'registration_closed', 'paused'], true)));
} else {
  $events = array_values(array_filter($allEvents, static fn($event) => in_array($event['status'], ['registration_open', 'in_progress', 'registration_closed'], true)));
}

// Which of these has the current player already joined, and with what queue status?
$myRows = [];
if ($events) {
    $ids = array_column($events, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = getDB()->prepare(
        "SELECT tournament_id, queue_status, skill_level FROM falcon.tournament_players
          WHERE player_id = ? AND status != 'withdrawn' AND tournament_id IN ($in)"
    );
    $stmt->execute([$myId, ...$ids]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $myRows[$r['tournament_id']] = $r;
}

// Full roster per event (approved / waitlisted / remaining slots) — visible
// to every player, not just the ones who joined. Only needed for events
// that are actually taking sign-ups or running right now.
$rosters = [];
foreach ($events as $e) {
    if ($tab === 'done') continue;
    $roster    = $engine->getRoster((int)$e['id']);
    $approved  = array_values(array_filter($roster, static fn($p) => $p['status'] === 'active'));
    $waitlist  = array_values(array_filter($roster, static fn($p) => $p['status'] === 'pending_approval'));
    $rosters[$e['id']] = [
        'approved'  => $approved,
        'waitlist'  => $waitlist,
        'remaining' => max(0, (int)$e['max_players'] - count($roster)),
    ];
}

function openPlayPlayerName(array $p): string {
    return $p['display_name'] ?: $p['full_name'] ?: $p['username'];
}

$pageTitle = 'Open Play';
$history   = $engine->getPlayerHistory($myId);
$queueLabels = ['waiting' => 'Waiting', 'playing' => 'Playing', 'resting' => 'Resting', 'queued' => 'Up Next', 'left' => 'Left'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;">

  <?php if ($history['totals']['games'] > 0): ?>
  <div class="card" style="margin-bottom:20px;">
    <div class="card-title mb-1">📈 Your Open Play Record</div>
    <hr class="divider"/>
    <div style="display:flex;gap:24px;flex-wrap:wrap;">
      <div><div style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:var(--accent);"><?= (int)$history['totals']['wins'] ?>-<?= (int)$history['totals']['losses'] ?></div><div style="color:var(--muted);font-size:12px;">Record</div></div>
      <div><div style="font-family:'Bebas Neue',sans-serif;font-size:28px;"><?= (int)$history['totals']['games'] ?></div><div style="color:var(--muted);font-size:12px;">Games Played</div></div>
      <div><div style="font-family:'Bebas Neue',sans-serif;font-size:28px;"><?= (int)$history['totals']['events'] ?></div><div style="color:var(--muted);font-size:12px;">Events</div></div>
      <?php if ($history['totals']['firsts'] > 0): ?>
      <div><div style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:var(--accent);">🥇 <?= (int)$history['totals']['firsts'] ?></div><div style="color:var(--muted);font-size:12px;">1st Place Finishes</div></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <h1 style="margin:0 0 6px;">🎲 Open Play</h1>
      <p style="color:var(--muted);margin:0;">Continuous walk-in session — join the queue at any time, get considered with the current wait and arrival fairness, and play as courts free up.</p>
    </div>

    <div style="display:flex;gap:8px;margin:16px 0;">
      <a class="btn <?= $tab === 'open' ? 'btn-primary' : '' ?>" href="?tab=open">Open Play</a>
      <a class="btn <?= $tab === 'live' ? 'btn-primary' : '' ?>" href="?tab=live">Happening Now</a>
      <a class="btn <?= $tab === 'done' ? 'btn-primary' : '' ?>" href="?tab=done">Past Results</a>
    </div>

    <?php if (empty($events)): ?>
      <div class="text-muted" style="padding:20px 0;">
          <?php if ($tab === 'open'): ?>
          No Open Play events are available right now.
        <?php elseif ($tab === 'live'): ?>
          No Open Play event is happening right now.
        <?php else: ?>
          No completed Open Play results are available yet.
        <?php endif; ?>
        <?php if (isStaff()): ?>
          <div style="margin-top:12px;">
            <a class="btn btn-primary" href="<?= APP_URL ?>/staff/open_play_control.php">Open Staff Control</a>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php foreach ($events as $e): $mine = $myRows[$e['id']] ?? null; ?>
      <div class="card" style="margin-bottom:14px;padding:18px;">
        <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;align-items:center;">
          <div>
            <div style="font-weight:800;font-size:18px;"><?= clean($e['name']) ?></div>
            <?php if (!empty($e['description'])): ?>
              <div style="color:var(--muted);font-size:14px;margin-top:2px;"><?= clean($e['description']) ?></div>
            <?php endif; ?>
            <div style="color:var(--muted);font-size:12px;margin-top:6px;">
              <?= $e['start_date'] ? date('M j, Y g:i A', strtotime($e['start_date'])) : '' ?>
            </div>
          </div>

          <div style="display:flex;gap:8px;align-items:center;">
            <?php if ($tab === 'done'): ?>
              <a class="btn" href="<?= APP_URL ?>/public/open_play_results.php?tournament_id=<?= (int)$e['id'] ?>">🏆 View Results</a>

            <?php elseif ($mine): ?>
              <span class="badge <?= $mine['queue_status'] === 'pending_approval' ? 'badge-warning' : 'badge-info' ?>">
                <?= $mine['queue_status'] === 'pending_approval' ? 'Join request pending approval' : "You're " . clean($queueLabels[$mine['queue_status']] ?? ucfirst($mine['queue_status'])) ?> · <?= ucfirst($mine['skill_level']) ?>
              </span>
              <?php if ($mine['queue_status'] === 'pending_approval'): ?><span class="text-muted">Admin approval required</span><?php endif; ?>
              <?php if ($mine['queue_status'] === 'waiting'): ?>
                <form method="POST">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="rest"/>
                  <input type="hidden" name="tournament_id" value="<?= (int)$e['id'] ?>"/>
                  <button type="submit" class="btn btn-sm">Take Break</button>
                </form>
              <?php elseif ($mine['queue_status'] === 'resting'): ?>
                <form method="POST">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="return"/>
                  <input type="hidden" name="tournament_id" value="<?= (int)$e['id'] ?>"/>
                  <button type="submit" class="btn btn-sm btn-primary">Return to Queue</button>
                </form>
              <?php elseif ($mine['queue_status'] !== 'pending_approval'): ?>
                <a class="btn" href="<?= APP_URL ?>/public/open_play_live.php?tournament_id=<?= (int)$e['id'] ?>">📺 Live Board</a>
              <?php endif; ?>
              <form method="POST" onsubmit="return confirm('Cancel this join request?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="leave"/>
                <input type="hidden" name="tournament_id" value="<?= (int)$e['id'] ?>"/>
                <button type="submit" class="btn btn-sm">Leave</button>
              </form>

            <?php else: ?>
              <form method="POST" style="display:flex;gap:6px;align-items:center;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="join"/>
                <input type="hidden" name="tournament_id" value="<?= (int)$e['id'] ?>"/>
                <select name="skill_level" title="Self-rate your skill so games stay balanced">
                  <option value="beginner">Beginner</option>
                  <option value="average" selected>Average</option>
                  <option value="advance">Advanced</option>
                </select>
                <button type="submit" class="btn btn-primary">Join Queue</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($tab !== 'done' && isset($rosters[$e['id']])): $r = $rosters[$e['id']]; ?>
          <hr class="divider" style="margin:14px 0;"/>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
            <span class="badge badge-info"><?= count($r['approved']) ?> / <?= (int)$e['max_players'] ?> approved</span>
            <?php if (count($r['waitlist']) > 0): ?>
              <span class="badge badge-warning"><?= count($r['waitlist']) ?> waitlisted</span>
            <?php endif; ?>
            <span class="badge"><?= (int)$r['remaining'] ?> slot<?= $r['remaining'] === 1 ? '' : 's' ?> left</span>
          </div>

          <details>
            <summary style="cursor:pointer;color:var(--accent);font-size:13px;">See who's playing</summary>
            <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:10px;">
              <div style="flex:1;min-width:200px;">
                <div style="font-weight:700;font-size:13px;margin-bottom:6px;">✅ Approved (<?= count($r['approved']) ?>)</div>
                <?php if (empty($r['approved'])): ?>
                  <div class="text-muted" style="font-size:13px;">No one has joined yet.</div>
                <?php else: ?>
                  <ol style="margin:0;padding-left:18px;font-size:14px;">
                    <?php foreach ($r['approved'] as $p): ?>
                      <li><?= clean(openPlayPlayerName($p)) ?>
                        <span class="text-muted" style="font-size:12px;">· <?= clean($queueLabels[$p['queue_status']] ?? ucfirst($p['queue_status'])) ?></span>
                      </li>
                    <?php endforeach; ?>
                  </ol>
                <?php endif; ?>
              </div>
              <div style="flex:1;min-width:200px;">
                <div style="font-weight:700;font-size:13px;margin-bottom:6px;">⏳ Waitlisted (<?= count($r['waitlist']) ?>)</div>
                <?php if (empty($r['waitlist'])): ?>
                  <div class="text-muted" style="font-size:13px;">No one is waiting on approval.</div>
                <?php else: ?>
                  <ol style="margin:0;padding-left:18px;font-size:14px;">
                    <?php foreach ($r['waitlist'] as $p): ?>
                      <li><?= clean(openPlayPlayerName($p)) ?></li>
                    <?php endforeach; ?>
                  </ol>
                <?php endif; ?>
              </div>
            </div>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
