<?php
// ============================================================
//  FILE: admin/food_orders.php
//  Live food order queue — staff and admin can advance status
//  (pending → preparing → ready → completed), cancel, or mark a
//  "pay at counter" cash order as paid.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireStaff();

$pageTitle = 'Food Order Queue';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.fq-cols { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
@media (max-width: 900px) { .fq-cols { grid-template-columns: 1fr; } }
.fq-col-head { font-family: 'Bebas Neue', sans-serif; letter-spacing: 1px; font-size: 16px; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between; }
.fq-count { background: var(--surface2); border: 1px solid var(--border); border-radius: 999px; padding: 1px 9px; font-size: 12px; color: var(--muted); }
.fq-order-card { margin-bottom: 10px; }
.fq-order-card.fq-stuck { border-color: var(--danger); animation: fq-pulse 1.6s ease-in-out infinite; }
@keyframes fq-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.35); }
    50%      { box-shadow: 0 0 0 5px rgba(239,68,68,0); }
}
.fq-order-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.fq-order-num { font-family: 'Bebas Neue', sans-serif; font-size: 20px; letter-spacing: 1px; color: var(--accent); }
.fq-order-meta { font-size: 12px; color: var(--muted); margin-top: 2px; }
.fq-age { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: var(--surface2); color: var(--muted); flex-shrink: 0; }
.fq-age.fq-age-stuck { background: rgba(239,68,68,0.12); color: var(--danger); }
.fq-items { font-size: 12.5px; margin: 8px 0; color: var(--muted); }
.fq-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
.empty-col { text-align: center; color: var(--muted); font-size: 13px; padding: 20px 10px; border: 1px dashed var(--border); border-radius: var(--radius); }
.fq-stuck-banner {
    display: none; align-items: center; gap: 10px; padding: 12px 16px; margin-bottom: 16px;
    border: 1px solid var(--danger); background: rgba(239,68,68,0.08); border-radius: var(--radius);
    color: var(--danger); font-size: 13.5px; font-weight: 600;
}
.fq-stuck-banner.show { display: flex; }
</style>

<div class="page-header flex-between">
    <div>
        <h1>🧾 Food Order Queue</h1>
        <p>Live orders — updates every 10 seconds. Customers get notified automatically when their order is ready.</p>
    </div>
    <?php if (isAdmin()): ?>
    <div><a href="<?= APP_URL ?>/admin/food_menu.php" class="btn-outline btn-sm">🍔 Manage Menu</a></div>
    <?php endif; ?>
</div>

<div class="fq-stuck-banner" id="fq-stuck-banner">
    <span>⚠️</span>
    <span id="fq-stuck-text"></span>
</div>

<div class="fq-cols">
    <div>
        <div class="fq-col-head">⏳ Pending <span class="fq-count" id="count-pending">0</span></div>
        <div id="col-pending"></div>
    </div>
    <div>
        <div class="fq-col-head">👨‍🍳 Preparing <span class="fq-count" id="count-preparing">0</span></div>
        <div id="col-preparing"></div>
    </div>
    <div>
        <div class="fq-col-head">✅ Ready <span class="fq-count" id="count-ready">0</span></div>
        <div id="col-ready"></div>
    </div>
</div>

<script nonce="<?= csrfNonce() ?>">
const STUCK_MINUTES = 8; // pending/preparing orders older than this get flagged for staff attention
const BASE_TITLE = document.title;

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

function fulfilLabel(o) {
    return o.fulfillment_type === 'court'
        ? '🏓 Deliver to ' + escapeHtml(o.court_name || 'court')
        : '🏃 Pickup at counter';
}
function payLabel(o) {
    if (o.payment_method === 'wallet') return '💳 Wallet — paid';
    return o.payment_status === 'paid' ? '💵 Cash — paid' : '💵 Cash — collect at counter';
}
function ageLabel(mins) {
    if (mins < 1) return 'just now';
    if (mins < 60) return mins + 'm ago';
    return Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm ago';
}
function isStuck(o) {
    return (o.status === 'pending' || o.status === 'preparing') && (o.age_minutes ?? 0) >= STUCK_MINUTES;
}

