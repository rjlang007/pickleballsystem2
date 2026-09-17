<?php
// ============================================================
//  FILE: admin/kiosk.php  — FIXED v2
//  Standalone fullscreen court display — opens in new tab
//  Auto-polls live data every 10s via kiosk_data.php
//  + Pause / Resume / Reset game timer
//  + Responsive: Desktop fullscreen + Mobile portrait/landscape
//
//  FIXES:
//   1. state object fully initialized before any render call —
//      prevents "Cannot read property of undefined" crash on load
//   2. applyData() no longer calls renderAll() before state is
//      ready; guards added throughout
//   3. launchGame() fixed: d.duration_mins (not d.duration),
//      end_ts computed correctly from server response
//   4. state.totalSecs set on every game mode entry so calcPct()
//      never divides by zero
//   5. timerColor() guard: never fires danger on 0 remaining
//      when game hasn't started yet
//   6. warmup mode: warmupTotal seeded from server on first apply
//   7. renderAll() is safe to call at any time (all modes guarded)
//   8. Removed redundant safeReload on pause-state change from
//      active_game poll — that lives in active_game.php, not here
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireStaff();

$courtId = (int)($_GET['court_id'] ?? 0);
$court = $courtId
    ? getDB()->prepare("SELECT id, name, game_duration, warmup_mins, is_active FROM falcon.courts WHERE id = ?")
    : null;
if ($court) { $court->execute([$courtId]); $court = $court->fetch(); }
if (!$court) {
    $court = getDB()->query("
        SELECT id, name, game_duration, warmup_mins, is_active
        FROM falcon.courts ORDER BY id LIMIT 1
    ")->fetch();
}

$pageTitle = 'Court Kiosk';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>🏓 <?= htmlspecialchars($court['name'] ?? 'Padol Pickleball') ?> — Kiosk</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;700;800&display=swap" rel="stylesheet"/>
<style nonce="<?= getCspNonce() ?>">
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg:      #050d12; --surface: #0a1520; --border:  #1a2a35;
    --text:    #f0fdf4; --muted:   #64748b; --accent:  #00e5a0;
    --warn:    #f59e0b; --danger:  #ef4444; --success: #10b981;

    --page-px:    48px;
    --topbar-h:   72px;
    --fs-title:   clamp(48px, 8vw, 90px);
    --fs-bignum:  clamp(90px, 15vw, 170px);
    --fs-medium:  clamp(40px, 7vw, 80px);
}

@media (max-width: 767px) {
    :root {
        --page-px:   16px;
        --topbar-h:  56px;
        --fs-bignum: clamp(64px, 18vw, 110px);
        --fs-medium: clamp(32px, 10vw, 60px);
    }
}

html, body {
    width: 100%; height: 100%;
    background: var(--bg); color: var(--text);
    font-family: 'DM Sans', sans-serif;
    overflow: hidden;
}

@media (min-width: 768px) {
    body { cursor: none; }
}

/* ── FULLSCREEN PROMPT ── */
#fs-prompt {
    position: fixed; inset: 0; z-index: 9999;
    background: var(--bg);
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 24px; text-align: center;
    padding: 40px 24px;
}
#fs-prompt h1 {
    font-family: 'Bebas Neue', sans-serif;
    font-size: var(--fs-title);
    color: var(--accent); letter-spacing: 4px;
}
#fs-prompt p { font-size: 18px; color: var(--muted); max-width: 500px; }
#fs-btn {
    background: var(--accent); color: #022c22;
    border: none; border-radius: 16px;
    padding: 18px 48px;
    font-size: clamp(18px, 4vw, 24px); font-weight: 800;
    font-family: 'DM Sans', sans-serif;
    cursor: pointer; letter-spacing: 1px;
    transition: transform .15s, box-shadow .15s;
    box-shadow: 0 0 40px rgba(0,229,160,0.3);
}
#fs-btn:hover  { transform: scale(1.04); box-shadow: 0 0 60px rgba(0,229,160,0.5); }
#fs-btn:active { transform: scale(0.97); }

/* ── MAIN DISPLAY ── */
#display { display: none; width: 100vw; height: 100vh; flex-direction: column; }
#display.show { display: flex; }

/* ── TOP BAR ── */
#topbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 var(--page-px);
    height: var(--topbar-h);
    background: rgba(5,13,18,0.95);
    border-bottom: 1px solid rgba(0,229,160,0.1);
    flex-shrink: 0;
    gap: 8px;
}
#court-name {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(18px, 3.5vw, 30px);
    letter-spacing: 2px; color: var(--accent);
    display: flex; align-items: center; gap: 10px;
    flex-shrink: 0;
    overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
    max-width: 50%;
}
.live-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--success); box-shadow: 0 0 10px var(--success);
    animation: livepulse 1.5s infinite; flex-shrink: 0;
}
@keyframes livepulse { 0%,100%{opacity:1} 50%{opacity:.3} }

#topbar-right {
    display: flex; align-items: center;
    gap: clamp(8px, 1.5vw, 20px);
    flex-shrink: 0;
}
#wall-clock {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(18px, 3vw, 28px);
    letter-spacing: 3px; color: var(--muted);
}
@media (max-width: 400px) { #wall-clock { display: none; } }

.slide-indicators { display: flex; gap: 6px; }
.slide-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: rgba(255,255,255,0.15);
    transition: all .3s; cursor: pointer;
}
.slide-dot.on { background: var(--accent); width: 20px; border-radius: 4px; box-shadow: 0 0 6px var(--accent); }
@media (max-width: 360px) { .slide-indicators { display: none; } }

#paused-badge {
    background: rgba(245,158,11,0.15);
    border: 1px solid var(--warn); color: var(--warn);
    border-radius: 20px; padding: 3px 10px;
    font-size: clamp(10px, 2vw, 12px);
    font-weight: 700; letter-spacing: 1px;
    animation: pausePulse 1.5s infinite; white-space: nowrap;
}
@keyframes pausePulse { 0%,100%{opacity:1} 50%{opacity:.35} }

#exit-fs {
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.1);
    color: rgba(255,255,255,0.4);
    border-radius: 8px; padding: 5px 14px;
    font-size: clamp(11px, 2vw, 13px);
    font-weight: 700; font-family: 'DM Sans', sans-serif; cursor: pointer;
    white-space: nowrap;
}
#exit-fs:hover { color: #fff; background: rgba(255,255,255,0.1); }

