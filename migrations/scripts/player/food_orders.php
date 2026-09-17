<?php
// ============================================================
//  FILE: player/food_orders.php
//  Player's food order history + live status tracking.
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

$pageTitle = 'My Food Orders';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.order-card { margin-bottom: 14px; }
.order-card-top { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.order-num { font-family: 'Bebas Neue', sans-serif; font-size: 22px; letter-spacing: 1px; color: var(--accent); }
.order-meta { color: var(--muted); font-size: 12.5px; margin-top: 2px; }
.order-status-track {
    display: flex; align-items: center; gap: 4px; margin: 12px 0 8px;
}
.ost-step {
    flex: 1; text-align: center; font-size: 11px; font-weight: 700; color: var(--muted);
    padding: 6px 4px; border-radius: 6px; background: var(--surface2); border: 1px solid var(--border);
}
.ost-step.done { color: var(--accent); border-color: var(--accent); background: rgba(0,229,160,0.1); }
.ost-step.cancelled { color: var(--danger); border-color: var(--danger); background: rgba(239,68,68,0.08); }
.order-items-list { font-size: 13px; color: var(--muted); margin-top: 8px; }
.order-items-list div { display: flex; justify-content: space-between; padding: 2px 0; }
.empty-state { text-align: center; padding: 40px 20px; color: var(--muted); }
</style>

<div class="page-header flex-between">
    <div>
        <h1>🧾 My Food Orders</h1>
        <p>Track your order number and status here — you'll also get a notification when it's ready.</p>
    </div>
    <div>
        <a href="<?= APP_URL ?>/player/food_menu.php" class="btn-primary btn-sm">🍔 Order Food</a>
    </div>
</div>

<div id="orders-wrap">
    <div class="empty-state">Loading your orders…</div>
</div>

<script nonce="<?= csrfNonce() ?>">
const STATUS_STEPS = ['pending', 'preparing', 'ready', 'completed'];
const STATUS_LABELS = { pending: 'Placed', preparing: 'Preparing', ready: 'Ready', completed: 'Completed', cancelled: 'Cancelled' };

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

function renderTrack(status) {
    if (status === 'cancelled') {
        return '<div class="order-status-track"><div class="ost-step cancelled">✕ Cancelled</div></div>';
    }
    const idx = STATUS_STEPS.indexOf(status);
    return '<div class="order-status-track">' + STATUS_STEPS.map((s, i) =>
        `<div class="ost-step ${i <= idx ? 'done' : ''}">${STATUS_LABELS[s]}</div>`
    ).join('') + '</div>';
}

function fulfilLabel(order) {
    if (order.fulfillment_type === 'court') {
        return '🏓 Deliver to ' + escapeHtml(order.court_name || 'court');
    }
    return '🏃 Pickup at counter';
}

function payLabel(order) {
    if (order.payment_method === 'wallet') {
        return order.payment_status === 'refunded' ? '💳 Wallet (refunded)' : '💳 Paid by wallet';
    }
    return order.payment_status === 'paid' ? '💵 Paid in cash' : '💵 Pay in cash at counter';
}

async function loadOrders() {
    try {
        const res = await fetch('<?= APP_URL ?>/api/food.php?orders=1');
        const json = await res.json();
        const wrap = document.getElementById('orders-wrap');
        if (!res.ok || !json.ok) {
            wrap.innerHTML = '<div class="empty-state">Could not load your orders.</div>';
            return;
        }
        const orders = json.data || [];
        if (orders.length === 0) {
            wrap.innerHTML = '<div class="empty-state">No orders yet. <a href="<?= APP_URL ?>/player/food_menu.php">Browse the menu →</a></div>';
            return;
        }
        wrap.innerHTML = orders.map(o => `
            <div class="card order-card">
                <div class="order-card-top">
                    <div>
                        <div class="order-num">${escapeHtml(o.order_number)}</div>
                        <div class="order-meta">${new Date(o.created_at).toLocaleString()} · ₱${parseFloat(o.total_amount).toFixed(2)}</div>
                    </div>
                    <div class="order-meta" style="text-align:right">
                        <div>${fulfilLabel(o)}</div>
                        <div>${payLabel(o)}</div>
                    </div>
                </div>
                ${renderTrack(o.status)}
                ${o.notes ? `<div class="order-meta">📝 ${escapeHtml(o.notes)}</div>` : ''}
            </div>
        `).join('');
    } catch (e) {
        document.getElementById('orders-wrap').innerHTML = '<div class="empty-state">Network error loading your orders.</div>';
    }
}

loadOrders();
setInterval(loadOrders, 15000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
