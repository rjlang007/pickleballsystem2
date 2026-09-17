<?php
// ============================================================
//  FILE: referee/score_match.php
//  Referee — live scoring form for a single assigned match.
//  Mobile-first: big tap targets, works one-handed courtside.
//  Every tap writes straight to the DB via referee/score_ajax.php
//  (no "publish" step) so admin/staff/kiosk/player standings see
//  it within one polling cycle, and a refresh/reconnect never
//  loses state because the score itself lives in the DB, not in
//  page memory.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireReferee();

require_once __DIR__ . '/../tournament/tournament_engine.php';

$db      = getDB();
$user    = currentUser();
$engine  = new TournamentEngine();
$matchId = (int)($_GET['match_id'] ?? 0);

$match = $matchId ? $engine->getMatchForScoring($matchId) : null;
if (!$match) {
    setFlash('error', '⚠️ Match not found.');
    redirect('referee/dashboard.php');
}

$isOwner = (int)($match['referee_id'] ?? 0) === (int)$user['id'];
$isAdmin = in_array($user['role'], ADMIN_ROLES, true);
if (!$isOwner && !$isAdmin) {
    setFlash('error', '⛔ You are not assigned to this match.');
    redirect('referee/dashboard.php');
}

$events = $engine->getMatchEvents($matchId);

$p1Name = $match['p1_display'] ?: $match['p1_name'] ?: 'Player 1';
$p2Name = $match['p2_display'] ?: $match['p2_name'] ?: 'Player 2';
$isBye     = $match['status'] === 'bye';
$notReady  = !$isBye && (!$match['player1_id'] || !$match['player2_id']);
$isDone    = $match['status'] === 'completed';

