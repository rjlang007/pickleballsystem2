<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../tournament/open_play_engine.php';
requireStaff();
$engine = new OpenPlayEngine();
$events = $engine->listEvents();
$selected = (int)($_GET['tournament_id'] ?? 0);
if (!$selected) foreach ($events as $item) { if (in_array($item['status'], ['registration_open','in_progress','paused'], true)) { $selected = (int)$item['id']; break; } }
$event = $selected ? $engine->getEvent($selected) : null;
$latest = $event ? $engine->getLatestRaffleDraw($selected) : null;
$pageTitle = 'Open Play Raffles';
require_once __DIR__ . '/../includes/header.php';
$moduleTitle = 'Raffles';
$moduleDescription = 'Spin the wheel for a prize draw among every player registered or approved for this Open Play event.';
$moduleEventName = $event['name'] ?? '';
require __DIR__ . '/open_play_module_header.php';
?>
<div class="op-module-panel"><form method="GET" class="opc-add"><label class="opc-help" for="raffleEvent">Event</label><select id="raffleEvent" name="tournament_id" class="opc-field" data-autosubmit><option value="">Select an event</option><?php foreach ($events as $item): ?><option value="<?= (int)$item['id'] ?>" <?= $selected === (int)$item['id'] ? 'selected' : '' ?>><?= clean($item['name']) ?> · <?= clean(str_replace('_',' ',$item['status'])) ?></option><?php endforeach; ?></select></form></div>
<?php if (!$event): ?><div class="op-module-panel"><p class="opc-help">Select an Open Play event to manage raffles.</p></div><?php elseif (in_array($event['status'], ['completed','cancelled'], true)): ?><div class="op-module-panel"><p class="opc-help">This event is closed and no new raffle can be drawn.</p></div><?php else: ?>
<div class="op-module-panel">
    <h2 class="opc-h3">Prize Draw</h2>
    <p class="opc-help">Everyone registered or approved for this event is on the wheel. Raffles don't change the queue or leaderboard.</p>

    <div class="raffle-wheel-wrap">
        <div class="raffle-wheel-stage">
            <div class="raffle-wheel-pointer" aria-hidden="true"></div>
            <canvas id="raffleWheel" width="440" height="440"></canvas>
            <div class="raffle-wheel-hub" aria-hidden="true"></div>
        </div>
        <p class="opc-help" id="raffleCount">Loading players…</p>
    </div>

    <form id="raffleForm" class="opc-add">
        <input type="text" id="rafflePrize" class="opc-field" maxlength="160" placeholder="Prize description, e.g. Dinner for two" required/>
        <button type="submit" class="opc-btn-action" id="raffleSpinBtn">Spin the wheel</button>
    </form>
    <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);margin:-6px 0 16px;cursor:pointer;">
        <input type="checkbox" id="raffleExcludeWinners" style="width:auto;"/> Exclude players who already won a prize tonight
    </label>
    <div id="raffleResult" class="opc-help" <?= $latest ? '' : 'hidden' ?>><?php if ($latest): ?>Last winner: <strong><?= clean($latest['winner_name']) ?></strong> · <?= clean($latest['prize_description']) ?><?php endif; ?></div>

    <div class="opc-section-head" style="margin-top:24px;">
        <h3 class="opc-h3" style="font-size:16px;">Past Winners</h3>
        <span class="op-module-meta" id="raffleHistoryCount"></span>
    </div>
    <div class="opc-table-wrap"><table class="opc-table"><thead><tr><th>Winner</th><th>Prize</th><th>Time</th></tr></thead><tbody id="raffleHistoryBody">
        <tr><td colspan="3">No prizes drawn yet this event.</td></tr>
    </tbody></table></div>
