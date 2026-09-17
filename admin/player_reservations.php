<?php
// ============================================================
//  FILE: admin/player_reservations.php
//  Admin — Review, confirm, and reject player slot reservations.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/email_helpers.php';
requireAdmin();

$db = getDB();

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $rid    = filter_input(INPUT_POST, 'reservation_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $note   = trim(substr($_POST['admin_note'] ?? '', 0, 300));

    if ($rid && in_array($action, ['confirm', 'reject', 'cancel'])) {
        $res = $db->prepare("SELECT r.*, u.username, u.full_name, c.name AS court_name FROM falcon.reservations r JOIN falcon.users u ON u.id = r.user_id JOIN falcon.courts c ON c.id = r.court_id WHERE r.id = ?");
        $res->execute([$rid]);
        $reservation = $res->fetch();

        if ($reservation && $reservation['status'] === 'pending') {
            $newStatus = match($action) { 'confirm' => 'confirmed', 'reject' => 'cancelled', 'cancel' => 'cancelled', default => null };
            if ($newStatus) {
                try {
                    $db->beginTransaction();
                    $db->prepare("UPDATE falcon.reservations SET status=?, admin_note=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$newStatus, $note ?: null, $_SESSION['user_id'], $rid]);
                    $slotDateFmt = date('M d, Y', strtotime($reservation['slot_date']));
                    $slotTimeFmt = date('h:i A', strtotime($reservation['slot_time']));
                    if ($action === 'confirm') {
                        $notifTitle = '✅ Reservation Confirmed!';
                        $notifMsg   = "Your reservation at {$reservation['court_name']} on $slotDateFmt at $slotTimeFmt has been confirmed.";
                        $notifType  = 'success';
                        $flash      = "✅ Confirmed reservation for @{$reservation['username']} — $slotDateFmt $slotTimeFmt.";
                    } else {
                        $notifTitle = '❌ Reservation Not Available';
                        $notifMsg   = "Your reservation at {$reservation['court_name']} on $slotDateFmt at $slotTimeFmt could not be confirmed." . ($note ? " Reason: $note" : '');
                        $notifType  = 'warn';
                        $flash      = "Reservation for @{$reservation['username']} on $slotDateFmt rejected.";
                    }
                    $db->prepare("INSERT INTO falcon.notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)")->execute([$reservation['user_id'], $notifTitle, $notifMsg, $notifType]);
                    $db->commit();

                    // Send email notification
                    sendBookingNotification($rid);

                    setFlash('success', $flash);
                } catch (PDOException $e) {
                    $db->rollBack();
                    error_log('admin reservations error: ' . $e->getMessage());
                    setFlash('error', 'Database error. Please try again.');
                }
            }
        } elseif ($reservation && $reservation['status'] !== 'pending') {
            setFlash('warn', 'This reservation has already been reviewed.');
        }
    }
    redirect('admin/player_reservations.php');
}

// ── Filters ──────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'pending';
$filterDate   = $_GET['date']   ?? '';
$filterCourt  = (int)($_GET['court'] ?? 0);
$validStatuses = ['all', 'pending', 'confirmed', 'cancelled'];
if (!in_array($filterStatus, $validStatuses)) $filterStatus = 'pending';

$where  = "WHERE 1=1";
$params = [];
if ($filterStatus !== 'all') { $where .= " AND r.status = ?"; $params[] = $filterStatus; }
if ($filterDate)             { $where .= " AND r.slot_date = ?"; $params[] = $filterDate; }
if ($filterCourt > 0)        { $where .= " AND r.court_id = ?"; $params[] = $filterCourt; }

$stmt = $db->prepare("
    SELECT r.id, r.court_id, r.user_id, r.slot_date, r.slot_time, r.slot_end,
           r.status, r.party_size, r.note, r.admin_note,
           r.reviewed_at, r.created_at,
           u.username, u.full_name, u.phone,
           c.name AS court_name,
           rev.username AS reviewed_by_name
    FROM falcon.reservations r
    JOIN falcon.users u  ON u.id  = r.user_id
    JOIN falcon.courts c ON c.id  = r.court_id
    LEFT JOIN falcon.users rev ON rev.id = r.reviewed_by
    $where
    ORDER BY CASE r.status WHEN 'pending' THEN 0 ELSE 1 END, r.slot_date ASC, r.slot_time ASC
    LIMIT 200
");
$stmt->execute($params);
$reservations = $stmt->fetchAll();

$counts = $db->query("SELECT status, COUNT(*) AS cnt FROM falcon.reservations WHERE slot_date >= CURRENT_DATE GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$pendingTotal   = $counts['pending']   ?? 0;
$confirmedTotal = $counts['confirmed'] ?? 0;
$cancelledTotal = $counts['cancelled'] ?? 0;

$courts = $db->query("SELECT id, name FROM falcon.courts ORDER BY id")->fetchAll();

$pageTitle = 'Reservations';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* Reservation stats */
.res-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 24px;
}
/* Filter form */
.res-filter {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: flex-end;
}
.res-filter .rf-group {
    flex: 1;
    min-width: 130px;
}
.res-filter .rf-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 5px;
}
.res-filter .rf-group input,
.res-filter .rf-group select {
    width: 100%;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 14px;
    color: var(--text);
    font-size: 15px;
    font-family: 'DM Sans', sans-serif;
    outline: none;
    -webkit-appearance: none;
    appearance: none;
}
.res-filter .rf-group select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2364748b' stroke-width='2' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 34px;
}
/* Reservation card */
.res-card {
    background: var(--surface2);
    border-radius: 12px;
    padding: 16px 18px;
    margin-bottom: 12px;
}
.res-card-inner {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}
.res-details { flex: 1; min-width: 0; }
.res-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex-shrink: 0;
    min-width: 150px;
}
.res-actions button {
    width: 100%;
}
.res-slot-chips {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    font-size: 13px;
    margin-top: 6px;
}
@media (max-width: 640px) {
    .res-stats { grid-template-columns: repeat(2, 1fr); }
    .res-card-inner { flex-direction: column; }
    .res-actions { min-width: 0; width: 100%; flex-direction: row; flex-wrap: wrap; }
    .res-actions button { flex: 1 1 auto; }
    .res-filter .rf-group { flex: 1 1 calc(50% - 10px); min-width: 120px; }
}
@media (max-width: 380px) {
    .res-stats { grid-template-columns: 1fr; }
    .res-filter .rf-group { flex: 1 1 100%; }
    .res-slot-chips { gap: 6px; }
}
</style>

