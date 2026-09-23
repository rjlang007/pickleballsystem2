<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../tournament/open_play_engine.php';
requireStaff();

$engine = new OpenPlayEngine();
$user = currentUser();
$selected = (int)($_GET['tournament_id'] ?? $_POST['tournament_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $tid = (int)($_POST['tournament_id'] ?? 0);
    try {
        switch ($action) {
            case 'add_player':
                $playerId = (int)($_POST['player_id'] ?? 0);
                $skillLevel = $_POST['skill_level'] ?? 'average';
                if ($playerId > 0) {
                    $engine->addPlayerByStaff($tid, $playerId, $skillLevel, (int)$user['id']);
                } else {
                    $engine->addGuestByStaff($tid, (string)($_POST['player_name_lookup'] ?? ''), $skillLevel, (int)$user['id']);
                }
                setFlash('success', 'Player registered successfully.');
                break;
            case 'approve_join':
                $engine->approveJoin($tid, (int)$_POST['player_id'], (int)$user['id']);
                setFlash('success', 'Join request approved.');
                break;
            case 'reject_join':
                $engine->rejectJoin($tid, (int)$_POST['player_id'], (int)$user['id']);
                setFlash('success', 'Join request rejected.');
                break;
            case 'queue_status':
                $engine->setQueueStatus($tid, (int)$_POST['player_id'], $_POST['status'], (int)$user['id']);
                setFlash('success', 'Player queue status updated.');
                break;
            case 'remove_player':
                $engine->leaveEvent($tid, (int)$_POST['player_id']);
                setFlash('success', 'Player removed from registration.');
                break;
            default:
                throw new RuntimeException('Unknown registration action.');
        }
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
    }
    redirect('staff/open_play_registration.php' . ($tid ? '?tournament_id=' . $tid : ''));
}

$events = $engine->listEvents();
if (!$selected) {
    foreach ($events as $eventOption) {
        if (in_array($eventOption['status'], ['registration_open', 'in_progress', 'paused'], true)) { $selected = (int)$eventOption['id']; break; }
    }
}
$event = $selected ? $engine->getEvent($selected) : null;
$roster = $event ? $engine->getRoster($selected) : [];
$db = getDB();
    $players = $db->query("SELECT id, COALESCE(display_name, full_name, username) AS name FROM falcon.users WHERE role = 'player' AND is_banned = FALSE AND is_guest = FALSE ORDER BY name LIMIT 500")->fetchAll();
$pageTitle = 'Open Play Registration';
require_once __DIR__ . '/../includes/header.php';
$moduleTitle = 'Registration & Approvals';
$moduleDescription = 'Register players manually and review player requests before they enter the Open Play queue.';
$moduleEventName = $event['name'] ?? '';
require __DIR__ . '/open_play_module_header.php';
?>
<div class="op-module-panel">
    <form method="GET" class="opc-add">
        <label class="opc-help" for="registrationEvent">Event</label>
        <select id="registrationEvent" name="tournament_id" class="opc-field" data-autosubmit>
            <option value="">Select an event</option>
            <?php foreach ($events as $item): ?>
                <option value="<?= (int)$item['id'] ?>" <?= $selected === (int)$item['id'] ? 'selected' : '' ?>><?= clean($item['name']) ?> · <?= clean(str_replace('_', ' ', $item['status'])) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>
<?php if (!$event): ?>
<div class="op-module-panel"><p class="opc-help">Select an Open Play event to manage registration.</p></div>
<?php else: ?>
<div class="op-module-panel">
    <div class="opc-section-head"><h2 class="opc-h3">Add Player Manually</h2><span class="op-module-meta" id="registeredCount"><?= count($roster) ?> registered</span></div>
    <p class="opc-help">Staff-added players are approved immediately. Players who request to join appear below as pending until approved.</p>
    <form method="POST" class="opc-add" id="addPlayerForm">
        <?= csrfField() ?><input type="hidden" name="action" value="add_player"/><input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
        <input type="text" list="playerList" name="player_name_lookup" class="opc-field" placeholder="Search player name" autocomplete="off" required/>
        <input type="hidden" name="player_id" id="player_id_field"/>
        <datalist id="playerList"><?php foreach ($players as $player): ?><option data-id="<?= (int)$player['id'] ?>" value="<?= clean($player['name']) ?>"></option><?php endforeach; ?></datalist>
        <select name="skill_level" class="opc-field"><option value="beginner">Beginner</option><option value="average" selected>Average</option><option value="advance">Advance</option></select>
        <button class="opc-btn-action opc-sm" type="submit">Register player</button>
    </form>
</div>
<div class="op-module-panel">
    <div class="opc-section-head"><h2 class="opc-h3">Registered Players &amp; Join Requests</h2><span class="op-module-meta" id="rosterCount"><?= count($roster) ?> players</span></div>
    <div class="opc-table-wrap"><table class="opc-table"><thead><tr><th>Player</th><th>Skill</th><th>Status</th><th>Record</th><th>Actions</th></tr></thead><tbody id="rosterBody">
    <?php foreach ($roster as $row): $pending = $row['status'] === 'pending_approval'; $label = $pending ? 'Pending approval' : ucfirst((string)$row['queue_status']); ?>
    <tr>
        <td><?= clean($row['display_name'] ?? $row['full_name'] ?? $row['username']) ?></td><td><?= clean(ucfirst($row['skill_level'])) ?></td><td><?= clean($label) ?></td><td><?= (int)$row['wins'] ?>-<?= (int)$row['losses'] ?></td>
        <td><div class="opc-cell-actions">
        <?php if ($pending): ?><form method="POST" data-op-action="approve_join"><?= csrfField() ?><input type="hidden" name="action" value="approve_join"/><input type="hidden" name="tournament_id" value="<?= $selected ?>"/><input type="hidden" name="player_id" value="<?= (int)$row['player_id'] ?>"/><button class="opc-mini opc-mini-green">Approve</button></form><form method="POST" data-op-action="reject_join"><?= csrfField() ?><input type="hidden" name="action" value="reject_join"/><input type="hidden" name="tournament_id" value="<?= $selected ?>"/><input type="hidden" name="player_id" value="<?= (int)$row['player_id'] ?>"/><button class="opc-mini opc-mini-red">Reject</button></form>
        <?php elseif ($row['queue_status'] === 'waiting'): ?><form method="POST" data-op-action="queue_status"><?= csrfField() ?><input type="hidden" name="action" value="queue_status"/><input type="hidden" name="status" value="resting"/><input type="hidden" name="tournament_id" value="<?= $selected ?>"/><input type="hidden" name="player_id" value="<?= (int)$row['player_id'] ?>"/><button class="opc-mini">Rest</button></form>
        <?php else: ?><form method="POST" data-op-action="queue_status"><?= csrfField() ?><input type="hidden" name="action" value="queue_status"/><input type="hidden" name="status" value="waiting"/><input type="hidden" name="tournament_id" value="<?= $selected ?>"/><input type="hidden" name="player_id" value="<?= (int)$row['player_id'] ?>"/><button class="opc-mini">Return</button></form><?php endif; ?>
        <form method="POST" data-op-action="remove_player" data-confirm="Remove this player from the event?"><?= csrfField() ?><input type="hidden" name="action" value="remove_player"/><input type="hidden" name="tournament_id" value="<?= $selected ?>"/><input type="hidden" name="player_id" value="<?= (int)$row['player_id'] ?>"/><button class="opc-mini opc-mini-red">Remove</button></form>
        </div></td>
    </tr>
    <?php endforeach; if (!$roster): ?><tr><td colspan="5">No registered players or pending requests.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php endif; ?>
<div id="opRegToasts" style="position:fixed;bottom:18px;right:18px;z-index:9999;display:flex;flex-direction:column;gap:8px;"></div>
<script nonce="<?= getCspNonce() ?>">
(function () {
    const APP_URL = window.APP_URL || '<?= APP_URL ?>';
    const TID = <?= (int)$selected ?>;

    // ── Player typeahead: fills the hidden player_id when the name matches an existing account ──
    const playerInput = document.querySelector('input[name="player_name_lookup"]');
    playerInput?.addEventListener('input', () => {
        const option = [...document.querySelectorAll('#playerList option')].find(item => item.value === playerInput.value);
        document.getElementById('player_id_field').value = option?.dataset.id || '';
    });

    function toast(msg, isError) {
        const box = document.getElementById('opRegToasts');
        if (!box) return;
        const t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = 'padding:10px 14px;border-radius:8px;font-size:13px;color:#fff;box-shadow:0 4px 14px rgba(0,0,0,.25);' +
            (isError ? 'background:#dc2626;' : 'background:#16a34a;');
        box.appendChild(t);
        setTimeout(() => t.remove(), 4000);
    }

    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    // ── Re-render the roster table + counts from a fresh roster payload ──
    function renderRoster(roster) {
        const body = document.getElementById('rosterBody');
        if (!body) return;
        document.getElementById('registeredCount').textContent = `${roster.length} registered`;
        document.getElementById('rosterCount').textContent = `${roster.length} players`;
        if (!roster.length) {
            body.innerHTML = '<tr><td colspan="5">No registered players or pending requests.</td></tr>';
            return;
        }
        body.innerHTML = roster.map(row => {
            const pending = row.status === 'pending_approval';
            const label = pending ? 'Pending approval' : (row.queue_status ? row.queue_status.charAt(0).toUpperCase() + row.queue_status.slice(1) : '');
            const name = escapeHtml(row.display_name || row.full_name || row.username || 'Player');
            const skill = escapeHtml((row.skill_level || '').charAt(0).toUpperCase() + (row.skill_level || '').slice(1));
            const pid = row.player_id;
            let actions = '';
            if (pending) {
                actions += formHtml('approve_join', pid, '', 'Approve', 'opc-mini-green');
                actions += formHtml('reject_join', pid, '', 'Reject', 'opc-mini-red');
            } else if (row.queue_status === 'waiting') {
                actions += formHtml('queue_status', pid, 'resting', 'Rest', '');
            } else {
                actions += formHtml('queue_status', pid, 'waiting', 'Return', '');
            }
            actions += formHtml('remove_player', pid, '', 'Remove', 'opc-mini-red', 'Remove this player from the event?');
            return `<tr><td>${name}</td><td>${skill}</td><td>${escapeHtml(label)}</td><td>${row.wins|0}-${row.losses|0}</td><td><div class="opc-cell-actions">${actions}</div></td></tr>`;
        }).join('');
    }

    function formHtml(action, pid, status, label, cls, confirmMsg) {
        return `<form method="POST" data-op-action="${action}"${confirmMsg ? ` data-confirm="${escapeHtml(confirmMsg)}"` : ''}>
            <input type="hidden" name="csrf_token" value="${escapeHtml(CSRF_TOKEN)}"/>
            <input type="hidden" name="action" value="${action}"/>
            <input type="hidden" name="tournament_id" value="${TID}"/>
            <input type="hidden" name="player_id" value="${pid}"/>
            ${status ? `<input type="hidden" name="status" value="${status}"/>` : ''}
            <button type="submit" class="opc-mini ${cls}">${label}</button></form>`;
    }

    const CSRF_TOKEN = '<?= csrfToken() ?>';

    async function callApi(action, payload) {
        const res = await fetch(`${APP_URL}/api/open_play.php?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload || {}),
        });
        let data;
        try { data = await res.json(); }
        catch (_) { throw new Error('Your session may have expired — reload the page and try again.'); }
        if (!data.success) throw new Error(data.message || 'Request failed.');
        return data;
    }

    // ── Keep this view live: if another staff member adds/approves/removes
    // a player from a different tab or device, this page's table should
    // catch up on its own rather than showing a stale roster. Paused while
    // the add-player field has focus so a background refresh can't wipe
    // out mid-typed input. ──
    let pollBusy = false;
    async function pollRoster() {
        if (!TID || pollBusy) return;
        if (document.activeElement === playerInput) return;
        pollBusy = true;
        try {
            const res = await fetch(`${APP_URL}/api/open_play.php?action=roster&tournament_id=${TID}`, { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            if (data.success && Array.isArray(data.data)) renderRoster(data.data);
        } catch (_) { /* next tick will retry */ }
        pollBusy = false;
    }
    if (TID) setInterval(pollRoster, 8000);

    // ── Intercept every form on this page (add player + per-row actions) so nothing reloads ──
    document.addEventListener('submit', async e => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        const action = form.dataset.opAction || form.querySelector('input[name="action"]')?.value;
        if (!action) return; // not one of ours — let it behave normally
        e.preventDefault();

        const confirmMsg = form.dataset.confirm;
        if (confirmMsg && !confirm(confirmMsg)) return;

        const button = form.querySelector('button[type="submit"]');
        const originalText = button ? button.textContent : '';
        if (button) { button.disabled = true; button.textContent = '…'; }

        const payload = {};
        new FormData(form).forEach((value, key) => {
            if (key === 'csrf_token') return;
            payload[key] = value;
        });

        try {
            const data = await callApi(action, payload);
            toast(data.message || 'Done.', false);
            if (Array.isArray(data.data)) renderRoster(data.data);
            if (action === 'add_player') {
                form.reset();
                document.getElementById('player_id_field').value = '';
            }
        } catch (err) {
            toast(err.message || 'Something went wrong.', true);
        } finally {
            if (button) { button.disabled = false; button.textContent = originalText; }
        }
    });
})();
</script>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
