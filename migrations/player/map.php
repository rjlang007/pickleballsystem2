<?php
// ============================================================
//  FILE: player/map.php  — FIXED v2
//  • nonce on <style> and <script> blocks
//  • inline styles replaced with CSS classes
//  • brand link → index.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

$db      = getDB();
$isAdmin = isAdmin();

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_location') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']); exit;
    }
    $newLat = trim($_POST['lat'] ?? '');
    $newLng = trim($_POST['lng'] ?? '');
    $errors = [];
    if (!is_numeric($newLat) || $newLat < -90  || $newLat > 90)  $errors[] = 'Latitude must be between -90 and 90.';
    if (!is_numeric($newLng) || $newLng < -180 || $newLng > 180) $errors[] = 'Longitude must be between -180 and 180.';
    if ($errors) { echo json_encode(['ok' => false, 'error' => implode(' ', $errors)]); exit; }
    try {
        $stmt = $db->prepare("INSERT INTO falcon.site_content (section, key, value, updated_at) VALUES ('location', :key, :value, NOW()) ON CONFLICT (section, key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()");
        $mapsUrlSave = trim($_POST['maps_url'] ?? '') ?: 'https://maps.google.com/?q=' . $newLat . ',' . $newLng;
        foreach (['address','hours','phone','email','courts','lat','lng'] as $key) {
            $stmt->execute([':key' => $key, ':value' => trim($_POST[$key] ?? '')]);
        }
        $stmt->execute([':key' => 'maps_url', ':value' => $mapsUrlSave]);
        echo json_encode(['ok' => true, 'message' => '✅ Saved successfully.']);
    } catch (PDOException $e) { echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]); }
    exit;
}

$loc = [];
try {
    $locStmt = $db->query("SELECT key, value FROM falcon.site_content WHERE section = 'location'");
    foreach ($locStmt->fetchAll() as $row) $loc[$row['key']] = $row['value'];
} catch (PDOException $e) {
    error_log('map location fetch error: ' . $e->getMessage());
}

$lat     = (float)($loc['lat']     ?? 6.229294);
$lng     = (float)($loc['lng']     ?? 125.076967);
$address = $loc['address']         ?? 'Purok Sagrado Valencia Site, Polomolok, 9504 South Cotabato';
$hours   = $loc['hours']           ?? '10:00 AM – 12:00 Midnight';
$phone   = $loc['phone']           ?? '+63 912 345 6789';
$email   = $loc['email']           ?? 'falconpickleball@gmail.com';
$courts  = $loc['courts']          ?? '1 Professional Court';
$mapsUrl = $loc['maps_url']        ?? 'https://maps.google.com/?q=' . $lat . ',' . $lng;

if ($isAdmin && empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $isAdmin ? $_SESSION['csrf_token'] : '';

$courtList = [];
try {
    $courtList = $db->query("SELECT id, name, is_active FROM falcon.courts ORDER BY id")->fetchAll();
} catch (PDOException $e) {
    error_log('map courts fetch error: ' . $e->getMessage());
}

$pageTitle = 'Find Us — Map';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>

<style nonce="<?= csrfNonce() ?>">
/* ── Map page layout ─────────────────────────────────────── */
.map-page-wrap {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    background: var(--surface);
    min-height: 500px;
}
@media (min-width: 769px) {
    .map-page-wrap { height: calc(100vh - 200px); max-height: 700px; }
}
@media (max-width: 768px) {
    .map-page-wrap {
        grid-template-columns: 1fr;
        grid-template-rows: auto 320px;
    }
}

/* Sidebar */
.map-sidebar {
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    overflow-x: hidden;
    min-width: 0;
}
@media (max-width: 768px) {
    .map-sidebar { border-right: none; border-bottom: 1px solid var(--border); }
}

.map-sidebar-head {
    padding: 16px 16px 12px;
    border-bottom: 1px solid var(--border);
    background: rgba(255,255,255,0.03);
}
.map-sidebar-head h2 {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 22px;
    letter-spacing: 1px;
    color: var(--accent);
    margin: 0 0 2px;
}
.map-sidebar-head p { font-size: 11px; color: var(--muted); margin: 0; }

.info-row {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 11px 16px;
    border-bottom: 1px solid var(--border);
    transition: background 0.15s;
}
.info-row:hover { background: rgba(0,229,160,0.04); }
.info-icon {
    width: 30px; height: 30px;
    background: rgba(0,229,160,0.1);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: 14px;
}
.info-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--muted); margin-bottom: 2px; }
.info-value { font-size: 13px; color: var(--text); line-height: 1.5; font-weight: 500; word-break: break-word; }
.info-value a { color: var(--accent); text-decoration: none; }
.info-open-daily { color: var(--accent); font-size: 12px; }