$pageTitle = 'Score Match';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.score-wrap { max-width: 720px; margin: 0 auto; }
.score-head { text-align: center; margin-bottom: 16px; }
.score-head .round { color: var(--muted); font-size: 14px; }
.score-board {
    display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
    margin-bottom: 16px;
}
.score-panel {
    background: var(--surface2); border: 2px solid var(--border);
    border-radius: var(--radius-lg); padding: 20px 12px;
    text-align: center; transition: border-color var(--t-base) var(--ease);
}
.score-panel.serving { border-color: var(--accent); box-shadow: var(--glow-accent); }
.score-panel .pname {
    font-weight: 700; font-size: 16px; margin-bottom: 4px; min-height: 22px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.score-panel .serve-badge {
    display: inline-block; font-size: 11px; color: var(--accent);
    margin-bottom: 6px; min-height: 16px; font-weight: 600;
}
.score-panel .score-num {
    font-size: 64px; font-weight: 800; line-height: 1; color: var(--text);
    margin: 8px 0;
}
.score-btns { display: flex; gap: 10px; justify-content: center; margin-top: 12px; }
.score-btn {
    width: 56px; height: 56px; border-radius: 50%; border: none;
    font-size: 26px; font-weight: 700; cursor: pointer;
    -webkit-tap-highlight-color: transparent; user-select: none;
    transition: transform var(--t-fast) var(--ease);
}
.score-btn:active { transform: scale(0.92); }
.score-btn.plus  { background: var(--grad-accent); color: #06251c; }
.score-btn.minus { background: var(--surface3); color: var(--text); border: 1px solid var(--border); }
.serve-toggle {
    display: block; width: 100%; margin-top: 10px; padding: 10px;
    border-radius: var(--radius); border: 1px solid var(--border);
    background: var(--surface3); color: var(--text); font-size: 13px;
    cursor: pointer;
}
.side-row { display: flex; justify-content: center; gap: 8px; margin-top: 8px; }
.side-btn {
    padding: 6px 14px; border-radius: var(--radius-pill); border: 1px solid var(--border);
    background: var(--surface3); color: var(--muted); font-size: 12px; cursor: pointer;
}
.side-btn.active { background: var(--accent); color: #06251c; border-color: var(--accent); font-weight: 700; }
.action-row { display: flex; gap: 10px; flex-wrap: wrap; margin: 20px 0; }
.action-row > * { flex: 1; min-width: 160px; }
.events-toggle { color: var(--accent2); cursor: pointer; font-size: 13px; }
#eventsLog { display: none; margin-top: 10px; max-height: 240px; overflow-y: auto; }
#eventsLog .ev-row { font-size: 12px; color: var(--muted); padding: 4px 0; border-bottom: 1px dashed var(--border-soft); }
@media (max-width: 480px) {
    .score-panel .score-num { font-size: 48px; }
    .score-btn { width: 50px; height: 50px; font-size: 22px; }
}
</style>

<div class="score-wrap">
    <div class="page-header score-head">
        <h1>🧑‍⚖️ <?= clean($match['tournament_name']) ?></h1>
        <p class="round">Round <?= (int)$match['bracket_round'] ?> · <?= clean($match['court_name'] ?: 'Court TBD') ?></p>
    </div>

    <?php if ($isBye): ?>
        <div class="card"><p class="text-muted text-center" style="padding:24px;">This is a bye — nothing to score.</p></div>
    <?php elseif ($notReady): ?>
        <div class="card"><p class="text-muted text-center" style="padding:24px;">Waiting for both players to be determined from earlier rounds — check back once they're set.</p></div>
    <?php elseif ($isDone): ?>
        <div class="card">
            <p class="text-center" style="padding:12px;">
                ✅ Match complete —
                <strong><?= clean($p1Name) ?> <?= (int)$match['score_player1'] ?></strong>
                &nbsp;–&nbsp;
                <strong><?= clean($p2Name) ?> <?= (int)$match['score_player2'] ?></strong>
            </p>
            <p class="text-muted text-center">Need to fix the recorded score? Use the correction below — it's logged, not overwritten silently.</p>
        </div>
    <?php endif; ?>

    <?php if (!$isBye && !$notReady): ?>
    <div class="score-board">
        <div class="score-panel <?= (int)($match['serving_player'] ?? 0) === 1 ? 'serving' : '' ?>" id="panel-1">
            <div class="pname"><?= clean($p1Name) ?></div>
            <div class="serve-badge" id="serve-badge-1"><?= (int)($match['serving_player'] ?? 0) === 1 ? '🏓 serving' : '' ?></div>
            <div class="score-num" id="score-1"><?= (int)$match['score_player1'] ?></div>
            <?php if (!$isDone): ?>
            <div class="score-btns">
                <button class="score-btn minus" onclick="adjust(1,-1)" aria-label="Subtract point from <?= clean($p1Name) ?>">−</button>
                <button class="score-btn plus"  onclick="adjust(1, 1)" aria-label="Add point to <?= clean($p1Name) ?>">+</button>
            </div>
            <button class="serve-toggle" onclick="setServer(1)">Set serving</button>
            <?php endif; ?>
        </div>

        <div class="score-panel <?= (int)($match['serving_player'] ?? 0) === 2 ? 'serving' : '' ?>" id="panel-2">
            <div class="pname"><?= clean($p2Name) ?></div>
            <div class="serve-badge" id="serve-badge-2"><?= (int)($match['serving_player'] ?? 0) === 2 ? '🏓 serving' : '' ?></div>
            <div class="score-num" id="score-2"><?= (int)$match['score_player2'] ?></div>
            <?php if (!$isDone): ?>
            <div class="score-btns">
                <button class="score-btn minus" onclick="adjust(2,-1)" aria-label="Subtract point from <?= clean($p2Name) ?>">−</button>
                <button class="score-btn plus"  onclick="adjust(2, 1)" aria-label="Add point to <?= clean($p2Name) ?>">+</button>
            </div>
            <button class="serve-toggle" onclick="setServer(2)">Set serving</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="side-row">
        <span class="text-muted" style="font-size:12px;align-self:center;">Serving from:</span>
        <button class="side-btn <?= ($match['serving_side'] ?? 'right') === 'left' ? 'active' : '' ?>" id="side-left"  onclick="setSide('left')">Left</button>
        <button class="side-btn <?= ($match['serving_side'] ?? 'right') === 'right' ? 'active' : '' ?>" id="side-right" onclick="setSide('right')">Right</button>
    </div>

    <?php if (!$isDone): ?>
    <div class="action-row">
        <button class="btn-primary" id="completeBtn" onclick="completeMatch()">🏁 Complete Match</button>
        <button class="btn-outline" onclick="openCorrection()">✏️ Correct Score</button>
    </div>
    <?php else: ?>
    <div class="action-row">
        <button class="btn-outline" onclick="openCorrection()">✏️ Correct Score</button>
        <a class="btn-outline" style="text-align:center;" href="<?= APP_URL ?>/referee/dashboard.php">← Back to my matches</a>
    </div>
    <?php endif; ?>

    <div class="card" id="correctionCard" style="display:none;margin-bottom:16px;">
        <div class="card-title mb-1">Correct Score</div>
        <p class="text-muted" style="font-size:13px;margin-bottom:10px;">Use this for typos or disputed calls — it's recorded as a correction in the match log, not a silent overwrite.</p>
        <div class="form-row-2">
            <div><label><?= clean($p1Name) ?></label><input type="number" min="0" id="correct-1" value="<?= (int)$match['score_player1'] ?>"></div>
            <div><label><?= clean($p2Name) ?></label><input type="number" min="0" id="correct-2" value="<?= (int)$match['score_player2'] ?>"></div>
        </div>
        <label>Reason (optional)</label>
        <input type="text" id="correct-note" placeholder="e.g. missed a point after a let call">
        <div class="action-row">
            <button class="btn-primary" onclick="submitCorrection()">Save Correction</button>
            <button class="btn-outline" onclick="closeCorrection()">Cancel</button>
        </div>
    </div>

    <div class="card">
        <span class="events-toggle" onclick="toggleEvents()">📜 Show scoring history (<?= count($events) ?>)</span>
        <div id="eventsLog">
            <?php if (empty($events)): ?>
                <p class="text-muted" style="font-size:12px;">No events yet.</p>
            <?php else: foreach (array_reverse($events) as $ev): ?>
                <div class="ev-row">
                    <?= clean(date('g:i:s a', strtotime($ev['created_at']))) ?> —
                    <?= clean(str_replace('_',' ', $ev['event_type'])) ?>
                    (<?= (int)$ev['score_player1'] ?>–<?= (int)$ev['score_player2'] ?>)
                    <?php if (!empty($ev['note'])): ?> · <?= clean($ev['note']) ?><?php endif; ?>
                    <?php if (!empty($ev['actor_username'])): ?> · by <?= clean($ev['actor_username']) ?><?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script nonce="<?= getCspNonce() ?>">
const MATCH_ID = <?= (int)$matchId ?>;
const AJAX_URL = <?= json_encode(APP_URL . '/referee/score_ajax.php') ?>;

function callScoreApi(payload) {
    return fetch(AJAX_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(Object.assign({ match_id: MATCH_ID }, payload)),
    }).then(r => r.json());
}

function adjust(slot, delta) {
    callScoreApi({ action: 'point', player_slot: slot, delta: delta }).then(d => {
        if (!d.ok) { showToast({type:'error', message: d.message || 'Could not update score.'}); return; }
        document.getElementById('score-1').textContent = d.score_player1;
        document.getElementById('score-2').textContent = d.score_player2;
    }).catch(() => showToast({type:'error', message: 'Connection error — point NOT saved, try again.'}));
}

function setServer(slot) {
    const side = document.getElementById('side-left').classList.contains('active') ? 'left' : 'right';
    callScoreApi({ action: 'server', player_slot: slot, side: side }).then(d => {
        if (!d.ok) { showToast({type:'error', message: d.message || 'Could not set server.'}); return; }
        document.getElementById('panel-1').classList.toggle('serving', slot === 1);
        document.getElementById('panel-2').classList.toggle('serving', slot === 2);
        document.getElementById('serve-badge-1').textContent = slot === 1 ? '🏓 serving' : '';
        document.getElementById('serve-badge-2').textContent = slot === 2 ? '🏓 serving' : '';
    }).catch(() => showToast({type:'error', message: 'Connection error.'}));
}

function setSide(side) {
    document.getElementById('side-left').classList.toggle('active', side === 'left');
    document.getElementById('side-right').classList.toggle('active', side === 'right');
    const servingSlot = document.getElementById('panel-1').classList.contains('serving') ? 1
                       : document.getElementById('panel-2').classList.contains('serving') ? 2 : null;
    if (servingSlot) {
        callScoreApi({ action: 'server', player_slot: servingSlot, side: side })
            .catch(() => showToast({type:'error', message: 'Connection error.'}));
    }
}

function completeMatch() {
    const s1 = parseInt(document.getElementById('score-1').textContent, 10);
    const s2 = parseInt(document.getElementById('score-2').textContent, 10);
    if (s1 === s2) { showToast({type:'warning', message: 'Scores are tied — record one more point first.'}); return; }
    if (!confirm('Complete this match with the current score? This advances the bracket.')) return;
    callScoreApi({ action: 'complete' }).then(d => {
        if (!d.ok) { showToast({type:'error', message: d.message || 'Could not complete match.'}); return; }
        showToast({type:'success', message: '🏁 Match recorded!'});
        setTimeout(() => { window.location.href = <?= json_encode(APP_URL . '/referee/dashboard.php') ?>; }, 900);
    }).catch(() => showToast({type:'error', message: 'Connection error — match NOT completed, try again.'}));
}

function openCorrection() { document.getElementById('correctionCard').style.display = 'block'; }
function closeCorrection() { document.getElementById('correctionCard').style.display = 'none'; }

function submitCorrection() {
    const s1 = parseInt(document.getElementById('correct-1').value, 10) || 0;
    const s2 = parseInt(document.getElementById('correct-2').value, 10) || 0;
    const note = document.getElementById('correct-note').value;
    callScoreApi({ action: 'correct', score_player1: s1, score_player2: s2, note: note }).then(d => {
        if (!d.ok) { showToast({type:'error', message: d.message || 'Could not save correction.'}); return; }
        const el1 = document.getElementById('score-1'); if (el1) el1.textContent = s1;
        const el2 = document.getElementById('score-2'); if (el2) el2.textContent = s2;
        showToast({type:'success', message: 'Correction saved.'});
        closeCorrection();
    }).catch(() => showToast({type:'error', message: 'Connection error — correction NOT saved.'}));
}

function toggleEvents() {
    const log = document.getElementById('eventsLog');
    log.style.display = log.style.display === 'block' ? 'none' : 'block';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
