<?php
// ============================================================
//  FILE: admin/court_settings.php
//  MODIFIED: Multi-court support — court selector tabs added.
//            Removed hardcoded ORDER BY id LIMIT 1.
//            All data fetches now use WHERE id = $courtId.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/activity_logger.php';

error_log('[COURT_SETTINGS] method=' . $_SERVER['REQUEST_METHOD']
    . ' session_id=' . session_id()
    . ' csrf_session=' . ($_SESSION['csrf_token'] ?? 'MISSING')
    . ' csrf_post='    . ($_POST['csrf_token']    ?? 'n/a')
    . ' fp='           . ($_SESSION['_fp']        ?? 'MISSING')
);

requireAdmin();

$db     = getDB();
$errors = [];

// ── Load all active courts for the selector ──────────────────
$allCourts = $db->query(
    "SELECT id, name, short_code, color FROM falcon.courts WHERE is_active = TRUE ORDER BY sort_order, id"
)->fetchAll();

// Fallback: include inactive courts if no active ones exist
if (empty($allCourts)) {
    $allCourts = $db->query(
        "SELECT id, name, short_code, color FROM falcon.courts ORDER BY sort_order, id"
    )->fetchAll();
}

// ── Determine selected court ──────────────────────────────────
$defaultCourtId = !empty($allCourts) ? (int)$allCourts[0]['id'] : 0;
$courtId = filter_input(INPUT_GET,  'court', FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_POST, 'court', FILTER_VALIDATE_INT)
        ?: $defaultCourtId;

// Validate the court_id belongs to a real court
$validIds = array_column($allCourts, 'id');
if (!in_array($courtId, array_map('intval', $validIds))) {
    $courtId = $defaultCourtId;
}

// ── PostGIS detection ────────────────────────────────────────
$hasPostGIS = false;
try {
    $db->query("SELECT ST_X(location) FROM falcon.courts WHERE location IS NOT NULL LIMIT 1");
    $hasPostGIS = true;
} catch (PDOException $e) {}

// ── Load selected court ───────────────────────────────────────
// FIX: Was ORDER BY id LIMIT 1 — now uses WHERE id = $courtId
$sql = $hasPostGIS
    ? "SELECT *, ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng FROM falcon.courts WHERE id = ?"
    : "SELECT *, NULL AS lat, NULL AS lng FROM falcon.courts WHERE id = ?";
$courtStmt = $db->prepare($sql);
$courtStmt->execute([$courtId]);
$court = $courtStmt->fetch();

if (!$court) {
    setFlash('error', 'Court not found.');
    redirect('admin/dashboard.php');
}