.courts-block { padding: 12px 16px; border-bottom: 1px solid var(--border); }
.courts-block-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--muted); margin-bottom: 8px; }
.court-pill {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 10px; border-radius: 7px;
    background: var(--surface2); border: 1px solid var(--border);
    margin-bottom: 5px; font-size: 13px; cursor: pointer; transition: all 0.15s;
}
.court-pill:hover { border-color: var(--accent); color: var(--accent); }
.court-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.court-dot.open   { background: var(--accent, #00e5a0); }
.court-dot.closed { background: var(--muted, #6b7fa3); }
.court-status-open   { margin-left: auto; font-size: 11px; color: var(--accent); }
.court-status-closed { margin-left: auto; font-size: 11px; color: var(--muted); }

.map-osm-note {
    padding: 10px 16px;
    font-size: 11px;
    color: var(--muted);
    background: rgba(0,229,160,0.03);
    border-top: 1px solid var(--border);
}
.map-osm-note strong { color: var(--text); }

.map-actions {
    margin-top: auto;
    padding: 12px 16px;
    display: flex; flex-direction: column; gap: 8px;
    border-top: 1px solid var(--border);
}
@media (max-width: 768px) {
    .map-actions { flex-direction: row; }
    .map-actions a { flex: 1; text-align: center; }
}

/* Map container */
.map-right {
    position: relative;
    height: 500px;
    flex: 1;
    min-width: 0;
    overflow: hidden;
}
@media (min-width: 769px) {
    .map-page-wrap { height: calc(100vh - 200px); max-height: 700px; }
    .map-right { height: 100%; }
}
@media (max-width: 768px) {
    .map-right { height: 320px; }
}
#gmap-frame {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
    display: block;
}

.map-badge {
    position: absolute; top: 12px; right: 12px; z-index: 500;
    background: rgba(10,15,30,0.88);
    backdrop-filter: blur(10px);
    border: 1px solid var(--border);
    border-radius: 10px; padding: 6px 12px;
    font-size: 12px; font-weight: 600; color: var(--text);
    display: flex; align-items: center; gap: 8px; pointer-events: none;
}
.badge-pulse {
    width: 7px; height: 7px; border-radius: 50%; background: var(--accent);
    animation: mapPulse 2s ease infinite;
}
@keyframes mapPulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.4;transform:scale(1.4)} }

.tile-btns {
    position: absolute; bottom: 24px; right: 10px; z-index: 500;
    display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end;
    max-width: 200px;
}
.tile-btn {
    background: rgba(10,15,30,0.88); backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.1); border-radius: 7px;
    padding: 5px 10px; font-size: 11px; font-weight: 600;
    color: #94a3b8; cursor: pointer; transition: all 0.2s; font-family: inherit;
    touch-action: manipulation;
}
.tile-btn.active, .tile-btn:hover { border-color: var(--accent); color: var(--accent); background: rgba(0,229,160,0.12); }

