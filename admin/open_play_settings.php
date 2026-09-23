<?php
// ============================================================
//  FILE: admin/open_play_settings.php
//  Admin controls for the recurring nightly Open Play schedule.
//  The actual auto-posting/creation logic lives in
//  tournament/open_play_scheduler.php (ensureNightlyOpenPlayEvent(),
//  self-healing on page load — same pattern as auto_end_games.php).
//  This page only reads/writes the settings it acts on.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../tournament/open_play_scheduler.php';
requireStaff();

$db      = getDB();
$adminId = (int)$_SESSION['user_id'];
$errors  = [];

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
$scheduleCourtIds = array_map('intval', json_decode($schedule['court_ids'] ?? '[]', true) ?: []);

// Today's auto-created (or manually created) Open Play event, if any —
// shown so the admin can see the schedule is actually firing. Uses the
// same 4 AM business-day cutover as ensureNightlyOpenPlayEvent(), so this
// still shows last night's event (not a not-yet-created "tomorrow") when
// checked between midnight and 4 AM.
$businessDate = openPlayBusinessDate();
$todayEvent = $db->prepare("
    SELECT id, name, status, start_date, end_date, max_players
      FROM falcon.tournaments
     WHERE bracket_type = 'open_play'
       AND DATE(start_date) = :bdate
       AND status != 'cancelled'
     ORDER BY id DESC LIMIT 1
");
$todayEvent->execute([':bdate' => $businessDate]);
$todayEvent = $todayEvent->fetch(PDO::FETCH_ASSOC);

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
        Control the recurring nightly Open Play post — what time it runs,
        how many players it holds, and whether it's posted at all.
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
            When on, an Open Play event is automatically posted every day.
            Turn this off for a night the whole venue is rented out privately —
            it just stops new nights from being posted; it won't touch tonight's
            event if one already exists.
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
        <button type="submit" name="clear_closed_for_date" value="1" class="btn btn-secondary">Re-open schedule</button>
        <a class="btn" href="<?= APP_URL ?>/staff/open_play_control.php">🎲 Open Play Control</a>
        <a class="btn" href="<?= APP_URL ?>/public/open_play.php">👀 View Public Page</a>
      </div>
    </form>

    <?php if ($closedForDate): ?>
      <div class="alert alert-warning" style="margin-top:18px;">
        ⚠️ The nightly schedule is currently closed from <?= clean($closedForDate) ?> onward. Re-open the schedule to resume automatic posting.
      </div>
    <?php endif; ?>

    <hr class="divider" style="margin:28px 0;"/>

    <h3 style="margin-top:0;">Tonight's status</h3>
    <?php if (!$todayEvent): ?>
      <p style="color:var(--muted);">
        <?= $schedule['enabled'] === '1'
            ? 'No event has been posted for today yet — it will be created automatically the next time anyone loads the site (checked at most every 5 minutes).'
            : 'The nightly schedule is currently off, so nothing will be auto-posted today.' ?>
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
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
