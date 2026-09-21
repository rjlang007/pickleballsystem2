// Section H: Landing Page (Customer-Facing) — plain HTML/JS frontend talking
// to the Express API. No build step needed, per Section 2's "any frontend"
// allowance.
const APP_BASE = (window.APP_URL || '').replace(/\/$/, '');
const API = APP_BASE + '/api';

// PWA: register the service worker (public/sw.js) so the landing page is
// installable and the static shell (HTML/CSS/JS/icons) loads instantly —
// or at all — even on a bad connection. Kept as a plain external-file
// script (not an inline <script> in index.html) since the default helmet()
// CSP in server.js blocks inline scripts; this file is already same-origin
// and allowed. Fire-and-forget: a failure here must never block the app
// itself from loading.
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register(APP_BASE + '/sw.js').catch((err) => {
      console.warn('Service worker registration failed:', err);
    });
  });
}

const state = {
  accessToken: localStorage.getItem('accessToken') || null,
  refreshToken: localStorage.getItem('refreshToken') || null,
  user: JSON.parse(localStorage.getItem('user') || 'null'),
  itemTypes: [],
  portalSession: null, // filled in below, once getPortalSession() exists
};

function authHeaders() {
  return state.accessToken ? { Authorization: `Bearer ${state.accessToken}` } : {};
}

// Maps a login response's role to where that role's dashboard actually lives,
// and which localStorage prefix that portal reads its session from. Used so
// logging in from ANY of the 4 portal pages lands you on the correct
// dashboard automatically instead of a "wrong portal" dead end.
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

// Detects a still-active Owner/Admin/Delivery session saved under that
// portal's own localStorage prefix (see redirectToRolePortal above). This
// page only ever reads the unprefixed keys for its own (customer) session,
// so someone who clicks "View site" from a portal while signed in looked,
// from here, like a logged-out visitor and got sent through the login form
// again. Checking the prefixed keys too lets this page recognise "I'm
// already signed in, just visiting the customer-facing page" instead.
const PORTALS = [
  { role: 'owner', label: 'the Owner Portal', path: '/owner', prefix: 'owner_' },
  { role: 'admin', label: 'the Admin Console', path: '/admin', prefix: 'admin_' },
  { role: 'delivery_staff', label: 'the Delivery Portal', path: '/delivery', prefix: 'delivery_' },
];
function getPortalSession() {
  for (const portal of PORTALS) {
    const token = localStorage.getItem(`${portal.prefix}accessToken`);
    const raw = localStorage.getItem(`${portal.prefix}user`);
    if (!token || !raw) continue;
    try {
      return { ...portal, user: JSON.parse(raw) };
    } catch { /* corrupt entry, ignore */ }
  }
  return null;
}
state.portalSession = getPortalSession();

// See admin.js for the full explanation: access tokens expire after 15
// minutes, so silently swap in a fresh one via the refresh token instead of
// forcing a repeat login every time it expires.
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
    localStorage.setItem('accessToken', data.accessToken);
    return true;
  } catch {
    return false;
  }
}

// Fetches a printable HTML quotation/receipt from POST /quotation/receipt-html
// and opens it in a new tab, where the customer/admin can use the browser's
// Print -> Save as PDF. Used both for pre-booking "what would this cost"
// previews and for post-booking official receipts.
async function openQuotationDocument(payload) {
  try {
    const res = await fetch(API + '/quotation/receipt-html', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...authHeaders() },
      body: JSON.stringify(payload),
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      alert(err.error || 'Could not generate the quotation right now.');
      return;
    }
    const html = await res.text();
    const blob = new Blob([html], { type: 'text/html' });
    window.open(URL.createObjectURL(blob), '_blank');
  } catch (err) {
    alert('Could not generate the quotation right now.');
  }
}

// Builds a quotation-breakdown object (same shape calculateQuotation()
// returns) directly from an already-saved booking's own stored numbers,
// instead of recalculating against current rates. This is what a receipt
// for a past booking SHOULD show — what was actually charged — and it
// sidesteps fields (delivery_zone_id/km) that bookings don't store.
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
  const doFetch = () => fetch(API + path, {
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
      renderView('account');
    }
    const err = await res.json().catch(() => ({ error: res.statusText }));
    throw new Error(err.error || 'Request failed');
  }
  if (res.status === 204) return null;
  return res.json();
}

function setSession({ accessToken, refreshToken, user }) {
  state.accessToken = accessToken;
  state.refreshToken = refreshToken;
  state.user = user;
  localStorage.setItem('accessToken', accessToken);
  localStorage.setItem('refreshToken', refreshToken);
  localStorage.setItem('user', JSON.stringify(user));
  connectSocket();
}

function clearSession() {
  state.accessToken = state.refreshToken = state.user = null;
  localStorage.clear();
}