/* Leaflet popup */
.leaflet-popup-content-wrapper { background: #111827 !important; border: 1px solid rgba(0,229,160,0.25) !important; border-radius: 12px !important; box-shadow: 0 8px 32px rgba(0,0,0,0.6) !important; color: #e2e8f0 !important; }
.leaflet-popup-tip { background: #111827 !important; }
.leaflet-popup-content { margin: 12px 14px !important; font-family: 'DM Sans', sans-serif !important; font-size: 13px !important; }
.popup-title { font-weight: 700; font-size: 14px; color: #00e5a0; margin-bottom: 4px; }
.popup-addr  { font-size: 12px; color: #64748b; line-height: 1.5; margin-bottom: 4px; }
.popup-hours { font-size: 12px; color: #e2e8f0; font-weight: 600; }
.popup-link  { display:inline-block; margin-top:8px; color:#00e5a0; font-size:12px; font-weight:600; text-decoration:none; }

/* Page header actions */
.map-header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}

/* Nearby note */
.map-nearby-note {
    margin-top: 16px;
    padding: 12px 16px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    font-size: 13px;
    color: var(--muted);
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.map-nearby-icon { font-size: 18px; flex-shrink: 0; }
.map-nearby-text strong.city { color: var(--text); }
.map-nearby-text strong.brand { color: var(--accent); }

/* Admin panel */
.btn-admin-edit {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 12px; font-size: 12px; font-weight: 600;
    background: rgba(251,191,36,0.1); border: 1px solid rgba(251,191,36,0.4);
    color: #fbbf24; border-radius: 7px; cursor: pointer;
    transition: all 0.2s; font-family: inherit; white-space: nowrap; touch-action: manipulation;
}
.btn-admin-edit:hover { background: rgba(251,191,36,0.2); border-color: #fbbf24; }
.btn-admin-edit.active { background: #fbbf24; color: #0a0f1e; }

#admin-edit-panel { display: none; margin-top: 24px; background: var(--surface); border: 1px solid rgba(251,191,36,0.25); border-radius: var(--radius); overflow: hidden; }
#admin-edit-panel.open { display: block; }

.admin-panel-head { background: rgba(251,191,36,0.06); border-bottom: 1px solid rgba(251,191,36,0.15); padding: 14px 18px; display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.admin-panel-head h3 { font-size: 15px; font-weight: 700; color: #fbbf24; margin: 0; }
.admin-panel-head p  { font-size: 12px; color: var(--muted); margin: 4px 0 0; }

.admin-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    padding: 18px;
}
.admin-form-grid .full { grid-column: 1 / -1; }
@media (max-width: 640px) {
    .admin-form-grid { grid-template-columns: 1fr; }
    .admin-form-grid .full { grid-column: 1; }
}

.afield label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:var(--muted); margin-bottom:6px; }
.afield input, .afield textarea { width:100%; background:var(--surface2); border:1px solid var(--border); border-radius:8px; padding:9px 12px; font-size:14px; color:var(--text); font-family:inherit; transition:border-color 0.2s; box-sizing:border-box; }
.afield input:focus, .afield textarea:focus { outline:none; border-color:#fbbf24; }
.afield textarea { resize:vertical; min-height:56px; }

.afield .maps-url-note { font-weight: 400; text-transform: none; letter-spacing: 0; }

.coord-picker-wrap { grid-column: 1 / -1; border:1px solid var(--border); border-radius:10px; overflow:hidden; }
.coord-picker-head { background:rgba(0,229,160,0.05); border-bottom:1px solid var(--border); padding:10px 14px; font-size:12px; color:var(--muted); display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
#admin-map-picker { width:100%; height:260px; background:#e8e0d8; cursor:crosshair !important; }
.coord-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; padding:12px 14px; background:var(--surface2); border-top:1px solid var(--border); }
@media (max-width: 480px) { .coord-row { grid-template-columns: 1fr; } }

.admin-form-footer { padding:12px 18px; border-top:1px solid var(--border); display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
#admin-save-msg { font-size:13px; font-weight:600; display:none; }
#admin-save-msg.success { color:#00e5a0; }
#admin-save-msg.error   { color:#f87171; }
</style>

<!-- Page header -->
<div class="page-header flex-between" style="margin-bottom:20px;">
    <div>
        <h1>Find Us</h1>
        <p>Falcon Pickleball Court — Polomolok, South Cotabato</p>
    </div>
    <div class="map-header-actions">
        <a href="<?= $mapsUrl ?>" target="_blank" rel="noopener" class="btn-primary btn-sm">
            📍 Open in Google Maps
        </a>
        <?php if ($isAdmin): ?>
            <button class="btn-admin-edit" id="toggle-edit-btn" onclick="toggleAdminPanel()">✏️ Edit Details</button>
        <?php endif; ?>
        <?php if (isLoggedIn()): ?>
            <a href="<?= APP_URL ?>/player/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
        <?php endif; ?>
    </div>
</div>

<!-- Map grid -->
<div class="map-page-wrap">

    <!-- Sidebar -->
    <aside class="map-sidebar">
        <div class="map-sidebar-head">
            <h2>🦅 FALCON</h2>
            <p>Pickleball Court · Polomolok</p>
        </div>

        <div class="info-row">
            <div class="info-icon">📍</div>
            <div>
                <div class="info-label">Address</div>
                <div class="info-value" id="disp-address"><?= nl2br(clean($address)) ?></div>
            </div>
        </div>
        <div class="info-row">
            <div class="info-icon">🕐</div>
            <div>
                <div class="info-label">Hours</div>
                <div class="info-value">
                    <span id="disp-hours"><?= clean($hours) ?></span><br>
                    <span class="info-open-daily">Open Daily</span>
                </div>
            </div>
        </div>
        <div class="info-row">
            <div class="info-icon">📱</div>
            <div>
                <div class="info-label">Phone / GCash</div>
                <div class="info-value">
                    <a href="tel:<?= preg_replace('/[^0-9+]/', '', $phone) ?>" id="disp-phone-link">
                        <span id="disp-phone"><?= clean($phone) ?></span>
                    </a>
                </div>
            </div>
        </div>
        <div class="info-row">
            <div class="info-icon">✉️</div>
            <div>
                <div class="info-label">Email</div>
                <div class="info-value">
                    <a href="mailto:<?= clean($email) ?>" id="disp-email-link">
                        <span id="disp-email"><?= clean($email) ?></span>
                    </a>
                </div>
            </div>
        </div>
        <div class="info-row">
            <div class="info-icon">🏓</div>
            <div>
                <div class="info-label">Facilities</div>
                <div class="info-value" id="disp-courts"><?= clean($courts) ?></div>
            </div>
        </div>

        <?php if (!empty($courtList)): ?>
        <div class="courts-block">
            <div class="courts-block-label">Court Status</div>
            <?php foreach ($courtList as $c): ?>
                <div class="court-pill" onclick="focusMap()">
                    <span class="court-dot <?= $c['is_active'] ? 'open' : 'closed' ?>"></span>
                    <span><?= clean($c['name']) ?></span>
                    <span class="<?= $c['is_active'] ? 'court-status-open' : 'court-status-closed' ?>">
                        <?= $c['is_active'] ? 'Open' : 'Closed' ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="map-osm-note">
            💡 Map by <strong>OpenStreetMap</strong> + Leaflet.js
        </div>

        <div class="map-actions">
            <a href="<?= $mapsUrl ?>" target="_blank" rel="noopener" class="btn-primary btn-sm" id="disp-maps-btn">
                🧭 Get Directions
            </a>
            <?php if (!isLoggedIn()): ?>
                <a href="<?= APP_URL ?>/auth/register.php" class="btn-outline btn-sm">Join Now →</a>
            <?php else: ?>
                <a href="<?= APP_URL ?>/player/schedule.php" class="btn-outline btn-sm">📅 Book a Slot</a>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Map -->
    <div class="map-right">
        <div class="map-badge"><span class="badge-pulse"></span> Falcon Pickleball</div>

        <iframe id="gmap-frame"
            src="https://maps.google.com/maps?q=<?= $lat ?>,<?= $lng ?>&z=17&output=embed"
            width="100%" height="100%"
            allowfullscreen="" loading="lazy"
            referrerpolicy="no-referrer-when-downgrade">
        </iframe>

        <div class="tile-btns">
            <button class="tile-btn active" onclick="setTile('street')"    id="tile-street">🗺 Road</button>
            <button class="tile-btn"        onclick="setTile('satellite')" id="tile-sat">🛰 Satellite</button>
            <button class="tile-btn"        onclick="setTile('hybrid')"    id="tile-hybrid">🌏 Hybrid</button>
        </div>
    </div>
</div>

<!-- Nearby note -->
<div class="map-nearby-note">
    <span class="map-nearby-icon">🏙️</span>
    <span class="map-nearby-text">
        Polomolok is in <strong class="city">South Cotabato, Mindanao</strong>.
        Nearest landmarks: Polomolok Public Market, St. Francis of Assisi Parish, Municipal Hall.
        Look for the <strong class="brand">🏓 Falcon</strong> signage.
    </span>
</div>

<?php if ($isAdmin): ?>
<!-- Admin Edit Panel -->
<div id="admin-edit-panel">
    <div class="admin-panel-head">
        <div>
            <h3>⚙️ Edit Location Details</h3>
            <p>Changes are saved to the database and reflected immediately.</p>
        </div>
        <button class="btn-admin-edit active" onclick="toggleAdminPanel()">✕ Close</button>
    </div>
    <div class="admin-form-grid">
        <div class="afield full"><label>📍 Address</label><textarea id="f-address" rows="2"><?= clean($address) ?></textarea></div>
        <div class="afield full"><label>🕐 Operating Hours</label><input type="text" id="f-hours" value="<?= clean($hours) ?>"/></div>
        <div class="afield"><label>📱 Phone / GCash</label><input type="text" id="f-phone" value="<?= clean($phone) ?>"/></div>
        <div class="afield"><label>✉️ Email</label><input type="email" id="f-email" value="<?= clean($email) ?>"/></div>
        <div class="afield full"><label>🏓 Facilities</label><input type="text" id="f-courts" value="<?= clean($courts) ?>"/></div>
        <div class="coord-picker-wrap">
            <div class="coord-picker-head"><span>📍</span><strong>Pin Exact Court Location</strong><span>— click the map or drag the marker</span></div>
            <div id="admin-map-picker"></div>
            <div class="coord-row">
                <div class="afield"><label>Latitude</label><input type="number" id="f-lat" value="<?= $lat ?>" step="0.000001" min="-90" max="90"/></div>
                <div class="afield"><label>Longitude</label><input type="number" id="f-lng" value="<?= $lng ?>" step="0.000001" min="-180" max="180"/></div>
            </div>
        </div>
        <div class="afield full">
            <label>🔗 Google Maps URL <span class="maps-url-note">(auto-generated if blank)</span></label>
            <input type="url" id="f-maps-url" value="<?= clean($mapsUrl) ?>"/>
        </div>
    </div>
    <div class="admin-form-footer">
        <button class="btn-primary btn-sm" id="admin-save-btn" onclick="saveLocation()">💾 Save Changes</button>
        <button class="btn-outline btn-sm" onclick="resetForm()">↺ Reset</button>
        <span id="admin-save-msg"></span>
    </div>
</div>
<?php endif; ?>

<script nonce="<?= csrfNonce() ?>">
// ── Constants ────────────────────────────────────────────────
const INIT_LAT  = <?= json_encode((float)$lat) ?>;
const INIT_LNG  = <?= json_encode((float)$lng) ?>;
const INIT_ADDR = <?= json_encode($address) ?>;
const INIT_HRS  = <?= json_encode($hours) ?>;
const INIT_MAPS = <?= json_encode($mapsUrl) ?>;
const CSRF      = <?= json_encode($csrfToken) ?>;
const IS_ADMIN  = <?= json_encode((bool)$isAdmin) ?>;
const _APP_URL  = <?= json_encode(APP_URL) ?>;

// ── Google Maps iframe tile switching ───────────────────────
const GMAP_SRCS = {
    street:    `https://maps.google.com/maps?q=${INIT_LAT},${INIT_LNG}&z=17&t=m&output=embed`,
    satellite: `https://maps.google.com/maps?q=${INIT_LAT},${INIT_LNG}&z=17&t=k&output=embed`,
    hybrid:    `https://maps.google.com/maps?q=${INIT_LAT},${INIT_LNG}&z=17&t=h&output=embed`
};

window.setTile = function(type) {
    const frame = document.getElementById('gmap-frame');
    if (!frame || !GMAP_SRCS[type]) return;
    frame.src = GMAP_SRCS[type];
    document.querySelectorAll('.tile-btn').forEach(b => b.classList.remove('active'));
    const ids = {street:'tile-street', satellite:'tile-sat', hybrid:'tile-hybrid'};
    const el = document.getElementById(ids[type]);
    if (el) el.classList.add('active');
};

window.focusMap = function() {
    const frame = document.getElementById('gmap-frame');
    if (frame) frame.src = GMAP_SRCS.street;
    document.querySelectorAll('.tile-btn').forEach(b => b.classList.remove('active'));
    const el = document.getElementById('tile-street');
    if (el) el.classList.add('active');
};

// ── Admin panel ──────────────────────────────────────────────
<?php if ($isAdmin): ?>
const ORIG = {
    address: <?= json_encode($address) ?>,
    hours:   <?= json_encode($hours) ?>,
    phone:   <?= json_encode($phone) ?>,
    email:   <?= json_encode($email) ?>,
    courts:  <?= json_encode($courts) ?>,
    lat:     <?= json_encode($lat) ?>,
    lng:     <?= json_encode($lng) ?>,
    mapsUrl: <?= json_encode($mapsUrl) ?>
};

let adminMap = null, adminMarker = null, panelOpen = false;

const courtIcon = L.divIcon({
    className: '',
    html: `<div style="width:38px;height:38px;background:#00e5a0;border:3px solid #fff;border-radius:50% 50% 50% 4px;transform:rotate(-45deg);box-shadow:0 4px 20px rgba(0,229,160,0.55);display:flex;align-items:center;justify-content:center;"><span style="transform:rotate(45deg);font-size:17px;line-height:1;">🏓</span></div>`,
    iconSize: [38,38], iconAnchor: [19,38], popupAnchor: [0,-42]
});

window.toggleAdminPanel = function() {
    panelOpen = !panelOpen;
    const panel = document.getElementById('admin-edit-panel');
    const btn   = document.getElementById('toggle-edit-btn');
    panel.classList.toggle('open', panelOpen);
    btn.classList.toggle('active', panelOpen);
    btn.textContent = panelOpen ? '✕ Close Editor' : '✏️ Edit Details';
    if (panelOpen) {
        if (!adminMap) setTimeout(initAdminMap, 150);
        setTimeout(() => panel.scrollIntoView({behavior:'smooth', block:'start'}), 100);
    }
};

function initAdminMap() {
    const lat = parseFloat(document.getElementById('f-lat').value) || INIT_LAT;
    const lng = parseFloat(document.getElementById('f-lng').value) || INIT_LNG;
    adminMap = L.map('admin-map-picker', {center:[lat,lng], zoom:18, zoomControl:true, scrollWheelZoom:true});
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors', subdomains:'abc', maxZoom:19
    }).addTo(adminMap);
    adminMarker = L.marker([lat,lng], {icon:courtIcon, draggable:true}).addTo(adminMap);
    adminMarker.on('dragend', e => { const p = e.target.getLatLng(); setAdminCoords(p.lat, p.lng); });
    adminMap.on('click', e => { adminMarker.setLatLng(e.latlng); setAdminCoords(e.latlng.lat, e.latlng.lng); });
    document.getElementById('f-lat').addEventListener('input', syncAdminMap);
    document.getElementById('f-lng').addEventListener('input', syncAdminMap);
    setTimeout(() => adminMap.invalidateSize(), 250);
}

function setAdminCoords(lat, lng) {
    document.getElementById('f-lat').value = parseFloat(lat.toFixed(6));
    document.getElementById('f-lng').value = parseFloat(lng.toFixed(6));
}

function syncAdminMap() {
    const lat = parseFloat(document.getElementById('f-lat').value);
    const lng = parseFloat(document.getElementById('f-lng').value);
    if (isNaN(lat)||isNaN(lng)||lat<-90||lat>90||lng<-180||lng>180) return;
    if (adminMarker) adminMarker.setLatLng([lat,lng]);
    if (adminMap)    adminMap.panTo([lat,lng]);
}

window.resetForm = function() {
    document.getElementById('f-address').value  = ORIG.address;
    document.getElementById('f-hours').value    = ORIG.hours;
    document.getElementById('f-phone').value    = ORIG.phone;
    document.getElementById('f-email').value    = ORIG.email;
    document.getElementById('f-courts').value   = ORIG.courts;
    document.getElementById('f-lat').value      = ORIG.lat;
    document.getElementById('f-lng').value      = ORIG.lng;
    document.getElementById('f-maps-url').value = ORIG.mapsUrl;
    if (adminMarker) { adminMarker.setLatLng([ORIG.lat,ORIG.lng]); adminMap.panTo([ORIG.lat,ORIG.lng]); }
    showMsg('','');
};

window.saveLocation = function() {
    const btn = document.getElementById('admin-save-btn');
    btn.disabled = true; btn.textContent = '⏳ Saving…'; showMsg('','');
    const body = new URLSearchParams({
        action:     'save_location',
        csrf_token:  CSRF,
        address:    document.getElementById('f-address').value.trim(),
        hours:      document.getElementById('f-hours').value.trim(),
        phone:      document.getElementById('f-phone').value.trim(),
        email:      document.getElementById('f-email').value.trim(),
        courts:     document.getElementById('f-courts').value.trim(),
        lat:        document.getElementById('f-lat').value.trim(),
        lng:        document.getElementById('f-lng').value.trim(),
        maps_url:   document.getElementById('f-maps-url').value.trim()
    });
    fetch(_APP_URL + '/player/map.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: body.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) { showMsg('✅ Saved! Reloading…','success'); setTimeout(()=>location.reload(),800); }
        else { showMsg(data.error||'Unknown error.','error'); }
    })
    .catch(()=>showMsg('⚠️ Network error.','error'))
    .finally(()=>{ btn.disabled=false; btn.textContent='💾 Save Changes'; });
};

function showMsg(text, type) {
    const el = document.getElementById('admin-save-msg');
    el.textContent   = text;
    el.className     = type;
    el.style.display = text ? 'inline' : 'none';
}
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>