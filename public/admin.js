// ============================================================
// Admin Console — Section M "Admin" sitemap, wired against the existing
// API. Vanilla JS, no build step, matches the pattern already used in
// public/app.js. Distinct localStorage keys so an admin session never
// collides with a customer session open in another tab.
// ============================================================

const APP_BASE = (window.APP_URL || '').replace(/\/$/, '');

const state = {
  accessToken: localStorage.getItem('admin_accessToken') || null,
  refreshToken: localStorage.getItem('admin_refreshToken') || null,
  user: JSON.parse(localStorage.getItem('admin_user') || 'null'),
  view: 'dashboard',
  cache: { owners: null, staff: null, itemTypes: null, bookings: null },
  socket: null,
};

function authHeaders() {
  return state.accessToken ? { Authorization: `Bearer ${state.accessToken}` } : {};
}

// The access token expires after 15 minutes (Section 6 hardening). Rather
// than forcing a fresh login every time it expires, silently exchange the
// longer-lived refresh token for a new access token and retry the request
// once. Only if that also fails (refresh token itself expired/invalid, or
// revoked by logout) do we actually drop the session and show the login
// screen again.
async function tryRefreshAccessToken() {
  if (!state.refreshToken) return false;
  try {
    const res = await fetch(APP_BASE + '/auth/refresh', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refreshToken: state.refreshToken }),
    });
    if (!res.ok) return false;
    const data = await res.json();
    state.accessToken = data.accessToken;
    localStorage.setItem('admin_accessToken', data.accessToken);
    return true;
  } catch {
    return false;
  }
}

// Fetches a printable HTML quotation/receipt from POST /quotation/receipt-html
// and opens it in a new tab (Print -> Save as PDF gives a PDF copy). Used
// by the booking drawer's "Print quotation" / "Print receipt" button.
async function openQuotationDocument(payload) {
  try {
    const res = await fetch(APP_BASE + '/quotation/receipt-html', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...authHeaders() },
      body: JSON.stringify(payload),
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      toast(err.error || 'Could not generate the document.', 'error');
      return;
    }
    const html = await res.text();
    const blob = new Blob([html], { type: 'text/html' });
    window.open(URL.createObjectURL(blob), '_blank');
  } catch {
    toast('Could not generate the document.', 'error');
  }
}

// Builds a quotation-breakdown object (same shape calculateQuotation()
// returns) directly from an already-saved booking's own stored numbers,
// instead of recalculating against current rates — a receipt for a past
// booking should show what was actually charged.
function breakdownFromBooking(b) {
  const items = (b.items || []).map((i) => ({
    item_type_id: i.item_type_id,
    name: i.item_type_name,
    quantity: i.quantity,
    days_rented: i.days_rented || 1,
    rate_used: Number(i.rate_used),
    line_total: Number(i.rate_used) * i.quantity * (i.days_rented || 1),
  }));
  const addons = (b.addons || []).map((a) => ({
    addon_id: a.addon_id,
    name: a.addon_name,
    quantity: a.quantity,
    rate_used: Number(a.rate_used),
    line_total: Number(a.rate_used) * a.quantity,
  }));
  const itemSubtotal = items.reduce((s, l) => s + l.line_total, 0);
  const addonSubtotal = addons.reduce((s, l) => s + l.line_total, 0);
  const deliveryFee = Number(b.delivery_fee || 0);

  return {
    items,
    addons,
    item_subtotal: itemSubtotal,
    addon_subtotal: addonSubtotal,
    fulfillment_method: b.fulfillment_method,
    delivery: b.fulfillment_method === 'delivery' ? { zone_name: null, km: null, fee: deliveryFee } : null,
    voucher: null,
    voucher_discount: 0,
    damage_deposit: Number(b.damage_deposit || 0),
    grand_total: Number(b.total_due || 0),
    deposit_required: Number(b.deposit_paid || 0),
    balance_due: Math.max(0, Number(b.total_due || 0) - Number(b.deposit_paid || 0)),
    generated_at: new Date().toISOString(),
  };
}

async function api(path, opts = {}) {
  const doFetch = () => fetch(path, {
    ...opts,
    headers: { 'Content-Type': 'application/json', ...authHeaders(), ...(opts.headers || {}) },
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });

  let res = await doFetch();

  if (res.status === 401 && (await tryRefreshAccessToken())) {
    res = await doFetch();
  }

  if (!res.ok) {
    if (res.status === 401) {
      clearSession();
      showLogin('Your session expired — please log in again.');
    }
    const err = await res.json().catch(() => ({ error: res.statusText }));
    throw new Error(err.error || 'Request failed');
  }
  if (res.status === 204) return null;
  return res.json();
}

function setSession(data) {
  state.accessToken = data.accessToken;
  state.refreshToken = data.refreshToken;
  state.user = data.user;
  localStorage.setItem('admin_accessToken', data.accessToken);
  localStorage.setItem('admin_refreshToken', data.refreshToken);
  localStorage.setItem('admin_user', JSON.stringify(data.user));
}
function clearSession() {
  state.accessToken = state.refreshToken = state.user = null;
  localStorage.removeItem('admin_accessToken');
  localStorage.removeItem('admin_refreshToken');
  localStorage.removeItem('admin_user');
}

// ---------- Small helpers ----------

function esc(s) {
  const d = document.createElement('div');
  d.textContent = s == null ? '' : String(s);
  return d.innerHTML;
}
function money(n) {
  if (n == null) return '—';
  const num = Number(n);
  return `₱${num.toLocaleString('en-PH', { maximumFractionDigits: 2 })}`;
}
function fmtDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}
function todayISO() {
  return new Date().toISOString().slice(0, 10);
}
function badge(text, cls) {
  return `<span class="badge ${cls}">${esc(text)}</span>`;
}
function statusBadge(status) {
  return badge(status.replace('_', ' '), `status-${status}`);
}
function riskBadge(flag) {
  return badge(flag, `risk-${flag}`);
}
function payoutBadge(status) {
  return badge(status, `pay-${status}`);
}

function toast(message, type = '') {
  const stack = document.getElementById('toast-stack');
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.textContent = message;
  stack.appendChild(el);
  setTimeout(() => el.remove(), 4000);
}

function table(columns, rows, renderRow) {
  if (!rows.length) return `<div class="empty">Nothing here yet.</div>`;
  return `<table class="data">
    <thead><tr>${columns.map((c) => `<th>${c}</th>`).join('')}</tr></thead>
    <tbody>${rows.map(renderRow).join('')}</tbody>
  </table>`;
}

// ---------- Drawer ----------

const drawerBackdrop = document.getElementById('drawer-backdrop');
const drawerEl = document.getElementById('drawer');
function openDrawer(html) {
  drawerEl.innerHTML = `<button class="drawer-close" id="drawer-close">&times;</button>${html}`;
  drawerBackdrop.classList.remove('hidden');
  document.getElementById('drawer-close').addEventListener('click', closeDrawer);
}
function closeDrawer() {
  drawerBackdrop.classList.add('hidden');
  drawerEl.innerHTML = '';
}
drawerBackdrop.addEventListener('click', (e) => { if (e.target === drawerBackdrop) closeDrawer(); });

// ---------- Cached lookups ----------

async function getOwners() {
  if (!state.cache.owners) state.cache.owners = await api('/owners');
  return state.cache.owners;
}
async function getStaffList() {
  if (!state.cache.staff) state.cache.staff = await api('/staff');
  return state.cache.staff;
}
async function getItemTypes() {
  if (!state.cache.itemTypes) state.cache.itemTypes = await api('/inventory/item-types');
  return state.cache.itemTypes;
}
async function getAllBookings(force) {
  if (!state.cache.bookings || force) state.cache.bookings = await api('/bookings');
  return state.cache.bookings;
}
function invalidateBookings() { state.cache.bookings = null; }

// ============================================================
// DASHBOARD
// ============================================================