// ---------- Views ----------

const app = document.getElementById('app');

// Small flat line icons per item type — matched by keyword in the name,
// falling back to the table icon. Kept simple/schematic on purpose so they
// read clearly at card size, not literal illustrations.
function itemIcon(name) {
  const n = name.toLowerCase();
  if (n.includes('chair')) {
    return `<svg class="item-icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M14 6h20a2 2 0 0 1 2 2v14a6 6 0 0 1-6 6H18a6 6 0 0 1-6-6V8a2 2 0 0 1 2-2z" stroke="currentColor" stroke-width="2.5"/>
      <path d="M18 28v12M30 28v12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
      <path d="M14 14h20" stroke="currentColor" stroke-width="2"/>
    </svg>`;
  }
  if (n.includes('tent')) {
    return `<svg class="item-icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M24 6 4 38h40L24 6z" fill="currentColor" opacity="0.12"/>
      <path d="M24 6 4 38h40L24 6z" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round"/>
      <path d="M24 6v32" stroke="currentColor" stroke-width="2"/>
      <path d="M4 38h40" stroke="currentColor" stroke-width="2.5"/>
    </svg>`;
  }
  // table (default)
  return `<svg class="item-icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
    <ellipse cx="24" cy="14" rx="18" ry="6" fill="currentColor" opacity="0.12"/>
    <ellipse cx="24" cy="14" rx="18" ry="6" stroke="currentColor" stroke-width="2.5"/>
    <path d="M14 20l-4 20M34 20l4 20M24 20v20" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
  </svg>`;
}

// The hero signature graphic: a flat, front-on scene of a set table with
// bunting strung overhead — the specific, recognizable moment of a
// backyard turning into a party, not a generic icon set.
const HERO_ART = `<svg viewBox="0 0 300 260" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Illustration of a set table with bunting overhead">
  <line x1="14" y1="18" x2="286" y2="18" stroke="#12140F" stroke-width="2"/>
  <path d="M20 18 L38 18 L29 46 Z" fill="#0C3B2A"/>
  <path d="M58 18 L76 18 L67 44 Z" fill="#12140F"/>
  <path d="M96 18 L114 18 L105 48 Z" fill="#0C3B2A"/>
  <path d="M134 18 L152 18 L143 44 Z" fill="#FFFFFF" stroke="#0C3B2A" stroke-width="1.5"/>
  <path d="M172 18 L190 18 L181 48 Z" fill="#0C3B2A"/>
  <path d="M210 18 L228 18 L219 44 Z" fill="#12140F"/>
  <path d="M248 18 L266 18 L257 46 Z" fill="#0C3B2A"/>
  <ellipse cx="150" cy="152" rx="92" ry="56" fill="#0C3B2A"/>
  <ellipse cx="150" cy="152" rx="92" ry="56" fill="none" stroke="#FFFFFF" stroke-width="3"/>
  <ellipse cx="150" cy="152" rx="80" ry="48" fill="none" stroke="#FFFFFF" stroke-width="1" opacity="0.5"/>
  <ellipse cx="150" cy="146" rx="70" ry="40" fill="#FFFFFF" opacity="0.06"/>
  <rect x="18" y="132" width="34" height="42" rx="6" fill="#12140F"/>
  <rect x="248" y="132" width="34" height="42" rx="6" fill="#12140F"/>
  <rect x="133" y="198" width="34" height="42" rx="6" fill="#12140F"/>
</svg>`;

// Simple line icons for the event-type gallery — same restrained,
// schematic style as itemIcon() above, just a different subject.
const GALLERY_ICONS = {
  birthday: `<svg class="gallery-icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M24 6c-3 3-3 6 0 8s3 5 0 8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
    <rect x="10" y="22" width="28" height="18" rx="2" stroke="currentColor" stroke-width="2.2"/>
    <path d="M10 30h28M17 22v18M31 22v18" stroke="currentColor" stroke-width="1.6" opacity="0.6"/>
  </svg>`,
  wedding: `<svg class="gallery-icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
    <circle cx="18" cy="24" r="10" stroke="currentColor" stroke-width="2.2"/>
    <circle cx="30" cy="24" r="10" stroke="currentColor" stroke-width="2.2"/>
  </svg>`,
  corporate: `<svg class="gallery-icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
    <rect x="8" y="18" width="32" height="20" rx="2" stroke="currentColor" stroke-width="2.2"/>
    <path d="M18 18v-4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v4" stroke="currentColor" stroke-width="2.2"/>
    <path d="M8 27h32" stroke="currentColor" stroke-width="1.6" opacity="0.6"/>
  </svg>`,
  fiesta: `<svg class="gallery-icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M6 14c8 6 12 6 18 0M18 14c8 6 12 6 18 0M30 14c6 4 8 5 12 3" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
    <path d="M10 14v20M24 14v20M38 17v17" stroke="currentColor" stroke-width="2" opacity="0.7"/>
  </svg>`,
};