/* ── ADMIN CONTROLS BAR ── */
#admin-controls {
    display: none;
    align-items: center; flex-wrap: wrap;
    gap: 8px;
    background: rgba(5,13,18,0.92);
    border-top: 1px solid rgba(255,255,255,0.06);
    padding: 8px var(--page-px);
    flex-shrink: 0;
}
#admin-controls.show { display: flex; }
.ac-label {
    font-size: clamp(9px, 1.8vw, 11px);
    font-weight: 800; text-transform: uppercase;
    letter-spacing: 2px; color: var(--muted);
    margin-right: 2px; white-space: nowrap;
}
.ac-btn {
    background: transparent; border: 1px solid;
    border-radius: 8px; padding: 5px 14px;
    font-size: clamp(11px, 2vw, 13px);
    font-weight: 700; font-family: 'DM Sans', sans-serif;
    cursor: pointer; transition: background .15s;
    white-space: nowrap;
}
.ac-btn.pause  { border-color: var(--warn);            color: var(--warn); }
.ac-btn.resume { border-color: var(--success);         color: var(--success); }
.ac-btn.reset  { border-color: var(--accent2,#00c4ff); color: var(--accent2,#00c4ff); }
.ac-btn:hover  { background: rgba(255,255,255,0.06); }

/* ── SLIDE CONTAINER ── */
#slides { flex: 1; position: relative; overflow: hidden; }
.slide { position: absolute; inset: 0; opacity: 0; transition: opacity .7s ease; pointer-events: none; }
.slide.on { opacity: 1; pointer-events: all; }

/* ══ SLIDE 1 ══ */
#s1 { display: grid; grid-template-columns: 1fr 1.15fr; }
@media (max-width: 767px) {
    #s1 { grid-template-columns: 1fr; grid-template-rows: auto 1fr; overflow-y: auto; }
}
.s1-left {
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    padding: clamp(16px, 3vw, 32px) clamp(12px, 2.5vw, 28px);
    border-right: 1px solid rgba(255,255,255,0.05);
    gap: 8px;
}
@media (max-width: 767px) {
    .s1-left { border-right: none; border-bottom: 1px solid rgba(255,255,255,0.05); padding: 20px 16px; justify-content: flex-start; }
}
.s1-state-label {
    font-size: clamp(10px, 1.8vw, 12px);
    font-weight: 800; text-transform: uppercase;
    letter-spacing: 4px; color: var(--muted);
}
.s1-big-timer {
    font-family: 'Bebas Neue', sans-serif;
    font-size: var(--fs-bignum);
    line-height: 1; letter-spacing: 4px;
    color: var(--accent); transition: color .5s;
}
.s1-progress {
    width: min(320px, 80%); height: 8px;
    background: rgba(255,255,255,0.07);
    border-radius: 8px; overflow: hidden;
    margin: 4px 0 16px;
}
.s1-progress-fill { height: 100%; background: linear-gradient(90deg,var(--accent),#00c4ff); border-radius: 8px; transition: width 1s linear; }
.s1-players { display: flex; flex-direction: column; gap: 8px; width: 100%; max-width: 320px; }
.s1-player {
    display: flex; align-items: center; gap: 12px;
    background: rgba(0,229,160,0.07);
    border: 1px solid rgba(0,229,160,0.2);
    border-radius: 12px; padding: 10px 16px;
}
.s1-player .pnum { font-family: 'Bebas Neue', sans-serif; font-size: 22px; color: var(--accent); min-width: 24px; }
.s1-player .pname { font-size: 14px; font-weight: 700; }
.s1-right {
    display: flex; flex-direction: column;
    padding: clamp(14px, 2vw, 20px) clamp(12px, 2vw, 24px) clamp(14px, 2vw, 20px) clamp(10px, 1.5vw, 20px);
    overflow: hidden;
}
@media (max-width: 767px) { .s1-right { padding: 16px; overflow-y: auto; max-height: 40vh; } }
.s1-queue-label {
    font-size: clamp(10px, 1.8vw, 11px);
    font-weight: 800; text-transform: uppercase;
    letter-spacing: 3px; color: var(--muted); margin-bottom: 14px; flex-shrink: 0;
}
.s1-queue-scroll { flex: 1; overflow: hidden; }
@media (max-width: 767px) { .s1-queue-scroll { overflow: visible; } }

/* ══ SLIDE 2 ══ */
#s2 { display: flex; flex-direction: column; padding: clamp(12px, 2vw, 16px) clamp(16px, 3vw, 40px) clamp(14px, 2vw, 20px); overflow-y: auto; }
.s2-strip {
    display: flex; align-items: center; gap: 16px;
    padding: 10px 16px; border-radius: 12px; margin-bottom: 16px;
    background: rgba(0,229,160,0.06); border: 1px solid rgba(0,229,160,0.15);
    flex-shrink: 0; flex-wrap: wrap;
}
.s2-strip-timer { font-family: 'Bebas Neue', sans-serif; font-size: clamp(32px, 6vw, 44px); letter-spacing: 3px; color: var(--accent); line-height: 1; }
.s2-strip-meta { font-size: clamp(11px, 2vw, 13px); color: var(--muted); line-height: 1.6; }
.s2-strip-chips { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }
.s2-chip { background: rgba(0,229,160,0.1); border: 1px solid rgba(0,229,160,0.3); border-radius: 8px; padding: 4px 10px; font-size: clamp(11px, 2vw, 13px); font-weight: 700; color: var(--accent); }
@media (max-width: 600px) { .s2-strip { flex-direction: column; align-items: flex-start; gap: 8px; } .s2-strip-chips { margin-left: 0; } }
.s2-queue-grid { flex: 1; overflow: hidden; display: grid; gap: 0; align-content: start; }

/* ══ SLIDE 3 ══ */
#s3 { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: clamp(16px, 3vw, 20px) clamp(16px, 5vw, 60px); gap: clamp(10px, 2vw, 16px); overflow-y: auto; }
.s3-label { font-size: clamp(10px, 2vw, 13px); font-weight: 800; text-transform: uppercase; letter-spacing: 5px; color: var(--warn); }
.s3-title { font-family: 'Bebas Neue', sans-serif; font-size: clamp(32px, 6vw, 72px); letter-spacing: 3px; color: var(--accent); }
.s3-timer-badge { background: rgba(0,229,160,0.1); border: 1px solid rgba(0,229,160,0.25); border-radius: 12px; padding: 6px 24px; font-family: 'Bebas Neue', sans-serif; font-size: clamp(24px, 4vw, 36px); color: var(--accent); letter-spacing: 3px; }
.s3-cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: clamp(10px, 2vw, 16px); width: 100%; max-width: 860px; }
@media (max-width: 500px) { .s3-cards { grid-template-columns: 1fr; } }
.s3-card { background: rgba(0,229,160,0.07); border: 2px solid rgba(0,229,160,0.3); border-radius: 20px; padding: clamp(14px, 2.5vw, 24px) clamp(14px, 2.5vw, 28px); display: flex; align-items: center; gap: 14px; animation: cardin .5s ease both; }
.s3-card.playing { background: rgba(16,185,129,0.08); border-color: rgba(16,185,129,0.4); }
.s3-card.empty   { background: rgba(255,255,255,0.02); border-color: rgba(255,255,255,0.07); opacity: .4; }
@keyframes cardin { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }
.s3-card .cnum { font-family: 'Bebas Neue', sans-serif; font-size: clamp(36px, 7vw, 56px); color: rgba(0,229,160,0.25); line-height: 1; min-width: 40px; }
.s3-card .cname { font-size: clamp(16px, 2.6vw, 28px); font-weight: 800; line-height: 1.1; }
.s3-waiting { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; max-width: 860px; }
.s3-wait-chip { background: rgba(245,158,11,0.08); border: 1px solid rgba(245,158,11,0.2); border-radius: 20px; padding: 6px 14px; font-size: clamp(11px, 2vw, 13px); color: var(--warn); font-weight: 700; }

/* ══ SLIDE 4 ══ */
#s4 { display: flex; flex-direction: column; align-items: center; padding: clamp(12px, 2vw, 18px) clamp(16px, 4vw, 48px) clamp(12px, 2vw, 16px); overflow-y: auto; }
.s4-timer-block { text-align: center; flex-shrink: 0; }
.s4-label { font-size: clamp(9px, 1.8vw, 11px); font-weight: 800; text-transform: uppercase; letter-spacing: 4px; color: var(--muted); margin-bottom: 4px; }
.s4-timer { font-family: 'Bebas Neue', sans-serif; font-size: clamp(60px, 12vw, 130px); line-height: 1; letter-spacing: 5px; color: var(--accent); }
.s4-bar { width: min(480px, 65vw); height: 6px; background: rgba(255,255,255,0.07); border-radius: 6px; overflow: hidden; margin: 10px auto 0; }
.s4-bar-fill { height: 100%; background: linear-gradient(90deg,var(--accent),#00c4ff); border-radius: 6px; transition: width 1s linear; }
.s4-divider  { width: 100%; height: 1px; background: rgba(255,255,255,0.06); margin: clamp(8px, 2vw, 14px) 0; flex-shrink: 0; }
.s4-queue-area { flex: 1; overflow: hidden; width: 100%; display: flex; flex-direction: column; }
.s4-queue-label { font-size: clamp(9px, 1.8vw, 11px); font-weight: 800; text-transform: uppercase; letter-spacing: 3px; color: var(--muted); text-align: center; margin-bottom: 10px; }
.s4-queue-rows { flex: 1; overflow: hidden; }
@media (max-width: 767px) { .s4-queue-rows { overflow-y: auto; } }

/* ══ QUEUE ROWS ══ */
.qg-header { display: flex; align-items: center; justify-content: space-between; padding: clamp(5px,1.2vw,7px) clamp(10px,2vw,14px); border-radius: 10px; margin-bottom: 6px; margin-top: 10px; font-family: 'Bebas Neue', sans-serif; font-size: clamp(13px, 2.2vw, 17px); letter-spacing: 1px; }
.qg-header:first-child { margin-top: 0; }
.qg-header.gnext { background: rgba(0,229,160,0.1);   border: 1px solid rgba(0,229,160,0.3); color: var(--accent); }
.qg-header.gwait { background: rgba(245,158,11,0.06); border: 1px solid rgba(245,158,11,0.2); color: var(--warn); }
.qg-row { display: flex; align-items: center; gap: 10px; padding: clamp(6px,1.2vw,9px) clamp(10px,2vw,14px); border-radius: 10px; margin-bottom: 5px; }
.qg-row.rnext  { background: rgba(0,229,160,0.06);   border: 1px solid rgba(0,229,160,0.2); }
.qg-row.rwait  { background: rgba(245,158,11,0.04);  border: 1px solid rgba(245,158,11,0.12); opacity: .8; }
.qg-row.rempty { background: rgba(255,255,255,0.02); border: 1px dashed rgba(255,255,255,0.07); opacity: .35; }
.qg-pos  { font-family: 'Bebas Neue', sans-serif; font-size: clamp(16px, 2.5vw, 22px); min-width: 24px; text-align: center; line-height: 1; }
.qg-name { font-size: clamp(12px, 2vw, 15px); font-weight: 700; flex: 1; }
.qg-tick { font-size: clamp(13px, 2vw, 16px); }

/* ══ ALERTS ══ */
.k-alert { display: none; position: fixed; inset: 0; z-index: 8000; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: clamp(20px, 5vw, 40px); animation: afadein .4s ease; }
.k-alert.show { display: flex; }
#alert-onemin   { background: rgba(245,158,11,0.97); }
#alert-onemin   .alert-title { color: #1a0e00; }
#alert-onemin   .alert-sub   { color: #1a0e00; opacity: .85; }
#alert-gameover { background: rgba(8,0,0,0.97); border: 6px solid var(--danger); }
#alert-gameover .alert-title { color: var(--danger); }
#alert-gameover .alert-sub   { color: rgba(255,255,255,0.85); }
#alert-warmup   { background: rgba(5,13,18,0.96); border: 4px solid var(--warn); }
#alert-warmup   .alert-title { color: var(--warn); }
#alert-warmup   .alert-sub   { color: rgba(255,255,255,0.75); }
.alert-icon  { font-size: clamp(48px, 10vw, 130px); margin-bottom: 10px; }
.alert-title { font-family: 'Bebas Neue', sans-serif; font-size: clamp(44px, 10vw, 120px); line-height: 1; letter-spacing: 3px; }
.alert-sub   { font-size: clamp(14px, 2.5vw, 28px); margin-top: 14px; font-weight: 700; }
@keyframes afadein { from{opacity:0;transform:scale(1.05)} to{opacity:1;transform:scale(1)} }
#warmup-banner-timer { font-family: 'Bebas Neue', sans-serif; font-size: clamp(64px, 14vw, 160px) !important; color: var(--warn); letter-spacing: 6px; line-height: 1; margin: 12px 0; }

/* ── IDLE ── */
.idle-center { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; text-align: center; gap: 16px; padding: 20px; }
.idle-icon  { font-size: clamp(48px, 10vw, 110px); }
.idle-title { font-family: 'Bebas Neue', sans-serif; font-size: clamp(32px, 7vw, 80px); color: var(--muted); letter-spacing: 3px; }
.idle-sub   { font-size: clamp(14px, 2.5vw, 18px); color: rgba(255,255,255,0.2); }

/* ── SWIPE HINT ── */
#swipe-hint { display: none; position: fixed; bottom: 18px; left: 50%; transform: translateX(-50%); font-size: 11px; color: rgba(255,255,255,0.2); letter-spacing: 2px; font-weight: 700; text-transform: uppercase; pointer-events: none; z-index: 100; }
@media (max-width: 767px) { #swipe-hint { display: block; } }/* ── TOURNAMENT MODE TAB ── */
.tourney-tab-btn {
    position: relative;
    background: rgba(0,229,160,0.08); color: var(--accent);
    border: 1px solid rgba(0,229,160,0.3); border-radius: 10px;
    padding: 8px 14px; font-family: 'DM Sans', sans-serif;
    font-size: clamp(12px, 1.8vw, 15px); font-weight: 700;
    cursor: pointer; display: flex; align-items: center; gap: 6px;
}
.tourney-tab-btn .tt-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--danger); box-shadow: 0 0 8px var(--danger);
    animation: livepulse 1.5s infinite; display: none;
}
.tourney-tab-btn.has-live .tt-dot { display: inline-block; }

/* ── TOURNAMENT VIEW (overlay) ── */
#tournament-view {
    display: none; position: fixed; inset: 0; z-index: 600;
    width: 100vw; height: 100vh; flex-direction: column;
    background: var(--bg);
}
#tournament-view.show { display: flex; }
#tv-topbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 var(--page-px); height: var(--topbar-h);
    background: rgba(5,13,18,0.95);
    border-bottom: 1px solid rgba(0,229,160,0.1);
    flex-shrink: 0;
}
#tv-back {
    background: transparent; border: 1px solid var(--border); color: var(--text);
    border-radius: 10px; padding: 8px 16px; cursor: pointer; font-size: 14px;
}
#tv-title {
    font-family: 'Bebas Neue', sans-serif; font-size: clamp(16px,3vw,26px);
    color: var(--accent); letter-spacing: 2px;
}
#tv-body {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: clamp(16px, 3vh, 40px); padding: 24px;
    text-align: center;
}
#tv-empty { color: var(--muted); font-size: clamp(18px, 3vw, 28px); }
.tv-round-badge {
    font-family: 'Bebas Neue', sans-serif; letter-spacing: 3px;
    color: var(--warn); font-size: clamp(16px, 2.5vw, 24px);
    padding: 6px 20px; border: 1px solid var(--warn); border-radius: 999px;
}
.tv-matchup {
    display: flex; align-items: center; justify-content: center;
    gap: clamp(20px, 5vw, 80px); width: 100%;
}
.tv-side { flex: 1; max-width: 420px; }
.tv-name {
    font-family: 'Bebas Neue', sans-serif; letter-spacing: 1px;
    font-size: clamp(24px, 4vw, 44px); margin-bottom: 12px;
    display: flex; align-items: center; justify-content: center; gap: 10px;
}
.tv-serve-dot {
    width: 12px; height: 12px; border-radius: 50%;
    background: var(--accent); box-shadow: 0 0 12px var(--accent);
    animation: livepulse 1.2s infinite; visibility: hidden;
}
.tv-serve-dot.on { visibility: visible; }
.tv-score {
    font-family: 'Bebas Neue', sans-serif; font-weight: 700;
    font-size: var(--fs-bignum); line-height: 1; color: var(--text);
}
.tv-vs {
    font-family: 'Bebas Neue', sans-serif; color: var(--muted);
    font-size: clamp(20px, 3vw, 32px);
}
#tv-tourney-name { color: var(--muted); font-size: clamp(13px, 2vw, 16px); }