async function viewDashboard(root) {
  root.innerHTML = `<div class="empty">Assembling today's manifest…</div>`;

  const [bookings, chatInbox, itemTypes] = await Promise.all([
    getAllBookings(true),
    api('/chat').catch(() => []),
    getItemTypes(),
  ]);

  const today = todayISO();
  const pending = bookings.filter((b) => b.status === 'pending_review');
  const jobsToday = bookings.filter((b) => b.event_date?.slice(0, 10) === today && ['confirmed', 'ongoing'].includes(b.status));
  const unreadChats = chatInbox.reduce((sum, t) => sum + Number(t.unread_count || 0), 0);

  // Low stock: any item type where 7-day-lookahead availability is thin.
  const lowStockThreshold = 3;
  const availabilityChecks = await Promise.all(
    itemTypes.map((it) =>
      api(`/inventory/availability?item_type_id=${it.id}&start_date=${today}&end_date=${today}`)
        .then((a) => ({ ...it, available: a.available }))
        .catch(() => ({ ...it, available: null }))
    )
  );
  const lowStock = availabilityChecks.filter((a) => a.available != null && a.available <= lowStockThreshold);

  root.innerHTML = `
    <div class="manifest">
      <div class="manifest-head">
        <span class="stamp">Manifest</span>
        <span class="date mono">${fmtDate(today)}</span>
      </div>
      <div class="manifest-line">
        <span class="label">Pending approvals</span>
        <span class="figure ${pending.length ? 'hot' : 'zero'}">${pending.length}</span>
        <a class="jump" data-jump="bookings" data-tab="pending_review">view →</a>
      </div>
      <div class="manifest-line">
        <span class="label">Jobs today</span>
        <span class="figure ${jobsToday.length ? '' : 'zero'}">${jobsToday.length}</span>
        <a class="jump" data-jump="bookings" data-tab="">view →</a>
      </div>
      <div class="manifest-line">
        <span class="label">Low-stock item types</span>
        <span class="figure ${lowStock.length ? 'hot' : 'zero'}">${lowStock.length}</span>
        <a class="jump" data-jump="inventory">view →</a>
      </div>
      <div class="manifest-line">
        <span class="label">Unread chat messages</span>
        <span class="figure ${unreadChats ? 'hot' : 'zero'}">${unreadChats}</span>
        <a class="jump" data-jump="chat">view →</a>
      </div>
    </div>

    <div class="grid-2">
      <div class="panel">
        <h3>Awaiting your review</h3>
        <p class="hint">Nothing here confirms itself — every one of these needs an approve or reject.</p>
        ${pending.length ? pending.slice(0, 6).map((b) => `
          <div class="manifest-line" style="cursor:pointer" data-open-booking="${b.id}">
            <span class="label">#${b.id} · ${esc(b.customer_name)} · ${fmtDate(b.event_date)}</span>
            <span class="figure">${money(b.total_due)}</span>
          </div>`).join('') : `<div class="empty">Nothing waiting on you right now.</div>`}
      </div>
      <div class="panel">
        <h3>Today's jobs</h3>
        <p class="hint">Confirmed or ongoing bookings scheduled for today.</p>
        ${jobsToday.length ? jobsToday.map((b) => `
          <div class="manifest-line" style="cursor:pointer" data-open-booking="${b.id}">
            <span class="label">#${b.id} · ${esc(b.customer_name)}</span>
            ${statusBadge(b.status)}
          </div>`).join('') : `<div class="empty">No jobs scheduled today.</div>`}
      </div>
    </div>

    ${lowStock.length ? `
    <div class="panel">
      <h3>Running low</h3>
      <p class="hint">7-day availability at or below ${lowStockThreshold} units.</p>
      ${lowStock.map((it) => `
        <div class="manifest-line">
          <span class="label">${esc(it.name)}</span>
          <span class="figure hot">${it.available} left</span>
        </div>`).join('')}
    </div>` : ''}
  `;

  root.querySelectorAll('[data-open-booking]').forEach((el) =>
    el.addEventListener('click', () => openBookingDrawer(Number(el.dataset.openBooking)))
  );
  root.querySelectorAll('[data-jump]').forEach((el) =>
    el.addEventListener('click', (e) => {
      e.preventDefault();
      navigate(el.dataset.jump, { tab: el.dataset.tab });
    })
  );
}

// ============================================================
// BOOKINGS
// ============================================================

const BOOKING_TABS = [
  ['', 'All'], ['inquiry', 'Inquiry'], ['pending_review', 'Pending Review'],
  ['confirmed', 'Confirmed'], ['ongoing', 'Ongoing'], ['completed', 'Completed'],
  ['rejected', 'Rejected'], ['cancelled', 'Cancelled'],
];

async function viewBookings(root, params = {}) {
  const activeTab = params.tab ?? '';
  root.innerHTML = `
    <div class="tabs" id="booking-tabs">
      ${BOOKING_TABS.map(([val, label]) => `<button class="tab ${val === activeTab ? 'active' : ''}" data-tab="${val}">${label}</button>`).join('')}
    </div>
    <div id="booking-table"><div class="empty">Loading…</div></div>
  `;

  async function loadTab(tab) {
    const path = tab ? `/bookings?status=${tab}` : '/bookings';
    const rows = await api(path);
    document.getElementById('booking-table').innerHTML = table(
      ['ID', 'Event date', 'Customer', 'Status', 'Payment', 'Total due'],
      rows,
      (b) => `<tr data-id="${b.id}">
        <td class="mono">#${b.id}</td>
        <td>${fmtDate(b.event_date)}</td>
        <td>${esc(b.customer_name)}</td>
        <td>${statusBadge(b.status)}</td>
        <td>${esc(b.payment_status)}</td>
        <td class="money">${money(b.total_due)}</td>
      </tr>`
    );
    document.querySelectorAll('#booking-table tr[data-id]').forEach((tr) =>
      tr.addEventListener('click', () => openBookingDrawer(Number(tr.dataset.id)))
    );
  }

  root.querySelectorAll('#booking-tabs .tab').forEach((btn) =>
    btn.addEventListener('click', () => {
      root.querySelectorAll('#booking-tabs .tab').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      loadTab(btn.dataset.tab);
    })
  );

  loadTab(activeTab);
}

async function openBookingDrawer(id) {
  openDrawer(`<div class="empty">Loading booking…</div>`);
  const [booking, staffList] = await Promise.all([api(`/bookings/${id}`), getStaffList()]);
  renderBookingDrawer(booking, staffList);
}

function renderBookingDrawer(b, staffList) {
  const itemsRows = (b.items || []).map((i) => `
    <div class="manifest-line">
      <span class="label">${esc(i.item_type_name)} × ${i.quantity} (${i.days_rented}d)</span>
      <span class="figure">${money(i.rate_used * i.quantity * i.days_rented)}</span>
    </div>`).join('') || `<div class="empty">No items.</div>`;

  const staffRows = (b.staff || []).map((s) => `
    <div class="manifest-line">
      <span class="label">${esc(s.staff_name)} · ${esc(s.job_type)}</span>
      <span class="figure">${badge(s.job_status.replace('_', ' '), 'status-confirmed')}</span>
    </div>`).join('') || `<div class="empty">No staff assigned yet.</div>`;

  let actions = '';
  if (b.status === 'pending_review') {
    actions = `
      <label>Review notes (internal only)</label>
      <textarea id="review-notes" placeholder="e.g. checked address, no issues"></textarea>
      <div class="action-row">
        <button class="btn good" id="approve-btn">Approve booking</button>
        <button class="btn danger" id="reject-btn">Reject booking</button>
      </div>`;
  } else if (b.status === 'confirmed') {
    actions = `
      <div class="action-row"><button class="btn primary" id="mark-ongoing-btn">Mark as ongoing (delivered)</button></div>
      <hr class="divider" />
      <h3 style="font-size:14px;">Assign delivery staff</h3>
      <div class="inline-form">
        <div><label>Staff</label><select id="assign-staff">${staffList.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('')}</select></div>
        <div><label>Job type</label><select id="assign-job-type">
          <option value="delivery">Delivery</option><option value="pickup">Pickup</option>
          <option value="setup">Setup</option><option value="teardown">Teardown</option>
        </select></div>
        <div><label>Pay ₱</label><input type="number" id="assign-pay" min="0" /></div>
        <button class="btn" id="assign-btn">Assign</button>
      </div>`;
  } else if (b.status === 'ongoing') {
    actions = `
      <div class="action-row">
        <button class="btn good" id="mark-completed-btn">Mark completed</button>
        <button class="btn danger" id="mark-cancelled-btn">Cancel booking</button>
      </div>
      <hr class="divider" />
      <h3 style="font-size:14px;">ID collateral</h3>
      <div class="kv"><span class="k">Held</span><span class="v">${b.id_held ? esc(b.id_held) : 'Not recorded'}</span></div>
      <div class="kv"><span class="k">Returned</span><span class="v">${b.id_returned ? 'Yes' : 'No'}</span></div>
      <div class="action-row">
        <input type="text" id="id-held-input" placeholder="e.g. Driver's License" style="flex:2;" />
        <button class="btn small" id="mark-id-held-btn">Mark held</button>
        <button class="btn small good" id="mark-id-returned-btn">Mark returned</button>
      </div>
      <hr class="divider" />
      <h3 style="font-size:14px;">Log damage (optional)</h3>
      <div class="inline-form">
        <div style="flex:2;"><label>Description</label><input type="text" id="damage-desc" /></div>
        <div><label>Charge ₱</label><input type="number" id="damage-charge" min="0" /></div>
        <button class="btn danger small" id="log-damage-btn">Log</button>
      </div>`;
  } else if (b.status === 'completed') {
    actions = `<p class="hint" style="margin-top:14px;">Earnings for this booking have been split — see the Earnings tab for each owner's share.</p>`;
  }

  openDrawer(`
    <h2>Booking #${b.id}</h2>
    <div class="sub">${fmtDate(b.event_date)} · ${statusBadge(b.status)}</div>

    <div class="kv">
      <span class="k">Customer</span><span class="v">${esc(b.customer_name)}</span>
      <span class="k">Contact</span><span class="v">${esc(b.contact_number || '—')}</span>
      <span class="k">Address</span><span class="v">${esc(b.delivery_address || b.customer_address || '—')}</span>
      <span class="k">Payment</span><span class="v">${esc(b.payment_status)} ${b.payment_method ? `(${esc(b.payment_method)})` : ''}</span>
      <span class="k">Deposit paid</span><span class="v money">${money(b.deposit_paid)}</span>
      <span class="k">Damage deposit</span><span class="v money">${money(b.damage_deposit)}</span>
      <span class="k">Total due</span><span class="v money">${money(b.total_due)}</span>
      ${b.proof_of_payment_url ? `<span class="k">Payment proof</span><span class="v"><a href="${APP_BASE}/payments/file/${esc(b.proof_of_payment_url)}" target="_blank">view file</a></span>` : ''}
      ${b.review_notes ? `<span class="k">Review notes</span><span class="v">${esc(b.review_notes)}</span>` : ''}
    </div>

    <div class="action-row">
      <button class="btn" id="print-quotation-btn">${b.payment_status === 'paid' ? 'Print receipt' : 'Print quotation'}</button>
    </div>
    <div id="booking-weather-note" style="margin:6px 0;"></div>

    <hr class="divider" />
    <h3 style="font-size:14px;">Items</h3>
    ${itemsRows}

    <hr class="divider" />
    <h3 style="font-size:14px;">Delivery staff</h3>
    ${staffRows}

    <hr class="divider" />
    ${actions}
  `);

  document.getElementById('print-quotation-btn')?.addEventListener('click', () => {
    openQuotationDocument({
      breakdown: breakdownFromBooking(b),
      bookingRef: `#${b.id}`,
      customerName: b.customer_name,
      eventDate: b.event_date,
      isReceipt: b.payment_status === 'paid',
      amountPaid: b.payment_status === 'paid' ? b.deposit_paid || b.total_due : null,
    });
  });

  // Weather advisory (new feature) — helps admin decide on tent add-ons,
  // crew prep, etc. while reviewing/managing a booking.
  (async () => {
    const note = document.getElementById('booking-weather-note');
    if (!note || !b.event_date) return;
    try {
      const lat = b.delivery_lat ? `&lat=${b.delivery_lat}&lng=${b.delivery_lng}` : '';
      const forecast = await api(`/weather/forecast?date=${b.event_date}${lat}`);
      if (!forecast.available) return;
      const f = forecast.forecast;
      const flags = forecast.advisory
        .filter((a) => a.level !== 'ok')
        .map((a) => `<div class="weather-flag weather-flag-${a.level}">${esc(a.message)}</div>`).join('');
      note.innerHTML = `<div class="weather-card"><strong>${esc(f.condition)}</strong> — ${f.temp_min_c}°–${f.temp_max_c}°C, rain ${f.rain_chance_percent}%, wind gusts ${f.wind_gust_kmh} km/h${flags}</div>`;
    } catch { /* non-fatal */ }
  })();

  document.getElementById('approve-btn')?.addEventListener('click', () => reviewBooking(b.id, 'approve'));
  document.getElementById('reject-btn')?.addEventListener('click', () => reviewBooking(b.id, 'reject'));
  document.getElementById('mark-ongoing-btn')?.addEventListener('click', () => setBookingStatus(b.id, 'ongoing'));
  document.getElementById('mark-completed-btn')?.addEventListener('click', () => setBookingStatus(b.id, 'completed'));
  document.getElementById('mark-cancelled-btn')?.addEventListener('click', () => setBookingStatus(b.id, 'cancelled'));
  document.getElementById('assign-btn')?.addEventListener('click', () => assignStaff(b.id));
  document.getElementById('mark-id-held-btn')?.addEventListener('click', () => markIdCollateral(b.id, false));
  document.getElementById('mark-id-returned-btn')?.addEventListener('click', () => markIdCollateral(b.id, true));
  document.getElementById('log-damage-btn')?.addEventListener('click', () => logDamage(b.id));
}

