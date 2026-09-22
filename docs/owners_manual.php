<?php
// ============================================================
//  FILE: docs/owners_manual.php — Owner's Manual Web View
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$pageTitle = "Owner's Manual";
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Shared Doc Layout ── */
.doc-wrap {
    max-width: 900px;
    margin: 0 auto;
    padding-bottom: 60px;
}

/* ── Hero Banner ── */
.doc-hero {
    background: linear-gradient(135deg, rgba(0,229,160,0.08), rgba(0,184,255,0.06));
    border: 1px solid rgba(0,229,160,0.22);
    border-radius: 18px;
    padding: 30px 24px 26px;
    margin-bottom: 24px;
    text-align: center;
}
.doc-hero-icon { font-size: 48px; line-height: 1; margin-bottom: 10px; }
.doc-hero h1 {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(28px, 6vw, 44px);
    letter-spacing: 2px;
    margin: 0 0 8px;
    color: var(--text);
}
.doc-hero p {
    font-size: 14px;
    color: var(--muted);
    margin: 0 auto;
    max-width: 520px;
    line-height: 1.7;
}
.doc-version-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 16px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 99px;
    padding: 6px 18px;
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
}

/* ── Quick Nav Pills ── */
.doc-nav {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: center;
    margin-bottom: 28px;
}
.doc-nav a {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 99px;
    padding: 8px 16px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text);
    text-decoration: none;
    transition: border-color .2s, background .2s;
    white-space: nowrap;
}
.doc-nav a:hover { border-color: var(--accent); background: rgba(0,229,160,0.06); color: var(--accent); }

/* ── Section Blocks ── */
.doc-section { margin-bottom: 36px; }
.doc-section-head {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
}
.doc-section-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}
.doc-section-icon.green  { background: rgba(0,229,160,0.12); border: 1px solid rgba(0,229,160,0.25); }
.doc-section-icon.blue   { background: rgba(0,184,255,0.12); border: 1px solid rgba(0,184,255,0.25); }
.doc-section-icon.amber  { background: rgba(245,158,11,0.12); border: 1px solid rgba(245,158,11,0.25); }
.doc-section-icon.purple { background: rgba(167,139,250,0.12); border: 1px solid rgba(167,139,250,0.25); }
.doc-section-icon.red    { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.25); }
.doc-section-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 20px;
    letter-spacing: 1px;
    margin: 0;
}
.doc-section-sub { font-size: 12px; color: var(--muted); margin-top: 2px; }

/* ── Step Cards ── */
.doc-steps { display: flex; flex-direction: column; gap: 10px; }
.doc-step {
    display: flex;
    gap: 14px;
    align-items: flex-start;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 16px;
    transition: border-color .2s;
}
.doc-step:hover { border-color: rgba(0,229,160,0.3); }
.doc-step-num {
    width: 30px; height: 30px;
    border-radius: 50%;
    background: rgba(0,229,160,0.12);
    border: 1.5px solid rgba(0,229,160,0.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700;
    color: var(--accent);
    flex-shrink: 0;
    margin-top: 1px;
    font-family: monospace;
}
.doc-step-title { font-weight: 700; font-size: 14px; margin-bottom: 4px; color: var(--text); }
.doc-step-desc  { font-size: 13px; color: var(--muted); line-height: 1.7; }
.doc-step-desc strong { color: var(--text); }
.doc-step-desc code {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 1px 6px;
    font-size: 11px;
    color: var(--accent);
    font-family: monospace;
}

/* ── Callout Boxes ── */
.doc-callout {
    display: flex;
    gap: 12px;
    padding: 14px 16px;
    border-radius: 12px;
    margin-bottom: 12px;
    font-size: 13px;
    line-height: 1.7;
}
.doc-callout.green  { background: rgba(0,229,160,0.06); border: 1px solid rgba(0,229,160,0.2); }
.doc-callout.blue   { background: rgba(0,184,255,0.06); border: 1px solid rgba(0,184,255,0.2); }
.doc-callout.amber  { background: rgba(245,158,11,0.06); border: 1px solid rgba(245,158,11,0.2); }
.doc-callout.red    { background: rgba(239,68,68,0.06); border: 1px solid rgba(239,68,68,0.2); }
.doc-callout-icon   { font-size: 18px; flex-shrink: 0; line-height: 1.5; }
.doc-callout-text strong { color: var(--text); }

/* ── Info Grid Cards ── */
.doc-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
    margin-bottom: 14px;
}
.doc-info-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 13px;
    transition: border-color .2s;
}
.doc-info-card:hover { border-color: rgba(0,229,160,0.3); }
.doc-info-card-icon   { font-size: 26px; margin-bottom: 7px; }
.doc-info-card-title  { font-weight: 700; font-size: 13px; margin-bottom: 3px; }
.doc-info-card-desc   { font-size: 12px; color: var(--muted); line-height: 1.5; }