async function renderBrowse() {
  app.innerHTML = `
    <section class="hero">
      <div class="hero-copy">
        <span class="eyebrow">Polomolok, South Cotabato · same-week delivery</span>
        <h2>Chairs lined up. Tent up. Table <span class="accent">set</span> — before your guests arrive.</h2>
        <p class="lede">We deliver, set up, and pick up your tables, chairs, and tent, so your backyard does the rest of the work. Every booking is reviewed by hand — not auto-confirmed — so we can look after your event personally.</p>
        <div class="hero-actions">
          <button class="primary" id="hero-cta-book">Check dates &amp; book</button>
          <button class="secondary" id="hero-cta-how">How it works</button>
        </div>
      </div>
      <div class="hero-art">${HERO_ART}</div>
    </section>

    <div class="stat-bar">
      <div class="stat"><span class="stat-num">4.9★</span><span class="stat-label">Average rating</span></div>
      <div class="stat"><span class="stat-num">300+</span><span class="stat-label">Events styled</span></div>
      <div class="stat"><span class="stat-num">24hr</span><span class="stat-label">Response time</span></div>
      <div class="stat"><span class="stat-num">100%</span><span class="stat-label">Hand-reviewed</span></div>
    </div>

    <div class="section-heading"><h2>What's available</h2></div>
    <div class="card">
      <label>Check availability for date</label>
      <input type="date" id="avail-date" value="${new Date().toISOString().slice(0,10)}" />
    </div>
    <div class="item-grid" id="item-grid">Loading…</div>

    <div class="section-heading" id="how-it-works"><h2>How it works</h2></div>
    <div class="how-it-works">
      <div class="step">
        <div class="step-num">1</div>
        <h3>Pick your date &amp; items</h3>
        <p>Browse what's free for your date, add tables/chairs/tent, and submit a request with a small deposit.</p>
      </div>
      <div class="step">
        <div class="step-num">2</div>
        <h3>We review &amp; confirm</h3>
        <p>Every request gets a personal look before it's approved — not an auto-confirm — so we can flag anything that needs a quick chat first.</p>
      </div>
      <div class="step">
        <div class="step-num">3</div>
        <h3>We deliver, set up &amp; pick up</h3>
        <p>Our crew brings everything, sets it up, and comes back for pickup after your event. You just host.</p>
      </div>
    </div>

    <div class="section-heading"><h2>Occasions we style</h2></div>
    <div class="gallery-grid">
      <div class="gallery-card">${GALLERY_ICONS.birthday}<span>Birthdays &amp; debuts</span></div>
      <div class="gallery-card">${GALLERY_ICONS.wedding}<span>Weddings &amp; receptions</span></div>
      <div class="gallery-card">${GALLERY_ICONS.corporate}<span>Corporate &amp; company events</span></div>
      <div class="gallery-card">${GALLERY_ICONS.fiesta}<span>Fiestas &amp; reunions</span></div>
    </div>

    <div class="section-heading"><h2>What hosts are saying</h2></div>
    <div class="testimonial-grid">
      <div class="testimonial">
        <span class="quote-mark">&ldquo;</span>
        <div class="stars">★★★★★</div>
        <p class="quote">Everything arrived on time and set up before our guests came. Made our daughter's debut so much less stressful to plan.</p>
        <div class="who">Ana R.</div>
        <div class="where">Debut · Polomolok</div>
      </div>
      <div class="testimonial">
        <span class="quote-mark">&ldquo;</span>
        <div class="stars">★★★★★</div>
        <p class="quote">Sobrang accommodating and organized. They confirmed every detail with us personally before the event — no surprises.</p>
        <div class="who">Mark T.</div>
        <div class="where">Wedding reception · South Cotabato</div>
      </div>
      <div class="testimonial">
        <span class="quote-mark">&ldquo;</span>
        <div class="stars">★★★★★</div>
        <p class="quote">Booked for our barangay fiesta with only a few days' notice. Crew was quick, polite, and picked everything up right after.</p>
        <div class="who">Grace L.</div>
        <div class="where">Fiesta &amp; reunion · Polomolok</div>
      </div>
    </div>

    <div class="section-heading"><h2>Frequently asked</h2></div>
    <div class="faq-list">
      <details class="faq-item">
        <summary>How much is the deposit, and when is the rest due?</summary>
        <p>A small deposit secures your date when your booking is confirmed. The remaining balance is settled on delivery day, before setup begins. Your booking summary always shows the exact split.</p>
      </details>
      <details class="faq-item">
        <summary>What's your delivery area, and is there a fee?</summary>
        <p>We're based in Polomolok, South Cotabato and deliver across nearby towns. Delivery fees are distance-based and shown automatically once you enter your address at checkout — no hidden charges.</p>
      </details>
      <details class="faq-item">
        <summary>Is setup and pickup really included?</summary>
        <p>Yes — our crew delivers, sets up tables, chairs, and tent ahead of your event, then returns afterward for pickup. You don't need to lift or arrange anything yourself.</p>
      </details>
      <details class="faq-item">
        <summary>Can I cancel or move my date?</summary>
        <p>Life happens — message us as early as you can through chat or your account, and we'll do our best to rebook you to a new date. Deposit terms for cancellations are outlined at checkout.</p>
      </details>
      <details class="faq-item">
        <summary>What if something gets damaged during the event?</summary>
        <p>Normal wear is expected and never charged. For accidental damage or loss, our team will walk you through it personally — we'd rather talk it through than surprise you with a bill.</p>
      </details>
    </div>

    <div class="contact-panel">
      <div>
        <h2>Planning something soon?</h2>
        <p>Reach out and we'll personally check availability for your date — usually within a day.</p>
      </div>
      <div class="contact-details">
        <div class="contact-row"><span class="k">Area</span><span class="v">Polomolok, South Cotabato</span></div>
        <div class="contact-row"><span class="k">Phone</span><span class="v">0917 000 0000</span></div>
        <div class="contact-row"><span class="k">Email</span><span class="v">hello@rjoco.ph</span></div>
        <div class="contact-row"><span class="k">Hours</span><span class="v">Mon–Sat, 8am–6pm</span></div>
      </div>
    </div>`;

  document.getElementById('hero-cta-book').addEventListener('click', () => document.getElementById('nav-book').click());
  document.getElementById('hero-cta-how').addEventListener('click', () => {
    document.getElementById('how-it-works').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  state.itemTypes = await api('/inventory/item-types');
  document.getElementById('avail-date').addEventListener('change', renderItemGrid);
  renderItemGrid();
}

async function renderItemGrid() {
  const grid = document.getElementById('item-grid');
  const date = document.getElementById('avail-date').value;
  grid.innerHTML = state.itemTypes.map(it => `
    <div class="card item-card" data-id="${it.id}">
      ${itemIcon(it.name)}
      <h3>${it.name}</h3>
      <div class="rate">${it.base_rental_rate ? '₱' + it.base_rental_rate + ' / event' : 'Rate TBD'}</div>
      <div class="availability" id="avail-${it.id}">Checking availability…</div>
    </div>`).join('');

  for (const it of state.itemTypes) {
    try {
      const a = await api(`/inventory/availability?item_type_id=${it.id}&start_date=${date}&end_date=${date}`);
      const el = document.getElementById(`avail-${it.id}`);
      el.textContent = `${a.available} available on ${date}`;
      if (a.available <= 3) el.classList.add('low');
    } catch {
      document.getElementById(`avail-${it.id}`).textContent = 'Unavailable to check right now';
    }
  }
}

async function renderBook() {
  if (!state.user || state.user.role !== 'customer') {
    app.innerHTML = state.portalSession
      ? `<div class="card"><p>Booking is for customer accounts. You're signed in to ${state.portalSession.label} — head to your dashboard to manage bookings there.</p></div>`
      : `<div class="card"><p>Please log in or create an account to book items.</p></div>`;
    renderAccountForm();
    return;
  }

  app.innerHTML = `
    <div class="section-heading"><h2>Book Now</h2></div>
    <div class="card">
      <label>Event date</label>
      <input type="date" id="book-date" required />
      <div id="weather-note" style="margin-top:6px;"></div>

      <label>How would you like to get your order?</label>
      <div class="fulfillment-toggle">
        <label><input type="radio" name="fulfillment" value="pickup" checked /> Pickup (free)</label>
        <label><input type="radio" name="fulfillment" value="delivery" /> Delivery</label>
      </div>

      <div id="delivery-fields" style="display:none;">
        <label>Delivery address</label>
        <input type="text" id="book-address" placeholder="Full delivery address (Poblacion, Polomolok area)" />
        <div id="delivery-fee-note" class="muted"></div>
      </div>

      <div id="booking-lines"></div>
      <button type="button" id="add-line" class="nav-btn">+ Add item</button>

      <div id="addon-picker"></div>

      <details class="terms-box">
        <summary>Terms &amp; Conditions</summary>
        <pre id="terms-text" class="muted">Loading terms…</pre>
      </details>
      <label class="terms-check">
        <input type="checkbox" id="terms-accepted" />
        I have read and agree to the Terms &amp; Conditions above.
      </label>
      <div id="price-summary" class="muted" style="margin-top:12px;"></div>
      <button type="button" class="nav-btn" id="preview-quotation">View quotation breakdown</button>
      <button class="primary" id="submit-booking">Submit Booking Request</button>
      <p class="muted">Your booking is reviewed manually and isn't confirmed until approved — even after payment. For delivery orders, the 50% deposit is only requested once your booking is accepted.</p>
    </div>`;

  // Section 8: show the live T&Cs (rendered from current business_settings)
  // before the customer can submit. The checkbox is a UX gate — the real
  // enforcement is server-side in POST /bookings, since a client can't be trusted.
  fetch(APP_BASE + '/settings/terms').then(r => r.json()).then(({ text }) => {
    document.getElementById('terms-text').textContent = text;
  }).catch(() => {
    document.getElementById('terms-text').textContent = 'Could not load terms — please try again.';
  });

  let deliveryFee = 0;
  const deliveryFields = document.getElementById('delivery-fields');
  const deliveryFeeNote = document.getElementById('delivery-fee-note');
  const addressInput = document.getElementById('book-address');

  document.querySelectorAll('input[name="fulfillment"]').forEach((radio) => {
    radio.addEventListener('change', () => {
      const isDelivery = document.querySelector('input[name="fulfillment"]:checked').value === 'delivery';
      deliveryFields.style.display = isDelivery ? '' : 'none';
      if (!isDelivery) { deliveryFee = 0; deliveryFeeNote.textContent = ''; }
    });
  });

  async function refreshDeliveryQuote() {
    const address = addressInput.value.trim();
    if (!address) { deliveryFeeNote.textContent = ''; deliveryFee = 0; return; }
    deliveryFeeNote.textContent = 'Checking delivery fee…';
    try {
      const quote = await api(`/settings/delivery-quote?address=${encodeURIComponent(address)}`);
      deliveryFee = quote.delivery_fee;
      deliveryFeeNote.textContent = `Delivery fee for this address: ₱${quote.delivery_fee} (${quote.zone})`;
    } catch (err) {
      deliveryFee = 0;
      deliveryFeeNote.textContent = err.message || 'Could not calculate a delivery fee for that address — our team will confirm it manually.';
    }
  }
  addressInput.addEventListener('blur', refreshDeliveryQuote);

  // --- Weather advisory for the chosen event date (new feature) -----------
  // Tables/chairs/tents live outdoors at the event, so a rain/wind/heat
  // heads-up is genuinely useful while the customer is still picking a date.
  const dateInput = document.getElementById('book-date');
  const weatherNote = document.getElementById('weather-note');
  if (dateInput && weatherNote) {
    dateInput.addEventListener('change', async () => {
      const date = dateInput.value;
      if (!date) { weatherNote.innerHTML = ''; return; }
      weatherNote.innerHTML = '<span class="muted">Checking weather for that date…</span>';
      try {
        const forecast = await api(`/weather/forecast?date=${encodeURIComponent(date)}`);
        if (!forecast.available) {
          weatherNote.innerHTML = `<span class="muted">${forecast.reason || ''}</span>`;
          return;
        }
        const f = forecast.forecast;
        const flags = forecast.advisory.map(a =>
          `<div class="weather-flag weather-flag-${a.level}">${a.message}</div>`).join('');
        weatherNote.innerHTML = `
          <div class="weather-card">
            <strong>${f.condition}</strong> — ${f.temp_min_c}°–${f.temp_max_c}°C, rain chance ${f.rain_chance_percent}%, wind up to ${f.wind_gust_kmh} km/h
            ${flags}
          </div>`;
      } catch (err) {
        weatherNote.innerHTML = '';
      }
    });
  }

  // --- Printable quotation for the current draft (new feature) -----------
  // Lets a customer see/download a full breakdown (a "quotation") before
  // they even submit the booking request.
  const previewBtn = document.getElementById('preview-quotation');
  if (previewBtn) {
    previewBtn.addEventListener('click', async () => {
      const event_date = document.getElementById('book-date').value;
      const fulfillment_method = document.querySelector('input[name="fulfillment"]:checked').value;
      const items = [...lines.querySelectorAll('.booking-line')].map(row => ({
        item_type_id: Number(row.querySelector('.line-item').value),
        quantity: Number(row.querySelector('.line-qty').value),
      })).filter(i => i.quantity > 0);
      const addons = [...addonPicker.querySelectorAll('.addon-qty')]
        .map(input => ({ addon_id: Number(input.dataset.addonId), quantity: Number(input.value) }))
        .filter(a => a.quantity > 0);
      if (items.length === 0) { alert('Add at least one item first.'); return; }
      await openQuotationDocument({
        items, addons, fulfillment_method,
        customerName: state.user?.name || '',
        eventDate: event_date || null,
        isReceipt: false,
      });
    });
  }

  // Add-ons: tablecloths, skirting, elastic table covers, etc. — optional,
  // layered on top of whichever tables/chairs are selected above.
  let addonTypes = [];
  try {
    addonTypes = await api('/settings/addons');
  } catch { /* non-fatal — addon picker just won't render */ }
  const addonPicker = document.getElementById('addon-picker');
  if (addonTypes.length > 0) {
    addonPicker.innerHTML = `
      <label style="margin-top:12px;">Add-ons (optional)</label>
      ${addonTypes.map(a => `
        <div class="booking-line">
          <span>${a.name}${a.rate != null ? ` — ₱${a.rate} each` : ''}</span>
          <input type="number" class="addon-qty" data-addon-id="${a.id}" min="0" value="0" placeholder="Qty" />
        </div>`).join('')}`;
  }

  const lines = document.getElementById('booking-lines');
  function addLine() {
    const row = document.createElement('div');
    row.className = 'booking-line';
    row.innerHTML = `
      <select class="line-item">${state.itemTypes.map(it => `<option value="${it.id}">${it.name}</option>`).join('')}</select>
      <input type="number" class="line-qty" min="1" value="1" placeholder="Qty" />`;
    lines.appendChild(row);
  }
  addLine();
  document.getElementById('add-line').addEventListener('click', addLine);

  document.getElementById('submit-booking').addEventListener('click', async () => {
    const event_date = document.getElementById('book-date').value;
    const fulfillment_method = document.querySelector('input[name="fulfillment"]:checked').value;
    const delivery_address = fulfillment_method === 'delivery' ? addressInput.value.trim() : undefined;
    const items = [...lines.querySelectorAll('.booking-line')].map(row => ({
      item_type_id: Number(row.querySelector('.line-item').value),
      quantity: Number(row.querySelector('.line-qty').value),
    }));
    const addons = [...addonPicker.querySelectorAll('.addon-qty')]
      .map(input => ({ addon_id: Number(input.dataset.addonId), quantity: Number(input.value) }))
      .filter(a => a.quantity > 0);

    if (!event_date || items.some(i => !i.quantity)) {
      alert('Please fill in the event date and item quantities.');
      return;
    }
    if (fulfillment_method === 'delivery' && !delivery_address) {
      alert('Please enter a delivery address, or switch to pickup.');
      return;
    }
    if (!document.getElementById('terms-accepted').checked) {
      alert('Please agree to the Terms & Conditions before submitting.');
      return;
    }

    try {
      const booking = await api('/bookings', {
        method: 'POST',
        body: { event_date, fulfillment_method, delivery_address, items, addons, delivery_fee: deliveryFee, terms_accepted: true },
      });
      document.getElementById('price-summary').innerHTML =
        `Request submitted — total due <span class="money">₱${booking.total_due}</span>` +
        (fulfillment_method === 'delivery' ? `, deposit required once accepted <span class="money">₱${booking.deposit_required}</span>` : ' (pickup — no deposit)') +
        `. Awaiting admin approval.`;
    } catch (err) {
      alert(err.message);
    }
  });
}

async function renderMyBookings() {
  if (!state.user || state.user.role !== 'customer') {
    app.innerHTML = state.portalSession
      ? `<div class="card"><p>Bookings here are for customer accounts. You're signed in to ${state.portalSession.label} — head to your dashboard to see bookings there.</p></div>`
      : `<div class="card"><p>Log in to see your bookings.</p></div>`;
    renderAccountForm();
    return;
  }

  app.innerHTML = `<div class="section-heading"><h2>My Bookings</h2></div><div id="bookings-list">Loading…</div>`;
  const bookings = await api('/bookings');
  document.getElementById('bookings-list').innerHTML = bookings.length
    ? bookings.map(b => `
      <div class="card booking-card">
        <div class="booking-card-head">
          <strong>${b.event_date}</strong>
          <span class="status-badge">${b.status}</span>
        </div>
        <div class="muted">Payment: ${b.payment_status} · Total due: <span class="money">₱${b.total_due ?? '—'}</span></div>
        <button class="secondary-btn booking-receipt-btn" data-booking-id="${b.id}">
          ${b.payment_status === 'paid' ? 'View receipt' : 'View quotation'}
        </button>
      </div>`).join('')
    : `<p class="muted">No bookings yet.</p>`;

  document.querySelectorAll('.booking-receipt-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        const booking = await api(`/bookings/${btn.dataset.bookingId}`);
        await openQuotationDocument({
          breakdown: breakdownFromBooking(booking),
          bookingRef: `#${booking.id}`,
          customerName: booking.customer_name,
          eventDate: booking.event_date,
          isReceipt: booking.payment_status === 'paid',
          amountPaid: booking.payment_status === 'paid' ? booking.deposit_paid || booking.total_due : null,
        });
      } catch (err) {
        alert(err.message || 'Could not load that booking.');
      }
    });
  });
}