async function reviewBooking(id, decision) {
  const review_notes = document.getElementById('review-notes')?.value || null;
  try {
    await api(`/bookings/${id}/review`, { method: 'PATCH', body: { decision, review_notes } });
    toast(`Booking #${id} ${decision === 'approve' ? 'approved' : 'rejected'}.`, 'ok');
    invalidateBookings();
    closeDrawer();
    reRenderCurrentView();
  } catch (err) { toast(err.message, 'err'); }
}
async function setBookingStatus(id, status) {
  try {
    await api(`/bookings/${id}/status`, { method: 'PATCH', body: { status } });
    toast(`Booking #${id} marked ${status}.`, 'ok');
    invalidateBookings();
    closeDrawer();
    reRenderCurrentView();
  } catch (err) { toast(err.message, 'err'); }
}
async function assignStaff(bookingId) {
  const staff_id = Number(document.getElementById('assign-staff').value);
  const job_type = document.getElementById('assign-job-type').value;
  const pay_amount = Number(document.getElementById('assign-pay').value) || null;
  try {
    await api('/staff/assign', { method: 'POST', body: { booking_id: bookingId, staff_id, pay_amount, job_type } });
    toast('Staff assigned.', 'ok');
    openBookingDrawer(bookingId);
  } catch (err) { toast(err.message, 'err'); }
}
async function markIdCollateral(bookingId, returned) {
  const body = returned ? { id_returned: true } : { id_held: document.getElementById('id-held-input').value || 'ID' };
  try {
    await api(`/bookings/${bookingId}/id-collateral`, { method: 'PATCH', body });
    toast('Updated.', 'ok');
    openBookingDrawer(bookingId);
  } catch (err) { toast(err.message, 'err'); }
}
async function logDamage(bookingId) {
  const description = document.getElementById('damage-desc').value;
  const charge_amount = Number(document.getElementById('damage-charge').value) || null;
  try {
    await api('/damage-reports', { method: 'POST', body: { booking_id: bookingId, description, charge_amount } });
    toast('Damage report logged.', 'ok');
    document.getElementById('damage-desc').value = '';
    document.getElementById('damage-charge').value = '';
  } catch (err) { toast(err.message, 'err'); }
}

// ============================================================
// INVENTORY
// ============================================================

async function viewInventory(root, params = {}) {
  const activeTab = params.tab || 'types';
  root.innerHTML = `
    <div class="tabs" id="inv-tabs">
      <button class="tab ${activeTab === 'types' ? 'active' : ''}" data-tab="types">Item Types</button>
      <button class="tab ${activeTab === 'batches' ? 'active' : ''}" data-tab="batches">Batches</button>
      <button class="tab ${activeTab === 'units' ? 'active' : ''}" data-tab="units">Units</button>
    </div>
    <div id="inv-body"><div class="empty">Loading…</div></div>
  `;
  root.querySelectorAll('#inv-tabs .tab').forEach((btn) =>
    btn.addEventListener('click', () => { navigate('inventory', { tab: btn.dataset.tab }); })
  );
  const body = document.getElementById('inv-body');
  if (activeTab === 'types') await renderItemTypes(body);
  else if (activeTab === 'batches') await renderBatches(body);
  else await renderUnits(body);
}

async function renderItemTypes(root) {
  const types = await getItemTypes();
  root.innerHTML = `
    <div class="panel">
      <h3>Item types &amp; pricing</h3>
      <p class="hint">Every rate and cost here is editable any time — changes apply to future bookings immediately.</p>
      ${table(['Name', 'Rate / event', 'Unit cost', ''], types, (it) => `
        <tr data-id="${it.id}">
          <td>${esc(it.name)}</td>
          <td><input type="number" class="edit-rate" value="${it.base_rental_rate ?? ''}" style="width:100px;" /></td>
          <td><input type="number" class="edit-cost" value="${it.unit_cost ?? ''}" style="width:100px;" /></td>
          <td><button class="btn small save-type-btn">Save</button></td>
        </tr>`)}
    </div>
    <div class="panel">
      <h3>Add item type</h3>
      <div class="inline-form">
        <div><label>Name</label><input type="text" id="new-type-name" placeholder="e.g. Tiffany Chair" /></div>
        <div><label>Rate / event ₱</label><input type="number" id="new-type-rate" /></div>
        <div><label>Unit cost ₱</label><input type="number" id="new-type-cost" /></div>
        <button class="btn primary" id="add-type-btn">Add</button>
      </div>
    </div>
  `;
  root.querySelectorAll('.save-type-btn').forEach((btn) => {
    const tr = btn.closest('tr');
    btn.addEventListener('click', async () => {
      const id = tr.dataset.id;
      const base_rental_rate = Number(tr.querySelector('.edit-rate').value) || null;
      const unit_cost = Number(tr.querySelector('.edit-cost').value) || null;
      try {
        await api(`/inventory/item-types/${id}`, { method: 'PATCH', body: { base_rental_rate, unit_cost } });
        state.cache.itemTypes = null;
        toast('Rate/cost updated.', 'ok');
      } catch (err) { toast(err.message, 'err'); }
    });
  });
  document.getElementById('add-type-btn').addEventListener('click', async () => {
    const name = document.getElementById('new-type-name').value;
    const base_rental_rate = Number(document.getElementById('new-type-rate').value);
    const unit_cost = Number(document.getElementById('new-type-cost').value) || null;
    if (!name || !base_rental_rate) return toast('Name and rate are required.', 'err');
    try {
      await api('/inventory/item-types', { method: 'POST', body: { name, base_rental_rate, unit_cost } });
      state.cache.itemTypes = null;
      toast('Item type added.', 'ok');
      renderItemTypes(root);
    } catch (err) { toast(err.message, 'err'); }
  });
}

