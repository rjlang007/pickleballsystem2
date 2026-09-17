<?php
// ============================================================
//  FILE: includes/header.php
//  FIXED: nonce on critical <style>, inline styles → CSS classes
//  UPDATED: Added new UX components for better user experience
// ============================================================

// Include new UX components
require_once __DIR__ . '/breadcrumb.php';
require_once __DIR__ . '/toast.php';
require_once __DIR__ . '/modal-confirm.php';
require_once __DIR__ . '/ux_helpers.php';
require_once __DIR__ . '/logo.php';

if (isLoggedIn()) {
    checkSessionTimeout();
}

$flash = getFlash();
$user  = currentUser();

$_jsSecondsRemaining = isLoggedIn() ? sessionSecondsRemaining() : 0;

// ── Chat dismissed state (read cookie server-side → zero flicker) ──
$_chatDismissed = ($_COOKIE['fcb_dismissed'] ?? '') === '1';

// ── Helper: renders the Padol Chat navbar toggle button ──────────
function fcb_toggle_btn(bool $isOn, bool $mobile = false): string {
    $onClass = $isOn ? 'fcb-nt-on' : 'fcb-nt-off';
    $label   = $isOn ? 'Chat: ON'  : 'Chat: OFF';
    $tip     = $isOn ? 'Click to hide the chat bubble' : 'Click to show the chat bubble';
    $pressed = $isOn ? 'true' : 'false';
    return <<<HTML
<button type="button"
        class="fcb-nav-toggle {$onClass}"
        data-tip="{$tip}"
        aria-label="{$tip}"
        aria-pressed="{$pressed}"
        title="{$tip}">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2.5"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
    </svg>
    <span class="fcb-nt-label">{$label}</span>
    <span class="fcb-nt-track" aria-hidden="true"></span>
</button>
HTML;
}

