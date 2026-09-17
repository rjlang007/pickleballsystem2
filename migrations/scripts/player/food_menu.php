<?php
// ============================================================
//  FILE: player/food_menu.php
//  Mini-restaurant menu + ordering for players.
//  Data comes from api/food.php (menu, courts, checkout).
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

$wallet  = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ?");
$wallet->execute([$uid]);
$balance = (float)($wallet->fetchColumn() ?? 0);

$menuStmt = $db->query(
    "SELECT id, name, description, price, category, image, sort_order
       FROM falcon.food_items
      WHERE is_available = TRUE
      ORDER BY category, sort_order, name"
);
$items = $menuStmt->fetchAll(PDO::FETCH_ASSOC);

$categories = [];
foreach ($items as $it) {
    $categories[$it['category']] ??= [];
    $categories[$it['category']][] = $it;
}

$courts = $db->query(
    "SELECT id, name, short_code FROM falcon.courts WHERE is_active = TRUE ORDER BY sort_order, id"
)->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Food Menu';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.food-layout {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 24px;
    align-items: start;
}
@media (max-width: 900px) {
    .food-layout { grid-template-columns: 1fr; }
}
.food-cat-title {
    font-family: 'Bebas Neue', sans-serif;
    letter-spacing: 1px;
    font-size: 20px;
    margin: 24px 0 12px;
    color: var(--accent);
}
.food-cat-title:first-child { margin-top: 0; }
.food-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 14px;
}
.food-item-card {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.food-item-img {
    width: 100%;
    height: 120px;
    border-radius: 10px;
    object-fit: cover;
    background: var(--surface2);
}
.food-item-noimg {
    width: 100%;
    height: 120px;
    border-radius: 10px;
    background: var(--surface2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    opacity: 0.5;
}
.food-item-name { font-weight: 700; font-size: 15px; }
.food-item-desc { color: var(--muted); font-size: 12.5px; line-height: 1.4; min-height: 32px; }
.food-item-foot { display: flex; align-items: center; justify-content: space-between; margin-top: auto; }
.food-item-price { font-weight: 700; color: var(--accent); font-size: 15px; }

.cart-panel { position: sticky; top: 84px; }
.cart-empty { text-align: center; color: var(--muted); padding: 24px 12px; font-size: 13.5px; }
.cart-line { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 0; border-bottom: 1px solid var(--border); }
.cart-line:last-child { border-bottom: none; }
.cart-line-name { font-size: 13.5px; font-weight: 600; }
.cart-line-price { font-size: 12px; color: var(--muted); }
.qty-controls { display: flex; align-items: center; gap: 6px; }
.qty-btn {
    width: 24px; height: 24px; border-radius: 6px; border: 1px solid var(--border);
    background: var(--surface2); color: var(--text); cursor: pointer; font-weight: 700;
    display: flex; align-items: center; justify-content: center; touch-action: manipulation;
}
.qty-btn:hover { border-color: var(--accent); color: var(--accent); }
.qty-val { min-width: 18px; text-align: center; font-size: 13px; font-weight: 700; }

.fulfil-tabs, .pay-tabs { display: flex; gap: 6px; margin: 10px 0; }
.fulfil-tab, .pay-tab {
    flex: 1; padding: 8px 6px; text-align: center; border-radius: 8px; border: 1px solid var(--border);
    background: var(--surface2); color: var(--muted); font-size: 12.5px; font-weight: 700; cursor: pointer;
}
.fulfil-tab.active, .pay-tab.active { border-color: var(--accent); background: rgba(0,229,160,0.1); color: var(--accent); }

.cart-total-row { display: flex; align-items: center; justify-content: space-between; font-size: 15px; font-weight: 700; margin: 12px 0; }
.wallet-hint { font-size: 12px; color: var(--muted); margin-top: 4px; }
.wallet-hint.low { color: var(--danger); }

.order-confirm-banner {
    text-align: center; padding: 18px 12px; border: 1px solid var(--accent);
    background: rgba(0,229,160,0.08); border-radius: var(--radius); margin-bottom: 20px;
}
.order-confirm-num {
    font-family: 'Bebas Neue', sans-serif; font-size: 34px; letter-spacing: 2px; color: var(--accent);
}
</style>

<div class="page-header flex-between">
    <div>
        <h1>🍔 Food &amp; Drinks</h1>
        <p>Order while you play — pickup at the counter, or we'll bring it to your court.</p>
    </div>
    <div>
        <a href="<?= APP_URL ?>/player/food_orders.php" class="btn-outline btn-sm">🧾 My Orders</a>
        <a href="<?= APP_URL ?>/player/topup.php" class="btn-outline btn-sm">+ Load Credits</a>
    </div>
</div>

<div id="order-confirm" class="order-confirm-banner" style="display:none">
    <div style="font-size:13px;color:var(--muted);margin-bottom:4px;">Order placed! Your order number is</div>
    <div class="order-confirm-num" id="order-confirm-num">—</div>
    <div style="font-size:13px;color:var(--muted);margin-top:6px;">We'll notify you the moment it's ready.</div>
</div>

<div class="food-layout">
    <div>
        <?php if (empty($items)): ?>
            <div class="card"><p class="card-subtitle">The menu isn't set up yet — check back soon!</p></div>
        <?php else: ?>
            <?php foreach ($categories as $cat => $catItems): ?>
                <div class="food-cat-title"><?= clean($cat) ?></div>
                <div class="food-grid">
                    <?php foreach ($catItems as $it): ?>
                        <div class="card food-item-card">
                            <?php if (!empty($it['image'])): ?>
                                <img class="food-item-img" src="<?= APP_URL ?>/Uploads/food/<?= urlencode($it['image']) ?>" alt="<?= clean($it['name']) ?>" onerror="this.style.display='none'">
                            <?php else: ?>
                                <div class="food-item-noimg">🍽️</div>
                            <?php endif; ?>
                            <div class="food-item-name"><?= clean($it['name']) ?></div>
                            <?php if (!empty($it['description'])): ?>
                                <div class="food-item-desc"><?= clean($it['description']) ?></div>
                            <?php endif; ?>
                            <div class="food-item-foot">
                                <span class="food-item-price">₱<?= number_format((float)$it['price'], 2) ?></span>
                                <button type="button" class="btn-primary btn-xs add-to-cart-btn"
                                        data-id="<?= (int)$it['id'] ?>"
                                        data-name="<?= clean($it['name']) ?>"
                                        data-price="<?= (float)$it['price'] ?>">＋ Add</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="cart-panel card">
        <h3 class="card-title">🛒 Your Order</h3>
        <div id="cart-lines"><div class="cart-empty">Your cart is empty — add something tasty!</div></div>

        <div id="cart-checkout-block" style="display:none">
            <div class="cart-total-row"><span>Total</span><span id="cart-total">₱0.00</span></div>

            <label style="font-size:12.5px;font-weight:700;color:var(--muted);">Fulfillment</label>
            <div class="fulfil-tabs">
                <div class="fulfil-tab active" data-fulfil="pickup" onclick="setFulfil('pickup')">🏃 Pickup</div>
                <div class="fulfil-tab" data-fulfil="court" onclick="setFulfil('court')">🏓 Deliver to Court</div>
            </div>
            <div id="court-picker" style="display:none;margin-bottom:8px;">
                <select id="court-select">
                    <option value="">Select your court…</option>
                    <?php foreach ($courts as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= clean($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label style="font-size:12.5px;font-weight:700;color:var(--muted);">Payment</label>
            <div class="pay-tabs">
                <div class="pay-tab active" data-pay="wallet" onclick="setPay('wallet')">💳 Wallet</div>
                <div class="pay-tab" data-pay="cash" onclick="setPay('cash')">💵 Cash at Counter</div>
            </div>
            <div class="wallet-hint" id="wallet-hint">Wallet balance: ₱<?= number_format($balance, 2) ?></div>

            <textarea id="order-notes" placeholder="Notes (e.g. no ice, allergies)…" maxlength="255" style="margin-top:10px;resize:vertical;min-height:50px;"></textarea>

            <button class="btn-primary btn-block" style="margin-top:12px;" onclick="placeOrder()" id="place-order-btn">Place Order</button>
        </div>
    </div>
</div>

<script nonce="<?= csrfNonce() ?>">
const WALLET_BALANCE = <?= json_encode($balance) ?>;
let cart = {}; // id -> {name, price, qty}
let fulfillmentType = 'pickup';
let paymentMethod = 'wallet';

function addToCart(id, name, price) {
    if (!cart[id]) cart[id] = { name, price, qty: 0 };
    cart[id].qty += 1;
    renderCart();
}
document.querySelectorAll('.add-to-cart-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        addToCart(parseInt(btn.dataset.id, 10), btn.dataset.name, parseFloat(btn.dataset.price));
    });
});
function changeQty(id, delta) {
    if (!cart[id]) return;
    cart[id].qty += delta;
    if (cart[id].qty <= 0) delete cart[id];
    renderCart();
}
function renderCart() {
    const wrap = document.getElementById('cart-lines');
    const ids = Object.keys(cart);
    if (ids.length === 0) {
        wrap.innerHTML = '<div class="cart-empty">Your cart is empty — add something tasty!</div>';
        document.getElementById('cart-checkout-block').style.display = 'none';
        return;
    }
    let total = 0;
    wrap.innerHTML = ids.map(id => {
        const c = cart[id];
        const sub = c.price * c.qty;
        total += sub;
        return `<div class="cart-line">
            <div>
                <div class="cart-line-name">${escapeHtml(c.name)}</div>
                <div class="cart-line-price">₱${c.price.toFixed(2)} each</div>
            </div>
            <div class="qty-controls">
                <button type="button" class="qty-btn" onclick="changeQty(${id},-1)">−</button>
                <span class="qty-val">${c.qty}</span>
                <button type="button" class="qty-btn" onclick="changeQty(${id},1)">＋</button>
            </div>
        </div>`;
    }).join('');
    document.getElementById('cart-checkout-block').style.display = 'block';
    document.getElementById('cart-total').textContent = '₱' + total.toFixed(2);
    updateWalletHint(total);
}
function updateWalletHint(total) {
    const hint = document.getElementById('wallet-hint');
    if (paymentMethod !== 'wallet') {
        hint.textContent = "You'll pay ₱" + total.toFixed(2) + ' in cash when you pick up / receive your order.';
        hint.classList.remove('low');
        return;
    }
    const short = total > WALLET_BALANCE;
    hint.textContent = 'Wallet balance: ₱' + WALLET_BALANCE.toFixed(2) + (short ? ' — not enough credits, top up or pay cash instead.' : '');
    hint.classList.toggle('low', short);
}
function setFulfil(type) {
    fulfillmentType = type;
    document.querySelectorAll('.fulfil-tab').forEach(el => el.classList.toggle('active', el.dataset.fulfil === type));
    document.getElementById('court-picker').style.display = type === 'court' ? 'block' : 'none';
}
function setPay(method) {
    paymentMethod = method;
    document.querySelectorAll('.pay-tab').forEach(el => el.classList.toggle('active', el.dataset.pay === method));
    const total = Object.values(cart).reduce((s, c) => s + c.price * c.qty, 0);
    updateWalletHint(total);
}
function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

async function placeOrder() {
    const ids = Object.keys(cart);
    if (ids.length === 0) return;
    if (fulfillmentType === 'court' && !document.getElementById('court-select').value) {
        alert('Please select which court to deliver to.');
        return;
    }
    const btn = document.getElementById('place-order-btn');
    btn.disabled = true;
    btn.textContent = 'Placing order…';

    const payload = {
        action: 'checkout',
        items: ids.map(id => ({ id: parseInt(id, 10), quantity: cart[id].qty })),
        payment_method: paymentMethod,
        fulfillment_type: fulfillmentType,
        court_id: fulfillmentType === 'court' ? parseInt(document.getElementById('court-select').value, 10) : null,
        notes: document.getElementById('order-notes').value,
    };

    try {
        const res = await fetch('<?= APP_URL ?>/api/food.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok || !json.ok) {
            alert(json.error?.message || 'Could not place your order. Please try again.');
            btn.disabled = false;
            btn.textContent = 'Place Order';
            return;
        }
        document.getElementById('order-confirm-num').textContent = json.data.order_number;
        document.getElementById('order-confirm').style.display = 'block';
        document.getElementById('order-confirm').scrollIntoView({ behavior: 'smooth', block: 'start' });
        cart = {};
        renderCart();
        btn.disabled = false;
        btn.textContent = 'Place Order';
    } catch (e) {
        alert('Network error placing your order. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Place Order';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