/* ── BIG MOMENT: semifinal / championship ── */
#tournament-view.big-moment {
    background: radial-gradient(ellipse at center, #0c2a20 0%, var(--bg) 70%);
}
#tournament-view.big-moment .tv-round-badge {
    color: var(--bg); background: var(--warn); border-color: var(--warn);
    font-size: clamp(20px, 3.5vw, 34px); padding: 10px 32px;
    box-shadow: 0 0 40px rgba(245,158,11,0.5);
    animation: bigmoment-glow 2s ease-in-out infinite;
}
@keyframes bigmoment-glow {
    0%,100% { box-shadow: 0 0 30px rgba(245,158,11,0.4); }
    50%     { box-shadow: 0 0 60px rgba(245,158,11,0.8); }
}
#tournament-view.big-moment .tv-score { font-size: clamp(120px, 20vw, 220px); color: var(--accent); }
#tournament-view.big-moment .tv-name  { font-size: clamp(30px, 5.5vw, 60px); }

</style>
</head>
<body>

<!-- FULLSCREEN PROMPT -->
<div id="fs-prompt">
    <div style="font-size:clamp(48px,10vw,64px);">📺</div>
    <h1>PADOL PICKLEBALL</h1>
    <p>Tap the button below to start the court display.</p>
    <button id="fs-btn" onclick="startKiosk()">▶ START KIOSK DISPLAY</button>
    <div style="font-size:13px;color:var(--muted);">
        Press <kbd style="background:rgba(255,255,255,0.1);padding:2px 8px;border-radius:4px;">Esc</kbd> or tap ✕ to exit
    </div>