// Login and Create Account live in their own tabs (rather than stacked
// on the same page) so returning guests aren't scanning past a whole
// registration form just to sign in, and vice versa. Switching tabs is
// a simple show/hide with a fade-in, no page reload.
function renderAccountForm() {
  const wrapper = document.createElement('div');
  wrapper.className = 'card view-fade';

  // Already signed in — just on a different portal (see getPortalSession
  // above). Send them back to their dashboard instead of a login form,
  // since they're not logged out at all.
  if (!state.user && state.portalSession) {
    const p = state.portalSession;
    wrapper.innerHTML = `
      <p>You're already signed in as <strong>${escapeHtml(p.user.name || p.user.email)}</strong> on ${escapeHtml(p.label)}.</p>
      <p class="muted-note">This is the customer-facing site, so it can't show that session here.</p>
      <button class="primary" id="go-dashboard-btn">Go to my dashboard</button>
    `;
    app.appendChild(wrapper);
    document.getElementById('go-dashboard-btn').addEventListener('click', () => {
      window.location.href = p.path;
    });
    return;
  }

  wrapper.innerHTML = state.user ? `
    <p>Signed in as <strong>${escapeHtml(state.user.email)}</strong> (${escapeHtml(state.user.role)})</p>
    <button class="primary" id="logout-btn">Log out</button>
    <hr class="muted-note" style="margin:20px 0;" />
    <h3>Change password</h3>
    <form id="change-password-form">
      <label>Current password</label><input type="password" id="cp-current" autocomplete="current-password" required />
      <label>New password (min. 8 characters)</label><input type="password" id="cp-new" minlength="8" autocomplete="new-password" required />
      <button class="primary" type="submit">Update password</button>
    </form>
  ` : `
    <div class="auth-tabs">
      <button type="button" class="auth-tab active" id="tab-login">Log In</button>
      <button type="button" class="auth-tab" id="tab-register">Create Account</button>
    </div>

    <div id="login-panel" class="auth-panel active view-fade">
      <h3>Welcome back</h3>
      <p class="muted-note">Log in to book items and check your bookings.</p>
      <label>Email</label><input type="email" id="login-email" autocomplete="username" />
      <label>Password</label><input type="password" id="login-password" autocomplete="current-password" />
      <button class="primary" id="login-btn">Log in</button>
    </div>

    <div id="register-panel" class="auth-panel">
      <h3>New here? Let's get you set up</h3>
      <p class="muted-note">Create a free account to start booking.</p>
      <label>Name</label><input type="text" id="reg-name" autocomplete="name" />
      <label>Email</label><input type="email" id="reg-email" autocomplete="email" />
      <label>Contact number</label><input type="text" id="reg-contact" autocomplete="tel" />
      <label>Password</label><input type="password" id="reg-password" autocomplete="new-password" />
      <button class="primary" id="register-btn">Create account</button>
    </div>`;
  app.appendChild(wrapper);

  if (state.user) {
    document.getElementById('logout-btn').addEventListener('click', async () => {
      try { await api('/auth/logout', { method: 'POST', body: { refreshToken: state.refreshToken } }); } catch {}
      clearSession();
      renderView('browse');
    });
    document.getElementById('change-password-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        await api('/auth/password', {
          method: 'PATCH',
          body: {
            currentPassword: document.getElementById('cp-current').value,
            newPassword: document.getElementById('cp-new').value,
          },
        });
        alert('Password updated. Please use your new password next time you log in.');
        e.target.reset();
      } catch (err) { alert(err.message); }
    });
    return;
  }

  const tabLogin = document.getElementById('tab-login');
  const tabRegister = document.getElementById('tab-register');
  const loginPanel = document.getElementById('login-panel');
  const registerPanel = document.getElementById('register-panel');

  function showTab(which) {
    const showingLogin = which === 'login';
    tabLogin.classList.toggle('active', showingLogin);
    tabRegister.classList.toggle('active', !showingLogin);
    loginPanel.classList.toggle('active', showingLogin);
    registerPanel.classList.toggle('active', !showingLogin);
    // Restart the fade-in animation on whichever panel just became visible.
    const shown = showingLogin ? loginPanel : registerPanel;
    shown.classList.remove('view-fade');
    void shown.offsetWidth; // force reflow so the animation replays
    shown.classList.add('view-fade');
  }
  tabLogin.addEventListener('click', () => showTab('login'));
  tabRegister.addEventListener('click', () => showTab('register'));

  document.getElementById('login-btn').addEventListener('click', async () => {
    try {
      const data = await api('/auth/login', {
        method: 'POST',
        body: { email: document.getElementById('login-email').value, password: document.getElementById('login-password').value },
      });
      if (data.user.role !== 'customer') {
        redirectToRolePortal(data);
        return;
      }
      setSession(data);
      renderView('mybookings');
    } catch (err) { alert(err.message); }
  });
  document.getElementById('register-btn').addEventListener('click', async () => {
    try {
      await api('/auth/register', {
        method: 'POST',
        body: {
          name: document.getElementById('reg-name').value,
          email: document.getElementById('reg-email').value,
          contact_number: document.getElementById('reg-contact').value,
          password: document.getElementById('reg-password').value,
        },
      });
      alert('Account created — please log in.');
      showTab('login');
    } catch (err) { alert(err.message); }
  });
}

