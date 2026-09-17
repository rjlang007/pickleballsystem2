<?php
// ============================================================
//  FILE: staff/tournament_queue.php
//  Staff — tournament queueing & bracket setup.
//
//  Separate from the regular walk-in queue (shown in a compact
//  read-only panel here for visibility, but courts running a
//  tournament match pull from the bracket below, not the walk-in
//  queue). Lets staff/admin:
//   - see who's registered and check them in on tournament day
//   - randomize entrants and generate the bracket (byes handled
//     automatically for non-power-of-2 counts)
//   - manually override a specific matchup before it's played
//   - assign a referee + court to each match (court list is
//     always pulled live from falcon.courts — never hardcoded)
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireStaff();

require_once __DIR__ . '/../tournament/tournament_engine.php';

$db     = getDB();
$user   = currentUser();
$engine = new TournamentEngine();

// ── Handle actions ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $tid    = (int)($_POST['tournament_id'] ?? 0);

    try {
        switch ($action) {
            case 'checkin':
                $engine->setPlayerCheckIn($tid, (int)$_POST['player_id'], $_POST['checked'] === '1', (int)$user['id']);
                setFlash('success', $_POST['checked'] === '1' ? '✅ Player checked in.' : 'Check-in undone.');
                break;

            case 'randomize_start':
                $engine->randomizeAndStart($tid, (int)$user['id']);
                setFlash('success', '🎲 Bracket randomized and tournament started.');
                break;

            case 'assign':
                $matchId  = (int)$_POST['match_id'];
                $refereeId = ($_POST['referee_id'] ?? '') !== '' ? (int)$_POST['referee_id'] : null;
                $courtId   = ($_POST['court_id'] ?? '') !== '' ? (int)$_POST['court_id'] : null;
                $engine->assignRefereeCourt($matchId, $refereeId, $courtId, (int)$user['id']);
                setFlash('success', '📍 Referee/court assignment saved.');
                break;

            case 'override':
                $matchId = (int)$_POST['match_id'];
                $p1 = ($_POST['player1_id'] ?? '') !== '' ? (int)$_POST['player1_id'] : null;
                $p2 = ($_POST['player2_id'] ?? '') !== '' ? (int)$_POST['player2_id'] : null;
                $engine->overrideMatchup($matchId, $p1, $p2, (int)$user['id']);
                setFlash('success', '✏️ Matchup updated.');
                break;

            default:
                setFlash('error', 'Unknown action.');
        }
    } catch (Throwable $e) {
        setFlash('error', '⚠️ ' . $e->getMessage());
    }

    redirect('staff/tournament_queue.php' . ($tid ? '?tournament_id=' . $tid : ''));
}

// ── Load data ────────────────────────────────────────────────
$tournaments = $engine->listTournaments();

$selectedId = (int)($_GET['tournament_id'] ?? 0);
if (!$selectedId) {
    foreach ($tournaments as $t) {
        if (in_array($t['status'], ['registration_open', 'in_progress'], true)) {
            $selectedId = (int)$t['id'];
            break;
        }
    }
}

$selected = $selectedId ? $engine->getTournament($selectedId) : null;
$players  = $selected ? $engine->getTournamentPlayers($selectedId) : [];
$bracket  = $selected ? $engine->getBracket($selectedId) : [];
$courts   = $engine->getActiveCourts();       // always live from falcon.courts
$referees = $engine->getAvailableReferees();

$checkedInCount = count(array_filter($players, fn($p) => !empty($p['checked_in'])));

// Group bracket rows by round for display
$byRound = [];
foreach ($bracket as $m) {
    $byRound[(int)$m['bracket_round']][] = $m;
}
ksort($byRound);

