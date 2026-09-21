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
                $engine->addPlayerByStaff($tid, (int)$_POST['player_id'], $_POST['skill_level'] ?? 'average', (int)$user['id']);
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
$players = $db->query("SELECT id, COALESCE(display_name, full_name, username) AS name FROM falcon.users WHERE role = 'player' AND is_banned = FALSE ORDER BY name LIMIT 500")->fetchAll();
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
    <div class="opc-section-head"><h2 class="opc-h3">Add Player Manually</h2><span class="op-module-meta"><?= count($roster) ?> registered</span></div>
    <p class="opc-help">Staff-added players are approved immediately. Players who request to join appear below as pending until approved.</p>
    <form method="POST" class="opc-add">
        <?= csrfField() ?><input type="hidden" name="action" value="add_player"/><input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
        <input type="text" list="playerList" name="player_name_lookup" class="opc-field" placeholder="Search player name" autocomplete="off" required/>
        <input type="hidden" name="player_id" id="player_id_field" required/>
        <datalist id="playerList"><?php foreach ($players as $player): ?><option data-id="<?= (int)$player['id'] ?>" value="<?= clean($player['name']) ?>"></option><?php endforeach; ?></datalist>
        <select name="skill_level" class="opc-field"><option value="beginner">Beginner</option><option value="average" selected>Average</option><option value="advance">Advance</option></select>
        <button class="opc-btn-action opc-sm" type="submit">Register player</button>
    </form>
</div>
<div class="op-module-panel">
    <div class="opc-section-head"><h2 class="opc-h3">Registered Players &amp; Join Requests</h2><span class="op-module-meta"><?= count($roster) ?> players</span></div>
    <div class="opc-table-wrap"><table class="opc-table"><thead><tr><th>Player</th><th>Skill</th><th>Status</th><th>Record</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($roster as $row): $pending = $row['status'] === 'pending_approval'; $label = $pending ? 'Pending approval' : ucfirst((string)$row['queue_status']); ?>
    <tr>
        <td><?= clean($row['display_name'] ?? $row['full_name'] ?? $row['username']) ?></td><td><?= clean(ucfirst($row['skill_level'])) ?></td><td><?= clean($label) ?></td><td><?= (int)$row['wins'] ?>-<?= (int)$row['losses'] ?></td>
        <td><div class="opc-cell-actions">
        <?php if ($pending): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="approve_join"/><input type="hidden" name="tournament_id" value="<?= $selected ?>"/><input type="hidden" name="player_id" value="<?= (int)$row['player_id'] ?>"/><button class="opc-mini opc-mini-green">Approve</button></form><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="reject_join"/><input type="hidden" name="tournament_id" value="<?= $selected ?>"/><input type="hidden" name="player_id" value="<?= (int)$row['player_id'] ?>"/><button class="opc-mini opc-mini-red">Reject</button></form>
        <?php elseif ($row['queue_status'] === 'waiting'): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="queue_status"/><input type="hidden" name="status" value="resting"/><input type="hidden" name="tournament_id" value="<?= $selected ?>"/><input type="hidden" name="player_id" value="<?= (int)$row['player_id'] ?>"/><button class="opc-mini">Rest</button></form>
        <?php else: ?><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="queue_status"/><input type="hidden" name="status" value="waiting"/><input type="hidden" name="tournament_id" value="<?= $selected ?>"/><input type="hidden" name="player_id" value="<?= (int)$row['player_id'] ?>"/><button class="opc-mini">Return</button></form><?php endif; ?>
        <form method="POST" data-confirm="Remove this player from the event?"><?= csrfField() ?><input type="hidden" name="action" value="remove_player"/><input type="hidden" name="tournament_id" value="<?= $selected ?>"/><input type="hidden" name="player_id" value="<?= (int)$row['player_id'] ?>"/><button class="opc-mini opc-mini-red">Remove</button></form>
        </div></td>
    </tr>
    <?php endforeach; if (!$roster): ?><tr><td colspan="5">No registered players or pending requests.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php endif; ?>
<script nonce="<?= getCspNonce() ?>">
const playerInput = document.querySelector('input[name="player_name_lookup"]');
playerInput?.addEventListener('input', () => { const option = [...document.querySelectorAll('#playerList option')].find(item => item.value === playerInput.value); document.getElementById('player_id_field').value = option?.dataset.id || ''; });
playerInput?.closest('form')?.addEventListener('submit', event => { if (!document.getElementById('player_id_field').value) { event.preventDefault(); alert('Select a player from the suggestions.'); } });
</script>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