</div>

<!-- MAIN DISPLAY -->
<div id="display">

    <!-- ALERT OVERLAYS -->
    <div id="alert-onemin" class="k-alert">
        <div class="alert-icon">🏓</div>
        <div class="alert-title">1 MINUTE LEFT</div>
        <div class="alert-sub">Last rally — wrap up and exit the court</div>
    </div>

    <div id="alert-gameover" class="k-alert">
        <div class="alert-icon">🏆</div>
        <div class="alert-title">GAME OVER</div>
        <div class="alert-sub">
            Great game! Please exit the court 🏓<br>
            <small style="opacity:.6;font-size:.6em;">Scan your QR code to re-join the queue</small>
        </div>
    </div>

    <div id="alert-warmup" class="k-alert">
        <div class="alert-icon">🏓</div>
        <div class="alert-title">DINK TIME!</div>
        <div id="warmup-banner-timer">00:00</div>
        <div class="alert-sub">🎾 Warm up your dinks — game starts when timer ends</div>
    </div>

    <!-- Top bar -->
    <div id="topbar">
        <div id="court-name">
            <div class="live-dot" id="live-dot" style="display:none;"></div>
            🏓 <?= htmlspecialchars($court['name'] ?? 'Padol Pickleball') ?>
        </div>
        <div id="topbar-right">
            <span id="paused-badge" style="display:none;">⏸ PAUSED</span>
            <button class="tourney-tab-btn" id="tourney-tab-btn" onclick="showTournamentView()">
                <span class="tt-dot"></span>🏆 Tournament
            </button>
            <div class="slide-indicators" id="slide-dots">
                <div class="slide-dot on" onclick="jumpSlide(0)"></div>
                <div class="slide-dot"    onclick="jumpSlide(1)"></div>
                <div class="slide-dot"    onclick="jumpSlide(2)"></div>
                <div class="slide-dot"    onclick="jumpSlide(3)"></div>
            </div>
            <div id="wall-clock">--:--:--</div>
            <button id="exit-fs" onclick="exitKiosk()">✕ Exit</button>
        </div>
    </div>

    <!-- Admin Controls Bar -->
    <div id="admin-controls">
        <span class="ac-label">⚡ Controls:</span>
        <button id="ac-pause"  class="ac-btn pause"  onclick="kioskControl('pause')">⏸ Pause Timer</button>
        <button id="ac-resume" class="ac-btn resume" onclick="kioskControl('resume')" style="display:none;">▶ Resume Timer</button>
        <button id="ac-reset"  class="ac-btn reset"  onclick="kioskControl('reset')">🔄 Reset Timer</button>
        <span id="ac-status" style="font-size:12px;color:var(--muted);margin-left:8px;"></span>
    </div>

    <!-- Slides -->
    <div id="slides">
        <div id="s1" class="slide on">
            <div class="s1-left" id="s1-left"></div>
            <div class="s1-right">
                <div class="s1-queue-label" id="s1-qlabel">Queue</div>
                <div class="s1-queue-scroll" id="s1-queue"></div>
            </div>
        </div>
        <div id="s2" class="slide">
            <div class="s2-strip" id="s2-strip"></div>
            <div class="s2-queue-grid" id="s2-queue"></div>
        </div>
        <div id="s3" class="slide" id="s3-inner"></div>
        <div id="s4" class="slide">
            <div class="s4-timer-block" id="s4-timer-block"></div>
            <div class="s4-divider"></div>
            <div class="s4-queue-area">
                <div class="s4-queue-label" id="s4-qlabel">Queue</div>
                <div class="s4-queue-rows" id="s4-queue"></div>
            </div>
        </div>
    </div>

    <div id="swipe-hint">← swipe to switch view →</div>
</div>

<!-- TOURNAMENT MODE OVERLAY (separate from the walk-in queue display above) -->
<div id="tournament-view">
    <div id="tv-topbar">
        <button id="tv-back" onclick="hideTournamentView()">← Walk-in Queue</button>
        <div id="tv-title">🏆 Tournament Mode</div>
        <div style="width:120px;"></div>
    </div>
    <div id="tv-body">
        <div id="tv-empty">No tournament match currently assigned to this court.</div>
        <div id="tv-match" style="display:none;width:100%;">
            <div id="tv-tourney-name"></div>
            <div class="tv-round-badge" id="tv-round" style="margin:14px auto 28px;width:fit-content;"></div>
            <div class="tv-matchup">
                <div class="tv-side">
                    <div class="tv-name"><span class="tv-serve-dot" id="tv-serve-1"></span><span id="tv-p1-name"></span></div>
                    <div class="tv-score" id="tv-p1-score">0</div>
                </div>
                <div class="tv-vs">vs</div>
                <div class="tv-side">
                    <div class="tv-name"><span id="tv-p2-name"></span><span class="tv-serve-dot" id="tv-serve-2"></span></div>
                    <div class="tv-score" id="tv-p2-score">0</div>
                </div>
            </div>
        </div>
    </div>
</div>


<script src="<?= APP_URL ?>/includes/game_alarm.js"></script>
<script nonce="<?= getCspNonce() ?>">
// ═══════════════════════════════════════════════════════════════
//  CONSTANTS & STATE
//  FIX: state is fully initialized with safe defaults before any
//       render call, preventing "cannot read property of undefined"
// ═══════════════════════════════════════════════════════════════
const APP_URL       = '<?= APP_URL ?>';
const POLL_INTERVAL = 10000;
const SLIDE_SECS    = 12;
const PPG           = <?= PLAYERS_PER_GAME ?>;
const COURT_ID      = <?= (int)$court['id'] ?>;

let tourneyPollTick   = null;
let tourneyAutoShown  = false;

const state = {
    mode:        'idle',   // 'idle' | 'warmup' | 'game'
    sessionId:   null,
    remSecs:     0,
    totalSecs:   0,        // FIX: must be set on game start for calcPct()
    pct:         0,
    endTime:     '',
    endTs:       0,
    players:     [],
    queue:       [],
    warmupRem:   0,
    warmupTotal: 0,
    isPaused:    false,
    pausedRem:   0,
};

let timerTick    = null;
let warmupTick   = null;
let slideTick    = null;
let pollTick     = null;
let currentSlide = 0;
const SLIDE_COUNT = 4;

const alertFired = { five_min:false, two_min:false, one_min:false, game_over:false };
let gameEndLocked    = false;
let gameLaunchLocked = false;