async function renderBatches(root) {
  const [batches, owners, types] = await Promise.all([api('/inventory/batches'), getOwners(), getItemTypes()]);
  root.innerHTML = `
    <div class="panel">
      <h3>Inventory batches</h3>
      ${table(['Owner', 'Item type', 'Qty', 'Cost/unit', 'Purchased'], batches, (b) => `
        <tr class="no-hover">
          <td>${esc(b.owner_name)}</td><td>${esc(b.item_type_name)}</td><td>${b.quantity}</td>
          <td class="money">${money(b.cost_per_unit)}</td><td>${fmtDate(b.purchase_date)}</td>
        </tr>`)}
    </div>
    <div class="panel">
      <h3>Add a new batch</h3>
      <p class="hint">Auto-generates individually trackable units for this batch.</p>
      <div class="inline-form">
        <div><label>Owner</label><select id="batch-owner">${owners.map((o) => `<option value="${o.id}">${esc(o.name)}</option>`).join('')}</select></div>
        <div><label>Item type</label><select id="batch-type">${types.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join('')}</select></div>
        <div><label>Quantity</label><input type="number" id="batch-qty" min="1" /></div>
        <div><label>Cost/unit ₱</label><input type="number" id="batch-cost" /></div>
        <div><label>Purchase date</label><input type="date" id="batch-date" value="${todayISO()}" /></div>
        <button class="btn primary" id="add-batch-btn">Add batch</button>
      </div>
    </div>
  `;
  document.getElementById('add-batch-btn').addEventListener('click', async () => {
    try {
      await api('/inventory/batches', {
        method: 'POST',
        body: {
          owner_id: Number(document.getElementById('batch-owner').value),
          item_type_id: Number(document.getElementById('batch-type').value),
          quantity: Number(document.getElementById('batch-qty').value),
          cost_per_unit: Number(document.getElementById('batch-cost').value) || null,
          purchase_date: document.getElementById('batch-date').value,
        },
      });
      toast('Batch added.', 'ok');
      renderBatches(root);
    } catch (err) { toast(err.message, 'err'); }
  });
}

async function renderUnits(root) {
  const statusOptions = ['available', 'rented', 'maintenance', 'damaged', 'retired'];
  root.innerHTML = `
    <div class="panel">
      <div class="inline-form" style="margin-bottom:14px;">
        <div><label>Filter by status</label>
          <select id="unit-status-filter"><option value="">All</option>${statusOptions.map((s) => `<option value="${s}">${s}</option>`).join('')}</select>
        </div>
      </div>
      <div id="units-table"><div class="empty">Loading…</div></div>
    </div>
  `;
  async function load() {
    const status = document.getElementById('unit-status-filter').value;
    const units = await api(`/inventory/units${status ? `?status=${status}` : ''}`);
    document.getElementById('units-table').innerHTML = table(
      ['Unit code', 'Item type', 'Status', ''],
      units,
      (u) => `<tr class="no-hover" data-id="${u.id}">
        <td><span class="unit-code">${esc(u.unit_code)}</span></td>
        <td>${esc(u.item_type_name)}</td>
        <td>${badge(u.status, u.status === 'available' ? 'risk-none' : u.status === 'rented' ? 'status-ongoing' : 'risk-blocked')}</td>
        <td><select class="unit-status-select">${statusOptions.map((s) => `<option value="${s}" ${s === u.status ? 'selected' : ''}>${s}</option>`).join('')}</select></td>
      </tr>`
    );
    document.querySelectorAll('.unit-status-select').forEach((sel) => {
      const tr = sel.closest('tr');
      sel.addEventListener('change', async () => {
        try {
          await api(`/inventory/units/${tr.dataset.id}`, { method: 'PATCH', body: { status: sel.value } });
          toast('Unit status updated.', 'ok');
        } catch (err) { toast(err.message, 'err'); }
      });
    });
  }
  document.getElementById('unit-status-filter').addEventListener('change', load);
  load();
}

// ============================================================
// CUSTOMERS
// ============================================================

async function viewCustomers(root, params = {}) {
  const activeTab = params.tab || '';
  root.innerHTML = `
    <div class="tabs" id="cust-tabs">
      <button class="tab ${activeTab === '' ? 'active' : ''}" data-tab="">All</button>
      <button class="tab ${activeTab === 'watch' ? 'active' : ''}" data-tab="watch">Watch</button>
      <button class="tab ${activeTab === 'blocked' ? 'active' : ''}" data-tab="blocked">Blocked</button>
    </div>
    <div id="cust-table"><div class="empty">Loading…</div></div>
  `;
  async function load(tab) {
    const rows = await api(`/customers${tab ? `?risk_flag=${tab}` : ''}`);
    document.getElementById('cust-table').innerHTML = table(
      ['Name', 'Contact', 'Address', 'Risk', 'Loyalty'],
      rows,
      (c) => `<tr data-id="${c.id}">
        <td>${esc(c.name)}</td><td>${esc(c.contact_number || '—')}</td>
        <td>${esc(c.address || '—')}</td><td>${riskBadge(c.risk_flag)}</td><td class="mono">${c.loyalty_count}</td>
      </tr>`
    );
    document.querySelectorAll('#cust-table tr[data-id]').forEach((tr) =>
      tr.addEventListener('click', () => openCustomerDrawer(Number(tr.dataset.id)))
    );
  }
  root.querySelectorAll('#cust-tabs .tab').forEach((btn) =>
    btn.addEventListener('click', () => {
      root.querySelectorAll('#cust-tabs .tab').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      load(btn.dataset.tab);
    })
  );
  load(activeTab);
}

async function openCustomerDrawer(id) {
  openDrawer(`<div class="empty">Loading…</div>`);
  const [customer, bookings] = await Promise.all([api(`/customers/${id}`), getAllBookings()]);
  const history = bookings.filter((b) => b.customer_id === id);

  openDrawer(`
    <h2>${esc(customer.name)}</h2>
    <div class="sub">Customer #${customer.id}</div>
    <div class="kv">
      <span class="k">Contact</span><span class="v">${esc(customer.contact_number || '—')}</span>
      <span class="k">Email</span><span class="v">${esc(customer.email || '—')}</span>
      <span class="k">Address</span><span class="v">${esc(customer.address || '—')}</span>
      <span class="k">Loyalty count</span><span class="v">${customer.loyalty_count}</span>
    </div>
    <hr class="divider" />
    <h3 style="font-size:14px;">Risk flag &amp; internal notes</h3>
    <p class="hint">Never shown to the customer.</p>
    <label>Risk flag</label>
    <select id="risk-flag-select">
      <option value="none" ${customer.risk_flag === 'none' ? 'selected' : ''}>None</option>
      <option value="watch" ${customer.risk_flag === 'watch' ? 'selected' : ''}>Watch</option>
      <option value="blocked" ${customer.risk_flag === 'blocked' ? 'selected' : ''}>Blocked</option>
    </select>
    <label>Internal notes</label>
    <textarea id="internal-notes">${esc(customer.internal_notes || '')}</textarea>
    <div class="action-row"><button class="btn primary" id="save-customer-btn">Save</button></div>
    <hr class="divider" />
    <h3 style="font-size:14px;">Booking history</h3>
    ${history.length ? history.map((b) => `
      <div class="manifest-line" style="cursor:pointer;" data-open-booking="${b.id}">
        <span class="label">#${b.id} · ${fmtDate(b.event_date)}</span>
        ${statusBadge(b.status)}
      </div>`).join('') : `<div class="empty">No bookings yet.</div>`}
  `);

  document.getElementById('save-customer-btn').addEventListener('click', async () => {
    try {
      await api(`/customers/${id}`, {
        method: 'PATCH',
        body: { risk_flag: document.getElementById('risk-flag-select').value, internal_notes: document.getElementById('internal-notes').value },
      });
      toast('Customer updated.', 'ok');
      closeDrawer();
      reRenderCurrentView();
    } catch (err) { toast(err.message, 'err'); }
  });
  drawerEl.querySelectorAll('[data-open-booking]').forEach((el) =>
    el.addEventListener('click', () => openBookingDrawer(Number(el.dataset.openBooking)))
  );
}

// ============================================================
// STAFF
// ============================================================

async function viewStaff(root) {
  const staff = await getStaffList();
  root.innerHTML = `
    <div class="panel">
      <h3>Delivery &amp; setup staff</h3>
      ${table(['Name', 'Contact', 'Rate/job', ''], staff, (s) => `
        <tr data-id="${s.id}">
          <td>${esc(s.name)}</td><td>${esc(s.contact_number || '—')}</td>
          <td><input type="number" class="edit-rate" value="${s.rate_per_job ?? ''}" style="width:100px;" /></td>
          <td><button class="btn small save-staff-btn">Save</button> <button class="btn small view-schedule-btn">Schedule</button></td>
        </tr>`)}
    </div>
    <div class="panel">
      <h3>Add staff member</h3>
      <div class="inline-form">
        <div><label>Name</label><input type="text" id="new-staff-name" /></div>
        <div><label>Contact</label><input type="text" id="new-staff-contact" /></div>
        <div><label>Rate/job ₱</label><input type="number" id="new-staff-rate" /></div>
        <button class="btn primary" id="add-staff-btn">Add</button>
      </div>
    </div>
  `;
  root.querySelectorAll('.save-staff-btn').forEach((btn) => {
    const tr = btn.closest('tr');
    btn.addEventListener('click', async () => {
      try {
        await api(`/staff/${tr.dataset.id}`, { method: 'PATCH', body: { rate_per_job: Number(tr.querySelector('.edit-rate').value) || null } });
        state.cache.staff = null;
        toast('Rate updated.', 'ok');
      } catch (err) { toast(err.message, 'err'); }
    });
  });
  root.querySelectorAll('.view-schedule-btn').forEach((btn) => {
    const tr = btn.closest('tr');
    btn.addEventListener('click', () => openStaffDrawer(Number(tr.dataset.id)));
  });
  document.getElementById('add-staff-btn').addEventListener('click', async () => {
    const name = document.getElementById('new-staff-name').value;
    if (!name) return toast('Name is required.', 'err');
    try {
      await api('/staff', { method: 'POST', body: {
        name, contact_number: document.getElementById('new-staff-contact').value || null,
        rate_per_job: Number(document.getElementById('new-staff-rate').value) || null,
      }});
      state.cache.staff = null;
      toast('Staff member added.', 'ok');
      viewStaff(root);
    } catch (err) { toast(err.message, 'err'); }
  });
}

