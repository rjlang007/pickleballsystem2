<?php
// ============================================================
//  FILE: admin/food_menu.php
//  Admin-only: manage the food/drinks menu (name, price, category,
//  availability, photo). Order fulfillment lives in food_orders.php.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db = getDB();
$items = $db->query(
    "SELECT fi.id, fi.name, fi.description, fi.price, fi.image, fi.is_available, fi.sort_order,
            fi.category_id, COALESCE(fc.name, fi.category, 'Main') AS category,
            COALESCE(fc.sort_order, 999) AS category_sort_order
       FROM falcon.food_items fi
  LEFT JOIN falcon.food_categories fc ON fc.id = fi.category_id
      ORDER BY category_sort_order, category, fi.sort_order, fi.name"
)->fetchAll(PDO::FETCH_ASSOC);

$categories = $db->query(
    "SELECT id, name, sort_order FROM falcon.food_categories ORDER BY sort_order, name"
)->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Food Menu Manager';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.fm-toolbar { display: flex; justify-content: flex-end; margin-bottom: 16px; }
.fm-toolbar-spread { justify-content: space-between; }
.fm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
.fm-card { display: flex; flex-direction: column; gap: 8px; }
.fm-img { width: 100%; height: 110px; object-fit: cover; border-radius: 10px; background: var(--surface2); }
.fm-noimg { width: 100%; height: 110px; border-radius: 10px; background: var(--surface2); display: flex; align-items: center; justify-content: center; font-size: 32px; opacity: 0.5; }
.fm-name { font-weight: 700; font-size: 14.5px; }
.fm-cat { color: var(--muted); font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.5px; }
.fm-price { color: var(--accent); font-weight: 700; }
.fm-actions { display: flex; gap: 6px; margin-top: auto; }
.fm-actions .fm-edit-btn { flex: 1; }
.fm-img.fm-image-error { display: none; }
.fm-file-input { display: none; }
.fm-available-label { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.fm-category-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
.fm-category-form { border-top: 1px solid var(--border); padding-top: 14px; }
.fm-category-inputs { display: flex; gap: 8px; }
.fm-category-inputs .cat-new-name { flex: 1; }
.fm-category-row { display: flex; align-items: center; gap: 8px; }
.fm-category-row .cat-name-input { flex: 1; }
.fm-category-row .cat-sort-input { width: 64px; }
.fm-empty-category { margin: 0; }

/* Modal (self-contained, no dependency on content_manager.php styles) */
.fmbg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; padding: 16px; }
.fmbg.open { display: flex; }
.fmbox { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); max-width: 460px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 20px; }
.fmhead { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
.fmclose { background: none; border: none; color: var(--muted); font-size: 18px; cursor: pointer; }
.fm-fg { margin-bottom: 12px; }
.fm-fg label { display: block; font-size: 12.5px; font-weight: 700; color: var(--muted); margin-bottom: 4px; }
.fm-g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.fm-dropzone { border: 2px dashed var(--border); border-radius: 10px; padding: 18px 10px; text-align: center; cursor: pointer; font-size: 12.5px; color: var(--muted); }
.fm-dropzone:hover { border-color: var(--accent); }
.fm-imgprev img { max-width: 100px; max-height: 100px; border-radius: 8px; margin-top: 8px; }
.fm-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
</style>

<div class="page-header flex-between">
    <div>
        <h1>🍔 Food Menu</h1>
        <p>Set what's on offer and its price. Players order it from the Food page.</p>
    </div>
    <div>
        <a href="<?= APP_URL ?>/admin/food_orders.php" class="btn-outline btn-sm">🧾 Order Queue</a>
    </div>
</div>

<div class="fm-toolbar fm-toolbar-spread">
    <button type="button" class="btn-outline btn-sm" data-action="open-categories">🏷️ Manage Categories</button>
    <button type="button" class="btn-primary btn-sm" data-action="open-item">＋ Add Menu Item</button>
</div>

<?php if (empty($items)): ?>
    <div class="card"><p class="card-subtitle">No menu items yet — add your first one above.</p></div>
<?php else: ?>
    <div class="fm-grid" id="fm-grid">
        <?php foreach ($items as $it): ?>
            <div class="card fm-card" data-item='<?= json_encode($it, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                <?php if (!empty($it['image'])): ?>
                    <img class="fm-img" src="<?= APP_URL ?>/Uploads/food/<?= urlencode($it['image']) ?>" alt="<?= clean($it['name']) ?>">
                <?php else: ?>
                    <div class="fm-noimg">🍽️</div>
                <?php endif; ?>
                <div class="fm-cat"><?= clean($it['category']) ?></div>
                <div class="fm-name"><?= clean($it['name']) ?></div>
                <div class="fm-price">₱<?= number_format((float)$it['price'], 2) ?></div>
                <span class="badge <?= $it['is_available'] ? 'badge-success' : 'badge-muted' ?>">
                    <?= $it['is_available'] ? 'Available' : 'Hidden' ?>
                </span>
                <div class="fm-actions">
                    <button type="button" class="btn-outline btn-xs fm-edit-btn" data-action="edit-item">✏️ Edit</button>
                    <button type="button" class="btn-danger btn-xs" data-action="delete-item" data-item-id="<?= (int)$it['id'] ?>" data-item-name="<?= htmlspecialchars($it['name'], ENT_QUOTES, 'UTF-8') ?>">🗑</button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Item Modal -->
<div class="fmbg" id="fm-modal">
    <div class="fmbox">
        <div class="fmhead"><h3 id="fm-modal-title">Add Menu Item</h3><button type="button" class="fmclose" data-action="close-item">✕</button></div>
        <input type="hidden" id="fm-id" value="0">
        <input type="hidden" id="fm-existing-image" value="">

        <div class="fm-fg"><label>Item Name *</label><input type="text" id="fm-name" placeholder="Chicken Sandwich"></div>
        <div class="fm-fg"><label>Description</label><textarea id="fm-desc" rows="2" placeholder="Short description shown to players"></textarea></div>
        <div class="fm-g2">
            <div class="fm-fg">
                <label>Category *</label>
                <select id="fm-cat">
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= clean($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fm-fg"><label>Price (₱) *</label><input type="number" id="fm-price" min="0" step="0.01"></div>
        </div>
        <div class="fm-fg"><label>Sort Order</label><input type="number" id="fm-sort" min="0" value="0"></div>
        <div class="fm-fg">
            <label>Photo</label>
            <div class="fm-dropzone" data-action="choose-image">
                📷 Click to choose a photo (JPG, PNG, WEBP, GIF — max 5MB)
            </div>
            <input type="file" id="fm-img-input" class="fm-file-input" accept="image/jpeg,image/png,image/webp,image/gif">
            <div class="fm-imgprev" id="fm-imgprev"></div>
        </div>
        <label class="fm-available-label">
            <input type="checkbox" id="fm-available" checked> Available to order
        </label>

        <div class="fm-foot">
            <button type="button" class="btn-outline btn-sm" data-action="close-item">Cancel</button>
            <button type="button" class="btn-primary btn-sm" id="fm-save-btn" data-action="save-item">💾 Save Item</button>
        </div>
    </div>
</div>

<!-- Manage Categories Modal -->
<div class="fmbg" id="cat-modal">
    <div class="fmbox">
        <div class="fmhead"><h3>🏷️ Manage Categories</h3><button type="button" class="fmclose" data-action="close-categories">✕</button></div>

        <div id="cat-list" class="fm-category-list"></div>

        <div class="fm-fg fm-category-form">
            <label>Add a Category</label>
            <div class="fm-category-inputs">
                <input type="text" id="cat-new-name" class="cat-new-name" placeholder="e.g. Desserts">
                <button type="button" class="btn-primary btn-sm" data-action="add-category">＋ Add</button>
            </div>
        </div>

        <div class="fm-foot">
            <button type="button" class="btn-outline btn-sm" data-action="close-categories">Close</button>
        </div>
    </div>
</div>

<script nonce="<?= csrfNonce() ?>">
const FOOD_CATEGORIES = <?= json_encode($categories, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function openItemModal() {
    document.getElementById('fm-modal-title').textContent = 'Add Menu Item';
    document.getElementById('fm-id').value = '0';
    document.getElementById('fm-existing-image').value = '';
    document.getElementById('fm-name').value = '';
    document.getElementById('fm-desc').value = '';
    if (document.getElementById('fm-cat').options.length) document.getElementById('fm-cat').selectedIndex = 0;
    document.getElementById('fm-price').value = '';
    document.getElementById('fm-sort').value = '0';
    document.getElementById('fm-available').checked = true;
    document.getElementById('fm-img-input').value = '';
    document.getElementById('fm-imgprev').innerHTML = '';
    document.getElementById('fm-modal').classList.add('open');
}
function closeItemModal() { document.getElementById('fm-modal').classList.remove('open'); }

function editItem(btn) {
    const data = JSON.parse(btn.closest('.fm-card').dataset.item);
    document.getElementById('fm-modal-title').textContent = 'Edit Menu Item';
    document.getElementById('fm-id').value = data.id;
    document.getElementById('fm-existing-image').value = data.image || '';
    document.getElementById('fm-name').value = data.name;
    document.getElementById('fm-desc').value = data.description || '';
    document.getElementById('fm-cat').value = data.category_id || '';
    document.getElementById('fm-price').value = data.price;
    document.getElementById('fm-sort').value = data.sort_order || 0;
    document.getElementById('fm-available').checked = !!(data.is_available === true || data.is_available === 't' || data.is_available === 1);
    document.getElementById('fm-img-input').value = '';
    document.getElementById('fm-imgprev').innerHTML = data.image
        ? `<img src="<?= APP_URL ?>/Uploads/food/${encodeURIComponent(data.image)}" alt="">`
        : '';
    document.getElementById('fm-modal').classList.add('open');
}

function previewImg(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => { document.getElementById('fm-imgprev').innerHTML = `<img src="${e.target.result}" alt="">`; };
    reader.readAsDataURL(file);
}

async function saveItem() {
    const name = document.getElementById('fm-name').value.trim();
    const price = document.getElementById('fm-price').value;
    const categoryId = document.getElementById('fm-cat').value;
    if (!name) { alert('Item name is required.'); return; }
    if (price === '' || parseFloat(price) < 0) { alert('Enter a valid price.'); return; }
    if (!categoryId) { alert('Choose a category (create one first if the list is empty).'); return; }

    const fd = new FormData();
    fd.append('action', 'save_item');
    fd.append('id', document.getElementById('fm-id').value);
    fd.append('name', name);
    fd.append('description', document.getElementById('fm-desc').value);
    fd.append('category_id', categoryId);
    fd.append('price', price);
    fd.append('sort_order', document.getElementById('fm-sort').value || '0');
    fd.append('is_available', document.getElementById('fm-available').checked ? '1' : '');
    fd.append('existing_image', document.getElementById('fm-existing-image').value);
    const fileInput = document.getElementById('fm-img-input');
    if (fileInput.files[0]) fd.append('image', fileInput.files[0]);

    const btn = document.getElementById('fm-save-btn');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
        const res = await fetch('<?= APP_URL ?>/api/food.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (!res.ok || !json.ok) {
            alert(json.error?.message || 'Could not save the item.');
            btn.disabled = false; btn.textContent = '💾 Save Item';
            return;
        }
        window.location.reload();
    } catch (e) {
        alert('Network error saving the item.');
        btn.disabled = false; btn.textContent = '💾 Save Item';
    }
}

async function deleteItem(id, name) {
    if (!confirm(`Remove "${name}" from the menu?`)) return;
    try {
        const res = await fetch('<?= APP_URL ?>/api/food.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_item', id }),
        });
        const json = await res.json();
        if (!res.ok || !json.ok) {
            alert(json.error?.message || 'Could not delete the item.');
            return;
        }
        window.location.reload();
    } catch (e) {
        alert('Network error deleting the item.');
    }
}

