<?php
// ============================================================
//  FILE: admin/chat_inbox.php
//  Admin chat inbox — see all player conversations
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$pageTitle = 'Court Chat — Inbox';
?>
<script nonce="<?= getCspNonce() ?>">
window.APP_URL = '<?= APP_URL ?>';
</script>
<?php
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Chat Inbox Layout ─────────────────────────────────────── */
.chat-inbox-wrap {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 0;
    height: calc(100vh - 130px);
    min-height: 500px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
}

/* Sidebar */
.chat-sidebar {
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--bg2);
}

.chat-sidebar-header {
    padding: 18px 16px 14px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}

.chat-sidebar-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 20px;
    letter-spacing: 1px;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}

.chat-sidebar-search {
    margin-top: 10px;
    position: relative;
}

.chat-sidebar-search input {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 12px 8px 32px;
    font-size: 13px;
    color: var(--text);
    font-family: inherit;
    outline: none;
    transition: border-color 0.2s;
    box-sizing: border-box;
}

.chat-sidebar-search input:focus { border-color: var(--accent); }
.chat-sidebar-search input::placeholder { color: var(--muted); }

.chat-sidebar-search-icon {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    font-size: 13px;
    pointer-events: none;
}

.chat-conv-list {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}

.chat-conv-list::-webkit-scrollbar { width: 3px; }
.chat-conv-list::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 99px; }

.chat-conv-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 11px 12px;
    border-radius: 10px;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
    border: 1px solid transparent;
    margin-bottom: 2px;
}

.chat-conv-item:hover { background: rgba(0,229,160,0.06); border-color: rgba(0,229,160,0.15); }
.chat-conv-item.active { background: rgba(0,229,160,0.1); border-color: rgba(0,229,160,0.3); }

.chat-conv-avatar {
    width: 38px; height: 38px;
    border-radius: 50%;
    background: var(--accent);
    color: var(--bg);
    font-weight: 700;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    position: relative;
}

