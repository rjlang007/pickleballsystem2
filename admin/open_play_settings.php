<?php
// ============================================================
//  FILE: admin/open_play_settings.php
//  Admin controls for the recurring daily Open Play post.
//  The actual auto-posting logic lives in tournament/open_play_scheduler.php
//  (runOpenPlayScheduler(), driven every minute by the background worker
//  scripts/open_play_cron.php, with a page-load fallback).
//  This page reads/writes the settings it acts on, and can trigger a run.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../tournament/open_play_scheduler.php';
requireStaff();

$db      = getDB();
$adminId = (int)$_SESSION['user_id'];
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_now'])) {
    verifyCsrf();
    try {
        $run = runOpenPlayScheduler(true);
        if (!empty($run['created'])) {
            setFlash('success', '✅ Open Play post created — it is now live for players.');
        } elseif (!empty($run['finalized'])) {
            setFlash('success', '✅ A finished session was closed out and the leaderboard updated.');
        } else {
            setFlash('info', 'ℹ️ Checked just now — nothing to post (' . ($run['reason'] ?: 'up to date') . ').');
        }
    } catch (Throwable $e) {
        error_log('[open_play_settings] run now failed: ' . $e->getMessage());
        setFlash('error', 'Could not run the scheduler right now. Please try again.');
    }
    redirect('admin/open_play_settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        saveOpenPlaySchedule($db, $_POST, $adminId);
        setFlash('success', '✅ Open Play schedule saved.');
        redirect('admin/open_play_settings.php');
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[open_play_settings] save failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving these settings. Please try again.';
    }
}

$schedule = getOpenPlaySchedule($db);
$closedForDate = getOpenPlayClosedDate($db);
$openPlayCourts = $db->query(
  "SELECT id, name, short_code, COALESCE(is_maintenance, FALSE) AS is_maintenance
     FROM falcon.courts WHERE is_active = TRUE ORDER BY sort_order NULLS LAST, id"
)->fetchAll(PDO::FETCH_ASSOC);
$scheduleCourtIds = openPlayDecodeCourtIds($schedule['court_ids'] ?? '[]');

// Tonight's session and the next one, using the same 4 AM business-day
// cutover (and the same date matching) as the scheduler itself.
$businessDate = openPlayBusinessDate();
$nextDate     = (new DateTimeImmutable($businessDate, new DateTimeZone(OPEN_PLAY_TZ)))->modify('+1 day')->format('Y-m-d');
$todayEvents  = findOpenPlayEventsForDate($db, $businessDate);
$todayEvent   = $todayEvents[0] ?? null;
$nextEvents   = findOpenPlayEventsForDate($db, $nextDate);
$nextEvent    = $nextEvents[0] ?? null;

$lastRunTs   = (int)(openPlaySchedulerGet($db, 'last_run') ?? 0);
$lastRunAgo  = $lastRunTs > 0 ? max(0, time() - $lastRunTs) : null;
$workerAlive = $lastRunAgo !== null && $lastRunAgo <= 300;