// ── Manage Categories ─────────────────────────────────────
function openCatModal() { renderCatList(); document.getElementById('cat-modal').classList.add('open'); }
function closeCatModal() { document.getElementById('cat-modal').classList.remove('open'); }

function renderCatList() {
    const wrap = document.getElementById('cat-list');
    if (!FOOD_CATEGORIES.length) {
        wrap.innerHTML = '<p class="card-subtitle fm-empty-category">No categories yet — add one below.</p>';
        return;
    }
    wrap.innerHTML = FOOD_CATEGORIES.map(c => `
        <div class="fm-category-row" data-cat-id="${c.id}" data-cat-name="${escapeAttr(c.name)}">
            <input type="text" value="${escapeAttr(c.name)}" class="cat-name-input">
            <input type="number" value="${c.sort_order}" class="cat-sort-input" title="Sort order">
            <button type="button" class="btn-outline btn-xs" data-action="save-category">💾</button>
            <button type="button" class="btn-danger btn-xs" data-action="delete-category">🗑</button>
        </div>
    `).join('');
}

function escapeAttr(str) {
    return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

async function saveCategoryRow(btn, id) {
    const row = btn.closest('[data-cat-id]');
    const name = row.querySelector('.cat-name-input').value.trim();
    const sortOrder = row.querySelector('.cat-sort-input').value || '0';
    if (!name) { alert('Category name is required.'); return; }
    await postCategory({ id, name, sort_order: sortOrder }, btn);
}

async function addCategory() {
    const input = document.getElementById('cat-new-name');
    const name = input.value.trim();
    if (!name) { alert('Enter a category name.'); return; }
    const ok = await postCategory({ id: 0, name, sort_order: FOOD_CATEGORIES.length });
    if (ok) input.value = '';
}

async function postCategory(payload, btn) {
    if (btn) { btn.disabled = true; }
    try {
        const res = await fetch('<?= APP_URL ?>/api/food.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save_category', ...payload }),
        });
        const json = await res.json();
        if (!res.ok || !json.ok) {
            alert(json.error?.message || 'Could not save the category.');
            if (btn) btn.disabled = false;
            return false;
        }
        window.location.reload();
        return true;
    } catch (e) {
        alert('Network error saving the category.');
        if (btn) btn.disabled = false;
        return false;
    }
}

