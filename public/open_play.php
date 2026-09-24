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
          $joinEvent = $engine->getEvent($tid);
          $joinSettings = $joinEvent ? (json_decode($joinEvent['settings'] ?? '{}', true) ?: []) : [];
          $joinPrice = round((float)($joinSettings['price'] ?? 0), 2);
          if ($joinPrice <= 0) {
            $engine->joinEvent($tid, $myId, $_POST['skill_level'] ?? 'average');
            setFlash('success', 'You joined the free session and entered the queue. Watch the live board for your turn.');
          } else {
            if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
              throw new RuntimeException('Upload your payment proof before requesting to join.');
            }
            $file = $_FILES['payment_proof'];
            if ((int)$file['size'] > 5 * 1024 * 1024) throw new RuntimeException('Payment proof must be 5 MB or smaller.');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime])) throw new RuntimeException('Payment proof must be a JPG, PNG, or WebP image.');
            $uploadDir = __DIR__ . '/../uploads/open_play_payments';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true)) throw new RuntimeException('Could not prepare payment upload storage.');
            $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) throw new RuntimeException('Could not save payment proof.');

            $engine->joinEvent($tid, $myId, $_POST['skill_level'] ?? 'average', [
              'payment_method' => $_POST['payment_method'] ?? '',
              'reference_no' => $_POST['reference_no'] ?? '',
              'proof_path' => 'uploads/open_play_payments/' . $filename,
            ]);
            setFlash('success', '💳 Payment proof submitted. Staff will review your request before you enter the queue.');
          }
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

    <div class="open-play-tabs" role="tablist" aria-label="Open Play views">
      <a class="open-play-tab <?= $tab === 'open' ? 'is-active' : '' ?>" href="?tab=open" role="tab" aria-selected="<?= $tab === 'open' ? 'true' : 'false' ?>">Open Play</a>
      <a class="open-play-tab <?= $tab === 'live' ? 'is-active' : '' ?>" href="?tab=live" role="tab" aria-selected="<?= $tab === 'live' ? 'true' : 'false' ?>">Happening Now</a>
      <a class="open-play-tab <?= $tab === 'done' ? 'is-active' : '' ?>" href="?tab=done" role="tab" aria-selected="<?= $tab === 'done' ? 'true' : 'false' ?>">Past Results</a>
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
          <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
            <a class="btn btn-primary" href="<?= APP_URL ?>/staff/open_play_settings.php">✏️ Edit Details</a>
            <a class="btn" href="<?= APP_URL ?>/staff/open_play_control.php">⚙ Open Play Control</a>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php foreach ($events as $e): $mine = $myRows[$e['id']] ?? null; ?>
      <div class="card open-play-event-card" data-event-id="<?= (int)$e['id'] ?>" style="margin-bottom:14px;padding:18px;">
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

          <div class="open-play-event-actions" style="display:flex;gap:8px;align-items:center;">
            <?php if ($tab === 'done'): ?>
              <a class="btn" href="<?= APP_URL ?>/public/open_play_results.php?tournament_id=<?= (int)$e['id'] ?>">🏆 View Results</a>

            <?php elseif (isStaff()): ?>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a class="btn" href="<?= APP_URL ?>/staff/open_play_settings.php?tournament_id=<?= (int)$e['id'] ?>" title="Edit name, date, time, capacity, price, and registration/posting status">✏️ Edit Details</a>
                <a class="btn btn-primary" href="<?= APP_URL ?>/staff/open_play_control.php?tournament_id=<?= (int)$e['id'] ?>" title="Manage the queue, draw/matchmaking, live games, and game duration">⚙ Open Play Control</a>
              </div>

            <?php elseif ($mine): ?>
              <span class="badge <?= $mine['queue_status'] === 'pending_approval' ? 'badge-warning' : 'badge-info' ?>" data-open-play-status>
                <?= $mine['queue_status'] === 'pending_approval' ? 'Join request pending approval' : "You're " . clean($queueLabels[$mine['queue_status']] ?? ucfirst($mine['queue_status'])) ?> · <?= ucfirst($mine['skill_level']) ?>
              </span>
              <?php if ($mine['queue_status'] === 'pending_approval'): ?><span class="text-muted">Admin approval required</span><?php endif; ?>
              <?php if ($mine['queue_status'] === 'waiting'): ?>
                <form method="POST" data-open-play-action="rest">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="rest"/>
                  <input type="hidden" name="tournament_id" value="<?= (int)$e['id'] ?>"/>
                  <button type="submit" class="btn btn-sm">Take Break</button>
                </form>
              <?php elseif ($mine['queue_status'] === 'resting'): ?>
                <form method="POST" data-open-play-action="return">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="return"/>
                  <input type="hidden" name="tournament_id" value="<?= (int)$e['id'] ?>"/>
                  <button type="submit" class="btn btn-sm btn-primary">Return to Queue</button>
                </form>
              <?php elseif ($mine['queue_status'] !== 'pending_approval'): ?>
                <a class="btn" href="<?= APP_URL ?>/public/open_play_live.php?tournament_id=<?= (int)$e['id'] ?>">📺 Live Board</a>
              <?php endif; ?>
              <form method="POST" data-open-play-action="leave" onsubmit="return confirm('Cancel this join request?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="leave"/>
                <input type="hidden" name="tournament_id" value="<?= (int)$e['id'] ?>"/>
                <button type="submit" class="btn btn-sm">Leave</button>
              </form>

            <?php else: $eventSettings = json_decode($e['settings'] ?? '{}', true) ?: []; $eventPrice = (float)($eventSettings['price'] ?? 0); $joinDialogId = 'open-play-join-' . (int)$e['id']; ?>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <strong>Fee: ₱<?= number_format($eventPrice, 2) ?></strong>
                <button type="button" class="btn btn-primary open-play-join-trigger" data-dialog-id="<?= $joinDialogId ?>">Join Open Play</button>
              </div>
              <dialog id="<?= $joinDialogId ?>" class="open-play-join-dialog">
                <form method="POST" enctype="multipart/form-data">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="join"/>
                  <input type="hidden" name="tournament_id" value="<?= (int)$e['id'] ?>"/>
                  <h2>Secure your slot</h2>
                  <p>Please fill up this form to secure your slot. The Open Play fee is <strong>₱<?= number_format($eventPrice, 2) ?></strong>.</p>
                  <?php if ($eventPrice <= 0): ?>
                    <div class="alert alert-info">This session is free. No payment is required. You will enter the queue immediately.</div>
                  <?php else: ?>
                    <label>Payment method</label>
                    <select name="payment_method" required>
                      <option value="">Select payment method</option>
                      <option value="gcash">GCash</option>
                      <option value="bank_transfer">Bank transfer</option>
                      <option value="cash">Cash at venue</option>
                    </select>
                    <label>Payment reference</label>
                    <input type="text" name="reference_no" maxlength="120" required/>
                    <label>Upload proof of payment</label>
                    <input type="file" name="payment_proof" accept="image/jpeg,image/png,image/webp" required/>
                  <?php endif; ?>
                  <label>Skill level</label>
                  <select name="skill_level" required>
                    <option value="beginner">Beginner</option>
                    <option value="average" selected>Average</option>
                    <option value="advance">Advanced</option>
                  </select>
                  <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">
                    <button type="button" class="btn open-play-dialog-close">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Join Request</button>
                  </div>
                </form>
              </dialog>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($tab !== 'done' && isset($rosters[$e['id']])): $r = $rosters[$e['id']]; ?>
          <hr class="divider" style="margin:14px 0;"/>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
            <span class="badge badge-info"><?= count($r['approved']) ?> / <?= (int)$e['max_players'] ?> approved</span>
            <?php if (count($r['waitlist']) > 0): ?>
              <span class="badge badge-warning"><?= count($r['waitlist']) ?> awaiting approval</span>
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
                <div style="font-weight:700;font-size:13px;margin-bottom:6px;">⏳ Awaiting approval (<?= count($r['waitlist']) ?>)</div>
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