function actionButtons(o) {
    const btns = [];
    if (o.status === 'pending') {
        btns.push(`<button type="button" class="btn-primary btn-xs" onclick="updateStatus(${o.id},'preparing')">Start Preparing</button>`);
        btns.push(`<button type="button" class="btn-danger btn-xs" onclick="updateStatus(${o.id},'cancelled')">Cancel</button>`);
    } else if (o.status === 'preparing') {
        btns.push(`<button type="button" class="btn-primary btn-xs" onclick="updateStatus(${o.id},'ready')">Mark Ready</button>`);
        btns.push(`<button type="button" class="btn-danger btn-xs" onclick="updateStatus(${o.id},'cancelled')">Cancel</button>`);
    } else if (o.status === 'ready') {
        btns.push(`<button type="button" class="btn-success btn-xs" onclick="updateStatus(${o.id},'completed')">Mark Completed</button>`);
    }
    if (o.payment_method === 'cash' && o.payment_status !== 'paid') {
        btns.push(`<button type="button" class="btn-outline btn-xs" onclick="markPaid(${o.id})">💵 Mark Paid</button>`);
    }
    return btns.join('');
}

async function loadQueue() {
    try {
        const res = await fetch('<?= APP_URL ?>/api/food.php?orders=1&queue=1');
        const json = await res.json();
        if (!res.ok || !json.ok) return;
        const orders = json.data || [];
        const cols = { pending: [], preparing: [], ready: [] };
        orders.forEach(o => { if (cols[o.status]) cols[o.status].push(o); });

        let stuckCount = 0;

        for (const status of ['pending', 'preparing', 'ready']) {
            document.getElementById('count-' + status).textContent = cols[status].length;
            const wrap = document.getElementById('col-' + status);
            if (cols[status].length === 0) {
                wrap.innerHTML = '<div class="empty-col">No orders here right now.</div>';
                continue;
            }
            wrap.innerHTML = cols[status].map(o => {
                const stuck = isStuck(o);
                if (stuck) stuckCount++;
                const mins = o.age_minutes ?? 0;
                return `
                <div class="card fq-order-card${stuck ? ' fq-stuck' : ''}">
                    <div class="fq-order-top">
                        <div>
                            <div class="fq-order-num">${escapeHtml(o.order_number)}</div>
                            <div class="fq-order-meta">${escapeHtml(o.full_name || o.username)} · ₱${parseFloat(o.total_amount).toFixed(2)}</div>
                        </div>
                        <div class="fq-age${stuck ? ' fq-age-stuck' : ''}">${stuck ? '⚠️ ' : ''}${ageLabel(mins)}</div>
                    </div>
                    <div class="fq-order-meta">${fulfilLabel(o)}</div>
                    <div class="fq-order-meta">${payLabel(o)}</div>
                    ${o.notes ? `<div class="fq-items">📝 ${escapeHtml(o.notes)}</div>` : ''}
                    <div class="fq-actions">${actionButtons(o)}</div>
                </div>
            `;
            }).join('');
        }

        const banner = document.getElementById('fq-stuck-banner');
        if (stuckCount > 0) {
            document.getElementById('fq-stuck-text').textContent =
                stuckCount === 1
                    ? `1 order has been waiting over ${STUCK_MINUTES} minutes — check the queue.`
                    : `${stuckCount} orders have been waiting over ${STUCK_MINUTES} minutes — check the queue.`;
            banner.classList.add('show');
            document.title = `(${stuckCount}) ${BASE_TITLE}`;
        } else {
            banner.classList.remove('show');
            document.title = BASE_TITLE;
        }
    } catch (e) { /* silent — retries on next interval */ }
}

async function updateStatus(orderId, status) {
    if (status === 'cancelled' && !confirm('Cancel this order? Wallet payments will be refunded automatically.')) return;
    try {
        const res = await fetch('<?= APP_URL ?>/api/food.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_status', order_id: orderId, status }),
        });
        const json = await res.json();
        if (!res.ok || !json.ok) { alert(json.error?.message || 'Could not update the order.'); return; }
        loadQueue();
    } catch (e) { alert('Network error updating the order.'); }
}

async function markPaid(orderId) {
    try {
        const res = await fetch('<?= APP_URL ?>/api/food.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'mark_paid', order_id: orderId }),
        });
        const json = await res.json();
        if (!res.ok || !json.ok) { alert(json.error?.message || 'Could not mark the order paid.'); return; }
        loadQueue();
    } catch (e) { alert('Network error marking the order paid.'); }
}

loadQueue();
setInterval(loadQueue, 10000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