function renderAccount() {
  app.innerHTML = `<h2>${!state.user && state.portalSession ? 'Dashboard' : 'Account'}</h2>`;
  renderAccountForm();
}

// ---------- Nav wiring ----------

const views = { browse: renderBrowse, book: renderBook, mybookings: renderMyBookings, account: renderAccount };
async function renderView(name) {
  document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
  document.getElementById(`nav-${name}`).classList.add('active');
  await views[name]();
  // Fade the freshly-rendered view in so switching tabs feels smooth
  // instead of an instant content swap.
  app.classList.remove('view-fade');
  void app.offsetWidth; // force reflow so the animation replays every time
  app.classList.add('view-fade');
}
document.getElementById('nav-browse').addEventListener('click', () => renderView('browse'));
document.getElementById('nav-book').addEventListener('click', () => renderView('book'));
document.getElementById('nav-mybookings').addEventListener('click', () => renderView('mybookings'));

const navAccountBtn = document.getElementById('nav-account');
// A signed-in Owner/Admin/Delivery visitor has nothing to log into here —
// swap the label to "Dashboard" and send them straight back rather than
// making them click through a login form for a session they already have.
if (!state.user && state.portalSession) {
  navAccountBtn.textContent = 'Dashboard';
}
navAccountBtn.addEventListener('click', () => {
  if (!state.user && state.portalSession) {
    window.location.href = state.portalSession.path;
    return;
  }
  renderView('account');
});