async function openStaffDrawer(id) {
  openDrawer(`<div class="empty">Loading…</div>`);
  const [schedule, payouts] = await Promise.all([
    api(`/staff/${id}/schedule`), api(`/staff/${id}/payouts`),
  ]);
  const pendingTotal = payouts.filter((p) => p.job_status !== 'completed').reduce((s, p) => s + Number(p.pay_amount || 0), 0);
  openDrawer(`
    <h2>Staff #${id} — schedule &amp; payouts</h2>
    <h3 style="font-size:14px;">Upcoming schedule</h3>
    ${schedule.length ? schedule.map((s) => `
      <div class="manifest-line">
        <span class="label">${fmtDate(s.event_date)} · ${esc(s.customer_name)} · ${esc(s.job_type)}</span>
        ${badge(s.job_status.replace('_', ' '), 'status-confirmed')}
      </div>`).join('') : `<div class="empty">No jobs scheduled.</div>`}
    <hr class="divider" />
    <h3 style="font-size:14px;">Payouts</h3>
    <div class="kv"><span class="k">Outstanding</span><span class="v money">${money(pendingTotal)}</span></div>
    ${payouts.length ? payouts.map((p) => `
      <div class="manifest-line">
        <span class="label">${fmtDate(p.event_date)} · ${esc(p.job_type)}</span>
        <span class="figure">${money(p.pay_amount)}</span>
      </div>`).join('') : `<div class="empty">No payout history.</div>`}
  `);
}

// ============================================================
// EARNINGS
// ============================================================

async function viewEarnings(root) {
  const owners = await getOwners();
  root.innerHTML = `
    <div class="panel">
      <h3>Owners &amp; investment %</h3>
      <p class="hint">Each owner's share of the profit pool, in proportion to how much capital they invested. These should sum to 100% across all owners — if they don't, a warning banner shows below. Randall also collects the management fee (set under Business Settings) on top of his own investment share.</p>
      <div id="investment-total-warning"></div>
      ${table(['Owner', 'Runs business?', 'Investment %', ''], owners, (o) => `
        <tr data-id="${o.id}">
          <td>${esc(o.name)}</td>
          <td>${o.runs_business ? 'Yes — collects management fee' : '—'}</td>
          <td><input type="number" class="edit-cut" value="${o.investment_percent}" min="0" max="100" step="0.01" style="width:90px;" /></td>
          <td><button class="btn small save-cut-btn">Save</button></td>
        </tr>`)}
    </div>
    <div class="panel">
      <h3>Per-owner earnings</h3>
      <div class="inline-form" style="margin-bottom:16px;">
        <div><label>Owner</label><select id="earnings-owner">${owners.map((o) => `<option value="${o.id}">${esc(o.name)}</option>`).join('')}</select></div>
        <button class="btn" id="load-earnings-btn">Load</button>
      </div>
      <div id="earnings-body"></div>
    </div>
  `;
  root.querySelectorAll('.save-cut-btn').forEach((btn) => {
    const tr = btn.closest('tr');
    btn.addEventListener('click', async () => {
      try {
        await api(`/owners/${tr.dataset.id}`, { method: 'PATCH', body: { investment_percent: Number(tr.querySelector('.edit-cut').value) } });
        state.cache.owners = null;
        const freshOwners = await api('/owners');
        const total = freshOwners.reduce((sum, o) => sum + Number(o.investment_percent || 0), 0);
        const warningEl = document.getElementById('investment-total-warning');
        if (warningEl) {
          warningEl.innerHTML = Math.abs(total - 100) > 0.01
            ? `<div class="banner warn">Heads up: all owners' investment % currently sum to ${total.toFixed(2)}%, not 100%. Future bookings will still split proportionally, but fix this to keep payouts matching what you actually agreed.</div>`
            : '';
        }
        toast('Investment % updated.', 'ok');
      } catch (err) { toast(err.message, 'err'); }
    });
  });
  document.getElementById('load-earnings-btn').addEventListener('click', () => loadOwnerEarnings(Number(document.getElementById('earnings-owner').value)));
  if (owners[0]) loadOwnerEarnings(owners[0].id);
}

async function loadOwnerEarnings(ownerId) {
  const body = document.getElementById('earnings-body');
  body.innerHTML = `<div class="empty">Loading…</div>`;
  const data = await api(`/owners/${ownerId}/earnings`);
  body.innerHTML = `
    <div class="grid-3" style="margin-bottom:16px;">
      <div class="panel" style="margin-bottom:0;"><div class="hint">Total net earned</div><div class="money good" style="font-size:20px;">${money(data.totals.total_net)}</div></div>
      <div class="panel" style="margin-bottom:0;"><div class="hint">— investment share / — personal fee income</div><div style="font-size:16px;">${money(data.totals.total_investment_share)} / ${money(data.totals.total_personal_fee_income)}</div></div>
      <div class="panel" style="margin-bottom:0;"><div class="hint">Pending payout</div><div class="money" style="font-size:20px;">${money(data.totals.pending_payout)}</div></div>
    </div>
    ${table(
      ['Booking', 'Type', 'Date', 'Order gross', 'Op. cost', 'Mgmt fee', 'Inv. %', 'Inv. share', 'Net', 'Payout', 'Explanation'],
      data.transactions,
      (t) => `<tr class="no-hover" data-id="${t.id}">
        <td class="mono">#${t.booking_id}</td>
        <td>${t.entry_type === 'personal_fee_income' ? 'Late/cleaning fee' : 'Investment share'}</td>
        <td>${fmtDate(t.event_date)}</td>
        <td class="money">${money(t.order_gross)}</td><td class="money">${money(t.operational_cost)}</td>
        <td class="money">${Number(t.management_fee_amount) > 0 ? money(t.management_fee_amount) : '—'}</td>
        <td>${t.investment_percent_used != null ? esc(t.investment_percent_used) + '%' : '—'}</td>
        <td class="money">${money(t.investment_share_amount)}</td>
        <td class="money good">${money(t.net_amount)}</td>
        <td>${payoutBadge(t.payout_status)} ${t.payout_status === 'pending' ? `<button class="btn small mark-paid-btn">Mark paid</button>` : ''}</td>
        <td class="hint" style="max-width:280px;">${esc(t.explanation || '')}</td>
      </tr>`
    )}
  `;
  body.querySelectorAll('.mark-paid-btn').forEach((btn) => {
    const tr = btn.closest('tr');
    btn.addEventListener('click', async () => {
      try {
        await api(`/owners/earnings/${tr.dataset.id}/payout`, { method: 'PATCH' });
        toast('Marked as paid.', 'ok');
        loadOwnerEarnings(ownerId);
      } catch (err) { toast(err.message, 'err'); }
    });
  });
}

// ============================================================
// EXPENSES
// ============================================================

async function viewExpenses(root) {
  root.innerHTML = `
    <div class="panel">
      <h3>Log an expense</h3>
      <div class="inline-form">
        <div><label>Category</label><select id="exp-category">
          <option value="repair">Repair</option><option value="fuel">Fuel</option>
          <option value="staff_pay">Staff pay</option><option value="replacement">Replacement</option>
          <option value="other">Other</option>
        </select></div>
        <div style="flex:2;"><label>Description</label><input type="text" id="exp-desc" /></div>
        <div><label>Amount ₱</label><input type="number" id="exp-amount" /></div>
        <div><label>Date</label><input type="date" id="exp-date" value="${todayISO()}" /></div>
        <button class="btn primary" id="add-expense-btn">Log</button>
      </div>
    </div>
    <div class="panel"><h3>Expense log</h3><div id="expenses-table"><div class="empty">Loading…</div></div></div>
  `;
  async function load() {
    const rows = await api('/settings/expenses');
    document.getElementById('expenses-table').innerHTML = table(
      ['Date', 'Category', 'Description', 'Amount'],
      rows,
      (e) => `<tr class="no-hover">
        <td>${fmtDate(e.expense_date)}</td><td>${esc(e.category)}</td>
        <td>${esc(e.description || '—')}</td><td class="money">${money(e.amount)}</td>
      </tr>`
    );
  }
  document.getElementById('add-expense-btn').addEventListener('click', async () => {
    const amount = Number(document.getElementById('exp-amount').value);
    if (!amount) return toast('Amount is required.', 'err');
    try {
      await api('/settings/expenses', {
        method: 'POST',
        body: {
          category: document.getElementById('exp-category').value,
          description: document.getElementById('exp-desc').value,
          amount, expense_date: document.getElementById('exp-date').value,
        },
      });
      toast('Expense logged.', 'ok');
      document.getElementById('exp-desc').value = '';
      document.getElementById('exp-amount').value = '';
      load();
    } catch (err) { toast(err.message, 'err'); }
  });
  load();
}

// ============================================================
// REPORTS
// ============================================================

