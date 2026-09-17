<?php
// ============================================================
//  FILE: admin/court_create.php
//  Create a new pickleball court
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db      = getDB();
$errors  = [];
$success = false;

// ── Clone from an existing court (Court Management → Duplicate) ──
$cloneSource = null;
$cloneId = filter_input(INPUT_GET, 'clone', FILTER_VALIDATE_INT);
if ($cloneId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $cs = $db->prepare("SELECT * FROM falcon.courts WHERE id = ?");
    $cs->execute([$cloneId]);
    $cloneSource = $cs->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

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
    $isActive    = isset($_POST['is_active']) ? true : false;

    // ── Optional photo upload ────────────────────────────────
    $photo = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $courtUploadDir = APP_ROOT . '/Uploads/courts/';
        if (!is_dir($courtUploadDir)) @mkdir($courtUploadDir, 0755, true);
        $savedPhoto = moveUploadedImageSafe($_FILES['photo'], $courtUploadDir, 'court');
        if ($savedPhoto === null) {
            $errors[] = 'Photo upload failed. Please use a JPG, PNG, WEBP, or GIF under 5MB.';
        } else {
            $photo = $savedPhoto;
        }
    }

    // ── Validation ────────────────────────────────────────────
    if (empty($name))                       $errors[] = 'Court name is required.';
    if (empty($shortCode))                  $errors[] = 'Short code is required.';
    if (!preg_match('/^[A-Z0-9]{1,5}$/', $shortCode)) $errors[] = 'Short code must be alphanumeric (A-Z, 0-9), max 5 chars.';
    if (!$creditCost || $creditCost < 1)    $errors[] = 'Credit cost must be at least ₱1.';
    if (!$duration   || $duration < 5)      $errors[] = 'Game duration must be at least 5 minutes.';
    if ($warmup === false || $warmup < 0)   $errors[] = 'Warmup time cannot be negative.';
    if (!$passHours  || $passHours < 0.5)   $errors[] = 'Pass duration must be at least 0.5 hours.';
    if (!$maxQueue   || $maxQueue < 2)      $errors[] = 'Players per game must be at least 2.';
    if ($maxQueue > 40)                     $errors[] = 'Players per game cannot exceed 40.';

    // Check short_code uniqueness
    if (empty($errors)) {
        $scCheck = $db->prepare("SELECT id FROM falcon.courts WHERE UPPER(short_code) = ?");
        $scCheck->execute([strtoupper($shortCode)]);
        if ($scCheck->fetch()) {
            $errors[] = "Short code '{$shortCode}' is already in use by another court.";
        }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // Get next sort_order
            $maxSort = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM falcon.courts")->fetchColumn();

            $ins = $db->prepare("
                INSERT INTO falcon.courts
                    (name, short_code, description, court_type, address, color,
                     credit_cost, game_duration, warmup_mins, pass_hours, max_queue,
                     is_active, is_maintenance, sort_order, photo, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE, ?, ?, NOW(), NOW())
                RETURNING id
            ");
            $ins->execute([
                $name, $shortCode, $description ?: null, $courtType, $address ?: null, $color,
                $creditCost, $duration, $warmup ?? 2, $passHours, $maxQueue,
                $isActive, $maxSort + 1, $photo,
            ]);
            $newCourtId = (int)$ins->fetchColumn();

            // ── Auto-populate court_hours (10 AM to midnight every day) ──
            $insHours = $db->prepare("
                INSERT INTO falcon.court_hours (court_id, day_of_week, open_time, close_time, is_closed)
                VALUES (?, ?, '10:00:00', '00:00:00', FALSE)
                ON CONFLICT (court_id, day_of_week) DO NOTHING
            ");
            for ($dow = 0; $dow <= 6; $dow++) {
                $insHours->execute([$newCourtId, $dow]);
            }

            // ── Auto-populate court_settings (reservation defaults) ──
            $insSet = $db->prepare("
                INSERT INTO falcon.court_settings (court_id, key, value)
                VALUES (?, ?, ?)
                ON CONFLICT (court_id, key) DO NOTHING
            ");
            $defaults = [
                'reservation_price_per_hour' => '150',
                'reservation_min_hours'      => '2',
                'reservation_max_hours'      => '8',
                'reservation_deposit_pct'    => '50',
                'reservation_enabled'        => '1',
                'reservation_advance_days'   => '14',
            ];
            foreach ($defaults as $k => $v) {
                $insSet->execute([$newCourtId, $k, $v]);
            }

            // ── Audit log ─────────────────────────────────────
            auditLog($db, 'court_created', isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, 'courts', $newCourtId,
                [], ['name' => $name, 'short_code' => $shortCode], 'success',
                "Court '{$name}' ({$shortCode}) created");

            $db->commit();
            setFlash('success', "✅ Court '{$name}' ({$shortCode}) created successfully!");
            redirect('admin/courts.php');

        } catch (PDOException $e) {
            $db->rollBack();
            error_log('[court_create] ' . $e->getMessage());
            $errors[] = IS_PRODUCTION
                ? 'Something went wrong while saving the court. Please try again.'
                : 'Database error: ' . $e->getMessage();
        }
    }
}

// Repopulate form values on error, or pre-fill from a clone source
$fv = [
    'name'          => clean($_POST['name']          ?? ($cloneSource ? $cloneSource['name'] . ' (Copy)' : '')),
    'short_code'    => clean(strtoupper($_POST['short_code']    ?? '')), // never copy short_code — must stay unique
    'description'   => clean($_POST['description']   ?? ($cloneSource['description'] ?? '')),
    'court_type'    => $_POST['court_type']    ?? ($cloneSource['court_type'] ?? 'covered'),
    'address'       => clean($_POST['address']       ?? ($cloneSource['address'] ?? '')),
    'color'         => preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : ($cloneSource['color'] ?? '#00e5a0'),
    'credit_cost'   => $_POST['credit_cost']   ?? ($cloneSource['credit_cost'] ?? '50'),
    'game_duration' => $_POST['game_duration'] ?? ($cloneSource['game_duration'] ?? '15'),
    'warmup_mins'   => $_POST['warmup_mins']   ?? ($cloneSource['warmup_mins'] ?? '2'),
    'pass_hours'    => $_POST['pass_hours']    ?? ($cloneSource['pass_hours'] ?? '8'),
    'max_queue'     => $_POST['max_queue']     ?? ($cloneSource['max_queue'] ?? '4'),
    'is_active'     => isset($_POST['is_active']) ? true : true, // default on
];

$pageTitle = 'Create New Court';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.cc-layout { display:grid; grid-template-columns:1fr 300px; gap:24px; align-items:start; }
@media(max-width:900px){ .cc-layout{grid-template-columns:1fr;} }

.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:12px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px; }
.form-group input,
.form-group select,
.form-group textarea {
    width:100%; background:var(--surface); border:1.5px solid var(--border);
    border-radius:9px; padding:10px 13px; color:var(--text); font-size:15px; font-family:inherit;
    transition:border-color .15s; -webkit-appearance:none;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { border-color:var(--accent); outline:none; }
.form-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; }
@media(max-width:600px){ .form-row-2,.form-row-3{grid-template-columns:1fr;gap:0;} }