// Pre-fetch pending counts for admin nav
$pendingCount = 0;
$pendingResCount = 0;
$pendingResPreview = [];
$foodPendingCount = 0;
if (isset($user) && $user && isAdmin() && !isSuperAdmin()) {
    try {
        $dbNav = getDB();
        $pendingCount      = (int)$dbNav->query("SELECT COUNT(*) FROM falcon.topup_requests WHERE status='pending'")->fetchColumn();
        $pendingResCount   = (int)$dbNav->query("SELECT COUNT(*) FROM falcon.reservations  WHERE status='pending'")->fetchColumn();
        $pendingResPreview = $dbNav->query("
            SELECT r.id, r.slot_date, r.slot_time, r.party_size,
                   u.username, u.full_name, c.name AS court_name
            FROM falcon.reservations r
            JOIN falcon.users  u ON u.id = r.user_id
            JOIN falcon.courts c ON c.id = r.court_id
            WHERE r.status = 'pending'
            ORDER BY r.slot_date ASC, r.slot_time ASC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
        $foodPendingCount = (int)$dbNav->query("SELECT COUNT(*) FROM falcon.food_orders WHERE status IN ('pending','preparing')")->fetchColumn();
    } catch (Exception $e) {
        $pendingCount = 0; $pendingResCount = 0; $pendingResPreview = []; $foodPendingCount = 0;
    }
}

$navAvatarUrl = null;
$navInitial   = '?';
if (isset($user) && $user) {
    $navAvatarPath = $user['avatar_path'] ?? null;
    $navAvatarUrl  = ($navAvatarPath && file_exists(UPLOAD_AVATARS . $navAvatarPath))
                        ? APP_URL . '/uploads/avatars/' . urlencode($navAvatarPath)
                        : null;
    $navInitial = strtoupper(substr($user['username'] ?? '?', 0, 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="data:,">
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
    <title><?= isset($pageTitle) ? clean($pageTitle) . ' — ' : '' ?><?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css"/>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/components.css"/>

    <!--
    ╔══════════════════════════════════════════════════════════╗
    ║  CRITICAL — nonce is REQUIRED so CSP allows this block   ║
    ║  Without nonce, these display:none rules get blocked,    ║
    ║  causing the session modal + overlay to show on load.    ║
    ╚══════════════════════════════════════════════════════════╝
    -->
    <style nonce="<?= csrfNonce() ?>">
        /* Force overlay hidden at parse time — before JS runs */
        #mobile-nav-overlay { display: none !important; }
        #mobile-nav-overlay.open { display: flex !important; }

        /* Prevent session modal flash on load */
        #session-timeout-modal { display: none !important; }
        #session-timeout-modal.visible { display: flex !important; }
    </style>

    <style nonce="<?= csrfNonce() ?>">
        /* ── pb-logo helper ──────────────────────────────────── */
        .pb-logo {
            flex-shrink: 0;
            filter: drop-shadow(0 0 6px rgba(0,229,160,0.5));
            transition: transform 0.25s var(--ease, ease);
        }

        /* ── Navbar brand ─────────────────────────────────────── */
        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 9px;
            text-decoration: none;
            color: var(--text);
            font-family: 'Bebas Neue', sans-serif;
            font-size: clamp(15px, 2.5vw, 22px);
            letter-spacing: 1px;
            flex-shrink: 0;
            white-space: nowrap;
            min-width: 0;
            transition: opacity 0.2s ease;
        }
        .navbar-brand:hover { opacity: 0.85; }
        .navbar-brand:hover .pb-logo { transform: scale(1.06) rotate(-4deg); }
        .navbar-brand span {
            background: var(--grad-accent);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* ── Desktop nav links ──────────────────────────────── */
        .navbar-links {
            display: flex;
            align-items: center;
            gap: 2px;
            flex-wrap: nowrap;
            min-width: 0;
            overflow: visible;
        }
        .navbar-links > a {
            color: var(--muted);
            text-decoration: none;
            padding: 6px 10px;
            border-radius: var(--radius-sm, 8px);
            font-size: 13px;
            font-weight: 500;
            white-space: nowrap;
            transition: color 0.2s, background 0.2s;
            min-height: 36px;
            display: inline-flex;
            align-items: center;
        }
        .navbar-links > a:hover { color: var(--text); background: var(--surface2); }
        .navbar-links > a:active { transform: scale(0.97); }

        /* ── nav-superadmin highlight ── */
        .nav-superadmin-link {
            color: #6ee7b7 !important;
            font-weight: 700 !important;
        }

        /* ── Dropdown groups ─────────────────────────────────── */
        .nav-group { position: relative; display: inline-block; }
        .nav-group-btn {
            cursor: pointer;
            background: none;
            border: 1px solid transparent;
            color: var(--text);
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            padding: 6px 10px;
            border-radius: var(--radius-sm, 8px);
            display: flex;
            align-items: center;
            gap: 6px;
            transition: color 0.2s, background 0.2s, border-color 0.2s;
            touch-action: manipulation;
            white-space: nowrap;
            min-height: 40px;
        }
        .nav-group-btn:hover { color: var(--accent); background: rgba(255,255,255,0.03); }
        .nav-group-btn .arrow {
            font-size: 10px;
            opacity: 0.55;
            transition: transform 0.2s;
            display: inline-block;
            line-height: 1;
        }
        .nav-group.open .nav-group-btn .arrow { transform: rotate(180deg); }
        .nav-group.open .nav-group-btn { color: var(--accent); background: rgba(0,229,160,0.08); border-color: rgba(0,229,160,0.2); }

        .nav-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            background: var(--surface2, var(--surface));
            border: 1px solid var(--border-soft, var(--border));
            border-radius: var(--radius, 12px);
            padding: 8px;
            min-width: 215px;
            z-index: 9999;
            box-shadow: var(--shadow-lg, 0 8px 32px rgba(0,0,0,0.5));
            animation: navDropIn 0.16s var(--ease, ease) both;
        }
        @keyframes navDropIn {
            from { opacity: 0; transform: translateY(-4px) scale(0.98); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        @media (prefers-reduced-motion: reduce) { .nav-dropdown { animation: none; } }
        .nav-group.open .nav-dropdown { display: block; }
        .nav-dropdown a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: var(--radius-sm, 8px);
            text-decoration: none;
            color: var(--text);
            font-size: 13px;
            font-weight: 500;
            transition: background 0.15s, color 0.15s, transform 0.15s;
            white-space: nowrap;
            min-height: 44px;
        }
        .nav-dropdown a:hover { background: rgba(0,229,160,0.1); color: var(--accent); transform: translateX(2px); }
        .nav-dropdown .sep { height: 1px; background: var(--border); margin: 6px 0; }
        .nav-dropdown .section-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--muted);
            padding: 4px 12px;
            font-weight: 700;
        }

        /* ── Nav badges & avatars ────────────────────────────── */
        .nav-badge {
            background: var(--warn);
            color: #000;
            border-radius: 20px;
            padding: 1px 7px;
            font-size: 10px;
            font-weight: 700;
            margin-left: auto;
            box-shadow: 0 0 0 2px rgba(245,158,11,0.18);
        }
        .nav-badge.red { background: var(--danger); color: #fff; box-shadow: 0 0 0 2px rgba(239,68,68,0.18); }
        .nav-avatar {
            width: 28px; height: 28px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent);
            flex-shrink: 0;
            transition: box-shadow 0.2s;
        }
        .nav-avatar-placeholder {
            width: 28px; height: 28px;
            border-radius: 50%;
            background: var(--grad-accent, var(--accent));
            color: #04120c;
            font-weight: 700;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 2px solid var(--accent);
        }
        .nav-group-btn:hover .nav-avatar,
        .nav-group.open .nav-avatar { box-shadow: 0 0 0 3px rgba(0,229,160,0.18); }

        /* ── Schedule nav group ──────────────────────────────── */
        #ng-schedule .nav-group-btn {
            background: rgba(0,229,160,0.07);
            border: 1px solid rgba(0,229,160,0.22);
            color: var(--accent);
            border-radius: 8px;
            padding: 6px 12px;
            font-weight: 700;
        }
        #ng-schedule .nav-group-btn:hover,
        #ng-schedule.open .nav-group-btn { background: rgba(0,229,160,0.16); border-color: var(--accent); }
        #ng-schedule .nav-dropdown { min-width: 300px; right: 0; left: auto; }

        .sched-live-badge {
            position: relative;
            background: #f59e0b;
            color: #000;
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: 800;
        }
        .sched-live-badge::before {
            content: '';
            position: absolute;
            inset: -3px;
            border-radius: 20px;
            border: 2px solid #f59e0b;
            animation: badgePulse 1.6s ease-out infinite;
        }
        @keyframes badgePulse {
            0%   { opacity: 0.9; transform: scale(1); }
            70%  { opacity: 0;   transform: scale(1.5); }
            100% { opacity: 0;   transform: scale(1.5); }
        }
        .sched-preview-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 8px;
            border: 1px solid rgba(245,158,11,0.2);
            background: rgba(245,158,11,0.06);
            margin-bottom: 4px;
            text-decoration: none;
            color: var(--text);
            transition: background 0.15s, border-color 0.15s;
        }
        .sched-preview-item:hover { background: rgba(245,158,11,0.14); border-color: rgba(245,158,11,0.5); }
        .sched-preview-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #f59e0b;
            flex-shrink: 0;
            box-shadow: 0 0 0 3px rgba(245,158,11,0.2);
        }
        .sched-footer-link {
            display: block;
            text-align: center;
            padding: 9px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            color: var(--accent);
            text-decoration: none;
            background: rgba(0,229,160,0.07);
            border: 1px solid rgba(0,229,160,0.2);
            margin-top: 4px;
            transition: background 0.15s;
        }
        .sched-footer-link:hover { background: rgba(0,229,160,0.15); }
        .sched-live-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: #f59e0b;
            flex-shrink: 0;
            animation: dotBlink 1.4s ease-in-out infinite;
        }
        @keyframes dotBlink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        /* ══════════════════════════════════════════════════════
           PADOL CHAT NAVBAR TOGGLE
           ══════════════════════════════════════════════════════ */
        .fcb-nav-toggle {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 0 10px 0 6px;
            border-radius: 99px;
            border: 1.5px solid transparent;
            cursor: pointer;
            font-family: inherit;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            white-space: nowrap;
            height: 30px;
            touch-action: manipulation;
            transition: background 0.22s ease, border-color 0.22s ease,
                        box-shadow 0.22s ease, transform 0.15s ease;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
            flex-shrink: 0;
        }
        .fcb-nav-toggle:active { transform: scale(0.95); }
        .fcb-nav-toggle.fcb-nt-on {
            background: rgba(0, 229, 160, 0.15);
            border-color: rgba(0, 229, 160, 0.55);
            color: #00e5a0;
            box-shadow: 0 0 10px rgba(0, 229, 160, 0.18);
        }
        .fcb-nav-toggle.fcb-nt-on:hover {
            background: rgba(0, 229, 160, 0.24);
            border-color: #00e5a0;
            box-shadow: 0 0 18px rgba(0, 229, 160, 0.32);
        }
        .fcb-nav-toggle.fcb-nt-off {
            background: rgba(239, 68, 68, 0.08);
            border-color: rgba(239, 68, 68, 0.35);
            color: #f87171;
        }
        .fcb-nav-toggle.fcb-nt-off:hover {
            background: rgba(239, 68, 68, 0.16);
            border-color: rgba(239, 68, 68, 0.7);
            box-shadow: 0 0 12px rgba(239, 68, 68, 0.2);
        }
        .fcb-nt-track {
            position: relative;
            width: 28px;
            height: 16px;
            border-radius: 99px;
            flex-shrink: 0;
            transition: background 0.25s ease;
        }
        .fcb-nt-on  .fcb-nt-track { background: #00e5a0; }
        .fcb-nt-off .fcb-nt-track { background: rgba(239, 68, 68, 0.4); border: 1px solid rgba(239,68,68,0.5); }
        .fcb-nt-track::after {
            content: '';
            position: absolute;
            top: 2px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.4);
            transition: left 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), background 0.2s ease;
        }
        .fcb-nt-on  .fcb-nt-track::after { left: 14px; }
        .fcb-nt-off .fcb-nt-track::after { left: 2px; background: #f87171; }
        .fcb-nt-label { line-height: 1; }
        @media (max-width: 1100px) {
            .fcb-nt-label { display: none; }
            .fcb-nav-toggle { padding: 0 6px; gap: 5px; }
        }
        .fcb-nav-toggle::after {
            content: attr(data-tip);
            position: absolute;
            bottom: calc(100% + 9px);
            left: 50%;
            transform: translateX(-50%) translateY(4px);
            background: #080d18;
            color: #e8f0fe;
            font-size: 11px;
            font-weight: 500;
            text-transform: none;
            letter-spacing: 0;
            white-space: nowrap;
            padding: 5px 10px;
            border-radius: 6px;
            border: 1px solid rgba(0, 229, 160, 0.2);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.18s ease, transform 0.18s ease;
            z-index: 99999;
        }
        .fcb-nav-toggle:hover::after { opacity: 1; transform: translateX(-50%) translateY(0); }
        @media (max-width: 768px) { .fcb-nav-toggle::after { display: none; } }

        /* ── Mobile overlay override ── */
        #mobile-nav-overlay .fcb-nav-toggle {
            width: 100%;
            height: auto;
            min-height: 52px;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 13px;
            letter-spacing: 0.03em;
            gap: 12px;
            justify-content: flex-start;
            text-transform: none;
            font-weight: 700;
        }
        #mobile-nav-overlay .fcb-nav-toggle .fcb-nt-label {
            display: block;
            flex: 1;
            text-align: left;
        }
        #mobile-nav-overlay .fcb-nav-toggle .fcb-nt-track {
            width: 36px;
            height: 20px;
            margin-left: auto;
        }
        #mobile-nav-overlay .fcb-nav-toggle .fcb-nt-track::after {
            width: 16px;
            height: 16px;
            top: 2px;
        }
        #mobile-nav-overlay .fcb-nt-on  .fcb-nt-track::after { left: 18px; }
        #mobile-nav-overlay .fcb-nt-off .fcb-nt-track::after { left: 2px; }
        #mobile-nav-overlay .fcb-nt-on  { border-color: rgba(0,229,160,0.4); background: rgba(0,229,160,0.09); border-radius: 10px; }
        #mobile-nav-overlay .fcb-nt-off { border-color: rgba(239,68,68,0.3);  background: rgba(239,68,68,0.06); border-radius: 10px; }

        /* ── Tablet navbar ─────────────────────────────────── */
        @media (min-width: 769px) and (max-width: 1100px) {
            .navbar-links { gap: 2px !important; }
            .navbar-links > a { font-size: 12px; padding: 5px 8px; }
            .nav-group-btn { font-size: 12px; padding: 5px 7px; }
            .navbar-brand { font-size: 17px; }
            .fcb-nav-toggle { padding: 5px 8px; }
        }

        /* ── Hamburger ─────────────────────────────────────── */
        #nav-hamburger {
            display: none;
            position: fixed;
            top: max(10px, env(safe-area-inset-top, 10px));
            right: max(12px, env(safe-area-inset-right, 12px));
            z-index: 99999;
            width: 44px; height: 44px;
            border-radius: 8px;
            border: 1.5px solid var(--border);
            background: rgba(10,15,30,0.95);
            color: var(--text);
            font-size: 20px;
            line-height: 1;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            touch-action: manipulation;
            transition: border-color 0.2s, color 0.2s, background 0.2s;
            -webkit-appearance: none;
            appearance: none;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        #nav-hamburger:hover { border-color: var(--accent); color: var(--accent); box-shadow: 0 0 0 4px rgba(0,229,160,0.08); }
        #nav-hamburger:active { transform: scale(0.94); }
        #nav-hamburger[aria-expanded="true"] {
            border-color: var(--accent);
            color: var(--accent);
            background: rgba(0,229,160,0.12);
        }

        /* ── Mobile overlay ────────────────────────────────── */
        #mobile-nav-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: 99998;
            background: rgba(5,13,18,0.98);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            flex-direction: column;
            align-items: stretch;
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
            padding-top: max(68px, calc(64px + env(safe-area-inset-top, 0px)));
            padding-bottom: max(40px, env(safe-area-inset-bottom, 20px));
            padding-left:  max(20px, env(safe-area-inset-left,  0px));
            padding-right: max(20px, env(safe-area-inset-right, 0px));
            gap: 2px;
        }
        #mobile-nav-overlay.open {
            animation: mobNavIn 0.22s ease both;
        }
        @keyframes mobNavIn {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .mob-section-label {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--muted);
            padding: 14px 16px 4px;
            pointer-events: none;
            user-select: none;
        }

        #mobile-nav-overlay > a {
            display: flex;
            align-items: center;
            min-height: 52px;
            padding: 12px 16px;
            font-size: 16px;
            font-weight: 600;
            border-radius: var(--radius, 10px);
            border: 1px solid transparent;
            color: var(--text);
            text-decoration: none;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
            background: rgba(255,255,255,0.015);
        }
        #mobile-nav-overlay > a:hover,
        #mobile-nav-overlay > a:active {
            background: rgba(0,229,160,0.09);
            border-color: rgba(0,229,160,0.2);
            color: var(--accent);
        }
        #mobile-nav-overlay > a.btn-primary,
        #mobile-nav-overlay > a.btn-outline {
            justify-content: center;
            margin-top: 6px;
        }

        #mobile-nav-overlay .nav-group {
            display: block;
            width: 100%;
            position: static;
        }
        #mobile-nav-overlay .nav-group-btn {
            width: 100%;
            justify-content: space-between;
            min-height: 52px;
            padding: 12px 16px;
            font-size: 16px;
            border-radius: 10px;
            border: 1px solid transparent !important;
            background: none;
        }
        #mobile-nav-overlay .nav-group-btn:hover,
        #mobile-nav-overlay .nav-group.open .nav-group-btn {
            background: rgba(0,229,160,0.09) !important;
            border-color: rgba(0,229,160,0.2) !important;
            color: var(--accent);
        }
        #mobile-nav-overlay .nav-group.open .nav-group-btn .arrow {
            transform: rotate(180deg);
            opacity: 1;
            color: var(--accent);
        }

        #mobile-nav-overlay .nav-dropdown {
            position: static !important;
            display: none;
            box-shadow: none;
            border: none;
            border-left: 2px solid rgba(0,229,160,0.2);
            border-radius: 0;
            padding: 4px 0 4px 12px;
            margin: 2px 0 4px 16px;
            background: transparent;
            min-width: unset !important;
            width: auto;
        }
        #mobile-nav-overlay .nav-group.open .nav-dropdown { display: block; }
        #mobile-nav-overlay .nav-dropdown a {
            min-height: 48px;
            font-size: 14px;
            padding: 10px 12px;
            white-space: normal;
        }
        #mobile-nav-overlay .nav-dropdown .section-label { font-size: 9px; padding: 6px 12px 2px; }
        #mobile-nav-overlay .nav-dropdown .sep { margin: 4px 8px; }

        #mobile-nav-overlay #ng-schedule-m .nav-group-btn {
            background: rgba(0,229,160,0.07) !important;
            border-color: rgba(0,229,160,0.22) !important;
            color: var(--accent) !important;
            font-weight: 700;
        }
        #mobile-nav-overlay #ng-schedule-m.open .nav-group-btn {
            background: rgba(0,229,160,0.14) !important;
            border-color: var(--accent) !important;
        }

        .fcp-nav-icon-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
        }

        /* Mobile nav chat badge (inline in overlay) */
        .mob-chat-badge {
            position: static;
            display: none;
            margin-left: 8px;
            border-radius: 99px;
            background: #ef4444;
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            padding: 2px 7px;
        }

        /* Mobile logout link style */
        .mob-logout-link {
            justify-content: center;
            margin-top: 8px;
            color: var(--danger, #ef4444) !important;
            border-color: rgba(239,68,68,0.3) !important;
        }

        /* Mobile dashboard link */
        .mob-dashboard-link {
            justify-content: center;
            margin-top: 4px;
        }

        @media (max-width: 768px) {
            #nav-hamburger   { display: flex; }
            .navbar-links    { display: none !important; }
        }

        /* ── Session Timeout Modal ─────────────────────────── */
        #session-timeout-modal {
            position: fixed;
            inset: 0;
            z-index: 999999;
            background: rgba(5,13,18,0.88);
            backdrop-filter: blur(6px);
            align-items: center;
            justify-content: center;
            padding: 20px;
            padding-bottom: max(20px, env(safe-area-inset-bottom, 0px));
            padding-left:   max(20px, env(safe-area-inset-left,   0px));
            padding-right:  max(20px, env(safe-area-inset-right,  0px));
            box-sizing: border-box;
        }
        .session-modal-box {
            background: var(--surface, #0d1526);
            border: 1px solid var(--warn, #f59e0b);
            border-radius: 16px;
            padding: clamp(24px, 5vw, 36px) clamp(20px, 6vw, 40px);
            max-width: 420px;
            width: 100%;
            text-align: center;
            box-shadow: 0 0 60px rgba(245,158,11,0.2);
            animation: modalIn 0.3s cubic-bezier(0.34,1.56,0.64,1) both;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.88) translateY(20px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .session-modal-icon  { font-size: 52px; margin-bottom: 12px; line-height: 1; }
        .session-modal-title {
            font-family: 'Bebas Neue', sans-serif;
            font-size: clamp(22px, 5vw, 28px);
            letter-spacing: 1px;
            color: var(--warn, #f59e0b);
            margin-bottom: 8px;
        }
        .session-modal-desc  { font-size: 14px; color: var(--muted, #6b7fa3); margin-bottom: 24px; line-height: 1.6; }
        #session-countdown-display {
            font-family: 'Bebas Neue', monospace;
            font-size: clamp(36px, 8vw, 52px);
            color: var(--warn, #f59e0b);
            line-height: 1;
            margin-bottom: 24px;
            letter-spacing: 2px;
        }
        #session-countdown-display.urgent { color: var(--danger, #ef4444); animation: urgentPulse 0.8s ease-in-out infinite; }
        @keyframes urgentPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .session-modal-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .session-modal-actions button {
            flex: 1 1 140px;
            padding: 11px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            font-family: inherit;
            transition: all 0.2s;
            min-height: 48px;
            touch-action: manipulation;
        }
        #btn-stay-logged-in { background: var(--accent, #00e5a0); color: var(--bg, #050d12); }
        #btn-stay-logged-in:hover { background: #00ffb2; transform: translateY(-1px); }
        #btn-logout-now { background: transparent; color: var(--muted, #6b7fa3); border: 1px solid var(--border, rgba(0,229,160,0.12)) !important; }
        #btn-logout-now:hover { color: var(--danger); border-color: var(--danger) !important; }
        @media (max-width: 400px) {
            .session-modal-actions { flex-direction: column; }
            .session-modal-actions button { width: 100%; flex: none; }
        }

        /* ── Sched dropdown header row ── */
        .sched-dd-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 4px 12px 8px;
            flex-wrap: wrap;
            gap: 4px;
        }
        .sched-dd-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--muted);
            font-weight: 700;
        }
        .sched-dd-count {
            font-size: 11px;
            color: var(--muted);
        }

        /* ── nav account dropdown (right-aligned) ── */
        .nav-dropdown-right { right: 0; left: auto; }

        /* ── nav account btn slim ── */
        .nav-acct-btn { padding: 4px 8px; }

        /* ── impersonation bar ── */
        .impersonate-bar {
            background: #059669;
            color: #fff;
            text-align: center;
            padding: 8px max(16px,env(safe-area-inset-right,16px)) 8px max(16px,env(safe-area-inset-left,16px));
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 9999;
            flex-wrap: wrap;
        }
        .impersonate-stop-btn {
            background: #fff;
            color: #059669;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            text-decoration: none;
            font-weight: 700;
            white-space: nowrap;
        }

        /* ── fcp nav icon ── */
        .fcp-nav-icon {
            font-size: 19px;
            position: relative;
        }
        button.fcp-nav-icon {
            background: none;
            border: none;
            cursor: pointer;
        }
    </style>
</head>
<body>

<a href="#main-content" class="skip-link">Skip to content</a>

<!-- Global Toast Notifications -->
<?= renderToastContainer() ?>

<!-- Global Confirmation Modal -->
<?php renderConfirmModal(); ?>

<?php if (isImpersonating()): ?>
<div class="impersonate-bar">
    <span>
        👁 Viewing as <strong><?= clean($_SESSION['username']) ?></strong>
        (<?= clean($_SESSION['role']) ?>)
        — You are <strong><?= clean($_SESSION['_real_username'] ?? '') ?></strong>
    </span>
    <a href="<?= APP_URL ?>/superadmin/impersonate_stop.php" class="impersonate-stop-btn">
        ✕ Stop
    </a>
</div>
<?php endif; ?>

<?php if (isLoggedIn()): ?>
<div id="session-timeout-modal" role="dialog" aria-modal="true" aria-labelledby="session-modal-title">
    <div class="session-modal-box">
        <div class="session-modal-icon">⏱</div>
        <div class="session-modal-title" id="session-modal-title">Still There?</div>
        <div class="session-modal-desc">Your session will expire due to inactivity in:</div>
        <div id="session-countdown-display">2:00</div>
        <div class="session-modal-actions">
            <button id="btn-stay-logged-in" onclick="stayLoggedIn()">✓ Keep Me Logged In</button>
            <button id="btn-logout-now"     onclick="logoutNow()">Logout Now</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ NAVBAR ══════════════════════════════════════════════ -->
<nav class="navbar">
    <a class="navbar-brand" href="<?= APP_URL ?>/index.php">
        <?= pickleballLogo(28) ?>
        <span><?= APP_NAME ?></span>
    </a>

    <div class="navbar-links" id="navbar-links">
    <?php if ($user): ?>

        <?php if (isSuperAdmin() && !isImpersonating()): ?>
            <a href="<?= APP_URL ?>/superadmin/dashboard.php" class="nav-superadmin-link">⚡ SuperAdmin</a>
            <a href="<?= APP_URL ?>/superadmin/users.php">Users</a>
            <a href="<?= APP_URL ?>/superadmin/activity_monitor.php">Activity Log</a>
            <a href="<?= APP_URL ?>/superadmin/impersonate.php">Impersonate</a>
            <a href="<?= APP_URL ?>/admin/dashboard.php">Admin View</a>
            <a href="<?= APP_URL ?>/player/dashboard.php">Player View</a>
            <a href="<?= APP_URL ?>/admin/activity_manager.php">🎯 Activity Center</a>
            <a href="<?= APP_URL ?>/admin/court_mode.php">🏓 Court Mode</a>
        <?php elseif (isAdmin()): ?>
            <div class="nav-group" id="ng-courts">
                <button class="nav-group-btn" onclick="toggleNavGroup('ng-courts')"><?= pickleballLogo(18) ?> Courts <span class="arrow">▾</span></button>
                <div class="nav-dropdown">
                    <div class="section-label">Live</div>
                    <a href="<?= APP_URL ?>/court/scanner.php">📷 Court Scanner</a>
                    <a href="<?= APP_URL ?>/admin/active_game.php">🎮 Game Monitor</a>
                    <div class="sep"></div>
                    <div class="section-label">Settings</div>
                    <a href="<?= APP_URL ?>/admin/court_settings.php">🏟️ Manage Courts</a>
                    <a href="<?= APP_URL ?>/admin/game_history.php">📋 Game History</a>
                    <a href="<?= APP_URL ?>/admin/scan_logs.php">📊 Scan Logs</a>
                </div>
            </div>
            <div class="nav-group" id="ng-players">
                <button class="nav-group-btn" onclick="toggleNavGroup('ng-players')">👥 Players <span class="arrow">▾</span></button>
                <div class="nav-dropdown">
                    <a href="<?= APP_URL ?>/admin/players.php">👥 All Players</a>
                    <a href="<?= APP_URL ?>/admin/create_player.php">➕ Add Player</a>
                    <div class="sep"></div>
                    <a href="<?= APP_URL ?>/admin/manage_staff.php">🧑‍💼 Staff &amp; Referees</a>
                    <a href="<?= APP_URL ?>/admin/create_staff.php">➕ Add Staff / Referee</a>
                </div>
            </div>
            <div class="nav-group" id="ng-finance">
                <button class="nav-group-btn" onclick="toggleNavGroup('ng-finance')">
                    💳 Finance
                    <?php if ($pendingCount > 0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?>
                    <span class="arrow">▾</span>
                </button>
                <div class="nav-dropdown">
                    <a href="<?= APP_URL ?>/admin/topup_requests.php">⏳ Top-Up Requests <?php if ($pendingCount > 0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?></a>
                    <a href="<?= APP_URL ?>/admin/generate_topup_qr.php">⚡ In-Person Top-Up</a>
                    <a href="<?= APP_URL ?>/admin/topup_history.php">📋 Top-Up History</a>
                    <div class="sep"></div>
                    <a href="<?= APP_URL ?>/admin/payment_options.php">💳 Payment Options</a>
                    <div class="sep"></div>
                    <a href="<?= APP_URL ?>/admin/reports.php">📈 Revenue Reports</a>
                    <a href="<?= APP_URL ?>/admin/export_csv.php">⬇ Export CSV</a>
                </div>
            </div>
            <div class="nav-group" id="ng-food">
                <button class="nav-group-btn" onclick="toggleNavGroup('ng-food')">
                    🍔 Food
                    <?php if ($foodPendingCount > 0): ?><span class="nav-badge"><?= $foodPendingCount ?></span><?php endif; ?>
                    <span class="arrow">▾</span>
                </button>
                <div class="nav-dropdown">
                    <a href="<?= APP_URL ?>/admin/food_orders.php">🧾 Order Queue <?php if ($foodPendingCount > 0): ?><span class="nav-badge"><?= $foodPendingCount ?></span><?php endif; ?></a>
                    <a href="<?= APP_URL ?>/admin/food_menu.php">🍔 Manage Menu</a>
                </div>
            </div>
            <div class="nav-group" id="ng-site">
                <button class="nav-group-btn" onclick="toggleNavGroup('ng-site')">✏️ Site <span class="arrow">▾</span></button>
                <div class="nav-dropdown">
                    <a href="<?= APP_URL ?>/admin/content_manager.php">✏️ Content Manager</a>
                    <a href="<?= APP_URL ?>/admin/activity_manager.php">🎯 Activity Center</a>
                    <a href="<?= APP_URL ?>/admin/court_mode.php">🏓 Court Mode</a>
                    <div class="sep"></div>
                    <a href="<?= APP_URL ?>/tournaments.php">🏆 Tournaments</a>
                    <a href="<?= APP_URL ?>/leaderboard.php">📊 Leaderboard</a>
                    <div class="sep"></div>
                    <a href="<?= APP_URL ?>/player/map.php">🗺️ Map</a>
                </div>
            </div>
            <div class="nav-group" id="ng-schedule">
                <button class="nav-group-btn" onclick="toggleNavGroup('ng-schedule')" id="sched-nav-btn">
                    <?php if ($pendingResCount > 0): ?><span class="sched-live-dot"></span><?php else: ?>📅<?php endif; ?>
                    Schedule
                    <span id="sched-badge-wrap"><?php if ($pendingResCount > 0): ?><span class="sched-live-badge" id="sched-badge"><?= $pendingResCount ?></span><?php endif; ?></span>
                    <span class="arrow">▾</span>
                </button>
                <div class="nav-dropdown" id="sched-dropdown">
                    <div class="sched-dd-header">
                        <span class="sched-dd-label">Pending Reservations</span>
                        <span id="sched-dropdown-count" class="sched-dd-count"><?= $pendingResCount ?> pending</span>
                    </div>
                    <div id="sched-preview-list">
                        <?php if (empty($pendingResPreview)): ?>
                            <div class="sched-no-pending">🎉 No pending reservations</div>
                        <?php else: ?>
                            <?php foreach ($pendingResPreview as $p): ?>
                            <a href="<?= APP_URL ?>/admin/schedule.php?date=<?= $p['slot_date'] ?>" class="sched-preview-item">
                                <div class="sched-preview-dot"></div>
                                <div class="sched-preview-text">
                                    <div class="sched-preview-name">
                                        <?= clean($p['full_name']) ?> <span class="sched-preview-uname">@<?= clean($p['username']) ?></span>
                                    </div>
                                    <div class="sched-preview-meta">
                                        <?= clean($p['court_name']) ?> · <?= date('M d', strtotime($p['slot_date'])) ?> · <?= date('h:i A', strtotime($p['slot_time'])) ?> · <?= $p['party_size'] ?> player<?= $p['party_size'] > 1 ? 's' : '' ?>
                                    </div>
                                </div>
                                <span class="sched-pending-pill">⏳</span>
                            </a>
                            <?php endforeach; ?>
                            <?php if ($pendingResCount > 5): ?><div class="sched-more-label">+ <?= $pendingResCount - 5 ?> more…</div><?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="sep"></div>
                    <a href="<?= APP_URL ?>/admin/schedule.php" class="sched-footer-link">📅 Open Full Schedule →</a>
                </div>
            </div>

            <a href="<?= APP_URL ?>/admin/chat_inbox.php"
               class="fcp-nav-icon"
               title="Padol Chat — Player Messages">
                💬
                <span class="fcp-nav-badge" id="nav-chat-badge"></span>
            </a>

            <?= fcb_toggle_btn(!$_chatDismissed) ?>

            <div class="nav-group" id="ng-admin-acct">
                <button class="nav-group-btn nav-acct-btn" onclick="toggleNavGroup('ng-admin-acct')">
                    <?php if ($navAvatarUrl): ?><img src="<?= $navAvatarUrl ?>" class="nav-avatar" alt=""/>
                    <?php else: ?><div class="nav-avatar-placeholder"><?= $navInitial ?></div><?php endif; ?>
                    <span class="nav-acct-name"><?= clean($user['username'] ?? '') ?></span>
                    <span class="arrow">▾</span>
                </button>
                <div class="nav-dropdown nav-dropdown-right">
                    <a href="<?= APP_URL ?>/player/profile.php">⚙️ My Profile</a>
                    <a href="<?= APP_URL ?>/player/change_password.php">🔑 Change Password</a>
                </div>
            </div>
            <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline btn-sm nav-dashboard-link">Dashboard</a>

        <?php elseif (isStaffOnly()): /* Staff desktop nav */ ?>
            <a href="<?= APP_URL ?>/staff/dashboard.php">🧑‍💼 Console</a>
            <a href="<?= APP_URL ?>/court/scanner.php">📷 Scanner</a>
            <a href="<?= APP_URL ?>/admin/active_game.php">🎮 Game Monitor</a>
            <a href="<?= APP_URL ?>/admin/court_mode.php">🏓 Court Mode</a>
            <a href="<?= APP_URL ?>/staff/tournament_queue.php">🏆 Tournament Queue</a>
            <a href="<?= APP_URL ?>/admin/kiosk.php">🖥️ Kiosk</a>
            <a href="<?= APP_URL ?>/admin/food_orders.php">🍔 Food Orders</a>

            <div class="nav-group" id="ng-staff-acct">
                <button class="nav-group-btn nav-acct-btn" onclick="toggleNavGroup('ng-staff-acct')">
                    <?php if ($navAvatarUrl): ?><img src="<?= $navAvatarUrl ?>" class="nav-avatar" alt=""/>
                    <?php else: ?><div class="nav-avatar-placeholder"><?= $navInitial ?></div><?php endif; ?>
                    <span class="nav-acct-name"><?= clean($user['username'] ?? '') ?></span>
                    <span class="arrow">▾</span>
                </button>
                <div class="nav-dropdown nav-dropdown-right">
                    <a href="<?= APP_URL ?>/player/profile.php">⚙️ My Profile</a>
                    <a href="<?= APP_URL ?>/player/change_password.php">🔑 Change Password</a>
                </div>
            </div>

        <?php elseif (isRefereeOnly()): /* Referee desktop nav */ ?>
            <a href="<?= APP_URL ?>/referee/dashboard.php">🧑‍⚖️ My Matches</a>
            <a href="<?= APP_URL ?>/tournaments.php">🏆 Tournaments</a>
            <a href="<?= APP_URL ?>/leaderboard.php">📊 Standings</a>

            <div class="nav-group" id="ng-ref-acct">
                <button class="nav-group-btn nav-acct-btn" onclick="toggleNavGroup('ng-ref-acct')">
                    <?php if ($navAvatarUrl): ?><img src="<?= $navAvatarUrl ?>" class="nav-avatar" alt=""/>
                    <?php else: ?><div class="nav-avatar-placeholder"><?= $navInitial ?></div><?php endif; ?>
                    <span class="nav-acct-name"><?= clean($user['username'] ?? '') ?></span>
                    <span class="arrow">▾</span>
                </button>
                <div class="nav-dropdown nav-dropdown-right">
                    <a href="<?= APP_URL ?>/player/profile.php">⚙️ My Profile</a>
                    <a href="<?= APP_URL ?>/player/change_password.php">🔑 Change Password</a>
                </div>
            </div>

        <?php else: /* Player desktop nav */ ?>
            <a href="<?= APP_URL ?>/player/schedule.php">📅 Schedule</a>
            <a href="<?= APP_URL ?>/player/map.php">🗺️ Map</a>
            <a href="<?= APP_URL ?>/leaderboard.php">🏆 Leaderboard</a>
            <a href="<?= APP_URL ?>/tournaments.php">🎯 Tournaments</a>
            <a href="<?= APP_URL ?>/player/food_menu.php">🍔 Food</a>
            <a href="<?= APP_URL ?>/player/dashboard.php">Dashboard</a>
            <a href="<?= APP_URL ?>/player/my_qr.php">📱 My QR</a>
            <a href="<?= APP_URL ?>/public/help.php">❓ Help</a>
            <a href="<?= APP_URL ?>/player/topup.php" class="btn-outline btn-sm">+ Load</a>

            <button onclick="falconChat&&falconChat.openPanel()"
                    class="fcp-nav-icon"
                    title="Padol Chat — Talk to us">
                💬
                <span class="fcp-nav-badge" id="nav-chat-badge"></span>
            </button>

            <?= fcb_toggle_btn(!$_chatDismissed) ?>

            <div class="nav-group" id="ng-account">
                <button class="nav-group-btn nav-acct-btn" onclick="toggleNavGroup('ng-account')">
                    <?php if ($navAvatarUrl): ?><img src="<?= $navAvatarUrl ?>" class="nav-avatar" alt=""/>
                    <?php else: ?><div class="nav-avatar-placeholder"><?= $navInitial ?></div><?php endif; ?>
                    <span class="nav-acct-name"><?= clean($user['username'] ?? '') ?></span>
                    <span class="arrow">▾</span>
                </button>
                <div class="nav-dropdown nav-dropdown-right">
                    <a href="<?= APP_URL ?>/player/history.php">🎮 Game History</a>
                    <a href="<?= APP_URL ?>/player/tournament_history.php">🏆 Tournament History</a>
                    <a href="<?= APP_URL ?>/player/topup_history.php">💳 Credit History</a>
                    <div class="sep"></div>
                    <a href="<?= APP_URL ?>/player/profile.php">⚙️ Profile</a>
                    <a href="<?= APP_URL ?>/player/change_password.php">🔑 Change Password</a>
                </div>
            </div>
        <?php endif; ?>

        <a href="<?= APP_URL ?>/auth/logout.php" class="btn-outline btn-sm nav-logout-link">Logout</a>

    <?php else: /* Guest desktop nav */ ?>
        <a href="<?= APP_URL ?>">Home</a>
        <a href="<?= APP_URL ?>/auth/login.php">Login</a>
        <a href="<?= APP_URL ?>/auth/register.php" class="btn-primary btn-sm">Register</a>
    <?php endif; ?>
    </div><!-- /navbar-links -->
</nav>

<!-- ══ HAMBURGER ════════════════════════════════════════════ -->
<button id="nav-hamburger"
        aria-label="Open navigation menu"
        aria-expanded="false"
        aria-controls="mobile-nav-overlay">☰</button>

<!-- ══ MOBILE NAV OVERLAY ══════════════════════════════════ -->
<div id="mobile-nav-overlay" aria-hidden="true" role="dialog" aria-label="Navigation menu">

<?php if ($user): ?>

    <?php if (isSuperAdmin() && !isImpersonating()): ?>
        <div class="mob-section-label">SuperAdmin</div>
        <a href="<?= APP_URL ?>/superadmin/dashboard.php" class="nav-superadmin-link">⚡ SuperAdmin Dashboard</a>
        <a href="<?= APP_URL ?>/superadmin/users.php">👥 Users</a>
        <a href="<?= APP_URL ?>/superadmin/activity_monitor.php">📋 Activity Log</a>
        <a href="<?= APP_URL ?>/superadmin/impersonate.php">🎭 Impersonate</a>
        <div class="mob-section-label">Switch View</div>
        <a href="<?= APP_URL ?>/admin/dashboard.php">🛠 Admin View</a>
        <a href="<?= APP_URL ?>/player/dashboard.php">🎮 Player View</a>

    <?php elseif (isAdmin()): ?>
        <div class="mob-section-label">Courts</div>
        <div class="nav-group" id="ng-courts-m">
            <button class="nav-group-btn" onclick="toggleNavGroup('ng-courts-m')">🏓 Courts <span class="arrow">▾</span></button>
            <div class="nav-dropdown">
                <div class="section-label">Live</div>
                <a href="<?= APP_URL ?>/court/scanner.php">📷 Court Scanner</a>
                <a href="<?= APP_URL ?>/admin/active_game.php">🎮 Game Monitor</a>
                <div class="sep"></div>
                <div class="section-label">Settings</div>
                <a href="<?= APP_URL ?>/admin/court_settings.php">🏟️ Manage Courts</a>
                <a href="<?= APP_URL ?>/admin/game_history.php">📋 Game History</a>
                <a href="<?= APP_URL ?>/admin/scan_logs.php">📊 Scan Logs</a>
            </div>
        </div>
        <div class="mob-section-label">Players &amp; Finance</div>
        <div class="nav-group" id="ng-players-m">
            <button class="nav-group-btn" onclick="toggleNavGroup('ng-players-m')">👥 Players <span class="arrow">▾</span></button>
            <div class="nav-dropdown">
                <a href="<?= APP_URL ?>/admin/players.php">👥 All Players</a>
                <a href="<?= APP_URL ?>/admin/create_player.php">➕ Add Player</a>
                <div class="sep"></div>
                <a href="<?= APP_URL ?>/admin/manage_staff.php">🧑‍💼 Staff &amp; Referees</a>
                <a href="<?= APP_URL ?>/admin/create_staff.php">➕ Add Staff / Referee</a>
            </div>
        </div>
        <div class="nav-group" id="ng-finance-m">
            <button class="nav-group-btn" onclick="toggleNavGroup('ng-finance-m')">
                💳 Finance
                <?php if ($pendingCount > 0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?>
                <span class="arrow">▾</span>
            </button>
            <div class="nav-dropdown">
                <a href="<?= APP_URL ?>/admin/topup_requests.php">⏳ Top-Up Requests <?php if ($pendingCount > 0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?></a>
                <a href="<?= APP_URL ?>/admin/generate_topup_qr.php">⚡ In-Person Top-Up</a>
                <a href="<?= APP_URL ?>/admin/topup_history.php">📋 Top-Up History</a>
                <div class="sep"></div>
                <a href="<?= APP_URL ?>/admin/payment_options.php">💳 Payment Options</a>
                <a href="<?= APP_URL ?>/admin/reports.php">📈 Revenue Reports</a>
                <a href="<?= APP_URL ?>/admin/export_csv.php">⬇ Export CSV</a>
            </div>
        </div>
        <div class="nav-group" id="ng-food-m">
            <button class="nav-group-btn" onclick="toggleNavGroup('ng-food-m')">
                🍔 Food
                <?php if ($foodPendingCount > 0): ?><span class="nav-badge"><?= $foodPendingCount ?></span><?php endif; ?>
                <span class="arrow">▾</span>
            </button>
            <div class="nav-dropdown">
                <a href="<?= APP_URL ?>/admin/food_orders.php">🧾 Order Queue <?php if ($foodPendingCount > 0): ?><span class="nav-badge"><?= $foodPendingCount ?></span><?php endif; ?></a>
                <a href="<?= APP_URL ?>/admin/food_menu.php">🍔 Manage Menu</a>
            </div>
        </div>
        <div class="mob-section-label">Schedule &amp; Site</div>
        <div class="nav-group" id="ng-site-m">
            <button class="nav-group-btn" onclick="toggleNavGroup('ng-site-m')">✏️ Site <span class="arrow">▾</span></button>
            <div class="nav-dropdown">
                <a href="<?= APP_URL ?>/admin/content_manager.php">✏️ Content Manager</a>
                <a href="<?= APP_URL ?>/admin/activity_manager.php">🎯 Activity Center</a>
                <a href="<?= APP_URL ?>/admin/court_mode.php">🏓 Court Mode</a>
                <div class="sep"></div>
                <a href="<?= APP_URL ?>/tournaments.php">🏆 Tournaments</a>
                <a href="<?= APP_URL ?>/leaderboard.php">📊 Leaderboard</a>
                <div class="sep"></div>
                <a href="<?= APP_URL ?>/player/map.php">🗺️ Map</a>
            </div>
        </div>
        <div class="nav-group" id="ng-schedule-m">
            <button class="nav-group-btn" onclick="toggleNavGroup('ng-schedule-m')">
                <?php if ($pendingResCount > 0): ?><span class="sched-live-dot"></span><?php else: ?>📅<?php endif; ?>
                Schedule
                <?php if ($pendingResCount > 0): ?><span class="sched-live-badge"><?= $pendingResCount ?></span><?php endif; ?>
                <span class="arrow">▾</span>
            </button>
            <div class="nav-dropdown">
                <a href="<?= APP_URL ?>/admin/schedule.php">📅 Full Schedule</a>
                <?php if (!empty($pendingResPreview)): ?>
                    <div class="sep"></div>
                    <div class="section-label">Pending (<?= $pendingResCount ?>)</div>
                    <?php foreach ($pendingResPreview as $p): ?>
                    <a href="<?= APP_URL ?>/admin/schedule.php?date=<?= $p['slot_date'] ?>" class="sched-preview-item">
                        <div class="sched-preview-dot"></div>
                        <div class="sched-preview-text">
                            <div class="sched-preview-name"><?= clean($p['full_name']) ?></div>
                            <div class="sched-preview-meta"><?= clean($p['court_name']) ?> · <?= date('M d', strtotime($p['slot_date'])) ?> · <?= date('h:i A', strtotime($p['slot_time'])) ?></div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="mob-section-label">Chat</div>
        <a href="<?= APP_URL ?>/admin/chat_inbox.php">
            💬 Padol Chat Inbox
            <span class="fcp-nav-badge mob-chat-badge" id="nav-chat-badge-m"></span>
        </a>
        <?= fcb_toggle_btn(!$_chatDismissed, true) ?>

        <div class="mob-section-label">Account</div>
        <div class="nav-group" id="ng-admin-acct-m">
            <button class="nav-group-btn nav-acct-btn" onclick="toggleNavGroup('ng-admin-acct-m')">
                <?php if ($navAvatarUrl): ?><img src="<?= $navAvatarUrl ?>" class="nav-avatar" alt=""/>
                <?php else: ?><div class="nav-avatar-placeholder"><?= $navInitial ?></div><?php endif; ?>
                <span class="nav-acct-name"><?= clean($user['username'] ?? '') ?></span>
                <span class="arrow">▾</span>
            </button>
            <div class="nav-dropdown">
                <a href="<?= APP_URL ?>/player/profile.php">⚙️ My Profile</a>
                <a href="<?= APP_URL ?>/player/change_password.php">🔑 Change Password</a>
            </div>
        </div>
        <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline mob-dashboard-link">🏠 Dashboard</a>

    <?php elseif (isStaffOnly()): /* Staff mobile nav */ ?>
        <div class="mob-section-label">Floor Ops</div>
        <a href="<?= APP_URL ?>/staff/dashboard.php">🧑‍💼 Console</a>
        <a href="<?= APP_URL ?>/court/scanner.php">📷 Court Scanner</a>
        <a href="<?= APP_URL ?>/admin/active_game.php">🎮 Game Monitor</a>
        <a href="<?= APP_URL ?>/admin/court_mode.php">🏓 Court Mode</a>
        <a href="<?= APP_URL ?>/admin/game_history.php">📋 Game History</a>

        <div class="mob-section-label">Tournaments</div>
        <a href="<?= APP_URL ?>/staff/tournament_queue.php">🏆 Tournament Queue</a>
        <a href="<?= APP_URL ?>/admin/kiosk.php">🖥️ Kiosk Display</a>

        <div class="mob-section-label">Food</div>
        <a href="<?= APP_URL ?>/admin/food_orders.php">🍔 Food Orders</a>

        <div class="mob-section-label">Account</div>
        <div class="nav-group" id="ng-staff-acct-m">
            <button class="nav-group-btn nav-acct-btn" onclick="toggleNavGroup('ng-staff-acct-m')">
                <?php if ($navAvatarUrl): ?><img src="<?= $navAvatarUrl ?>" class="nav-avatar" alt=""/>
                <?php else: ?><div class="nav-avatar-placeholder"><?= $navInitial ?></div><?php endif; ?>
                <span class="nav-acct-name"><?= clean($user['username'] ?? '') ?></span>
                <span class="arrow">▾</span>
            </button>
            <div class="nav-dropdown">
                <a href="<?= APP_URL ?>/player/profile.php">⚙️ My Profile</a>
                <a href="<?= APP_URL ?>/player/change_password.php">🔑 Change Password</a>
            </div>
        </div>
        <a href="<?= APP_URL ?>/staff/dashboard.php" class="btn-outline mob-dashboard-link">🏠 Dashboard</a>

    <?php elseif (isRefereeOnly()): /* Referee mobile nav */ ?>
        <div class="mob-section-label">Referee</div>
        <a href="<?= APP_URL ?>/referee/dashboard.php">🧑‍⚖️ My Matches</a>
        <a href="<?= APP_URL ?>/tournaments.php">🏆 Tournaments</a>
        <a href="<?= APP_URL ?>/leaderboard.php">📊 Standings</a>

        <div class="mob-section-label">Account</div>
        <div class="nav-group" id="ng-ref-acct-m">
            <button class="nav-group-btn nav-acct-btn" onclick="toggleNavGroup('ng-ref-acct-m')">
                <?php if ($navAvatarUrl): ?><img src="<?= $navAvatarUrl ?>" class="nav-avatar" alt=""/>
                <?php else: ?><div class="nav-avatar-placeholder"><?= $navInitial ?></div><?php endif; ?>
                <span class="nav-acct-name"><?= clean($user['username'] ?? '') ?></span>
                <span class="arrow">▾</span>
            </button>
            <div class="nav-dropdown">
                <a href="<?= APP_URL ?>/player/profile.php">⚙️ My Profile</a>
                <a href="<?= APP_URL ?>/player/change_password.php">🔑 Change Password</a>
            </div>
        </div>
        <a href="<?= APP_URL ?>/referee/dashboard.php" class="btn-outline mob-dashboard-link">🏠 Dashboard</a>

    <?php else: /* Player mobile nav */ ?>
        <div class="mob-section-label">Menu</div>
        <a href="<?= APP_URL ?>/player/dashboard.php">🏠 Dashboard</a>
        <a href="<?= APP_URL ?>/player/schedule.php">📅 Schedule</a>
        <a href="<?= APP_URL ?>/player/map.php">🗺️ Map</a>
        <a href="<?= APP_URL ?>/leaderboard.php">🏆 Leaderboard</a>
        <a href="<?= APP_URL ?>/tournaments.php">🎯 Tournaments</a>
        <a href="<?= APP_URL ?>/player/food_menu.php">🍔 Food</a>
        <a href="<?= APP_URL ?>/player/my_qr.php">📱 My QR</a>
        <a href="<?= APP_URL ?>/public/help.php">❓ Help</a>

        <div class="mob-section-label">Chat</div>
        <a href="#" onclick="event.preventDefault();closeMobileNav();setTimeout(function(){falconChat&&falconChat.openPanel();},150);">
            💬 Open Padol Chat
            <span class="fcp-nav-badge mob-chat-badge" id="nav-chat-badge-m"></span>
        </a>
        <?= fcb_toggle_btn(!$_chatDismissed, true) ?>

        <div class="mob-section-label">Account</div>
        <div class="nav-group" id="ng-account-m">
            <button class="nav-group-btn nav-acct-btn" onclick="toggleNavGroup('ng-account-m')">
                <?php if ($navAvatarUrl): ?><img src="<?= $navAvatarUrl ?>" class="nav-avatar" alt=""/>
                <?php else: ?><div class="nav-avatar-placeholder"><?= $navInitial ?></div><?php endif; ?>
                <span class="nav-acct-name"><?= clean($user['username'] ?? '') ?></span>
                <span class="arrow">▾</span>
            </button>
            <div class="nav-dropdown">
                <a href="<?= APP_URL ?>/player/history.php">🎮 Game History</a>
                <a href="<?= APP_URL ?>/player/tournament_history.php">🏆 Tournament History</a>
                <a href="<?= APP_URL ?>/player/topup_history.php">💳 Credit History</a>
                <a href="<?= APP_URL ?>/player/food_orders.php">🍔 Food Orders</a>
                <div class="sep"></div>
                <a href="<?= APP_URL ?>/player/profile.php">⚙️ Profile</a>
                <a href="<?= APP_URL ?>/player/change_password.php">🔑 Change Password</a>
            </div>
        </div>
        <a href="<?= APP_URL ?>/player/topup.php" class="btn-outline mob-dashboard-link">+ Load Credits</a>
    <?php endif; ?>

    <a href="<?= APP_URL ?>/auth/logout.php" class="btn-outline mob-logout-link">
        🚪 Logout
    </a>

<?php else: /* Guest mobile nav */ ?>
    <div class="mob-section-label">Welcome</div>
    <a href="<?= APP_URL ?>">🏠 Home</a>
    <a href="<?= APP_URL ?>/auth/login.php">🔑 Login</a>
    <a href="<?= APP_URL ?>/auth/register.php" class="btn-primary mob-dashboard-link">📝 Register</a>
<?php endif; ?>

</div><!-- /#mobile-nav-overlay -->

<?php if ($flash): ?>
<div class="flash flash-<?= $flash['type'] ?>">
    <span><?= clean($flash['message']) ?></span>
    <button onclick="this.parentElement.remove()" aria-label="Dismiss">✕</button>
</div>
<?php endif; ?>

<main class="main-content" id="main-content">

<script nonce="<?= csrfNonce() ?>">
/* ═══════════════════════════════════════════════════════════════
   CRITICAL FIX 1 — Reset any stale overflow lock from bfcache.
   ═══════════════════════════════════════════════════════════════ */
(function() {
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            var overlay   = document.getElementById('mobile-nav-overlay');
            var hamburger = document.getElementById('nav-hamburger');
            if (overlay)   { overlay.classList.remove('open'); overlay.setAttribute('aria-hidden','true'); }
            if (hamburger) { hamburger.setAttribute('aria-expanded','false'); hamburger.textContent = '☰'; hamburger.setAttribute('aria-label','Open navigation menu'); }
            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';
            document.querySelectorAll('.nav-group.open').forEach(function(g){ g.classList.remove('open'); });
        }
    });
})();

/* ── Desktop dropdown ──────────────────────────────────────── */
function toggleNavGroup(id) {
    var group = document.getElementById(id);
    if (!group) return;
    var isOpen = group.classList.contains('open');
    document.querySelectorAll('.nav-group.open').forEach(function(g){ g.classList.remove('open'); });
    if (!isOpen) group.classList.add('open');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.nav-group'))
        document.querySelectorAll('.nav-group.open').forEach(function(g){ g.classList.remove('open'); });
});

/* ── Mobile hamburger ──────────────────────────────────────── */
(function() {
    var hamburger = document.getElementById('nav-hamburger');
    var overlay   = document.getElementById('mobile-nav-overlay');
    if (!hamburger || !overlay) return;

    function openMenu() {
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        hamburger.setAttribute('aria-expanded', 'true');
        hamburger.setAttribute('aria-label', 'Close navigation menu');
        hamburger.textContent = '✕';
        document.body.style.overflow = 'hidden';
    }

    function closeMenu() {
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
        hamburger.setAttribute('aria-expanded', 'false');
        hamburger.setAttribute('aria-label', 'Open navigation menu');
        hamburger.textContent = '☰';
        document.body.style.overflow = '';
        document.documentElement.style.overflow = '';
        overlay.querySelectorAll('.nav-group.open').forEach(function(g){ g.classList.remove('open'); });
    }

    window.closeMobileNav = closeMenu;

    hamburger.addEventListener('click', function(e) {
        e.stopPropagation();
        overlay.classList.contains('open') ? closeMenu() : openMenu();
    });

    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeMenu();
    });

    document.addEventListener('click', function(e) {
        if (overlay.classList.contains('open') &&
            !overlay.contains(e.target) &&
            !hamburger.contains(e.target)) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) closeMenu();
    });

    overlay.querySelectorAll('a[href]').forEach(function(a) {
        var href = a.getAttribute('href');
        if (href && href !== '#' && !a.classList.contains('fcb-nav-toggle')) {
            a.addEventListener('click', function() { setTimeout(closeMenu, 80); });
        }
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) closeMenu();
    });
})();

