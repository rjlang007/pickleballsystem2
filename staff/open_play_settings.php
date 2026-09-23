<?php
// Open Play — event posting details for staff, admin, and superadmin.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../tournament/open_play_engine.php';

requireStaff();

$engine = new OpenPlayEngine();
$user   = currentUser();
$events = $engine->listEvents();
$selected = (int)($_GET['tournament_id'] ?? $_POST['tournament_id'] ?? 0);
$event = $selected ? $engine->getEvent($selected) : null;
$openPlayCourts = getDB()->query(
    "SELECT id, name, short_code, COALESCE(is_maintenance, FALSE) AS is_maintenance
       FROM falcon.courts WHERE is_active = TRUE ORDER BY sort_order NULLS LAST, id"
)->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $event = $engine->createEvent($_POST, (int)$user['id']);
            setFlash('success', 'Open Play posting created.');
            redirect('staff/open_play_settings.php?tournament_id=' . (int)$event['id']);
        }

        if ($action === 'update') {
            if (!$event) throw new RuntimeException('Select an Open Play event first.');
            $engine->updateEvent($selected, $_POST, (int)$user['id']);
            setFlash('success', 'Open Play details updated.');
            redirect('staff/open_play_settings.php?tournament_id=' . $selected);
        }

        if ($action === 'close_registration') {
            if (!$event) throw new RuntimeException('Select an Open Play event first.');
            $engine->closeRegistration($selected, (int)$user['id']);
            setFlash('success', '🚪 Registration closed — already queued players may continue.');
            redirect('staff/open_play_settings.php?tournament_id=' . $selected);
        }

        if ($action === 'cancel_event') {
            if (!$event) throw new RuntimeException('Select an Open Play event first.');
            $engine->cancelEvent($selected, (int)$user['id']);
            setFlash('success', '🗑️ Posting disabled and the event was cancelled.');
            redirect('staff/open_play_settings.php?tournament_id=' . $selected);
        }

        throw new RuntimeException('Unknown settings action.');
    } catch (Throwable $e) {
        setFlash('error', 'Unable to save settings: ' . $e->getMessage());
        redirect('staff/open_play_settings.php' . ($selected ? '?tournament_id=' . $selected : ''));
    }
}

$settings = $event ? (json_decode($event['settings'] ?? '{}', true) ?: []) : [];
$pageTitle = 'Open Play Settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Open Play Settings</h1>
    <p>Create or edit the public event posting. Queueing and games are managed separately.</p>
    <a class="btn btn-sm" href="<?= APP_URL ?>/staff/open_play_control.php<?= $selected ? '?tournament_id=' . $selected : '' ?>">← Back to Court Control</a>
</div>