// ═══════════════════════════════════════════════════════════════
//  FULLSCREEN
// ═══════════════════════════════════════════════════════════════
function startKiosk() {
    const root = document.documentElement;
    const req  = root.requestFullscreen || root.webkitRequestFullscreen || root.mozRequestFullScreen || root.msRequestFullscreen;
    const go = () => {
        document.getElementById('fs-prompt').style.display = 'none';
        document.getElementById('display').classList.add('show');
        try { GameAlarm.unlock(); } catch(e) {}
        fetchLiveData();
        pollTick  = setInterval(fetchLiveData, POLL_INTERVAL);
        slideTick = setInterval(() => rotateSlide(1), SLIDE_SECS * 1000);
        clockTick();
        setInterval(clockTick, 1000);
        initSwipe();
        renderAll();   // initial render with idle defaults

        // Tournament mode polls independently of the walk-in queue
        // above — separate endpoint, same cadence, so nothing about
        // the working queue polling is touched.
        fetchTourneyData();
        tourneyPollTick = setInterval(fetchTourneyData, POLL_INTERVAL);
    };
    if (req) req.call(root).then(go).catch(go);
    else go();
}

function exitKiosk() {
    const ex = document.exitFullscreen || document.webkitExitFullscreen || document.mozCancelFullScreen || document.msExitFullscreen;
    if (ex && (document.fullscreenElement || document.webkitFullscreenElement)) ex.call(document).catch(() => {});
    window.close();
}

['fullscreenchange','webkitfullscreenchange'].forEach(ev =>
    document.addEventListener(ev, () => {
        if (!document.fullscreenElement && !document.webkitFullscreenElement)
            document.getElementById('exit-fs').style.color = '#fff';
    })
);

// ═══════════════════════════════════════════════════════════════
//  SWIPE (mobile)
// ═══════════════════════════════════════════════════════════════
function initSwipe() {
    let xStart = null, yStart = null;
    const slides = document.getElementById('slides');
    slides.addEventListener('touchstart', e => {
        xStart = e.touches[0].clientX;
        yStart = e.touches[0].clientY;
    }, { passive: true });
    slides.addEventListener('touchend', e => {
        if (xStart === null) return;
        const dx = e.changedTouches[0].clientX - xStart;
        const dy = e.changedTouches[0].clientY - yStart;
        if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) rotateSlide(dx < 0 ? 1 : -1);
        xStart = null; yStart = null;
    }, { passive: true });
}

// ═══════════════════════════════════════════════════════════════
//  FETCH
// ═══════════════════════════════════════════════════════════════
function fetchLiveData() {
    fetch(APP_URL + '/admin/kiosk_data.php', { credentials: 'same-origin' })
        .then(r => r.ok ? r.json() : null)
        .then(d => { if (d) applyData(d); })
        .catch(() => {});
}

// ═══════════════════════════════════════════════════════════════
//  APPLY SERVER DATA
//  FIX: every branch sets state fully before calling renderAll()
//       Guards prevent stale-mode artifacts
// ═══════════════════════════════════════════════════════════════
function applyData(d) {
    state.queue   = d.queue   || [];
    state.players = d.players || [];

    const newMode     = d.has_game ? 'game' : d.has_warmup ? 'warmup' : 'idle';
    const modeChanged = newMode !== state.mode;
    const sesChanged  = d.has_game && d.session_id !== state.sessionId;
    const srvPaused   = !!d.is_paused;

    document.getElementById('live-dot').style.display = d.has_game ? 'block' : 'none';

    if (newMode === 'game') {
        if (modeChanged || sesChanged) {
            // ── Brand new game ──
            state.mode      = 'game';
            state.sessionId = d.session_id;
            // FIX: totalSecs must be set so calcPct() works correctly
            state.totalSecs = (d.duration_mins || 0) * 60;
            state.endTime   = d.end_time_fmt || '';
            state.endTs     = d.end_ts || 0;
            state.isPaused  = srvPaused;
            state.pausedRem = d.paused_rem || 0;
            // FIX: compute rem from wall-clock offset; fall back to paused value
            state.remSecs   = srvPaused
                ? state.pausedRem
                : (d.end_ts ? Math.max(0, Math.round(d.end_ts - (Date.now() / 1000))) : d.rem_secs || 0);
            state.pct       = calcPct(state.remSecs, state.totalSecs);
            Object.keys(alertFired).forEach(k => alertFired[k] = false);
            gameEndLocked    = false;
            clearInterval(warmupTick); warmupTick = null;
            clearInterval(timerTick);
            if (!srvPaused && state.remSecs > 0) timerTick = setInterval(tickGame, 1000);
        } else {
            // ── Same game — sync pause state ──
            if (srvPaused !== state.isPaused) {
                state.isPaused  = srvPaused;
                state.pausedRem = d.paused_rem || state.remSecs;
                if (srvPaused) {
                    clearInterval(timerTick); timerTick = null;
                    state.remSecs = state.pausedRem;
                } else {
                    state.remSecs = d.end_ts
                        ? Math.max(0, Math.round(d.end_ts - (Date.now() / 1000)))
                        : d.rem_secs || state.remSecs;
                    clearInterval(timerTick);
                    if (state.remSecs > 0) timerTick = setInterval(tickGame, 1000);
                }
            } else if (!srvPaused && d.end_ts) {
                // Drift correction (>2s off)
                const srv = Math.max(0, Math.round(d.end_ts - (Date.now() / 1000)));
                if (Math.abs(srv - state.remSecs) > 2) state.remSecs = srv;
            }
        }
        updateAdminControls();

    } else if (newMode === 'warmup') {
        if (modeChanged) {
            state.mode        = 'warmup';
            state.sessionId   = null;
            state.isPaused    = false;
            // FIX: seed warmupTotal from server so fmtTime(warmupTotal) works
            state.warmupTotal = d.warmup_total || d.warmup_rem || 0;
            state.warmupRem   = d.warmup_rem   || 0;
            clearInterval(timerTick);  timerTick  = null;
            clearInterval(warmupTick);
            warmupTick = setInterval(tickWarmup, 1000);
            hideAllAlerts();
            showAlert('alert-warmup', 0);
            const bt = document.getElementById('warmup-banner-timer');
            if (bt) bt.textContent = fmtTime(state.warmupRem);
            try { GameAlarm.warmup(); } catch(e) {}
        } else {
            // Drift correction for warmup
            const drift = Math.abs((d.warmup_rem || 0) - state.warmupRem);
            if (drift > 3) state.warmupRem = d.warmup_rem || state.warmupRem;
        }
        updateAdminControls();

    } else {
        // ── idle ──
        if (modeChanged) {
            state.mode      = 'idle';
            state.sessionId = null;
            state.isPaused  = false;
            state.remSecs   = 0;
            state.totalSecs = 0;
            state.warmupRem = 0;
            clearInterval(timerTick);  timerTick  = null;
            clearInterval(warmupTick); warmupTick = null;
            gameEndLocked    = false;
            gameLaunchLocked = false;
            hideAllAlerts();
        }
        updateAdminControls();
    }

    renderAll();
}

// ═══════════════════════════════════════════════════════════════
//  ADMIN CONTROLS
// ═══════════════════════════════════════════════════════════════
function updateAdminControls() {
    const bar      = document.getElementById('admin-controls');
    const btnPause = document.getElementById('ac-pause');
    const btnResume= document.getElementById('ac-resume');
    const badge    = document.getElementById('paused-badge');
    if (state.mode === 'game') {
        bar.classList.add('show');
        btnPause.style.display  = state.isPaused ? 'none'        : '';
        btnResume.style.display = state.isPaused ? ''            : 'none';
        badge.style.display     = state.isPaused ? 'inline-flex' : 'none';
    } else {
        bar.classList.remove('show');
        badge.style.display = 'none';
    }
}