/* ── Admin schedule polling ────────────────────────────────── */
<?php if (isAdmin() && !isSuperAdmin()): ?>
if (typeof APP_URL === 'undefined') window.APP_URL = '<?= APP_URL ?>';
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function renderSchedPreview(data) {
    var badgeWrap  = document.getElementById('sched-badge-wrap');
    var countLabel = document.getElementById('sched-dropdown-count');
    var list       = document.getElementById('sched-preview-list');
    var btn        = document.getElementById('sched-nav-btn');
    if (!badgeWrap || !list || !btn) return;
    var count = data.count != null ? data.count : 0;
    if (count > 0) {
        badgeWrap.innerHTML = '<span class="sched-live-badge" id="sched-badge">'+count+'</span>';
        if (!btn.querySelector('.sched-live-dot')) {
            var dot = document.createElement('span');
            dot.className = 'sched-live-dot';
            btn.insertBefore(dot, btn.firstChild);
        }
    } else {
        badgeWrap.innerHTML = '';
        var existingDot = btn.querySelector('.sched-live-dot');
        if (existingDot) existingDot.remove();
    }
    if (countLabel) countLabel.textContent = count + ' pending';
    if (!data.preview || data.preview.length === 0) {
        list.innerHTML = '<div class="sched-no-pending">🎉 No pending reservations</div>';
        return;
    }
    list.innerHTML = data.preview.map(function(p) {
        return '<a href="'+APP_URL+'/admin/schedule.php?date='+p.slot_date+'" class="sched-preview-item">'
            + '<div class="sched-preview-dot"></div>'
            + '<div class="sched-preview-text">'
            + '<div class="sched-preview-name">'
            + escHtml(p.full_name)+' <span class="sched-preview-uname">@'+escHtml(p.username)+'</span></div>'
            + '<div class="sched-preview-meta">'+escHtml(p.court_name)+' · '+escHtml(p.slot_date_fmt)+' · '+escHtml(p.slot_time_fmt)+' · '+p.party_size+' player'+(p.party_size > 1 ? 's' : '')+'</div>'
            + '</div>'
            + '<span class="sched-pending-pill">⏳</span>'
            + '</a>';
    }).join('') + (data.count > 5 ? '<div class="sched-more-label">+' + (data.count - 5) + ' more…</div>' : '');
}
function pollReservations() {
    fetch(APP_URL + '/admin/api/pending_reservations.php', { credentials: 'same-origin' })
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(data){ if (data && data.count !== undefined) renderSchedPreview(data); })
        .catch(function(){});
}
setInterval(pollReservations, 30000);
<?php else: ?>
if (typeof APP_URL === 'undefined') window.APP_URL = '<?= APP_URL ?>';
<?php endif; ?>