/* ── Table ── */
.doc-table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 12px; }
.doc-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.doc-table th {
    text-align: left;
    padding: 10px 14px;
    background: var(--surface2);
    color: var(--muted);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .08em;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.doc-table td {
    padding: 10px 14px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    vertical-align: top;
    line-height: 1.5;
}
.doc-table tr:last-child td { border-bottom: none; }
.doc-table tr:hover td { background: rgba(255,255,255,0.02); }
.doc-table td strong { color: var(--text); }
.doc-table td code {
    background: var(--surface2);
    border-radius: 4px;
    padding: 1px 6px;
    font-size: 11px;
    color: var(--accent);
    font-family: monospace;
}

/* ── Code Block ── */
.doc-code {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 16px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px;
    color: var(--accent);
    line-height: 1.7;
    overflow-x: auto;
    margin-bottom: 12px;
    white-space: pre-wrap;
    word-break: break-word;
}

/* ── Accordion FAQ ── */
.doc-faq-list { display: flex; flex-direction: column; gap: 8px; }
.doc-faq-item {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    transition: border-color .2s;
}
.doc-faq-item.open { border-color: rgba(0,229,160,0.3); }
.doc-faq-q {
    width: 100%; background: none; border: none;
    padding: 14px 16px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    cursor: pointer; color: var(--text); font-size: 14px; font-weight: 600; text-align: left;
    transition: color .2s;
}
.doc-faq-q:hover { color: var(--accent); }
.doc-faq-q-text { flex: 1; }
.doc-faq-chevron { font-size: 11px; color: var(--muted); flex-shrink: 0; transition: transform .25s; }
.doc-faq-item.open .doc-faq-chevron { transform: rotate(180deg); }
.doc-faq-a {
    display: none;
    padding: 12px 16px 14px;
    font-size: 13px;
    color: var(--muted);
    line-height: 1.7;
    border-top: 1px solid var(--border);
}
.doc-faq-item.open .doc-faq-a { display: block; }
.doc-faq-a strong { color: var(--text); }

/* ── Default Values Chips ── */
.doc-defaults-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 10px;
    margin-bottom: 14px;
}
.doc-default-chip {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 13px;
    text-align: center;
}
.doc-default-val  { font-family: 'Bebas Neue', sans-serif; font-size: 22px; color: var(--accent); line-height: 1; }
.doc-default-label { font-size: 11px; color: var(--muted); margin-top: 4px; }

/* ── Responsive ── */
@media (max-width: 600px) {
    .doc-hero { padding: 22px 16px 20px; }
    .doc-nav a { font-size: 11px; padding: 7px 12px; }
    .doc-step { padding: 12px 13px; }
    .doc-table th, .doc-table td { padding: 8px 10px; font-size: 12px; }
    .doc-info-grid { grid-template-columns: 1fr 1fr; }
    .doc-defaults-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 420px) {
    .doc-info-grid { grid-template-columns: 1fr; }
    .doc-hero h1 { font-size: 26px; }
    .doc-nav a { font-size: 11px; padding: 6px 10px; }
}
</style>