// Compact, read-only view of the *walk-in* queue — deliberately
// separate from the tournament bracket above/below it.
$walkinQueue = $db->query("
    SELECT gq.user_id, gq.joined_at,
           COALESCE(u.display_name, u.full_name) AS name
      FROM falcon.game_queue gq
      JOIN falcon.users u ON u.id = gq.user_id
     WHERE gq.session_id IS NULL
     ORDER BY gq.joined_at ASC
")->fetchAll();

$pageTitle = 'Tournament Queue';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>🏆 Tournament Queue &amp; Bracket Setup</h1>
    <p>Register check-ins, randomize the bracket, and assign a referee + court to each match.</p>
</div>

<!-- ── Walk-in queue (separate from tournament matches) ── -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-title mb-1">🚶 Walk-in Queue <span class="text-muted" style="font-weight:400;">(not part of any tournament)</span></div>
    <hr class="divider"/>
    <?php if (empty($walkinQueue)): ?>
        <p class="text-muted" style="padding:8px 0;">No one waiting in the walk-in queue right now.</p>
    <?php else: ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <?php foreach ($walkinQueue as $i => $w): ?>
                <span class="badge badge-info">#<?= $i + 1 ?> <?= clean($w['name']) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ── Tournament picker ── -->
<div class="card" style="margin-bottom:20px;">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div style="flex:1;min-width:220px;">
            <label>Tournament</label>
            <select name="tournament_id" onchange="this.form.submit()">
                <option value="">— Select a tournament —</option>
                <?php foreach ($tournaments as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= $selectedId === (int)$t['id'] ? 'selected' : '' ?>>
                        <?= clean($t['name']) ?> — <?= clean(str_replace('_', ' ', $t['status'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <noscript><button type="submit" class="btn-outline btn-sm">Go</button></noscript>
    </form>
</div>

<?php if (!$selected): ?>
    <div class="card">
        <p class="text-muted text-center" style="padding:24px;">
            <?= empty($tournaments) ? 'No tournaments created yet — create one from the admin panel first.' : 'Pick a tournament above to manage its queue and bracket.' ?>
        </p>
    </div>
<?php else: ?>

    <div class="card" style="margin-bottom:20px;">
        <div class="card-title mb-1"><?= clean($selected['name']) ?></div>
        <p class="text-muted">
            <?= clean(str_replace('_', ' ', $selected['bracket_type'])) ?> ·
            <span class="badge badge-info"><?= clean(str_replace('_', ' ', $selected['status'])) ?></span> ·
            <?= (int)$selected['current_players'] ?> registered, <?= $checkedInCount ?> checked in ·
            <?= count($courts) ?> active court<?= count($courts) === 1 ? '' : 's' ?> available
        </p>
    </div>

    <?php if ($selected['status'] === 'registration_open'): ?>
    <!-- ── Registration & check-in ── -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-title mb-1">Registered Players</div>
        <p class="text-muted" style="margin-bottom:10px;">
            Check players in as they arrive. If any player is checked in, only checked-in players are placed in the
            bracket when you randomize — no-shows won't get auto-seeded.
        </p>
        <hr class="divider"/>
        <?php if (empty($players)): ?>
            <p class="text-muted text-center" style="padding:20px;">No players registered yet.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Player</th><th>Season Pts</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($players as $p): ?>
                    <tr>
                        <td style="font-weight:600;"><?= clean($p['display_name'] ?: $p['full_name']) ?></td>
                        <td><?= (int)$p['season_points'] ?></td>
                        <td>
                            <?php if (!empty($p['checked_in'])): ?>
                                <span class="badge badge-success">✔ Checked in</span>
                            <?php else: ?>
                                <span class="badge badge-warn">Not checked in</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="checkin">
                                <input type="hidden" name="tournament_id" value="<?= (int)$selected['id'] ?>">
                                <input type="hidden" name="player_id" value="<?= (int)$p['player_id'] ?>">
                                <input type="hidden" name="checked" value="<?= !empty($p['checked_in']) ? '0' : '1' ?>">
                                <button type="submit" class="btn-outline btn-sm">
                                    <?= !empty($p['checked_in']) ? 'Undo' : 'Check in' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <form method="POST" style="margin-top:16px;" onsubmit="return confirm('Randomize entrants and start the bracket now? This locks the field.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="randomize_start">
            <input type="hidden" name="tournament_id" value="<?= (int)$selected['id'] ?>">
            <button type="submit" class="btn-primary">🎲 Randomize &amp; Start Bracket</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!empty($bracket)): ?>
    <!-- ── Bracket / matches ── -->
    <?php foreach ($byRound as $round => $matches): ?>
    <div class="card" style="margin-bottom:20px;">
        <div class="card-title mb-1">Round <?= (int)$round ?></div>
        <hr class="divider"/>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Matchup</th><th>Status</th><th>Court</th><th>Referee</th><th>Edit matchup</th></tr></thead>
                <tbody>
                <?php foreach ($matches as $m): ?>
                    <tr>
                        <td style="font-weight:600;">
                            <?= clean($m['player1_name'] ?: ($m['player1_id'] ? '#' . $m['player1_id'] : ($m['status'] === 'bye' ? '—' : 'TBD'))) ?>
                            vs
                            <?= clean($m['player2_name'] ?: ($m['player2_id'] ? '#' . $m['player2_id'] : ($m['status'] === 'bye' ? 'BYE' : 'TBD'))) ?>
                        </td>
                        <td><span class="badge badge-<?= $m['status'] === 'completed' ? 'success' : ($m['status'] === 'bye' ? 'info' : 'warn') ?>">
                            <?= clean(str_replace('_', ' ', $m['status'])) ?>
                        </span></td>

                        <td colspan="2">
                            <?php if ($m['status'] !== 'completed'): ?>
                            <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="assign">
                                <input type="hidden" name="tournament_id" value="<?= (int)$selected['id'] ?>">
                                <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">
                                <select name="court_id">
                                    <option value="">Court…</option>
                                    <?php foreach ($courts as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>" <?= (int)($m['court_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= clean($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="referee_id">
                                    <option value="">Referee…</option>
                                    <?php foreach ($referees as $r): ?>
                                        <option value="<?= (int)$r['id'] ?>" <?= (int)($m['referee_id'] ?? 0) === (int)$r['id'] ? 'selected' : '' ?>><?= clean($r['display_name'] ?: $r['full_name'] ?: $r['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn-outline btn-sm">Save</button>
                            </form>
                            <?php else: ?>
                                <span class="text-muted"><?= clean($m['court_id'] ? ('Court #' . $m['court_id']) : '—') ?></span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($m['status'] !== 'completed' && !empty($players)): ?>
                            <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="override">
                                <input type="hidden" name="tournament_id" value="<?= (int)$selected['id'] ?>">
                                <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">
                                <select name="player1_id">
                                    <option value="">— empty —</option>
                                    <?php foreach ($players as $p): ?>
                                        <option value="<?= (int)$p['player_id'] ?>" <?= (int)$m['player1_id'] === (int)$p['player_id'] ? 'selected' : '' ?>><?= clean($p['display_name'] ?: $p['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="player2_id">
                                    <option value="">— empty —</option>
                                    <?php foreach ($players as $p): ?>
                                        <option value="<?= (int)$p['player_id'] ?>" <?= (int)$m['player2_id'] === (int)$p['player_id'] ? 'selected' : '' ?>><?= clean($p['display_name'] ?: $p['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn-outline btn-sm" onclick="return confirm('Override this matchup?');">Swap</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
    <?php elseif ($selected['status'] !== 'registration_open'): ?>
        <div class="card"><p class="text-muted text-center" style="padding:24px;">No bracket generated yet.</p></div>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