async function viewReports(root) {
  root.innerHTML = `<div class="empty">Pulling reports…</div>`;
  const [byOwner, mostRented, busiest, retention, expenseVsProfit] = await Promise.all([
    api('/reports/revenue-by-owner'), api('/reports/most-rented-items'),
    api('/reports/busiest-months'), api('/reports/customer-retention'),
    api('/reports/expense-vs-profit'),
  ]);

  const maxOwnerNet = Math.max(1, ...byOwner.map((o) => Number(o.net || 0)));
  const maxItemQty = Math.max(1, ...mostRented.map((i) => Number(i.total_quantity_rented || 0)));

  root.innerHTML = `
    <div class="grid-2">
      <div class="panel">
        <h3>Revenue by owner</h3>
        ${byOwner.map((o) => `
          <div class="bar-row">
            <div class="bar-label">${esc(o.owner_name)}</div>
            <div class="bar-track"><div class="bar-fill" style="width:${(Number(o.net) / maxOwnerNet) * 100}%"></div></div>
            <div class="bar-value">${money(o.net)}</div>
          </div>`).join('') || `<div class="empty">No completed bookings yet.</div>`}
      </div>
      <div class="panel">
        <h3>Most-rented items</h3>
        ${mostRented.map((i) => `
          <div class="bar-row">
            <div class="bar-label">${esc(i.item_type)}</div>
            <div class="bar-track"><div class="bar-fill" style="width:${(Number(i.total_quantity_rented) / maxItemQty) * 100}%"></div></div>
            <div class="bar-value">${i.total_quantity_rented}×</div>
          </div>`).join('') || `<div class="empty">No completed bookings yet.</div>`}
      </div>
    </div>
    <div class="grid-2">
      <div class="panel">
        <h3>Busiest months</h3>
        ${table(['Month', 'Bookings', 'Revenue'], busiest, (m) => `
          <tr class="no-hover"><td>${esc(m.month)}</td><td>${m.bookings_count}</td><td class="money">${money(m.total_revenue)}</td></tr>`)}
      </div>
      <div class="panel">
        <h3>Customer retention</h3>
        <div class="kv">
          <span class="k">One-time customers</span><span class="v">${retention.one_time_customers ?? 0}</span>
          <span class="k">Repeat customers</span><span class="v">${retention.repeat_customers ?? 0}</span>
          <span class="k">Avg. completed bookings</span><span class="v">${retention.avg_completed_bookings ?? '—'}</span>
        </div>
      </div>
    </div>
    <div class="panel">
      <h3>Expense vs. profit (all time)</h3>
      <div class="kv">
        <span class="k">Total revenue</span><span class="v money good">${money(expenseVsProfit.total_revenue)}</span>
        <span class="k">Total expenses</span><span class="v money bad">${money(expenseVsProfit.total_expenses)}</span>
        <span class="k">Net profit</span><span class="v money ${expenseVsProfit.net_profit >= 0 ? 'good' : 'bad'}">${money(expenseVsProfit.net_profit)}</span>
      </div>
    </div>
  `;
}

// ============================================================
// CHAT INBOX
// ============================================================

let activeChatCustomerId = null;

async function viewChat(root) {
  root.innerHTML = `
    <div class="grid-2" style="grid-template-columns: 280px 1fr; align-items:start;">
      <div class="panel" id="chat-threads" style="margin-bottom:0;">Loading…</div>
      <div class="panel" id="chat-pane" style="margin-bottom:0;"><div class="empty">Select a conversation.</div></div>
    </div>
  `;
  await loadChatThreads();
}

async function loadChatThreads() {
  const threads = await api('/chat');
  const el = document.getElementById('chat-threads');
  el.innerHTML = threads.length ? threads.map((t) => `
    <div class="manifest-line" style="cursor:pointer;" data-cid="${t.customer_id}">
      <span class="label">${esc(t.customer_name)}${Number(t.unread_count) ? ` <span class="badge status-pending_review">${t.unread_count}</span>` : ''}</span>
    </div>`).join('') : `<div class="empty">No conversations yet.</div>`;
  el.querySelectorAll('[data-cid]').forEach((row) =>
    row.addEventListener('click', () => openChatThread(Number(row.dataset.cid)))
  );
}

async function openChatThread(customerId) {
  activeChatCustomerId = customerId;
  const pane = document.getElementById('chat-pane');
  pane.innerHTML = `<div class="empty">Loading…</div>`;
  const msgs = await api(`/chat/${customerId}`);
  api(`/chat/${customerId}/read`, { method: 'PATCH' }).catch(() => {});
  state.socket?.emit('watch_chat', customerId);

  pane.innerHTML = `
    <div id="chat-msg-list" style="max-height:420px; overflow-y:auto; margin-bottom:12px;">
      ${msgs.map(chatMsgLine).join('') || `<div class="empty">No messages yet.</div>`}
    </div>
    <div class="inline-form">
      <div style="flex:3;"><input type="text" id="chat-reply-input" placeholder="Type a reply…" /></div>
      <button class="btn primary" id="chat-reply-btn">Send</button>
    </div>
  `;
  const list = document.getElementById('chat-msg-list');
  list.scrollTop = list.scrollHeight;

  document.getElementById('chat-reply-btn').addEventListener('click', sendChatReply);
  document.getElementById('chat-reply-input').addEventListener('keydown', (e) => { if (e.key === 'Enter') sendChatReply(); });
}

function chatMsgLine(m) {
  return `<div class="manifest-line"><span class="label"><strong>${m.sender_role === 'admin' ? 'You' : 'Customer'}:</strong> ${esc(m.message)}</span></div>`;
}

async function sendChatReply() {
  const input = document.getElementById('chat-reply-input');
  const message = input.value.trim();
  if (!message || !activeChatCustomerId) return;
  input.value = '';
  try {
    await api(`/chat/${activeChatCustomerId}`, { method: 'POST', body: { message } });
  } catch (err) { toast(err.message, 'err'); }
}

// ============================================================
// SETTINGS
// ============================================================

async function viewSettings(root, params = {}) {
  const activeTab = params.tab || 'business';
  root.innerHTML = `
    <div class="tabs" id="settings-tabs">
      <button class="tab ${activeTab === 'business' ? 'active' : ''}" data-tab="business">Terms &amp; Deposits</button>
      <button class="tab ${activeTab === 'zones' ? 'active' : ''}" data-tab="zones">Delivery Zones</button>
      <button class="tab ${activeTab === 'addons' ? 'active' : ''}" data-tab="addons">Add-ons</button>
      <button class="tab ${activeTab === 'accounts' ? 'active' : ''}" data-tab="accounts">User Accounts</button>
    </div>
    <div id="settings-body"><div class="empty">Loading…</div></div>
  `;
  root.querySelectorAll('#settings-tabs .tab').forEach((btn) =>
    btn.addEventListener('click', () => navigate('settings', { tab: btn.dataset.tab }))
  );
  const body = document.getElementById('settings-body');
  if (activeTab === 'business') await renderBusinessSettings(body);
  else if (activeTab === 'zones') await renderDeliveryZones(body);
  else if (activeTab === 'addons') await renderAddons(body);
  else await renderAccounts(body);
}

async function renderBusinessSettings(root) {
  const s = await api('/settings/business');
  root.innerHTML = `
    <div class="panel">
      <h3>Deposits, fees &amp; refund windows</h3>
      <p class="hint">These numbers feed directly into the live-rendered Terms &amp; Conditions customers see at booking time.</p>
      <div class="grid-3">
        <div><label>Deposit % (delivery bookings only)</label><input type="number" id="s-deposit" value="${s.deposit_percent}" /></div>
        <div><label>Rental window (hrs)</label><input type="number" id="s-window" value="${s.rental_window_hours}" /></div>
        <div><label>Late fee ₱/hr (kept by Randall)</label><input type="number" id="s-late" value="${s.late_fee_per_hour}" /></div>
        <div><label>Cleaning fee ₱/item (kept by Randall)</label><input type="number" id="s-clean" value="${s.cleaning_fee_per_item}" /></div>
        <div><label>Full refund if cancelled (days+ before)</label><input type="number" id="s-full-refund-days" value="${s.full_refund_days_before}" /></div>
      </div>
      <p class="hint" style="margin-top:8px;">No damage deposit is collected. Cancellations ${'>='} the day threshold above get a full refund of deposit; inside that window, no refund.</p>
      <div class="action-row"><button class="btn primary" id="save-business-btn">Save</button></div>
    </div>
    <div class="panel">
      <h3>Management fee (Randall's cut for running the business)</h3>
      <p class="hint">This % comes off the top of every completed booking's shared profit pool (revenue minus operational costs) before the remainder is split among all owners by investment %. Randall also gets his own investment-% share of what's left, same as everyone else. <strong>This only affects bookings completed after you save — every past transaction keeps the rate that was actually used at the time, so nothing already paid out changes retroactively.</strong></p>
      <div class="grid-3">
        <div><label>Management fee %</label><input type="number" id="s-mgmt-fee" min="0" max="100" step="0.1" value="${s.management_fee_percent}" /></div>
      </div>
      <div class="action-row"><button class="btn primary" id="save-mgmt-fee-btn">Save management fee %</button></div>
    </div>
    <div class="panel">
      <h3>Live Terms &amp; Conditions preview</h3>
      <details><summary style="cursor:pointer; color:var(--brand-dark);">Show current customer-facing text</summary>
        <pre class="mono" style="white-space:pre-wrap; font-size:12.5px; max-height:320px; overflow-y:auto; margin-top:10px;" id="terms-preview">Loading…</pre>
      </details>
    </div>
  `;
  api('/settings/terms').then(({ text }) => { document.getElementById('terms-preview').textContent = text; });

  document.getElementById('save-business-btn').addEventListener('click', async () => {
    try {
      await api('/settings/business', {
        method: 'PATCH',
        body: {
          deposit_percent: Number(document.getElementById('s-deposit').value),
          rental_window_hours: Number(document.getElementById('s-window').value),
          late_fee_per_hour: Number(document.getElementById('s-late').value),
          cleaning_fee_per_item: Number(document.getElementById('s-clean').value),
          full_refund_days_before: Number(document.getElementById('s-full-refund-days').value),
          partial_refund_days_before: Number(document.getElementById('s-full-refund-days').value),
        },
      });
      toast('Business settings saved.', 'ok');
      renderBusinessSettings(root);
    } catch (err) { toast(err.message, 'err'); }
  });

  document.getElementById('save-mgmt-fee-btn').addEventListener('click', async () => {
    try {
      await api('/settings/business', {
        method: 'PATCH',
        body: { management_fee_percent: Number(document.getElementById('s-mgmt-fee').value) },
      });
      toast('Management fee % saved. This applies to bookings completed from now on.', 'ok');
      renderBusinessSettings(root);
    } catch (err) { toast(err.message, 'err'); }
  });
}