function kioskControl(action) {
    if (!state.sessionId) return;
    if (action === 'reset' && !confirm('Reset timer to full ' + Math.round(state.totalSecs/60) + ' minutes?')) return;
    const status = document.getElementById('ac-status');
    if (status) status.textContent = action === 'pause' ? 'Pausing…' : action === 'resume' ? 'Resuming…' : 'Resetting…';
    fetch(APP_URL + '/court/pause_game.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify({ action, session_id: state.sessionId, rem_secs: state.remSecs })
    })
    .then(r => r.json())
    .then(d => {
        if (status) status.textContent = '';
        if (d.status === 'paused') {
            state.isPaused = true; state.pausedRem = d.paused_rem; state.remSecs = d.paused_rem;
            clearInterval(timerTick); timerTick = null;
            showFlashMsg('⏸ ' + d.message, 'warn');
        } else if (d.status === 'resumed') {
            state.isPaused = false; state.remSecs = d.rem_secs;
            clearInterval(timerTick);
            if (state.remSecs > 0) timerTick = setInterval(tickGame, 1000);
            hideAllAlerts(); showFlashMsg('▶ ' + d.message, 'success');
        } else if (d.status === 'reset') {
            state.isPaused = false; state.remSecs = d.rem_secs;
            // FIX: also restore totalSecs on reset so progress bar recalculates correctly
            state.totalSecs = d.rem_secs;
            state.pct = 0;
            Object.keys(alertFired).forEach(k => alertFired[k] = false);
            clearInterval(timerTick);
            if (state.remSecs > 0) timerTick = setInterval(tickGame, 1000);
            hideAllAlerts(); showFlashMsg('🔄 ' + d.message, 'success');
        } else {
            showFlashMsg('⚠️ ' + (d.message || 'Error'), 'warn');
        }
        updateAdminControls(); renderAll();
    })
    .catch(() => { showFlashMsg('❌ Connection error.', 'warn'); if (status) status.textContent = ''; });
}

// ═══════════════════════════════════════════════════════════════
//  GAME TICK
// ═══════════════════════════════════════════════════════════════
function tickGame() {
    if (state.isPaused || state.mode !== 'game') return;

    if (state.remSecs <= 0) {
        state.remSecs = 0;
        clearInterval(timerTick); timerTick = null;
        if (!alertFired.game_over) {
            alertFired.game_over = true;
            try { GameAlarm.end(); } catch(e) {}
            showAlert('alert-gameover', 0);
            showFlashMsg('🏆 Game over — great match!', 'warn');
            setTimeout(() => triggerGameEnd(), 2500);
        }
        return;
    }

    state.remSecs--;
    state.pct = calcPct(state.remSecs, state.totalSecs);

    const s   = state.remSecs;
    const col = gameTimerColor(s);
    const fmt = fmtTime(s);
    const pct = state.pct.toFixed(1) + '%';

    document.querySelectorAll('.js-game-timer').forEach(el => { el.textContent = fmt; el.style.color = col; });
    document.querySelectorAll('.js-game-bar').forEach(el   => el.style.width = pct);

    if (s === 300 && !alertFired.five_min) { alertFired.five_min=true; showFlashMsg('⏳ 5 minutes remaining','warn'); }
    if (s === 120 && !alertFired.two_min)  { alertFired.two_min=true;  showFlashMsg('🏓 Next group — 2 min left','warn'); }
    if (s === 60  && !alertFired.one_min)  {
        alertFired.one_min=true;
        try { GameAlarm.almostEnd(); } catch(e) {}
        showAlert('alert-onemin', 8000);
        showFlashMsg('🏓 1 minute left — last rally!','warn');
    }
}

// ═══════════════════════════════════════════════════════════════
//  WARMUP TICK
// ═══════════════════════════════════════════════════════════════
function tickWarmup() {
    if (state.mode !== 'warmup') return;
    if (state.warmupRem <= 0) {
        state.warmupRem = 0;
        clearInterval(warmupTick); warmupTick = null;
        hideAllAlerts();
        launchGame();
        return;
    }
    state.warmupRem--;
    const fmt = fmtTime(state.warmupRem);
    const col = state.warmupRem <= 30 ? 'var(--danger)' : 'var(--warn)';
    document.querySelectorAll('.js-warmup-timer').forEach(el => { el.textContent = fmt; el.style.color = col; });
    const bt = document.getElementById('warmup-banner-timer');
    if (bt) { bt.textContent = fmt; bt.style.color = col; }
}

// ═══════════════════════════════════════════════════════════════
//  RENDER
//  FIX: All renders guard on state.mode so idle/warmup/game
//       never cross-contaminate each other's UI
// ═══════════════════════════════════════════════════════════════
function renderAll() {
    renderS1();
    renderS2();
    renderS3();
    renderS4();
}

function renderS1() {
    const left = document.getElementById('s1-left');
    if (!left) return;

    if (state.mode === 'game') {
        const col = state.isPaused ? 'var(--warn)' : gameTimerColor(state.remSecs);
        const lbl = state.isPaused ? '⏸ PAUSED' : '⏱ Time Remaining';
        left.innerHTML =
            `<div class="s1-state-label">${lbl}</div>
             <div class="s1-big-timer js-game-timer" style="color:${col}">${fmtTime(state.remSecs)}</div>
             <div class="s1-progress"><div class="s1-progress-fill js-game-bar" style="width:${state.pct.toFixed(1)}%"></div></div>
             <div class="s1-state-label">Now Playing</div>
             <div class="s1-players">${renderPlayerChips()}</div>`;

    } else if (state.mode === 'warmup') {
        left.innerHTML =
            `<div class="s1-state-label">🏓 Dink Time!</div>
             <div class="s1-big-timer js-warmup-timer" style="color:var(--warn)">${fmtTime(state.warmupRem)}</div>
             <div class="s1-state-label" style="margin-top:12px;">Game starting soon…</div>`;

    } else {
        left.innerHTML =
            `<div class="idle-center">
               <div class="idle-icon">🏟️</div>
               <div class="idle-title">COURT READY</div>
               <div class="idle-sub">Scan QR to join the queue</div>
             </div>`;
    }

    const ql = document.getElementById('s1-qlabel');
    if (ql) ql.textContent = `Queue — ${state.queue.length} Player${state.queue.length !== 1 ? 's' : ''}`;
    const sq = document.getElementById('s1-queue');
    if (sq) sq.innerHTML = buildQueueHTML(state.queue, PPG, true);
}

function renderS2() {
    const strip = document.getElementById('s2-strip');
    if (strip) {
        if (state.mode === 'game') {
            const col = state.isPaused ? 'var(--warn)' : gameTimerColor(state.remSecs);
            const lbl = state.isPaused ? '⏸ PAUSED' : '🏓 Game in progress';
            strip.style.borderColor = state.isPaused ? 'rgba(245,158,11,0.3)' : 'rgba(0,229,160,0.2)';
            strip.innerHTML =
                `<div class="s2-strip-timer js-game-timer" style="color:${col}">${fmtTime(state.remSecs)}</div>
                 <div class="s2-strip-meta">
                   <div style="font-weight:700;color:${col};">${lbl}</div>
                   <div>Auto-ends ${esc(state.endTime)}</div>
                 </div>
                 <div class="s2-strip-chips">${state.players.map((p, i) =>
                   `<div class="s2-chip">${esc(p.screen_name || 'P' + (i+1))}</div>`
                 ).join('')}</div>`;

        } else if (state.mode === 'warmup') {
            strip.style.borderColor = 'rgba(245,158,11,0.2)';
            strip.innerHTML =
                `<div style="font-size:26px;">🏓</div>
                 <div class="s2-strip-meta"><div style="font-weight:700;color:var(--warn);">Dink time — warmup in progress</div></div>
                 <div class="s2-strip-timer js-warmup-timer" style="color:var(--warn);margin-left:auto;">${fmtTime(state.warmupRem)}</div>`;

        } else {
            strip.style.borderColor = 'rgba(100,116,139,0.2)';
            strip.innerHTML = `<div style="color:var(--muted);font-size:15px;font-weight:700;">🏟️ Court Ready — waiting for players</div>`;
        }
    }

    const sq = document.getElementById('s2-queue');
    if (sq) {
        if (state.queue.length > PPG * 2) sq.style.gridTemplateColumns = '1fr 1fr';
        sq.innerHTML = buildQueueHTML(state.queue, PPG, false);
    }
}

