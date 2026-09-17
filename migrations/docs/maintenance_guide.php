<?php
// ============================================================
//  FILE: docs/maintenance_guide.php — Maintenance Guide Web View
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$pageTitle = 'Maintenance Guide';
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
    background: linear-gradient(135deg, rgba(245,158,11,0.08), rgba(0,184,255,0.06));
    border: 1px solid rgba(245,158,11,0.22);
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
    background: rgba(245,158,11,0.12);
    border: 1.5px solid rgba(245,158,11,0.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700;
    color: #f59e0b;
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

/* ── Checklist ── */
.doc-checklist {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 14px;
}
.doc-check-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 13px;
    line-height: 1.6;
    transition: border-color .2s;
}
.doc-check-item:hover { border-color: rgba(0,229,160,0.3); }
.doc-check-box {
    width: 20px; height: 20px;
    border: 2px solid rgba(0,229,160,0.4);
    border-radius: 5px;
    background: rgba(0,229,160,0.06);
    flex-shrink: 0;
    margin-top: 1px;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px;
    color: var(--accent);
}
.doc-check-label { color: var(--text); }
.doc-check-label strong { color: var(--text); }
.doc-check-label span { color: var(--muted); font-size: 12px; display: block; margin-top: 2px; }

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

/* ── Frequency Badge ── */
.freq-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .04em;
    margin-bottom: 12px;
}
.freq-badge.daily  { background: rgba(0,229,160,0.1); color: var(--accent); border: 1px solid rgba(0,229,160,0.25); }
.freq-badge.weekly { background: rgba(0,184,255,0.1); color: var(--accent2); border: 1px solid rgba(0,184,255,0.25); }
.freq-badge.monthly{ background: rgba(245,158,11,0.1); color: #f59e0b; border: 1px solid rgba(245,158,11,0.25); }
.freq-badge.urgent { background: rgba(239,68,68,0.1); color: #ef4444; border: 1px solid rgba(239,68,68,0.25); }

/* ── Email template ── */
.doc-email-template {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px;
    color: var(--muted);
    line-height: 1.8;
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-word;
    margin-bottom: 12px;
}
.doc-email-template strong { color: var(--accent); }

/* ── Accordion FAQ ── */
.doc-faq-list { display: flex; flex-direction: column; gap: 8px; }
.doc-faq-item {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    transition: border-color .2s;
}
.doc-faq-item.open { border-color: rgba(245,158,11,0.35); }
.doc-faq-q {
    width: 100%; background: none; border: none;
    padding: 14px 16px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    cursor: pointer; color: var(--text); font-size: 14px; font-weight: 600; text-align: left;
    transition: color .2s;
}
.doc-faq-q:hover { color: #f59e0b; }
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
.doc-faq-a code {
    background: var(--surface2);
    border-radius: 4px;
    padding: 1px 6px;
    font-size: 11px;
    color: var(--accent);
    font-family: monospace;
}

/* ── Responsive ── */
@media (max-width: 600px) {
    .doc-hero { padding: 22px 16px 20px; }
    .doc-nav a { font-size: 11px; padding: 7px 12px; }
    .doc-step { padding: 12px 13px; }
    .doc-table th, .doc-table td { padding: 8px 10px; font-size: 12px; }
    .doc-check-item { padding: 10px 12px; }
}
@media (max-width: 420px) {
    .doc-hero h1 { font-size: 26px; }
    .doc-nav a { font-size: 11px; padding: 6px 10px; }
}
</style>

<div class="doc-wrap">

    <!-- ══ HERO ══ -->
    <div class="doc-hero">
        <div class="doc-hero-icon">🛠️</div>
        <h1>Maintenance Guide</h1>
        <p>Daily, weekly, and monthly operational checklists to keep your Falcon Pickleball Court system running smoothly and securely.</p>
        <div class="doc-version-chip">📅 Version 1.0 &nbsp;·&nbsp; April 2026</div>
    </div>

    <!-- ══ QUICK NAV ══ -->
    <div class="doc-nav">
        <a href="#daily">☀️ Daily (5 min)</a>
        <a href="#weekly">📆 Weekly (30 min)</a>
        <a href="#monthly">🗓️ Monthly (1 hr)</a>
        <a href="#backups">🗄️ Backups</a>
        <a href="#restore">⏪ Restore</a>
        <a href="#update-settings">⚙️ Update Settings</a>
        <a href="#manage-players">👥 Manage Players</a>
        <a href="#errors">⚠️ Error Reference</a>
        <a href="#performance">🚀 Performance</a>
        <a href="#contact-dev">📧 Contact Dev</a>
    </div>

    <!-- ══ 1. DAILY CHECKS ══ -->
    <div class="doc-section" id="daily">
        <div class="doc-section-head">
            <div class="doc-section-icon green">☀️</div>
            <div>
                <div class="doc-section-title">Daily Checks</div>
                <div class="doc-section-sub">Perform every morning before opening — takes about 5 minutes</div>
            </div>
        </div>
        <span class="freq-badge daily">⏱️ 5 Minutes — Every Morning</span>
        <div class="doc-checklist">
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Website loads</strong><span>Go to your site URL — should load in under 3 seconds</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Scanner works</strong><span>Go to <code>/court/scanner.php</code> — queue should be visible</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Database connected</strong><span>Admin dashboard should show live stats — no red errors</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Courts available</strong><span>Check court schedule — courts should show as available for today</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Pending top-ups</strong><span>Review and approve/reject any overnight top-up requests</span></div></div>
        </div>
        <div class="doc-table-wrap">
            <table class="doc-table">
                <thead><tr><th>Problem</th><th>Quick Fix</th><th>Time</th></tr></thead>
                <tbody>
                    <tr><td>Website won't load</td><td>Check internet. Restart router. Contact developer.</td><td>2–3 min</td></tr>
                    <tr><td>Scanner shows "Database Error"</td><td>Refresh page. If still broken, restart tablet.</td><td>1 min</td></tr>
                    <tr><td>Dashboard shows "Connection Timeout"</td><td>Wait 2 minutes. Refresh. If it persists, contact developer.</td><td>2 min</td></tr>
                    <tr><td>Courts not showing up</td><td>Refresh page. Check for recent admin changes.</td><td>1 min</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══ 2. WEEKLY CHECKS ══ -->
    <div class="doc-section" id="weekly">
        <div class="doc-section-head">
            <div class="doc-section-icon blue">📆</div>
            <div>
                <div class="doc-section-title">Weekly Checks</div>
                <div class="doc-section-sub">Every Monday morning after daily check — about 30 minutes</div>
            </div>
        </div>
        <span class="freq-badge weekly">⏱️ 30 Minutes — Every Monday</span>
        <div class="doc-checklist">
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Review this week's statistics</strong><span>Admin → Dashboard — check total games, revenue, new players, unusual patterns</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Review player registrations</strong><span>Admin → Players — any new unverified players? Suspicious accounts? Players needing bans?</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Process all pending top-ups</strong><span>Admin → Top-Up Requests — all pending requests reviewed? Any suspicious screenshots?</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Review pending reservations</strong><span>Admin → Schedule — any conflicts or issues? Confirm all seem legitimate.</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Check payment methods</strong><span>Admin → Payment Settings — are all methods still active? QR codes still showing?</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Export weekly data</strong><span>Admin → Reports → Export CSV → save as <code>Week_YYYY-MM-DD.csv</code></span></div></div>
        </div>
        <div class="doc-callout blue">
            <div class="doc-callout-icon">📊</div>
            <div class="doc-callout-text"><strong>Weekly Tracking Tip:</strong> Note your games played, revenue, and new players week-over-week. Spotting trends early (e.g., revenue dropping) lets you act quickly — run a promotion, verify stuck players, or check for technical issues.</div>
        </div>
    </div>

    <!-- ══ 3. MONTHLY TASKS ══ -->
    <div class="doc-section" id="monthly">
        <div class="doc-section-head">
            <div class="doc-section-icon amber">🗓️</div>
            <div>
                <div class="doc-section-title">Monthly Tasks</div>
                <div class="doc-section-sub">On the 1st of every month — about 1 hour</div>
            </div>
        </div>
        <span class="freq-badge monthly">⏱️ 1 Hour — 1st of Every Month</span>
        <div class="doc-checklist">
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Generate monthly report</strong><span>Admin → Reports → select the full previous month → review all metrics</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Export all player data</strong><span>Admin → Players → Export CSV → archive for accounting</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Review revenue — should match expectations</strong><span>Cross-check against approved top-ups and games played</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Review top players</strong><span>Admin → Reports → Top Players — consider loyalty rewards for regulars</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Review court hours</strong><span>Admin → Court Hours — update for upcoming season or holidays</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Check court settings</strong><span>Credit cost still correct? Game duration still set right?</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Verify database backups</strong><span>Login to Railway → PostgreSQL → Backups — confirm 30 recent backups, all "Ready"</span></div></div>
            <div class="doc-check-item"><div class="doc-check-box">☐</div><div class="doc-check-label"><strong>Check for suspicious activity</strong><span>Admin → Audit Log — any unusual login attempts or brute force patterns?</span></div></div>
        </div>
    </div>

    <!-- ══ 4. DATABASE BACKUPS ══ -->
    <div class="doc-section" id="backups">
        <div class="doc-section-head">
            <div class="doc-section-icon green">🗄️</div>
            <div>
                <div class="doc-section-title">Database Backup Procedures</div>
                <div class="doc-section-sub">Automatic daily backups — verify them monthly</div>
            </div>
        </div>
        <div class="doc-callout green">
            <div class="doc-callout-icon">✅</div>
            <div class="doc-callout-text"><strong>Automatic backups are configured.</strong> Your database is backed up every day at 2 AM. 30 days of backups are kept. You don't need to do anything — just verify them monthly.</div>
        </div>
        <div class="doc-steps">
            <div class="doc-step">
                <div class="doc-step-num">1</div>
                <div>
                    <div class="doc-step-title">Verify Backups (Monthly)</div>
                    <div class="doc-step-desc">Log into <strong>Railway.app</strong> → go to your project → click <strong>PostgreSQL</strong> (database plugin) → click the <strong>Backups tab</strong>. Look for a list of recent daily backups. Status should show <strong>"Ready"</strong> or <strong>"Completed"</strong> — not <strong>"Failed"</strong>.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">2</div>
                <div>
                    <div class="doc-step-title">Create a Manual Backup (Optional)</div>
                    <div class="doc-step-desc">Useful before making major changes (e.g., changing credit cost, updating court hours). Login to Railway → PostgreSQL → Backups tab → click <strong>"Create Backup"</strong> → wait 2–5 minutes for it to appear in the list.</div>
                </div>
            </div>
        </div>
        <div class="doc-callout red">
            <div class="doc-callout-icon">🚨</div>
            <div class="doc-callout-text">If any backup shows <strong>"Failed"</strong>, contact your developer immediately. A failed backup means if something goes wrong, you could lose recent data.</div>
        </div>
    </div>

    <!-- ══ 5. RESTORE ══ -->
    <div class="doc-section" id="restore">
        <div class="doc-section-head">
            <div class="doc-section-icon red">⏪</div>
            <div>
                <div class="doc-section-title">Database Restore Procedures</div>
                <div class="doc-section-sub">Only use this if something goes seriously wrong</div>
            </div>
        </div>
        <div class="doc-callout red">
            <div class="doc-callout-icon">⚠️</div>
            <div class="doc-callout-text"><strong>WARNING:</strong> Restoring will replace your current database with the backup copy. All data entered after the backup was made will be lost. Only do this if you have no other option.</div>
        </div>
        <div class="doc-table-wrap">
            <table class="doc-table">
                <thead><tr><th>Restore ✅ IF</th><th>Do NOT Restore ❌ IF</th></tr></thead>
                <tbody>
                    <tr><td>You accidentally deleted important data</td><td>You just want to view old data</td></tr>
                    <tr><td>A staff member made major wrong changes</td><td>You want to undo one legitimate transaction</td></tr>
                    <tr><td>You suspect data corruption</td><td>It's a minor display issue</td></tr>
                    <tr><td>A bug corrupted player balances</td><td>System is slow (not a data problem)</td></tr>
                </tbody>
            </table>
        </div>
        <div class="doc-steps">
            <div class="doc-step">
                <div class="doc-step-num">1</div>
                <div>
                    <div class="doc-step-title">Stop All Operations</div>
                    <div class="doc-step-desc">Tell staff to stop using the system. Post a maintenance message for players. Do not make any new transactions until restore is complete.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">2</div>
                <div>
                    <div class="doc-step-title">Choose the Right Backup</div>
                    <div class="doc-step-desc">Railway → PostgreSQL → Backups tab. Choose the backup from <strong>before the problem happened</strong>. For example: if bad data was entered today, choose yesterday's backup. Click on it.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">3</div>
                <div>
                    <div class="doc-step-title">Restore</div>
                    <div class="doc-step-desc">Click <strong>"Restore"</strong> → confirm with <strong>"Yes, restore"</strong>. Wait 5–10 minutes. Do not refresh or interrupt the process.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">4</div>
                <div>
                    <div class="doc-step-title">Verify the Restored Data</div>
                    <div class="doc-step-desc">Log into admin dashboard. Check: player balances look correct? Revenue showing right? Recent games visible? If all good, continue to step 5. If bad, contact developer immediately.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">5</div>
                <div>
                    <div class="doc-step-title">Resume Operations</div>
                    <div class="doc-step-desc">Tell staff system is restored. Remove maintenance message. Monitor closely for the next hour. Report the issue to your developer with details of what caused it.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ 6. UPDATE SETTINGS ══ -->
    <div class="doc-section" id="update-settings">
        <div class="doc-section-head">
            <div class="doc-section-icon amber">⚙️</div>
            <div>
                <div class="doc-section-title">Updating Court Settings</div>
                <div class="doc-section-sub">Change credit cost, game duration, players, and hours</div>
            </div>
        </div>
        <div class="doc-faq-list" id="settings-faq-list">
            <?php
            $settingsFaqs = [
                ["Change Credit Cost Per Game", "Go to Admin → Court Settings → find the 'Credit Cost' field → change the value (e.g., from 10 to 15) → click Save. Effect: New games will use the new price. Games already in progress are not affected. Players who already paid are not charged the difference."],
                ["Change Game Duration", "Go to Admin → Court Settings → find 'Game Duration (minutes)' → change the value (e.g., from 60 to 45) → click Save. Effect: All new games will auto-end at the new duration. Does not affect games already running."],
                ["Change Players Per Game", "Go to Admin → Court Settings → find 'Players Per Game' → change the value (e.g., from 4 to 3) → click Save. Effect: The queue will auto-start games when the new number of players is reached."],
                ["Set Court Hours", "Go to Admin → Court Hours. For each day (Mon–Sun), set the Open Time and Close Time (e.g., 07:00 to 23:00). To close a specific day entirely (e.g., Mondays), check the 'Is Closed' checkbox for that day. Click Save."],
            ];
            foreach ($settingsFaqs as $i => $faq): ?>
            <div class="doc-faq-item" id="sfaq-<?= $i ?>">
                <button class="doc-faq-q" onclick="toggleSettingsFaq(<?= $i ?>)">
                    <span class="doc-faq-q-text">⚙️ <?= htmlspecialchars($faq[0]) ?></span>
                    <span class="doc-faq-chevron">▼</span>
                </button>
                <div class="doc-faq-a"><?= htmlspecialchars($faq[1]) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══ 7. MANAGE PLAYERS ══ -->
    <div class="doc-section" id="manage-players">
        <div class="doc-section-head">
            <div class="doc-section-icon purple">👥</div>
            <div>
                <div class="doc-section-title">Managing Players</div>
                <div class="doc-section-sub">Step-by-step for common player operations</div>
            </div>
        </div>
        <div class="doc-faq-list" id="players-faq-list">
            <?php
            $playerFaqs = [
                ["Verify a New Player", "Go to Admin → Players → filter by Status = 'Unverified'. Click the player's name. Review their info (name, valid phone number, etc.). Click 'Verify' → Save. Effect: Player can now join games and use all system features."],
                ["Ban a Player (Rule Violations)", "Go to Admin → Players → find the player → click 'Ban' → enter a reason (e.g., 'Aggressive behavior, yelling at other players') → Save. Effect: Player gets a suspension notification, cannot login, QR code stops working, cannot book courts."],
                ["Unban a Player", "Go to Admin → Players → find the banned player → click 'Unban' → confirm. Effect: Player can login again, QR code works, can join games."],
                ["Reset Player Password", "Go to Admin → Players → find the player → click 'Reset Password' → enter a temporary password (e.g., Temp123456) → Save. Tell the player their temp password. They will be forced to change it on next login."],
                ["Manually Add Credits (Top-Up at Counter)", "Go to Admin → Players → find the player → click 'Adjust Balance' → select 'Add Credits' → enter amount (e.g., 500) → reason: 'Cash top-up at counter' → Save. Effect: Player's balance increases immediately."],
                ["Deduct Credits (Refund or Correction)", "Go to Admin → Players → find the player → click 'Adjust Balance' → select 'Deduct Credits' → enter amount → reason: 'Refund - cancelled reservation' or 'Correction - double charged' → Save."],
            ];
            foreach ($playerFaqs as $i => $faq): ?>
            <div class="doc-faq-item" id="pfaq-<?= $i ?>">
                <button class="doc-faq-q" onclick="togglePlayerFaq(<?= $i ?>)">
                    <span class="doc-faq-q-text">👤 <?= htmlspecialchars($faq[0]) ?></span>
                    <span class="doc-faq-chevron">▼</span>
                </button>
                <div class="doc-faq-a"><?= htmlspecialchars($faq[1]) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══ 8. ERROR REFERENCE ══ -->
    <div class="doc-section" id="errors">
        <div class="doc-section-head">
            <div class="doc-section-icon red">⚠️</div>
            <div>
                <div class="doc-section-title">Error Messages & Solutions</div>
                <div class="doc-section-sub">What errors mean and how to fix them</div>
            </div>
        </div>
        <div class="doc-table-wrap">
            <table class="doc-table">
                <thead><tr><th>Error</th><th>Cause</th><th>Solution</th></tr></thead>
                <tbody>
                    <tr><td><code>Connection Timeout</code></td><td>Database unreachable</td><td>Wait 2 min. Refresh. If persists, restart Railway app. Contact developer.</td></tr>
                    <tr><td><code>Insufficient Balance</code></td><td>Player has &lt; ₱10</td><td>Player must top up credits. Show them the top-up page.</td></tr>
                    <tr><td><code>Account Suspended</code></td><td>Player was banned</td><td>Check Admin → Players. Unban if appropriate.</td></tr>
                    <tr><td><code>Invalid QR Code</code></td><td>Scanner can't read QR</td><td>Clean scanner lens. Try manual entry. Check USB connection.</td></tr>
                    <tr><td><code>Password Too Weak</code></td><td>Doesn't meet requirements</td><td>Must be 8+ chars, 1 UPPERCASE, 1 number. Example: <code>Court2026</code></td></tr>
                    <tr><td><code>Username Already Taken</code></td><td>Someone else has that username</td><td>Try a variation: <code>juan_2024</code>, <code>jdelaCruz99</code></td></tr>
                    <tr><td><code>Email Already Used</code></td><td>Email on another account</td><td>Verify email. One account per email allowed.</td></tr>
                    <tr><td><code>File Too Large</code></td><td>Avatar/screenshot &gt; 25 MB</td><td>Use a smaller file. Compress image before uploading.</td></tr>
                    <tr><td><code>Payment Proof Required</code></td><td>Player didn't attach screenshot</td><td>Ask player to take photo of GCash/bank receipt and resubmit.</td></tr>
                    <tr><td><code>No Available Courts</code></td><td>All courts fully booked</td><td>Check calendar. Show player available dates/times.</td></tr>
                    <tr><td><code>Session Expired</code></td><td>Idle for 20+ minutes</td><td>Log in again — no data was lost.</td></tr>
                    <tr><td><code>Access Denied</code></td><td>Wrong role accessing admin page</td><td>Log in with the correct admin account.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══ 9. PERFORMANCE ══ -->
    <div class="doc-section" id="performance">
        <div class="doc-section-head">
            <div class="doc-section-icon blue">🚀</div>
            <div>
                <div class="doc-section-title">Performance Monitoring</div>
                <div class="doc-section-sub">Is your system running slow? Here's how to diagnose it</div>
            </div>
        </div>
        <div class="doc-steps">
            <div class="doc-step">
                <div class="doc-step-num">1</div>
                <div>
                    <div class="doc-step-title">Test on Different Browsers</div>
                    <div class="doc-step-desc">Try Chrome, Firefox, Safari, and Edge. Is it slow on all browsers or just one? If only one browser, clear that browser's cache (<strong>Ctrl+Shift+Delete</strong>).</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">2</div>
                <div>
                    <div class="doc-step-title">Check Your Internet Speed</div>
                    <div class="doc-step-desc">Run a speed test at <strong>speedtest.net</strong>. You need at least <strong>10 Mbps upload</strong> and <strong>50 Mbps download</strong> for a smooth experience.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">3</div>
                <div>
                    <div class="doc-step-title">Check Railway Server Status</div>
                    <div class="doc-step-desc">Go to <strong>status.railway.app</strong>. Green = OK. Red/Yellow = Railway has issues — wait for them to fix it (usually within 30 minutes).</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">4</div>
                <div>
                    <div class="doc-step-title">Check Server Load in Railway</div>
                    <div class="doc-step-desc">Login to Railway → your project → look at CPU and Memory usage metrics. Should be <strong>below 80%</strong>. If higher, click <strong>Redeploy</strong> and wait 2 minutes for it to restart.</div>
                </div>
            </div>
            <div class="doc-step">
                <div class="doc-step-num">5</div>
                <div>
                    <div class="doc-step-title">Contact Developer If All Above Are OK</div>
                    <div class="doc-step-desc">If internet is fast, Railway is green, and all browsers are slow — the issue is likely in the database or code. Contact your developer with details of what's slow and since when.</div>
                </div>
            </div>
        </div>
        <div class="doc-callout green">
            <div class="doc-callout-icon">⚡</div>
            <div class="doc-callout-text"><strong>Quick fixes to try first:</strong> Clear browser cache (Ctrl+Shift+Delete → select All Time → clear). Close unused browser tabs. Restart your browser. Restart the device if very slow.</div>
        </div>
    </div>

    <!-- ══ 10. CONTACT DEVELOPER ══ -->
    <div class="doc-section" id="contact-dev">
        <div class="doc-section-head">
            <div class="doc-section-icon amber">📧</div>
            <div>
                <div class="doc-section-title">When & How to Contact Support</div>
                <div class="doc-section-sub">Emergency vs standard support — and how to write a good report</div>
            </div>
        </div>
        <div class="doc-callout red">
            <div class="doc-callout-icon">🚨</div>
            <div class="doc-callout-text">
                <strong>Contact immediately (24-hour response) if:</strong> Website completely down · Database offline · All player balances show ₱0 · Money being charged incorrectly · Security breach suspected (unauthorized access).
                <br>Email subject: <code>[URGENT] Falcon System Issue — [Brief description]</code>
            </div>
        </div>
        <div class="doc-callout amber">
            <div class="doc-callout-icon">📬</div>
            <div class="doc-callout-text">
                <strong>Standard support (within 5 business days) for:</strong> Minor UI bugs · Feature questions · Performance optimization · New feature requests · System capacity planning.
                <br>Email subject: <code>[STANDARD] Falcon Question — [Brief description]</code>
            </div>
        </div>
        <div style="margin-bottom:10px;font-weight:700;font-size:13px;color:var(--text);">Template for a Good Support Email:</div>
        <div class="doc-email-template"><strong>Subject: [URGENT] QR Scanner Not Working</strong>

Hi [Developer],

This morning at 10:30 AM, I tried to scan a player's QR code at the
court. The scanner displayed "Invalid QR Code" even though the player
has a ₱500 balance.

I tried:
- Cleaning the scanner lens
- Restarting the tablet
- Scanning a different player's QR (same error)

The scanner worked fine yesterday. Players cannot join games.

Browser: Chrome (latest)
Device: iPad Air 2024
Screenshots attached: [error-screen.png]

What should I do?

Thanks,
[Your Name]</div>
        <div class="doc-callout green">
            <div class="doc-callout-icon">💡</div>
            <div class="doc-callout-text"><strong>Always include:</strong> What you were doing · What you expected · What actually happened · When it started · How many times you've seen it · Screenshots if possible · Your browser and device.</div>
        </div>
        <div style="text-align:center;margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">
            <a href="<?= APP_URL ?>/docs/owners_manual.php" class="btn-outline btn-sm">📘 Owner's Manual</a>
            <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-primary btn-sm">← Back to Dashboard</a>
        </div>
    </div>

</div><!-- /.doc-wrap -->

<script nonce="<?= getCspNonce() ?>">
function toggleSettingsFaq(i) {
    const item = document.getElementById('sfaq-' + i);
    if (!item) return;
    const isOpen = item.classList.contains('open');
    document.querySelectorAll('#settings-faq-list .doc-faq-item.open').forEach(el => el.classList.remove('open'));
    if (!isOpen) item.classList.add('open');
}
function togglePlayerFaq(i) {
    const item = document.getElementById('pfaq-' + i);
    if (!item) return;
    const isOpen = item.classList.contains('open');
    document.querySelectorAll('#players-faq-list .doc-faq-item.open').forEach(el => el.classList.remove('open'));
    if (!isOpen) item.classList.add('open');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>