<style nonce="<?= getCspNonce() ?>">
.open-play-tabs {
  display: flex;
  gap: 4px;
  margin: 16px 0 22px;
  padding: 4px;
  width: fit-content;
  max-width: 100%;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 10px;
}
.open-play-tab {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 36px;
  padding: 0 15px;
  border: 1px solid transparent;
  border-radius: 7px;
  color: var(--muted);
  font-size: 13px;
  font-weight: 700;
  line-height: 1;
  text-decoration: none;
  white-space: nowrap;
  transition: color .18s ease, background-color .18s ease, border-color .18s ease;
}
.open-play-tab:hover,
.open-play-tab:focus-visible {
  color: var(--text);
  background: rgba(0,229,160,.08);
  outline: none;
}
.open-play-tab.is-active {
  color: var(--bg);
  background: var(--accent);
  border-color: var(--accent);
  box-shadow: 0 3px 10px rgba(0,229,160,.18);
}
@media (max-width: 520px) {
  .open-play-tabs { width: 100%; }
  .open-play-tab { flex: 1; padding: 0 8px; font-size: 12px; }
}
</style>

  <style nonce="<?= getCspNonce() ?>">
  .open-play-join-dialog { border:1px solid var(--border); border-radius:12px; background:var(--surface); color:var(--text); padding:0; width:min(92vw,480px); }
  .open-play-join-dialog::backdrop { background:rgba(5,10,15,.72); }
  .open-play-join-dialog form { display:flex; flex-direction:column; gap:8px; padding:22px; }
  .open-play-join-dialog h2 { margin:0; }
  .open-play-join-dialog p { color:var(--muted); margin:0 0 8px; }
  .open-play-join-dialog label { color:var(--muted); font-size:12px; font-weight:700; }
  .open-play-join-dialog input, .open-play-join-dialog select { width:100%; }
  </style>
  <script nonce="<?= csrfNonce() ?>">
  const APP_URL = <?= json_encode(APP_URL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  document.querySelectorAll('.open-play-join-trigger').forEach(function (button) {
    button.addEventListener('click', function () {
      const dialog = document.getElementById(button.dataset.dialogId);
      if (dialog) dialog.showModal();
    });
  });
  document.querySelectorAll('.open-play-dialog-close').forEach(function (button) {
    button.addEventListener('click', function () {
      const dialog = button.closest('dialog');
      if (dialog) dialog.close();
    });
  });

  async function refreshOpenPlayCards() {
    const cards = Array.from(document.querySelectorAll('.open-play-event-card'));
    if (!cards.length) return;
    const response = await fetch(window.location.href, { credentials: 'same-origin', cache: 'no-store' });
    if (!response.ok) throw new Error('Could not refresh Open Play status.');
    const html = await response.text();
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    cards.forEach(function (card) {
      const replacement = parsed.querySelector('[data-event-id="' + card.dataset.eventId + '"]');
      if (replacement) card.replaceWith(replacement);
    });
  }

  document.addEventListener('click', function (event) {
    const trigger = event.target.closest('.open-play-join-trigger');
    if (trigger) {
      const dialog = document.getElementById(trigger.dataset.dialogId);
      if (dialog && !dialog.open) dialog.showModal();
      return;
    }
    const close = event.target.closest('.open-play-dialog-close');
    if (close) {
      const dialog = close.closest('dialog');
      if (dialog && dialog.open) dialog.close();
    }
  });

  document.addEventListener('submit', async function (event) {
    const form = event.target.closest('form[data-open-play-action]');
    if (!form || event.defaultPrevented) return;
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    const values = new FormData(form);
    try {
      const response = await fetch(APP_URL + '/api/open_play.php?action=' + encodeURIComponent(form.dataset.openPlayAction), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tournament_id: Number(values.get('tournament_id')) }),
      });
      const data = await response.json();
      if (!data.success) throw new Error(data.message || 'Request failed.');
      await refreshOpenPlayCards();
    } catch (error) {
      if (button) button.disabled = false;
      alert(error.message || 'Unable to update your Open Play status.');
    }
  });

  setInterval(function () {
    refreshOpenPlayCards().catch(function () {});
  }, 8000);
  </script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