function renderS3() {
    const el = document.getElementById('s3');
    if (!el) return;

    const isPlaying = state.mode === 'game' && state.players.length > 0;
    const spotlight = isPlaying ? state.players : state.queue.slice(0, PPG);
    const label     = isPlaying ? '🎮 NOW PLAYING' : '⏳ UP NEXT';
    const title     = isPlaying ? 'ON COURT'       : 'PLAYS NEXT';

    let timerHTML = '';
    if (isPlaying) {
        const col = state.isPaused ? 'var(--warn)' : gameTimerColor(state.remSecs);
        const borderExtra = state.isPaused ? 'border-color:rgba(245,158,11,0.3);background:rgba(245,158,11,0.1);' : '';
        timerHTML = `<div class="s3-timer-badge js-game-timer" style="color:${col};${borderExtra}">${fmtTime(state.remSecs)}</div>`;
    } else if (state.mode === 'warmup') {
        timerHTML = `<div class="s3-timer-badge js-warmup-timer" style="color:var(--warn);background:rgba(245,158,11,0.1);border-color:rgba(245,158,11,0.25);">${fmtTime(state.warmupRem)}</div>`;
    }

    let cards = '';
    for (let i = 0; i < PPG; i++) {
        const p = spotlight[i];
        cards += p
            ? `<div class="s3-card ${isPlaying ? 'playing' : ''}">
                 <div class="cnum">${i+1}</div>
                 <div style="flex:1"><div class="cname">${esc(p.screen_name || 'Player ' + (i+1))}</div></div>
                 <div style="font-size:clamp(18px,3vw,28px);">${isPlaying ? '🏓' : '⏳'}</div>
               </div>`
            : `<div class="s3-card empty">
                 <div class="cnum" style="color:rgba(255,255,255,0.1)">${i+1}</div>
                 <div style="flex:1;color:rgba(255,255,255,0.2)">— open slot</div>
               </div>`;
    }

    const waiting = [];
    for (let i = PPG; i < state.queue.length; i += PPG) waiting.push(state.queue.slice(i, i + PPG));
    const waitChips = waiting.map((grp, gi) =>
        `<div class="s3-wait-chip">Group ${gi+2}: ${grp.map(p => esc(p.screen_name || '?')).join(', ')}</div>`
    ).join('');

    el.innerHTML =
        `<div class="s3-label">${label}</div>
         <div class="s3-title">${title}</div>
         ${timerHTML}
         <div class="s3-cards">${cards}</div>
         ${waitChips ? `<div class="s3-waiting">${waitChips}</div>` : ''}`;
}

function renderS4() {
    const tb = document.getElementById('s4-timer-block');
    if (tb) {
        if (state.mode === 'game') {
            const col = state.isPaused ? 'var(--warn)' : gameTimerColor(state.remSecs);
            const lbl = state.isPaused ? '⏸ PAUSED' : 'Time Remaining';
            tb.innerHTML =
                `<div class="s4-label">${lbl}</div>
                 <div class="s4-timer js-game-timer" style="color:${col}">${fmtTime(state.remSecs)}</div>
                 <div class="s4-bar"><div class="s4-bar-fill js-game-bar" style="width:${state.pct.toFixed(1)}%"></div></div>`;

        } else if (state.mode === 'warmup') {
            tb.innerHTML =
                `<div class="s4-label">🏓 Dink Time</div>
                 <div class="s4-timer js-warmup-timer" style="color:var(--warn)">${fmtTime(state.warmupRem)}</div>`;

        } else {
            tb.innerHTML =
                `<div class="s4-label">Court Status</div>
                 <div class="s4-timer" style="color:var(--muted);font-size:clamp(36px,6vw,80px);">READY</div>`;
        }
    }

    const ql = document.getElementById('s4-qlabel');
    if (ql) ql.textContent = `Queue — ${state.queue.length} Player${state.queue.length !== 1 ? 's' : ''}`;
    const sq = document.getElementById('s4-queue');
    if (sq) sq.innerHTML = buildQueueHTML(state.queue, PPG, true);
}

function renderPlayerChips() {
    return state.players.length
        ? state.players.map((p, i) =>
            `<div class="s1-player"><div class="pnum">${i+1}</div><div class="pname">${esc(p.screen_name || 'Player ' + (i+1))}</div></div>`
          ).join('')
        : '<div style="color:var(--muted);font-size:14px;">No players yet</div>';
}

// ═══════════════════════════════════════════════════════════════
//  QUEUE HTML
// ═══════════════════════════════════════════════════════════════
function buildQueueHTML(queue, ppg, compact) {
    if (!queue || queue.length === 0) {
        return `<div style="text-align:center;padding:28px 0;color:rgba(255,255,255,0.2);">
                  <div style="font-size:32px;margin-bottom:8px;">🎾</div>
                  <div style="font-size:13px;">No players in queue</div>
                </div>`;
    }
    const groups = [];
    for (let i = 0; i < queue.length; i += ppg) groups.push(queue.slice(i, i + ppg));
    const fs = compact ? '13px' : '14px';
    const py = compact ? '6px 10px' : '8px 12px';
    let html = '';
    groups.forEach((grp, gi) => {
        const first = gi === 0;
        const color = first ? 'var(--accent)' : 'var(--warn)';
        html += `<div class="qg-header ${first ? 'gnext' : 'gwait'}">
                   <span>${first ? '🏓 UP NEXT' : 'GROUP ' + (gi+1)}</span>
                   <span style="font-size:11px;color:var(--muted)">${grp.length}/${ppg}</span>
                 </div>`;
        for (let s = 0; s < ppg; s++) {
            const p   = grp[s];
            const pos = gi * ppg + s + 1;
            html += p
                ? `<div class="qg-row ${first ? 'rnext' : 'rwait'}" style="padding:${py}">
                     <div class="qg-pos" style="color:${color}">${pos}</div>
                     <div class="qg-name" style="font-size:${fs}">${esc(p.screen_name || 'Player ' + pos)}</div>
                     <div class="qg-tick" style="color:${color}">✓</div>
                   </div>`
                : `<div class="qg-row rempty" style="padding:${py}">
                     <div class="qg-pos" style="color:rgba(255,255,255,0.1)">${pos}</div>
                     <div style="flex:1;color:rgba(255,255,255,0.15);font-size:${fs}">— open slot</div>
                   </div>`;
        }
    });
    return html;
}

// ═══════════════════════════════════════════════════════════════
//  SLIDE ROTATION
// ═══════════════════════════════════════════════════════════════
function rotateSlide(dir) { currentSlide = (currentSlide + dir + SLIDE_COUNT) % SLIDE_COUNT; applySlide(); }
function jumpSlide(idx)   { clearInterval(slideTick); currentSlide = idx; applySlide(); slideTick = setInterval(() => rotateSlide(1), SLIDE_SECS * 1000); }
function applySlide() {
    document.querySelectorAll('.slide').forEach((el, i) => el.classList.toggle('on', i === currentSlide));
    document.querySelectorAll('.slide-dot').forEach((el, i) => el.classList.toggle('on', i === currentSlide));
    renderAll();
    const hint = document.getElementById('swipe-hint');
    if (hint) { hint.style.opacity = '0'; setTimeout(() => hint.style.display = 'none', 600); }
}

// ═══════════════════════════════════════════════════════════════
//  ALERTS
// ═══════════════════════════════════════════════════════════════
function showAlert(id, autoDismiss) {
    hideAllAlerts();
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('show');
    if (autoDismiss) setTimeout(() => el.classList.remove('show'), autoDismiss);
}
function hideAllAlerts() {
    document.querySelectorAll('.k-alert').forEach(el => el.classList.remove('show'));
}

// ═══════════════════════════════════════════════════════════════
//  CLOCK
// ═══════════════════════════════════════════════════════════════
function clockTick() {
    const n = new Date();
    const el = document.getElementById('wall-clock');
    if (el) el.textContent = [n.getHours(), n.getMinutes(), n.getSeconds()]
        .map(v => String(v).padStart(2, '0')).join(':');
}