// ---------- Chat widget (Section H "Chat/inquiry widget") ----------

const chatToggle = document.getElementById('chat-toggle');
const chatWidget = document.getElementById('chat-widget');
const chatMessages = document.getElementById('chat-messages');
const chatForm = document.getElementById('chat-form');
const chatInput = document.getElementById('chat-input');

chatToggle.addEventListener('click', async () => {
  chatWidget.classList.toggle('hidden');
  if (!chatWidget.classList.contains('hidden') && state.user?.role === 'customer') {
    const msgs = await api(`/chat/${state.user.linked_customer_id}`);
    chatMessages.innerHTML = msgs.map(renderChatMsg).join('');
    chatMessages.scrollTop = chatMessages.scrollHeight;
  }
});
document.getElementById('chat-close').addEventListener('click', () => chatWidget.classList.add('hidden'));

function renderChatMsg(m) {
  return `<div class="chat-msg ${m.sender_role}">${escapeHtml(m.message)}</div>`;
}
function escapeHtml(s) {
  const div = document.createElement('div');
  div.textContent = s;
  return div.innerHTML;
}

chatForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!state.user || state.user.role !== 'customer') { alert('Please log in to chat with us.'); return; }
  const message = chatInput.value.trim();
  if (!message) return;
  chatInput.value = '';
  try {
    await api(`/chat/${state.user.linked_customer_id}`, { method: 'POST', body: { message } });
  } catch (err) { alert(err.message); }
});

