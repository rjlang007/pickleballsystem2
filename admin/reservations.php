<?php
// ============================================================
//  FILE: admin/reservations.php
//  FIXED: Fully responsive — res-grid, modal, sync bar,
//         availability matrix, reservation cards
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/availability.php';
requireAdmin();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $s = fn($v) => trim(htmlspecialchars(strip_tags($v ?? ''), ENT_QUOTES));

    switch ($action) {
        case 'create_reservation':
        case 'update_reservation':
            $existingId = (int)($_POST['reservation_id'] ?? 0);
            $data = [
                ':court_id'         => (int)$_POST['court_id'],
                ':reservation_date' => $s($_POST['reservation_date']),
                ':slot_start'       => $s($_POST['slot_start']),
                ':slot_end'         => $s($_POST['slot_end']),
                ':label'            => $s($_POST['label']) ?: 'Reserved',
                ':notes'            => $s($_POST['notes']),
                ':status'           => in_array($_POST['status'], ['pending','confirmed','cancelled'])
                                       ? $_POST['status'] : 'confirmed',
                ':reserved_by'      => (int)$_SESSION['user_id'],
            ];
            $result = upsertReservation($db, $data, $existingId);
            if ($result['ok']) {
                try { $db->exec("NOTIFY availability_changed, 'reservation_upserted'"); } catch (Exception $e) {}
            }
            echo json_encode($result);
            exit;

        case 'cancel_reservation':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Invalid ID']); exit; }
            $db->prepare("UPDATE falcon.court_reservations SET status='cancelled', updated_at=NOW() WHERE id=?")->execute([$id]);
            syncScheduleSlotStatus($db, date('Y-m-d'));
            try { $db->exec("NOTIFY availability_changed, 'reservation_cancelled'"); } catch(Exception $e){}
            echo json_encode(['ok'=>true,'msg'=>'Reservation cancelled.']);
            exit;

        case 'delete_reservation':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Invalid ID']); exit; }
            $resDate = $db->prepare("SELECT reservation_date FROM falcon.court_reservations WHERE id=?");
            $resDate->execute([$id]);
            $d = $resDate->fetchColumn();
            $db->prepare("DELETE FROM falcon.court_reservations WHERE id=?")->execute([$id]);
            if ($d) syncScheduleSlotStatus($db, $d);
            try { $db->exec("NOTIFY availability_changed, 'reservation_deleted'"); } catch(Exception $e){}
            echo json_encode(['ok'=>true,'msg'=>'Reservation deleted.']);
            exit;

        case 'force_sync':
            syncScheduleSlotStatus($db, date('Y-m-d'));
            echo json_encode(['ok'=>true,'msg'=>'Schedule slots synced.']);
            exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown action.']);
    exit;
}

$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) $selectedDate = date('Y-m-d');

$courts       = $db->query("SELECT * FROM falcon.courts ORDER BY id")->fetchAll();
$availability = getAvailabilityForDate($db, $selectedDate);

$resStmt = $db->prepare("
    SELECT r.*, c.name AS court_name,
           u.full_name AS reserver_name, u.username AS reserver_username
    FROM falcon.court_reservations r
    JOIN falcon.courts c ON c.id = r.court_id
    LEFT JOIN falcon.users u ON u.id = r.reserved_by
    WHERE r.reservation_date = ?
    ORDER BY r.slot_start, c.id
");
$resStmt->execute([$selectedDate]);
$reservations = $resStmt->fetchAll();

$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$isToday  = ($selectedDate === date('Y-m-d'));

$activeGames = (int)$db->query("SELECT COUNT(*) FROM falcon.game_sessions WHERE status='active'")->fetchColumn();
$queueNow    = (int)$db->query("SELECT COUNT(*) FROM falcon.game_queue WHERE session_id IS NULL")->fetchColumn();
$openCourts  = max(0, count($courts) - $activeGames);

$allTimes = [];
for ($h = 6; $h <= 23; $h++) {
    $allTimes[sprintf('%02d:00:00', $h)] = date('g:00 A', mktime($h, 0, 0));
    $allTimes[sprintf('%02d:30:00', $h)] = date('g:30 A', mktime($h, 30, 0));
}
$allTimes['00:00:00'] = '12:00 AM (midnight)';

$pageTitle = 'Court Reservations';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Reservations page — responsive only ───────────────────── */

/* Availability matrix scrolls horizontally on small screens */
.avail-matrix-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: var(--border) transparent;
    border-radius: 8px;
}
.avail-matrix-wrap::-webkit-scrollbar { height: 4px; }
.avail-matrix-wrap::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
.avail-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13px;
    min-width: 420px;
}
.avail-table th {
    background: var(--surface2);
    padding: 10px 12px;
    font-weight: 700;
    text-align: center;
    color: var(--muted);
    font-size: 11px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    white-space: nowrap;
    border-bottom: 1px solid var(--border);
}
.avail-table th:first-child { text-align: left; }
.avail-table td {
    padding: 9px 10px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    text-align: center;
    vertical-align: middle;
    min-width: 0;
}
.avail-table td:first-child { text-align: left; font-weight: 600; white-space: nowrap; font-size: 12px; }
.avail-table tr:hover td { background: rgba(0,229,160,0.03); }