<div class="doc-wrap">

    <!-- ══ HERO ══ -->
    <div class="doc-hero">
        <div class="doc-hero-icon">📘</div>
        <h1>Owner's Manual</h1>
        <p>Complete administrator guide for running your Padol Pickleball Court facility — player management, payments, court settings, and more.</p>
        <div class="doc-version-chip">📅 Version 1.0 &nbsp;·&nbsp; April 2026</div>
    </div>

    <!-- ══ QUICK NAV ══ -->
    <div class="doc-nav">
        <a href="#overview">🏓 Overview</a>
        <a href="#first-login">🚀 First Login</a>
        <a href="#dashboard">📊 Dashboard</a>
        <a href="#players">👥 Players</a>
        <a href="#court-settings">⚙️ Court Settings</a>
        <a href="#schedule">📅 Schedule</a>
        <a href="#court-mode">🎮 Court Mode</a>
        <a href="#payments">💳 Payments</a>
        <a href="#reports">📈 Reports</a>
        <a href="#scanner">📺 Open Play Kiosk</a>
        <a href="#security">🔒 Security</a>
        <a href="#backup">🗄️ Backup</a>
        <a href="#troubleshooting">🛠️ Troubleshooting</a>
        <a href="#defaults">📋 Quick Ref</a>
    </div>

    <!-- ══ 1. SYSTEM OVERVIEW ══ -->
    <div class="doc-section" id="overview">
        <div class="doc-section-head">
            <div class="doc-section-icon green">🏓</div>
            <div>
                <div class="doc-section-title">System Overview</div>
                <div class="doc-section-sub">What Padol Pickleball Court does</div>
            </div>
        </div>
        <div class="doc-callout green">
            <div class="doc-callout-icon">💡</div>
            <div class="doc-callout-text">
                <strong>Padol Pickleball Court</strong> is a complete management system for running a modern pickleball facility — from player registration and credit wallets to court reservations, live queues, and revenue reports.
            </div>
        </div>
        <div class="doc-info-grid">
            <div class="doc-info-card"><div class="doc-info-card-icon">👤</div><div class="doc-info-card-title">Player Management</div><div class="doc-info-card-desc">Registration, verification, banning, account adjustments</div></div>
            <div class="doc-info-card"><div class="doc-info-card-icon">💰</div><div class="doc-info-card-title">Credit Wallet</div><div class="doc-info-card-desc">Players load credits like prepaid cards to play games</div></div>
            <div class="doc-info-card"><div class="doc-info-card-icon">🎮</div><div class="doc-info-card-title">Open Play Queue</div><div class="doc-info-card-desc">Players join a live waiting list and are drawn into balanced games</div></div>
            <div class="doc-info-card"><div class="doc-info-card-icon">📅</div><div class="doc-info-card-title">Reservations</div><div class="doc-info-card-desc">Players book specific time slots in advance</div></div>
            <div class="doc-info-card"><div class="doc-info-card-icon">💳</div><div class="doc-info-card-title">Payment Processing</div><div class="doc-info-card-desc">Admin approves top-up requests via GCash, bank, or cash</div></div>
            <div class="doc-info-card"><div class="doc-info-card-icon">📈</div><div class="doc-info-card-title">Reports & Analytics</div><div class="doc-info-card-desc">Revenue tracking, player statistics, peak hours analysis</div></div>
        </div>
        <div class="doc-callout blue">
            <div class="doc-callout-icon">🔄</div>
            <div class="doc-callout-text">
                <strong>Key Business Flow:</strong> Players register → load credits via top-up → pay through GCash/Bank/Cash → admin approves → credits appear in wallet → players join the Open Play queue to play → system deducts <strong>₱10.00 credits per game</strong> (configurable).
            </div>
        </div>
    </div>

    <!-- ══ 2. GETTING STARTED ══ -->
    <div class="doc-section" id="first-login">
        <div class="doc-section-head">
            <div class="doc-section-icon green">🚀</div>
            <div>
                <div class="doc-section-title">Getting Started — First Login</div>
                <div class="doc-section-sub">Initial setup checklist before opening to players</div>
            </div>
        </div>
        <div class="doc-steps">
            <div class="doc-step">
                <div class="doc-step-num">1</div>
                <div>
                    <div class="doc-step-title">Access the Admin Panel</div>
                    <div class="doc-step-desc">Go to your Railway deployment URL and append <code>/pickleball/admin/dashboard.php</code>. Example: <code>https://falcon-production-1.up.railway.app/pickleball/admin/dashboard.php</code></div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">2</div>
                <div>
                    <div class="doc-step-title">Login with Super Admin Credentials</div>
                    <div class="doc-step-desc">Use the username and password provided by your developer. Click <strong>Sign In</strong> to reach the Admin Dashboard.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">3</div>
                <div>
                    <div class="doc-step-title">Change Your Password Immediately</div>
                    <div class="doc-step-desc">Go to <strong>Profile → Change Password</strong>. New password must be <strong>8+ characters</strong> with at least <strong>1 uppercase letter</strong> and <strong>1 number</strong>. Example: <code>Padol2026Pro</code></div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">4</div>
                <div>
                    <div class="doc-step-title">Configure Court Settings</div>
                    <div class="doc-step-desc">Go to <strong>Admin → Court Settings</strong>. Set your court name, credit cost per game (default: ₱10), game duration (default: 60 min), and max players per game (default: 4).</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">5</div>
                <div>
                    <div class="doc-step-title">Add Payment Methods</div>
                    <div class="doc-step-desc">Go to <strong>Admin → Payment Settings</strong>. Add your GCash account with QR code, bank transfer details, and optional cash payment option.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">6</div>
                <div>
                    <div class="doc-step-title">Set Court Hours & Mode</div>
                    <div class="doc-step-desc">Go to <strong>Admin → Court Mode</strong>. Set opening hours (e.g., 6 AM–11 PM), and configure Open Play vs Reservation schedules for each time slot.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ 3. DASHBOARD ══ -->
    <div class="doc-section" id="dashboard">
        <div class="doc-section-head">
            <div class="doc-section-icon blue">📊</div>
            <div>
                <div class="doc-section-title">Admin Dashboard Guide</div>
                <div class="doc-section-sub">Your command center — what every metric means</div>
            </div>
        </div>
        <div class="doc-callout blue">
            <div class="doc-callout-icon">🖥️</div>
            <div class="doc-callout-text">The dashboard gives you a real-time overview of your entire facility — live games, pending approvals, revenue, and player stats — all in one place.</div>
        </div>
        <div class="doc-table-wrap">
            <table class="doc-table">
                <thead><tr><th>Metric</th><th>What It Shows</th><th>Why It Matters</th></tr></thead>
                <tbody>
                    <tr><td><strong>Total Players</strong></td><td>All registered players</td><td>Growth indicator</td></tr>
                    <tr><td><strong>New This Week</strong></td><td>Recent registrations</td><td>Player acquisition rate</td></tr>
                    <tr><td><strong>Active Courts</strong></td><td>Courts currently open</td><td>Current operations</td></tr>
                    <tr><td><strong>Live Sessions</strong></td><td>Games happening right now</td><td>Real-time activity</td></tr>
                    <tr><td><strong>Games Today</strong></td><td>Completed games so far today</td><td>Daily volume</td></tr>
                    <tr><td><strong>Pending Top-Ups</strong></td><td>Awaiting your approval</td><td>Revenue in limbo</td></tr>
                    <tr><td><strong>Pending Reservations</strong></td><td>Bookings waiting to confirm</td><td>Booking queue</td></tr>
                    <tr><td><strong>Today's Revenue</strong></td><td>₱ earned from games today</td><td>Daily income</td></tr>
                    <tr><td><strong>Monthly Revenue</strong></td><td>₱ earned this month</td><td>Performance tracking</td></tr>
                </tbody>
            </table>
        </div>
        <div class="doc-callout amber">
            <div class="doc-callout-icon">⚠️</div>
            <div class="doc-callout-text">
                <strong>Pending Top-Ups</strong> are the most time-sensitive item — players cannot play until you approve their credit request. Check this first every morning.
            </div>
        </div>
    </div>

    <!-- ══ 4. PLAYERS ══ -->
    <div class="doc-section" id="players">
        <div class="doc-section-head">
            <div class="doc-section-icon purple">👥</div>
            <div>
                <div class="doc-section-title">Managing Players</div>
                <div class="doc-section-sub">Verify, ban, reset passwords, adjust balances</div>
            </div>
        </div>
        <div class="doc-steps">
            <div class="doc-step">
                <div class="doc-step-num">✅</div>
                <div>
                    <div class="doc-step-title">Verify a Player</div>
                    <div class="doc-step-desc">Unverified players <strong>cannot play games or make reservations</strong>. Go to <strong>Admin → Players</strong>, find players with ❌ in the Verified column, click their row, then click <strong>"Verify Player"</strong>. Player receives a notification and can now play.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">🚫</div>
                <div>
                    <div class="doc-step-title">Ban a Player</div>
                    <div class="doc-step-desc">For rule violations (violence, theft, harassment, non-payment). Click <strong>"Ban"</strong> on the player's row, enter a reason (e.g., <code>Aggressive behavior on court</code>), confirm. Banned players are immediately locked out — can't login, can't join Open Play, can't make reservations.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">🔓</div>
                <div>
                    <div class="doc-step-title">Unban a Player</div>
                    <div class="doc-step-desc">Find the banned player in the list (✅ in Banned column), click <strong>"Unban"</strong>, confirm. Player can login again and play.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">🔑</div>
                <div>
                    <div class="doc-step-title">Reset Player Password</div>
                    <div class="doc-step-desc">Click <strong>"Reset Password"</strong> on the player's row. Enter a temporary password (e.g., <code>TempPass123</code>). Player receives notification and is forced to change it on next login.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">💰</div>
                <div>
                    <div class="doc-step-title">Adjust Player Balance</div>
                    <div class="doc-step-desc">Click <strong>"Adjust Balance"</strong> → select <strong>Add</strong> or <strong>Deduct Credits</strong> → enter amount and reason. Examples: <code>Loyalty reward +₱100</code>, <code>Refund for cancelled reservation +₱150</code>, <code>Equipment damage -₱200</code>. Player is notified immediately.</div>
                </div>
            </div>
        </div>
        <div class="doc-callout green">
            <div class="doc-callout-icon">📥</div>
            <div class="doc-callout-text">
                <strong>Export CSV:</strong> Go to Admin → Players → click <strong>"Export to CSV"</strong> at the bottom. Downloads <code>players_YYYY-MM-DD.csv</code> with all player data for accounting.
            </div>
        </div>
    </div>

    <!-- ══ 5. COURT SETTINGS ══ -->
    <div class="doc-section" id="court-settings">
        <div class="doc-section-head">
            <div class="doc-section-icon amber">⚙️</div>
            <div>
                <div class="doc-section-title">Court Settings & Configuration</div>
                <div class="doc-section-sub">Game cost, duration, players, and pass settings</div>
            </div>
        </div>
        <div class="doc-defaults-grid">
            <div class="doc-default-chip"><div class="doc-default-val">₱10</div><div class="doc-default-label">Default Credit Cost/Game</div></div>
            <div class="doc-default-chip"><div class="doc-default-val">60</div><div class="doc-default-label">Default Game Duration (min)</div></div>
            <div class="doc-default-chip"><div class="doc-default-val">4</div><div class="doc-default-label">Default Players per Game</div></div>
            <div class="doc-default-chip"><div class="doc-default-val">5</div><div class="doc-default-label">Default Warmup Time (min)</div></div>
            <div class="doc-default-chip"><div class="doc-default-val">8h</div><div class="doc-default-label">Default Pass Duration</div></div>
        </div>
        <div class="doc-table-wrap">
            <table class="doc-table">
                <thead><tr><th>Setting</th><th>Description</th><th>Example</th></tr></thead>
                <tbody>
                    <tr><td><strong>Court Name</strong></td><td>Your facility name</td><td><code>Padol Court</code></td></tr>
                    <tr><td><strong>Credit Cost Per Game</strong></td><td>Credits each player pays per game</td><td><code>₱10.00</code> or <code>₱15.00</code></td></tr>
                    <tr><td><strong>Game Duration</strong></td><td>How long each game lasts</td><td><code>45</code>, <code>60</code>, or <code>90</code> min</td></tr>
                    <tr><td><strong>Warmup Time</strong></td><td>Grace period before game starts</td><td><code>5</code> min</td></tr>
                    <tr><td><strong>Players Per Game</strong></td><td>Min 2, Max 40 (standard = 4 for doubles)</td><td><code>4</code></td></tr>
                    <tr><td><strong>Session Duration</strong></td><td>How long a player stays active in the Open Play queue</td><td><code>8</code> hours</td></tr>
                </tbody>
            </table>
        </div>
        <div class="doc-callout amber">
            <div class="doc-callout-icon">⚠️</div>
            <div class="doc-callout-text"><strong>Changing game duration</strong> only affects future games — games already in progress are not affected.</div>
        </div>
    </div>

    <!-- ══ 6. SCHEDULE ══ -->
    <div class="doc-section" id="schedule">
        <div class="doc-section-head">
            <div class="doc-section-icon blue">📅</div>
            <div>
                <div class="doc-section-title">Schedule & Reservations</div>
                <div class="doc-section-sub">Approve, reject, and create bookings</div>
            </div>
        </div>
        <div class="doc-callout blue">
            <div class="doc-callout-icon">📅</div>
            <div class="doc-callout-text">The schedule is a calendar showing all court reservations. <strong>Green slots</strong> = available, <strong>Blue slots</strong> = reserved (pending or confirmed), <strong>Gray slots</strong> = outside operating hours.</div>
        </div>
        <div class="doc-steps">
            <div class="doc-step">
                <div class="doc-step-num">✅</div>
                <div>
                    <div class="doc-step-title">Approving a Reservation</div>
                    <div class="doc-step-desc">Click on a blue/pending slot → review player details → click <strong>"✅ Approve"</strong>. Player receives notification. Slot turns confirmed (darker blue). Note: Approving does not charge the player immediately.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">❌</div>
                <div>
                    <div class="doc-step-title">Rejecting a Reservation</div>
                    <div class="doc-step-desc">Click reservation → click <strong>"❌ Reject"</strong> → optionally add reason (e.g., <code>Court maintenance scheduled</code>). Player is notified and slot opens up again.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">🔒</div>
                <div>
                    <div class="doc-step-title">Block a Slot (Admin-Only)</div>
                    <div class="doc-step-desc">Click an empty/green slot → fill in label (e.g., <code>Court Maintenance 2–4 PM</code>) → duration → click <strong>"Create"</strong>. Slot is blocked from player bookings. Use for maintenance, staff training, or events.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ 7. COURT MODE ══ -->
    <div class="doc-section" id="court-mode">
        <div class="doc-section-head">
            <div class="doc-section-icon green">🎮</div>
            <div>
                <div class="doc-section-title">Court Mode — Open Play vs Reservation</div>
                <div class="doc-section-sub">Control who can use the court and when</div>
            </div>
        </div>
        <div class="doc-table-wrap">
            <table class="doc-table">
                <thead><tr><th>Mode</th><th>What Happens</th><th>Best For</th></tr></thead>
                <tbody>
                    <tr><td><strong>🎮 Open Play</strong></td><td>Players join the queue → get drawn into a game → play when their turn comes</td><td>Evenings, weekends, walk-in traffic</td></tr>
                    <tr><td><strong>📅 Reservation</strong></td><td>Only pre-booked players can use the court in that time slot</td><td>Daytime, serious players, tournaments</td></tr>
                </tbody>
            </table>
        </div>
        <div class="doc-callout green">
            <div class="doc-callout-icon">💡</div>
            <div class="doc-callout-text">
                When you switch a time slot to <strong>Open Play</strong>, all players automatically receive a push notification announcing when walk-in play is available.
            </div>
        </div>
        <div style="margin-bottom:12px;"><strong style="font-size:13px;color:var(--text);">Example Rules to Set Up:</strong></div>
        <div class="doc-code">Every Friday · 8:00 PM → 11:59 PM · Open Play · "Friday Night Open Play"