.chat-conv-unread-dot {
    position: absolute;
    top: -2px; right: -2px;
    width: 10px; height: 10px;
    background: var(--accent3, #ef4444);
    border-radius: 50%;
    border: 2px solid var(--bg2);
}

.chat-conv-body { flex: 1; min-width: 0; }

.chat-conv-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.chat-conv-preview {
    font-size: 11px;
    color: var(--muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    margin-top: 2px;
}

.chat-conv-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
    flex-shrink: 0;
}

.chat-conv-time { font-size: 10px; color: var(--muted); white-space: nowrap; }

.chat-conv-badge {
    background: var(--accent3, #ef4444);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    border-radius: 99px;
    padding: 2px 7px;
    min-width: 20px;
    text-align: center;
}

/* Main chat area */
.chat-main {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--bg);
}

.chat-main-empty {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 12px;
    color: var(--muted);
    font-size: 15px;
}

.chat-main-empty span { font-size: 48px; }

/* Loading / error states */
.conv-list-state {
    text-align: center;
    padding: 24px;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.6;
}
.conv-list-state .state-icon { font-size: 28px; margin-bottom: 8px; }
.conv-list-state.error { color: #ef4444; }

/* Responsive */
@media (max-width: 768px) {
    .chat-inbox-wrap {
        grid-template-columns: 1fr;
        height: calc(100vh - 120px);
    }
    .chat-main { display: none; }
    .chat-main.mobile-open { display: flex; }
    .chat-sidebar { display: flex; }
    .chat-sidebar.mobile-hidden { display: none; }
}
</style>

<div class="page-header flex-between">
    <div>
        <h1>🏓 Court Chat — Inbox</h1>
        <p>Direct messages from players</p>
    </div>
</div>

<div class="chat-inbox-wrap" id="chat-inbox-wrap">

    <!-- Sidebar: Conversation List -->
    <div class="chat-sidebar" id="chat-sidebar">
        <div class="chat-sidebar-header">
            <div class="chat-sidebar-title">
                <span>💬</span> Messages
                <span id="total-unread-badge" style="background:#ef4444;color:#fff;font-size:11px;font-weight:700;border-radius:99px;padding:2px 8px;display:none;margin-left:4px;"></span>
            </div>
            <div class="chat-sidebar-search">
                <span class="chat-sidebar-search-icon">🔍</span>
                <input type="text" id="conv-search" placeholder="Search all players…" spellcheck="false" autocomplete="off">
            </div>
        </div>
        <div class="chat-conv-list" id="conv-list">
            <div class="conv-list-state">
                <div class="state-icon">💬</div>
                Loading conversations…
            </div>
        </div>
    </div>

    <!-- Main: Empty state until a conversation is selected -->
    <div class="chat-main" id="chat-main">
        <div class="chat-main-empty">
            <span>🏓</span>
            Select a conversation to start chatting
        </div>
    </div>

</div>

<script nonce="<?= getCspNonce() ?>">
(function () {
    'use strict';

    // ── State ────────────────────────────────────────────────
    var API              = window.APP_URL + '/api/chat.php';
    var allConversations = [];
    var activeUserId     = null;
    var searchTimer      = null;
    var isSearching      = false;
    var refreshTimer     = null;
    var widgetReady      = false;

    // ── Helpers ──────────────────────────────────────────────
    function escHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function strToColor(str) {
        var hash = 0;
        for (var i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
        var colors = ['#00e5a0', '#00b8ff', '#ff6b35', '#a78bfa', '#f59e0b', '#ec4899'];
        return colors[Math.abs(hash) % colors.length];
    }

    function getSearchVal() {
        var el = document.getElementById('conv-search');
        return el ? el.value.trim() : '';
    }

    function showListState(html, isError) {
        var list = document.getElementById('conv-list');
        if (!list) return;
        list.innerHTML = '<div class="conv-list-state' + (isError ? ' error' : '') + '">' + html + '</div>';
    }

    // ── Wait for falconChat widget to be ready ───────────────
    // chat_widget.php calls init() on DOMContentLoaded, which
    // does an async fetch. We poll until mountInbox is available
    // AND the widget has finished its status check.
    function waitForWidget(cb) {
        if (widgetReady) { cb(); return; }
        var attempts = 0;
        var check = setInterval(function () {
            attempts++;
            var fc = window.falconChat || window.FC;
            if (fc && typeof fc.mountInbox === 'function') {
                clearInterval(check);
                widgetReady = true;
                cb();
            } else if (attempts > 50) { // 5s timeout
                clearInterval(check);
                console.warn('PadolChat widget did not become ready in time.');
                cb(); // proceed anyway, openConversation will show an error
            }
        }, 100);
    }

    // ── Load all existing conversations (default view) ───────
    function loadConversations(silent) {
        // Don't auto-refresh list while user is actively searching
        if (isSearching) return;

        if (!silent) {
            showListState('<div class="state-icon">💬</div>Loading conversations…');
        }

        fetch(API + '?action=conversations', { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (!data.ok) {
                    throw new Error(data.error || 'Unknown API error');
                }
                allConversations = data.conversations || [];
                if (!getSearchVal()) {
                    renderConvList(allConversations, false);
                }
            })
            .catch(function (err) {
                console.error('loadConversations error:', err);
                if (!silent && !getSearchVal()) {
                    showListState(
                        '<div class="state-icon">⚠️</div>Could not load conversations.<br>'
                        + '<small>' + escHtml(err.message) + '</small>',
                        true
                    );
                }
            });
    }

    // ── Search all players via API ───────────────────────────
    function searchPlayers(q) {
        if (!q) {
            isSearching = false;
            renderConvList(allConversations, false);
            return;
        }
        isSearching = true;
        showListState('<div class="state-icon">🔍</div>Searching…');

        fetch(API + '?action=search_players&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (!data.ok) throw new Error(data.error || 'Search failed');
                renderConvList(data.players || [], true);
            })
            .catch(function (err) {
                console.error('searchPlayers error:', err);
                showListState(
                    '<div class="state-icon">⚠️</div>Search failed.<br><small>' + escHtml(err.message) + '</small>',
                    true
                );
            });
    }

    // ── Render list (works for both conversations & search results) ──
    function renderConvList(items, isSearchResult) {
        var list = document.getElementById('conv-list');
        if (!list) return;

        // Update total unread badge (always from allConversations for accuracy)
        var totalUnread = allConversations.reduce(function (acc, c) {
            return acc + (c.unread_count || 0);
        }, 0);
        var badge = document.getElementById('total-unread-badge');
        if (badge) {
            badge.textContent = totalUnread;
            badge.style.display = totalUnread > 0 ? 'inline' : 'none';
        }

        if (!items || items.length === 0) {
            showListState(
                '<div class="state-icon">' + (isSearchResult ? '😕' : '💬') + '</div>'
                + (isSearchResult ? 'No players found.' : 'No conversations yet.')
            );
            return;
        }

        var html = items.map(function (c) {
            var timeStr  = c.last_time ? (c.last_time.split(', ')[1] || c.last_time) : '';
            var isActive = activeUserId === c.user_id;
            var hasConv  = isSearchResult ? !!c.has_conv : true;
            var preview  = c.last_message
                ? escHtml(c.last_message)
                : (hasConv
                    ? '<span style="opacity:.5">No messages yet</span>'
                    : '<span style="opacity:.4;font-style:italic">New conversation</span>');
            var unread = c.unread_count || 0;

            return '<div class="chat-conv-item' + (isActive ? ' active' : '') + '"'
                + ' data-userid="' + c.user_id + '"'
                + ' data-username="' + escHtml(c.username) + '">'
                + '<div class="chat-conv-avatar" style="background:' + strToColor(c.username || '') + '">'
                + (unread > 0 ? '<div class="chat-conv-unread-dot"></div>' : '')
                + escHtml((c.username || '?').charAt(0).toUpperCase())
                + '</div>'
                + '<div class="chat-conv-body">'
                + '<div class="chat-conv-name">'
                + escHtml(c.full_name || c.username)
                + '<span style="font-size:10px;color:var(--muted);font-weight:400;margin-left:4px;">@' + escHtml(c.username) + '</span>'
                + '</div>'
                + '<div class="chat-conv-preview">' + preview + '</div>'
                + '</div>'
                + '<div class="chat-conv-meta">'
                + '<div class="chat-conv-time">' + escHtml(timeStr) + '</div>'
                + (unread > 0 ? '<div class="chat-conv-badge">' + unread + '</div>' : '')
                + (!hasConv ? '<div style="font-size:9px;color:var(--accent);margin-top:2px;font-weight:600;">NEW</div>' : '')
                + '</div>'
                + '</div>';
        }).join('');

        list.innerHTML = html;

        // Attach click listeners (safe — no inline onclick with user data)
        list.querySelectorAll('.chat-conv-item').forEach(function (el) {
            el.addEventListener('click', function () {
                openConversation(
                    parseInt(el.dataset.userid, 10),
                    el.dataset.username
                );
            });
        });
    }

    // ── Open a conversation ──────────────────────────────────
    function openConversation(userId, username) {
        activeUserId = userId;

        // Re-highlight active item in current view
        document.querySelectorAll('.chat-conv-item').forEach(function (el) {
            el.classList.toggle('active', parseInt(el.dataset.userid, 10) === userId);
        });

        var main = document.getElementById('chat-main');

        // Mobile: swap panels
        if (window.innerWidth <= 768) {
            document.getElementById('chat-sidebar').classList.add('mobile-hidden');
            main.classList.add('mobile-open');
        }

        // Show loading state in main while widget mounts
        main.innerHTML = '<div class="chat-main-empty">'
            + '<span>💬</span>'
            + '<span style="font-size:15px;color:var(--muted);">Opening conversation…</span>'
            + '</div>';

        waitForWidget(function () {
            var fc = window.falconChat || window.FC;
            if (!fc || typeof fc.mountInbox !== 'function') {
                main.innerHTML = '<div class="chat-main-empty">'
                    + '<span>⚠️</span>'
                    + '<span style="font-size:14px;color:var(--muted);">Chat widget not available. Please refresh the page.</span>'
                    + '</div>';
                return;
            }

            main.innerHTML = '<div id="embedded-chat" style="display:flex;flex-direction:column;height:100%;overflow:hidden;"></div>';
            var embedEl = document.getElementById('embedded-chat');
            if (!embedEl) return;

            try {
                fc.mountInbox(embedEl, userId, username);
            } catch (err) {
                console.error('mountInbox error:', err);
                main.innerHTML = '<div class="chat-main-empty">'
                    + '<span>⚠️</span>'
                    + '<span style="font-size:14px;color:var(--muted);">Failed to load conversation: ' + escHtml(err.message) + '</span>'
                    + '</div>';
            }
        });
    }

    // ── Back button (mobile) ─────────────────────────────────
    window.backToList = function () {
        // Stop inbox poll by closing the widget's internal state
        var fc = window.falconChat || window.FC;
        if (fc && typeof fc.close === 'function') {
            // Only close the internal panel state, not the floating panel
            // We just need to stop the poll – reset via a flag on FC if available
        }

        activeUserId = null;
        var sidebar = document.getElementById('chat-sidebar');
        var main    = document.getElementById('chat-main');
        if (sidebar) sidebar.classList.remove('mobile-hidden');
        if (main) {
            main.classList.remove('mobile-open');
            main.innerHTML = '<div class="chat-main-empty"><span>🏓</span>Select a conversation to start chatting</div>';
        }
        // Refresh view to show updated read counts
        if (isSearching && getSearchVal()) {
            searchPlayers(getSearchVal());
        } else {
            loadConversations(false);
        }
    };

    // ── Search input handler (debounced 300ms) ───────────────
    function onSearchInput() {
        var q = getSearchVal();
        clearTimeout(searchTimer);
        if (!q) {
            isSearching = false;
            renderConvList(allConversations, false);
            return;
        }
        // Instant local filter for snappiness
        var localMatch = allConversations.filter(function (c) {
            return (c.username  || '').toLowerCase().indexOf(q.toLowerCase()) !== -1
                || (c.full_name || '').toLowerCase().indexOf(q.toLowerCase()) !== -1;
        });
        if (localMatch.length > 0) renderConvList(localMatch, false);

        // Full API search after debounce
        searchTimer = setTimeout(function () { searchPlayers(q); }, 300);
    }

    // ── Auto-refresh conversations ───────────────────────────
    function startRefresh() {
        clearInterval(refreshTimer);
        refreshTimer = setInterval(function () {
            loadConversations(true); // silent refresh — don't flash "Loading…"
        }, 5000);
    }

    // ── Boot ─────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        var searchEl = document.getElementById('conv-search');
        if (searchEl) {
            searchEl.addEventListener('input', onSearchInput);
            searchEl.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    searchEl.value = '';
                    isSearching = false;
                    renderConvList(allConversations, false);
                    searchEl.blur();
                }
            });
        }

        // Initial load — start this immediately, don't wait for widget
        loadConversations(false);
        startRefresh();

        // Pre-warm widget readiness check so first click is instant
        waitForWidget(function () {
            // Widget is ready — nothing else needed here
        });
    });

}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>