async function deleteCategory(id, name) {
    if (!confirm(`Delete category "${name}"? This only works if no menu items are using it.`)) return;
    try {
        const res = await fetch('<?= APP_URL ?>/api/food.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_category', id }),
        });
        const json = await res.json();
        if (!res.ok || !json.ok) {
            alert(json.error?.message || 'Could not delete the category.');
            return;
        }
        window.location.reload();
    } catch (e) {
        alert('Network error deleting the category.');
    }
}

document.addEventListener('click', event => {
    const target = event.target.closest('[data-action]');
    if (!target) return;

    const action = target.dataset.action;
    if (action === 'open-item') openItemModal();
    if (action === 'open-categories') openCatModal();
    if (action === 'close-item') closeItemModal();
    if (action === 'close-categories') closeCatModal();
    if (action === 'choose-image') document.getElementById('fm-img-input').click();
    if (action === 'save-item') saveItem();
    if (action === 'add-category') addCategory();
    if (action === 'edit-item') editItem(target);
    if (action === 'delete-item') deleteItem(Number(target.dataset.itemId), target.dataset.itemName);
    if (action === 'save-category') saveCategoryRow(target, Number(target.closest('[data-cat-id]').dataset.catId));
    if (action === 'delete-category') deleteCategory(Number(target.closest('[data-cat-id]').dataset.catId), target.closest('[data-cat-id]').dataset.catName);
});

document.getElementById('fm-img-input').addEventListener('change', event => previewImg(event.target));
document.querySelectorAll('.fm-img').forEach(image => {
    image.addEventListener('error', () => image.classList.add('fm-image-error'));
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
