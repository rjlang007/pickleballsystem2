<?php
// ============================================================
//  FILE: admin/court_edit.php
//  Edit an existing court — URL: court_edit.php?court_id=X
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db      = getDB();
$errors  = [];
$courtId = filter_input(INPUT_GET, 'court_id', FILTER_VALIDATE_INT)
         ?: filter_input(INPUT_POST, 'court_id', FILTER_VALIDATE_INT);

if (!$courtId) {
    setFlash('error', 'No court specified.');
    redirect('admin/courts.php');
}

// ── Load court ────────────────────────────────────────────────
$court = $db->prepare("SELECT * FROM falcon.courts WHERE id = ?");
$court->execute([$courtId]);
$court = $court->fetch();

if (!$court) {
    setFlash('error', 'Court not found.');
    redirect('admin/courts.php');
}

// ── Check if deletable ────────────────────────────────────────
$hasActiveSession = (bool)$db->prepare("SELECT 1 FROM falcon.game_sessions WHERE court_id=? AND status='active' LIMIT 1")
    ->execute([$courtId]) && $db->prepare("SELECT 1 FROM falcon.game_sessions WHERE court_id=? AND status='active' LIMIT 1")->fetchColumn();

$futureBkCheck = $db->prepare("
    SELECT COUNT(*) FROM falcon.reservations
    WHERE court_id = ? AND status IN ('pending','confirmed') AND slot_date >= CURRENT_DATE
");
$futureBkCheck->execute([$courtId]);
$hasFutureBookings = (int)$futureBkCheck->fetchColumn() > 0;

// Re-check active session properly
$activeCheck = $db->prepare("SELECT COUNT(*) FROM falcon.game_sessions WHERE court_id=? AND status='active'");
$activeCheck->execute([$courtId]);
$hasActiveSession = (int)$activeCheck->fetchColumn() > 0;

$isDeletable = !$hasActiveSession && !$hasFutureBookings;

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'save';

    // ── Delete court ──────────────────────────────────────────
    if ($action === 'delete') {
        $confirmName = trim($_POST['confirm_name'] ?? '');
        if ($confirmName !== $court['name']) {
            $errors[] = 'Court name does not match. Deletion cancelled.';
        } elseif (!$isDeletable) {
            $errors[] = 'Cannot delete: court has active sessions or future bookings.';
        } else {
            try {
                $db->beginTransaction();
                $db->prepare("DELETE FROM falcon.court_hours    WHERE court_id = ?")->execute([$courtId]);
                $db->prepare("DELETE FROM falcon.court_settings WHERE court_id = ?")->execute([$courtId]);
                $db->prepare("DELETE FROM falcon.court_slot_modes WHERE court_id = ?")->execute([$courtId]);
                $db->prepare("DELETE FROM falcon.courts WHERE id = ?")->execute([$courtId]);
                auditLog($db, 'court_deleted', isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, 'courts', $courtId,
                    ['name' => $court['name']], [], 'success', 'Court deleted');
                $db->commit();
                setFlash('success', "🗑 Court '{$court['name']}' deleted.");
                redirect('admin/courts.php');
            } catch (PDOException $e) {
                $db->rollBack();
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }

    // ── Deactivate ────────────────────────────────────────────
    if ($action === 'deactivate') {
        $db->prepare("UPDATE falcon.courts SET is_active = FALSE, updated_at = NOW() WHERE id = ?")
           ->execute([$courtId]);
        setFlash('warn', "⏸ Court '{$court['name']}' deactivated.");
        redirect('admin/courts.php');
    }

    // ── Save settings ─────────────────────────────────────────
    if ($action === 'save') {
        $name        = sanitizeString($_POST['name']        ?? '', 100);
        $shortCode   = strtoupper(trim(sanitizeString($_POST['short_code'] ?? '', 5)));
        $description = sanitizeString($_POST['description'] ?? '', 500);
        $courtType   = in_array($_POST['court_type'] ?? '', ['covered','uncovered','indoor','outdoor'])
                       ? $_POST['court_type'] : 'covered';
        $address     = sanitizeString($_POST['address'] ?? '', 255);
        $color       = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#00e5a0';
        $creditCost  = filter_input(INPUT_POST, 'credit_cost',   FILTER_VALIDATE_FLOAT);
        $duration    = filter_input(INPUT_POST, 'game_duration', FILTER_VALIDATE_INT);
        $warmup      = filter_input(INPUT_POST, 'warmup_mins',   FILTER_VALIDATE_INT);
        $passHours   = filter_input(INPUT_POST, 'pass_hours',    FILTER_VALIDATE_FLOAT);
        $maxQueue    = filter_input(INPUT_POST, 'max_queue',     FILTER_VALIDATE_INT);
        $sortOrder   = filter_input(INPUT_POST, 'sort_order',    FILTER_VALIDATE_INT) ?? 0;
        $isActive    = isset($_POST['is_active']);
        $isMaint     = isset($_POST['is_maintenance']);
        $manualStatusIn = $_POST['manual_status'] ?? 'auto';
        $manualStatus   = in_array($manualStatusIn, ['open_play', 'reserved', 'occupied', 'tournament'], true)
            ? $manualStatusIn
            : null;

        // ── Optional photo upload ────────────────────────────
        $photo = $court['photo'] ?? null; // keep existing unless replaced/removed
        if (isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1') {
            if (!empty($court['photo'])) {
                $oldPath = APP_ROOT . '/Uploads/courts/' . basename($court['photo']);
                if (is_file($oldPath)) @unlink($oldPath);
            }
            $photo = null;
        } elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $courtUploadDir = APP_ROOT . '/Uploads/courts/';
            if (!is_dir($courtUploadDir)) @mkdir($courtUploadDir, 0755, true);
            $savedPhoto = moveUploadedImageSafe($_FILES['photo'], $courtUploadDir, 'court');
            if ($savedPhoto === null) {
                $errors[] = 'Photo upload failed. Please use a JPG, PNG, WEBP, or GIF under 5MB.';
            } else {
                if (!empty($court['photo'])) {
                    $oldPath = $courtUploadDir . basename($court['photo']);
                    if (is_file($oldPath)) @unlink($oldPath);
                }
                $photo = $savedPhoto;
            }
        }

        if (empty($name))                       $errors[] = 'Court name is required.';
        if (empty($shortCode))                  $errors[] = 'Short code is required.';
        if (!preg_match('/^[A-Z0-9]{1,5}$/', $shortCode)) $errors[] = 'Short code must be alphanumeric, max 5 chars.';
        if (!$creditCost || $creditCost < 1)    $errors[] = 'Credit cost must be at least ₱1.';
        if (!$duration   || $duration < 5)      $errors[] = 'Game duration must be at least 5 minutes.';
        if ($warmup === false || $warmup < 0)   $errors[] = 'Warmup time cannot be negative.';
        if (!$passHours  || $passHours < 0.5)   $errors[] = 'Pass duration must be at least 0.5 hours.';
        if (!$maxQueue   || $maxQueue < 2)      $errors[] = 'Players per game must be at least 2.';
        if ($maxQueue > 40)                     $errors[] = 'Players per game cannot exceed 40.';

        // Short code uniqueness (excluding self)
        if (empty($errors)) {
            $scCheck = $db->prepare("SELECT id FROM falcon.courts WHERE UPPER(short_code) = ? AND id != ?");
            $scCheck->execute([strtoupper($shortCode), $courtId]);
            if ($scCheck->fetch()) {
                $errors[] = "Short code '{$shortCode}' is already in use by another court.";
            }
        }

        if (empty($errors)) {
            try {
                $db->prepare("
                    UPDATE falcon.courts
                    SET name=?, short_code=?, description=?, court_type=?, address=?, color=?,
                        credit_cost=?, game_duration=?, warmup_mins=?, pass_hours=?, max_queue=?,
                        is_active=?, is_maintenance=?, sort_order=?, photo=?,
                        manual_status=?, manual_status_set_by=?, manual_status_set_at=NOW(),
                        updated_at=NOW()
                    WHERE id=?
                ")->execute([
                                        $name, $shortCode, $description ?: null, $courtType, $address ?: null, $color,
                    $creditCost, $duration, $warmup ?? 2, $passHours, $maxQueue,
                    (int)$isActive, (int)$isMaint, max(0, $sortOrder ?? 0), $photo,
                    $manualStatus, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
                    $courtId,
                ]);
                auditLog($db, 'court_updated', isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, 'courts', $courtId,
                    ['name' => $court['name']], ['name' => $name], 'success', 'Court settings updated');
                setFlash('success', "✅ Court '{$name}' updated.");
                redirect('admin/court_edit.php?court_id=' . $courtId);
            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Re-fetch court after potential save
$courtRow = $db->prepare("SELECT * FROM falcon.courts WHERE id = ?");
$courtRow->execute([$courtId]);
$court = $courtRow->fetch() ?: $court;

$typeIcons = ['covered'=>'🏠','uncovered'=>'☀️','indoor'=>'🏢','outdoor'=>'🌿'];
$pageTitle = 'Edit Court — ' . clean($court['name']);
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.ce-layout { display:grid; grid-template-columns:1fr 300px; gap:24px; align-items:start; }
@media(max-width:900px){ .ce-layout{grid-template-columns:1fr;} }

.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:12px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px; }
.form-group input, .form-group select, .form-group textarea {
    width:100%; background:var(--surface); border:1.5px solid var(--border);
    border-radius:9px; padding:10px 13px; color:var(--text); font-size:15px; font-family:inherit;
    transition:border-color .15s; -webkit-appearance:none;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:var(--accent); outline:none; }
.form-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; }
@media(max-width:600px){ .form-row-2,.form-row-3{grid-template-columns:1fr;gap:0;} }

.field-note { font-size:11px; color:var(--muted); margin-top:4px; }

.toggle-row {
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    padding:13px 15px; background:var(--surface2); border:1px solid var(--border);
    border-radius:10px; margin-bottom:14px;
}
.toggle-label-text { font-size:14px; font-weight:600; }
.toggle-label-text small { display:block; font-size:12px; color:var(--muted); font-weight:400; margin-top:2px; }
.toggle-switch { position:relative; width:48px; height:26px; flex-shrink:0; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider { position:absolute; inset:0; background:var(--border); border-radius:26px; cursor:pointer; transition:background 0.2s; }
.toggle-slider::before { content:''; position:absolute; width:20px; height:20px; border-radius:50%; left:3px; bottom:3px; background:#fff; transition:transform 0.2s; box-shadow:0 1px 3px rgba(0,0,0,.3); }
.toggle-switch input:checked + .toggle-slider { background:var(--accent); }
.toggle-switch input:checked + .toggle-slider::before { transform:translateX(22px); }

.type-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px; }
@media(max-width:500px){ .type-grid{grid-template-columns:1fr 1fr;} }
.type-card { border:2px solid var(--border); border-radius:10px; padding:12px 8px; text-align:center; cursor:pointer; transition:all 0.15s; position:relative; }
.type-card input { position:absolute; opacity:0; width:0; height:0; }
.type-card .type-icon { font-size:24px; margin-bottom:4px; }
.type-card .type-name { font-size:12px; font-weight:700; }
.type-card.selected { border-color:var(--accent); background:rgba(0,229,160,0.07); }

.danger-section {
    border: 2px solid rgba(239,68,68,.3);
    border-radius: 14px;
    padding: 20px;
    margin-top: 8px;
    background: rgba(239,68,68,.03);
}
.danger-title {
    font-size: 14px; font-weight: 800; color: var(--danger);
    text-transform: uppercase; letter-spacing: .06em; margin-bottom: 14px;
    display: flex; align-items: center; gap: 8px;
}

/* Delete modal */
.modal-overlay { display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.8); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:20px; }
.modal-overlay.open { display:flex; }
.modal-box { background:var(--surface); border:2px solid var(--danger); border-radius:16px; padding:28px; max-width:420px; width:100%; animation:modalIn .25s ease; }
@keyframes modalIn { from{opacity:0;transform:scale(.92)} to{opacity:1;transform:scale(1)} }

.color-row { display:flex; align-items:center; gap:12px; }
.color-row input[type=color] { width:80px; height:40px; padding:2px; border-radius:8px; cursor:pointer; }
.color-row input[type=text]  { flex:1; }
.color-preview { display:inline-block; width:36px; height:36px; border-radius:8px; border:2px solid var(--border); flex-shrink:0; }
</style>

<!-- ── Page Header ── -->
<div class="page-header flex-between">
    <div>
        <h1>Edit Court</h1>
        <p>
            <span style="color:<?= clean($court['color'] ?? '#00e5a0') ?>;font-family:'Bebas Neue',sans-serif;font-size:18px;">
                <?= clean($court['short_code'] ?? '') ?>
            </span>
            &nbsp;<?= clean($court['name']) ?>
        </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="<?= APP_URL ?>/admin/court_settings.php?court=<?= $courtId ?>" class="btn-outline btn-sm">⚙️ Settings</a>
        <a href="<?= APP_URL ?>/admin/court_hours.php?court=<?= $courtId ?>"    class="btn-outline btn-sm">📅 Hours</a>
        <a href="<?= APP_URL ?>/admin/courts.php"                               class="btn-outline btn-sm">← Courts</a>
    </div>
</div>

<!-- Color accent bar -->
<div style="height:4px;background:<?= clean($court['color'] ?? '#00e5a0') ?>;border-radius:4px;margin-bottom:24px;"></div>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $e): ?>
        <div class="flash flash-error" style="margin-bottom:10px;border-radius:10px;">
            <?= clean($e) ?> <button onclick="this.parentElement.remove()">✕</button>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php $flash = getFlash(); if ($flash): ?>
<div class="flash flash-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" style="margin-bottom:16px;border-radius:10px;">
    <?= clean($flash['message']) ?>
</div>
<?php endif; ?>

<div class="ce-layout">
    <div>
        <form method="POST" id="edit-form" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action"   value="save">
            <input type="hidden" name="court_id" value="<?= $courtId ?>">

            <!-- Status toggles -->
            <div class="card mb-3">
                <div class="card-title" style="margin-bottom:14px;">🔧 Court Status</div>

                <div class="toggle-row">
                    <div class="toggle-label-text">
                        Court Active
                        <small>Inactive courts are hidden from players</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="is_active" <?= $court['is_active'] ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="toggle-row" style="<?= $court['is_maintenance'] ? 'border-color:rgba(245,158,11,.4);background:rgba(245,158,11,.05);' : '' ?>">
                    <div class="toggle-label-text">
                        Maintenance Mode
                        <small>Disables scanning and queuing for this court</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="is_maintenance"
                               <?= $court['is_maintenance'] ? 'checked' : '' ?>
                               onchange="document.getElementById('maint-warning').style.display=this.checked?'block':'none'">
                        <span class="toggle-slider" style="<?= $court['is_maintenance'] ? 'background:var(--warn);' : '' ?>"></span>
                    </label>
                </div>
                <div id="maint-warning"
                     style="display:<?= $court['is_maintenance'] ? 'block' : 'none' ?>;background:rgba(245,158,11,.1);border:1px solid var(--warn);border-radius:8px;padding:10px;font-size:13px;color:var(--warn);margin-top:-8px;margin-bottom:8px;">
                    ⚠️ Maintenance mode is ON — this court is not accessible to players.
                </div>

                <div class="toggle-row" style="align-items:center;">
                    <div class="toggle-label-text">
                        Manual Status Override
                        <small>Overrides the auto-computed status (ignored while Maintenance is on or court is Inactive)</small>
                    </div>
                    <select name="manual_status" style="background:rgba(255,255,255,.04);color:var(--text,#e5e7eb);border:1px solid rgba(255,255,255,.12);border-radius:8px;padding:8px 12px;font-size:13px;">
                        <?php
                        $currentManual = $court['manual_status'] ?: 'auto';
                        $editStatusOptions = [
                            'auto'       => 'Auto (computed)',
                            'open_play'  => '🏓 Open Play',
                            'reserved'   => '📌 Reserved',
                            'occupied'   => '🔒 Occupied',
                            'tournament' => '🏆 Tournament',
                        ];
                        foreach ($editStatusOptions as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $currentManual === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Court Info -->
            <div class="card mb-3">
                <div class="card-title" style="margin-bottom:16px;">🏟️ Court Information</div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label>Court Name <span style="color:var(--danger);">*</span></label>
                        <input type="text" name="name" value="<?= clean($court['name']) ?>"
                               maxlength="100" required/>
                    </div>
                    <div class="form-group">
                        <label>Short Code <span style="color:var(--danger);">*</span></label>
                        <input type="text" name="short_code"
                               value="<?= clean($court['short_code'] ?? '') ?>"
                               maxlength="10" required
                               oninput="this.value=this.value.toUpperCase()"/>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label>Address / Venue</label>
                        <input type="text" name="address"
                               value="<?= clean($court['address'] ?? '') ?>" maxlength="255"/>
                    </div>
                    <div class="form-group">
                        <label>Sort Order</label>
                        <input type="number" name="sort_order"
                               value="<?= (int)($court['sort_order'] ?? 0) ?>" min="0" max="999"/>
                        <div class="field-note">Lower number = shown first</div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label>Court Photo</label>
                    <div style="display:flex;align-items:center;gap:14px;">
                        <div id="photo-preview-wrap" style="width:88px;height:66px;border-radius:9px;overflow:hidden;background:var(--surface2);border:1.5px solid var(--border);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                            <?php if (!empty($court['photo'])): ?>
                                <img id="photo-preview-img" src="<?= APP_URL ?>/Uploads/courts/<?= urlencode($court['photo']) ?>"
                                     alt="" style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <span id="photo-preview-placeholder" style="font-size:22px;opacity:.4;">🏓</span>
                                <img id="photo-preview-img" src="" alt="" style="width:100%;height:100%;object-fit:cover;display:none;">
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;">
                            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"
                                   onchange="previewCourtPhoto(this)"/>
                            <div class="field-note">JPG, PNG, WEBP or GIF — max 5MB. Shown as the card thumbnail.</div>
                            <?php if (!empty($court['photo'])): ?>
                            <label style="display:flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;color:var(--muted);cursor:pointer;">
                                <input type="checkbox" name="remove_photo" value="1" id="remove-photo-cb"
                                       onchange="document.getElementById('photo-preview-wrap').style.opacity=this.checked?'0.35':'1'">
                                Remove current photo
                            </label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label>Description</label>
                    <textarea name="description" rows="2" style="resize:vertical;"><?= clean($court['description'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Court Type -->
            <div class="card mb-3">
                <div class="card-title" style="margin-bottom:14px;">🏠 Court Type</div>
                <div class="type-grid">
                    <?php foreach (['covered'=>'🏠','uncovered'=>'☀️','indoor'=>'🏢','outdoor'=>'🌿'] as $t=>$icon): ?>
                    <label class="type-card <?= ($court['court_type']??'covered')===$t?'selected':'' ?>"
                           onclick="selectType('<?= $t ?>')">
                        <input type="radio" name="court_type" value="<?= $t ?>"
                               <?= ($court['court_type']??'covered')===$t?'checked':'' ?>>
                        <div class="type-icon"><?= $icon ?></div>
                        <div class="type-name"><?= ucfirst($t) ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Color -->
            <div class="card mb-3">
                <div class="card-title" style="margin-bottom:14px;">🎨 Color Accent</div>
                <div class="color-row">
                    <input type="color" id="color-picker" value="<?= clean($court['color']??'#00e5a0') ?>"
                           oninput="syncColor(this.value)"/>
                    <input type="text"  id="color-hex" value="<?= clean($court['color']??'#00e5a0') ?>"
                           maxlength="7" oninput="syncColorFromText(this.value)"/>
                    <div class="color-preview" id="color-preview"
                         style="background:<?= clean($court['color']??'#00e5a0') ?>;"></div>
                </div>
                <input type="hidden" name="color" id="hidden-color" value="<?= clean($court['color']??'#00e5a0') ?>">
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
                    <?php foreach (['#00e5a0','#00aaff','#f97316','#a855f7','#ec4899','#facc15','#10b981','#ef4444'] as $pc): ?>
                    <button type="button" style="width:28px;height:28px;border-radius:6px;background:<?= $pc ?>;border:2px solid transparent;cursor:pointer;" onclick="syncColor('<?= $pc ?>')" title="<?= $pc ?>"></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Game Settings -->
            <div class="card mb-3">
                <div class="card-title" style="margin-bottom:14px;">🏓 Game Settings</div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>Credit Cost (₱) <span style="color:var(--danger);">*</span></label>
                        <input type="number" name="credit_cost"
                               value="<?= (float)$court['credit_cost'] ?>" min="1" max="9999" step="0.5" required/>
                    </div>
                    <div class="form-group">
                        <label>Pass Duration (hours) <span style="color:var(--danger);">*</span></label>
                        <input type="number" name="pass_hours"
                               value="<?= (float)($court['pass_hours']??8) ?>" min="0.5" max="24" step="0.5" required/>
                    </div>
                </div>
                <div class="form-row-3">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Game Duration (min) <span style="color:var(--danger);">*</span></label>
                        <input type="number" name="game_duration"
                               value="<?= (int)$court['game_duration'] ?>" min="5" max="300" step="5" required/>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Warmup Time (min) <span style="color:var(--danger);">*</span></label>
                        <input type="number" name="warmup_mins"
                               value="<?= (int)($court['warmup_mins']??2) ?>" min="0" max="15" step="1" required/>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Max Players <span style="color:var(--danger);">*</span></label>
                        <input type="number" name="max_queue"
                               value="<?= (int)$court['max_queue'] ?>" min="2" max="40" step="1" required/>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-primary"
                    style="width:100%;min-height:52px;font-size:16px;font-weight:700;">
                💾 Save Changes
            </button>
        </form>

        <!-- Dangerous Actions -->
        <div class="danger-section" style="margin-top:24px;">
            <div class="danger-title">⚠️ Dangerous Actions</div>

            <?php if ($hasActiveSession): ?>
            <div style="background:rgba(239,68,68,.1);border:1px solid var(--danger);border-radius:8px;padding:10px;font-size:13px;color:var(--danger);margin-bottom:12px;">
                🎮 A game is currently active on this court. Actions are limited.
            </div>
            <?php endif; ?>

            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <!-- Deactivate -->
                <?php if ($court['is_active']): ?>
                <form method="POST" style="margin:0;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"   value="deactivate">
                    <input type="hidden" name="court_id" value="<?= $courtId ?>">
                    <button type="submit" class="btn-warn btn-sm"
                            onclick="return confirm('Deactivate this court? Players will not be able to scan in.')">
                        ⏸ Deactivate Court
                    </button>
                </form>
                <?php endif; ?>

                <!-- Delete -->
                <button type="button" class="btn-danger btn-sm"
                        onclick="document.getElementById('delete-modal').classList.add('open')"
                        <?= !$isDeletable ? 'disabled title="Cannot delete: active sessions or future bookings exist"' : '' ?>>
                    🗑 Delete Court
                </button>
            </div>

            <?php if (!$isDeletable): ?>
            <div style="margin-top:10px;font-size:12px;color:var(--muted);">
                Cannot delete:
                <?= $hasActiveSession   ? '• Active game session exists. ' : '' ?>
                <?= $hasFutureBookings  ? '• Future reservations exist. '  : '' ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sidebar -->
    <div style="display:flex;flex-direction:column;gap:16px;">
        <div class="card">
            <div class="card-title" style="margin-bottom:10px;">🔗 Quick Links</div>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <a href="<?= APP_URL ?>/admin/court_settings.php?court=<?= $courtId ?>"
                   class="btn-outline btn-sm" style="display:block;text-align:center;">⚙️ Full Court Settings</a>
                <a href="<?= APP_URL ?>/admin/court_hours.php?court=<?= $courtId ?>"
                   class="btn-outline btn-sm" style="display:block;text-align:center;">📅 Operating Hours</a>
                <a href="<?= APP_URL ?>/admin/court_mode.php?court=<?= $courtId ?>"
                   class="btn-outline btn-sm" style="display:block;text-align:center;">🎮 Court Modes</a>
                <a href="<?= APP_URL ?>/admin/active_game.php"
                   class="btn-outline btn-sm" style="display:block;text-align:center;">🟢 Live Monitor</a>
            </div>
        </div>
        <div class="card" style="border-color:rgba(239,68,68,.2);">
            <div class="card-title" style="margin-bottom:10px;color:var(--danger);">🗑 Delete Checklist</div>
            <div style="font-size:12px;color:var(--muted);line-height:1.9;">
                <div style="color:<?= !$hasActiveSession   ? 'var(--success)' : 'var(--danger)' ?>;">
                    <?= !$hasActiveSession   ? '✅' : '❌' ?> No active game sessions
                </div>
                <div style="color:<?= !$hasFutureBookings  ? 'var(--success)' : 'var(--danger)' ?>;">
                    <?= !$hasFutureBookings  ? '✅' : '❌' ?> No future reservations
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Delete Confirmation Modal ── -->
<div class="modal-overlay" id="delete-modal">
    <div class="modal-box">
        <div style="font-size:36px;text-align:center;margin-bottom:12px;">🗑</div>
        <div style="font-family:'Bebas Neue',sans-serif;font-size:24px;color:var(--danger);text-align:center;margin-bottom:8px;">
            Delete Court?
        </div>
        <p style="font-size:13px;color:var(--muted);text-align:center;line-height:1.6;margin-bottom:20px;">
            This action <strong style="color:var(--danger);">cannot be undone</strong>. All court data,
            hours, and settings will be permanently deleted.<br><br>
            Type <strong style="color:var(--text);"><?= clean($court['name']) ?></strong> to confirm:
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action"   value="delete">
            <input type="hidden" name="court_id" value="<?= $courtId ?>">
            <input type="text" name="confirm_name" id="confirm-name-input"
                   placeholder="Type court name here…"
                   style="width:100%;background:var(--surface2);border:2px solid var(--danger);border-radius:8px;padding:10px 14px;color:var(--text);font-size:14px;font-family:inherit;margin-bottom:16px;"
                   oninput="checkConfirm(this.value)"/>
            <div style="display:flex;gap:10px;">
                <button type="button" class="btn-outline btn-sm" style="flex:1;"
                        onclick="document.getElementById('delete-modal').classList.remove('open')">
                    Cancel
                </button>
                <button type="submit" class="btn-danger btn-sm" style="flex:1;" id="confirm-delete-btn" disabled>
                    🗑 Delete Permanently
                </button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
const COURT_NAME = <?= json_encode($court['name']) ?>;

function previewCourtPhoto(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = document.getElementById('photo-preview-img');
        const placeholder = document.getElementById('photo-preview-placeholder');
        img.src = e.target.result;
        img.style.display = 'block';
        if (placeholder) placeholder.style.display = 'none';
        document.getElementById('photo-preview-wrap').style.opacity = '1';
        const removeCb = document.getElementById('remove-photo-cb');
        if (removeCb) removeCb.checked = false; // a new upload cancels "remove"
    };
    reader.readAsDataURL(input.files[0]);
}

function selectType(t) {
    document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('.type-card').forEach(c => {
        if (c.querySelector('input').value === t) c.classList.add('selected');
    });
}

function syncColor(hex) {
    if (!/^#[0-9A-Fa-f]{6}$/.test(hex)) return;
    document.getElementById('color-picker').value  = hex;
    document.getElementById('color-hex').value     = hex;
    document.getElementById('color-preview').style.background = hex;
    document.getElementById('hidden-color').value  = hex;
}
function syncColorFromText(val) {
    if (/^#[0-9A-Fa-f]{6}$/.test(val)) syncColor(val);
}
document.getElementById('color-picker').addEventListener('input', function() {
    syncColor(this.value);
});

function checkConfirm(val) {
    document.getElementById('confirm-delete-btn').disabled = (val !== COURT_NAME);
}

// Close modal on backdrop click
document.getElementById('delete-modal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>