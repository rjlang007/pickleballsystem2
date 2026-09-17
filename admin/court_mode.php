<?php
// ============================================================
//  FILE: admin/court_mode.php
//  Set open play vs. reservation mode per slot / day / court
//  8PM rule is set by default in migration; owner can override
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireStaff();

$db      = getDB();
$adminId = (int)$_SESSION['user_id'];

$courts = $db->query("SELECT id, name FROM falcon.courts WHERE is_active = TRUE ORDER BY id")->fetchAll();
$selectedCourt = (int)($_GET['court'] ?? ($courts[0]['id'] ?? 1));

// ── POST: save a mode rule ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_rule') {
        $courtId    = filter_input(INPUT_POST, 'court_id',    FILTER_VALIDATE_INT);
        $ruleType   = in_array($_POST['rule_type'] ?? '', ['date','dow']) ? $_POST['rule_type'] : 'dow';
        $slotDate   = ($ruleType === 'date') ? ($_POST['slot_date'] ?? null) : null;
        $dayOfWeek  = ($ruleType === 'dow')  ? filter_input(INPUT_POST, 'day_of_week', FILTER_VALIDATE_INT) : null;
        $timeFrom   = $_POST['time_from'] ?? '00:00';
        $timeTo     = $_POST['time_to']   ?? '23:59';
        $mode       = in_array($_POST['mode'] ?? '', ['open_play','reservation']) ? $_POST['mode'] : 'open_play';
        $isWholeDay = isset($_POST['is_whole_day']);
        $note       = trim(substr($_POST['note'] ?? '', 0, 300));

        if ($isWholeDay) { $timeFrom = '00:00'; $timeTo = '23:59'; }

        if ($slotDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $slotDate)) $slotDate = null;
        if ($dayOfWeek !== null && ($dayOfWeek < 0 || $dayOfWeek > 6)) $dayOfWeek = null;

        $db->prepare("
            INSERT INTO falcon.court_slot_modes
                (court_id, slot_date, day_of_week, time_from, time_to, mode, is_whole_day, note, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $courtId, $slotDate, $dayOfWeek,
            $timeFrom . ':00', $timeTo . ':00',
            $mode, $isWholeDay, $note ?: null, $adminId
        ]);

        // Notify all players if switching a slot to open play
        if ($mode === 'open_play') {
            $players = $db->query("SELECT id FROM falcon.users WHERE role = 'player' AND is_banned = FALSE")->fetchAll();
            $label   = $isWholeDay
                ? 'All day'
                : date('g:i A', strtotime('2000-01-01 ' . $timeFrom)) . ' – ' . date('g:i A', strtotime('2000-01-01 ' . $timeTo));
            $dateLabel = $slotDate ? date('M d, Y', strtotime($slotDate)) : ('Every ' . ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][$dayOfWeek ?? 0]);
            $notifStmt = $db->prepare("
                INSERT INTO falcon.notifications (user_id, title, message, type, created_at)
                VALUES (?, '🎮 Open Play Announced', ?, 'info', NOW())
            ");
            foreach ($players as $p) {
                $notifStmt->execute([
                    $p['id'],
                    "Open play has been set for {$dateLabel} ({$label}) at court #" . $courtId . ". Walk-ins welcome!",
                ]);
            }
        }

        setFlash('success', '✅ Court mode rule saved.');
        redirect('admin/court_mode.php?court=' . $courtId);
    }

    if ($action === 'delete_rule') {
        $ruleId = filter_input(INPUT_POST, 'rule_id', FILTER_VALIDATE_INT);
        if ($ruleId) {
            $db->prepare("DELETE FROM falcon.court_slot_modes WHERE id = ?")->execute([$ruleId]);
        }
        setFlash('success', '🗑 Rule deleted.');
        redirect('admin/court_mode.php?court=' . $courtId);
    }
}

