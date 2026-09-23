<?php
// ============================================================
//  FILE: admin/courts.php
//  Court Management — list all courts with live status
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db      = getDB();
$errors  = [];

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action'] ?? '';
    $courtId = filter_input(INPUT_POST, 'court_id', FILTER_VALIDATE_INT);

    if ($action === 'toggle_maintenance' && $courtId) {
        // Cannot toggle while game is active on that court
        $activeCheck = $db->prepare("
            SELECT live_status FROM falcon.v_court_status WHERE id = ?
        ");
        $activeCheck->execute([$courtId]);
        $cs = $activeCheck->fetch();

        if ($cs && $cs['live_status'] === 'active') {
            setFlash('warn', '⚠️ Cannot toggle maintenance — a game is currently active on this court.');
        } else {
            $db->prepare("UPDATE falcon.courts SET is_maintenance = NOT is_maintenance, updated_at = NOW() WHERE id = ?")
               ->execute([$courtId]);
            setFlash('success', '✅ Maintenance status updated.');
        }
        redirect('admin/courts.php');
    }

    if ($action === 'set_status' && $courtId) {
        $allowedStatuses = ['auto', 'open_play', 'reserved', 'occupied', 'tournament'];
        $newStatus = $_POST['status'] ?? '';

        if (!in_array($newStatus, $allowedStatuses, true)) {
            setFlash('warn', '⚠️ Invalid status selected.');
        } else {
            $adminId = $_SESSION['user_id'] ?? null;
            $db->prepare("
                UPDATE falcon.courts
                SET manual_status = ?,
                    manual_status_set_by = ?,
                    manual_status_set_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $newStatus === 'auto' ? null : $newStatus,
                $adminId,
                $courtId
            ]);
            setFlash('success', $newStatus === 'auto'
                ? '✅ Court status reset to automatic.'
                : '✅ Court status set to ' . ucwords(str_replace('_', ' ', $newStatus)) . '.');
        }
        redirect('admin/courts.php');
    }

    if ($action === 'reorder' && $courtId) {
        $direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';

        // Get current sort_order
        $cur = $db->prepare("SELECT sort_order FROM falcon.courts WHERE id = ?");
        $cur->execute([$courtId]);
        $curOrder = (int)($cur->fetchColumn() ?? 0);

        if ($direction === 'up') {
            // Find the court just above (lower sort_order)
            $neighbor = $db->prepare("
                SELECT id, sort_order FROM falcon.courts
                WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1
            ");
        } else {
            // Find the court just below (higher sort_order)
            $neighbor = $db->prepare("
                SELECT id, sort_order FROM falcon.courts
                WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1
            ");
        }
        $neighbor->execute([$curOrder]);
        $nb = $neighbor->fetch();

        if ($nb) {
            $db->prepare("UPDATE falcon.courts SET sort_order = ? WHERE id = ?")
               ->execute([$nb['sort_order'], $courtId]);
            $db->prepare("UPDATE falcon.courts SET sort_order = ? WHERE id = ?")
               ->execute([$curOrder, $nb['id']]);
            setFlash('success', '✅ Court order updated.');
        }
        redirect('admin/courts.php');
    }

    // ── Deactivate court (soft — reversible, keeps history) ────
    if ($action === 'deactivate' && $courtId) {
        $db->prepare("UPDATE falcon.courts SET is_active = FALSE, updated_at = NOW() WHERE id = ?")
           ->execute([$courtId]);
        setFlash('warn', '⏸ Court deactivated. Re-enable it anytime from Edit → Court Active.');
        redirect('admin/courts.php');
    }

    // ── Reactivate court ─────────────────────────────────────
    if ($action === 'reactivate' && $courtId) {
        $db->prepare("UPDATE falcon.courts SET is_active = TRUE, updated_at = NOW() WHERE id = ?")
           ->execute([$courtId]);
        setFlash('success', '✅ Court reactivated.');
        redirect('admin/courts.php');
    }

    // ── Delete court (permanent) ────────────────────────────────
    if ($action === 'delete_court' && $courtId) {
        $target = $db->prepare("SELECT name FROM falcon.courts WHERE id = ?");
        $target->execute([$courtId]);
        $targetName = $target->fetchColumn();

        $confirmName = trim($_POST['confirm_name'] ?? '');

        $activeCheck = $db->prepare("SELECT COUNT(*) FROM falcon.game_sessions WHERE court_id=? AND status='active'");
        $activeCheck->execute([$courtId]);
        $hasActiveSession = (int)$activeCheck->fetchColumn() > 0;

        $futureBkCheck = $db->prepare("
            SELECT COUNT(*) FROM falcon.reservations
            WHERE court_id = ? AND status IN ('pending','confirmed') AND slot_date >= CURRENT_DATE
        ");
        $futureBkCheck->execute([$courtId]);
        $hasFutureBookings = (int)$futureBkCheck->fetchColumn() > 0;

        if (!$targetName) {
            setFlash('error', 'Court not found.');
        } elseif ($confirmName !== $targetName) {
            setFlash('error', 'Court name did not match. Deletion cancelled.');
        } elseif ($hasActiveSession || $hasFutureBookings) {
            setFlash('error', 'Cannot delete: court has active sessions or future bookings. Deactivate it instead.');
        } else {
            try {
                // Capture full row + related settings before deleting, so
                // we can offer a short "Undo" window.
                $fullCourt = $db->prepare("SELECT * FROM falcon.courts WHERE id = ?");
                $fullCourt->execute([$courtId]);
                $fullCourtRow = $fullCourt->fetch();

                $fullHours = $db->prepare("SELECT * FROM falcon.court_hours WHERE court_id = ?");
                $fullHours->execute([$courtId]);
                $fullHoursRows = $fullHours->fetchAll();

                $fullSettings = $db->prepare("SELECT * FROM falcon.court_settings WHERE court_id = ?");
                $fullSettings->execute([$courtId]);
                $fullSettingsRows = $fullSettings->fetchAll();

                $db->beginTransaction();
                $db->prepare("DELETE FROM falcon.court_hours      WHERE court_id = ?")->execute([$courtId]);
                $db->prepare("DELETE FROM falcon.court_settings   WHERE court_id = ?")->execute([$courtId]);
                $db->prepare("DELETE FROM falcon.court_slot_modes WHERE court_id = ?")->execute([$courtId]);
                $db->prepare("DELETE FROM falcon.courts           WHERE id = ?")->execute([$courtId]);
                auditLog($db, 'court_deleted', isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, 'courts', $courtId,
                    ['name' => $targetName], [], 'success', 'Court deleted from Court Management');
                $db->commit();

                $_SESSION['deleted_court_undo'] = [
                    'court'     => $fullCourtRow,
                    'hours'     => $fullHoursRows,
                    'settings'  => $fullSettingsRows,
                    'expires'   => time() + 60,
                ];
                setFlash('success', "🗑 Court '{$targetName}' deleted.");
            } catch (PDOException $e) {
                $db->rollBack();
                error_log('[courts.php delete] ' . $e->getMessage());
                setFlash('error', 'Database error while deleting the court.');
            }
        }
        redirect('admin/courts.php');
    }

    // ── Restore a just-deleted court (undo window) ──────────────
    if ($action === 'restore_court') {
        $backup = $_SESSION['deleted_court_undo'] ?? null;
        unset($_SESSION['deleted_court_undo']); // one-time use either way

        if (!$backup || $backup['expires'] < time() || !$backup['court']) {
            setFlash('error', 'That undo window has expired.');
            redirect('admin/courts.php');
        }

        try {
            $db->beginTransaction();
            $ct = $backup['court'];

            // Short code may have been reused since deletion — fall back
            // to a safe suffixed version if so, rather than failing silently.
            $scCheck = $db->prepare("SELECT id FROM falcon.courts WHERE UPPER(short_code) = ?");
            $scCheck->execute([strtoupper($ct['short_code'])]);
            $restoredShortCode = $scCheck->fetch() ? $ct['short_code'] . '-R' : $ct['short_code'];

            $ins = $db->prepare("
                INSERT INTO falcon.courts
                    (name, short_code, description, court_type, address, color, photo,
                     credit_cost, game_duration, warmup_mins, pass_hours, max_queue,
                     is_active, is_maintenance, sort_order, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                RETURNING id
            ");
            $ins->execute([
                $ct['name'], $restoredShortCode, $ct['description'], $ct['court_type'], $ct['address'],
                $ct['color'], $ct['photo'] ?? null, $ct['credit_cost'], $ct['game_duration'],
                $ct['warmup_mins'], $ct['pass_hours'], $ct['max_queue'],
                $ct['is_active'], $ct['is_maintenance'], $ct['sort_order'],
            ]);
            $restoredId = (int)$ins->fetchColumn();

            foreach ($backup['hours'] as $h) {
                $db->prepare("
                    INSERT INTO falcon.court_hours (court_id, day_of_week, open_time, close_time, is_closed)
                    VALUES (?, ?, ?, ?, ?)
                    ON CONFLICT (court_id, day_of_week) DO NOTHING
                ")->execute([$restoredId, $h['day_of_week'], $h['open_time'], $h['close_time'], $h['is_closed']]);
            }
            foreach ($backup['settings'] as $s) {
                $db->prepare("
                    INSERT INTO falcon.court_settings (court_id, key, value)
                    VALUES (?, ?, ?)
                    ON CONFLICT (court_id, key) DO NOTHING
                ")->execute([$restoredId, $s['key'], $s['value']]);
            }

            auditLog($db, 'court_restored', isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, 'courts', $restoredId,
                [], ['name' => $ct['name']], 'success', 'Court restored via undo');
            $db->commit();
            setFlash('success', "↩️ Court '{$ct['name']}' restored." . ($restoredShortCode !== $ct['short_code'] ? " (short code changed to {$restoredShortCode} — the original was taken again)" : ''));
        } catch (PDOException $e) {
            $db->rollBack();
            error_log('[courts.php restore] ' . $e->getMessage());
            setFlash('error', 'Could not restore the court — please recreate it manually.');
        }
        redirect('admin/courts.php');
    }
}

// ── Fetch all courts with live status ────────────────────────
$courts = $db->query("
    SELECT * FROM falcon.v_court_status ORDER BY sort_order, id
")->fetchAll();

// ── Today's game counts per court ────────────────────────────
$todayStats = $db->query("
    SELECT
        gs.court_id,
        COUNT(DISTINCT gs.id)       AS games_today,
        COUNT(DISTINCT gp.user_id)  AS players_today
    FROM falcon.game_sessions gs
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    WHERE DATE(gs.started_at) = CURRENT_DATE
    GROUP BY gs.court_id
")->fetchAll(PDO::FETCH_KEY_PAIR + 0); // use manual index below

$statsMap = [];
foreach ($db->query("
    SELECT
        gs.court_id,
        COUNT(DISTINCT gs.id)       AS games_today,
        COUNT(DISTINCT gp.user_id)  AS players_today
    FROM falcon.game_sessions gs
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    WHERE DATE(gs.started_at) = CURRENT_DATE
    GROUP BY gs.court_id
")->fetchAll() as $row) {
    $statsMap[(int)$row['court_id']] = $row;
}

// ── Status config ─────────────────────────────────────────────
$statusConfig = [
    'available'   => ['color' => 'var(--accent)',   'icon' => '●',  'label' => 'Available',   'class' => 'status-available'],
    'active'      => ['color' => '#00aaff',          'icon' => '🎮', 'label' => 'In Game',     'class' => 'status-active'],
    'queuing'     => ['color' => 'var(--warn)',      'icon' => '⏳', 'label' => 'Queuing',     'class' => 'status-queuing'],
    'maintenance' => ['color' => 'var(--muted)',     'icon' => '🔧', 'label' => 'Maintenance', 'class' => 'status-maintenance'],
    'closed'      => ['color' => 'var(--danger)',    'icon' => '✗',  'label' => 'Closed',      'class' => 'status-closed'],
    'open_play'   => ['color' => 'var(--accent)',    'icon' => '🏓', 'label' => 'Open Play',   'class' => 'status-open-play'],
    'reserved'    => ['color' => '#8b5cf6',          'icon' => '📌', 'label' => 'Reserved',    'class' => 'status-reserved'],
    'occupied'    => ['color' => '#00aaff',          'icon' => '🔒', 'label' => 'Occupied',    'class' => 'status-occupied'],
    'tournament'  => ['color' => '#f43f5e',          'icon' => '🏆', 'label' => 'Tournament',  'class' => 'status-tournament'],
];

// Options for the manual status override dropdown on each court card
$manualStatusOptions = [
    'auto'       => 'Auto (computed)',
    'open_play'  => '🏓 Open Play',
    'reserved'   => '📌 Reserved',
    'occupied'   => '🔒 Occupied',
    'tournament' => '🏆 Tournament',
];

// ── Court type icons ──────────────────────────────────────────
$typeIcons = [
    'covered'   => '🏠',
    'uncovered' => '☀️',
    'indoor'    => '🏢',
    'outdoor'   => '🌿',
];

$pageTitle = 'Court Management';
require_once __DIR__ . '/../includes/header.php';
$flash = getFlash();
$undoBackup = $_SESSION['deleted_court_undo'] ?? null;
$undoSecondsLeft = ($undoBackup && $undoBackup['expires'] > time()) ? ($undoBackup['expires'] - time()) : 0;
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Court Management Page ────────────────────────────────── */
.courts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.court-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
    position: relative;
    transition: box-shadow 0.2s, transform 0.15s;
}
.court-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 32px rgba(0,0,0,0.3);
}
.court-card-accent {
    height: 4px;
    width: 100%;
}
.court-card-body {
    padding: 18px 20px 16px;
}

/* Maintenance overlay */
.court-card.is-maintenance::after {
    content: '🔧 MAINTENANCE';
    position: absolute;
    top: 12px; right: 12px;
    background: rgba(100,116,139,0.9);
    color: #fff;
    font-size: 10px; font-weight: 800;
    letter-spacing: .1em;
    padding: 4px 10px;
    border-radius: 20px;
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255,255,255,0.1);
}

/* Deactivated court styling */
.court-card.is-inactive {
    opacity: 0.55;
    filter: grayscale(0.4);
}
.court-card.is-inactive:hover {
    opacity: 0.8;
    filter: grayscale(0.15);
}
.inactive-ribbon {
    position: absolute;
    top: 12px; left: 12px;
    background: rgba(100,116,139,0.9);
    color: #fff;
    font-size: 10px; font-weight: 800;
    letter-spacing: .1em;
    padding: 4px 10px;
    border-radius: 20px;
    z-index: 2;
}

.court-thumb {
    width: 52px;
    height: 52px;
    border-radius: 9px;
    object-fit: cover;
    flex-shrink: 0;
    border: 1.5px solid var(--border);
}

.court-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 12px;
}
.court-name {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 22px;
    letter-spacing: 1px;
    line-height: 1;
}
.short-code-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px; height: 36px;
    border-radius: 8px;
    font-family: 'Bebas Neue', sans-serif;
    font-size: 16px;
    letter-spacing: 1px;
    flex-shrink: 0;
}

.court-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    padding: 3px 10px;
    border-radius: 20px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.1);
    color: var(--muted);
    margin-bottom: 12px;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    padding: 4px 12px;
    border-radius: 20px;
    margin-bottom: 14px;
}
.status-pill.status-available   { background: rgba(0,229,160,.12);  color: var(--accent);  border: 1px solid rgba(0,229,160,.3); }
.status-pill.status-active      { background: rgba(0,170,255,.12);  color: #00aaff;         border: 1px solid rgba(0,170,255,.3); animation: activePulse 2s infinite; }
.status-pill.status-queuing     { background: rgba(245,158,11,.12); color: var(--warn);    border: 1px solid rgba(245,158,11,.3); }
.status-pill.status-maintenance { background: rgba(100,116,139,.12);color: var(--muted);  border: 1px solid rgba(100,116,139,.3); }
.status-pill.status-closed      { background: rgba(239,68,68,.12);  color: var(--danger);  border: 1px solid rgba(239,68,68,.3); }
.status-pill.status-open-play   { background: rgba(0,229,160,.12);  color: var(--accent);  border: 1px solid rgba(0,229,160,.3); }
.status-pill.status-reserved    { background: rgba(139,92,246,.12); color: #8b5cf6;         border: 1px solid rgba(139,92,246,.3); }
.status-pill.status-occupied    { background: rgba(0,170,255,.12);  color: #00aaff;         border: 1px solid rgba(0,170,255,.3); }
.status-pill.status-tournament  { background: rgba(244,63,94,.12);  color: #f43f5e;         border: 1px solid rgba(244,63,94,.3); }
.status-select-form { margin: 0; }
.status-select-form select {
    background: rgba(255,255,255,.04);
    color: var(--text, #e5e7eb);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 13px;
    cursor: pointer;
}
@keyframes activePulse { 0%,100%{opacity:1} 50%{opacity:0.65} }

.court-stats-row {
    display: grid;
    grid-template-columns: repeat(3,1fr);
    gap: 8px;
    margin-bottom: 14px;
}
.court-stat {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 6px;
    text-align: center;
}
.court-stat-val {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 20px;
    line-height: 1;
}
.court-stat-lbl {
    font-size: 9px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-top: 2px;
}

.court-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.court-actions a,
.court-actions button {
    font-size: 11px;
    padding: 6px 10px;
    border-radius: 7px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    border: 1px solid var(--border);
    background: var(--surface2);
    color: var(--text);
    transition: all 0.15s;
    font-family: inherit;
}
.court-actions a:hover,
.court-actions button:hover {
    border-color: var(--accent);
    color: var(--accent);
}
.court-actions .btn-maint {
    border-color: rgba(245,158,11,.3);
    color: var(--warn);
    background: rgba(245,158,11,.06);
}
.court-actions .btn-maint:hover {
    background: rgba(245,158,11,.12);
    border-color: var(--warn);
}
.court-actions .btn-maint-off {
    border-color: rgba(100,116,139,.3);
    color: var(--muted);
}
.court-actions .btn-remove {
    border-color: rgba(239,68,68,.3);
    color: var(--danger);
    background: rgba(239,68,68,.06);
    position: relative;
}
.court-actions .btn-remove:hover {
    background: rgba(239,68,68,.12);
    border-color: var(--danger);
    color: var(--danger);
}
.remove-menu-wrap { position: relative; display: inline-block; }
.remove-menu {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    right: 0;
    z-index: 40;
    min-width: 170px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,.4);
    overflow: hidden;
}
.remove-menu.open { display: block; }
.remove-menu button {
    display: block;
    width: 100%;
    text-align: left;
    padding: 10px 14px;
    font-size: 12px;
    font-weight: 600;
    background: transparent;
    border: none;
    border-bottom: 1px solid var(--border);
    color: var(--text);
    cursor: pointer;
    font-family: inherit;
}
.remove-menu button:last-child { border-bottom: none; }
.remove-menu button:hover { background: rgba(255,255,255,.05); }
.remove-menu button.danger-item { color: var(--danger); }
.remove-menu button.danger-item:hover { background: rgba(239,68,68,.1); }

.reorder-btns {
    display: flex;
    flex-direction: column;
    gap: 3px;
    margin-left: auto;
    flex-shrink: 0;
}
.reorder-btn {
    width: 26px; height: 26px;
    border-radius: 5px;
    border: 1px solid var(--border);
    background: var(--surface2);
    color: var(--muted);
    font-size: 12px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.15s;
    font-family: inherit;
}
.reorder-btn:hover {
    border-color: var(--accent);
    color: var(--accent);
}

/* Summary table */
.courts-summary-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13px;
}
.courts-summary-table th {
    text-align: left;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--muted);
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
}
.courts-summary-table td {
    padding: 12px 14px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    vertical-align: middle;
}
.courts-summary-table tr:last-child td { border-bottom: none; }
.courts-summary-table tr:hover td { background: rgba(255,255,255,0.02); }
</style>

<!-- ── Page Header ── -->
<div class="page-header flex-between">
    <div>
        <h1>Court Management</h1>
        <p>Manage all courts, their settings, and live status</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="<?= APP_URL ?>/admin/court_create.php" class="btn-primary btn-sm">+ Add Court</a>
        <a href="<?= APP_URL ?>/admin/dashboard.php"    class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warn' ? 'warn' : 'error') ?>"
     style="margin-bottom:16px;border-radius:10px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <span><?= clean($flash['message']) ?></span>
    <?php if ($undoSecondsLeft > 0 && str_contains($flash['message'], 'deleted')): ?>
    <form method="POST" style="margin:0;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="restore_court">
        <button type="submit" class="btn-outline btn-sm" style="white-space:nowrap;">
            ↩️ Undo <span id="undo-countdown"><?= $undoSecondsLeft ?></span>s
        </button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Courts Grid ── -->
<div class="courts-grid">
    <?php foreach ($courts as $i => $c):
        $statusKey = $c['live_status'] ?? 'closed';
        $sc  = $statusConfig[$statusKey] ?? $statusConfig['closed'];
        $tid = (int)$c['id'];
        $ts  = $statsMap[$tid] ?? [];
        $typeIcon = $typeIcons[$c['court_type'] ?? 'covered'] ?? '🏓';
        $isMaint  = (bool)$c['is_maintenance'];
        $isFirst  = ($i === 0);
        $isLast   = ($i === count($courts) - 1);
        $courtColor = clean($c['color'] ?? '#00e5a0');
    ?>
    <div class="court-card <?= $isMaint ? 'is-maintenance' : '' ?> <?= !$c['is_active'] ? 'is-inactive' : '' ?>">
        <!-- Color accent bar -->
        <div class="court-card-accent" style="background: <?= $courtColor ?>;"></div>
        <?php if (!$c['is_active']): ?>
        <div class="inactive-ribbon">INACTIVE</div>
        <?php endif; ?>

        <div class="court-card-body">
            <div class="court-header">
                <div style="display:flex;gap:10px;align-items:flex-start;">
                    <?php if (!empty($c['photo'])): ?>
                    <img src="<?= APP_URL ?>/uploads/courts/<?= urlencode($c['photo']) ?>" alt=""
                         class="court-thumb" onerror="this.style.display='none'">
                    <?php endif; ?>
                    <div>
                        <!-- Short code badge -->
                        <div class="short-code-badge"
                             style="background:<?= $courtColor ?>22;color:<?= $courtColor ?>;border:1px solid <?= $courtColor ?>44;margin-bottom:6px;">
                            <?= clean($c['short_code'] ?? 'C?') ?>
                        </div>
                        <div class="court-name" style="color:<?= $courtColor ?>;"><?= clean($c['name']) ?></div>
                    </div>
                </div>
                <div class="reorder-btns">
                    <?php if (!$isFirst): ?>
                    <form method="POST" style="margin:0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"    value="reorder">
                        <input type="hidden" name="court_id"  value="<?= $tid ?>">
                        <input type="hidden" name="direction" value="up">
                        <button type="submit" class="reorder-btn" title="Move up">▲</button>
                    </form>
                    <?php endif; ?>
                    <?php if (!$isLast): ?>
                    <form method="POST" style="margin:0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"    value="reorder">
                        <input type="hidden" name="court_id"  value="<?= $tid ?>">
                        <input type="hidden" name="direction" value="down">
                        <button type="submit" class="reorder-btn" title="Move down">▼</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Type badge -->
            <div class="court-type-badge">
                <?= $typeIcon ?> <?= ucfirst(clean($c['court_type'] ?? 'covered')) ?>
            </div>

            <!-- Status pill -->
            <div class="status-pill <?= $sc['class'] ?>">
                <span><?= $sc['icon'] ?></span>
                <span><?= $sc['label'] ?></span>
            </div>

            <!-- Manual status override -->
            <form method="POST" class="status-select-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="court_id" value="<?= $tid ?>">
                <select name="status" onchange="this.form.submit()">
                    <?php $currentManual = $c['manual_status'] ?: 'auto'; ?>
                    <?php foreach ($manualStatusOptions as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $currentManual === $val ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <!-- Stats -->
            <div class="court-stats-row">
                <div class="court-stat">
                    <div class="court-stat-val" style="color:<?= $courtColor ?>;">
                        <?= (int)$c['players_on_court'] ?>/<?= (int)$c['max_queue'] ?>
                    </div>
                    <div class="court-stat-lbl">On Court</div>
                </div>
                <div class="court-stat">
                    <div class="court-stat-val" style="color:var(--warn);">
                        <?= (int)$c['queue_count'] ?>
                    </div>
                    <div class="court-stat-lbl">In Queue</div>
                </div>
                <div class="court-stat">
                    <div class="court-stat-val">
                        <?= (int)($ts['games_today'] ?? 0) ?>
                    </div>
                    <div class="court-stat-lbl">Today</div>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="court-actions">
                <a href="<?= APP_URL ?>/admin/court_settings.php?court=<?= $tid ?>">⚙️ Settings</a>
                <a href="<?= APP_URL ?>/admin/court_hours.php?court=<?= $tid ?>">📅 Hours</a>
                <a href="<?= APP_URL ?>/admin/court_mode.php?court=<?= $tid ?>">🎮 Modes</a>
                <a href="<?= APP_URL ?>/admin/court_edit.php?court_id=<?= $tid ?>">✏️ Edit</a>
                <a href="<?= APP_URL ?>/admin/court_create.php?clone=<?= $tid ?>" title="Create a new court pre-filled with these settings">⧉ Duplicate</a>

                <!-- Maintenance toggle -->
                <form method="POST" style="margin:0;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"   value="toggle_maintenance">
                    <input type="hidden" name="court_id" value="<?= $tid ?>">
                    <button type="submit"
                            class="<?= $isMaint ? 'btn-maint-off' : 'btn-maint' ?>"
                            onclick="return confirm('<?= $isMaint ? 'Clear maintenance mode?' : 'Enable maintenance mode?' ?>')">
                        <?= $isMaint ? '✅ Clear Maint.' : '🔧 Maintenance' ?>
                    </button>
                </form>

                <!-- Remove dropdown: deactivate/reactivate or permanently delete -->
                <div class="remove-menu-wrap">
                    <button type="button" class="btn-remove" onclick="toggleRemoveMenu(<?= $tid ?>, this)">🗑 Remove ▾</button>
                    <div class="remove-menu" id="remove-menu-<?= $tid ?>">
                        <?php if ($c['is_active']): ?>
                            <form method="POST" style="margin:0;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"   value="deactivate">
                                <input type="hidden" name="court_id" value="<?= $tid ?>">
                                <button type="submit" onclick="return confirm('Deactivate <?= clean(addslashes($c['name'])) ?>? Players won\'t be able to scan in, but all history is kept. You can reactivate it anytime.')">
                                    ⏸ Deactivate
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="POST" style="margin:0;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"   value="reactivate">
                                <input type="hidden" name="court_id" value="<?= $tid ?>">
                                <button type="submit">▶️ Reactivate</button>
                            </form>
                        <?php endif; ?>
                        <button type="button" class="danger-item"
                                onclick="openDeleteModal(<?= $tid ?>, <?= htmlspecialchars(json_encode($c['name']), ENT_QUOTES) ?>)">
                            🗑 Delete permanently
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Shared Delete Confirmation Modal ── -->
<div class="modal-overlay" id="courts-delete-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.8);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px;">
    <div style="background:var(--surface);border:2px solid var(--danger);border-radius:16px;padding:28px;max-width:420px;width:100%;">
        <div style="font-size:36px;text-align:center;margin-bottom:12px;">🗑</div>
        <div style="font-family:'Bebas Neue',sans-serif;font-size:24px;color:var(--danger);text-align:center;margin-bottom:8px;">
            Delete Court?
        </div>
        <p style="font-size:13px;color:var(--muted);text-align:center;line-height:1.6;margin-bottom:20px;">
            This action <strong style="color:var(--danger);">cannot be undone</strong>. All court data,
            hours, and settings will be permanently deleted.<br><br>
            Type <strong id="delete-modal-name" style="color:var(--text);"></strong> to confirm:
        </p>
        <form method="POST" id="courts-delete-form">
            <?= csrfField() ?>
            <input type="hidden" name="action"    value="delete_court">
            <input type="hidden" name="court_id"  id="delete-modal-court-id" value="">
            <input type="text" name="confirm_name" id="courts-confirm-name-input"
                   placeholder="Type court name here…"
                   style="width:100%;background:var(--surface2);border:2px solid var(--danger);border-radius:8px;padding:10px 14px;color:var(--text);font-size:14px;font-family:inherit;margin-bottom:16px;"
                   oninput="checkCourtsConfirm(this.value)" autocomplete="off"/>
            <div style="display:flex;gap:10px;">
                <button type="button" class="btn-outline btn-sm" style="flex:1;" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn-danger btn-sm" style="flex:1;" id="courts-confirm-delete-btn" disabled>🗑 Delete Permanently</button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
let currentDeleteCourtName = '';

const undoCountdownEl = document.getElementById('undo-countdown');
if (undoCountdownEl) {
    let secs = parseInt(undoCountdownEl.textContent, 10);
    const timer = setInterval(function() {
        secs -= 1;
        if (secs <= 0) {
            clearInterval(timer);
            const btn = undoCountdownEl.closest('form');
            if (btn) btn.style.display = 'none';
            return;
        }
        undoCountdownEl.textContent = secs;
    }, 1000);
}

function toggleRemoveMenu(courtId, btn) {
    const menu = document.getElementById('remove-menu-' + courtId);
    const wasOpen = menu.classList.contains('open');

    document.querySelectorAll('.remove-menu.open').forEach(m => m.classList.remove('open'));
    if (wasOpen) return;

    // Move the menu out to <body> the first time it's opened, so no
    // ancestor's overflow:hidden (the card) can ever clip it.
    if (menu.parentElement !== document.body) {
        document.body.appendChild(menu);
    }

    const rect = btn.getBoundingClientRect();
    menu.style.position = 'fixed';
    menu.style.top   = (rect.bottom + 4) + 'px';
    menu.style.left  = 'auto';
    menu.style.right = (window.innerWidth - rect.right) + 'px';
    menu.classList.add('open');
}
// Dropdowns are viewport-fixed while open; close them on scroll so they
// don't visually drift away from the button that opened them.
window.addEventListener('scroll', function() {
    document.querySelectorAll('.remove-menu.open').forEach(m => m.classList.remove('open'));
}, true);
window.addEventListener('resize', function() {
    document.querySelectorAll('.remove-menu.open').forEach(m => m.classList.remove('open'));
});
document.addEventListener('click', function(e) {
    if (!e.target.closest('.remove-menu-wrap') && !e.target.closest('.remove-menu')) {
        document.querySelectorAll('.remove-menu.open').forEach(m => m.classList.remove('open'));
    }
});

function openDeleteModal(courtId, courtName) {
    document.querySelectorAll('.remove-menu.open').forEach(m => m.classList.remove('open'));
    currentDeleteCourtName = courtName;
    document.getElementById('delete-modal-court-id').value = courtId;
    document.getElementById('delete-modal-name').textContent = courtName;
    document.getElementById('courts-confirm-name-input').value = '';
    document.getElementById('courts-confirm-delete-btn').disabled = true;
    document.getElementById('courts-delete-modal').style.display = 'flex';
}
function closeDeleteModal() {
    document.getElementById('courts-delete-modal').style.display = 'none';
}
function checkCourtsConfirm(val) {
    document.getElementById('courts-confirm-delete-btn').disabled = (val !== currentDeleteCourtName);
}
document.getElementById('courts-delete-modal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});
</script>

<!-- ── Summary Table ── -->
<div class="card">
    <div class="card-title" style="margin-bottom:16px;">📊 All Courts — Quick Reference</div>
    <div style="overflow-x:auto;">
        <table class="courts-summary-table">
            <thead>
                <tr>
                    <th>Court</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Credit Cost</th>
                    <th>Game Duration</th>
                    <th>Max Players</th>
                    <th>Active</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($courts as $c):
                    $statusKey = $c['live_status'] ?? 'closed';
                    $sc  = $statusConfig[$statusKey] ?? $statusConfig['closed'];
                    $tid = (int)$c['id'];
                    $courtColor = clean($c['color'] ?? '#00e5a0');
                    $typeIcon   = $typeIcons[$c['court_type'] ?? 'covered'] ?? '🏓';
                ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:10px;height:10px;border-radius:3px;background:<?= $courtColor ?>;flex-shrink:0;"></div>
                            <div>
                                <div style="font-weight:700;"><?= clean($c['name']) ?></div>
                                <div style="font-size:11px;color:var(--muted);font-family:monospace;"><?= clean($c['short_code'] ?? '') ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?= $typeIcon ?> <?= ucfirst(clean($c['court_type'] ?? '—')) ?></td>
                    <td>
                        <span class="status-pill <?= $sc['class'] ?>" style="font-size:11px;padding:2px 8px;">
                            <?= $sc['icon'] ?> <?= $sc['label'] ?>
                        </span>
                    </td>
                    <td style="font-family:monospace;color:var(--accent);">₱<?= number_format((float)($c['credit_cost'] ?? 0), 0) ?></td>
                    <td><?= (int)($c['game_duration'] ?? 15) ?> min</td>
                    <td><?= (int)($c['max_queue'] ?? 4) ?> players</td>
                    <td>
                        <?php if ($c['is_active']): ?>
                            <span style="color:var(--success);font-weight:700;">✅ Yes</span>
                        <?php else: ?>
                            <span style="color:var(--muted);">⏸ No</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>