</div>
<?php endif; ?>
<style nonce="<?= getCspNonce() ?>">
.raffle-wheel-wrap { display:flex; flex-direction:column; align-items:center; gap:10px; margin:8px 0 20px; }
.raffle-wheel-stage { position:relative; width:min(440px, 90vw); aspect-ratio:1/1; }
.raffle-wheel-stage canvas { width:100%; height:100%; display:block; border-radius:50%; box-shadow:var(--shadow-lg, 0 16px 48px rgba(0,0,0,0.45)); transform:rotate(0deg); }
.raffle-wheel-pointer { position:absolute; top:-6px; left:50%; transform:translateX(-50%); width:0; height:0; border-left:14px solid transparent; border-right:14px solid transparent; border-top:22px solid var(--text, #e9eef7); z-index:3; filter:drop-shadow(0 2px 3px rgba(0,0,0,.5)); }
.raffle-wheel-hub { position:absolute; top:50%; left:50%; width:18%; height:18%; transform:translate(-50%,-50%); border-radius:50%; background:var(--surface, #111827); border:3px solid var(--border, #1e2d45); z-index:2; }
.raffle-wheel-stage.is-spinning canvas { transition: transform 4.6s cubic-bezier(0.15, 0.65, 0.15, 1); }
#raffleSpinBtn:disabled { opacity:.6; cursor:not-allowed; }
#raffleResult strong { color:var(--accent, #00e5a0); }
</style>
<script nonce="<?= getCspNonce() ?>">
(function(){
const TID = <?= (int)$selected ?>;
const stage = document.querySelector('.raffle-wheel-stage');
const canvas = document.getElementById('raffleWheel');
const countEl = document.getElementById('raffleCount');
const form = document.getElementById('raffleForm');
const spinBtn = document.getElementById('raffleSpinBtn');
const prizeInput = document.getElementById('rafflePrize');
const resultEl = document.getElementById('raffleResult');
const excludeToggle = document.getElementById('raffleExcludeWinners');
const historyBody = document.getElementById('raffleHistoryBody');
const historyCount = document.getElementById('raffleHistoryCount');
if (!canvas) return;
const ctx = canvas.getContext('2d');
const PALETTE = ['#00e5a0','#00aaff','#f97316','#a78bfa','#f43f5e','#facc15','#34d399','#60a5fa','#fb7185','#c084fc'];
let participants = [];
let rotation = 0;
const escHtml = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function drawWheel(list) {
    const size = canvas.width, r = size / 2;
    ctx.clearRect(0, 0, size, size);
    const n = list.length;
    if (!n) {
        ctx.fillStyle = '#1a2332';
        ctx.beginPath(); ctx.arc(r, r, r - 2, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = '#8695ad';
        ctx.font = '600 15px system-ui, sans-serif';
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText('No players yet', r, r);
        return;
    }
    const slice = (Math.PI * 2) / n;
    const fontSize = Math.max(9, Math.min(15, 220 / n));
    const maxChars = n > 30 ? 10 : (n > 16 ? 14 : 20);
    list.forEach((p, i) => {
        const start = -Math.PI / 2 + i * slice;
        const end = start + slice;
        ctx.beginPath();
        ctx.moveTo(r, r);
        ctx.arc(r, r, r - 2, start, end);
        ctx.closePath();
        ctx.fillStyle = PALETTE[i % PALETTE.length];
        ctx.fill();
        ctx.strokeStyle = 'rgba(9,13,24,0.35)';
        ctx.lineWidth = 1.5;
        ctx.stroke();

        ctx.save();
        ctx.translate(r, r);
        ctx.rotate(start + slice / 2);
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#0b1220';
        ctx.font = '700 ' + fontSize + 'px system-ui, sans-serif';
        let name = String(p.name || 'Player');
        if (name.length > maxChars) name = name.slice(0, maxChars - 1) + '…';
        ctx.fillText(name, r - 14, 0);
        ctx.restore();
    });
}

function setCount(n) {
    countEl.textContent = n === 0
        ? 'No registered or approved players yet — approve or add players first.'
        : n + ' player' + (n === 1 ? '' : 's') + ' on the wheel';
    spinBtn.disabled = n === 0;
}

async function loadParticipants() {
    try {
        const res = await fetch('<?= APP_URL ?>/api/open_play.php?action=raffle_data&tournament_id=' + TID, { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Could not load players.');
        participants = data.data.participants || [];
        drawWheel(participants);
        setCount(participants.length);
    } catch (e) {
        countEl.textContent = 'Could not load players.';
        spinBtn.disabled = true;
    }
}

function renderHistory(rows) {
    if (!historyBody) return;
    historyCount.textContent = rows.length ? `${rows.length} drawn` : '';
    if (!rows.length) {
        historyBody.innerHTML = '<tr><td colspan="3">No prizes drawn yet this event.</td></tr>';
        return;
    }
    historyBody.innerHTML = rows.map(r => {
        const when = r.created_at ? new Date(r.created_at.replace(' ', 'T') + 'Z').toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
        return `<tr><td>${escHtml(r.winner_name)}</td><td>${escHtml(r.prize_description)}</td><td>${escHtml(when)}</td></tr>`;
    }).join('');
}

async function loadHistory() {
    try {
        const res = await fetch('<?= APP_URL ?>/api/open_play.php?action=raffle_history&tournament_id=' + TID, { credentials: 'same-origin', cache: 'no-store' });
        const data = await res.json();
        if (data.success) renderHistory(data.data || []);
    } catch (_) { /* history is a nice-to-have, fail quietly */ }
}

function spinToWinner(list, winnerId, winnerName) {
    const n = list.length;
    let idx = list.findIndex(p => String(p.id) === String(winnerId));
    if (idx === -1) idx = list.findIndex(p => p.name === winnerName);
    if (idx === -1) idx = 0;
    const slice = 360 / n;
    const centerFromTop = idx * slice + slice / 2;
    const jitter = (Math.random() - 0.5) * slice * 0.7;
    const extraSpins = 6 + Math.floor(Math.random() * 3);
    const targetMod = (360 - (centerFromTop + jitter) + 360) % 360;
    const base = rotation - (rotation % 360);
    let next = base + extraSpins * 360 + targetMod;
    if (next <= rotation) next += 360;
    rotation = next;
    stage.classList.add('is-spinning');
    void canvas.offsetHeight; // force reflow so the transition below is guaranteed to run
    canvas.style.transform = 'rotate(' + rotation + 'deg)';
}

form?.addEventListener('submit', async event => {
    event.preventDefault();
    const prize = prizeInput.value.trim();
    if (!prize) return;
    if (!participants.length) { alert('There are no registered or approved players to enter in the raffle.'); return; }
    spinBtn.disabled = true;
    prizeInput.disabled = true;
    try {
        const response = await fetch('<?= APP_URL ?>/api/open_play.php?action=raffle_spin', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tournament_id: TID, prize_description: prize, exclude_previous_winners: !!(excludeToggle && excludeToggle.checked) })
        });
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Raffle failed.');
        const draw = data.data.draw;
        const pool = data.data.participants && data.data.participants.length ? data.data.participants : participants;
        participants = pool;
        drawWheel(participants);
        spinToWinner(participants, draw.winner_player_id, draw.winner_name);
        const onDone = () => {
            canvas.removeEventListener('transitionend', onDone);
            stage.classList.remove('is-spinning');
            resultEl.hidden = false;
            resultEl.innerHTML = '🏆 Winner: <strong>' + escHtml(draw.winner_name) + '</strong> · ' + escHtml(draw.prize_description);
            spinBtn.disabled = false;
            prizeInput.disabled = false;
            form.reset();
            loadHistory();
        };
        canvas.addEventListener('transitionend', onDone, { once: true });
        setTimeout(() => { if (stage.classList.contains('is-spinning')) onDone(); }, 5200);
    } catch (error) {
        alert(error.message);
        spinBtn.disabled = false;
        prizeInput.disabled = false;
    }
});

loadParticipants();
loadHistory();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