async function renderDeliveryZones(root) {
  const zones = await api('/settings/delivery-zones');
  root.innerHTML = `
    <div class="panel">
      <h3>Delivery zones</h3>
      ${table(['Zone', 'Base fee', 'Per-km rate', 'Max km', ''], zones, (z) => `
        <tr data-id="${z.id}">
          <td><input type="text" class="z-name" value="${esc(z.zone_name)}" /></td>
          <td><input type="number" class="z-base" value="${z.base_fee}" style="width:90px;" /></td>
          <td><input type="number" class="z-rate" value="${z.per_km_rate}" style="width:90px;" /></td>
          <td><input type="number" class="z-max" value="${z.max_km ?? ''}" style="width:80px;" placeholder="∞" /></td>
          <td><button class="btn small save-zone-btn">Save</button></td>
        </tr>`)}
    </div>
    <div class="panel">
      <h3>Add a zone</h3>
      <div class="inline-form">
        <div><label>Zone name</label><input type="text" id="nz-name" placeholder="e.g. 10-15km" /></div>
        <div><label>Base fee ₱</label><input type="number" id="nz-base" /></div>
        <div><label>Per-km rate ₱</label><input type="number" id="nz-rate" /></div>
        <div><label>Max km</label><input type="number" id="nz-max" placeholder="leave blank = no limit" /></div>
        <button class="btn primary" id="add-zone-btn">Add</button>
      </div>
    </div>
  `;
  root.querySelectorAll('.save-zone-btn').forEach((btn) => {
    const tr = btn.closest('tr');
    btn.addEventListener('click', async () => {
      try {
        await api(`/settings/delivery-zones/${tr.dataset.id}`, {
          method: 'PATCH',
          body: {
            zone_name: tr.querySelector('.z-name').value,
            base_fee: Number(tr.querySelector('.z-base').value),
            per_km_rate: Number(tr.querySelector('.z-rate').value),
            max_km: tr.querySelector('.z-max').value ? Number(tr.querySelector('.z-max').value) : null,
          },
        });
        toast('Zone updated.', 'ok');
      } catch (err) { toast(err.message, 'err'); }
    });
  });
  document.getElementById('add-zone-btn').addEventListener('click', async () => {
    const zone_name = document.getElementById('nz-name').value;
    if (!zone_name) return toast('Zone name is required.', 'err');
    try {
      await api('/settings/delivery-zones', {
        method: 'POST',
        body: {
          zone_name,
          base_fee: Number(document.getElementById('nz-base').value) || 0,
          per_km_rate: Number(document.getElementById('nz-rate').value) || 0,
          max_km: document.getElementById('nz-max').value ? Number(document.getElementById('nz-max').value) : null,
        },
      });
      toast('Zone added.', 'ok');
      renderDeliveryZones(root);
    } catch (err) { toast(err.message, 'err'); }
  });
}

async function renderAddons(root) {
  const [addons, owners] = await Promise.all([api('/settings/addons'), getOwners()]);
  root.innerHTML = `
    <div class="panel">
      <h3>Add-ons</h3>
      ${table(['Name', 'Rate', 'Owner'], addons, (a) => `
        <tr class="no-hover"><td>${esc(a.name)}</td><td class="money">${money(a.rate)}</td><td>${esc(owners.find((o) => o.id === a.owner_id)?.name || '—')}</td></tr>`)}
    </div>
    <div class="panel">
      <h3>Add a new add-on</h3>
      <div class="inline-form">
        <div><label>Name</label><input type="text" id="addon-name" placeholder="e.g. Linens" /></div>
        <div><label>Rate ₱</label><input type="number" id="addon-rate" /></div>
        <div><label>Owner</label><select id="addon-owner"><option value="">— none / business —</option>${owners.map((o) => `<option value="${o.id}">${esc(o.name)}</option>`).join('')}</select></div>
        <button class="btn primary" id="add-addon-btn">Add</button>
      </div>
    </div>
  `;
  document.getElementById('add-addon-btn').addEventListener('click', async () => {
    const name = document.getElementById('addon-name').value;
    if (!name) return toast('Name is required.', 'err');
    try {
      await api('/settings/addons', {
        method: 'POST',
        body: { name, rate: Number(document.getElementById('addon-rate').value) || 0, owner_id: Number(document.getElementById('addon-owner').value) || null },
      });
      toast('Add-on created.', 'ok');
      renderAddons(root);
    } catch (err) { toast(err.message, 'err'); }
  });
}

async function renderAccounts(root) {
  const users = await api('/auth/users');
  const roleLabel = { admin: 'Admin', owner: 'Owner', delivery_staff: 'Delivery Staff', customer: 'Customer' };

  root.innerHTML = `
    <div class="panel">
      <h3>Change your password</h3>
      <form id="admin-password-form">
        <div class="form-row">
          <div><label>Current password</label><input type="password" id="ap-current" autocomplete="current-password" required /></div>
          <div><label>New password (min. 8 characters)</label><input type="password" id="ap-new" minlength="8" autocomplete="new-password" required /></div>
        </div>
        <div class="action-row" style="margin-top:10px;"><button type="submit" class="btn primary">Update password</button></div>
      </form>
    </div>

    <div class="panel">
      <h3>Create an account</h3>
      <p class="hint">Only admins can create Owner, Delivery Staff, and Customer accounts here. This creates the business record (owner/staff/customer profile) and its login together.</p>
      <form id="create-account-form">
        <div class="form-row">
          <div>
            <label>Account type</label>
            <select id="na-role">
              <option value="owner">Owner</option>
              <option value="delivery_staff">Delivery Staff</option>
              <option value="customer">Customer</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div id="na-name-field"><label>Full name</label><input type="text" id="na-name" /></div>
        </div>
        <div class="form-row">
          <div><label>Email</label><input type="email" id="na-email" required /></div>
          <div><label>Password (min. 8 characters)</label><input type="password" id="na-password" minlength="8" autocomplete="new-password" required /></div>
        </div>
        <div class="form-row" id="na-owner-fields">
          <div><label>Investment %</label><input type="number" id="na-investment" min="0" max="100" step="0.01" placeholder="0" /></div>
          <div><label>Contact info</label><input type="text" id="na-owner-contact" /></div>
          <div><label><input type="checkbox" id="na-runs-business" style="width:auto;vertical-align:middle;" /> Runs the business (collects management fee)</label></div>
        </div>
        <div class="form-row" id="na-staff-fields">
          <div><label>Contact number</label><input type="text" id="na-staff-contact" /></div>
          <div><label>Rate per job ₱</label><input type="number" id="na-rate" min="0" /></div>
        </div>
        <div class="form-row" id="na-customer-fields">
          <div><label>Contact number</label><input type="text" id="na-cust-contact" /></div>
          <div><label>Address</label><input type="text" id="na-cust-address" /></div>
        </div>
        <div class="action-row" style="margin-top:10px;"><button type="submit" class="btn primary">Create account</button></div>
      </form>
    </div>

    <div class="panel">
      <h3>Existing accounts</h3>
      ${table(['Email', 'Type', 'Linked to', 'Created', 'Status', ''], users, (u) => `
        <tr class="no-hover" data-id="${u.id}">
          <td>${esc(u.email)}</td>
          <td>${esc(roleLabel[u.role] || u.role)}</td>
          <td>${esc(u.linked_name || '—')}</td>
          <td>${fmtDate(u.created_at)}</td>
          <td>${u.deleted_at ? badge('deactivated', 'risk-blocked') : badge('active', 'status-confirmed')}</td>
          <td>${u.deleted_at
            ? `<button class="btn small reactivate-btn">Reactivate</button>`
            : `<button class="btn small danger deactivate-btn">Deactivate</button>`}</td>
        </tr>`)}
    </div>
  `;

  // ---- Change own password ----
  document.getElementById('admin-password-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await api('/auth/password', {
        method: 'PATCH',
        body: {
          currentPassword: document.getElementById('ap-current').value,
          newPassword: document.getElementById('ap-new').value,
        },
      });
      toast('Password updated. Use it next time you log in.', 'ok');
      e.target.reset();
    } catch (err) { toast(err.message, 'err'); }
  });

  // ---- Create-account form: show/hide fields by role ----
  const roleSelect = document.getElementById('na-role');
  const nameField = document.getElementById('na-name-field');
  const ownerFields = document.getElementById('na-owner-fields');
  const staffFields = document.getElementById('na-staff-fields');
  const customerFields = document.getElementById('na-customer-fields');
  function syncRoleFields() {
    const role = roleSelect.value;
    nameField.style.display = role === 'admin' ? 'none' : '';
    ownerFields.style.display = role === 'owner' ? '' : 'none';
    staffFields.style.display = role === 'delivery_staff' ? '' : 'none';
    customerFields.style.display = role === 'customer' ? '' : 'none';
  }
  roleSelect.addEventListener('change', syncRoleFields);
  syncRoleFields();

  document.getElementById('create-account-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const role = roleSelect.value;
    const body = {
      role,
      email: document.getElementById('na-email').value,
      password: document.getElementById('na-password').value,
      name: document.getElementById('na-name').value || undefined,
    };
    if (role === 'owner') {
      body.investment_percent = document.getElementById('na-investment').value ? Number(document.getElementById('na-investment').value) : undefined;
      body.contact_info = document.getElementById('na-owner-contact').value || undefined;
      body.runs_business = document.getElementById('na-runs-business').checked;
    } else if (role === 'delivery_staff') {
      body.contact_number = document.getElementById('na-staff-contact').value || undefined;
      body.rate_per_job = document.getElementById('na-rate').value ? Number(document.getElementById('na-rate').value) : undefined;
    } else if (role === 'customer') {
      body.contact_number = document.getElementById('na-cust-contact').value || undefined;
      body.address = document.getElementById('na-cust-address').value || undefined;
    }
    try {
      await api('/auth/accounts', { method: 'POST', body });
      toast('Account created.', 'ok');
      renderAccounts(root);
    } catch (err) { toast(err.message, 'err'); }
  });

  // ---- Deactivate / reactivate ----
  root.querySelectorAll('.deactivate-btn').forEach((btn) => {
    const id = btn.closest('tr').dataset.id;
    btn.addEventListener('click', async () => {
      if (!confirm('Deactivate this account? They will be logged out and unable to log back in until reactivated.')) return;
      try {
        await api(`/auth/users/${id}/deactivate`, { method: 'PATCH' });
        toast('Account deactivated.', 'ok');
        renderAccounts(root);
      } catch (err) { toast(err.message, 'err'); }
    });
  });
  root.querySelectorAll('.reactivate-btn').forEach((btn) => {
    const id = btn.closest('tr').dataset.id;
    btn.addEventListener('click', async () => {
      try {
        await api(`/auth/users/${id}/reactivate`, { method: 'PATCH' });
        toast('Account reactivated.', 'ok');
        renderAccounts(root);
      } catch (err) { toast(err.message, 'err'); }
    });
  });
}