// ── Handle POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? 'save';

    // Quick toggle open/close — scoped to selected court
    if ($action === 'toggle') {
        $db->prepare("UPDATE falcon.courts SET is_active = NOT is_active WHERE id = ?")
           ->execute([$courtId]);
        setFlash('success', $court['is_active'] ? '⏸ Court closed.' : '▶ Court opened.');
        redirect('admin/court_settings.php?court=' . $courtId);
    }

    if ($action === 'save') {
        $name        = sanitizeString($_POST['name']        ?? '', 100);
        $description = sanitizeString($_POST['description'] ?? '', 500);
        $address     = sanitizeString($_POST['address']     ?? '', 255);
        $passFee       = filter_input(INPUT_POST, 'credit_cost',   FILTER_VALIDATE_FLOAT);
        $duration      = filter_input(INPUT_POST, 'game_duration', FILTER_VALIDATE_INT);
        $warmup        = filter_input(INPUT_POST, 'warmup_mins',   FILTER_VALIDATE_INT);
        $passHours     = filter_input(INPUT_POST, 'pass_hours',    FILTER_VALIDATE_FLOAT);
        $maxQueue      = filter_input(INPUT_POST, 'max_queue',     FILTER_VALIDATE_INT);
        $isActive      = isset($_POST['is_active']);
        $isMaintenance = isset($_POST['is_maintenance']);
        $lat           = filter_input(INPUT_POST, 'latitude',  FILTER_VALIDATE_FLOAT);
        $lng           = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);

        if (empty($name))                  $errors[] = 'Court name is required.';
        if (!$passFee   || $passFee < 1)   $errors[] = 'Day pass fee must be at least ₱1.';
        if (!$duration  || $duration < 5)  $errors[] = 'Game duration must be at least 5 minutes.';
        if ($warmup === false || $warmup < 0) $errors[] = 'Warmup time cannot be negative.';
        if (!$passHours || $passHours < 0.5) $errors[] = 'Pass duration must be at least 0.5 hours.';
        if ($passHours  > 24)              $errors[] = 'Pass duration cannot exceed 24 hours.';
        if (!$maxQueue  || $maxQueue < 2)  $errors[] = 'Players per game must be at least 2.';
        if ($maxQueue   > 40)              $errors[] = 'Players per game cannot exceed 40.';

        if (empty($errors)) {
            $hasCoords = ($lat !== false && $lat !== null && $lng !== false && $lng !== null);

            try {
                if ($hasPostGIS && $hasCoords) {
                    $db->prepare("
                        UPDATE falcon.courts
                        SET name=?, description=?, address=?,
                            location=ST_SetSRID(ST_MakePoint(?,?),4326),
                            credit_cost=?, game_duration=?, warmup_mins=?,
                            pass_hours=?, max_queue=?, is_active=?, is_maintenance=?, updated_at=NOW()
                        WHERE id=?
                    ")->execute([
                        $name, $description ?: null, $address ?: null,
                        $lng, $lat,
                        $passFee, $duration, $warmup,
                        $passHours, $maxQueue, $isActive, $isMaintenance,
                        $courtId
                    ]);
                } else {
                    $db->prepare("
                        UPDATE falcon.courts
                        SET name=?, description=?, address=?,
                            credit_cost=?, game_duration=?, warmup_mins=?,
                            pass_hours=?, max_queue=?, is_active=?, is_maintenance=?, updated_at=NOW()
                        WHERE id=?
                    ")->execute([
                        $name, $description ?: null, $address ?: null,
                        $passFee, $duration, $warmup,
                        $passHours, $maxQueue, $isActive, $isMaintenance,
                        $courtId
                    ]);
                }

                // ── Save reservation settings ─────────────────────────────
                $rsvPrice   = filter_input(INPUT_POST, 'resv_price_per_hour', FILTER_VALIDATE_FLOAT);
                $rsvMin     = filter_input(INPUT_POST, 'resv_min_hours',      FILTER_VALIDATE_FLOAT);
                $rsvMax     = filter_input(INPUT_POST, 'resv_max_hours',      FILTER_VALIDATE_FLOAT);
                $rsvDeposit = filter_input(INPUT_POST, 'resv_deposit_pct',    FILTER_VALIDATE_INT);
                $rsvEnabled = isset($_POST['resv_enabled']) ? '1' : '0';
                $rsvAdvance = filter_input(INPUT_POST, 'resv_advance_days',   FILTER_VALIDATE_INT);

                $resvMap = [
                    'reservation_price_per_hour' => max(0,    (float)($rsvPrice   ?? 150)),
                    'reservation_min_hours'      => max(0.5, min(24,  (float)($rsvMin    ?? 2))),
                    'reservation_max_hours'      => max(1,   min(24,  (float)($rsvMax    ?? 8))),
                    'reservation_deposit_pct'    => max(0,   min(100, (int)($rsvDeposit  ?? 50))),
                    'reservation_enabled'        => $rsvEnabled,
                    'reservation_advance_days'   => max(1,   min(90,  (int)($rsvAdvance  ?? 14))),
                ];

                try {
                    $db->exec("
                        CREATE TABLE IF NOT EXISTS falcon.court_settings (
                            id       SERIAL PRIMARY KEY,
                            court_id INT NOT NULL,
                            key      VARCHAR(100) NOT NULL,
                            value    TEXT,
                            UNIQUE(court_id, key)
                        )
                    ");
                    foreach ($resvMap as $k => $v) {
                        $db->prepare("
                            INSERT INTO falcon.court_settings (court_id, key, value)
                            VALUES (?, ?, ?)
                            ON CONFLICT (court_id, key) DO UPDATE SET value = EXCLUDED.value
                        ")->execute([$courtId, $k, $v]);
                    }
                } catch (PDOException $e) {
                    error_log('court_settings save error: ' . $e->getMessage());
                }

                logActivity('Court Updated', 'admin', 'normal',
                    "Court #{$courtId} settings saved by " . ($_SESSION['username'] ?? 'admin'));
                setFlash('success', '✅ Court settings saved.');
                redirect('admin/court_settings.php?court=' . $courtId);

            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// ── Live stats — scoped to selected court ─────────────────────
// FIX: Was global query; now filters by court_id = $courtId
$liveStmt = $db->prepare("
    SELECT
        COUNT(*) FILTER (WHERE gs.status = 'active')                              AS active_games,
        COUNT(*) FILTER (WHERE DATE(gs.started_at) = CURRENT_DATE)                AS games_today,
        COUNT(DISTINCT gp.user_id) FILTER (WHERE DATE(gs.started_at) = CURRENT_DATE) AS players_today,
        (SELECT COUNT(*) FROM falcon.player_passes pp
         WHERE pp.is_active = TRUE
           AND pp.expires_at > NOW()
           AND pp.expires_at < NOW() + INTERVAL '25 hours')                        AS active_passes_now
    FROM falcon.game_sessions gs
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    WHERE gs.court_id = ?
");
$liveStmt->execute([$courtId]);
$live      = $liveStmt->fetch();
$isPlaying = (int)$live['active_games'] > 0;

// ── Court hours ──────────────────────────────────────────────
$hoursStmt = $db->prepare(
    "SELECT day_of_week, open_time, close_time, is_closed
     FROM falcon.court_hours WHERE court_id = ? ORDER BY day_of_week"
);
$hoursStmt->execute([$courtId]);
$courtHours = $hoursStmt->fetchAll();
$dayNames   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

// ── Reservation settings ─────────────────────────────────────
$resvSettings = [];
try {
    $rsStmt = $db->prepare("SELECT key, value FROM falcon.court_settings WHERE court_id = ?");
    $rsStmt->execute([$courtId]);
    foreach ($rsStmt->fetchAll() as $row) {
        $resvSettings[$row['key']] = $row['value'];
    }
} catch (PDOException $e) { /* table may not exist — defaults below */ }

$resvPricePerHour = (float)($resvSettings['reservation_price_per_hour'] ?? 150);
$resvMinHours     = (float)($resvSettings['reservation_min_hours']      ?? 2);
$resvMaxHours     = (float)($resvSettings['reservation_max_hours']      ?? 8);
$resvDepositPct   = (int)($resvSettings['reservation_deposit_pct']      ?? 50);
$resvEnabled      = ($resvSettings['reservation_enabled']               ?? '1') === '1';
$resvAdvanceDays  = (int)($resvSettings['reservation_advance_days']     ?? 14);

// ── Calculator helper ─────────────────────────────────────────
$passHoursVal = (float)($court['pass_hours'] ?? 3);
$gameDurVal   = (int)($court['game_duration'] ?? 15);
$warmupVal    = (int)($court['warmup_mins'] ?? 2);
$cycleMin     = $gameDurVal + $warmupVal;
$gamesPerPass = ($cycleMin > 0) ? floor(($passHoursVal * 60) / $cycleMin) : 0;

$pageTitle = 'Court Settings — ' . clean($court['name']);
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Court Settings — fully responsive ─────────────────────── */
.cs-layout {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 24px;
    align-items: start;
}
@media (max-width: 900px) { .cs-layout { grid-template-columns: 1fr; } }

.cs-section { border: 1px solid var(--border); border-radius: 12px; padding: 18px; margin-bottom: 18px; }
.cs-section-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 14px; }

.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 480px) { .form-row-2 { grid-template-columns: 1fr; gap: 0; } }

.form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
@media (max-width: 640px) { .form-row-3 { grid-template-columns: 1fr 1fr; } }
@media (max-width: 400px) { .form-row-3 { grid-template-columns: 1fr; gap: 0; } }

.preset-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.preset-btn {
    background: var(--surface2); border: 1px solid var(--border); color: var(--text);
    border-radius: 10px; padding: 10px 6px; font-size: 11px; font-weight: 700;
    cursor: pointer; text-align: center; transition: all 0.15s; font-family: 'DM Sans', sans-serif;
    touch-action: manipulation; min-height: 52px; line-height: 1.4;
}
.preset-btn:hover { border-color: var(--accent); color: var(--accent); background: rgba(0,229,160,0.08); }
@media (max-width: 560px) { .preset-grid { grid-template-columns: repeat(2, 1fr); } }

.toggle-row {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 13px 15px; background: var(--surface2); border: 1px solid var(--border);
    border-radius: 10px; margin-bottom: 14px; min-width: 0;
}
.toggle-label-text { font-size: 14px; font-weight: 600; min-width: 0; }
.toggle-label-text small { display: block; font-size: 12px; color: var(--muted); font-weight: 400; margin-top: 2px; }
.toggle-switch { position: relative; width: 48px; height: 26px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; inset: 0; background: var(--border); border-radius: 26px; cursor: pointer; transition: background 0.2s; }
.toggle-slider::before { content: ''; position: absolute; width: 20px; height: 20px; border-radius: 50%; left: 3px; bottom: 3px; background: #fff; transition: transform 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,.3); }
.toggle-switch input:checked + .toggle-slider { background: var(--accent); }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(22px); }

.cs-stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
.cs-stat { background: var(--surface2); border: 1px solid var(--border); border-radius: 10px; padding: 12px 10px; text-align: center; min-width: 0; }
.cs-stat-val { font-family: 'Bebas Neue', sans-serif; font-size: clamp(22px, 4vw, 30px); color: var(--accent); line-height: 1; word-break: break-all; }
.cs-stat-label { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; margin-top: 3px; }

.pass-value-box { background: rgba(0,229,160,0.07); border: 1px solid rgba(0,229,160,0.2); border-radius: 10px; padding: 14px; text-align: center; margin-bottom: 14px; }

.hours-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 10px; border-radius: 8px; margin-bottom: 5px; font-size: 13px; gap: 8px; min-width: 0; }
.hours-row:last-child { margin-bottom: 0; }
.field-note { font-size: 11px; color: var(--muted); margin-top: 3px; }

