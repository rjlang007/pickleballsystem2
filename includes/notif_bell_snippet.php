<?php
// ============================================================
//  FILE: includes/notif_bell_snippet.php
//  INSTRUCTIONS: This file shows the TWO additions to make
//  to includes/header.php:
//
//  1. Add the CSS block inside the existing <style> tag
//     in header.php (at the very end, before </style>)
//
//  2. Add the HTML+JS block just BEFORE the closing </style>
//     tag comment, after the fcb_toggle_btn calls in both
//     desktop and mobile nav sections.
//
//  The notification bell appears between the schedule/chat
//  icons and the account dropdown in the navbar.
// ============================================================

/*
 ─────────────────────────────────────────────────────────
  CSS — paste into the existing <style nonce="<?= getCspNonce() ?>"> block in header.php
 ─────────────────────────────────────────────────────────
*/
?>
<style id="notif-bell-styles" nonce="<?= getCspNonce() ?>">
/* ── Notification Bell ─────────────────────────────────── */
.notif-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
}

.notif-btn {
    position: relative;
    background: none;
    border: 1px solid transparent;
    color: var(--muted);
    font-size: 18px;
    cursor: pointer;
    width: 38px; height: 38px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    transition: color 0.2s, background 0.2s, border-color 0.2s;
    touch-action: manipulation;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
}
.notif-btn:hover {
    color: var(--text);
    background: var(--surface2);
}
.notif-btn.has-unread {
    color: var(--accent);
    border-color: rgba(0,229,160,0.25);
    background: rgba(0,229,160,0.07);
    animation: notifPulse 2.5s ease infinite;
}
@keyframes notifPulse {
    0%,100% { box-shadow: 0 0 0 0 rgba(0,229,160,0); }
    50%      { box-shadow: 0 0 0 5px rgba(0,229,160,0.12); }
}

.notif-badge {
    position: absolute;
    top: 1px; right: 1px;
    background: var(--danger);
    color: #fff;
    font-size: 9px;
    font-weight: 800;
    border-radius: 99px;
    padding: 1px 4px;
    min-width: 16px;
    text-align: center;
    border: 1.5px solid var(--bg, #0a0f1e);
    display: none;
    line-height: 1.4;
    pointer-events: none;
    animation: notifBadgePop 0.3s cubic-bezier(0.34,1.56,0.64,1);
}
.notif-badge.show { display: block; }
@keyframes notifBadgePop { from{transform:scale(0)} to{transform:scale(1)} }

/* ── Dropdown ──────────────────────────────────────────── */
.notif-dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: -8px;
    width: 340px;
    max-width: calc(100vw - 24px);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: 0 16px 48px rgba(0,0,0,0.55), 0 0 0 1px rgba(0,229,160,0.07);
    z-index: 9000;
    display: none;
    overflow: hidden;
    animation: notifDropIn 0.22s cubic-bezier(0.34,1.56,0.64,1);
}
.notif-dropdown.open { display: block; }
@keyframes notifDropIn {
    from { opacity:0; transform:translateY(-8px) scale(0.97); }
    to   { opacity:1; transform:translateY(0) scale(1); }
}

.notif-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px 10px;
    border-bottom: 1px solid var(--border);
}
.notif-header-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 17px;
    letter-spacing: 1px;
    color: var(--text);
}
.notif-mark-all {
    font-size: 11px;
    color: var(--accent);
    background: none;
    border: none;
    cursor: pointer;
    font-family: inherit;
    font-weight: 600;
    padding: 3px 7px;
    border-radius: 6px;
    transition: background 0.15s;
    touch-action: manipulation;
}
.notif-mark-all:hover { background: rgba(0,229,160,0.1); }

.notif-list {
    max-height: 340px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--border) transparent;
}
.notif-list::-webkit-scrollbar { width: 3px; }
.notif-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

.notif-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 11px 15px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    cursor: pointer;
    transition: background 0.12s;
    text-decoration: none;
}
.notif-item:last-child { border-bottom: none; }
.notif-item:hover { background: rgba(255,255,255,0.03); }
.notif-item.unread { background: rgba(0,229,160,0.04); border-left: 3px solid var(--accent); }
.notif-item.unread:hover { background: rgba(0,229,160,0.08); }