/* ── Session timeout ───────────────────────────────────────── */
<?php if (isLoggedIn()): ?>
(function() {
    'use strict';
    var INITIAL_REMAINING_SECS = <?= (int)$_jsSecondsRemaining ?>;
    var IDLE_TIMEOUT_SECS      = <?= SESSION_IDLE_TIMEOUT ?>;
    var WARN_BEFORE_SECS       = <?= SESSION_WARN_BEFORE ?>;
    var HEARTBEAT_INTERVAL_MS  = 4 * 60 * 1000;
    var PING_URL               = window.location.pathname + '?_session_ping=1';
    var LOGOUT_URL             = '<?= APP_URL ?>/auth/logout.php?force=1';

    var modal       = document.getElementById('session-timeout-modal');
    var countdownEl = document.getElementById('session-countdown-display');
    if (!modal || !countdownEl) return;

    var idleTimer = null, countdownTimer = null, heartbeatTimer = null;
    var secsRemaining = 0, modalShowing = false, lastActivity = Date.now();

    function fmt(s) { var m = Math.floor(s/60); return m+':'+String(s%60).padStart(2,'0'); }

    function showModal() {
        if (modalShowing) return;
        modalShowing = true;
        secsRemaining = WARN_BEFORE_SECS;
        countdownEl.textContent = fmt(secsRemaining);
        countdownEl.classList.remove('urgent');
        modal.classList.add('visible');
        countdownTimer = setInterval(function() {
            secsRemaining--;
            countdownEl.textContent = fmt(Math.max(0, secsRemaining));
            if (secsRemaining <= 30) countdownEl.classList.add('urgent');
            if (secsRemaining <= 0)  { clearInterval(countdownTimer); window.location.href = LOGOUT_URL; }
        }, 1000);
    }
    function hideModal() {
        modal.classList.remove('visible');
        modalShowing = false;
        clearInterval(countdownTimer);
        countdownEl.classList.remove('urgent');
    }
    function sendHeartbeat() {
        fetch(PING_URL, { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(data){ if (!data.ok) window.location.href = LOGOUT_URL; })
            .catch(function(){});
    }
    function startHeartbeat() { clearInterval(heartbeatTimer); heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS); }
    function getWarnDelaySecs() {
        var elapsed = (Date.now() - lastActivity) / 1000;
        return Math.max(0, Math.max(0, INITIAL_REMAINING_SECS - elapsed) - WARN_BEFORE_SECS);
    }
    function resetIdleTimer() {
        clearTimeout(idleTimer);
        if (modalShowing) { stayLoggedIn(); return; }
        lastActivity = Date.now();
        idleTimer = setTimeout(showModal, getWarnDelaySecs() * 1000);
    }
    ['mousemove','keydown','mousedown','touchstart','scroll','click'].forEach(function(evt) {
        document.addEventListener(evt, resetIdleTimer, { passive: true });
    });
    window.stayLoggedIn = function() {
        hideModal();
        fetch(PING_URL, { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (!data.ok) { window.location.href = LOGOUT_URL; }
                else { lastActivity = Date.now(); clearTimeout(idleTimer); idleTimer = setTimeout(showModal, (IDLE_TIMEOUT_SECS - WARN_BEFORE_SECS) * 1000); }
            })
            .catch(function() { lastActivity = Date.now(); clearTimeout(idleTimer); idleTimer = setTimeout(showModal, (IDLE_TIMEOUT_SECS - WARN_BEFORE_SECS) * 1000); });
    };
    window.logoutNow = function() { window.location.href = LOGOUT_URL; };
    resetIdleTimer();
    startHeartbeat();
})();
<?php endif; ?>
</script>

<!-- ============================================================
     ✦ FORM VALIDATION — Real-time field feedback
     ============================================================ -->
<script src="<?= APP_URL ?>/assets/js/form-validation.js"></script>

<!-- ============================================================
     ✦ PADOL CHAT WIDGET — loaded on every page
     ============================================================ -->
<script nonce="<?= csrfNonce() ?>">
if (typeof APP_URL === 'undefined') window.APP_URL = '<?= APP_URL ?>';
</script>
<?php include __DIR__ . '/chat_widget.php'; ?>