<div class="card" style="margin-bottom:20px;">
    <div class="card-title mb-1">Event Posting</div>
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div style="flex:1;min-width:240px;">
            <label>Select event to edit</label>
            <select name="tournament_id" onchange="this.form.submit()">
                <option value="">-- Select an event --</option>
                <?php foreach ($events as $item): ?>
                    <option value="<?= (int)$item['id'] ?>" <?= $selected === (int)$item['id'] ? 'selected' : '' ?>>
                        <?= clean($item['name']) ?> (<?= clean($item['status']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <a class="btn" href="<?= APP_URL ?>/staff/open_play_control.php<?= $selected ? '?tournament_id=' . $selected : '' ?>">Open Play Control</a>
    </form>
</div>

<div class="card" style="margin-bottom:20px;">
    <div class="card-title mb-1">Create New Open Play</div>
    <form method="POST" style="display:grid;gap:10px;max-width:620px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create"/>
        <label>Name</label>
        <input type="text" name="name" placeholder="Friday Night Open Play" required/>
        <label>Description</label>
        <textarea name="description" rows="2" placeholder="Optional public description"></textarea>
        <label>Date and time</label>
        <input type="datetime-local" name="start_date" required/>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;">
            <div><label>Capacity</label><input type="number" name="max_players" min="4" value="32" required/></div>
            <div><label>Price (PHP)</label><input type="number" name="price" min="0" step="0.01" value="0" required/></div>
        </div>
        <label>Format</label>
        <select name="format"><option value="doubles">Doubles</option><option value="singles">Singles</option></select>
        <label>Courts for Open Play</label>
        <div style="display:flex;gap:14px;flex-wrap:wrap;">
            <label><input type="radio" name="court_scope" value="all" checked onchange="this.closest('form').querySelector('.open-play-court-list').hidden=true"/> All active courts</label>
            <label><input type="radio" name="court_scope" value="selected" onchange="this.closest('form').querySelector('.open-play-court-list').hidden=false"/> Selected courts only</label>
        </div>
        <div class="open-play-court-list" hidden style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;">
            <?php foreach ($openPlayCourts as $court): ?>
                <label style="border:1px solid var(--border);padding:10px;border-radius:8px;"><input type="checkbox" name="court_ids[]" value="<?= (int)$court['id'] ?>"/> <?= clean($court['name']) ?><?= !empty($court['short_code']) ? ' (' . clean($court['short_code']) . ')' : '' ?></label>
            <?php endforeach; ?>
        </div>
        <p class="text-muted" style="font-size:12px;margin:0;">Game duration and other live-session settings are set from Open Play Control once the event is created.</p>
        <button type="submit" class="btn btn-primary" style="justify-self:start;">Create Posting</button>
    </form>
</div>

<?php if ($event): ?>
<div class="card">
    <div class="card-title mb-1">Edit Selected Posting</div>
    <form method="POST" style="display:grid;gap:10px;max-width:620px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update"/>
        <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
        <label>Name</label>
        <input type="text" name="name" value="<?= clean($event['name']) ?>" required/>
        <label>Description</label>
        <textarea name="description" rows="2"><?= clean($event['description'] ?? '') ?></textarea>
        <label>Date and time</label>
        <input type="datetime-local" name="start_date" value="<?= !empty($event['start_date']) ? date('Y-m-d\TH:i', strtotime($event['start_date'])) : '' ?>" required/>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;">
            <div><label>Capacity</label><input type="number" name="max_players" min="4" value="<?= (int)$event['max_players'] ?>" required/></div>
            <div><label>Price (PHP)</label><input type="number" name="price" min="0" step="0.01" value="<?= number_format((float)($settings['price'] ?? 0), 2, '.', '') ?>" required/></div>
        </div>
        <label>Format</label>
        <select name="format"><option value="doubles" <?= ($settings['format'] ?? 'doubles') === 'doubles' ? 'selected' : '' ?>>Doubles</option><option value="singles" <?= ($settings['format'] ?? '') === 'singles' ? 'selected' : '' ?>>Singles</option></select>
        <?php $eventCourtScope = $settings['court_scope'] ?? 'all'; $eventCourtIds = array_map('intval', $settings['court_ids'] ?? []); ?>
        <label>Courts for Open Play</label>
        <div style="display:flex;gap:14px;flex-wrap:wrap;">
            <label><input type="radio" name="court_scope" value="all" <?= $eventCourtScope === 'all' ? 'checked' : '' ?> onchange="this.closest('form').querySelector('.open-play-court-list').hidden=true"/> All active courts</label>
            <label><input type="radio" name="court_scope" value="selected" <?= $eventCourtScope === 'selected' ? 'checked' : '' ?> onchange="this.closest('form').querySelector('.open-play-court-list').hidden=false"/> Selected courts only</label>
        </div>
        <div class="open-play-court-list" <?= $eventCourtScope === 'selected' ? '' : 'hidden' ?> style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;">
            <?php foreach ($openPlayCourts as $court): ?>
                <label style="border:1px solid var(--border);padding:10px;border-radius:8px;"><input type="checkbox" name="court_ids[]" value="<?= (int)$court['id'] ?>" <?= in_array((int)$court['id'], $eventCourtIds, true) ? 'checked' : '' ?>/> <?= clean($court['name']) ?><?= !empty($court['short_code']) ? ' (' . clean($court['short_code']) . ')' : '' ?></label>
            <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary" style="justify-self:start;">Save Posting Details</button>
    </form>
</div>

<?php if (!in_array($event['status'], ['completed', 'cancelled'], true)): ?>
<div class="card" style="margin-top:20px;">
    <div class="card-title mb-1">Posting Status</div>
    <p class="text-muted" style="font-size:13px;margin-top:0;">Current status: <strong><?= clean(ucwords(str_replace('_', ' ', $event['status']))) ?></strong>. Queueing, draws, and live games are managed on the Open Play Control page.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <?php if (in_array($event['status'], ['registration_open', 'in_progress'], true)): ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="close_registration"/>
                <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                <button type="submit" class="btn">🚪 Close Registration</button>
            </form>
        <?php endif; ?>
        <form method="POST" onsubmit="return confirm('Disable this posting? The event will be cancelled, in-progress games stopped, and this cannot be undone.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_event"/>
            <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
            <button type="submit" class="btn btn-danger">🚫 Disable Posting</button>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