<div class="page-header flex-between">
    <div>
        <h1>Reservations</h1>
        <p>Review and confirm player slot bookings.</p>
    </div>
    <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
</div>

<!-- Summary stats -->
<div class="res-stats">
    <div class="stat-card" style="cursor:pointer;" onclick="location.href='?status=pending'">
        <div class="stat-val" style="color:var(--warn);"><?= $pendingTotal ?></div>
        <div class="stat-label">Pending Review</div>
    </div>
    <div class="stat-card" style="cursor:pointer;" onclick="location.href='?status=confirmed'">
        <div class="stat-val" style="color:var(--success);"><?= $confirmedTotal ?></div>
        <div class="stat-label">Confirmed</div>
    </div>
    <div class="stat-card" style="cursor:pointer;" onclick="location.href='?status=cancelled'">
        <div class="stat-val" style="color:var(--danger);"><?= $cancelledTotal ?></div>
        <div class="stat-label">Cancelled</div>
    </div>
</div>

<?php if ($pendingTotal > 0 && $filterStatus !== 'pending'): ?>
    <div style="background:rgba(245,158,11,0.1);border:1px solid var(--warn);border-radius:10px;
                padding:12px 18px;margin-bottom:20px;font-size:14px;color:var(--warn);">
        ⏳ <strong><?= $pendingTotal ?></strong> reservation<?= $pendingTotal !== 1 ? 's' : '' ?> waiting for review.
        <a href="?status=pending" style="color:var(--accent);margin-left:8px;">Review now →</a>
    </div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-3">
    <form method="GET" class="res-filter">
        <div class="rf-group">
            <label>Status</label>
            <select name="status">
                <?php foreach (['pending'=>'⏳ Pending','confirmed'=>'✅ Confirmed','cancelled'=>'❌ Cancelled','all'=>'All'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $filterStatus===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rf-group">
            <label>Date</label>
            <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>"/>
        </div>
        <div class="rf-group">
            <label>Court</label>
            <select name="court">
                <option value="0">All Courts</option>
                <?php foreach ($courts as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filterCourt===$c['id']?'selected':'' ?>><?= clean($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;gap:6px;align-items:flex-end;flex-shrink:0;">
            <button type="submit" class="btn-primary btn-sm" style="padding:10px 18px;">Filter</button>
            <a href="?" class="btn-outline btn-sm" style="padding:10px 18px;">Reset</a>
        </div>
    </form>
</div>

<!-- Reservations list -->
<div class="card">
    <div class="card-title mb-1">
        <?= match($filterStatus) {
            'pending'   => '⏳ Pending Reservations',
            'confirmed' => '✅ Confirmed Reservations',
            'cancelled' => '❌ Cancelled Reservations',
            default     => '📋 All Reservations',
        } ?>
    </div>
    <div class="card-subtitle">Showing <?= count($reservations) ?> result<?= count($reservations)!==1?'s':'' ?></div>
    <hr class="divider"/>

    <?php if (empty($reservations)): ?>
        <div style="text-align:center;padding:48px;">
            <div style="font-size:52px;margin-bottom:12px;">📭</div>
            <p class="text-muted">No reservations found for this filter.</p>
        </div>
    <?php else: ?>
        <?php foreach ($reservations as $r):
            $isPending   = ($r['status'] === 'pending');
            $isConfirmed = ($r['status'] === 'confirmed');
            $badgeColor  = match($r['status']) { 'confirmed'=>'success','cancelled'=>'danger','pending'=>'warn', default=>'muted' };
            $borderColor = match($r['status']) { 'confirmed'=>'var(--success)','cancelled'=>'var(--danger)','pending'=>'var(--warn)', default=>'var(--border)' };
        ?>
            <div class="res-card" style="border-left:4px solid <?= $borderColor ?>;">
                <div class="res-card-inner">
                    <!-- Details -->
                    <div class="res-details">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap;">
                            <span class="badge badge-<?= $badgeColor ?>"><?= ucfirst($r['status']) ?></span>
                            <span style="font-size:11px;color:var(--muted);">
                                Submitted <?= date('M d, h:i A', strtotime($r['created_at'])) ?>
                            </span>
                        </div>

                        <div style="margin-bottom:6px;">
                            <strong style="font-size:14px;"><?= clean($r['full_name']) ?></strong>
                            <span style="color:var(--muted);font-size:12px;margin-left:6px;">@<?= clean($r['username']) ?></span>
                            <?php if ($r['phone']): ?>
                                <span style="color:var(--muted);font-size:11px;margin-left:6px;">· <?= clean($r['phone']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="res-slot-chips">
                            <span>📅 <strong><?= date('D, M d, Y', strtotime($r['slot_date'])) ?></strong></span>
                            <span>🕐 <?= date('h:i A', strtotime($r['slot_time'])) ?> – <?= date('h:i A', strtotime($r['slot_end'])) ?></span>
                            <span>🏓 <?= clean($r['court_name']) ?></span>
                            <span>👥 <?= $r['party_size'] ?> player<?= $r['party_size']>1?'s':'' ?></span>
                        </div>

                        <?php if ($r['note']): ?>
                            <div style="margin-top:8px;font-size:12px;color:var(--muted);background:var(--surface);border-radius:6px;padding:6px 10px;">
                                Player note: <?= clean($r['note']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($r['admin_note'] && !$isPending): ?>
                            <div style="margin-top:6px;font-size:12px;color:<?= $isConfirmed?'var(--success)':'var(--danger)' ?>;background:var(--surface);border-radius:6px;padding:6px 10px;">
                                Admin note: <?= clean($r['admin_note']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($r['reviewed_at']): ?>
                            <div style="margin-top:4px;font-size:11px;color:var(--muted);">
                                Reviewed by @<?= clean($r['reviewed_by_name'] ?? 'admin') ?>
                                · <?= date('M d, h:i A', strtotime($r['reviewed_at'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Actions (pending only) -->
                    <?php if ($isPending): ?>
                    <div class="res-actions">
                        <button onclick="showActionModal(<?= $r['id'] ?>, 'confirm',
                                    '<?= clean($r['full_name']) ?>',
                                    '<?= date('M d', strtotime($r['slot_date'])) ?> <?= date('h:i A', strtotime($r['slot_time'])) ?>')"
                                class="btn-success btn-sm">
                            ✅ Confirm
                        </button>
                        <button onclick="showActionModal(<?= $r['id'] ?>, 'reject',
                                    '<?= clean($r['full_name']) ?>',
                                    '<?= date('M d', strtotime($r['slot_date'])) ?> <?= date('h:i A', strtotime($r['slot_time'])) ?>')"
                                class="btn-danger btn-sm">
                            ❌ Reject
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Action Modal -->
<style nonce="<?= getCspNonce() ?>">
.action-overlay {
    display: none; position: fixed; inset: 0; z-index: 1000;
    background: rgba(0,0,0,0.72); backdrop-filter: blur(4px);
    align-items: center; justify-content: center;
    padding: 16px;
    padding-bottom: max(16px, env(safe-area-inset-bottom));
    box-sizing: border-box;
}
.action-overlay.open { display: flex; }
.action-box {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 20px; padding: 28px;
    width: 100%; max-width: 420px;
    max-height: 90vh; overflow-y: auto;
    box-sizing: border-box;
}
.action-box .ax-title { font-family:'Bebas Neue',sans-serif; font-size:26px; margin-bottom:4px; }
.action-box .ax-sub   { font-size:13px; color:var(--muted); margin-bottom:18px; }
.action-box .ax-group { margin-bottom:16px; }
.action-box .ax-group label { display:block; font-size:12px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px; }
.action-box .ax-group textarea {
    width:100%; background:var(--surface2); border:1px solid var(--border);
    border-radius:8px; padding:10px 14px; color:var(--text);
    font-size:16px; font-family:'DM Sans',sans-serif; outline:none;
    resize:none; -webkit-appearance:none; appearance:none;
}
.action-box .ax-group textarea:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(0,229,160,0.1); }
.ax-footer { display:flex; gap:10px; margin-top:4px; }
.ax-footer button { flex:1; }
@media (max-width:420px) {
    .action-box { padding:20px; border-radius:14px; }
    .ax-footer { flex-direction:column; }
}
</style>

<div id="action-modal" class="action-overlay">
    <div class="action-box">
        <div id="modal-title" class="ax-title"></div>
        <div id="modal-sub"   class="ax-sub"></div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="reservation_id" id="modal-rid">
            <input type="hidden" name="action"         id="modal-action">
            <div class="ax-group">
                <label>Note to Player <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
                <textarea name="admin_note" id="modal-note" rows="3" maxlength="300"
                          placeholder="e.g. Court available! / Slot is double-booked, sorry."></textarea>
            </div>
            <div class="ax-footer">
                <button type="submit" id="modal-btn" class="btn-primary"></button>
                <button type="button" onclick="closeActionModal()" class="btn-outline">Back</button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
function showActionModal(rid, action, playerName, slotInfo) {
    document.getElementById('modal-rid').value    = rid;
    document.getElementById('modal-action').value = action;
    document.getElementById('modal-note').value   = '';

    if (action === 'confirm') {
        document.getElementById('modal-title').textContent = '✅ Confirm Reservation';
        document.getElementById('modal-title').style.color = 'var(--success)';
        document.getElementById('modal-sub').textContent   = playerName + ' · ' + slotInfo;
        document.getElementById('modal-btn').textContent   = '✅ Yes, Confirm';
        document.getElementById('modal-btn').className     = 'btn-success';
        document.getElementById('modal-note').placeholder  = 'e.g. Court is ready, see you there!';
    } else {
        document.getElementById('modal-title').textContent = '❌ Reject Reservation';
        document.getElementById('modal-title').style.color = 'var(--danger)';
        document.getElementById('modal-sub').textContent   = playerName + ' · ' + slotInfo;
        document.getElementById('modal-btn').textContent   = '❌ Yes, Reject';
        document.getElementById('modal-btn').className     = 'btn-danger';
        document.getElementById('modal-note').placeholder  = 'e.g. Slot is fully booked, please try another time.';
    }

    document.getElementById('action-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('modal-note').focus(), 100);
}
function closeActionModal() {
    document.getElementById('action-modal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('action-modal').addEventListener('click', function(e) {
    if (e.target === this) closeActionModal();
});
document.addEventListener('keydown', e => { if (e.key==='Escape') closeActionModal(); });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>