.status-banner { display: flex; align-items: center; gap: 14px; padding: 14px 18px; border-radius: 12px; margin-bottom: 24px; flex-wrap: wrap; }
.status-banner-icon { font-size: clamp(24px, 5vw, 36px); flex-shrink: 0; }

.cs-live-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin-bottom: 24px; }
.btn-save { width: 100%; min-height: 52px; font-size: 16px; font-weight: 700; }

@media (max-width: 900px) { .cs-stat-grid { grid-template-columns: repeat(4, 1fr); } }
@media (max-width: 560px) { .cs-stat-grid { grid-template-columns: 1fr 1fr; } }

/* ── Court selector tabs ── */
.court-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 24px; }
.court-tab {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 14px; border-radius: 8px;
    font-size: 13px; font-weight: 700;
    text-decoration: none; color: var(--muted);
    border: 1.5px solid var(--border);
    background: var(--surface);
    transition: all 0.15s;
}
.court-tab:hover { color: var(--text); border-color: var(--accent); }
.court-tab.active { color: #000; font-weight: 800; }
.court-tab-code {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 15px; letter-spacing: 1px;
}
</style>

<!-- ── Page Header ── -->
<div class="page-header flex-between">
    <div>
        <h1>Court Settings</h1>
        <p>Game rules, day pass pricing &amp; court info</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <?php if ($isPlaying): ?>
            <a href="<?= APP_URL ?>/admin/active_game.php" class="btn-success btn-sm">🟢 Live Game</a>
        <?php endif; ?>
        <form method="POST" style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle"/>
            <input type="hidden" name="court"  value="<?= $courtId ?>"/>
            <button type="submit"
                    class="<?= $court['is_active'] ? 'btn-warn' : 'btn-primary' ?> btn-sm"
                    <?= $isPlaying ? 'disabled title="Game in progress"' : '' ?>>
                <?= $court['is_active'] ? '⏸ Close Court' : '▶ Open Court' ?>
            </button>
        </form>
        <a href="<?= APP_URL ?>/admin/courts.php"    class="btn-outline btn-sm">🏟️ All Courts</a>
        <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<!-- ── Court Selector Tabs ── -->
<?php if (count($allCourts) > 1): ?>
<div class="court-tabs">
    <?php foreach ($allCourts as $ct):
        $ctColor  = clean($ct['color'] ?? '#00e5a0');
        $isActive = ((int)$ct['id'] === $courtId);
    ?>
        <a href="?court=<?= $ct['id'] ?>"
           class="court-tab <?= $isActive ? 'active' : '' ?>"
           style="<?= $isActive
               ? "background:{$ctColor};border-color:{$ctColor};color:#000;"
               : "border-color:{$ctColor}33;" ?>">
            <span class="court-tab-code" style="color:<?= $isActive ? '#000' : $ctColor ?>;">
                <?= clean($ct['short_code'] ?? '?') ?>
            </span>
            <?= clean($ct['name']) ?>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php $flash = getFlash(); if ($flash): ?>
<div class="flash flash-<?= $flash['type']==='success'?'success':'error' ?>" style="margin-bottom:16px;border-radius:10px;">
    <?= clean($flash['message']) ?>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $e): ?>
        <div class="flash flash-error" style="margin-bottom:10px;border-radius:10px;">
            <?= clean($e) ?> <button onclick="this.parentElement.remove()">✕</button>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- ── Status Banner ── -->