// ═══════════════════════════════════════════════════════════════
//  FLASH MESSAGE
// ═══════════════════════════════════════════════════════════════
let flashWrap = null;
function showFlashMsg(msg, type) {
    if (!flashWrap) {
        flashWrap = document.createElement('div');
        flashWrap.style.cssText = 'position:fixed;top:calc(var(--topbar-h) + 80px);right:16px;z-index:9000;display:flex;flex-direction:column;gap:8px;max-width:min(360px,90vw);';
        document.body.appendChild(flashWrap);
    }
    const c = {
        success: 'rgba(16,185,129,0.15)|var(--success)|#6ee7b7',
        error:   'rgba(239,68,68,0.15)|var(--danger)|#fca5a5',
        warn:    'rgba(245,158,11,0.15)|var(--warn)|#fcd34d',
    }[type]?.split('|') || [];
    const div = document.createElement('div');
    div.style.cssText = `background:${c[0]};border:1px solid ${c[1]};color:${c[2]};padding:10px 14px;border-radius:10px;font-size:clamp(12px,2.5vw,14px);font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,0.4);`;
    div.textContent = msg;
    flashWrap.appendChild(div);
    setTimeout(() => div.remove(), 5000);
}

// ═══════════════════════════════════════════════════════════════
//  UTILS
//  FIX: gameTimerColor() never fires 'danger' when remSecs=0
//       and no game is running (prevents false red on idle kiosk)
// ═══════════════════════════════════════════════════════════════
function fmtTime(s) {
    s = Math.max(0, Math.floor(s));
    return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
}
function gameTimerColor(s) {
    if (state.mode !== 'game' || state.totalSecs === 0) return 'var(--accent)';
    return s <= 60 ? 'var(--danger)' : s <= 300 ? 'var(--warn)' : 'var(--accent)';
}
function calcPct(rem, total) {
    if (!total || total <= 0) return 0;
    return Math.min(100, ((total - rem) / total) * 100);
}
function esc(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

document.addEventListener('keydown', e => {
    if (e.key === 'ArrowRight') rotateSlide(1);
    if (e.key === 'ArrowLeft')  rotateSlide(-1);
    if (e.key === 'Escape')     exitKiosk();
});

// ═══════════════════════════════════════════════════════════════
//  GAME LIFECYCLE — launch & end
//  FIX: launchGame() uses d.duration_mins (not d.duration)
//       end_ts computed correctly with proper fallback
// ═══════════════════════════════════════════════════════════════
function launchGame() {
    if (gameLaunchLocked) return;
    gameLaunchLocked = true;
    showFlashMsg('🚀 Starting game…', 'warn');

    fetch(APP_URL + '/court/game_engine.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ court_id: COURT_ID }),
    })
    .then(r => {
        if (!r.ok) return r.text().then(t => { throw new Error('HTTP ' + r.status + ': ' + t.slice(0, 200)); });
        return r.json();
    })
    .then(d => {
        if (d.status === 'started') {
            // FIX: d.duration_mins is the correct field name from game_engine.php
            const durMins = d.duration_mins || d.duration || 0;
            const endTs   = d.end_ts || (Date.now() / 1000 + durMins * 60);

            state.mode      = 'game';
            state.sessionId = d.session_id;
            state.totalSecs = durMins * 60;          // FIX: set totalSecs
            state.remSecs   = Math.max(0, Math.round(endTs - (Date.now() / 1000)));
            state.pct       = 0;
            state.isPaused  = false;
            state.endTs     = endTs;
            state.endTime   = new Date(endTs * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            state.players   = (d.players || []).map(p => ({
                screen_name: p.display_name || p.full_name || null,
            }));

            Object.keys(alertFired).forEach(k => alertFired[k] = false);
            gameEndLocked = false;
            clearInterval(warmupTick); warmupTick = null;
            clearInterval(timerTick);
            if (state.remSecs > 0) timerTick = setInterval(tickGame, 1000);

            hideAllAlerts();
            renderAll();
            updateAdminControls();
            try { GameAlarm.start(); } catch(e) {}
            showFlashMsg('✅ Game #' + d.session_id + ' started!', 'success');
            setTimeout(() => { gameLaunchLocked = false; fetchLiveData(); }, 3000);

        } else if (d.status === 'already_active') {
            gameLaunchLocked = false;
            fetchLiveData();
        } else {
            showFlashMsg('⚠️ ' + (d.message || 'Could not start game.'), 'warn');
            gameLaunchLocked = false;
        }
    })
    .catch(err => {
        showFlashMsg('❌ ' + err.message, 'warn');
        gameLaunchLocked = false;
    });
}

function triggerGameEnd() {
    if (gameEndLocked || !state.sessionId) return;
    gameEndLocked = true;

    fetch(APP_URL + '/court/end_game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ session_id: state.sessionId, action: 'complete' }),
    })
    .then(r => {
        if (!r.ok) return r.text().then(t => { throw new Error('HTTP ' + r.status + ': ' + t.slice(0, 200)); });
        return r.json();
    })
    .then(d => {
        if (d.status === 'success') {
            showFlashMsg('🏆 ' + d.message, 'success');
            hideAllAlerts();
            state.mode = 'idle'; state.sessionId = null;
            state.remSecs = 0; state.totalSecs = 0; state.pct = 0;
            state.players = []; state.isPaused = false;
            clearInterval(timerTick); timerTick = null;
            renderAll(); updateAdminControls();
        } else {
            showFlashMsg('⚠️ ' + (d.message || 'Error ending game.'), 'warn');
        }
        setTimeout(() => { gameEndLocked = false; fetchLiveData(); }, 2000);
    })
    .catch(() => {
        gameEndLocked = false;
        setTimeout(fetchLiveData, 2000);
    });
}
// ═══════════════════════════════════════════════════════════════
//  TOURNAMENT MODE — separate overlay, separate poll, separate
//  endpoint (kiosk_tournament_data.php). Deliberately independent
//  of the walk-in queue state machine above.
// ═══════════════════════════════════════════════════════════════
function fetchTourneyData() {
    fetch(APP_URL + '/admin/kiosk_tournament_data.php?court_id=' + COURT_ID, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(renderTourney)
        .catch(() => {});
}

function renderTourney(d) {
    const btn = document.getElementById('tourney-tab-btn');
    btn.classList.toggle('has-live', !!d.has_match);

    const empty = document.getElementById('tv-empty');
    const box   = document.getElementById('tv-match');
    const view  = document.getElementById('tournament-view');

    if (!d.has_match) {
        empty.style.display = 'block';
        box.style.display   = 'none';
        view.classList.remove('big-moment');
        return;
    }

    empty.style.display = 'none';
    box.style.display   = 'block';
    view.classList.toggle('big-moment', !!d.big_moment);

    document.getElementById('tv-tourney-name').textContent = d.tournament_name || '';
    document.getElementById('tv-round').textContent = (d.big_moment ? '🏆 ' : '') + d.round_name;
    document.getElementById('tv-p1-name').textContent  = d.p1_name;
    document.getElementById('tv-p2-name').textContent  = d.p2_name;
    document.getElementById('tv-p1-score').textContent = d.score_player1;
    document.getElementById('tv-p2-score').textContent = d.score_player2;
    document.getElementById('tv-serve-1').classList.toggle('on', d.serving_player === 1);
    document.getElementById('tv-serve-2').classList.toggle('on', d.serving_player === 2);

    // Auto-surface tournament mode once, the first time this court
    // has a live match — staff can still tab back to the walk-in
    // queue manually at any point afterward.
    if (!tourneyAutoShown && d.status === 'in_progress') {
        tourneyAutoShown = true;
        showTournamentView();
    }
}

function showTournamentView() {
    document.getElementById('tournament-view').classList.add('show');
}
function hideTournamentView() {
    document.getElementById('tournament-view').classList.remove('show');
}
</script>
</body>
</html>