// ---------- Real-time (Section J) ----------

let socket = null;
function connectSocket() {
  if (!state.accessToken) return;
  if (socket) socket.disconnect();
  socket = io({ auth: { token: state.accessToken } });

  socket.on('chat_message', (msg) => {
    if (state.user?.role === 'customer' && msg.customer_id === state.user.linked_customer_id) {
      chatMessages.insertAdjacentHTML('beforeend', renderChatMsg(msg));
      chatMessages.scrollTop = chatMessages.scrollHeight;
    }
  });

  socket.on('notification', (n) => {
    console.log('Notification:', n.message);
  });

  socket.on('availability_changed', () => {
    const grid = document.getElementById('item-grid');
    if (grid) renderItemGrid();
  });
}

// ---------- Init ----------

// Catches the case where someone is ALREADY logged in (session saved from
// before) and simply reloads/revisits this page — not just a brand-new
// login. Without this, an existing owner/admin/staff session just sits here
// showing "Signed in as..." instead of redirecting, since the login-form
// handler above only fires on an actual login button click.
if (state.user && state.user.role !== 'customer') {
  redirectToRolePortal({ accessToken: state.accessToken, refreshToken: state.refreshToken, user: state.user });
} else {
  if (state.accessToken) connectSocket();
  renderView('browse');
}