// ── Load existing rules ───────────────────────────────────
$rulesStmt = $db->prepare("
    SELECT m.*, u.username AS created_by_name
    FROM falcon.court_slot_modes m
    LEFT JOIN falcon.users u ON u.id = m.created_by
    WHERE m.court_id = ?
    ORDER BY
        CASE WHEN m.slot_date IS NOT NULL THEN 0 ELSE 1 END,
        m.slot_date DESC,
        m.day_of_week ASC,
        m.time_from ASC
");
$rulesStmt->execute([$selectedCourt]);
$rules = $rulesStmt->fetchAll();

// Upcoming open play reminders (reservations within 8PM slots)
$affectedRes = $db->prepare("
    SELECT r.id, r.slot_date, r.slot_time, r.slot_end, u.full_name, u.username
    FROM falcon.reservations r
    JOIN falcon.users u ON u.id = r.user_id
    JOIN falcon.court_slot_modes m ON m.court_id = r.court_id
    WHERE r.court_id = ?
      AND r.status IN ('pending','confirmed')
      AND r.slot_date >= CURRENT_DATE
      AND m.mode = 'open_play'
      AND (
          (m.slot_date = r.slot_date AND r.slot_time >= m.time_from AND r.slot_time < m.time_to)
          OR (m.slot_date IS NULL AND EXTRACT(DOW FROM r.slot_date) = m.day_of_week AND r.slot_time >= m.time_from AND r.slot_time < m.time_to)
      )
    ORDER BY r.slot_date, r.slot_time
    LIMIT 10
");
$affectedRes->execute([$selectedCourt]);
$affected = $affectedRes->fetchAll();

$dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

$pageTitle = 'Court Mode Settings';
require_once __DIR__ . '/../includes/header.php';
$flash = getFlash();
?>

<style nonce="<?= getCspNonce() ?>">
.cm-layout { display:grid; grid-template-columns:1fr 340px; gap:24px; }
@media(max-width:900px){ .cm-layout{grid-template-columns:1fr;} }

.mode-badge {
    display:inline-flex; align-items:center; gap:5px;
    font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
    padding:4px 11px; border-radius:20px;
}
.mode-badge.open_play   { background:rgba(0,229,160,.12); color:var(--accent); border:1px solid rgba(0,229,160,.3); }
.mode-badge.reservation { background:rgba(0,184,255,.12); color:var(--accent2); border:1px solid rgba(0,184,255,.3); }

.rule-card {
    background:var(--surface2); border:1px solid var(--border);
    border-radius:12px; padding:14px 16px; margin-bottom:10px;
    display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;
}
.rule-card.open-play-rule  { border-left:3px solid var(--accent); }
.rule-card.reservation-rule{ border-left:3px solid var(--accent2); }

.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:12px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px; }
.form-group input, .form-group select, .form-group textarea {
    width:100%; background:var(--surface); border:1.5px solid var(--border);
    border-radius:9px; padding:10px 13px; color:var(--text); font-size:15px; font-family:inherit;
    transition:border-color .15s; -webkit-appearance:none;
}
.form-group input:focus, .form-group select:focus { border-color:var(--accent); outline:none; }
.form-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media(max-width:480px){ .form-row-2{grid-template-columns:1fr;} }
</style>

<div class="page-header flex-between">
    <div>
        <h1>Court Mode Settings</h1>
        <p>Set when slots are open play vs. reservation only</p>
    </div>
    <div>
        <a href="<?= APP_URL ?>/admin/schedule.php" class="btn-outline btn-sm">📅 Schedule</a>
        <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= $flash['type']==='success'?'success':'error' ?>" style="margin-bottom:16px;border-radius:10px;">
    <?= clean($flash['message']) ?>
</div>
<?php endif; ?>

<!-- Court selector -->
<?php if (count($courts) > 1): ?>
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <?php foreach ($courts as $c): ?>
        <a href="?court=<?= $c['id'] ?>"
           class="tab-link <?= $c['id']==$selectedCourt?'active':'' ?>">
            🏓 <?= clean($c['name']) ?>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Info banner -->
<div style="background:rgba(0,229,160,.06);border:1px solid rgba(0,229,160,.2);border-radius:12px;padding:13px 18px;margin-bottom:22px;font-size:13px;color:var(--muted);line-height:1.7;">
    💡 <strong style="color:var(--text);">8PM Rule:</strong> By default, every day from 8:00 PM onward is set to
    <span style="color:var(--accent);font-weight:700;">Open Play</span>.
    You can override this for specific dates or days. Rules with a specific date take priority over day-of-week rules.
</div>

<?php if (!empty($affected)): ?>
<div style="background:rgba(245,158,11,.08);border:1px solid var(--warn);border-radius:12px;padding:13px 18px;margin-bottom:22px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:8px;">⚠️ <?= count($affected) ?> existing reservation<?= count($affected)!==1?'s':'' ?> overlap with Open Play rules</div>
    <?php foreach ($affected as $r): ?>
        <div style="font-size:12px;color:var(--muted);margin-bottom:4px;">
            <?= clean($r['full_name']) ?> (@<?= clean($r['username']) ?>) — <?= date('M d', strtotime($r['slot_date'])) ?> at <?= date('g:i A', strtotime($r['slot_time'])) ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="cm-layout">

    <!-- LEFT: Add rule form -->
    <div>
        <div class="card mb-3">
            <div class="card-title">+ Add Mode Rule</div>
            <div class="card-subtitle">Override the default mode for a specific date or recurring day</div>
            <hr class="divider"/>

            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action"   value="save_rule">
                <input type="hidden" name="court_id" value="<?= $selectedCourt ?>">

                <!-- Rule type -->
                <div class="form-group">
                    <label>Rule Type</label>
                    <select name="rule_type" id="rule-type-sel" onchange="toggleRuleType(this.value)">
                        <option value="dow">Recurring (every day of week)</option>
                        <option value="date">Specific date (one-time override)</option>
                    </select>
                </div>

                <!-- Day of week -->
                <div class="form-group" id="dow-field">
                    <label>Day of Week</label>
                    <select name="day_of_week">
                        <?php foreach ($dayNames as $i => $d): ?>
                            <option value="<?= $i ?>"><?= $d ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Specific date -->
                <div class="form-group" id="date-field" style="display:none;">
                    <label>Specific Date</label>
                    <input type="date" name="slot_date" min="<?= date('Y-m-d') ?>">
                </div>

                <!-- Whole day toggle -->
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:11px 13px;background:var(--surface2);border:1px solid var(--border);border-radius:9px;">
                    <input type="checkbox" name="is_whole_day" id="whole-day-cb" onchange="toggleWholeDay(this.checked)" style="width:18px;height:18px;accent-color:var(--accent);cursor:pointer;">
                    <label for="whole-day-cb" style="font-size:14px;font-weight:600;cursor:pointer;">Whole day rule (overrides all time slots)</label>
                </div>

                <!-- Time range -->
                <div class="form-row-2" id="time-range-fields">
                    <div class="form-group">
                        <label>From Time</label>
                        <input type="time" name="time_from" value="20:00">
                    </div>
                    <div class="form-group">
                        <label>To Time</label>
                        <input type="time" name="time_to" value="23:59">
                    </div>
                </div>

                <!-- Mode -->
                <div class="form-group">
                    <label>Mode</label>
                    <select name="mode">
                        <option value="open_play" selected>🎮 Open Play (walk-in, no reservation needed)</option>
                        <option value="reservation">📅 Reservation Only</option>
                    </select>
                </div>

                <!-- Note -->
                <div class="form-group">
                    <label>Note <span style="color:var(--muted);font-weight:400;">(optional)</span></label>
                    <input type="text" name="note" maxlength="300" placeholder="e.g. League night, maintenance, etc.">
                </div>

                <button type="submit" class="btn-primary" style="width:100%;padding:13px;font-size:14px;">
                    💾 Save Rule
                </button>
            </form>
        </div>

        <!-- Existing rules -->
        <div class="card">
            <div class="card-title">📋 Active Rules</div>
            <div class="card-subtitle"><?= count($rules) ?> rule<?= count($rules)!==1?'s':'' ?> for this court</div>
            <hr class="divider"/>

            <?php if (empty($rules)): ?>
                <div style="text-align:center;padding:28px;color:var(--muted);font-size:13px;">
                    No custom rules yet. The default 8PM open play rule applies.
                </div>
            <?php else: ?>
                <?php foreach ($rules as $r):
                    $isDate = ($r['slot_date'] !== null);
                    $label  = $isDate
                        ? date('D, M d Y', strtotime($r['slot_date']))
                        : 'Every ' . $dayNames[$r['day_of_week']] ?? 'Unknown';
                    $timeLbl = $r['is_whole_day']
                        ? 'All day'
                        : date('g:i A', strtotime('2000-01-01 '.$r['time_from'])).' – '.date('g:i A', strtotime('2000-01-01 '.$r['time_to']));
                ?>
                <div class="rule-card <?= $r['mode'] === 'open_play' ? 'open-play-rule' : 'reservation-rule' ?>">
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:700;font-size:13px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span class="mode-badge <?= $r['mode'] ?>">
                                <?= $r['mode'] === 'open_play' ? '🎮 Open Play' : '📅 Reservation' ?>
                            </span>
                            <span><?= clean($label) ?></span>
                            <?php if ($isDate): ?><span class="badge badge-info" style="font-size:9px;">One-time</span><?php endif; ?>
                        </div>
                        <div style="font-size:12px;color:var(--muted);margin-top:4px;">
                            ⏰ <?= $timeLbl ?>
                            <?php if ($r['note']): ?> · <?= clean($r['note']) ?><?php endif; ?>
                        </div>
                        <div style="font-size:10px;color:var(--muted);margin-top:2px;">
                            Added by <?= clean($r['created_by_name'] ?? 'admin') ?> · <?= date('M d, Y', strtotime($r['created_at'])) ?>
                        </div>
                    </div>
                    <form method="POST" style="flex-shrink:0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"   value="delete_rule">
                        <input type="hidden" name="rule_id"  value="<?= $r['id'] ?>">
                        <input type="hidden" name="court_id" value="<?= $selectedCourt ?>">
                        <button type="submit" class="btn-danger btn-sm"
                                onclick="return confirm('Delete this rule?')">🗑</button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: Info sidebar -->
    <div style="display:flex;flex-direction:column;gap:18px;">

        <div class="card" style="border-color:rgba(0,229,160,.2);">
            <div class="card-title">🏓 How It Works</div>
            <hr class="divider"/>
            <div style="font-size:13px;color:var(--muted);line-height:1.9;">
                <div style="margin-bottom:8px;">
                    <span class="mode-badge open_play">🎮 Open Play</span>
                    <span style="display:block;margin-top:4px;">Walk-in players scan their QR. No reservation needed. First-come first-served queue.</span>
                </div>
                <div>
                    <span class="mode-badge reservation">📅 Reservation</span>
                    <span style="display:block;margin-top:4px;">Players must reserve in advance. Only confirmed reservation holders can scan in during this slot.</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">⚡ Default Rules</div>
            <hr class="divider"/>
            <div style="font-size:13px;color:var(--muted);line-height:1.8;">
                <div style="padding:8px 10px;background:var(--surface2);border-radius:8px;margin-bottom:8px;border-left:3px solid var(--accent);">
                    <strong style="color:var(--accent);">8PM Rule</strong><br>
                    Every day 8:00 PM – Midnight → Open Play<br>
                    <span style="font-size:11px;opacity:.7;">Set by migration, applies to all courts</span>
                </div>
                <div style="padding:8px 10px;background:var(--surface2);border-radius:8px;border-left:3px solid var(--accent2);">
                    <strong style="color:var(--accent2);">Default (all other times)</strong><br>
                    Reservation-based booking
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">📢 Player Notifications</div>
            <hr class="divider"/>
            <div style="font-size:13px;color:var(--muted);line-height:1.7;">
                When you change a slot to <strong style="color:var(--accent);">Open Play</strong>,
                all active players are automatically notified via their notification bell.
                <br><br>
                When slots are near 8PM, a reminder is sent to all players who have been active that day.
            </div>
        </div>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
function toggleRuleType(val) {
    document.getElementById('dow-field').style.display  = val === 'dow'  ? 'block' : 'none';
    document.getElementById('date-field').style.display = val === 'date' ? 'block' : 'none';
}
function toggleWholeDay(checked) {
    document.getElementById('time-range-fields').style.display = checked ? 'none' : 'grid';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>