$rosterCounts = null;
if ($todayEvent) {
    $c = $db->prepare("
        SELECT
            COUNT(*) FILTER (WHERE status = 'active')            AS approved,
            COUNT(*) FILTER (WHERE status = 'pending_approval')  AS waitlisted
          FROM falcon.tournament_players
         WHERE tournament_id = :tid
    ");
    $c->execute([':tid' => $todayEvent['id']]);
    $rosterCounts = $c->fetch(PDO::FETCH_ASSOC);
}

$pageTitle = 'Open Play Settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;">
  <div class="card">
    <div class="card-header">
      <h1 style="margin:0 0 6px;">⚙️ Open Play Settings</h1>
      <p style="color:var(--muted);margin:0;">
        Control the recurring daily Open Play post — what time it runs,
        how many players it holds, and whether it's posted at all. A new post
        goes up automatically every day, right after the previous session ends.
      </p>
    </div>

    <?php foreach ($errors as $err): ?>
      <div class="alert alert-error" style="margin-top:12px;">⚠️ <?= clean($err) ?></div>
    <?php endforeach; ?>

    <form method="POST" style="margin-top:20px;display:grid;gap:20px;max-width:520px;">
      <?= csrfField() ?>

      <div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
        <div>
          <div style="font-weight:700;">Nightly Open Play schedule</div>
          <div style="color:var(--muted);font-size:13px;margin-top:2px;">
            When on, a new Open Play post is created automatically every day as soon
            as the previous session has ended. Turn this off to pause posting
            (e.g. the venue is closed for a while) — it never touches a session that
            is already posted or in progress. To skip just one night, cancel that
            night's post: tomorrow's still goes up on its own.
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:8px;white-space:nowrap;">
          <input type="checkbox" name="enabled" value="1" <?= $schedule['enabled'] === '1' ? 'checked' : '' ?> />
          Enabled
        </label>
      </div>

      <div class="card">
        <label class="form-label">Event name</label>
        <input type="text" name="name" class="form-input" maxlength="150"
               value="<?= clean($schedule['name']) ?>" required />
      </div>

      <div class="card">
        <label class="form-label">Description shown to players</label>
        <textarea name="description" class="form-input" rows="3" maxlength="1000"><?= clean($schedule['description']) ?></textarea>
      </div>

      <div class="card" style="display:flex;gap:16px;flex-wrap:wrap;">
        <div style="flex:1;min-width:140px;">
          <label class="form-label">Starts at</label>
          <input type="time" name="start_time" class="form-input" value="<?= clean($schedule['start_time']) ?>" required />
        </div>
        <div style="flex:1;min-width:140px;">
          <label class="form-label">Ends at</label>
          <input type="time" name="end_time" class="form-input" value="<?= clean($schedule['end_time']) ?>" required />
          <div style="color:var(--muted);font-size:12px;margin-top:4px;">
            An end time earlier than the start time (e.g. 6:00 PM → 12:00 AM) is
            treated as running past midnight into the next day.
          </div>
        </div>
      </div>

      <div class="card">
        <div style="font-weight:700;margin-bottom:10px;">Per-day schedule (overrides the default start/end)</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
          <?php foreach (['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7] as $label => $day): ?>
            <div style="border:1px solid var(--border);padding:10px;border-radius:10px;">
              <div style="font-weight:600;margin-bottom:8px;"><?= clean($label) ?></div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <label>
                  <span style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px;">Start</span>
                  <input type="time" name="day_<?= (int)$day ?>_start_time" class="form-input" value="<?= clean($schedule["day_{$day}_start_time"] ?? $schedule['start_time']) ?>" />
                </label>
                <label>
                  <span style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px;">End</span>
                  <input type="time" name="day_<?= (int)$day ?>_end_time" class="form-input" value="<?= clean($schedule["day_{$day}_end_time"] ?? $schedule['end_time']) ?>" />
                </label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card" style="display:flex;gap:16px;flex-wrap:wrap;">
        <div style="flex:1;min-width:140px;">
          <label class="form-label">Capacity (max players)</label>
          <input type="number" name="max_players" class="form-input" min="4" max="200"
                 value="<?= (int)$schedule['max_players'] ?>" required />
        </div>
        <div style="flex:1;min-width:140px;">
          <label class="form-label">Format</label>
          <select name="format" class="form-input">
            <option value="doubles" <?= $schedule['format'] === 'doubles' ? 'selected' : '' ?>>Doubles</option>
            <option value="singles" <?= $schedule['format'] === 'singles' ? 'selected' : '' ?>>Singles</option>
          </select>
        </div>
      </div>

      <div class="card">
        <label class="form-label">Registration fee (PHP)</label>
        <input type="number" name="price" class="form-input" min="0" max="100000" step="0.01"
               value="<?= number_format((float)$schedule['price'], 2, '.', '') ?>" required />
        <div style="color:var(--muted);font-size:12px;margin-top:4px;">
          Charged on every auto-posted event. Because each night's event is a brand-new
          posting — not a reuse of last night's — this fee (and payment collection) is
          fresh for every new post, and the roster starts back at zero players.
        </div>
      </div>

      <div class="card" style="display:grid;gap:14px;">
        <div style="font-weight:700;">Automation</div>
        <label style="display:flex;align-items:flex-start;gap:8px;">
          <input type="checkbox" name="auto_finalize" value="1" <?= ($schedule['auto_finalize'] ?? '1') === '1' ? 'checked' : '' ?> style="margin-top:3px;" />
          <span>
            Close each session automatically after its end time
            <span style="display:block;color:var(--muted);font-size:12px;">
              Finalizes standings and posts the podium to the leaderboard once the end time has passed and
              every game on the courts is finished — so the next post can go up. Games in progress are never cut off.
            </span>
          </span>
        </label>
        <div style="max-width:220px;">
          <label class="form-label">Grace period after end time (minutes)</label>
          <input type="number" name="finalize_grace_minutes" class="form-input" min="0" max="240"
                 value="<?= (int)($schedule['finalize_grace_minutes'] ?? 30) ?>" />
          <div style="color:var(--muted);font-size:12px;margin-top:4px;">
            Extra time before a session counts as ended, for late games and score entry.
          </div>
        </div>
        <label style="display:flex;align-items:flex-start;gap:8px;">
          <input type="checkbox" name="notify_regulars" value="1" <?= ($schedule['notify_regulars'] ?? '1') === '1' ? 'checked' : '' ?> style="margin-top:3px;" />
          <span>
            Notify last session's players when the next post goes up
            <span style="display:block;color:var(--muted);font-size:12px;">In-app notification with a link to sign up.</span>
          </span>
        </label>
      </div>

      <div class="card">
        <div style="font-weight:700;margin-bottom:6px;">Courts for Open Play</div>
        <div style="color:var(--muted);font-size:13px;margin-bottom:12px;">
          Choose all active courts, or reserve the remaining courts for reservations and other events.
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;">
          <label><input type="radio" name="court_scope" value="all" <?= ($schedule['court_scope'] ?? 'all') === 'all' ? 'checked' : '' ?> onchange="document.getElementById('open-play-court-list').hidden=true" /> All active courts</label>
          <label><input type="radio" name="court_scope" value="selected" <?= ($schedule['court_scope'] ?? 'all') === 'selected' ? 'checked' : '' ?> onchange="document.getElementById('open-play-court-list').hidden=false" /> Selected courts only</label>
        </div>
        <div id="open-play-court-list" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;" <?= ($schedule['court_scope'] ?? 'all') === 'selected' ? '' : 'hidden' ?>>
          <?php foreach ($openPlayCourts as $court): ?>
            <label style="border:1px solid var(--border);padding:10px;border-radius:8px;">
              <input type="checkbox" name="court_ids[]" value="<?= (int)$court['id'] ?>" <?= in_array((int)$court['id'], $scheduleCourtIds, true) ? 'checked' : '' ?> />
              <?= clean($court['name']) ?><?= !empty($court['short_code']) ? ' (' . clean($court['short_code']) . ')' : '' ?>
              <?php if (in_array($court['is_maintenance'], [true, 't', '1', 1], true)): ?><small style="display:block;color:var(--muted);">Maintenance</small><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if (!$openPlayCourts): ?><div style="color:var(--muted);">No active courts are configured.</div><?php endif; ?>
      </div>

      <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary">Save Settings</button>
        <button type="submit" name="clear_closed_for_date" value="1" class="btn btn-secondary" title="Un-close a date that was cancelled, so its post is created again">Re-open cancelled date</button>
        <a class="btn" href="<?= APP_URL ?>/staff/open_play_control.php">🎲 Open Play Control</a>
        <a class="btn" href="<?= APP_URL ?>/public/open_play.php">👀 View Public Page</a>
      </div>
    </form>

    <?php if ($closedForDate): ?>
      <div class="alert alert-warning" style="margin-top:18px;">
        ⚠️ The post for <?= clean($closedForDate) ?> was cancelled, so that one date is skipped.
        Every other day keeps posting automatically. Use “Re-open cancelled date” to post it again.
      </div>
    <?php endif; ?>

    <hr class="divider" style="margin:28px 0;"/>

    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <h3 style="margin:0;">Posting status</h3>
      <form method="POST" style="margin:0;">
        <?= csrfField() ?>
        <button type="submit" name="run_now" value="1" class="btn btn-sm btn-secondary">🔄 Check &amp; post now</button>
      </form>
    </div>

    <p style="font-size:13px;margin:10px 0 16px;color:<?= $workerAlive ? 'var(--muted)' : 'var(--danger, #c0392b)' ?>;">
      <?php if ($workerAlive): ?>
        ✅ Auto-poster is running (last check <?= $lastRunAgo < 90 ? (int)$lastRunAgo . 's' : (int)round($lastRunAgo / 60) . ' min' ?> ago).
      <?php elseif ($lastRunAgo === null): ?>
        ⚠️ The auto-poster hasn't run yet. Press “Check &amp; post now”, or wait a minute after the app restarts.
      <?php else: ?>
        ⚠️ The auto-poster last ran <?= (int)round($lastRunAgo / 60) ?> min ago — the background worker may be stopped. Posts still get created whenever someone opens the site.
      <?php endif; ?>
    </p>

    <?php if ($schedule['enabled'] !== '1'): ?>
      <p style="color:var(--muted);">The schedule is currently off, so nothing new will be auto-posted.</p>
    <?php endif; ?>

    <h4 style="margin:0 0 8px;">Tonight</h4>
    <?php if (!$todayEvent): ?>
      <p style="color:var(--muted);margin-top:0;">
        <?= $schedule['enabled'] === '1'
            ? 'No session is posted for tonight yet — it is created automatically within a minute.'
            : 'Nothing is posted for tonight.' ?>
      </p>
    <?php else: ?>
      <div class="card">
        <div style="font-weight:700;"><?= clean($todayEvent['name']) ?>
          <span class="badge badge-info" style="margin-left:6px;"><?= clean(ucwords(str_replace('_',' ', $todayEvent['status']))) ?></span>
        </div>
        <div style="color:var(--muted);font-size:13px;margin-top:4px;">
          <?= $todayEvent['start_date'] ? date('g:i A', strtotime($todayEvent['start_date'])) : '' ?>
          –
          <?= $todayEvent['end_date'] ? date('g:i A', strtotime($todayEvent['end_date'])) : '' ?>
        </div>
        <div style="display:flex;gap:20px;margin-top:12px;flex-wrap:wrap;">
          <div><strong><?= (int)($rosterCounts['approved'] ?? 0) ?></strong> <span style="color:var(--muted);">approved</span></div>
          <div><strong><?= (int)($rosterCounts['waitlisted'] ?? 0) ?></strong> <span style="color:var(--muted);">waitlisted</span></div>
          <div><strong><?= max(0, (int)$todayEvent['max_players'] - (int)($rosterCounts['approved'] ?? 0) - (int)($rosterCounts['waitlisted'] ?? 0)) ?></strong> <span style="color:var(--muted);">slots left</span></div>
        </div>
        <div style="margin-top:12px;">
          <a class="btn btn-sm" href="<?= APP_URL ?>/staff/open_play_control.php?tournament_id=<?= (int)$todayEvent['id'] ?>">Manage tonight's event</a>
        </div>
      </div>
    <?php endif; ?>

    <h4 style="margin:18px 0 8px;">Next post</h4>
    <?php if ($nextEvent): ?>
      <div class="card">
        <div style="font-weight:700;"><?= clean($nextEvent['name']) ?>
          <span class="badge badge-info" style="margin-left:6px;"><?= clean(ucwords(str_replace('_',' ', $nextEvent['status']))) ?></span>
        </div>
        <div style="color:var(--muted);font-size:13px;margin-top:4px;">
          <?= $nextEvent['start_date'] ? date('D, M j · g:i A', strtotime($nextEvent['start_date'])) : '' ?> — already posted and open for sign-ups.
        </div>
      </div>
    <?php else: ?>
      <p style="color:var(--muted);margin-top:0;">
        <?= $schedule['enabled'] === '1'
            ? 'Tomorrow’s post is created automatically the moment tonight’s session ends.'
            : 'Not scheduled (schedule is off).' ?>
      </p>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