Every Day · 6:00 AM → 8:00 PM · Reservation · "Business hours — bookings only"
December 25 · Whole Day · Reservation · "Holiday — reservations only"</div>
    </div>

    <!-- ══ 8. PAYMENTS ══ -->
    <div class="doc-section" id="payments">
        <div class="doc-section-head">
            <div class="doc-section-icon amber">💳</div>
            <div>
                <div class="doc-section-title">Payment Settings & Top-Up Management</div>
                <div class="doc-section-sub">Configure payment methods and process credit requests</div>
            </div>
        </div>
        <div class="doc-callout amber">
            <div class="doc-callout-icon">💡</div>
            <div class="doc-callout-text"><strong>Top-Up Flow:</strong> Player submits request with payment screenshot → you review proof → approve → credits added to wallet → player can play.</div>
        </div>
        <div class="doc-steps">
            <div class="doc-step">
                <div class="doc-step-num">1</div>
                <div>
                    <div class="doc-step-title">Add GCash Payment Method</div>
                    <div class="doc-step-desc">Go to <strong>Admin → Payment Settings → Add Payment Method</strong>. Enter method name <code>GCash</code>, account name, your GCash mobile number, instructions, and upload your GCash QR code image. Check <strong>Is Active</strong> and save.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">2</div>
                <div>
                    <div class="doc-step-title">Add Bank Transfer</div>
                    <div class="doc-step-desc">Same as above with method name <code>Bank Transfer</code> and your bank account number. Add instructions like: <code>Reference: Your Username</code>.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">3</div>
                <div>
                    <div class="doc-step-title">Process Pending Top-Up Requests</div>
                    <div class="doc-step-desc">Go to <strong>Admin → Top-Up Requests</strong>. Review each request: ✅ amount matches? ✅ reference number valid? ✅ screenshot clear? ✅ not a duplicate? Then click <strong>Approve</strong> or <strong>Reject</strong> with a reason.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">⚡</div>
                <div>
                    <div class="doc-step-title">Bulk Approve Multiple Requests</div>
                    <div class="doc-step-desc">Check the <strong>Select All</strong> checkbox (or pick individual ones) → click <strong>"Bulk Approve"</strong> → confirm. Saves time when you have many pending requests.</div>
                </div>
            </div>
        </div>
        <div class="doc-callout red">
            <div class="doc-callout-icon">🚨</div>
            <div class="doc-callout-text">
                <strong>Watch for fraud:</strong> Same reference number used twice = reject the duplicate. Amount mismatch = contact player for clarification. No screenshot = reject with reason <code>No payment proof provided. Please resubmit with screenshot.</code>
            </div>
        </div>
    </div>

    <!-- ══ 9. REPORTS ══ -->
    <div class="doc-section" id="reports">
        <div class="doc-section-head">
            <div class="doc-section-icon blue">📈</div>
            <div>
                <div class="doc-section-title">Reports & Analytics</div>
                <div class="doc-section-sub">Revenue, player stats, and peak hours</div>
            </div>
        </div>
        <div class="doc-table-wrap">
            <table class="doc-table">
                <thead><tr><th>Report</th><th>What It Shows</th><th>Use It To</th></tr></thead>
                <tbody>
                    <tr><td><strong>Monthly Summary</strong></td><td>Games completed/cancelled, total revenue, unique players</td><td>Month-over-month performance</td></tr>
                    <tr><td><strong>Daily Breakdown</strong></td><td>Revenue and session count per day</td><td>Spot patterns (busy Fridays?)</td></tr>
                    <tr><td><strong>Top 10 Players</strong></td><td>Most active players by games played and credits spent</td><td>Identify VIPs for loyalty rewards</td></tr>
                    <tr><td><strong>Peak Hours</strong></td><td>Chart of what time of day is busiest</td><td>Schedule staff, plan maintenance</td></tr>
                    <tr><td><strong>Revenue by Court</strong></td><td>Sessions and income per court</td><td>Identify most popular courts</td></tr>
                    <tr><td><strong>Credits Flow</strong></td><td>Total distributed vs spent vs sitting in wallets</td><td>Cash flow visibility</td></tr>
                </tbody>
            </table>
        </div>
        <div class="doc-callout green">
            <div class="doc-callout-icon">📥</div>
            <div class="doc-callout-text">Most reports have an <strong>"Export to CSV"</strong> button. Download and open in Excel or Google Sheets for accounting and board presentations.</div>
        </div>
    </div>

    <!-- ══ 10. OPEN PLAY KIOSK ══ -->
    <div class="doc-section" id="scanner">
        <div class="doc-section-head">
            <div class="doc-section-icon green">📺</div>
            <div>
                <div class="doc-section-title">Open Play Kiosk &amp; Queue Operations</div>
                <div class="doc-section-sub">Running the live queue and the TV board</div>
            </div>
        </div>
        <div class="doc-callout green">
            <div class="doc-callout-icon">📺</div>
            <div class="doc-callout-text">QR scanning has been retired. Staff run the floor from <strong>Open Play Control</strong> (<code>/staff/open_play_control.php</code>) — adding players, drawing rounds, and starting or finishing games. The <strong>Open Play TV Kiosk</strong> (<code>/staff/open_play_kiosk.php</code>) is the read-only big-screen view players watch for their name. Both read the same queue, so what staff see and what the TV shows can never disagree.</div>
        </div>
        <div class="doc-table-wrap">
            <table class="doc-table">
                <thead><tr><th>Situation</th><th>Cause</th><th>Fix</th></tr></thead>
                <tbody>
                    <tr><td><code>Player isn't in the queue</code></td><td>Never joined, or their join is still pending approval</td><td>Add them from Open Play Control → Add Player, or approve their pending request</td></tr>
                    <tr><td><code>Player not found</code></td><td>Not registered in the system</td><td>Direct player to register at the landing page first</td></tr>
                    <tr><td><code>Insufficient balance</code></td><td>Player has &lt; ₱10 credits</td><td>Direct player to top up wallet; they can join once approved</td></tr>
                    <tr><td><code>No game can be drawn</code></td><td>Not enough waiting players, or every court is busy/unavailable</td><td>Check the kiosk court tiles — a court marked Unavailable is in maintenance, reserved, or set to Reservations Only</td></tr>
                    <tr><td><code>TV board looks frozen</code></td><td>Kiosk lost its connection to the server</td><td>An "offline" badge appears bottom-left after ~12s; refresh the TV browser tab</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══ 11. SECURITY ══ -->
    <div class="doc-section" id="security">
        <div class="doc-section-head">
            <div class="doc-section-icon red">🔒</div>
            <div>
                <div class="doc-section-title">Security & User Roles</div>
                <div class="doc-section-sub">Roles, session timeouts, and password rules</div>
            </div>
        </div>
        <div class="doc-table-wrap">
            <table class="doc-table">
                <thead><tr><th>Role</th><th>Can Do</th><th>Cannot Do</th></tr></thead>
                <tbody>
                    <tr><td><strong>Super Admin</strong></td><td>Everything — full system access</td><td>Nothing restricted</td></tr>
                    <tr><td><strong>Admin</strong></td><td>Dashboard, players, reservations, reports</td><td>Delete system, create other admins</td></tr>
                    <tr><td><strong>Player</strong></td><td>Register, play, book courts, check balance</td><td>Access any admin function</td></tr>
                </tbody>
            </table>
        </div>
        <div class="doc-callout red">
            <div class="doc-callout-icon">⏱️</div>
            <div class="doc-callout-text"><strong>Session timeout:</strong> You are automatically logged out after <strong>20 minutes of inactivity</strong>. A warning appears at 18 minutes. Always log out manually when stepping away — never leave your computer unlocked while logged in.</div>
        </div>
        <div style="margin-bottom:8px;font-weight:700;font-size:13px;">Password Requirements</div>
        <div class="doc-steps">
            <div class="doc-step">
                <div class="doc-step-num">✅</div>
                <div>
                    <div class="doc-step-title">Valid Password Examples</div>
                    <div class="doc-step-desc"><code>SecurePass123</code> &nbsp;·&nbsp; <code>Padol2026Pro</code> &nbsp;·&nbsp; Must be 8+ characters with at least 1 uppercase letter (A–Z) and 1 number (0–9). Cannot be the same as your last 5 passwords.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">❌</div>
                <div>
                    <div class="doc-step-title">Invalid Password Examples</div>
                    <div class="doc-step-desc"><code>password123</code> (no uppercase) &nbsp;·&nbsp; <code>PassWord</code> (no number) &nbsp;·&nbsp; <code>Pass1</code> (too short — only 5 characters)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ 12. BACKUP ══ -->
    <div class="doc-section" id="backup">
        <div class="doc-section-head">
            <div class="doc-section-icon purple">🗄️</div>
            <div>
                <div class="doc-section-title">Backup & Data Management</div>
                <div class="doc-section-sub">Automatic daily backups via Railway — no action needed</div>
            </div>
        </div>
        <div class="doc-callout green">
            <div class="doc-callout-icon">✅</div>
            <div class="doc-callout-text"><strong>Automatic backups are already configured.</strong> Railway backs up your database every day at 2 AM and keeps 30 days of history. You don't need to do anything — it happens automatically.</div>
        </div>
        <div class="doc-steps">
            <div class="doc-step">
                <div class="doc-step-num">🔍</div>
                <div>
                    <div class="doc-step-title">Verify Backups Are Working</div>
                    <div class="doc-step-desc">Log into <strong>Railway.app</strong> → your project → click <strong>PostgreSQL</strong> → click <strong>Backups tab</strong>. You should see a list of daily backups with status <strong>"Ready"</strong> or <strong>"Completed"</strong>. If any show <strong>"Failed"</strong>, contact your developer.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">📥</div>
                <div>
                    <div class="doc-step-title">Export Player Data (Simple)</div>
                    <div class="doc-step-desc">Go to <strong>Admin → Players → Export to CSV</strong>. Downloads <code>players_YYYY-MM-DD.csv</code> with: ID, Username, Name, Email, Phone, Balance, Games Played, Join Date.</div>
                </div>
            </div>
        </div>
        <div class="doc-callout amber">
            <div class="doc-callout-icon">⚠️</div>
            <div class="doc-callout-text"><strong>Data Deletion Requests:</strong> Players may request account deletion. Go to Admin → Players → find player → click "Delete Account" → confirm. Transaction history may be retained for auditing purposes.</div>
        </div>
    </div>

    <!-- ══ 13. TROUBLESHOOTING ══ -->
    <div class="doc-section" id="troubleshooting">
        <div class="doc-section-head">
            <div class="doc-section-icon red">🛠️</div>
            <div>
                <div class="doc-section-title">Troubleshooting & FAQs</div>
                <div class="doc-section-sub">Common issues and how to fix them</div>
            </div>
        </div>
        <div class="doc-faq-list" id="doc-faq-list">
            <?php
            $faqs = [
                ["Database Connection Error", "Go to Railway.app and check if the service is up. Try refreshing the page (Ctrl+R). If still down, go to Railway → Padol Pickleball project → click Redeploy and wait 2 minutes."],
                ["Player Can't Login", "Go to Admin → Players → search for the player. Check if they are Banned (✅ = yes, ❌ = no) or Unverified. Unban or verify as needed. If still failing, use Reset Password and send them a temporary password."],
                ["Open Play TV Kiosk Not Updating", "The kiosk polls the server every 4 seconds. If an \u0022live board offline\u0022 badge appears, refresh the browser tab on the TV. Check the TV\u0027s network connection. Confirm an Open Play event is actually running under Open Play Control — with no live event the board shows an idle state by design."],
                ["Player Balance Shows Wrong Amount", "Refresh the page first. If still wrong, go to Admin → Players → find the player → Adjust Balance → manually correct the amount → add reason 'Balance correction — sync issue'."],
                ["Top-Up Request Stuck as Pending", "Go to Admin → Top-Up Requests → find the request. Check the payment screenshot. If valid, click Approve. If payment was not received, click Reject and add a reason for the player."],
                ["Reservation Not Showing in Schedule", "Go to Admin → Schedule → check the correct date. Look for blue (pending) or dark blue (confirmed) slots. If not found, ask the player for their confirmation email with the date and time, then check if they may have booked a different court."],
                ["System Running Slow / Laggy", "Clear browser cache (Ctrl+Shift+Delete). Try a different browser. Check Railway dashboard for high CPU/memory usage. If above 80%, click Redeploy. If still slow, contact your developer for database optimization."],
                ["Can I have multiple admin accounts?", "Yes. Contact your developer to create additional admin accounts for staff members."],
                ["Can I change the credit cost mid-month?", "Yes. Go to Court Settings and change the Daily Credit Cost Per Game. It only affects new games — it does not refund or retroactively change previous games."],
                ["What if a player disputes a charge?", "Go to Admin → Players → find the player → Adjust Balance → add credits with reason 'Dispute refund — [date and game]'."],
            ];
            foreach ($faqs as $i => $faq): ?>
            <div class="doc-faq-item" id="dfaq-<?= $i ?>">
                <button class="doc-faq-q" onclick="toggleDocFaq(<?= $i ?>)">
                    <span class="doc-faq-q-text"><?= htmlspecialchars($faq[0]) ?></span>
                    <span class="doc-faq-chevron">▼</span>
                </button>
                <div class="doc-faq-a"><?= htmlspecialchars($faq[1]) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══ 14. QUICK REFERENCE ══ -->
    <div class="doc-section" id="defaults">
        <div class="doc-section-head">
            <div class="doc-section-icon blue">📋</div>
            <div>
                <div class="doc-section-title">Quick Reference</div>
                <div class="doc-section-sub">Default values and commonly used URLs</div>
            </div>
        </div>
        <div class="doc-defaults-grid">
            <div class="doc-default-chip"><div class="doc-default-val">₱10</div><div class="doc-default-label">Credit Cost per Game</div></div>
            <div class="doc-default-chip"><div class="doc-default-val">60</div><div class="doc-default-label">Game Duration (min)</div></div>
            <div class="doc-default-chip"><div class="doc-default-val">4</div><div class="doc-default-label">Players per Game</div></div>
            <div class="doc-default-chip"><div class="doc-default-val">5</div><div class="doc-default-label">Warmup Time (min)</div></div>
            <div class="doc-default-chip"><div class="doc-default-val">8h</div><div class="doc-default-label">QR Pass Duration</div></div>
            <div class="doc-default-chip"><div class="doc-default-val">20</div><div class="doc-default-label">Session Timeout (min)</div></div>
        </div>
        <div class="doc-table-wrap">
            <table class="doc-table">
                <thead><tr><th>Page</th><th>URL Path</th></tr></thead>
                <tbody>
                    <tr><td>Admin Dashboard</td><td><code>/admin/dashboard.php</code></td></tr>
                    <tr><td>Players Management</td><td><code>/admin/players.php</code></td></tr>
                    <tr><td>Court Settings</td><td><code>/admin/court_settings.php</code></td></tr>
                    <tr><td>Schedule & Reservations</td><td><code>/admin/schedule.php</code></td></tr>
                    <tr><td>Court Mode</td><td><code>/admin/court_mode.php</code></td></tr>
                    <tr><td>Payment Settings</td><td><code>/admin/payment_settings.php</code></td></tr>
                    <tr><td>Top-Up Requests</td><td><code>/admin/topup_requests.php</code></td></tr>
                    <tr><td>Reports</td><td><code>/admin/reports.php</code></td></tr>
                    <tr><td>Open Play Control</td><td><code>/staff/open_play_control.php</code></td></tr>
                    <tr><td>Open Play TV Kiosk</td><td><code>/staff/open_play_kiosk.php</code></td></tr>
                </tbody>
            </table>
        </div>
        <div style="text-align:center;margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">
            <a href="<?= APP_URL ?>/docs/maintenance_guide.php" class="btn-outline btn-sm">🛠️ Maintenance Guide</a>
            <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-primary btn-sm">← Back to Dashboard</a>
        </div>
    </div>

</div><!-- /.doc-wrap -->

<script nonce="<?= getCspNonce() ?>">
function toggleDocFaq(i) {
    const item = document.getElementById('dfaq-' + i);
    if (!item) return;
    const isOpen = item.classList.contains('open');
    document.querySelectorAll('.doc-faq-item.open').forEach(el => el.classList.remove('open'));
    if (!isOpen) item.classList.add('open');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>