// ============================================================
// Router
// ============================================================

const VIEWS = {
  dashboard: viewDashboard, bookings: viewBookings, inventory: viewInventory,
  customers: viewCustomers, staff: viewStaff, earnings: viewEarnings,
  expenses: viewExpenses, reports: viewReports, chat: viewChat, settings: viewSettings,
};
const TITLES = {
  dashboard: ['Dashboard', "Today's manifest, at a glance"],
  bookings: ['Bookings', 'Review, approve, and manage every booking'],
  inventory: ['Inventory', 'Item types, batches, and individual units'],
  customers: ['Customers', 'Profiles, history, and internal risk flags'],
  staff: ['Delivery Staff', 'Crew, schedules, and payouts'],
  earnings: ['Earnings', 'Per-owner splits and payout tracking'],
  expenses: ['Expenses', 'Repairs, fuel, and other bookkeeping'],
  reports: ['Reports', 'Revenue, retention, and profit at a glance'],
  chat: ['Chat Inbox', 'All customer conversations'],
  settings: ['Settings', 'Pricing rules, delivery zones, and terms'],
};

let lastParams = {};
function navigate(view, params = {}) {
  state.view = view;
  lastParams = params;
  document.querySelectorAll('.side-link').forEach((b) => b.classList.toggle('active', b.dataset.view === view));
  const [title, sub] = TITLES[view];
  document.getElementById('page-title').textContent = title;
  document.getElementById('page-sub').textContent = sub;
  const content = document.getElementById('content');
  content.innerHTML = `<div class="empty">Loading…</div>`;
  VIEWS[view](content, params).catch((err) => {
    content.innerHTML = `<div class="empty">${esc(err.message)}</div>`;
  });
}
function reRenderCurrentView() { navigate(state.view, lastParams); }

document.querySelectorAll('.side-link').forEach((btn) =>
  btn.addEventListener('click', () => navigate(btn.dataset.view))
);

// ============================================================
// Auth wiring
// ============================================================

const loginScreen = document.getElementById('login-screen');
const appShell = document.getElementById('app-shell');

function showApp() {
  loginScreen.classList.add('hidden');
  appShell.classList.remove('hidden');
  document.getElementById('whoami').textContent = state.user.email;
  connectSocket();
  navigate('dashboard');
  refreshSidebarCounts();
}
function showLogin(message) {
  appShell.classList.add('hidden');
  loginScreen.classList.remove('hidden');
  document.getElementById('login-error').textContent = message || '';
}

// Maps a login response's role to where that role's dashboard actually lives,
// and which localStorage prefix that portal reads its session from (see the
// matching state.accessToken lines near the top of app.js/admin.js/owner.js/
// delivery.js). Used so that logging in from ANY of the 4 portal pages lands
// you on the correct dashboard automatically, instead of showing "wrong
// portal" and making you navigate there and log in again.
function routeForRole(role) {
  return {
    customer: { path: '/', prefix: '' },
    owner: { path: '/owner', prefix: 'owner_' },
    admin: { path: '/admin', prefix: 'admin_' },
    delivery_staff: { path: '/delivery', prefix: 'delivery_' },
  }[role];
}
function redirectToRolePortal(data) {
  const route = routeForRole(data.user.role);
  if (!route) return;
  const prefix = route.prefix;
  localStorage.setItem(`${prefix}accessToken`, data.accessToken);
  localStorage.setItem(`${prefix}refreshToken`, data.refreshToken);
  localStorage.setItem(`${prefix}user`, JSON.stringify(data.user));
  window.location.href = route.path;
}

document.getElementById('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const email = document.getElementById('login-email').value;
  const password = document.getElementById('login-password').value;
  try {
    const data = await api('/auth/login', { method: 'POST', body: { email, password } });
    if (data.user.role !== 'admin') {
      redirectToRolePortal(data);
      return;
    }
    setSession(data);
    showApp();
  } catch (err) {
    showLogin(err.message);
  }
});

document.getElementById('logout-btn').addEventListener('click', async () => {
  try { await api('/auth/logout', { method: 'POST', body: { refreshToken: state.refreshToken } }); } catch {}
  state.socket?.disconnect();
  clearSession();
  showLogin();
});

async function refreshSidebarCounts() {
  try {
    const [bookings, chatInbox] = await Promise.all([getAllBookings(true), api('/chat').catch(() => [])]);
    const pendingCount = bookings.filter((b) => b.status === 'pending_review').length;
    const unread = chatInbox.reduce((s, t) => s + Number(t.unread_count || 0), 0);
    const bEl = document.getElementById('count-bookings');
    const cEl = document.getElementById('count-chat');
    bEl.textContent = pendingCount; bEl.classList.toggle('hidden', !pendingCount);
    cEl.textContent = unread; cEl.classList.toggle('hidden', !unread);
  } catch {}
}

// ---------- Real-time ----------

function connectSocket() {
  if (!state.accessToken) return;
  if (state.socket) state.socket.disconnect();
  state.socket = io({ auth: { token: state.accessToken } });

  state.socket.on('new_booking_request', () => { toast('New booking request received.'); invalidateBookings(); refreshSidebarCounts(); if (state.view === 'bookings' || state.view === 'dashboard') reRenderCurrentView(); });
  state.socket.on('booking_updated', () => { invalidateBookings(); refreshSidebarCounts(); if (state.view === 'bookings' || state.view === 'dashboard') reRenderCurrentView(); });
  state.socket.on('new_chat_message', () => { refreshSidebarCounts(); if (state.view === 'chat') loadChatThreads(); });
  state.socket.on('chat_message', (msg) => {
    if (state.view === 'chat' && activeChatCustomerId === msg.customer_id) {
      const list = document.getElementById('chat-msg-list');
      if (list) { list.insertAdjacentHTML('beforeend', chatMsgLine(msg)); list.scrollTop = list.scrollHeight; }
    }
  });
  state.socket.on('job_status_changed', () => { if (state.view === 'bookings') reRenderCurrentView(); });
}

// ---------- Init ----------

if (state.accessToken && state.user?.role === 'admin') {
  showApp();
} else if (state.accessToken && state.user) {
  // A session exists but it's for a different role (e.g. this browser was
  // last logged in as Owner) — send them to the right dashboard instead of
  // just showing this portal's login screen.
  redirectToRolePortal({ accessToken: state.accessToken, refreshToken: state.refreshToken, user: state.user });
} else {
  showLogin();
}