.notif-item-icon {
    font-size: 20px;
    flex-shrink: 0;
    width: 36px; height: 36px;
    background: var(--surface2);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    margin-top: 1px;
}
.notif-item-body { flex: 1; min-width: 0; }
.notif-item-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--text);
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.notif-item-msg {
    font-size: 12px;
    color: var(--muted);
    margin-top: 2px;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.notif-item-time {
    font-size: 10px;
    color: var(--muted);
    margin-top: 4px;
    font-family: monospace;
}

.notif-empty {
    text-align: center;
    padding: 32px 20px;
    color: var(--muted);
    font-size: 13px;
}
.notif-empty-icon { font-size: 32px; margin-bottom: 8px; }

.notif-footer-link {
    display: block;
    text-align: center;
    padding: 10px;
    font-size: 13px;
    font-weight: 600;
    color: var(--accent);
    text-decoration: none;
    border-top: 1px solid var(--border);
    transition: background 0.15s;
}
.notif-footer-link:hover { background: rgba(0,229,160,0.06); }

/* Mobile: full-width dropdown from top */
@media (max-width: 480px) {
    .notif-dropdown {
        position: fixed;
        top: 70px; left: 8px; right: 8px;
        width: auto;
        border-radius: 16px;
    }
}
</style>

<?php
/*
 ─────────────────────────────────────────────────────────
  HTML + PHP — paste in the navbar-links section of header.php
  BEFORE the fcb-nav-toggle buttons and account dropdowns
  (both desktop AND mobile sections need this)
 ─────────────────────────────────────────────────────────
*/
?>

<!-- NOTIFICATION BELL — desktop (paste in .navbar-links before fcb_toggle_btn) -->
<div class="notif-wrap" id="notif-wrap-desktop">
    <button class="notif-btn" id="notif-btn-desktop"
            aria-label="Notifications"
            aria-expanded="false"
            aria-haspopup="true">
        🔔
        <span class="notif-badge" id="notif-badge-desktop"></span>
    </button>
    <div class="notif-dropdown" id="notif-dropdown-desktop" role="dialog" aria-label="Notifications">
        <div class="notif-header">
            <span class="notif-header-title">Notifications</span>
            <button class="notif-mark-all" id="notif-mark-all-desktop">Mark all read</button>
        </div>
        <div class="notif-list" id="notif-list-desktop">
            <div class="notif-empty"><div class="notif-empty-icon">🔔</div>No notifications yet.</div>
        </div>
        <a href="<?= APP_URL ?>/<?= isAdmin() ? 'admin' : 'player' ?>/notifications.php" class="notif-footer-link">
            View all notifications →
        </a>
    </div>
</div>

<?php
/*
 ─────────────────────────────────────────────────────────
  JS — paste at the bottom of header.php before </script>
  (inside the existing <script nonce="<?= getCspNonce() ?>"> block)
 ─────────────────────────────────────────────────────────
*/
?>
<script id="notif-bell-js" nonce="<?= getCspNonce() ?>">
(function() {
    'use strict';

    var NOTIF_API = '<?= APP_URL ?>/api/notifications.php';
    var IS_ADMIN  = <?= isAdmin() ? 'true' : 'false' ?>;
    var IS_LOGGED = <?= isLoggedIn() ? 'true' : 'false' ?>;

    if (!IS_LOGGED) return;

    var dropdownOpen = false;
    var allNotifs    = [];

    var btn      = document.getElementById('notif-btn-desktop');
    var dropdown = document.getElementById('notif-dropdown-desktop');
    var badge    = document.getElementById('notif-badge-desktop');
    var list     = document.getElementById('notif-list-desktop');
    var markAll  = document.getElementById('notif-mark-all-desktop');

    if (!btn) return;

    // ── Toggle dropdown ───────────────────────────────────
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (dropdownOpen) {
            closeDropdown();
        } else {
            openDropdown();
        }
    });

    function openDropdown() {
        dropdownOpen = true;
        dropdown.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');
        fetchNotifications();
    }

    function closeDropdown() {
        dropdownOpen = false;
        dropdown.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
    }

    // Close on outside click
    document.addEventListener('click', function(e) {
        if (dropdownOpen && !dropdown.contains(e.target) && !btn.contains(e.target)) {
            closeDropdown();
        }
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && dropdownOpen) closeDropdown();
    });

    // ── Fetch notifications ───────────────────────────────
    function fetchNotifications() {
        list.innerHTML = '<div class="notif-empty"><div class="notif-empty-icon">⏳</div>Loading…</div>';
        fetch(NOTIF_API + '?list=1&limit=15', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.ok) return;
                allNotifs = data.notifications || [];
                renderList(allNotifs);
                updateBadge(data.unread_count || 0);
            })
            .catch(function() {
                list.innerHTML = '<div class="notif-empty"><div class="notif-empty-icon">⚠️</div>Could not load notifications.</div>';
            });
    }

    // ── Render list ───────────────────────────────────────
    function renderList(notifs) {
        if (!notifs.length) {
            list.innerHTML = '<div class="notif-empty"><div class="notif-empty-icon">🔔</div>All caught up — no notifications.</div>';
            return;
        }

        var typeIcons = {
            'success': '✅', 'error': '❌', 'warn': '⚠️',
            'info': '📢', 'danger': '🚨'
        };
        var typeBg = {
            'success': 'rgba(0,229,160,0.1)', 'error': 'rgba(239,68,68,0.1)',
            'warn': 'rgba(245,158,11,0.1)', 'info': 'rgba(0,184,255,0.1)',
            'danger': 'rgba(239,68,68,0.12)'
        };

        var html = '';
        notifs.forEach(function(n) {
            var icon = typeIcons[n.type] || '📢';
            var bg   = typeBg[n.type]   || 'var(--surface2)';
            var href = n.link || (IS_ADMIN ? '<?= APP_URL ?>/admin/notifications.php' : '<?= APP_URL ?>/player/notifications.php');
            html += '<a href="' + escAttr(href) + '" class="notif-item' + (n.is_read ? '' : ' unread') + '"'
                  + ' data-id="' + n.id + '"'
                  + ' onclick="notifMarkRead(' + n.id + ',event)">'
                  + '<div class="notif-item-icon" style="background:' + bg + ';">' + icon + '</div>'
                  + '<div class="notif-item-body">'
                  + '<div class="notif-item-title">' + escHtmlNotif(n.title) + '</div>'
                  + '<div class="notif-item-msg">' + escHtmlNotif(n.message) + '</div>'
                  + '<div class="notif-item-time">' + escHtmlNotif(n.time_ago) + '</div>'
                  + '</div>'
                  + '</a>';
        });
        list.innerHTML = html;
    }

    // ── Mark read ─────────────────────────────────────────
    window.notifMarkRead = function(id, e) {
        fetch(NOTIF_API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'mark_read', id: id })
        }).catch(function() {});

        // Update local state
        allNotifs = allNotifs.map(function(n) {
            if (n.id === id) n.is_read = true;
            return n;
        });
        var unread = allNotifs.filter(function(n) { return !n.is_read; }).length;
        updateBadge(unread);

        // Remove unread styling
        var item = document.querySelector('.notif-item[data-id="' + id + '"]');
        if (item) item.classList.remove('unread');
    };

    // ── Mark all read ──────────────────────────────────────
    if (markAll) {
        markAll.addEventListener('click', function(e) {
            e.stopPropagation();
            fetch(NOTIF_API, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_all_read' })
            })
            .then(function() {
                allNotifs = allNotifs.map(function(n) { n.is_read = true; return n; });
                renderList(allNotifs);
                updateBadge(0);
            })
            .catch(function() {});
        });
    }

    // ── Badge update ──────────────────────────────────────
    function updateBadge(count) {
        var b = document.getElementById('notif-badge-desktop');
        if (!b) return;
        if (count > 0) {
            b.textContent = count > 99 ? '99+' : count;
            b.classList.add('show');
            btn.classList.add('has-unread');
        } else {
            b.classList.remove('show');
            btn.classList.remove('has-unread');
        }
    }

    // ── Poll for unread count (every 30s) ─────────────────
    function pollUnread() {
        fetch(NOTIF_API + '?unread_count=1', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) { if (data.ok) updateBadge(data.count); })
            .catch(function() {});
    }

    // Initial fetch + polling
    pollUnread();
    setInterval(pollUnread, 30000);

    // ── Helpers ───────────────────────────────────────────
    function escHtmlNotif(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escAttr(str) {
        return String(str || '').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
}());
</script>