/* Cell chips */
.avail-cell {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 8px; border-radius: 7px;
    font-size: 11px; font-weight: 700; letter-spacing: 0.04em;
    cursor: default; white-space: nowrap;
}
.cell-open     { background: rgba(0,229,160,0.1);   color: #00e5a0; border: 1px solid rgba(0,229,160,0.25); }
.cell-filling  { background: rgba(245,158,11,0.1);  color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); }
.cell-full     { background: rgba(239,68,68,0.1);   color: #f87171; border: 1px solid rgba(239,68,68,0.25); }
.cell-reserved { background: rgba(245,158,11,0.12); color: #fbbf24; border: 1px solid rgba(245,158,11,0.4); cursor: pointer; }
.cell-active   { background: rgba(0,184,255,0.1);   color: #00b8ff; border: 1px solid rgba(0,184,255,0.3); }
.cell-closed   { background: rgba(107,127,163,0.1); color: #6b7fa3; border: 1px solid var(--border); }
.cell-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; flex-shrink: 0; }

.cell-add-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; border-radius: 6px;
    background: rgba(0,229,160,0.1); border: 1px dashed rgba(0,229,160,0.3);
    color: var(--accent); font-size: 15px; cursor: pointer; transition: all 0.2s;
    touch-action: manipulation;
}
.cell-add-btn:hover { background: rgba(0,229,160,0.2); border-style: solid; }

/* Reservation cards */
.res-card {
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: 12px; padding: 14px; margin-bottom: 10px;
    transition: border-color 0.2s; min-width: 0; overflow: hidden; word-break: break-word;
}
.res-card:hover { border-color: rgba(0,229,160,0.3); }
.res-card-header {
    display: flex; align-items: flex-start;
    justify-content: space-between; gap: 8px; margin-bottom: 8px; flex-wrap: wrap;
}
.res-card-label { font-weight: 700; font-size: 14px; }
.res-card-time  { font-family: monospace; font-size: 12px; color: var(--accent); margin-top: 2px; }
.res-card-meta  { font-size: 12px; color: var(--muted); margin-top: 2px; }
.res-card-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }

/* Today's pulse mini grid */
.pulse-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.pulse-item {
    background: var(--surface2); border-radius: 10px;
    padding: 14px; text-align: center;
}
.pulse-val { font-family: 'Bebas Neue',sans-serif; font-size: clamp(24px,4vw,32px); }
.pulse-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.08em; margin-top: 2px; }

/* Sync bar */
.sync-bar {
    display: flex; align-items: center; gap: 10px;
    background: rgba(0,229,160,0.06); border: 1px solid rgba(0,229,160,0.15);
    border-radius: 10px; padding: 10px 16px; font-size: 13px; margin-bottom: 20px; flex-wrap: wrap;
}
.sync-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); animation: pulse 2s infinite; flex-shrink: 0; }
.sync-time { color: var(--muted); margin-left: auto; font-family: monospace; font-size: 11px; white-space: nowrap; }
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.2)} }

/* Legend */
.legend { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
.legend-item { display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--muted); }
.legend-dot { width: 9px; height: 9px; border-radius: 3px; flex-shrink: 0; }

/* Date nav */
.date-nav-bar {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 12px;
}
.date-nav-bar form { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; flex: 1; min-width: 0; }
.date-nav-bar .quick-btns { display: flex; gap: 6px; flex-wrap: wrap; }

/* Form row inside modal */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 480px) { .form-row { grid-template-columns: 1fr; } }