<div class="status-banner"
     style="background:<?= $isPlaying ? 'rgba(16,185,129,0.08)' : ($court['is_active'] ? 'rgba(0,229,160,0.06)' : 'rgba(100,116,139,0.08)') ?>;
            border:1px solid <?= $isPlaying ? 'var(--success)' : ($court['is_active'] ? 'var(--accent)' : 'var(--border)') ?>;">
    <div class="status-banner-icon"><?= $isPlaying ? '🏓' : ($court['is_active'] ? '✅' : '⏸') ?></div>
    <div style="min-width:0;flex:1;">
        <div style="font-family:'Bebas Neue',sans-serif;font-size:clamp(18px,4vw,24px);color:<?= clean($court['color']??'var(--accent)') ?>;">
            <?= clean($court['name']) ?>
            <?php if (!empty($court['short_code'])): ?>
            <span style="font-size:14px;color:var(--muted);margin-left:8px;"><?= clean($court['short_code']) ?></span>
            <?php endif; ?>
        </div>
        <div style="font-size:13px;color:var(--muted);display:flex;flex-wrap:wrap;gap:10px;margin-top:2px;">
            <?php if ($isPlaying): ?>
                <span style="color:var(--success);font-weight:700;">● Game in progress</span>
            <?php elseif ($court['is_active']): ?>
                <span style="color:var(--accent);font-weight:700;">● Open for play</span>
            <?php else: ?>
                <span>⏸ Closed — QR scans rejected</span>
            <?php endif; ?>
            <?php if ($court['address']): ?>
                <span>📍 <?= clean($court['address']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div>
        <a href="<?= APP_URL ?>/admin/court_edit.php?court_id=<?= $courtId ?>"
           class="btn-outline btn-sm">✏️ Edit Court</a>
    </div>
</div>

<!-- ── Live Stats ── -->
<div class="cs-live-grid">
    <div class="stat-card">
        <div class="stat-val" style="color:<?= $isPlaying ? 'var(--success)' : 'var(--muted)' ?>;"><?= $isPlaying ? '1' : '0' ?></div>
        <div class="stat-label">Active Game</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= (int)$live['games_today'] ?></div>
        <div class="stat-label">Games Today</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--accent);"><?= (int)$live['active_passes_now'] ?></div>
        <div class="stat-label">Active Passes</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= (int)$live['players_today'] ?></div>
        <div class="stat-label">Players Today</div>
    </div>