.field-note { font-size:11px; color:var(--muted); margin-top:4px; }

.type-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px; }
@media(max-width:500px){ .type-grid{grid-template-columns:1fr 1fr;} }
.type-card {
    border:2px solid var(--border); border-radius:10px; padding:12px 8px;
    text-align:center; cursor:pointer; transition:all 0.15s; position:relative;
}
.type-card input { position:absolute; opacity:0; width:0; height:0; }
.type-card .type-icon { font-size:24px; margin-bottom:4px; }
.type-card .type-name { font-size:12px; font-weight:700; }
.type-card.selected { border-color:var(--accent); background:rgba(0,229,160,0.07); }

.toggle-row {
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    padding:13px 15px; background:var(--surface2); border:1px solid var(--border);
    border-radius:10px; margin-bottom:16px;
}
.toggle-label-text { font-size:14px; font-weight:600; }
.toggle-label-text small { display:block; font-size:12px; color:var(--muted); font-weight:400; margin-top:2px; }
.toggle-switch { position:relative; width:48px; height:26px; flex-shrink:0; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider { position:absolute; inset:0; background:var(--border); border-radius:26px; cursor:pointer; transition:background 0.2s; }
.toggle-slider::before { content:''; position:absolute; width:20px; height:20px; border-radius:50%; left:3px; bottom:3px; background:#fff; transition:transform 0.2s; box-shadow:0 1px 3px rgba(0,0,0,.3); }
.toggle-switch input:checked + .toggle-slider { background:var(--accent); }
.toggle-switch input:checked + .toggle-slider::before { transform:translateX(22px); }

.color-preview {
    display:inline-block; width:36px; height:36px; border-radius:8px;
    border:2px solid var(--border); vertical-align:middle; margin-left:8px;
    flex-shrink:0; transition:background 0.15s;
}
.color-row { display:flex; align-items:center; gap:12px; }
.color-row input[type=color] {
    width:80px; height:40px; padding:2px; border-radius:8px; cursor:pointer;
}
.color-row input[type=text] { flex:1; }
</style>

<!-- ── Page Header ── -->
<div class="page-header flex-between">
    <div>
        <h1><?= $cloneSource ? 'Duplicate Court' : 'Add New Court' ?></h1>
        <p><?= $cloneSource
                ? 'Pre-filled from ' . clean($cloneSource['name']) . ' — adjust the name, short code, and anything else that should differ.'
                : 'Configure a new pickleball court for the system' ?></p>
    </div>
    <div>
        <a href="<?= APP_URL ?>/admin/courts.php" class="btn-outline btn-sm">← Back to Courts</a>
    </div>
</div>

<?php if ($cloneSource): ?>
<div class="flash" style="margin-bottom:16px;border-radius:10px;background:rgba(0,229,160,.08);border:1px solid rgba(0,229,160,.3);color:var(--accent);">
    📋 Duplicating settings from <strong><?= clean($cloneSource['name']) ?></strong>. A short code is required and must be unique — the photo is not copied.
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $e): ?>
        <div class="flash flash-error" style="margin-bottom:10px;border-radius:10px;">
            <?= clean($e) ?> <button onclick="this.parentElement.remove()">✕</button>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<div class="cc-layout">
    <form method="POST" id="court-form" enctype="multipart/form-data">
        <?= csrfField() ?>

        <!-- Court Info -->
        <div class="card mb-3">
            <div class="card-title" style="margin-bottom:16px;">🏟️ Court Information</div>

            <div class="toggle-row">
                <div class="toggle-label-text">
                    Court Active
                    <small>Players can scan in and join the queue</small>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="is_active" <?= $fv['is_active'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label>Court Name <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="name" value="<?= $fv['name'] ?>"
                           maxlength="100" required placeholder="e.g. Court 1"/>
                </div>
                <div class="form-group">
                    <label>Short Code <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="short_code" value="<?= $fv['short_code'] ?>"
                           maxlength="10" required placeholder="e.g. C1"
                           oninput="this.value=this.value.toUpperCase()"/>
                    <div class="field-note">Unique code shown on scanner — e.g. C1, C2</div>
                </div>
            </div>

            <div class="form-group">
                <label>Court Photo</label>
                <div style="display:flex;align-items:center;gap:14px;">
                    <div id="photo-preview-wrap" style="width:88px;height:66px;border-radius:9px;overflow:hidden;background:var(--surface2);border:1.5px solid var(--border);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                        <span id="photo-preview-placeholder" style="font-size:22px;opacity:.4;">🏓</span>
                        <img id="photo-preview-img" src="" alt="" style="width:100%;height:100%;object-fit:cover;display:none;">
                    </div>
                    <div style="flex:1;">
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"
                               onchange="previewCourtPhoto(this)"/>
                        <div class="field-note">JPG, PNG, WEBP or GIF — max 5MB. Shown as the card thumbnail. Optional.</div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="2" style="resize:vertical;" placeholder="Optional — shown to players on booking"><?= $fv['description'] ?></textarea>
            </div>

            <div class="form-group">
                <label>Address / Venue</label>
                <input type="text" name="address" value="<?= $fv['address'] ?>"
                       maxlength="255" placeholder="e.g. Padol Sports Complex, Building A"/>
            </div>
        </div>

        <!-- Court Type -->
        <div class="card mb-3">
            <div class="card-title" style="margin-bottom:14px;">🏠 Court Type</div>
            <div class="type-grid" id="type-grid">
                <?php foreach (['covered' => '🏠', 'uncovered' => '☀️', 'indoor' => '🏢', 'outdoor' => '🌿'] as $t => $icon): ?>
                <label class="type-card <?= $fv['court_type'] === $t ? 'selected' : '' ?>"
                       onclick="selectType('<?= $t ?>')">
                    <input type="radio" name="court_type" value="<?= $t ?>"
                           <?= $fv['court_type'] === $t ? 'checked' : '' ?>>
                    <div class="type-icon"><?= $icon ?></div>
                    <div class="type-name"><?= ucfirst($t) ?></div>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Color Accent -->
        <div class="card mb-3">
            <div class="card-title" style="margin-bottom:14px;">🎨 Color Accent</div>
            <p style="font-size:13px;color:var(--muted);margin-bottom:14px;">
                Used for the court card border, badges, and status indicators
            </p>
            <div class="color-row">
                <input type="color" name="color" id="color-picker"
                       value="<?= $fv['color'] ?>" oninput="syncColor(this.value)"/>
                <input type="text"  name="color_hex" id="color-hex"
                       value="<?= $fv['color'] ?>" maxlength="7"
                       placeholder="#00e5a0" oninput="syncColorFromText(this.value)"/>
                <div class="color-preview" id="color-preview"
                     style="background:<?= $fv['color'] ?>;"></div>
            </div>
            <!-- Color presets -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
                <?php foreach (['#00e5a0','#00aaff','#f97316','#a855f7','#ec4899','#facc15','#10b981','#ef4444'] as $pc): ?>
                <button type="button" class="preset-color-btn"
                        style="width:28px;height:28px;border-radius:6px;background:<?= $pc ?>;border:2px solid transparent;cursor:pointer;transition:transform .15s;"
                        onclick="syncColor('<?= $pc ?>')"
                        title="<?= $pc ?>"></button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Game Settings -->
        <div class="card mb-3">
            <div class="card-title" style="margin-bottom:14px;">🏓 Game Settings</div>

            <div class="form-row-2">
                <div class="form-group">
                    <label>Credit Cost (₱) <span style="color:var(--danger);">*</span></label>
                    <input type="number" name="credit_cost" value="<?= $fv['credit_cost'] ?>"
                           min="1" max="9999" step="0.5" required/>
                    <div class="field-note">Charged per game session</div>
                </div>
                <div class="form-group">
                    <label>Pass Duration (hours) <span style="color:var(--danger);">*</span></label>
                    <input type="number" name="pass_hours" value="<?= $fv['pass_hours'] ?>"
                           min="0.5" max="24" step="0.5" required/>
                    <div class="field-note">QR window after first scan</div>
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Game Duration (min) <span style="color:var(--danger);">*</span></label>
                    <input type="number" name="game_duration" value="<?= $fv['game_duration'] ?>"
                           min="5" max="300" step="5" required/>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Warmup Time (min) <span style="color:var(--danger);">*</span></label>
                    <input type="number" name="warmup_mins" value="<?= $fv['warmup_mins'] ?>"
                           min="0" max="15" step="1" required/>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Max Players <span style="color:var(--danger);">*</span></label>
                    <input type="number" name="max_queue" value="<?= $fv['max_queue'] ?>"
                           min="2" max="40" step="1" required/>
                    <div class="field-note">Per game, 2–40</div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn-primary"
                style="width:100%;min-height:52px;font-size:16px;font-weight:700;">
            🏟️ Create Court
        </button>
    </form>

    <!-- Sidebar -->
    <div style="display:flex;flex-direction:column;gap:16px;">
        <div class="card">
            <div class="card-title" style="margin-bottom:10px;">✅ What Happens Next</div>
            <div style="font-size:13px;color:var(--muted);line-height:1.9;">
                <div>✅ Court created in the database</div>
                <div>✅ Operating hours auto-set (10 AM–midnight daily)</div>
                <div>✅ Reservation settings pre-configured</div>
                <div>✅ Court appears on scanner and player pages</div>
            </div>
        </div>
        <div class="card">
            <div class="card-title" style="margin-bottom:10px;">💡 Short Code Tips</div>
            <div style="font-size:13px;color:var(--muted);line-height:1.7;">
                Keep it short and memorable:<br>
                <strong style="color:var(--accent);">C1</strong> → Court 1<br>
                <strong style="color:var(--accent2);">VIP</strong> → VIP Court<br>
                <strong style="color:var(--warn);">OUT1</strong> → Outdoor Court 1<br>
                Shown prominently on the scanner screen when a player scans in.
            </div>
        </div>
        <div class="card">
            <div class="card-title" style="margin-bottom:10px;">⚙️ After Creation</div>
            <div style="font-size:13px;color:var(--muted);line-height:1.7;">
                You can customize operating hours, reservation pricing, and court modes from the Courts page after creation.
            </div>
        </div>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
function previewCourtPhoto(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = document.getElementById('photo-preview-img');
        const placeholder = document.getElementById('photo-preview-placeholder');
        img.src = e.target.result;
        img.style.display = 'block';
        if (placeholder) placeholder.style.display = 'none';
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
    // sync hidden name=color
    const pickers = document.querySelectorAll('input[name=color]');
    pickers.forEach(p => p.value = hex);
}
function syncColorFromText(val) {
    if (/^#[0-9A-Fa-f]{6}$/.test(val)) syncColor(val);
}

// Override color picker to also write to hidden field
document.getElementById('color-picker').addEventListener('input', function() {
    syncColor(this.value);
});

// Add hidden color field that actually submits
const form = document.getElementById('court-form');
const hiddenColor = document.createElement('input');
hiddenColor.type = 'hidden';
hiddenColor.name = 'color';
hiddenColor.id   = 'hidden-color';
hiddenColor.value = document.getElementById('color-picker').value;
form.appendChild(hiddenColor);

// Override syncColor to also update hidden
const origSync = window.syncColor;
window.syncColor = function(hex) {
    if (!/^#[0-9A-Fa-f]{6}$/.test(hex)) return;
    document.getElementById('color-picker').value  = hex;
    document.getElementById('color-hex').value     = hex;
    document.getElementById('color-preview').style.background = hex;
    document.getElementById('hidden-color').value  = hex;
    document.querySelectorAll('.preset-color-btn').forEach(b => {
        b.style.borderColor = b.getAttribute('onclick').includes(hex) ? '#fff' : 'transparent';
    });
};
// Init
window.syncColor('<?= $fv['color'] ?>');
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>