/* Toast */
#toast {
    position: fixed; bottom: max(28px,env(safe-area-inset-bottom)); right: 28px; z-index: 9999;
    background: var(--surface); border: 1px solid var(--accent); border-radius: 12px;
    padding: 12px 20px; font-size: 14px; font-weight: 600; color: var(--accent);
    box-shadow: 0 8px 30px rgba(0,229,160,0.2);
    opacity: 0; transform: translateY(12px); transition: opacity 0.3s,transform 0.3s; pointer-events: none;
    max-width: calc(100vw - 56px); word-break: break-word;
}
#toast.show  { opacity: 1; transform: translateY(0); }
#toast.error { border-color: #f87171; color: #f87171; }
@media (max-width: 480px) { #toast { right: 16px; left: 16px; max-width: unset; } }

/* Main res-grid collapses at 900px */
.res-grid {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 24px;
    margin-bottom: 24px;
    min-width: 0;
}
@media (max-width: 900px) { .res-grid { grid-template-columns: 1fr; } }
</style>

<!-- ── Page Header ── -->
<div class="page-header flex-between">
    <div>
        <h1>Court Reservations</h1>
        <p>Manage court availability · <strong style="color:var(--accent);"><?= date('l, F j, Y', strtotime($selectedDate)) ?></strong></p>
    </div>
    <div>
        <button class="btn-primary btn-sm" onclick="openModal()">＋ Add Reservation</button>
        <a href="?date=<?= date('Y-m-d') ?>" class="btn-outline btn-sm">Today</a>
    </div>
</div>

<!-- ── Sync Status Bar ── -->
<div class="sync-bar">
    <span class="sync-dot"></span>
    <span id="syncStatus">Live — synced across all views</span>
    <button class="btn-outline btn-sm" onclick="forceSync()">🔄 Sync</button>
    <span class="sync-time" id="syncTime"><?= date('h:i:s A') ?></span>
</div>

<!-- Date nav -->
<div class="date-nav-bar">
    <a href="?date=<?= $prevDate ?>" class="btn-outline btn-sm">← Prev</a>
    <form method="GET">
        <input type="date" name="date" value="<?= $selectedDate ?>" style="max-width:180px;"/>
        <button type="submit" class="btn-primary btn-sm">Go</button>
    </form>
    <div class="quick-btns">
        <a href="?date=<?= $nextDate ?>" class="btn-outline btn-sm">Next →</a>
    </div>
</div>

<!-- ── Legend ── -->
<div class="legend">
    <div class="legend-item"><div class="legend-dot" style="background:rgba(0,229,160,0.5);"></div>Open</div>
    <div class="legend-item"><div class="legend-dot" style="background:rgba(245,158,11,0.6);"></div>Filling</div>
    <div class="legend-item"><div class="legend-dot" style="background:rgba(239,68,68,0.5);"></div>Full</div>
    <div class="legend-item"><div class="legend-dot" style="background:rgba(245,158,11,0.8);border:1px dashed #fbbf24;"></div>Reserved</div>
    <div class="legend-item"><div class="legend-dot" style="background:rgba(0,184,255,0.5);"></div>Live Game</div>
    <div class="legend-item"><div class="legend-dot" style="background:rgba(107,127,163,0.4);"></div>Closed</div>
</div>

<!-- ── Main Content ── -->
<div class="res-grid">

    <!-- LEFT: Availability Matrix -->
    <div class="card">
        <div class="flex-between mb-2">
            <div>
                <div class="card-title">📊 Availability Matrix</div>
                <div class="card-subtitle">Tap <strong>＋</strong> on open cell to add a reservation</div>
            </div>
            <?php if ($isToday): ?>
                <span style="font-size:12px;color:var(--accent);font-family:monospace;flex-shrink:0;">🔴 LIVE</span>
            <?php endif; ?>
        </div>
        <hr class="divider"/>

        <?php if (empty($courts)): ?>
            <p class="text-muted">No courts configured.</p>
        <?php else: ?>
        <div class="avail-matrix-wrap">
            <table class="avail-table" id="availTable">
                <thead>
                    <tr>
                        <th>Time Slot</th>
                        <?php foreach ($courts as $court): ?>
                            <th><?= clean($court['name']) ?></th>
                        <?php endforeach; ?>
                        <th>Queue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $bySlot = [];
                    foreach ($availability as $a) {
                        $key = $a['slot_id'] . '_' . $a['time_label'];
                        $bySlot[$key][$a['court_id']] = $a;
                    }
                    $slotKeys = [];
                    foreach ($availability as $a) {
                        $key = $a['slot_id'] . '_' . $a['time_label'];
                        if (!isset($slotKeys[$key])) $slotKeys[$key] = $a['sort_order'];
                    }
                    asort($slotKeys);
                    foreach ($slotKeys as $key => $sortOrder):
                        $timeParts  = explode('_', $key, 2);
                        $slotId     = $timeParts[0];
                        $timeLabel  = $timeParts[1] ?? '';
                        $firstSlot  = array_values($bySlot[$key])[0] ?? null;
                        $queueCount = $firstSlot['queue_count'] ?? 0;
                    ?>
                    <tr>
                        <td><strong style="font-size:12px;"><?= clean($timeLabel) ?></strong></td>
                        <?php foreach ($courts as $court):
                            $cell     = $bySlot[$key][$court['id']] ?? null;
                            $status   = $cell['status'] ?? 'closed';
                            $cssClass = match($status) {
                                'open'    =>'cell-open','filling'=>'cell-filling',
                                'full'    =>'cell-full','reserved'=>'cell-reserved',
                                'active'  =>'cell-active',default=>'cell-closed'
                            };
                        ?>
                        <td>
                            <?php if ($cell && in_array($status, ['open','filling']) && $selectedDate >= date('Y-m-d')): ?>
                                <button class="cell-add-btn"
                                        onclick="openModal(<?= $court['id'] ?>,'<?= clean($court['name']) ?>','<?= clean($timeLabel) ?>')"
                                        title="Add reservation">＋</button>
                            <?php elseif ($cell && $status==='reserved' && $cell['reservation']): ?>
                                <span class="avail-cell <?= $cssClass ?>"
                                      onclick="editReservation(<?= htmlspecialchars(json_encode($cell['reservation']), ENT_QUOTES) ?>)"
                                      title="<?= clean($cell['reservation']['notes']??'') ?>">
                                    <span class="cell-dot"></span><?= clean($cell['reservation']['label']??'Reserved') ?>
                                </span>
                            <?php elseif ($cell && $status==='active' && $cell['active_game']): ?>
                                <span class="avail-cell <?= $cssClass ?>"
                                      title="Session #<?= $cell['active_game']['id'] ?> · <?= $cell['active_game']['player_count'] ?> players">
                                    <span class="cell-dot"></span>Live
                                </span>
                            <?php else: ?>
                                <span class="avail-cell <?= $cssClass ?>">
                                    <span class="cell-dot"></span><?= $cell['status_label']??'Closed' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td>
                            <?php if ($queueCount > 0): ?>
                                <span style="font-size:12px;color:var(--accent2);font-weight:700;">👥 <?= $queueCount ?></span>
                            <?php else: ?>
                                <span style="color:var(--muted);font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- RIGHT: Sidebar -->
    <div style="display:flex;flex-direction:column;gap:20px;min-width:0;">

        <!-- Reservation cards -->
        <div class="card">
            <div class="card-title">🔒 Reservations</div>
            <div class="card-subtitle"><?= count($reservations) ?> block<?= count($reservations)!==1?'s':'' ?> today</div>
            <hr class="divider"/>

            <?php if (empty($reservations)): ?>
                <div style="text-align:center;padding:24px;">
                    <div style="font-size:36px;margin-bottom:8px;">📋</div>
                    <p class="text-muted" style="font-size:13px;">No reservations on this day.</p>
                    <button class="btn-primary btn-sm" onclick="openModal()" style="margin-top:12px;">＋ Add First</button>
                </div>
            <?php else: ?>
                <?php foreach ($reservations as $res):
                    $statusColors = ['confirmed'=>'success','pending'=>'warn','cancelled'=>'danger'];
                    $sc = $statusColors[$res['status']] ?? 'muted';
                    $startFmt = date('g:i A', strtotime($res['slot_start']));
                    $endFmt   = date('g:i A', strtotime($res['slot_end']));
                ?>
                <div class="res-card">
                    <div class="res-card-header">
                        <div style="min-width:0;flex:1;">
                            <div class="res-card-label"><?= clean($res['label']) ?></div>
                            <div class="res-card-time"><?= $startFmt ?> – <?= $endFmt ?></div>
                            <div class="res-card-meta">
                                🏟 <?= clean($res['court_name']) ?>
                                <?php if ($res['reserver_username']): ?>· @<?= clean($res['reserver_username']) ?><?php endif; ?>
                            </div>
                            <?php if ($res['notes']): ?>
                                <div style="font-size:11px;color:var(--muted);margin-top:3px;font-style:italic;"><?= clean($res['notes']) ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="badge badge-<?= $sc ?>" style="flex-shrink:0;"><?= ucfirst($res['status']) ?></span>
                    </div>
                    <div class="res-card-actions">
                        <button class="btn-outline btn-sm"
                                onclick='editReservation(<?= htmlspecialchars(json_encode($res), ENT_QUOTES) ?>)'>✏️ Edit</button>
                        <?php if ($res['status'] !== 'cancelled'): ?>
                        <button class="btn-outline btn-sm"
                                onclick="cancelReservation(<?= $res['id'] ?>)"
                                style="color:var(--danger);border-color:rgba(248,113,113,0.3);">✕ Cancel</button>
                        <?php endif; ?>
                        <button class="btn-danger btn-sm" onclick="deleteReservation(<?= $res['id'] ?>)">🗑</button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Today's Pulse -->
        <div class="card">
            <div class="card-title">📈 Today's Pulse</div>
            <hr class="divider"/>
            <div class="pulse-grid">
                <div class="pulse-item"><div class="pulse-val" style="color:var(--accent);"><?= $activeGames ?></div><div class="pulse-label">Active Games</div></div>
                <div class="pulse-item"><div class="pulse-val" style="color:var(--accent2);"><?= $queueNow ?></div><div class="pulse-label">In Queue</div></div>
                <div class="pulse-item"><div class="pulse-val"><?= $openCourts ?></div><div class="pulse-label">Open Courts</div></div>
                <div class="pulse-item"><div class="pulse-val" style="color:#fbbf24;"><?= count($reservations) ?></div><div class="pulse-label">Reservations</div></div>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal: Add / Edit Reservation ── -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal-box" style="max-width:520px;max-height:90vh;max-height:90dvh;overflow-y:auto;">
        <div style="position:relative;margin-bottom:16px;">
            <div style="font-family:'Bebas Neue',sans-serif;font-size:clamp(20px,5vw,26px);letter-spacing:0.04em;" id="modalTitle">New Reservation</div>
            <div style="font-size:13px;color:var(--muted);">Block a court time slot from player booking.</div>
            <button onclick="closeModal()"
                    style="position:absolute;top:0;right:0;width:32px;height:32px;background:var(--surface2);
                           border:1px solid var(--border);border-radius:8px;cursor:pointer;
                           color:var(--muted);font-size:16px;display:flex;align-items:center;justify-content:center;
                           touch-action:manipulation;">✕</button>
        </div>

        <form id="reservationForm">
            <input type="hidden" name="action" id="formAction" value="create_reservation">
            <input type="hidden" name="reservation_id" id="reservationId" value="0">
            <?= csrfField() ?>

            <div class="form-row">
                <div class="form-group">
                    <label>Court</label>
                    <select name="court_id" id="fieldCourtId" required>
                        <option value="">— Select —</option>
                        <?php foreach ($courts as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= clean($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="reservation_date" id="fieldDate" value="<?= $selectedDate ?>" required min="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Start Time</label>
                    <select name="slot_start" id="fieldSlotStart" required>
                        <option value="">— Start —</option>
                        <?php foreach ($allTimes as $val => $disp): ?>
                            <option value="<?= $val ?>"><?= $disp ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>End Time</label>
                    <select name="slot_end" id="fieldSlotEnd" required>
                        <option value="">— End —</option>
                        <?php foreach ($allTimes as $val => $disp): ?>
                            <option value="<?= $val ?>"><?= $disp ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Label</label>
                <input type="text" name="label" id="fieldLabel" placeholder="Tournament Setup, Maintenance…" required>
            </div>
            <div class="form-group">
                <label>Notes (optional)</label>
                <textarea name="notes" id="fieldNotes" rows="2" style="resize:vertical;" placeholder="Extra info…"></textarea>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" id="fieldStatus">
                    <option value="confirmed">✅ Confirmed</option>
                    <option value="pending">⏳ Pending</option>
                    <option value="cancelled">✕ Cancelled</option>
                </select>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;flex-wrap:wrap;">
                <button type="button" class="btn-outline" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn-primary" id="submitBtn">Save Reservation</button>
            </div>
        </form>
    </div>
</div>

<div id="toast"></div>

<script nonce="<?= getCspNonce() ?>">
const APP_URL      = '<?= APP_URL ?>';
const SELECTED_DATE = '<?= $selectedDate ?>';
// Every ad-hoc FormData() below (cancel/delete/forceSync) posts back to this
// same page, so each needs the CSRF token too, not just the main form.
const CSRF_TOKEN = '<?= htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8") ?>';

function toast(msg, isError=false) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'show' + (isError?' error':'');
    clearTimeout(el._t);
    el._t = setTimeout(() => el.className='', 3500);
}

function openModal(courtId=null, courtName=null, timeLabel=null) {
    document.getElementById('formAction').value    = 'create_reservation';
    document.getElementById('reservationId').value = 0;
    document.getElementById('modalTitle').textContent = '＋ New Reservation';
    document.getElementById('submitBtn').textContent  = 'Save Reservation';
    document.getElementById('reservationForm').reset();
    document.getElementById('fieldDate').value = SELECTED_DATE;
    if (courtId) document.getElementById('fieldCourtId').value = courtId;
    if (timeLabel) {
        const p = parseTimeLabel(timeLabel);
        if (p) { document.getElementById('fieldSlotStart').value = p.start; document.getElementById('fieldSlotEnd').value = p.end; }
    }
    document.getElementById('modalOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function editReservation(res) {
    document.getElementById('formAction').value    = 'update_reservation';
    document.getElementById('reservationId').value = res.id;
    document.getElementById('modalTitle').textContent = '✏️ Edit Reservation';
    document.getElementById('submitBtn').textContent  = 'Update Reservation';
    document.getElementById('fieldCourtId').value  = res.court_id;
    document.getElementById('fieldDate').value      = res.reservation_date;
    document.getElementById('fieldSlotStart').value = res.slot_start;
    document.getElementById('fieldSlotEnd').value   = res.slot_end;
    document.getElementById('fieldLabel').value     = res.label || '';
    document.getElementById('fieldNotes').value     = res.notes || '';
    document.getElementById('fieldStatus').value    = res.status || 'confirmed';
    document.getElementById('modalOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('open');
    document.body.style.overflow = '';
}

document.getElementById('modalOverlay').addEventListener('click', e => {
    if (e.target === document.getElementById('modalOverlay')) closeModal();
});

document.getElementById('reservationForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    btn.textContent = 'Saving…'; btn.disabled = true;
    const fd = new FormData(e.target);
    try {
        const r = await fetch('', { method:'POST', body:fd });
        const d = await r.json();
        if (d.ok) { toast('✅ ' + d.msg); closeModal(); setTimeout(()=>location.reload(), 800); }
        else { toast('❌ ' + d.msg, true); }
    } catch(err) { toast('❌ Network error.', true); }
    btn.textContent = 'Save Reservation'; btn.disabled = false;
});

async function cancelReservation(id) {
    if (!confirm('Cancel this reservation?')) return;
    const fd = new FormData(); fd.append('action','cancel_reservation'); fd.append('id',id); fd.append('csrf_token', CSRF_TOKEN);
    const r = await fetch('', {method:'POST',body:fd}); const d = await r.json();
    toast(d.ok?'✅ '+d.msg:'❌ '+d.msg,!d.ok);
    if (d.ok) setTimeout(()=>location.reload(), 800);
}

async function deleteReservation(id) {
    if (!confirm('Permanently delete this reservation?')) return;
    const fd = new FormData(); fd.append('action','delete_reservation'); fd.append('id',id); fd.append('csrf_token', CSRF_TOKEN);
    const r = await fetch('', {method:'POST',body:fd}); const d = await r.json();
    toast(d.ok?'🗑 '+d.msg:'❌ '+d.msg,!d.ok);
    if (d.ok) setTimeout(()=>location.reload(), 800);
}

async function forceSync() {
    const fd = new FormData(); fd.append('action','force_sync'); fd.append('csrf_token', CSRF_TOKEN);
    const r = await fetch('', {method:'POST',body:fd}); const d = await r.json();
    toast(d.ok?'🔄 '+d.msg:'❌ '+d.msg,!d.ok);
    updateSyncTime();
}

function updateSyncTime() {
    const now = new Date();
    document.getElementById('syncTime').textContent =
        now.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}

function parseTimeLabel(label) {
    const m = label.match(/(\d{1,2}):?(\d{2})?\s*(AM|PM)/i);
    if (!m) return null;
    let h = parseInt(m[1]);
    const pm = m[3].toUpperCase()==='PM';
    if (pm && h!==12) h+=12; if (!pm && h===12) h=0;
    const s = String(h).padStart(2,'0');
    const e = String(h+2>23?0:h+2).padStart(2,'0');
    return { start:`${s}:00:00`, end:`${e}:00:00` };
}

<?php if ($isToday): ?>
setInterval(() => { updateSyncTime(); }, 15000);
<?php endif; ?>
updateSyncTime();
setInterval(updateSyncTime, 1000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>