</div>

<!-- ── Main Layout ── -->
<div class="cs-layout">

    <!-- LEFT: Edit Form -->
    <div>
        <form method="POST" id="settings-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save"/>
            <input type="hidden" name="court"  value="<?= $courtId ?>"/>

            <!-- Presets -->
            <div class="card mb-3">
                <div class="card-title" style="margin-bottom:4px;">⚡ Quick Presets</div>
                <div class="card-subtitle" style="margin-bottom:14px;">Apply a common configuration instantly</div>
                <div class="preset-grid">
                    <button type="button" class="preset-btn" onclick="applyPreset(4,15,2,8,50)">
                        🎯 Standard<br/><small>15m · 2w · 8h · ₱50</small>
                    </button>
                    <button type="button" class="preset-btn" onclick="applyPreset(4,10,1,4,30)">
                        ⚡ Short<br/><small>10m · 1w · 4h · ₱30</small>
                    </button>
                    <button type="button" class="preset-btn" onclick="applyPreset(4,20,3,12,80)">
                        🏆 Extended<br/><small>20m · 3w · 12h · ₱80</small>
                    </button>
                    <button type="button" class="preset-btn" onclick="applyPreset(2,15,1,4,25)">
                        🚀 Quick<br/><small>15m · 1w · 4h · ₱25</small>
                    </button>
                </div>
            </div>

            <!-- Court Info -->
            <div class="card mb-3">
                <div class="card-title" style="margin-bottom:14px;">🏟️ Court Info</div>

                <div class="toggle-row">
                    <div class="toggle-label-text">
                        Court Active
                        <small>Players can scan in and join queue</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="is_active" <?= $court['is_active'] ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                <div class="toggle-row">
                    <div class="toggle-label-text">
                        Maintenance Mode
                        <small>Court cannot accept new queue entries</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="is_maintenance" <?= $court['is_maintenance'] ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label>Court Name <span style="color:var(--danger);">*</span></label>
                        <input type="text" name="name" value="<?= clean($court['name']) ?>"
                               maxlength="100" required placeholder="e.g. Padol Court 1"/>
                    </div>
                    <div class="form-group">
                        <label>Address / Venue</label>
                        <input type="text" name="address"
                               value="<?= clean($court['address'] ?? '') ?>"
                               maxlength="255" placeholder="e.g. Padol Sports Complex"/>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Description</label>
                    <textarea name="description" rows="2" style="resize:vertical;"
                              placeholder="Optional notes about this court…"><?= clean($court['description'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Day Pass Settings -->
            <div class="card mb-3">
                <div class="cs-section-title" style="color:var(--accent);">🎫 Day Pass Settings</div>
                <div class="form-row-2">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Day Pass Fee (₱) <span style="color:var(--danger);">*</span></label>
                        <input type="number" name="credit_cost" id="inp-cost"
                               value="<?= (float)$court['credit_cost'] ?>"
                               min="1" max="9999" step="0.50" required oninput="updatePreviews()"/>
                        <div class="field-note">Charged once per top-up — covers the full pass window</div>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Pass Duration (hours) <span style="color:var(--danger);">*</span></label>
                        <input type="number" name="pass_hours" id="inp-hours"
                               value="<?= (float)($court['pass_hours'] ?? 8) ?>"
                               min="0.5" max="24" step="0.5" required oninput="updatePreviews()"/>
                        <div class="field-note">How long the QR window lasts after first scan</div>
                    </div>
                </div>
            </div>

            <!-- Game Rules -->
            <div class="card mb-3">
                <div class="cs-section-title" style="color:var(--warn);">🏓 Game Rules</div>
                <div class="form-row-3">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Game Duration (min) <span style="color:var(--danger);">*</span></label>
                        <input type="number" name="game_duration" id="inp-dur"
                               value="<?= (int)$court['game_duration'] ?>"
                               min="5" max="300" step="5" required oninput="updatePreviews()"/>
                        <div class="field-note">Length of each game</div>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Warmup Time (min) <span style="color:var(--danger);">*</span></label>
                        <input type="number" name="warmup_mins" id="inp-warmup"
                               value="<?= (int)($court['warmup_mins'] ?? 2) ?>"
                               min="0" max="15" step="1" required oninput="updatePreviews()"/>
                        <div class="field-note">Transition time between games</div>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Players per Game <span style="color:var(--danger);">*</span></label>
                        <input type="number" name="max_queue" id="inp-queue"
                               value="<?= (int)$court['max_queue'] ?>"
                               min="2" max="40" step="1" required oninput="updatePreviews()"/>
                        <div class="field-note">Game starts when queue is full</div>
                    </div>
                </div>
            </div>

            <!-- Reservation Settings -->
            <div class="card mb-3">
                <div class="cs-section-title" style="color:var(--accent2);">📅 Reservation Settings</div>

                <div class="toggle-row">
                    <div class="toggle-label-text">
                        Reservations Enabled
                        <small>Allow players to book specific time slots in advance</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="resv_enabled" <?= $resvEnabled ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label>Price per Hour (₱) <span style="color:var(--danger);">*</span></label>
                        <input type="number" name="resv_price_per_hour" id="inp-resv-price"
                               value="<?= $resvPricePerHour ?>" min="0" max="9999" step="10"
                               oninput="updateResvPreview()"/>
                        <div class="field-note">Charged per hour of court reservation time</div>
                    </div>
                    <div class="form-group">
                        <label>Deposit Required (%)</label>
                        <input type="number" name="resv_deposit_pct" id="inp-resv-deposit"
                               value="<?= $resvDepositPct ?>" min="0" max="100" step="5"
                               oninput="updateResvPreview()"/>
                        <div class="field-note">0% = full payment on arrival · 100% = full upfront</div>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Minimum Hours <span style="color:var(--danger);">*</span></label>
                        <input type="number" name="resv_min_hours" id="inp-resv-min"
                               value="<?= $resvMinHours ?>" min="0.5" max="12" step="0.5"
                               oninput="updateResvPreview()"/>
                        <div class="field-note">Bookings shorter than this are auto-denied</div>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Maximum Hours</label>
                        <input type="number" name="resv_max_hours" id="inp-resv-max"
                               value="<?= $resvMaxHours ?>" min="1" max="24" step="0.5"
                               oninput="updateResvPreview()"/>
                        <div class="field-note">Max duration a player can reserve</div>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Book in Advance (days)</label>
                        <input type="number" name="resv_advance_days" id="inp-resv-advance"
                               value="<?= $resvAdvanceDays ?>" min="1" max="90" step="1"/>
                        <div class="field-note">How far ahead players can book</div>
                    </div>
                </div>

                <div style="margin-top:14px;padding:12px 14px;background:rgba(0,184,255,0.06);
                            border:1px solid rgba(0,184,255,0.2);border-radius:10px;font-size:13px;"
                     id="resv-preview-box">
                    <div style="font-weight:700;color:var(--accent2);margin-bottom:6px;">📊 Reservation Preview</div>
                    <div id="resv-preview-text" style="color:var(--muted);line-height:1.8;"></div>
                </div>
            </div>

            <!-- GPS — only if PostGIS available -->
            <?php if ($hasPostGIS): ?>
            <div class="card mb-3">
                <div class="card-title" style="margin-bottom:4px;">📍 GPS Coordinates</div>
                <div class="card-subtitle" style="margin-bottom:14px;">Optional — used for the player map view</div>
                <div class="form-row-2">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Latitude</label>
                        <input type="number" name="latitude" step="0.000001"
                               placeholder="e.g. 6.085000"
                               value="<?= $court['lat'] ? htmlspecialchars((string)$court['lat'], ENT_QUOTES) : '' ?>"/>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Longitude</label>
                        <input type="number" name="longitude" step="0.000001"
                               placeholder="e.g. 124.996000"
                               value="<?= $court['lng'] ? htmlspecialchars((string)$court['lng'], ENT_QUOTES) : '' ?>"/>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Save -->
            <button type="submit" class="btn-primary btn-save"
                    <?= $isPlaying ? "onclick=\"return confirm('A game is in progress. Save settings anyway?')\"" : '' ?>>
                💾 Save Court Settings
            </button>
        </form>
    </div>

    <!-- RIGHT: Sidebar -->
    <div style="display:flex;flex-direction:column;gap:18px;">

        <!-- Live preview tiles -->
        <div class="card">
            <div class="card-title" style="margin-bottom:12px;">⚙️ Live Preview</div>
            <div class="cs-stat-grid">
                <div class="cs-stat">
                    <div class="cs-stat-val" id="preview-cost">₱<?= number_format((float)$court['credit_cost'], 0) ?></div>
                    <div class="cs-stat-label">Pass Fee</div>
                </div>
                <div class="cs-stat">
                    <div class="cs-stat-val" id="preview-hours"><?= (float)($court['pass_hours'] ?? 8) ?>h</div>
                    <div class="cs-stat-label">Pass Duration</div>
                </div>
                <div class="cs-stat">
                    <div class="cs-stat-val" id="preview-duration"><?= (int)$court['game_duration'] ?>m</div>
                    <div class="cs-stat-label">Game Length</div>
                </div>
                <div class="cs-stat">
                    <div class="cs-stat-val" id="preview-warmup"><?= (int)($court['warmup_mins'] ?? 2) ?>m</div>
                    <div class="cs-stat-label">Warmup</div>
                </div>
            </div>

            <div class="pass-value-box">
                <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">
                    Pass Value Calculator
                </div>
                <div style="font-family:'Bebas Neue',sans-serif;font-size:clamp(24px,4vw,32px);color:var(--accent);" id="preview-games">
                    <?= $gamesPerPass ?> games / pass
                </div>
                <div style="font-size:12px;color:var(--muted);margin-top:3px;" id="preview-cost-per">
                    <?php if ($gamesPerPass > 0): ?>
                        ≈ ₱<?= number_format((float)$court['credit_cost'] / $gamesPerPass, 2) ?>/game per player
                    <?php endif; ?>
                </div>
            </div>

            <div id="pass-calc" style="font-size:12px;color:var(--muted);line-height:1.6;"></div>
        </div>

        <!-- Court hours (read-only display) -->
        <?php if (!empty($courtHours)): ?>
        <div class="card">
            <div class="card-title" style="margin-bottom:12px;">🕐 Operating Hours</div>
            <?php foreach ($courtHours as $h): ?>
            <div class="hours-row"
                 style="background:<?= $h['is_closed'] ? 'var(--surface2)' : 'rgba(0,229,160,0.05)' ?>;
                        border:1px solid <?= $h['is_closed'] ? 'var(--border)' : 'rgba(0,229,160,0.15)' ?>;">
                <span style="font-weight:600;"><?= $dayNames[$h['day_of_week']] ?></span>
                <?php if ($h['is_closed']): ?>
                    <span style="font-size:12px;color:var(--muted);">Closed</span>
                <?php else: ?>
                    <span style="font-family:monospace;font-size:12px;color:var(--accent);">
                        <?= date('h:i A', strtotime($h['open_time'])) ?> – <?= date('h:i A', strtotime($h['close_time'])) ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <div style="margin-top:10px;">
                <a href="<?= APP_URL ?>/admin/court_hours.php?court=<?= $courtId ?>"
                   class="btn-outline btn-sm" style="display:block;text-align:center;">
                    📅 Manage Hours →
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Auto-start info -->
        <div class="card">
            <div class="card-title" style="margin-bottom:8px;">⏳ Auto-Start Info</div>
            <div style="font-size:13px;color:var(--muted);line-height:1.7;">
                When <strong style="color:var(--text);" id="info-queue"><?= (int)$court['max_queue'] ?></strong> players
                are in queue, a <strong style="color:var(--warn);">warmup countdown</strong> begins automatically.
            </div>
            <div style="margin-top:10px;padding:10px;background:var(--surface2);border-radius:8px;font-size:13px;">
                Warmup: <strong style="color:var(--warn);" id="info-warmup"><?= (int)($court['warmup_mins'] ?? 2) ?> min</strong>
                &nbsp;·&nbsp;
                Game: <strong id="info-dur"><?= (int)$court['game_duration'] ?> min</strong>
            </div>
            <div style="margin-top:8px;">
                <a href="<?= APP_URL ?>/admin/active_game.php" class="btn-outline btn-sm" style="margin-right:6px;">🎮 Monitor</a>
                <a href="<?= APP_URL ?>/staff/open_play_control.php" class="btn-outline btn-sm">🎲 Open Play Queue</a>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
function updatePreviews() {
    const cost    = parseFloat(document.getElementById('inp-cost')?.value)    || 0;
    const hours   = parseFloat(document.getElementById('inp-hours')?.value)   || 0;
    const dur     = parseInt(document.getElementById('inp-dur')?.value)       || 0;
    const warmup  = parseInt(document.getElementById('inp-warmup')?.value)    || 0;
    const players = parseInt(document.getElementById('inp-queue')?.value)     || 0;
    const cycle   = dur + warmup;
    const maxGames= cycle > 0 ? Math.floor((hours * 60) / cycle) : 0;
    const perGame = maxGames > 0 ? (cost / maxGames).toFixed(2) : '—';
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('preview-cost',     '₱' + cost.toFixed(0));
    set('preview-hours',    hours + 'h');
    set('preview-duration', dur + 'm');
    set('preview-warmup',   warmup + 'm');
    set('preview-games',    maxGames ? maxGames + ' games / pass' : '— games / pass');
    set('preview-cost-per', (maxGames > 0 && cost > 0) ? '≈ ₱' + perGame + '/game per player' : '');
    set('pass-calc', hours > 0 && dur > 0 ? `${hours}h pass → up to ${maxGames} games (${dur}m + ${warmup}m warmup each)` : '');
    set('info-queue',  players);
    set('info-warmup', warmup + ' min');
    set('info-dur',    dur + ' min');
}

function applyPreset(queue, dur, warmup, hours, cost) {
    const fields = { 'inp-queue':queue, 'inp-dur':dur, 'inp-warmup':warmup, 'inp-hours':hours, 'inp-cost':cost };
    for (const [id, val] of Object.entries(fields)) {
        const el = document.getElementById(id);
        if (el) { el.value = val; el.style.borderColor = 'var(--accent)'; setTimeout(() => el.style.borderColor = '', 1500); }
    }
    updatePreviews();
}

function updateResvPreview() {
    const price   = parseFloat(document.getElementById('inp-resv-price')?.value)   || 0;
    const minH    = parseFloat(document.getElementById('inp-resv-min')?.value)    || 2;
    const maxH    = parseFloat(document.getElementById('inp-resv-max')?.value)    || 8;
    const deposit = parseInt(document.getElementById('inp-resv-deposit')?.value)  || 0;
    const minCost    = (price * minH).toFixed(0);
    const maxCost    = (price * maxH).toFixed(0);
    const minDeposit = (price * minH * deposit / 100).toFixed(0);
    const el = document.getElementById('resv-preview-text');
    if (el) el.innerHTML = `
        Min booking: <strong style="color:var(--text);">${minH}h</strong> →
        <strong style="color:var(--accent2);">₱${minCost}</strong>
        ${deposit > 0 ? `(₱${minDeposit} deposit upfront)` : '(full payment on arrival)'}<br>
        Max booking: <strong style="color:var(--text);">${maxH}h</strong> →
        <strong style="color:var(--accent2);">₱${maxCost}</strong><br>
        Bookings under <strong style="color:var(--danger);">${minH}h</strong> are
        <strong style="color:var(--danger);">automatically denied</strong>
    `;
}
updateResvPreview();
updatePreviews();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>