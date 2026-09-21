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
$moduleDescription = 'Run a prize draw for players currently seated in an active Open Play game.';
require __DIR__ . '/open_play_module_header.php';
?>
<div class="op-module-panel"><form method="GET" class="opc-add"><label class="opc-help" for="raffleEvent">Event</label><select id="raffleEvent" name="tournament_id" class="opc-field" data-autosubmit><option value="">Select an event</option><?php foreach ($events as $item): ?><option value="<?= (int)$item['id'] ?>" <?= $selected === (int)$item['id'] ? 'selected' : '' ?>><?= clean($item['name']) ?> · <?= clean(str_replace('_',' ',$item['status'])) ?></option><?php endforeach; ?></select></form></div>
<?php if (!$event): ?><div class="op-module-panel"><p class="opc-help">Select an Open Play event to manage raffles.</p></div><?php elseif (in_array($event['status'], ['completed','cancelled'], true)): ?><div class="op-module-panel"><p class="opc-help">This event is closed and no new raffle can be drawn.</p></div><?php else: ?>
<div class="op-module-panel"><h2 class="opc-h3">Prize Draw</h2><p class="opc-help">Only players currently assigned to an active game are eligible. Raffles do not change the queue or leaderboard.</p><form id="raffleForm" class="opc-add"><input type="text" id="rafflePrize" class="opc-field" maxlength="160" placeholder="Prize description, e.g. Dinner for two" required/><button type="submit" class="opc-btn-action">Spin raffle</button></form><div id="raffleResult" class="opc-help" <?= $latest ? '' : 'hidden' ?>><?php if ($latest): ?>Last winner: <strong><?= clean($latest['winner_name']) ?></strong> · <?= clean($latest['prize_description']) ?><?php endif; ?></div></div>
<?php endif; ?>
<script nonce="<?= getCspNonce() ?>">
const form=document.getElementById('raffleForm'); form?.addEventListener('submit',async event=>{event.preventDefault();const button=form.querySelector('button'), prize=document.getElementById('rafflePrize').value.trim();if(!prize)return;button.disabled=true;try{const response=await fetch('<?= APP_URL ?>/api/open_play.php?action=raffle_spin',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({tournament_id:<?= (int)$selected ?>,prize_description:prize})});const data=await response.json();if(!data.success)throw new Error(data.message||'Raffle failed.');const draw=data.data.draw;const result=document.getElementById('raffleResult');result.hidden=false;result.innerHTML='Winner: <strong>'+String(draw.winner_name).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))+'</strong> · '+String(draw.prize_description).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));form.reset();}catch(error){alert(error.message);}finally{button.disabled=false;}});
</script>
</div><?php require_once __DIR__ . '/../includes/footer.php'; ?>
