<?php
// ============================================================
//  FILE: index.php — Padol Pickleball Court · Public Landing
//  v5 — CSP-clean: no inline styles, no inline event handlers
//  v6 — restored + fixed: defines $isOpenPlayTonight (was
//  triggering an "Undefined variable" notice in the ticker),
//  and removes a leftover orphaned markup fragment that was
//  dangling after the Live Courts section.
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
$db = getDB();

function getSiteContent(PDO $db, string $section): array {
    $stmt = $db->prepare("SELECT key, value FROM falcon.site_content WHERE section = :s");
    $stmt->execute([':s' => $section]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[$r['key']] = $r['value'];
    return $out;
}

$hero       = getSiteContent($db, 'hero');
$about      = getSiteContent($db, 'about');
$location   = getSiteContent($db, 'location');
$social     = getSiteContent($db, 'social');
$ticker     = getSiteContent($db, 'ticker');
$footerCont = getSiteContent($db, 'footer');
$actSection = getSiteContent($db, 'activities_section');

$isLoggedIn = false;
if (function_exists('isLoggedIn')) {
    $isLoggedIn = isLoggedIn();
}
$dashUrl = APP_URL . '/auth/login.php';
if (!empty($isLoggedIn) && function_exists('roleDashboard')) {
    $dashUrl = APP_URL . '/' . roleDashboard();
}

$plans    = $db->query("SELECT * FROM falcon.membership_plans ORDER BY sort_order")->fetchAll();
$slots    = $db->query("SELECT * FROM falcon.schedule_slots ORDER BY sort_order")->fetchAll();
$events   = $db->query("SELECT * FROM falcon.events WHERE is_active=TRUE ORDER BY event_date")->fetchAll();
$training = $db->query("SELECT * FROM falcon.training_programs WHERE is_active=TRUE ORDER BY sort_order")->fetchAll();
$shop     = $db->query("SELECT * FROM falcon.shop_items WHERE is_active=TRUE ORDER BY sort_order")->fetchAll();

$activities = $db->query("
    SELECT id, name, description, icon, price_per_hour, flat_price, pricing_note, photo
    FROM falcon.activity_types
    WHERE is_active = TRUE
    ORDER BY sort_order, id
")->fetchAll();

$liveSession   = null;
$queueCount    = 0;
$todayGames    = 0;
$courtIsOpen   = false;
$courtName     = 'Padol Court';
$slotModeNow   = 'reservation';

// ── Is tonight's 8PM slot an open-play session? ──────────────
// Mirrors the logic used in player/dashboard.php so the ticker
// and hero copy never disagree with what players see once
// logged in.
$isOpenPlayTonight = true;
try {
    $openPlayMode = $db->query("
        SELECT mode FROM falcon.court_slot_modes
        WHERE court_id = (SELECT id FROM falcon.courts WHERE is_active = TRUE ORDER BY id LIMIT 1)
          AND time_from <= '20:00:00' AND time_to >= '20:00:00'
          AND ((slot_date = CURRENT_DATE) OR (slot_date IS NULL AND day_of_week = EXTRACT(DOW FROM CURRENT_DATE)::int))
        ORDER BY CASE WHEN slot_date IS NOT NULL THEN 1 ELSE 2 END ASC LIMIT 1
    ")->fetch();
    if ($openPlayMode) $isOpenPlayTonight = ($openPlayMode['mode'] === 'open_play');
} catch (PDOException $e) {}

// ── Multi-Court Status ───────────────────────────────────────
$allCourts = $db->query("SELECT * FROM falcon.v_court_status WHERE is_active = TRUE ORDER BY sort_order, id")->fetchAll();

$todayResPublic = (int)$db->query("
    SELECT COUNT(*) FROM falcon.reservations
    WHERE slot_date = CURRENT_DATE AND status IN ('pending','confirmed')
")->fetchColumn();

$featuredEvent = null;
$sideEvents    = [];
foreach ($events as $ev) {
    if ($ev['is_featured'] && $featuredEvent === null) $featuredEvent = $ev;
    else $sideEvents[] = $ev;
}
if (!$featuredEvent && count($events) > 0) {
    $featuredEvent = $events[0];
    $sideEvents    = array_slice($events, 1);
}

function h(mixed $v, string $fallback = ''): string {
    return htmlspecialchars((string)($v ?? $fallback), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Padol Pickleball Court — Polomolok, South Cotabato</title>
<meta name="description" content="Padol Pickleball Court — Premier pickleball facility in Polomolok, South Cotabato. Join, play, compete.">
<meta name="theme-color" content="#00e5a0">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🦅</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>

<style nonce="<?= getCspNonce() ?>">
/* ============================================================
   ROOT VARIABLES & RESET
   ============================================================ */
:root {
  --bg:      #05080f; --bg2:    #080d18;
  --surface: #0d1526; --surface2: #111e35;
  --border:  rgba(0,229,160,0.12);
  --accent:  #00e5a0; --accent2: #00b8ff; --accent3: #ff6b35;
  --text:    #e8f0fe; --muted:   #6b7fa3;
  --font-h:  'Bebas Neue', sans-serif;
  --font-b:  'Outfit', sans-serif;
  --font-m:  'Space Mono', monospace;
  --glow:    0 0 40px rgba(0,229,160,0.25);
  --glow-sm: 0 0 15px rgba(0,229,160,0.15);
  --radius:  12px;
  --safe-l:  env(safe-area-inset-left, 0px);
  --safe-r:  env(safe-area-inset-right, 0px);
  --safe-b:  env(safe-area-inset-bottom, 0px);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; font-size: 16px; overflow-x: hidden; }
body {
  font-family: var(--font-b); background: var(--bg);
  color: var(--text); overflow-x: hidden; max-width: 100vw;
  padding-left: var(--safe-l); padding-right: var(--safe-r);
}
* { -webkit-tap-highlight-color: transparent; }
button, a, .btn { touch-action: manipulation; }
img, svg, video { display: block; max-width: 100%; }
::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 99px; }
body::before {
  content: ''; position: fixed; inset: 0;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.03'/%3E%3C/svg%3E");
  pointer-events: none; z-index: 1; opacity: 0.4;
}

/* ============================================================
   SPLASH
   ============================================================ */
#splash {
  position: fixed; inset: 0; z-index: 9999; background: var(--bg);
  display: flex; align-items: center; justify-content: center;
  flex-direction: column; gap: 16px; pointer-events: none;
}
#splash.hide { animation: splashOut 1.2s cubic-bezier(.76,0,.24,1) forwards .1s; }
@keyframes splashOut { 0%{opacity:1;transform:scale(1)} 60%{opacity:1;transform:scale(1.04)} 100%{opacity:0;transform:scale(1.08)} }
.splash-icon { width:110px;height:110px;display:flex;align-items:center;justify-content:center;animation:sIconIn 1s cubic-bezier(.34,1.56,.64,1) forwards;opacity:0;margin-bottom:4px; }
@keyframes sIconIn { from{opacity:0;transform:scale(.2) translateY(30px)} to{opacity:1;transform:scale(1) translateY(0)} }
.splash-icon svg { animation:splashGlow 3s ease-in-out infinite; animation-delay:1.2s; }
@keyframes splashGlow { 0%,100%{filter:drop-shadow(0 0 10px rgba(0,229,160,.4))} 50%{filter:drop-shadow(0 0 32px rgba(0,229,160,.9)) drop-shadow(0 0 60px rgba(0,229,160,.4))} }
.splash-word { font-family:var(--font-h);font-size:clamp(60px,16vw,130px);letter-spacing:.08em;color:var(--text);line-height:1;animation:sWordIn 1s cubic-bezier(.34,1.56,.64,1) forwards .2s;opacity:0; }
@keyframes sWordIn { from{opacity:0;transform:translateY(30px) scaleX(.85)} to{opacity:1;transform:translateY(0) scaleX(1)} }
.splash-sub { font-family:var(--font-m);font-size:clamp(9px,2.5vw,11px);letter-spacing:.3em;color:var(--accent);text-transform:uppercase;animation:sFadeIn .8s ease forwards .6s;opacity:0;text-align:center;padding:0 20px; }
@keyframes sFadeIn { from{opacity:0} to{opacity:1} }
.splash-bar { width:140px;height:2px;background:var(--surface2);border-radius:99px;overflow:hidden;margin-top:20px;opacity:0;animation:sFadeIn .5s ease forwards .7s; }
.splash-bar-fill { height:100%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:99px;width:0;animation:sLoad 1.6s ease forwards .7s; }
@keyframes sLoad { from{width:0} to{width:100%} }
#main-content { opacity: 1; }

/* ============================================================
   NAVBAR
   ============================================================ */
#navbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
  padding: 0 max(40px, var(--safe-l));
  padding-right: max(40px, var(--safe-r));
  height: 70px; display: flex; align-items: center; justify-content: space-between;
  transition: background .4s, backdrop-filter .4s, box-shadow .4s;
}
#navbar.scrolled {
  background: rgba(5,8,15,.94);
  backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
  box-shadow: 0 1px 0 var(--border);
}
.nav-logo { display:flex;align-items:center;gap:10px;text-decoration:none;flex-shrink:0; }
.nav-logo-icon { width:36px;height:36px;display:flex;align-items:center;justify-content:center;transition:transform .3s; }
.nav-logo-icon:hover { transform:scale(1.12); }
.nav-logo-text { font-family:var(--font-h);font-size:22px;letter-spacing:.05em;color:var(--text);line-height:1; }
.nav-logo-text span { color:var(--accent); }
.nav-links { display:flex;align-items:center;gap:4px;list-style:none; }
.nav-links a { font-size:13px;font-weight:500;color:var(--muted);text-decoration:none;padding:7px 12px;border-radius:8px;letter-spacing:.02em;transition:color .2s,background .2s;white-space:nowrap; }
.nav-links a:hover { color:var(--text);background:var(--surface); }
.nav-links a.active { color:var(--accent); }
.nav-cta { display:flex;align-items:center;gap:10px;flex-shrink:0; }

/* Buttons */
.btn { display:inline-flex;align-items:center;justify-content:center;gap:8px;font-family:var(--font-b);font-weight:600;border-radius:10px;cursor:pointer;transition:all .2s;text-decoration:none;border:none;font-size:14px;letter-spacing:.02em;white-space:nowrap;min-height:44px; }
.btn-sm  { padding:9px 20px;font-size:13px;min-height:40px; }
.btn-md  { padding:13px 28px;font-size:15px; }
.btn-lg  { padding:16px 36px;font-size:16px; }
.btn-ghost   { color:var(--muted);background:transparent;border:1px solid var(--border); }
.btn-ghost:hover { color:var(--text);border-color:var(--accent);background:rgba(0,229,160,.06); }
.btn-primary { color:var(--bg);background:var(--accent);box-shadow:0 4px 20px rgba(0,229,160,.3); }
.btn-primary:hover { background:#00ffb2;box-shadow:0 6px 30px rgba(0,229,160,.5);transform:translateY(-1px); }
.btn-outline  { color:var(--accent);background:transparent;border:1.5px solid var(--accent); }
.btn-outline:hover { background:rgba(0,229,160,.08);box-shadow:var(--glow-sm); }
.btn-secondary { color:var(--text);background:var(--surface2);border:1px solid var(--border); }
.btn-secondary:hover { background:var(--surface);border-color:var(--accent); }

/* Hamburger */
.hamburger { display:none;flex-direction:column;gap:5px;background:none;border:none;cursor:pointer;padding:8px;min-width:44px;min-height:44px;align-items:center;justify-content:center;z-index:1001; }
.hamburger span { width:24px;height:2px;background:var(--text);border-radius:99px;transition:all .3s;display:block; }
.hamburger.open span:nth-child(1) { transform:translateY(7px) rotate(45deg); }
.hamburger.open span:nth-child(2) { opacity:0;transform:scaleX(0); }
.hamburger.open span:nth-child(3) { transform:translateY(-7px) rotate(-45deg); }

/* Mobile nav */
.mobile-nav {
  display:none;position:fixed;inset:0;top:70px;
  background:rgba(5,8,15,.98);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
  z-index:999;flex-direction:column;align-items:center;justify-content:center;gap:2px;
  padding:24px 20px;opacity:0;transform:translateY(-10px);
  transition:opacity .3s,transform .3s;overflow-y:auto;
}
.mobile-nav.open { display:flex;opacity:1;transform:translateY(0); }
.mobile-nav a { font-family:var(--font-h);font-size:clamp(22px,7vw,32px);letter-spacing:.08em;color:var(--muted);text-decoration:none;padding:6px 20px;transition:color .2s;min-height:44px;display:flex;align-items:center; }
.mobile-nav a:hover { color:var(--accent); }
.mobile-nav-cta { margin-top:18px;display:flex;flex-direction:column;gap:10px;width:100%;max-width:280px; }
.mobile-nav-cta .btn { width:100%;justify-content:center; }

/* ============================================================
   HERO
   ============================================================ */
#hero {
  min-height:100svh;position:relative;display:flex;align-items:center;overflow:hidden;
  padding:70px clamp(20px,5vw,60px) 60px;
}
.hero-bg {
  position:absolute;inset:0;
  background: radial-gradient(ellipse 80% 60% at 70% 40%, rgba(0,229,160,.07) 0%, transparent 70%),
              radial-gradient(ellipse 60% 50% at 20% 70%, rgba(0,184,255,.05) 0%, transparent 60%),
              linear-gradient(180deg, var(--bg) 0%, var(--bg2) 100%);
}
.hero-court-lines { position:absolute;right:-80px;top:50%;transform:translateY(-50%);width:clamp(300px,50vw,600px);height:clamp(250px,42vw,500px);opacity:.04;pointer-events:none; }
.hero-content { position:relative;z-index:2;max-width:760px; }
.hero-badge { display:inline-flex;align-items:center;gap:8px;background:rgba(0,229,160,.08);border:1px solid rgba(0,229,160,.2);border-radius:99px;padding:6px 16px;font-family:var(--font-m);font-size:clamp(9px,1.5vw,11px);letter-spacing:.2em;color:var(--accent);text-transform:uppercase;margin-bottom:28px;opacity:0;animation:fadeUp .8s ease forwards .2s; }
.hero-badge::before { content:'';width:6px;height:6px;background:var(--accent);border-radius:50%;flex-shrink:0;animation:pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.3)} }
.hero-title { font-family:var(--font-h);font-size:clamp(48px,9vw,110px);line-height:.95;letter-spacing:.03em;color:var(--text);margin-bottom:24px;opacity:0;animation:fadeUp .9s ease forwards .4s; }
.hero-title .accent { color:var(--accent);display:block; }
.hero-title .outline-text { -webkit-text-stroke:1.5px var(--text);color:transparent; }
.hero-desc { font-size:clamp(14px,1.8vw,18px);font-weight:300;color:var(--muted);line-height:1.7;max-width:500px;margin-bottom:40px;opacity:0;animation:fadeUp .9s ease forwards .6s; }
.hero-actions { display:flex;gap:14px;flex-wrap:wrap;opacity:0;animation:fadeUp .9s ease forwards .8s; }
.hero-stats { display:flex;align-items:center;gap:clamp(20px,4vw,40px);margin-top:clamp(24px,4vw,40px);flex-wrap:wrap;opacity:0;animation:fadeUp .9s ease forwards 1s; }
.hero-stat-num { font-family:var(--font-h);font-size:clamp(30px,5vw,42px);letter-spacing:.03em;color:var(--text);line-height:1; }
.hero-stat-label { font-size:clamp(10px,1.2vw,12px);font-weight:500;color:var(--muted);text-transform:uppercase;letter-spacing:.12em;margin-top:4px; }
.hero-scroll { position:absolute;bottom:40px;left:50%;transform:translateX(-50%);display:flex;flex-direction:column;align-items:center;gap:8px;opacity:0;animation:fadeUp .9s ease forwards 1.2s; }
.scroll-line { width:1px;height:50px;background:linear-gradient(to bottom,var(--accent),transparent);animation:scrollPulse 2s ease infinite; }
@keyframes scrollPulse { 0%,100%{opacity:1;transform:scaleY(1)} 50%{opacity:.4;transform:scaleY(.6)} }
.hero-scroll span { font-family:var(--font-m);font-size:10px;letter-spacing:.25em;color:var(--muted);text-transform:uppercase; }
@keyframes fadeUp { from{opacity:0;transform:translateY(30px)} to{opacity:1;transform:translateY(0)} }

/* ============================================================
   TICKER
   ============================================================ */
.ticker-wrap { background:var(--accent);padding:clamp(10px,1.5vw,14px) 0;overflow:hidden;white-space:nowrap; }
.ticker-track { display:inline-flex;animation:ticker 22s linear infinite; }
.ticker-item { font-family:var(--font-h);font-size:clamp(12px,1.5vw,14px);letter-spacing:.15em;color:var(--bg);padding:0 clamp(18px,2.5vw,30px);display:inline-flex;align-items:center;gap:20px; }
.ticker-item::before { content:'●';font-size:8px; }
@keyframes ticker { from{transform:translateX(0)} to{transform:translateX(-50%)} }

/* ============================================================
   SECTION BASE
   ============================================================ */
.section { padding:clamp(70px,10vw,120px) clamp(20px,5vw,60px);position:relative; }
.section-inner { max-width:1200px;margin:0 auto; }
.section-label { font-family:var(--font-m);font-size:11px;letter-spacing:.3em;color:var(--accent);text-transform:uppercase;margin-bottom:16px;display:flex;align-items:center;gap:12px; }
.section-label::after { content:'';height:1px;width:40px;background:var(--accent);opacity:.5; }
.section-label.centered { justify-content:center; }
.section-title { font-family:var(--font-h);font-size:clamp(36px,6vw,72px);letter-spacing:.03em;line-height:1;margin-bottom:20px; }
.section-desc { font-size:clamp(14px,1.8vw,17px);font-weight:300;color:var(--muted);max-width:540px;line-height:1.7;margin-bottom:clamp(32px,5vw,60px); }
.reveal { opacity:0;transform:translateY(40px);transition:opacity .7s ease,transform .7s ease; }
.reveal.revealed { opacity:1;transform:translateY(0); }

/* Staggered reveal delays */
.delay-7 { transition-delay: 0.07s; }
.delay-8 { transition-delay: 0.08s; }
.delay-10 { transition-delay: 0.1s; }
.delay-15 { transition-delay: 0.15s; }
.delay-16 { transition-delay: 0.16s; }
.delay-32 { transition-delay: 0.32s; }

/* ============================================================
   LIVE STATUS
   ============================================================ */
#live-status { background:var(--bg2);border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:clamp(32px,4vw,56px) clamp(20px,5vw,60px); }
.live-inner { max-width:1200px;margin:0 auto; }
.live-inner .section-title { grid-column:1/-1;text-align:center;margin-bottom:clamp(24px,3vw,40px);color:var(--text);font-size:clamp(24px,3vw,36px);font-weight:700; }
.courts-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:clamp(16px,2vw,24px); }
.court-card { background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:clamp(20px,2.5vw,28px);transition:border-color .2s,transform .2s; }
.court-card:hover { border-color:rgba(0,229,160,.3);transform:translateY(-2px); }
.court-header { display:flex;align-items:center;justify-content:space-between;margin-bottom:12px; }
.court-name { font-size:16px;font-weight:700;color:var(--text); }
.court-type { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);background:var(--surface2);border-radius:99px;padding:4px 8px; }
.court-divider { border:0;border-top:1px solid var(--border);margin:12px 0; }
.court-status .status-text { font-size:14px;font-weight:600;margin-bottom:16px; }
.court-status .status-text.available { color:var(--accent); }
.court-status .status-text.active { color:#00aaff; }
.court-status .status-text.queuing { color:#f59e0b; }
.court-status .status-text.maintenance { color:#6b7280; }
.court-status .status-text.closed { color:#ef4444; }
.court-status .status-text.reserved { color:#8b5cf6; }
.court-status .status-text.unknown { color:var(--muted); }
.court-action { text-align:center; }
.court-action .btn-primary { background:var(--accent);color:var(--bg);border:1px solid var(--accent);padding:10px 20px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;transition:background .2s,border-color .2s; }
.court-action .btn-primary:hover { background:var(--accent2);border-color:var(--accent2); }

/* ============================================================
   ABOUT
   ============================================================ */
#about { background:var(--bg2); }
.about-grid { display:grid;grid-template-columns:1fr 1fr;gap:clamp(32px,6vw,80px);align-items:center; }
.about-visual { position:relative; }
.about-court-card { background:var(--surface);border:1px solid var(--border);border-radius:20px;overflow:hidden;aspect-ratio:4/3;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--surface) 0%,var(--surface2) 100%); }
.court-svg { width:80%;max-width:340px;opacity:.7; }
.about-accent-card { position:absolute;bottom:-24px;right:-24px;background:var(--accent);border-radius:16px;padding:20px 24px;text-align:center;box-shadow:var(--glow); }
.about-accent-card .num { font-family:var(--font-h);font-size:42px;color:var(--bg);line-height:1; }
.about-accent-card .label { font-size:12px;font-weight:600;color:rgba(5,8,15,.7);letter-spacing:.06em; }
.about-features { display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:36px; }
.feature-chip { display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:11px 14px;font-size:13px;font-weight:500;transition:border-color .2s,background .2s; }
.feature-chip:hover { border-color:var(--accent);background:rgba(0,229,160,.05); }
.feature-chip svg { color:var(--accent);flex-shrink:0;width:15px;height:15px; }
.about-desc2 { font-size:clamp(13px,1.5vw,15px);color:var(--muted);line-height:1.8;margin-bottom:32px; }

/* ============================================================
   GALLERY CAROUSEL
   ============================================================ */
#gallery { background: var(--bg); padding: 0; position: relative; overflow: hidden; }
.gallery-label-bar {
  max-width: 1200px; margin: 0 auto;
  padding: clamp(40px,6vw,70px) clamp(20px,5vw,60px) clamp(20px,3vw,32px);
  display: flex; align-items: flex-end; justify-content: space-between;
  gap: 20px; flex-wrap: wrap;
}
.gallery-label-bar-left .section-label { margin-bottom: 10px; }
.gallery-label-bar-left .section-title { margin-bottom: 8px; font-size: clamp(28px,4.5vw,56px); }
.gallery-label-bar-left p { font-size: clamp(13px,1.5vw,15px); color: var(--muted); max-width: 460px; line-height: 1.6; }
.gallery-carousel-outer { position: relative; width: 100%; overflow: hidden; background: var(--bg2); }
.gallery-track { display: flex; transition: transform 0.55s cubic-bezier(.4,0,.2,1); will-change: transform; }
.gallery-slide { min-width: 100%; position: relative; overflow: hidden; }
.gallery-slide img { width: 100%; height: clamp(240px, 50vw, 560px); object-fit: cover; object-position: center; display: block; transition: transform 8s ease; }
.gallery-slide.active img { transform: scale(1.03); }
.gallery-empty-slide { width: 100%; height: clamp(240px,42vw,480px); display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 12px; background: var(--surface); color: var(--muted); }
.gallery-empty-slide .ge-icon { font-size: 52px; opacity: .3; }
.gallery-empty-slide p { font-size: 14px; }
.gallery-caption { position: absolute; bottom: 0; left: 0; right: 0; padding: clamp(28px,4vw,48px) clamp(20px,4vw,48px) clamp(18px,2.5vw,28px); background: linear-gradient(to top, rgba(5,8,15,.9) 0%, rgba(5,8,15,.4) 60%, transparent 100%); pointer-events: none; }
.gallery-caption-text { font-size: clamp(13px,1.5vw,16px); color: rgba(232,240,254,.85); font-weight: 400; letter-spacing: .02em; max-width: 600px; line-height: 1.5; }
.gallery-caption-num { font-family: var(--font-m); font-size: 11px; color: var(--accent); letter-spacing: .15em; margin-bottom: 8px; text-transform: uppercase; }
.gallery-arrow { position: absolute; top: 50%; transform: translateY(-50%); z-index: 10; background: rgba(5,8,15,.6); border: 1px solid var(--border); color: var(--text); width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all .2s; backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); }
.gallery-arrow:hover { background: var(--accent); border-color: var(--accent); color: var(--bg); transform: translateY(-50%) scale(1.08); }
.gallery-arrow svg { width: 20px; height: 20px; flex-shrink: 0; }
.gallery-arrow.prev { left: clamp(12px,2vw,24px); }
.gallery-arrow.next { right: clamp(12px,2vw,24px); }
.gallery-dots { display: flex; align-items: center; justify-content: center; gap: 8px; padding: clamp(16px,2vw,22px) 20px; background: var(--bg); }
.gallery-dot { width: 7px; height: 7px; border-radius: 99px; background: var(--surface2); border: 1px solid var(--border); cursor: pointer; transition: all .3s; }
.gallery-dot.active { width: 24px; background: var(--accent); border-color: var(--accent); }
.gallery-cta-bar { background: var(--bg); padding: clamp(20px,2.5vw,28px) clamp(20px,5vw,60px); display: flex; align-items: center; justify-content: space-between; gap: 16px; max-width: 100%; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.gallery-cta-info { font-size: clamp(13px,1.5vw,15px); color: var(--muted); }
.gallery-cta-info strong { color: var(--text); }

/* ============================================================
   MEMBERSHIP
   ============================================================ */
#membership { background:var(--bg);position:relative;overflow:hidden; }
#membership::before { content:'';position:absolute;top:-200px;left:50%;transform:translateX(-50%);width:700px;height:700px;background:radial-gradient(circle,rgba(0,229,160,.04) 0%,transparent 70%);pointer-events:none; }
.membership-header { text-align:center;margin-bottom:clamp(36px,5vw,60px); }
.membership-header .section-desc { margin:0 auto;text-align:center; }
.membership-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:22px; }
.plan-card { background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:clamp(24px,3vw,36px) clamp(20px,2.5vw,30px);position:relative;transition:transform .3s,box-shadow .3s,border-color .3s;overflow:hidden; }
.plan-card::before { content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--border);transition:background .3s; }
.plan-card:hover { transform:translateY(-6px);box-shadow:0 20px 60px rgba(0,0,0,.4);border-color:rgba(0,229,160,.25); }
.plan-card:hover::before { background:var(--accent); }
.plan-card.featured { background:linear-gradient(135deg,rgba(0,229,160,.08) 0%,rgba(0,229,160,.03) 100%);border-color:rgba(0,229,160,.3);transform:scale(1.03); }
.plan-card.featured::before { background:var(--accent);box-shadow:var(--glow); }
.plan-card.featured:hover { transform:scale(1.03) translateY(-6px); }
.plan-badge { display:inline-block;background:var(--accent);color:var(--bg);font-size:10px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;padding:4px 12px;border-radius:99px;margin-bottom:18px; }
.plan-name    { font-family:var(--font-h);font-size:28px;letter-spacing:.05em;margin-bottom:4px; }
.plan-tagline { font-size:13px;color:var(--muted);margin-bottom:24px; }
.plan-price   { display:flex;align-items:flex-end;gap:4px;margin-bottom:8px; }
.plan-price .currency { font-size:18px;font-weight:600;color:var(--accent);line-height:2; }
.plan-price .amount   { font-family:var(--font-h);font-size:clamp(44px,6vw,60px);letter-spacing:-.02em;line-height:1; }
.plan-price .period   { font-size:14px;color:var(--muted);line-height:2.4; }
.plan-per-game { font-size:12px;color:var(--muted);margin-bottom:28px; }
.plan-per-game span { color:var(--accent); }
.plan-divider { height:1px;background:var(--border);margin-bottom:24px; }
.plan-features { list-style:none;display:flex;flex-direction:column;gap:11px;margin-bottom:32px; }
.plan-features li { display:flex;align-items:flex-start;gap:10px;font-size:13px;color:var(--muted); }
.plan-features li svg { color:var(--accent);flex-shrink:0;margin-top:2px;width:14px;height:14px; }
.plan-features li.muted svg, .plan-features li.muted span { opacity:.4; }
.plan-btn-wrap { width:100%; }
.plan-btn-wrap .btn { width:100%;justify-content:center; }

/* ============================================================
   SCHEDULE
   ============================================================ */
#schedule { background:var(--bg2); }
.schedule-header { display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:clamp(28px,4vw,50px);flex-wrap:wrap;gap:20px; }
.hours-card { background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:clamp(14px,2vw,24px) clamp(16px,2.5vw,28px);display:flex;align-items:center;gap:18px; }
.hours-icon { width:44px;height:44px;background:rgba(0,229,160,.1);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.hours-icon svg { color:var(--accent);width:22px;height:22px; }
.hours-time { font-family:var(--font-h);font-size:clamp(18px,2.5vw,26px);letter-spacing:.03em; }
.hours-days { font-size:12px;color:var(--muted);margin-top:2px; }
.time-slots { display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:clamp(10px,1.5vw,14px); }
.time-slot { background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:clamp(14px,2vw,20px) clamp(12px,1.5vw,18px);cursor:pointer;transition:all .2s; }
.time-slot:hover { border-color:var(--accent);background:rgba(0,229,160,.04);transform:translateY(-2px); }
.time-slot.unavailable { opacity:.4;cursor:not-allowed; }
.time-slot.unavailable:hover { transform:none; }
.slot-time   { font-family:var(--font-m);font-size:clamp(12px,1.5vw,15px);color:var(--text);margin-bottom:8px; }
.slot-status { font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;display:flex;align-items:center;gap:6px; }
.slot-dot    { width:7px;height:7px;border-radius:50%;flex-shrink:0; }
.available .slot-dot { background:var(--accent); }
.busy      .slot-dot { background:var(--accent3); }
.unavailable .slot-dot { background:var(--muted); }
.slot-players { font-size:12px;color:var(--muted);margin-top:6px; }
.time-slot.available .slot-status { color:var(--accent); }
.time-slot.busy .slot-status      { color:var(--accent3); }
/* Slot legend */
.slot-legend { display:flex;gap:18px;margin-top:14px;flex-wrap:wrap;font-size:12px;color:var(--muted); }
.slot-legend-dot { display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:5px;vertical-align:middle; }
.slot-legend-dot.sl-available { background:var(--accent); }
.slot-legend-dot.sl-busy      { background:#f59e0b; }
.slot-legend-dot.sl-reserved  { background:#00b8ff; }
.slot-legend-dot.sl-full      { background:#ef4444; }
.slot-legend-dot.sl-past      { background:var(--muted);opacity:.4; }
.schedule-book-wrap { margin-top:28px;text-align:center; }
/* Live slots status bar */
#slots-status-bar { display:flex;align-items:center;gap:10px;margin-bottom:16px;font-size:12px;color:var(--muted);font-family:var(--font-m);flex-wrap:wrap; }
#slots-live-dot { width:7px;height:7px;border-radius:50%;background:var(--accent);display:inline-block;animation:pulse 2s infinite;flex-shrink:0; }

/* ============================================================
   EVENTS
   ============================================================ */
#events { background:var(--bg); }
.events-header { margin-bottom:clamp(28px,4vw,50px); }
.events-grid { display:grid;grid-template-columns:2fr 1fr;gap:22px; }
.event-card-featured { background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:clamp(22px,3.5vw,40px);position:relative;overflow:hidden;transition:border-color .3s; }
.event-card-featured:hover { border-color:rgba(0,229,160,.3); }
.event-card-featured::after { content:'';position:absolute;top:-80px;right:-80px;width:280px;height:280px;background:radial-gradient(circle,rgba(0,229,160,.06) 0%,transparent 70%);pointer-events:none; }
.event-date-badge { display:inline-flex;flex-direction:column;align-items:center;background:var(--accent);color:var(--bg);border-radius:12px;padding:8px 14px;margin-bottom:22px;min-width:52px; }
.event-date-badge .day { font-family:var(--font-h);font-size:28px;line-height:1; }
.event-date-badge .month { font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase; }
.event-tag { display:inline-block;background:rgba(0,229,160,.1);color:var(--accent);border:1px solid rgba(0,229,160,.2);font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;padding:4px 12px;border-radius:99px;margin-bottom:10px; }
.event-title { font-family:var(--font-h);font-size:clamp(24px,3.5vw,38px);letter-spacing:.03em;margin-bottom:10px;line-height:1.1; }
.event-desc  { font-size:clamp(13px,1.5vw,15px);color:var(--muted);line-height:1.7;margin-bottom:24px;max-width:460px; }
.event-meta  { display:flex;gap:18px;flex-wrap:wrap;margin-bottom:24px; }
.event-meta-item { display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted); }
.event-meta-item svg { color:var(--accent);width:15px;height:15px;flex-shrink:0; }
.events-list { display:flex;flex-direction:column;gap:14px; }
.event-card-small { background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:clamp(14px,2vw,22px);cursor:pointer;transition:all .2s;display:flex;gap:14px;align-items:flex-start; }
.event-card-small:hover { border-color:rgba(0,229,160,.3);transform:translateX(4px); }
.event-mini-date { background:var(--surface2);border-radius:10px;padding:7px 9px;text-align:center;flex-shrink:0;min-width:44px; }
.event-mini-date .day   { font-family:var(--font-h);font-size:20px;color:var(--text);line-height:1; }
.event-mini-date .month { font-size:10px;font-weight:600;letter-spacing:.1em;color:var(--muted);text-transform:uppercase; }
.event-small-title { font-size:clamp(12px,1.5vw,14px);font-weight:600;margin-bottom:3px; }
.event-small-type  { font-size:12px;color:var(--accent); }

/* ============================================================
   TOURNAMENTS
   ============================================================ */
#tournaments { background:var(--bg); }
.tournaments-header { margin-bottom:clamp(28px,4vw,50px); }
.tournaments-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:clamp(16px,2vw,24px); }
.tournament-card { background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:clamp(20px,2.5vw,28px);transition:border-color .3s,transform .3s; }
.tournament-card:hover { border-color:rgba(0,229,160,.3);transform:translateY(-4px); }
.tournament-header { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px; }
.tournament-date-badge { display:inline-flex;flex-direction:column;align-items:center;background:var(--accent);color:var(--bg);border-radius:10px;padding:6px 10px;margin-bottom:0;min-width:48px;flex-shrink:0; }
.tournament-date-badge .day { font-family:var(--font-h);font-size:24px;line-height:1; }
.tournament-date-badge .month { font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase; }
.tournament-status { font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;padding:4px 10px;border-radius:99px;border:1px solid var(--border);background:var(--surface2);color:var(--muted); }
.tournament-status.registration { background:rgba(0,229,160,.1);color:var(--accent);border-color:rgba(0,229,160,.3); }
.tournament-status.active { background:rgba(0,184,255,.1);color:var(--accent2);border-color:rgba(0,184,255,.3); }
.tournament-title { font-family:var(--font-h);font-size:clamp(18px,2.5vw,24px);letter-spacing:.04em;margin-bottom:8px;line-height:1.2; }
.tournament-desc { font-size:13px;color:var(--muted);line-height:1.6;margin-bottom:16px;max-width:100%; }
.tournament-meta { display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px; }
.tournament-meta-item { display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted); }
.tournament-meta-item svg { color:var(--accent);width:14px;height:14px;flex-shrink:0; }
.tournaments-empty { text-align:center;padding:clamp(40px,6vw,60px);background:var(--surface);border:1px solid var(--border);border-radius:20px;margin:0 auto;max-width:400px; }
.tournaments-empty-icon { font-size:48px;margin-bottom:16px; }
.tournaments-empty h3 { font-family:var(--font-h);font-size:24px;margin-bottom:8px; }
.tournaments-empty p { color:var(--muted);margin-bottom:24px; }

/* ============================================================
   ACTIVITY CENTER
   ============================================================ */
#activities { background:linear-gradient(135deg,var(--bg2) 0%,var(--bg) 100%);position:relative;overflow:hidden; }
#activities::before { content:'';position:absolute;top:-100px;right:-100px;width:500px;height:500px;background:radial-gradient(circle,rgba(0,184,255,.04) 0%,transparent 70%);pointer-events:none; }
.activities-header { margin-bottom:clamp(28px,4vw,60px);text-align:center; }
.activities-header .section-desc { margin:0 auto;text-align:center; }
.activities-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:clamp(14px,2vw,22px); }
.activity-card { background:var(--surface);border:1px solid var(--border);border-radius:20px;overflow:hidden;transition:transform .3s,box-shadow .3s,border-color .3s; }
.activity-card:hover { transform:translateY(-6px);box-shadow:0 24px 60px rgba(0,0,0,.4);border-color:rgba(0,184,255,.3); }
.activity-card-img { aspect-ratio:16/9;background:var(--surface2);display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden; }
.activity-card-img img { width:100%;height:100%;object-fit:cover; }
.activity-emoji { font-size:clamp(44px,6vw,64px);line-height:1;opacity:.75;filter:drop-shadow(0 0 16px rgba(0,184,255,.4)); }
.activity-card-body { padding:clamp(16px,2vw,22px); }
.activity-name  { font-family:var(--font-h);font-size:clamp(18px,2.5vw,24px);letter-spacing:.04em;margin-bottom:6px; }
.activity-desc  { font-size:13px;color:var(--muted);line-height:1.6;margin-bottom:14px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden; }
.activity-price { font-family:var(--font-m);font-size:12px;color:var(--accent2);margin-bottom:16px; }
.activity-card-footer { display:flex;align-items:center;justify-content:space-between;gap:8px; }
.activity-badge { display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:99px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;background:rgba(0,184,255,.1);color:var(--accent2);border:1px solid rgba(0,184,255,.2); }
.activities-cta-wrap { text-align:center;margin-top:clamp(24px,3vw,40px); }

/* ============================================================
   TRAINING — Desktop Cards + Mobile Accordion
   ============================================================ */
#training { background:var(--bg); }
.training-header { margin-bottom:clamp(28px,4vw,60px);text-align:center; }
.training-header .section-desc { margin:0 auto;text-align:center; }
.training-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:clamp(14px,2vw,22px); }
.training-card { background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:clamp(22px,2.5vw,32px) clamp(18px,2vw,28px);transition:all .3s;position:relative;overflow:hidden; }
.training-card::after { content:'';position:absolute;bottom:0;left:0;right:0;height:3px;background:transparent;transition:background .3s; }
.training-card:hover { transform:translateY(-6px);box-shadow:0 20px 50px rgba(0,0,0,.4); }
.training-card:hover::after { background:var(--accent); }
.training-icon { width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;margin-bottom:20px; }
.training-icon svg { width:24px;height:24px; }
.icon-green  { background:rgba(0,229,160,.1);  } .icon-green  svg { color:var(--accent); }
.icon-blue   { background:rgba(0,184,255,.1);  } .icon-blue   svg { color:var(--accent2); }
.icon-orange { background:rgba(255,107,53,.1); } .icon-orange svg { color:var(--accent3); }
.training-title { font-family:var(--font-h);font-size:clamp(20px,2.5vw,26px);letter-spacing:.04em;margin-bottom:10px; }
.training-desc  { font-size:13px;color:var(--muted);line-height:1.7;margin-bottom:20px; }
.training-price { font-family:var(--font-m);font-size:13px;color:var(--accent); }

/* ── Mobile Accordion ─────────────────────────────── */
.training-accordion { display: none; }
.training-accordion-item { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; margin-bottom: 10px; transition: border-color .2s; }
.training-accordion-item.open { border-color: rgba(0,229,160,.3); }
.training-accordion-header { display: flex; align-items: center; gap: 14px; padding: 18px 20px; cursor: pointer; user-select: none; -webkit-user-select: none; transition: background .2s; position: relative; min-height: 68px; }
.training-accordion-header:hover { background: rgba(0,229,160,.03); }
.training-accordion-icon-wrap { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.training-accordion-icon-wrap svg { width: 22px; height: 22px; }
.training-accordion-title { font-family: var(--font-h); font-size: 20px; letter-spacing: .04em; line-height: 1.1; flex: 1; }
.training-accordion-price { font-family: var(--font-m); font-size: 11px; color: var(--accent); white-space: nowrap; }
.training-accordion-chevron { width: 28px; height: 28px; background: var(--surface2); border: 1px solid var(--border); border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: transform .35s ease, background .2s; }
.training-accordion-chevron svg { width: 14px; height: 14px; color: var(--muted); transition: color .2s; }
.training-accordion-item.open .training-accordion-chevron { transform: rotate(180deg); background: rgba(0,229,160,.1); border-color: rgba(0,229,160,.3); }
.training-accordion-item.open .training-accordion-chevron svg { color: var(--accent); }
.training-accordion-body { max-height: 0; overflow: hidden; transition: max-height .4s cubic-bezier(.4,0,.2,1), padding .4s; padding: 0 20px; }
.training-accordion-item.open .training-accordion-body { max-height: 400px; padding: 0 20px 20px; }
.training-accordion-body-inner { border-top: 1px solid var(--border); padding-top: 16px; }
.training-accordion-desc { font-size: 14px; color: var(--muted); line-height: 1.7; margin-bottom: 16px; }

/* ============================================================
   SHOP
   ============================================================ */
#shop { background:var(--bg2); }
.shop-header { display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:clamp(28px,4vw,50px);flex-wrap:wrap;gap:20px; }
.shop-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:clamp(12px,1.5vw,18px); }
.shop-card { background:var(--surface);border:1px solid var(--border);border-radius:18px;overflow:hidden;transition:all .3s; }
.shop-card:hover { transform:translateY(-6px);box-shadow:0 20px 50px rgba(0,0,0,.4);border-color:rgba(0,229,160,.25); }
.shop-img { aspect-ratio:1;background:var(--surface2);display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden; }
.shop-img img { width:100%;height:100%;object-fit:cover; }
.shop-img svg { width:64px;height:64px;opacity:.25; }
.shop-badge-new { position:absolute;top:10px;right:10px;background:var(--accent);color:var(--bg);font-size:10px;font-weight:700;letter-spacing:.1em;padding:3px 8px;border-radius:99px; }
.shop-info  { padding:clamp(12px,1.5vw,18px); }
.shop-cat   { font-size:10px;font-weight:600;letter-spacing:.12em;color:var(--muted);text-transform:uppercase;margin-bottom:5px; }
.shop-name  { font-size:clamp(12px,1.5vw,15px);font-weight:600;margin-bottom:12px;line-height:1.3; }
.shop-price-row { display:flex;align-items:center;justify-content:space-between; }
.shop-price { font-family:var(--font-m);font-size:clamp(13px,1.5vw,16px);color:var(--accent); }
.shop-add   { width:36px;height:36px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;min-width:36px;min-height:36px; }
.shop-add:hover { background:var(--accent);border-color:var(--accent); }
.shop-add:hover svg { color:var(--bg); }
.shop-add svg { color:var(--muted);width:16px;height:16px; }

/* ============================================================
   LOCATION / MAP
   ============================================================ */
#location { background:var(--bg); }
.location-header { margin-bottom:clamp(28px,4vw,50px); }
.location-grid { display:grid;grid-template-columns:1fr 1fr;gap:clamp(24px,4vw,50px);align-items:start; }
.location-info { display:flex;flex-direction:column;gap:14px; }
.info-card { background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:clamp(16px,2vw,24px);display:flex;gap:14px;align-items:flex-start;transition:border-color .2s; }
.info-card:hover { border-color:rgba(0,229,160,.3); }
.info-icon  { width:42px;height:42px;background:rgba(0,229,160,.1);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.info-icon svg { color:var(--accent);width:19px;height:19px; }
.info-label { font-size:11px;font-weight:600;letter-spacing:.12em;color:var(--muted);text-transform:uppercase;margin-bottom:5px; }
.info-value { font-size:clamp(13px,1.5vw,15px);font-weight:500;line-height:1.5; }
.info-value .accent-text { color:var(--accent);font-size:13px; }
.map-container { background:var(--surface);border:1px solid var(--border);border-radius:20px;overflow:hidden;height:clamp(240px,40vw,420px);position:relative; }
#leaflet-map { width:100%;height:100%; }
.map-overlay-tag { position:absolute;top:14px;left:14px;z-index:500;background:rgba(5,8,15,.88);backdrop-filter:blur(12px);border:1px solid var(--border);border-radius:10px;padding:8px 14px;display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;pointer-events:none; }
.map-dot { width:7px;height:7px;background:var(--accent);border-radius:50%;animation:pulse 2s infinite;flex-shrink:0; }

/* Leaflet custom marker styles — no inline styles needed */
.falcon-marker-wrap {
  width: 40px; height: 40px;
  background: var(--accent);
  border-radius: 50% 50% 50% 4px;
  transform: rotate(-45deg);
  border: 3px solid #fff;
  box-shadow: 0 4px 20px rgba(0,229,160,.6);
  display: flex; align-items: center; justify-content: center;
}
.falcon-marker-inner {
  transform: rotate(45deg);
  font-size: 18px;
  color: #05080f;
  line-height: 1;
}
.falcon-pulse-ring {
  width: 80px; height: 80px;
  border-radius: 50%;
  border: 2px solid rgba(0,229,160,.4);
  animation: mapPulse 2s ease infinite;
}
@keyframes mapPulse {
  0%,100% { transform:scale(1);opacity:.4; }
  50%      { transform:scale(1.2);opacity:.1; }
}

/* ============================================================
   SOCIAL
   ============================================================ */
#social-section { background:var(--surface);padding:clamp(48px,7vw,80px) clamp(20px,5vw,60px);text-align:center;border-top:1px solid var(--border);border-bottom:1px solid var(--border); }
.social-title { font-family:var(--font-h);font-size:clamp(28px,5vw,56px);letter-spacing:.05em;margin-bottom:10px; }
.social-sub   { font-size:clamp(13px,1.5vw,16px);color:var(--muted);margin-bottom:36px; }
.social-icons { display:flex;justify-content:center;gap:14px;flex-wrap:wrap; }
.social-btn   { display:flex;align-items:center;gap:10px;background:var(--surface2);border:1px solid var(--border);border-radius:14px;padding:clamp(13px,1.8vw,16px) clamp(18px,2.5vw,28px);text-decoration:none;color:var(--text);font-weight:600;font-size:clamp(13px,1.5vw,15px);transition:all .3s;min-height:52px; }
.social-btn svg { width:20px;height:20px;flex-shrink:0; }
.social-btn.fb:hover { background:rgba(24,119,242,.1);border-color:#1877F2;color:#1877F2;transform:translateY(-3px);box-shadow:0 10px 30px rgba(24,119,242,.2); }
.social-btn.ig:hover { background:rgba(225,48,108,.1);border-color:#e1306c;color:#e1306c;transform:translateY(-3px); }
.social-btn.tt:hover { background:rgba(255,0,80,.1);border-color:#ff0050;color:#ff0050;transform:translateY(-3px); }

/* ============================================================
   FOOTER
   ============================================================ */
footer { background:var(--bg2);border-top:1px solid var(--border);padding:clamp(48px,6vw,80px) clamp(20px,5vw,60px) clamp(28px,3vw,40px); }
.footer-grid { max-width:1200px;margin:0 auto;display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:clamp(24px,4vw,60px);padding-bottom:clamp(32px,4vw,60px);border-bottom:1px solid var(--border); }
.footer-brand-logo  { display:flex;align-items:center;gap:10px;margin-bottom:16px; }
.footer-logo-icon   { width:44px;height:44px;display:flex;align-items:center;justify-content:center;filter:drop-shadow(0 0 8px rgba(0,229,160,.55));flex-shrink:0; }
.footer-brand-name  { font-family:var(--font-h);font-size:22px;letter-spacing:.05em; }
.footer-brand-name span { color:var(--accent); }
.footer-desc   { font-size:clamp(12px,1.3vw,14px);color:var(--muted);line-height:1.7;max-width:280px;margin-bottom:20px; }
.footer-social { display:flex;gap:8px;flex-wrap:wrap; }
.footer-social a { width:36px;height:36px;background:var(--surface);border:1px solid var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;transition:all .2s;min-width:36px; }
.footer-social a:hover { background:var(--accent);border-color:var(--accent); }
.footer-social a:hover svg { color:var(--bg); }
.footer-social a svg { color:var(--muted);width:15px;height:15px; }
.footer-col-title { font-family:var(--font-h);font-size:16px;letter-spacing:.08em;margin-bottom:18px; }
.footer-links { list-style:none;display:flex;flex-direction:column;gap:8px; }
.footer-links a { font-size:clamp(12px,1.3vw,14px);color:var(--muted);text-decoration:none;transition:color .2s;display:flex;align-items:center;gap:6px;min-height:32px; }
.footer-links a:hover { color:var(--accent); }
.footer-links a::before { content:'—';font-size:10px;opacity:.4;flex-shrink:0; }
.footer-contact-item { display:flex;gap:9px;align-items:flex-start;margin-bottom:12px; }
.footer-contact-item svg { color:var(--accent);width:14px;height:14px;flex-shrink:0;margin-top:2px; }
.footer-contact-item span { font-size:clamp(11px,1.2vw,13px);color:var(--muted);line-height:1.5; }
.footer-bottom { max-width:1200px;margin:0 auto;padding-top:26px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px; }
.footer-copy   { font-size:12px;color:var(--muted); }
.footer-copy span { color:var(--accent); }
.footer-copy .dev-credit { font-size:11px;opacity:.7; }
.footer-legal  { display:flex;gap:18px;flex-wrap:wrap; }
.footer-legal a { font-size:12px;color:var(--muted);text-decoration:none;transition:color .2s;min-height:32px;display:flex;align-items:center; }
.footer-legal a:hover { color:var(--accent); }

/* ============================================================
   FAB
   ============================================================ */
.fab { position:fixed;bottom:max(100px,calc(var(--safe-b) + 100px));right:max(32px,var(--safe-r));z-index:900;opacity:0;transform:translateY(20px);transition:opacity .4s,transform .4s; }
.fab.visible { opacity:1;transform:translateY(0); }
.fab-main { width:52px;height:52px;background:var(--accent);border-radius:16px;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:var(--glow);transition:all .2s;border:none; }
.fab-main:hover { transform:scale(1.08);background:#00ffb2; }
.fab-main svg { color:var(--bg);width:22px;height:22px; }

/* ============================================================
   ACTIVITIES MODAL — all classes, zero inline styles
   ============================================================ */
.act-modal-overlay {
  display: none;
  position: fixed; inset: 0; z-index: 9999;
  background: rgba(5,8,15,0.92);
  backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
  overflow-y: auto;
  padding: clamp(20px,4vw,40px) clamp(16px,3vw,30px);
}
.act-modal-overlay.open { display: block; }
.act-modal-box {
  max-width: 1100px; margin: 0 auto;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 20px; padding: clamp(24px,3vw,40px);
  animation: mActIn .28s cubic-bezier(.34,1.56,.64,1);
}
@keyframes mActIn {
  from { opacity:0; transform:scale(.93) translateY(16px); }
  to   { opacity:1; transform:scale(1)   translateY(0);    }
}
.act-modal-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 16px; margin-bottom: 28px; padding-bottom: 20px;
  border-bottom: 1px solid var(--border);
}
.act-modal-title-group .section-label { margin-bottom: 8px; }
.act-modal-title-group h2 {
  font-family: var(--font-h);
  font-size: clamp(28px,4vw,46px); letter-spacing: .04em;
  line-height: 1; margin-bottom: 6px;
}
.act-modal-title-group p { font-size: clamp(13px,1.5vw,15px); color: var(--muted); }
.act-modal-close {
  background: var(--surface2); border: 1px solid var(--border);
  color: var(--muted); font-size: 20px; cursor: pointer;
  width: 42px; height: 42px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; transition: color .2s, border-color .2s, background .2s;
  line-height: 1;
}
.act-modal-close:hover {
  color: #ef4444; border-color: rgba(239,68,68,.4);
  background: rgba(239,68,68,.06);
}
.act-modal-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px,1fr));
  gap: clamp(14px,2vw,22px);
}
.act-modal-card {
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 18px; overflow: hidden;
  transition: transform .3s, border-color .3s;
}
.act-modal-card:hover { transform: translateY(-4px); border-color: rgba(0,184,255,.35); }
.act-modal-card-img {
  aspect-ratio: 16/9; background: var(--surface);
  display: flex; align-items: center; justify-content: center;
  overflow: hidden; position: relative;
}
.act-modal-card-img img { width:100%; height:100%; object-fit:cover; }
.act-modal-card-emoji { font-size: 56px; line-height: 1; opacity: .65; }
.act-modal-card-body { padding: clamp(14px,1.8vw,20px); }
.act-modal-card-name {
  font-family: var(--font-h); font-size: 22px;
  letter-spacing: .04em; margin-bottom: 6px;
}
.act-modal-card-desc { font-size: 13px; color: var(--muted); line-height: 1.7; margin-bottom: 12px; }
.act-modal-card-price { font-family: var(--font-m); font-size: 12px; color: var(--accent2); margin-bottom: 16px; }
.act-modal-card-btn { width: 100%; justify-content: center; font-size: 13px; }
.act-modal-footer {
  margin-top: 28px; padding-top: 22px;
  border-top: 1px solid var(--border); text-align: center;
}
.act-modal-footer-note { font-size: 12px; color: var(--muted); margin-top: 10px; }

/* ============================================================
   ACTIVITY CENTER — Mobile Accordion
   ============================================================ */
.activities-accordion { display: none; }
.activities-accordion-item { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; margin-bottom: 10px; transition: border-color .25s; }
.activities-accordion-item.open { border-color: rgba(0,184,255,.35); }
.activities-accordion-header { display: flex; align-items: center; gap: 14px; padding: 16px 18px; cursor: pointer; user-select: none; -webkit-user-select: none; transition: background .2s; min-height: 68px; }
.activities-accordion-header:hover { background: rgba(0,184,255,.04); }
.activities-accordion-emoji { width: 46px; height: 46px; border-radius: 12px; background: rgba(0,184,255,.1); display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; line-height: 1; }
.activities-accordion-title { font-family: var(--font-h); font-size: 20px; letter-spacing: .04em; line-height: 1.1; flex: 1; }
.activities-accordion-price { font-family: var(--font-m); font-size: 11px; color: var(--accent2); white-space: nowrap; }
.activities-accordion-chevron { width: 28px; height: 28px; background: var(--surface2); border: 1px solid var(--border); border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: transform .35s ease, background .2s; }
.activities-accordion-chevron svg { width: 14px; height: 14px; color: var(--muted); transition: color .2s; }
.activities-accordion-item.open .activities-accordion-chevron { transform: rotate(180deg); background: rgba(0,184,255,.12); border-color: rgba(0,184,255,.3); }
.activities-accordion-item.open .activities-accordion-chevron svg { color: var(--accent2); }
.activities-accordion-body { max-height: 0; overflow: hidden; transition: max-height .4s cubic-bezier(.4,0,.2,1), padding .4s; padding: 0 18px; }
.activities-accordion-item.open .activities-accordion-body { max-height: 500px; padding: 0 18px 18px; }
.activities-accordion-body-inner { border-top: 1px solid var(--border); padding-top: 14px; }
.activities-accordion-img { width: 100%; aspect-ratio: 16/9; border-radius: 10px; overflow: hidden; background: var(--surface2); display: flex; align-items: center; justify-content: center; margin-bottom: 12px; font-size: 48px; }
.activities-accordion-img img { width:100%; height:100%; object-fit:cover; }
.activities-accordion-desc { font-size: 14px; color: var(--muted); line-height: 1.7; margin-bottom: 14px; }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
  .hero-content { max-width:620px; }
  .shop-grid { grid-template-columns:repeat(3,1fr); }
}
@media (max-width: 1024px) {
  #navbar { padding:0 max(28px,var(--safe-l)) 0 max(28px,var(--safe-l));height:65px; }
  #hero { padding-top:65px; }
  .about-grid { gap:40px; }
  .events-grid { grid-template-columns:3fr 2fr; }
  .membership-grid { grid-template-columns:repeat(3,1fr); }
  .location-grid { grid-template-columns:1fr 1fr;gap:32px; }
  .footer-grid { grid-template-columns:2fr 1fr 1fr;gap:32px; }
  .time-slots { grid-template-columns:repeat(3,1fr); }
}
@media (max-width: 900px) {
  #navbar { padding:0 max(20px,var(--safe-l));height:62px; }
  .mobile-nav { top:62px; }
  .nav-links, .nav-cta { display:none !important; }
  .hamburger { display:flex; }
  .live-inner { grid-template-columns:1fr 1fr; }
  .about-grid { grid-template-columns:1fr; }
  .about-visual { max-width:500px; }
  .about-accent-card { right:-10px;bottom:-14px;padding:16px 20px; }
  .about-accent-card .num { font-size:34px; }
  .membership-grid { grid-template-columns:repeat(2,1fr); }
  .plan-card.featured { transform:scale(1); }
  .plan-card.featured:hover { transform:translateY(-6px); }
  .events-grid { grid-template-columns:1fr; }
  .location-grid { grid-template-columns:1fr; }
  .gallery-arrow { width:40px;height:40px; }
  .gallery-arrow svg { width:16px;height:16px; }
}
@media (max-width: 768px) {
  .activities-grid      { display: none; }
  .activities-accordion { display: block; }
  #hero { padding:72px 20px 50px; }
  .hero-title { font-size:clamp(42px,12vw,72px); }
  .hero-title .outline-text { -webkit-text-stroke:1px var(--text); }
  .hero-desc { font-size:15px;max-width:100%; }
  .hero-court-lines { display:none; }
  .hero-scroll { display:none; }
  .section { padding:clamp(60px,8vw,80px) 20px; }
  .about-features { grid-template-columns:1fr 1fr; }
  .membership-grid { grid-template-columns:1fr;max-width:420px;margin:0 auto; }
  .schedule-header { flex-direction:column;align-items:flex-start; }
  .hours-card { width:100%;max-width:100%; }
  .time-slots { grid-template-columns:repeat(2,1fr); }
  .map-container { height:280px; }
  .footer-grid { grid-template-columns:1fr 1fr;gap:28px; }
  .footer-bottom { flex-direction:column;text-align:center; }
  .footer-legal { justify-content:center; }
  .training-grid { display:none; }
  .training-accordion { display:block; }
  .gallery-label-bar { flex-direction:column;align-items:flex-start; }
  .gallery-arrow { display:none; }
  .act-modal-grid { grid-template-columns:1fr; }
  .tournaments-grid { grid-template-columns:1fr; }
}
@media (max-width: 600px) {
  .hero-actions { flex-direction:column; }
  .hero-actions .btn { width:100%;justify-content:center; }
  .live-inner { grid-template-columns:1fr; }
  .social-icons { flex-direction:column;align-items:stretch;max-width:300px;margin:0 auto; }
  .social-btn { width:100%;justify-content:center; }
}
@media (max-width: 480px) {
  #hero { padding:68px 16px 44px; }
  .hero-badge { font-size:9px;letter-spacing:.12em;padding:5px 12px;margin-bottom:20px; }
  .hero-title { font-size:clamp(36px,13vw,58px); }
  .hero-stats { gap:14px 24px; }
  .section { padding:55px 16px; }
  .about-features { grid-template-columns:1fr; }
  .about-accent-card { right:0;bottom:-10px; }
  .membership-grid { max-width:100%; }
  .time-slots { grid-template-columns:repeat(2,1fr);gap:10px; }
  .event-meta { flex-direction:column;gap:10px; }
  .activities-grid { grid-template-columns:1fr; }
  .tournaments-grid { grid-template-columns:1fr; }
  .shop-grid { grid-template-columns:repeat(2,1fr);gap:12px; }
  .footer-grid { grid-template-columns:1fr;gap:24px; }
  .map-container { height:220px; }
  .gallery-cta-bar { flex-direction:column;align-items:flex-start; }
}
@media (max-width: 360px) {
  .shop-grid { grid-template-columns:1fr;max-width:260px;margin:0 auto; }
  .time-slots { grid-template-columns:1fr; }
  .hero-badge { display:none; }
}
@media (min-width: 769px) and (max-width: 1024px) {
  .activities-grid      { display: grid; }
  .activities-accordion { display: none; }
  .nav-links, .nav-cta { display:flex !important; }
  .hamburger { display:none; }
  .nav-links a { font-size:12px;padding:6px 10px; }
  .training-grid { display:grid; }
  .training-accordion { display:none; }
  .tournaments-grid { display: grid; }
}
@media print {
  #splash,#navbar,.fab,.mobile-nav,.ticker-wrap { display:none !important; }
  #main-content { opacity:1 !important; }
  .section { padding:30px 20px; }
}
</style>
</head>
<body>

<!-- SPLASH -->
<div id="splash">
  <div class="splash-icon">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="110" height="110">
      <rect x="44" y="63" width="13" height="30" rx="6.5" fill="#00e5a0"/>
      <rect x="44" y="69" width="13" height="2.5" rx="1.2" fill="#003d2a" opacity="0.45"/>
      <rect x="44" y="75" width="13" height="2.5" rx="1.2" fill="#003d2a" opacity="0.45"/>
      <rect x="44" y="81" width="13" height="2.5" rx="1.2" fill="#003d2a" opacity="0.45"/>
      <rect x="20" y="8" width="58" height="60" rx="29" fill="#00e5a0"/>
      <circle cx="36" cy="22" r="3.8" fill="#003d2a" opacity="0.32"/><circle cx="50" cy="22" r="3.8" fill="#003d2a" opacity="0.32"/>
      <circle cx="64" cy="22" r="3.8" fill="#003d2a" opacity="0.32"/><circle cx="43" cy="33" r="3.8" fill="#003d2a" opacity="0.32"/>
      <circle cx="57" cy="33" r="3.8" fill="#003d2a" opacity="0.32"/><circle cx="36" cy="44" r="3.8" fill="#003d2a" opacity="0.32"/>
      <circle cx="50" cy="44" r="3.8" fill="#003d2a" opacity="0.32"/><circle cx="64" cy="44" r="3.8" fill="#003d2a" opacity="0.32"/>
      <circle cx="43" cy="55" r="3.8" fill="#003d2a" opacity="0.32"/><circle cx="57" cy="55" r="3.8" fill="#003d2a" opacity="0.32"/>
      <circle cx="80" cy="18" r="14" fill="#f5e642" stroke="#00e5a0" stroke-width="2.5"/>
      <circle cx="74" cy="13" r="2.2" fill="#d4c820" opacity="0.8"/><circle cx="83" cy="11" r="2.2" fill="#d4c820" opacity="0.8"/>
      <circle cx="88" cy="19" r="2.2" fill="#d4c820" opacity="0.8"/><circle cx="84" cy="26" r="2.2" fill="#d4c820" opacity="0.8"/>
      <circle cx="75" cy="25" r="2.2" fill="#d4c820" opacity="0.8"/>
    </svg>
  </div>
  <div class="splash-word">PADOL</div>
  <div class="splash-sub">Pickleball Court · Polomolok</div>
  <div class="splash-bar"><div class="splash-bar-fill"></div></div>
</div>

<div id="main-content">

<!-- ══ NAVBAR ════════════════════════════════════════════ -->
<nav id="navbar">
  <a class="nav-logo" href="#hero">
    <div class="nav-logo-icon">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="34" height="34">
        <rect x="44" y="63" width="13" height="30" rx="6.5" fill="#00e5a0"/>
        <rect x="20" y="8" width="58" height="60" rx="29" fill="#00e5a0"/>
        <circle cx="50" cy="35" r="12" fill="#003d2a" opacity="0.15"/>
        <circle cx="80" cy="18" r="14" fill="#f5e642" stroke="#00e5a0" stroke-width="2.5"/>
      </svg>
    </div>
    <div class="nav-logo-text">PADOL<span>.</span></div>
  </a>

  <ul class="nav-links">
    <li><a href="#about">About</a></li>
    <li><a href="#membership">Membership</a></li>
    <li><a href="#schedule">Schedule</a></li>
    <li><a href="#activities">Activities</a></li>
    <li><a href="#events">Events</a></li>
    <li><a href="#training">Training</a></li>
    <li><a href="#shop">Shop</a></li>
    <li><a href="#location">Location</a></li>
  </ul>

  <div class="nav-cta">
    <?php if (!empty($isLoggedIn)): ?>
      <a href="<?= $dashUrl ?>" class="btn btn-ghost btn-sm" title="Go to your dashboard for bookings, account info, and tournament access">Dashboard</a>
    <?php else: ?>
      <a href="auth/login.php" class="btn btn-ghost btn-sm" title="Log in to manage your bookings and view member-only features">Log In</a>
      <a href="auth/register.php" class="btn btn-primary btn-sm" title="Create an account to join tournaments, access bookings, and start playing">Join Now</a>
    <?php endif; ?>
  </div>

  <button class="hamburger" id="hamburger" aria-label="Open menu" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>
</nav>

<!-- ══ MOBILE NAV ═════════════════════════════════════════ -->
<nav class="mobile-nav" id="mobile-nav" aria-hidden="true">
  <a href="#about"       class="mobile-link">About</a>
  <a href="#membership"  class="mobile-link">Membership</a>
  <a href="#schedule"    class="mobile-link">Schedule</a>
  <a href="#activities"  class="mobile-link">Activities</a>
  <a href="#events"      class="mobile-link">Events</a>
  <a href="#training"    class="mobile-link">Training</a>
  <a href="#shop"        class="mobile-link">Shop</a>
  <a href="#location"    class="mobile-link">Location</a>
  <div class="mobile-nav-cta">
    <?php if (!empty($isLoggedIn)): ?>
      <a href="<?= $dashUrl ?>" class="btn btn-primary btn-md">🏠 Dashboard</a>
    <?php else: ?>
      <a href="auth/login.php"    class="btn btn-ghost btn-md">Log In</a>
      <a href="auth/register.php" class="btn btn-primary btn-md">Join Now</a>
    <?php endif; ?>
  </div>
</nav>

<!-- ══ HERO ══════════════════════════════════════════════ -->
<section id="hero">
  <div class="hero-bg"></div>
  <div class="hero-court-lines" aria-hidden="true">
    <svg viewBox="0 0 600 500" fill="none"><rect x="50" y="50" width="500" height="400" stroke="white" stroke-width="3"/><line x1="50" y1="250" x2="550" y2="250" stroke="white" stroke-width="3"/><line x1="300" y1="50" x2="300" y2="250" stroke="white" stroke-width="3"/><rect x="50" y="50" width="175" height="100" stroke="white" stroke-width="2"/><rect x="375" y="50" width="175" height="100" stroke="white" stroke-width="2"/><rect x="50" y="350" width="175" height="100" stroke="white" stroke-width="2"/><rect x="375" y="350" width="175" height="100" stroke="white" stroke-width="2"/><circle cx="300" cy="250" r="30" stroke="white" stroke-width="2"/></svg>
  </div>
  <div class="hero-content">
    <div class="hero-badge"><?= h($hero['badge'] ?? 'Now Open · Polomolok, South Cotabato') ?></div>
    <h1 class="hero-title">
      <span class="outline-text"><?= h($hero['title_line1'] ?? 'Play Like') ?></span>
      <span class="accent"><?= h($hero['title_line2'] ?? 'A Padol') ?></span>
    </h1>
    <p class="hero-desc"><?= h($hero['description'] ?? 'Premier pickleball facility in South Cotabato. Professional courts, competitive leagues, and a community built for players at every level.') ?></p>
    <div class="hero-actions">
      <?php if (!empty($isLoggedIn)): ?>
        <a href="<?= $dashUrl ?>" class="btn btn-primary btn-lg">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
          My Dashboard
        </a>
      <?php else: ?>
        <a href="auth/register.php" class="btn btn-primary btn-lg">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
          Join Now
        </a>
      <?php endif; ?>
      <a href="#live-status" class="btn btn-outline btn-lg">
        <span>🔴</span> Live Status
      </a>
    </div>
    <div class="hero-stats">
      <div class="hero-stat"><div class="hero-stat-num"><?= h($hero['stat1_num'] ?? '1') ?></div><div class="hero-stat-label"><?= h($hero['stat1_label'] ?? 'Courts') ?></div></div>
      <div class="hero-stat"><div class="hero-stat-num"><?= h($hero['stat2_num'] ?? '14H') ?></div><div class="hero-stat-label"><?= h($hero['stat2_label'] ?? 'Daily Play') ?></div></div>
      <div class="hero-stat"><div class="hero-stat-num"><?= h($hero['stat3_num'] ?? '100+') ?></div><div class="hero-stat-label"><?= h($hero['stat3_label'] ?? 'Members') ?></div></div>
    </div>
  </div>
  <div class="hero-scroll" aria-hidden="true"><div class="scroll-line"></div><span>Scroll</span></div>
</section>

<!-- ══ TICKER ══════════════════════════════════════════════ -->
<div class="ticker-wrap" aria-hidden="true">
  <div class="ticker-track">
    <?php
    $tItems = [
        $ticker['item1'] ?? 'Padol Pickleball Court',
        $ticker['item2'] ?? 'Polomolok · South Cotabato',
        h($location['hours'] ?? 'Open 10AM – Midnight'),
        $ticker['item3'] ?? 'Join Now · Limited Slots',
        $courtIsOpen ? '🟢 Court Open Now' : '🔴 Court Closed',
        $isOpenPlayTonight ? '🎮 Open Play Tonight 8PM' : '📅 Reservations Available',
        $ticker['item4'] ?? 'Tournament Registration Open',
        $ticker['item5'] ?? 'Professional Coaching Available',
    ];
    $tDouble = array_merge($tItems, $tItems);
    foreach ($tDouble as $item): ?>
      <span class="ticker-item"><?= $item ?></span>
    <?php endforeach; ?>
  </div>
</div>

<!-- ══ LIVE COURTS — Right Now ════════════════════════════════ -->
<section id="live-status" aria-label="Live court status">
  <div class="live-inner">
    <h2 class="section-title reveal">LIVE COURTS — Right Now</h2>
    <div id="courts-grid" class="courts-grid">
      <?php foreach ($allCourts as $court): ?>
        <div class="court-card reveal" data-court-id="<?= $court['id'] ?>">
          <div class="court-header">
            <div class="court-name">
              <?php
              $typeIcon = ['covered'=>'🏠','uncovered'=>'☀️','indoor'=>'🏢','outdoor'=>'🌿'][$court['court_type']] ?? '🏓';
              echo $typeIcon . ' ' . h($court['name']);
              ?>
            </div>
            <div class="court-type"><?= strtoupper(h($court['court_type'])) ?></div>
          </div>
          <hr class="court-divider">
          <div class="court-status">
            <?php
            $statusText = '';
            $statusClass = '';
            switch ($court['live_status']) {
              case 'available': $statusText = '● OPEN FOR PLAY'; $statusClass = 'available'; break;
              case 'active': $statusText = '🎮 GAME IN PROGRESS'; $statusClass = 'active'; break;
              case 'queuing': $statusText = '⏳ QUEUING'; $statusClass = 'queuing'; break;
              case 'maintenance': $statusText = '🔧 UNDER MAINTENANCE'; $statusClass = 'maintenance'; break;
              case 'closed': $statusText = '✗ CLOSED'; $statusClass = 'closed'; break;
              case 'reserved': $statusText = '📅 RESERVED'; $statusClass = 'reserved'; break;
              default: $statusText = '○ UNKNOWN'; $statusClass = 'unknown'; break;
            }
            ?>
            <div class="status-text <?= $statusClass ?>"><?= $statusText ?></div>
          </div>
          <div class="court-action">
            <a href="<?= APP_URL ?>/player/schedule.php?court=<?= $court['id'] ?>" class="btn-primary btn-sm">Book This Court →</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ══ ABOUT ══════════════════════════════════════════════ -->
<section id="about" class="section">
  <div class="section-inner">
    <div class="about-grid">
      <div class="about-visual reveal">
        <div class="about-court-card">
          <svg class="court-svg" viewBox="0 0 440 280" fill="none">
            <rect x="10" y="10" width="420" height="260" rx="4" fill="rgba(0,229,160,0.04)" stroke="#00e5a0" stroke-width="2.5"/>
            <line x1="10" y1="140" x2="430" y2="140" stroke="#00e5a0" stroke-width="2.5"/>
            <line x1="220" y1="10" x2="220" y2="270" stroke="#00e5a0" stroke-width="3.5"/>
            <rect x="110" y="10" width="110" height="130" fill="rgba(0,229,160,0.09)" stroke="#00e5a0" stroke-width="1.5"/>
            <rect x="110" y="140" width="110" height="130" fill="rgba(0,229,160,0.09)" stroke="#00e5a0" stroke-width="1.5"/>
            <rect x="220" y="10" width="110" height="130" fill="rgba(0,229,160,0.09)" stroke="#00e5a0" stroke-width="1.5"/>
            <rect x="220" y="140" width="110" height="130" fill="rgba(0,229,160,0.09)" stroke="#00e5a0" stroke-width="1.5"/>
            <text x="165" y="75" text-anchor="middle" fill="#00e5a0" font-size="8" font-family="monospace" opacity="0.65">KITCHEN</text>
            <text x="165" y="200" text-anchor="middle" fill="#00e5a0" font-size="8" font-family="monospace" opacity="0.65">KITCHEN</text>
            <text x="275" y="75" text-anchor="middle" fill="#00e5a0" font-size="8" font-family="monospace" opacity="0.65">KITCHEN</text>
            <text x="275" y="200" text-anchor="middle" fill="#00e5a0" font-size="8" font-family="monospace" opacity="0.65">KITCHEN</text>
            <circle cx="220" cy="140" r="6" stroke="#00e5a0" stroke-width="1.5" fill="none" opacity="0.4"/>
          </svg>
        </div>
        <div class="about-accent-card">
          <div class="num">2026</div>
          <div class="label">Est. Polomolok</div>
        </div>
      </div>
      <div class="reveal delay-15">
        <div class="section-label"><?= h($about['tagline'] ?? 'About Padol') ?></div>
        <h2 class="section-title"><?= h($about['heading'] ?? 'Where Champions Are Made') ?></h2>
        <p class="section-desc"><?= h($about['description'] ?? "Padol Pickleball Court is South Cotabato's premier pickleball destination. Professional-grade courts, smart credit-based booking, and a passionate community — in the heart of Polomolok.") ?></p>
        <p class="about-desc2"><?= h($about['description2'] ?? "Whether you're a beginner picking up your first paddle or a seasoned competitor, Padol is your court. Our QR-powered access system means less waiting, more playing.") ?></p>
        <div class="about-features">
          <div class="feature-chip"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Professional Courts</div>
          <div class="feature-chip"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg> Smart QR Access</div>
          <div class="feature-chip"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Active Community</div>
          <div class="feature-chip"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> <?= h($location['hours'] ?? '10 AM – Midnight') ?></div>
          <div class="feature-chip"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> GCash &amp; Maya</div>
          <div class="feature-chip"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> Ranked Leagues</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══ MEMBERSHIP ══════════════════════════════════════════ -->
<section id="membership" class="section">
  <div class="section-inner">
    <div class="membership-header reveal">
      <div class="section-label centered">Membership Plans</div>
      <h2 class="section-title">Choose Your Level</h2>
      <p class="section-desc">Credit-based pricing for every type of player. Load once, play anytime within your 8-hour access window.</p>
    </div>
    <?php if (count($plans) > 0): ?>
    <div class="membership-grid" id="membership-grid">
      <?php foreach ($plans as $i => $plan):
        $features = [];
        if (!empty($plan['features'])) {
          $j = json_decode($plan['features'], true);
          $features = is_array($j) ? $j : array_filter(array_map('trim', explode("\n", $plan['features'])));
        }
      ?>
      <div class="plan-card <?= $plan['is_featured'] ? 'featured' : '' ?> reveal js-delay delay-<?= $i * 10 ?>">
        <?php if ($plan['is_featured']): ?><div class="plan-badge">Most Popular</div><?php endif; ?>
        <div class="plan-name"><?= h($plan['name']) ?></div>
        <div class="plan-tagline"><?= h($plan['tagline']) ?></div>
        <div class="plan-price">
          <span class="currency">₱</span>
          <span class="amount"><?= number_format((int)$plan['price']) ?></span>
          <span class="period"><?= h($plan['period']) ?></span>
        </div>
        <?php if (!empty($plan['per_game'])): ?><div class="plan-per-game"><?= h($plan['per_game']) ?></div><?php endif; ?>
        <div class="plan-divider"></div>
        <?php if ($features): ?>
        <ul class="plan-features">
          <?php foreach ($features as $feat):
            $feat = trim($feat);
            $isExcluded = (strpos($feat,'✗')===0||strpos($feat,'✘')===0||strpos($feat,'×')===0);
            $featText = trim(preg_replace('/^[✓✔✗✘×\-]\s*/u','',$feat));
          ?>
          <li class="<?= $isExcluded ? 'muted' : '' ?>">
            <?php if ($isExcluded): ?>
              <svg fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            <?php else: ?>
              <svg fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            <?php endif; ?>
            <span><?= h($featText) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <div class="plan-btn-wrap">
          <a href="<?= !empty($isLoggedIn) ? $dashUrl : APP_URL.'/auth/register.php' ?>"
             class="btn <?= $plan['is_featured'] ? 'btn-primary' : ($i===count($plans)-1?'btn-outline':'btn-secondary') ?> btn-md">
            <?= $plan['is_featured'] ? 'Join '.h($plan['name']) : 'Get '.h($plan['name']) ?>
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="membership-grid">
      <?php foreach ([
        ['Starter','Casual players','150','/load','~₱150/session','btn-secondary',false,0],
        ['Pro','Regular competitive players','350','/load','~₱87/session','btn-primary',true,1],
        ['Elite','Serious players & coaches','600','/load','~₱60/session','btn-outline',false,2]
      ] as [$pname,$ptag,$pprice,$pper,$ppg,$pbtn,$pfeat,$pi]): ?>
      <div class="plan-card <?= $pfeat?'featured':'' ?> reveal js-delay delay-<?= $pi * 10 ?>">
        <?php if ($pfeat): ?><div class="plan-badge">Most Popular</div><?php endif; ?>
        <div class="plan-name"><?= $pname ?></div>
        <div class="plan-tagline"><?= $ptag ?></div>
        <div class="plan-price"><span class="currency">₱</span><span class="amount"><?= $pprice ?></span><span class="period"><?= $pper ?></span></div>
        <div class="plan-per-game"><?= $ppg ?></div>
        <div class="plan-divider"></div>
        <div class="plan-btn-wrap">
          <a href="auth/register.php" class="btn <?= $pbtn ?> btn-md">Get <?= $pname ?></a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ══ SCHEDULE ══════════════════════════════════════════ -->
<section id="schedule" class="section">
  <div class="section-inner">
    <div class="schedule-header reveal">
      <div>
        <div class="section-label">Court Schedule</div>
        <h2 class="section-title">Book Your Slot</h2>
        <p class="section-desc">Live availability — updates every 30 seconds.</p>
      </div>
      <div class="hours-card">
        <div class="hours-icon">
          <svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div>
          <div class="hours-time"><?= h($location['hours'] ?? '10AM — 12MN') ?></div>
          <div class="hours-days">Monday to Sunday · Daily</div>
        </div>
      </div>
    </div>
    <div id="slots-status-bar">
      <span id="slots-live-dot"></span>
      <span id="slots-updated-label">Loading live slots…</span>
    </div>
    <div id="live-slots-grid" class="time-slots reveal delay-10">
      <?php for ($i=0;$i<8;$i++): ?>
        <div class="time-slot available slot-skeleton">
          <div class="slot-time slot-skel-bar"></div>
          <div class="slot-status"><span class="slot-dot"></span></div>
        </div>
      <?php endfor; ?>
    </div>
    <div class="slot-legend">
      <span><span class="slot-legend-dot sl-available"></span>Available</span>
      <span><span class="slot-legend-dot sl-busy"></span>Filling Up</span>
      <span><span class="slot-legend-dot sl-reserved"></span>Reserved</span>
      <span><span class="slot-legend-dot sl-full"></span>Full</span>
      <span><span class="slot-legend-dot sl-past"></span>Past / Closed</span>
    </div>
    <div class="schedule-book-wrap reveal">
      <a href="auth/login.php" class="btn btn-primary btn-md">
        <svg fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" width="16" height="16"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        View Full Schedule &amp; Book
      </a>
    </div>
  </div>
</section>

<!-- Live Slot JS — uses CSS classes, not inline styles -->
<script nonce="<?= getCspNonce() ?>">
(function() {
  const POLL_MS = 30000;
  const API_URL = '<?= APP_URL ?>/api/slots.php';
  const grid    = document.getElementById('live-slots-grid');
  const label   = document.getElementById('slots-updated-label');
  const dot     = document.getElementById('slots-live-dot');
  let pollTimer = null;

  // Map status → CSS class + dot colour CSS variable
  function statusMeta(s, isCurrent) {
    if (isCurrent) return { cls:'available', dotVar:'--accent2', text:'On Court Now' };
    switch(s) {
      case 'full':      return { cls:'unavailable', dotVar:'--color-full',    text:'Full'        };
      case 'busy':      return { cls:'busy',        dotVar:'--color-busy',    text:'Almost Full' };
      case 'reserved':  return { cls:'busy',        dotVar:'--color-reserved',text:'Reserved 📅' };
      case 'partial':   return { cls:'available',   dotVar:'--color-partial', text:'Filling Up'  };
      case 'available': return { cls:'available',   dotVar:'--accent',        text:'Available'   };
      case 'past':      return { cls:'unavailable', dotVar:'--muted',         text:'Passed'      };
      default:          return { cls:'unavailable', dotVar:'--muted',         text:s             };
    }
  }

  function fmtTime(t) {
    const [h,m] = t.split(':').map(Number);
    return (h%12||12)+':'+ String(m).padStart(2,'0')+' '+(h>=12?'PM':'AM');
  }

  function renderSlots(slots) {
    if (!slots || slots.length === 0) {
      grid.innerHTML = '<div class="slot-empty-msg">No slots available today.</div>';
      return;
    }
    grid.innerHTML = slots.map(s => {
      const m = statusMeta(s.status, s.isCurrent);
      // Build pip dots using a span with data attributes — no inline color
      const pipClass = s.isCurrent ? 'slot-pip active-pip' : (s.status === 'past' ? '' : 'slot-pip');
      const pips = s.status !== 'past'
        ? '<div class="slot-pips">' +
            Array.from({length: s.max}, (_,i) =>
              `<span class="slot-pip${i < s.booked ? ' filled' : ''}" data-status="${s.status}${s.isCurrent?' current':''}"></span>`
            ).join('') +
            `<span class="slot-pip-count">${s.booked}/${s.max}</span>` +
          '</div>'
        : '';
      return `<div class="time-slot ${m.cls}${s.isCurrent ? ' is-current' : ''}" data-slot-status="${s.status}">
        ${s.isCurrent ? '<span class="slot-live-indicator"></span>' : ''}
        <div class="slot-time">${fmtTime(s.start)}</div>
        <div class="slot-status"><span class="slot-dot" data-status-dot="${s.status}${s.isCurrent?' current':''}"></span>${m.text}</div>
        ${pips}
      </div>`;
    }).join('');
  }

  function fetchSlots() {
    dot.dataset.state = 'loading';
    fetch(API_URL + '?_=' + Date.now())
      .then(r => r.json())
      .then(data => {
        renderSlots(data.slots || []);
        dot.dataset.state = 'ok';
        label.textContent = 'Updated at ' + (data.updatedAt || new Date().toLocaleTimeString());
      })
      .catch(() => {
        dot.dataset.state = 'error';
        label.textContent = 'Could not load live data — retrying…';
      });
  }

  fetchSlots();
  pollTimer = setInterval(fetchSlots, POLL_MS);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { clearInterval(pollTimer); }
    else { fetchSlots(); pollTimer = setInterval(fetchSlots, POLL_MS); }
  });
})();
</script>

<!-- ══ EVENTS ══════════════════════════════════════════════ -->
<section id="events" class="section">
  <div class="section-inner">
    <div class="events-header reveal">
      <div class="section-label">Upcoming Events</div>
      <h2 class="section-title">Compete &amp; Connect</h2>
      <p class="section-desc">From weekly ladder leagues to grand slam tournaments — there's always something happening at Padol.</p>
    </div>
    <?php if ($featuredEvent || count($sideEvents) > 0): ?>
    <div class="events-grid reveal delay-10">
      <?php if ($featuredEvent): ?>
      <div class="event-card-featured">
        <div class="event-date-badge">
          <span class="day"><?= date('d',strtotime($featuredEvent['event_date'])) ?></span>
          <span class="month"><?= date('M',strtotime($featuredEvent['event_date'])) ?></span>
        </div>
        <div class="event-tag"><?= h($featuredEvent['tag']) ?></div>
        <h3 class="event-title"><?= h($featuredEvent['title']) ?></h3>
        <p class="event-desc"><?= h($featuredEvent['description']) ?></p>
        <div class="event-meta">
          <?php if (!empty($featuredEvent['time_info'])): ?>
            <div class="event-meta-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="15" height="15"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?= h($featuredEvent['time_info']) ?></div>
          <?php endif; ?>
          <?php if (!empty($featuredEvent['slots_info'])): ?>
            <div class="event-meta-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="15" height="15"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg><?= h($featuredEvent['slots_info']) ?></div>
          <?php endif; ?>
          <?php if (!empty($featuredEvent['price_info'])): ?>
            <div class="event-meta-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="15" height="15"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg><?= h($featuredEvent['price_info']) ?></div>
          <?php endif; ?>
        </div>
        <a href="auth/register.php" class="btn btn-primary btn-md">Register Now</a>
      </div>
      <?php endif; ?>
      <?php if (count($sideEvents) > 0): ?>
      <div class="events-list">
        <?php foreach (array_slice($sideEvents, 0, 4) as $ev): ?>
        <div class="event-card-small">
          <div class="event-mini-date">
            <div class="day"><?= date('d',strtotime($ev['event_date'])) ?></div>
            <div class="month"><?= date('M',strtotime($ev['event_date'])) ?></div>
          </div>
          <div>
            <div class="event-small-title"><?= h($ev['title']) ?></div>
            <div class="event-small-type"><?= h($ev['tag']) ?><?= !empty($ev['time_info'])?' · '.h($ev['time_info']):'' ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>
      <p class="section-desc">No upcoming events at the moment. Check back soon!</p>
    <?php endif; ?>
  </div>
</section>

<!-- ══ TOURNAMENTS ══════════════════════════════════════════ -->
<section id="tournaments" class="section">
  <div class="section-inner">
    <div class="tournaments-header reveal">
      <div class="section-label">Tournament Center</div>
      <h2 class="section-title">Upcoming Tournaments</h2>
      <p class="section-desc">Join competitive tournaments and climb the leaderboard. From local leagues to championship events.</p>
    </div>

    <?php
    // Get upcoming tournaments: guard this section so the page still renders if the feature schema is missing
    $upcomingTournaments = [];
    try {
        $upcomingTournaments = $db->query("
          SELECT t.*,
                 COUNT(tp.player_id) as registered_players,
                 CASE
                   WHEN t.status = 'registration' THEN 'Registration Open'
                   WHEN t.status = 'active' THEN 'In Progress'
                   WHEN t.status = 'completed' THEN 'Completed'
                   ELSE 'Coming Soon'
                 END as status_text
          FROM falcon.tournaments t
          LEFT JOIN falcon.tournament_players tp ON t.id = tp.tournament_id
          WHERE t.start_date >= CURRENT_DATE
             AND t.status IN ('registration', 'active')
          GROUP BY t.id
          ORDER BY t.start_date ASC
          LIMIT 6
        ")->fetchAll();
    } catch (Throwable $ex) {
        error_log('Index tournament section skipped: ' . $ex->getMessage());
        $upcomingTournaments = [];
    }

    if (count($upcomingTournaments) > 0):
    ?>
    <div class="tournaments-grid reveal delay-10">
      <?php foreach ($upcomingTournaments as $tournament): ?>
      <div class="tournament-card">
        <div class="tournament-header">
          <div class="tournament-date-badge">
            <span class="day"><?= date('d', strtotime($tournament['start_date'])) ?></span>
            <span class="month"><?= date('M', strtotime($tournament['start_date'])) ?></span>
          </div>
          <div class="tournament-status <?= strtolower(str_replace(' ', '-', $tournament['status'])) ?>">
            <?= h($tournament['status_text']) ?>
          </div>
        </div>
        <div class="tournament-body">
          <h3 class="tournament-title"><?= h($tournament['name']) ?></h3>
          <p class="tournament-desc"><?= h($tournament['description'] ?: 'Join this exciting tournament and compete for prizes!') ?></p>
          <div class="tournament-meta">
            <div class="tournament-meta-item">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
              </svg>
              <?= h($tournament['registered_players']) ?>/<?= h($tournament['max_players']) ?> Players
            </div>
            <div class="tournament-meta-item">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
              </svg>
              <?= h(ucfirst(str_replace('_', ' ', $tournament['bracket_type']))) ?>
            </div>
          </div>
          <a href="tournaments.php" class="btn btn-primary btn-sm">
            <?= $tournament['status'] === 'registration' ? 'Register Now' : 'View Details' ?> →
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="tournaments-empty reveal delay-10">
      <div class="tournaments-empty-icon">🏆</div>
      <h3>No Upcoming Tournaments</h3>
      <p>Check back soon for new tournament announcements!</p>
      <a href="tournaments.php" class="btn btn-outline btn-md">View All Tournaments</a>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ══ ACTIVITIES ══════════════════════════════════════════ -->
<section id="activities" class="section">
  <div class="section-inner">
    <div class="activities-header reveal">
      <div class="section-label centered">Activity Center</div>
      <h2 class="section-title"><?= h($actSection['title'] ?? 'More Than Just Pickleball') ?></h2>
      <p class="section-desc"><?= h($actSection['subtitle'] ?? 'We offer a range of activities beyond the court. From billiards to event hosting — Padol is your all-in-one venue.') ?></p>
    </div>

    <?php
    $actList = [];
    if ($activities) {
        foreach ($activities as $act) {
            $actList[] = [
                'icon'  => $act['icon'] ?: '🎯',
                'name'  => $act['name'],
                'desc'  => $act['description'],
                'price' => $act['price_per_hour'] ? '₱'.number_format($act['price_per_hour'],0).' / hour'
                         : ($act['flat_price'] ? '₱'.number_format($act['flat_price'],0).' flat rate'
                         : ($act['pricing_note'] ?: 'Contact us for pricing')),
                'photo' => $act['photo'],
            ];
        }
    } else {
        $actList = [
            ['icon'=>'🎱','name'=>'Billiards','desc'=>'Pool table rental for casual or competitive play.','price'=>'Contact for pricing','photo'=>null],
            ['icon'=>'🍳','name'=>'Kitchen Use','desc'=>'Full kitchen facility for events or cooking sessions.','price'=>'Contact for pricing','photo'=>null],
            ['icon'=>'🎯','name'=>'Darts','desc'=>'Dart boards available for casual or competitive play.','price'=>'Contact for pricing','photo'=>null],
            ['icon'=>'🎊','name'=>'Events &amp; Venue','desc'=>'Full venue rental for weddings, birthdays &amp; corporate.','price'=>'Contact for pricing','photo'=>null],
        ];
    }
    ?>

    <!-- Desktop grid -->
    <div class="activities-grid">
      <?php foreach ($actList as $i => $act): ?>
      <div class="activity-card reveal js-delay delay-<?= $i * 8 ?>">
        <div class="activity-card-img">
          <?php if (!empty($act['photo'])): ?>
            <img src="<?= APP_URL ?>/uploads/activity_photos/<?= urlencode($act['photo']) ?>" alt="<?= h($act['name']) ?>" loading="lazy">
          <?php else: ?>
            <div class="activity-emoji"><?= $act['icon'] ?></div>
          <?php endif; ?>
        </div>
        <div class="activity-card-body">
          <div class="activity-name"><?= $act['icon'] ?> <?= h($act['name']) ?></div>
          <?php if ($act['desc']): ?><div class="activity-desc"><?= h($act['desc']) ?></div><?php endif; ?>
          <div class="activity-price"><?= $act['price'] ?></div>
          <div class="activity-card-footer">
            <span class="activity-badge">📋 Bookable</span>
            <a href="auth/register.php" class="btn btn-outline btn-sm">Book Now →</a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Mobile accordion -->
    <div class="activities-accordion">
      <?php foreach ($actList as $i => $act): ?>
      <div class="activities-accordion-item">
        <div class="activities-accordion-header">
          <div class="activities-accordion-emoji"><?= $act['icon'] ?></div>
          <div class="activities-accordion-text">
            <div class="activities-accordion-title"><?= h($act['name']) ?></div>
            <div class="activities-accordion-price"><?= $act['price'] ?></div>
          </div>
          <div class="activities-accordion-chevron">
            <svg fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
        </div>
        <div class="activities-accordion-body">
          <div class="activities-accordion-body-inner">
            <?php if (!empty($act['photo'])): ?>
              <div class="activities-accordion-img"><img src="<?= APP_URL ?>/uploads/activity_photos/<?= urlencode($act['photo']) ?>" alt="<?= h($act['name']) ?>" loading="lazy"></div>
            <?php else: ?>
              <div class="activities-accordion-img"><?= $act['icon'] ?></div>
            <?php endif; ?>
            <?php if ($act['desc']): ?><div class="activities-accordion-desc"><?= h($act['desc']) ?></div><?php endif; ?>
            <a href="auth/register.php" class="btn btn-outline btn-sm act-acc-btn">Book Now →</a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="activities-cta-wrap reveal">
      <button class="btn btn-outline btn-md" id="open-activities-modal">🎯 View All Activities</button>
    </div>
  </div>
</section>

<!-- ══ TRAINING ══════════════════════════════════════════ -->
<?php if ($training):
  $iconColors = ['green'=>'icon-green','blue'=>'icon-blue','orange'=>'icon-orange'];
  $iconSvgs   = [
    'green'  => '<svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M2 20h.01M7 20v-4M12 20v-8M17 20V8M22 4v16"/></svg>',
    'blue'   => '<svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>',
    'orange' => '<svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
  ];
?>
<section id="training" class="section">
  <div class="section-inner">
    <div class="training-header reveal">
      <div class="section-label centered">Training Programs</div>
      <h2 class="section-title">Level Up Your Game</h2>
      <p class="section-desc">Structured coaching from certified pickleball instructors for every skill level.</p>
    </div>

    <!-- Desktop grid -->
    <div class="training-grid">
      <?php foreach ($training as $i => $tr):
        $color = $tr['color'] ?? 'green';
      ?>
      <div class="training-card reveal js-delay delay-<?= $i * 10 ?>">
        <div class="training-icon <?= $iconColors[$color] ?? 'icon-green' ?>"><?= $iconSvgs[$color] ?? $iconSvgs['green'] ?></div>
        <div class="training-title"><?= h($tr['title']) ?></div>
        <div class="training-desc"><?= h($tr['description']) ?></div>
        <div class="training-price"><?= h($tr['price_label']) ?></div>
        <a href="auth/register.php" class="btn btn-outline btn-sm training-enroll-btn">Enroll Now →</a>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Mobile accordion -->
    <div class="training-accordion">
      <?php foreach ($training as $i => $tr):
        $color = $tr['color'] ?? 'green';
        $bgMap  = ['green'=>'rgba(0,229,160,.1)','blue'=>'rgba(0,184,255,.1)','orange'=>'rgba(255,107,53,.1)'];
        $svgMap = ['green'=>'var(--accent)','blue'=>'var(--accent2)','orange'=>'var(--accent3)'];
        $paths  = [
          'green'  => '<path d="M2 20h.01M7 20v-4M12 20v-8M17 20V8M22 4v16"/>',
          'blue'   => '<circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/>',
          'orange' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        ];
        $bg  = $bgMap[$color]  ?? $bgMap['green'];
        $svg = $svgMap[$color] ?? $svgMap['green'];
      ?>
      <div class="training-accordion-item">
        <div class="training-accordion-header">
          <div class="training-accordion-icon-wrap" data-color-theme="<?= h($color) ?>">
            <svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?= $paths[$color] ?? $paths['green'] ?></svg>
          </div>
          <div class="training-accordion-text-group">
            <div class="training-accordion-title"><?= h($tr['title']) ?></div>
            <?php if (!empty($tr['price_label'])): ?>
              <div class="training-accordion-price"><?= h($tr['price_label']) ?></div>
            <?php endif; ?>
          </div>
          <div class="training-accordion-chevron">
            <svg fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
        </div>
        <div class="training-accordion-body">
          <div class="training-accordion-body-inner">
            <div class="training-accordion-desc"><?= h($tr['description']) ?></div>
            <a href="auth/register.php" class="btn btn-outline btn-sm training-enroll-full-btn">Enroll Now →</a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ══ SHOP ══════════════════════════════════════════════ -->
<?php if ($shop): ?>
<section id="shop" class="section">
  <div class="section-inner">
    <div class="shop-header reveal">
      <div>
        <div class="section-label">Padol Shop</div>
        <h2 class="section-title">Gear Up</h2>
      </div>
      <a href="#" class="btn btn-ghost btn-sm">View All →</a>
    </div>
    <div class="shop-grid">
      <?php foreach ($shop as $i => $item): ?>
      <div class="shop-card reveal js-delay delay-<?= $i * 7 ?>">
        <div class="shop-img">
          <?php if (!empty($item['image_path'])): ?>
            <img src="<?= APP_URL ?>/uploads/shop/<?= urlencode($item['image_path']) ?>" alt="<?= h($item['name']) ?>" loading="lazy">
          <?php else: ?>
            <svg viewBox="0 0 100 100" fill="none"><rect x="25" y="20" width="50" height="60" rx="6" stroke="#00e5a0" stroke-width="3" fill="rgba(0,229,160,.05)"/></svg>
          <?php endif; ?>
          <?php if (!empty($item['badge'])): ?><div class="shop-badge-new"><?= h($item['badge']) ?></div><?php endif; ?>
        </div>
        <div class="shop-info">
          <div class="shop-cat"><?= h($item['category']) ?></div>
          <div class="shop-name"><?= h($item['name']) ?></div>
          <div class="shop-price-row">
            <div class="shop-price">₱<?= number_format((float)$item['price'],2) ?></div>
            <a href="auth/register.php" class="shop-add" title="Order now">
              <svg fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ══ LOCATION ══════════════════════════════════════════ -->
<section id="location" class="section">
  <div class="section-inner">
    <div class="location-header reveal">
      <div class="section-label">Find Us</div>
      <h2 class="section-title">Our Location</h2>
      <p class="section-desc">Right in the heart of Polomolok — easy to find, impossible to miss.</p>
    </div>
    <div class="location-grid">
      <div class="location-info">
        <div class="info-card reveal">
          <div class="info-icon"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></div>
          <div><div class="info-label">Address</div><div class="info-value"><?= nl2br(h($location['address'] ?? "Purok Sagrado Valencia Site\nPolomolok, 9504 South Cotabato")) ?></div></div>
        </div>
        <div class="info-card reveal delay-8">
          <div class="info-icon"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
          <div>
            <div class="info-label">Operating Hours</div>
            <div class="info-value"><?= h($location['hours'] ?? '10:00 AM – 12:00 Midnight') ?><br><span class="accent-text">Open Daily · 7 Days a Week</span></div>
          </div>
        </div>
        <div class="info-card reveal delay-16">
          <div class="info-icon"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.36 12 19.79 19.79 0 0 1 1.12 3.38 2 2 0 0 1 3.08 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.09 8.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21 16z"/></svg></div>
          <div><div class="info-label">Contact</div><div class="info-value">📱 <?= h($location['phone'] ?? '+63 912 345 6789') ?><br>📧 <?= h($location['email'] ?? 'falconpickleball@gmail.com') ?></div></div>
        </div>
        <div class="reveal delay-32">
          <a href="<?= h($location['maps_url'] ?? 'https://maps.google.com/?q=6.2167,125.0750') ?>" target="_blank" rel="noopener" class="btn btn-primary btn-md">
            <svg fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" width="16" height="16"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
            Get Directions
          </a>
        </div>
      </div>
      <div class="reveal delay-10">
        <div class="map-container">
          <div class="map-overlay-tag"><span class="map-dot"></span>Padol Pickleball Court</div>
          <div id="leaflet-map"></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══ SOCIAL ══════════════════════════════════════════════ -->
<div id="social-section">
  <div class="social-title reveal">Follow The Flock</div>
  <p class="social-sub reveal">Stay updated with scores, events, and behind-the-scenes court action.</p>
  <div class="social-icons reveal">
    <a href="<?= h($social['facebook'] ?? '#') ?>" target="_blank" rel="noopener" class="social-btn fb">
      <svg fill="currentColor" viewBox="0 0 24 24"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>Facebook
    </a>
    <a href="<?= h($social['instagram'] ?? '#') ?>" target="_blank" rel="noopener" class="social-btn ig">
      <svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>Instagram
    </a>
    <a href="<?= h($social['tiktok'] ?? '#') ?>" target="_blank" rel="noopener" class="social-btn tt">
      <svg fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.18 8.18 0 0 0 4.77 1.52V6.78a4.85 4.85 0 0 1-1-.09z"/></svg>TikTok
    </a>
  </div>
</div>

<!-- ══ FOOTER ══════════════════════════════════════════════ -->
<footer>
  <div class="footer-grid">
    <div>
      <div class="footer-brand-logo">
        <div class="footer-logo-icon">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="44" height="44">
            <rect x="44" y="63" width="13" height="30" rx="6.5" fill="#00e5a0"/>
            <rect x="20" y="8" width="58" height="60" rx="29" fill="#00e5a0"/>
            <circle cx="36" cy="22" r="3.8" fill="#003d2a" opacity="0.32"/><circle cx="50" cy="22" r="3.8" fill="#003d2a" opacity="0.32"/>
            <circle cx="64" cy="22" r="3.8" fill="#003d2a" opacity="0.32"/><circle cx="43" cy="33" r="3.8" fill="#003d2a" opacity="0.32"/>
            <circle cx="57" cy="33" r="3.8" fill="#003d2a" opacity="0.32"/><circle cx="36" cy="44" r="3.8" fill="#003d2a" opacity="0.32"/>
            <circle cx="50" cy="44" r="3.8" fill="#003d2a" opacity="0.32"/><circle cx="64" cy="44" r="3.8" fill="#003d2a" opacity="0.32"/>
            <circle cx="43" cy="55" r="3.8" fill="#003d2a" opacity="0.32"/><circle cx="57" cy="55" r="3.8" fill="#003d2a" opacity="0.32"/>
            <circle cx="80" cy="18" r="14" fill="#f5e642" stroke="#00e5a0" stroke-width="2.5"/>
          </svg>
        </div>
        <div class="footer-brand-name">PADOL<span>.</span></div>
      </div>
      <p class="footer-desc"><?= h($footerCont['tagline'] ?? 'Premier pickleball destination in Polomolok, South Cotabato. Play smarter, compete harder, and join the Padol community today.') ?></p>
      <div class="footer-social">
        <a href="<?= h($social['facebook']??'#') ?>" target="_blank" rel="noopener" title="Facebook"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></a>
        <a href="<?= h($social['instagram']??'#') ?>" target="_blank" rel="noopener" title="Instagram"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></a>
        <a href="<?= h($social['tiktok']??'#') ?>" target="_blank" rel="noopener" title="TikTok"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.18 8.18 0 0 0 4.77 1.52V6.78a4.85 4.85 0 0 1-1-.09z"/></svg></a>
      </div>
    </div>
    <div>
      <div class="footer-col-title">Quick Links</div>
      <ul class="footer-links">
        <li><a href="#about">About Padol</a></li>
        <li><a href="#membership">Membership</a></li>
        <li><a href="#schedule">Court Schedule</a></li>
        <li><a href="#activities">Activities</a></li>
        <li><a href="#events">Events</a></li>
        <li><a href="#training">Training</a></li>
        <li><a href="#shop">Shop</a></li>
      </ul>
    </div>
    <div>
      <div class="footer-col-title">Members</div>
      <ul class="footer-links">
        <li><a href="auth/login.php">Player Login</a></li>
        <li><a href="auth/register.php">Join Now</a></li>
        <li><a href="player/dashboard.php">My Dashboard</a></li>
        <li><a href="player/schedule.php">Reserve a Court</a></li>
        <li><a href="#">Wallet &amp; Credits</a></li>
        <li><a href="#">Game History</a></li>
        <li><a href="#">QR Pass</a></li>
      </ul>
    </div>
    <div>
      <div class="footer-col-title">Contact Us</div>
      <div class="footer-contact-item"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg><span><?= h($location['address'] ?? 'Purok Sagrado Valencia Site, Polomolok, 9504 South Cotabato') ?></span></div>
      <div class="footer-contact-item"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.36 12 19.79 19.79 0 0 1 1.12 3.38 2 2 0 0 1 3.08 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.09 8.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21 16z"/></svg><span><?= h($location['phone'] ?? '+63 912 345 6789') ?></span></div>
      <div class="footer-contact-item"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg><span><?= h($location['email'] ?? 'falconpickleball@gmail.com') ?></span></div>
      <div class="footer-contact-item"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><span>Mon–Sun · <?= h($location['hours'] ?? '10:00 AM – 12:00 MN') ?></span></div>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="footer-copy">
      © <?= date('Y') ?> <span>Padol Pickleball Court</span>. All rights reserved. Polomolok, South Cotabato.<br>
      <span class="dev-credit">Developed by <span>Engr. Randall James Oculam</span></span>
    </div>
    <div class="footer-legal">
      <a href="<?= h($footerCont['privacy_url']??'#') ?>">Privacy Policy</a>
      <a href="<?= h($footerCont['terms_url']  ??'#') ?>">Terms of Use</a>
      <a href="<?= h($footerCont['refund_url'] ??'#') ?>">Refund Policy</a>
    </div>
  </div>
</footer>

</div><!-- /main-content -->

<!-- FAB -->
<div class="fab" id="fab">
  <button class="fab-main" id="fab-top" aria-label="Back to top">
    <svg fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" width="22" height="22"><polyline points="18 15 12 9 6 15"/></svg>
  </button>
</div>

<!-- ══ ACTIVITIES MODAL ══════════════════════════════════ -->
<div id="activities-modal" class="act-modal-overlay" role="dialog" aria-modal="true" aria-label="All Activities">
  <div class="act-modal-box">
    <div class="act-modal-header">
      <div class="act-modal-title-group">
        <div class="section-label">Activity Center</div>
        <h2>All Activities</h2>
        <p><?= h($actSection['subtitle'] ?? 'From billiards to event hosting — Padol is your all-in-one venue.') ?></p>
      </div>
      <button class="act-modal-close" id="close-activities-modal" aria-label="Close">✕</button>
    </div>

    <div class="act-modal-grid">
      <?php foreach ($actList as $act): ?>
      <div class="act-modal-card">
        <div class="act-modal-card-img">
          <?php if (!empty($act['photo'])): ?>
            <img src="<?= APP_URL ?>/uploads/activity_photos/<?= urlencode($act['photo']) ?>" alt="<?= h($act['name']) ?>" loading="lazy">
          <?php else: ?>
            <div class="act-modal-card-emoji"><?= $act['icon'] ?></div>
          <?php endif; ?>
        </div>
        <div class="act-modal-card-body">
          <div class="act-modal-card-name"><?= $act['icon'] ?> <?= h($act['name']) ?></div>
          <?php if (!empty($act['desc'])): ?>
            <div class="act-modal-card-desc"><?= h($act['desc']) ?></div>
          <?php endif; ?>
          <div class="act-modal-card-price"><?= $act['price'] ?></div>
          <a href="auth/register.php" class="btn btn-outline btn-sm act-modal-card-btn">Book Now →</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="act-modal-footer">
      <a href="auth/register.php" class="btn btn-primary btn-md">Join Padol · Access All Activities</a>
      <p class="act-modal-footer-note">Members get priority booking and exclusive rates.</p>
    </div>
  </div>
</div>

<!-- ══ SUPPLEMENTAL CSS for dynamic JS-rendered elements ══ -->
<style nonce="<?= getCspNonce() ?>">
/* Slot pip dots — colours via data attributes, no inline styles */
.slot-pip {
  display: inline-block; width: 6px; height: 6px;
  border-radius: 50%; margin: 0 1px;
  background: var(--surface2); transition: background .3s;
  vertical-align: middle;
}
.slot-pip.filled[data-status="available"]  { background: var(--accent); }
.slot-pip.filled[data-status="partial"]    { background: #f59e0b; }
.slot-pip.filled[data-status="busy"]       { background: #f59e0b; }
.slot-pip.filled[data-status="reserved"]   { background: #00b8ff; }
.slot-pip.filled[data-status="full"]       { background: #ef4444; }
.slot-pip.filled[data-status="current"]    { background: var(--accent2); }
.slot-pip-count {
  font-size: 10px; color: var(--muted); margin-left: 5px;
  vertical-align: middle; font-family: var(--font-m);
}
.slot-pips { margin-top: 5px; }
.slot-live-indicator {
  position: absolute; top: 6px; right: 8px;
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--accent2); animation: pulse 2s infinite;
}
.time-slot.is-current { box-shadow: 0 0 0 2px var(--accent2); border-color: var(--accent2); }
.slot-empty-msg { color: var(--muted); font-size: 14px; padding: 20px 0; }
.slot-skel-bar { background: var(--surface2); border-radius: 4px; height: 16px; width: 70%; margin-bottom: 8px; }

/* Slot-dot colours via data attribute */
[data-status-dot="available"]         { background: var(--accent); }
[data-status-dot="partial"]           { background: #f59e0b; }
[data-status-dot="busy"]              { background: #f59e0b; }
[data-status-dot="reserved"]          { background: #00b8ff; }
[data-status-dot="full"]              { background: #ef4444; }
[data-status-dot="past"]              { background: var(--muted); }
[data-status-dot="current"]           { background: var(--accent2); }
[data-status-dot="available current"] { background: var(--accent2); }

/* Live dot states */
#slots-live-dot[data-state="loading"] { background: #f59e0b; }
#slots-live-dot[data-state="ok"]      { background: var(--accent); }
#slots-live-dot[data-state="error"]   { background: #ef4444; }

/* Slot playing label */
.slot-playing-label { color: var(--accent); font-size: 12px; font-weight: 600; }

/* Training accordion icon/svg — background set via JS from data-bg */
.training-accordion-icon-wrap.theme-green  { background: rgba(0,229,160,.1); }
.training-accordion-icon-wrap.theme-green svg { color: var(--accent); }
.training-accordion-icon-wrap.theme-blue   { background: rgba(0,184,255,.1); }
.training-accordion-icon-wrap.theme-blue svg  { color: var(--accent2); }
.training-accordion-icon-wrap.theme-orange { background: rgba(255,107,53,.1); }
.training-accordion-icon-wrap.theme-orange svg { color: var(--accent3); }

/* Accordion text groups */
.activities-accordion-text,
.training-accordion-text-group { flex: 1; min-width: 0; }
.training-enroll-btn,
.training-enroll-full-btn { margin-top: 18px; }
.act-acc-btn { width: 100%; justify-content: center; }
</style>

<!-- ══ SCRIPTS ══════════════════════════════════════════════ -->
<script nonce="<?= getCspNonce() ?>">
// ── Splash ────────────────────────────────────────────────────
window.addEventListener('load', () => {
  setTimeout(() => {
    document.getElementById('splash').classList.add('hide');
    setTimeout(() => { document.getElementById('splash').style.display = 'none'; }, 1400);
  }, 2200);
});

// ── FAB back-to-top ───────────────────────────────────────────
document.getElementById('fab-top').addEventListener('click', () => {
  window.scrollTo({ top: 0, behavior: 'smooth' });
});

// ── Navbar scroll ─────────────────────────────────────────────
const navbar = document.getElementById('navbar');
window.addEventListener('scroll', () => {
  navbar.classList.toggle('scrolled', window.scrollY > 30);
  document.getElementById('fab').classList.toggle('visible', window.scrollY > 500);
}, { passive: true });

// ── Hamburger ─────────────────────────────────────────────────
const hamburger = document.getElementById('hamburger');
const mobileNav  = document.getElementById('mobile-nav');
function closeMobileNav() {
  hamburger.classList.remove('open');
  mobileNav.classList.remove('open');
  hamburger.setAttribute('aria-expanded', 'false');
  mobileNav.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
}
hamburger.addEventListener('click', () => {
  const isOpen = hamburger.classList.toggle('open');
  mobileNav.classList.toggle('open', isOpen);
  hamburger.setAttribute('aria-expanded', String(isOpen));
  mobileNav.setAttribute('aria-hidden', String(!isOpen));
  document.body.style.overflow = isOpen ? 'hidden' : '';
});
document.querySelectorAll('.mobile-link').forEach(l => l.addEventListener('click', closeMobileNav));
document.addEventListener('click', e => {
  if (mobileNav.classList.contains('open') && !mobileNav.contains(e.target) && !hamburger.contains(e.target)) closeMobileNav();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeMobileNav(); closeActivitiesModal(); } });

// ── Scroll reveal ─────────────────────────────────
const revealObserver = new IntersectionObserver(entries => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      entry.target.classList.add('revealed');
      revealObserver.unobserve(entry.target);
    }
  });
}, { threshold: 0.07, rootMargin: '0px 0px -40px 0px' });
document.querySelectorAll('.reveal').forEach(el => revealObserver.observe(el));

// ── Active nav link ───────────────────────────────────────────
const sections = document.querySelectorAll('section[id],#social-section');
const navLinks  = document.querySelectorAll('.nav-links a');
window.addEventListener('scroll', () => {
  let current = '';
  sections.forEach(s => { if (window.scrollY >= s.offsetTop - 120) current = s.id; });
  navLinks.forEach(a => {
    a.classList.remove('active');
    if (a.getAttribute('href') === '#' + current) a.classList.add('active');
  });
}, { passive: true });

// ── Gallery Carousel ──────────────────────────────────────────
(function() {
  const track = document.getElementById('gallery-track');
  if (!track) return;
  const slides = track.querySelectorAll('.gallery-slide');
  const total  = slides.length;
  if (total <= 1) return;

  const dots    = document.querySelectorAll('.gallery-dot');
  const prevBtn = document.getElementById('gallery-prev');
  const nextBtn = document.getElementById('gallery-next');
  let current   = 0, autoTimer = null, isDragging = false, dragStartX = 0;

  function goTo(idx) {
    idx = ((idx % total) + total) % total;
    slides[current].classList.remove('active');
    if (dots[current]) dots[current].classList.remove('active');
    slides[idx].classList.add('active');
    track.style.transform = 'translateX(-' + (idx * 100) + '%)';
    if (dots[idx]) dots[idx].classList.add('active');
    current = idx;
  }
  function startAuto() { clearInterval(autoTimer); autoTimer = setInterval(() => goTo(current + 1), 5000); }
  if (prevBtn) prevBtn.addEventListener('click', () => { goTo(current - 1); startAuto(); });
  if (nextBtn) nextBtn.addEventListener('click', () => { goTo(current + 1); startAuto(); });
  dots.forEach((dot, i) => dot.addEventListener('click', () => { goTo(i); startAuto(); }));

  document.addEventListener('keydown', e => {
    const carousel = document.getElementById('gallery-carousel');
    if (!carousel) return;
    const rect = carousel.getBoundingClientRect();
    if (rect.top < window.innerHeight && rect.bottom > 0) {
      if (e.key === 'ArrowLeft')  { goTo(current - 1); startAuto(); }
      if (e.key === 'ArrowRight') { goTo(current + 1); startAuto(); }
    }
  });

  const outer = document.getElementById('gallery-carousel');
  if (outer) {
    outer.addEventListener('touchstart', e => { isDragging = true; dragStartX = e.touches[0].clientX; }, { passive: true });
    outer.addEventListener('touchend', e => {
      if (!isDragging) return;
      const diff = dragStartX - e.changedTouches[0].clientX;
      if (Math.abs(diff) > 50) { goTo(current + (diff > 0 ? 1 : -1)); startAuto(); }
      isDragging = false;
    }, { passive: true });
  }
  track.addEventListener('mouseenter', () => clearInterval(autoTimer));
  track.addEventListener('mouseleave', startAuto);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) clearInterval(autoTimer); else startAuto();
  });
  startAuto();
})();

// ── Training Accordion ────────────────────────────────────────
// Apply background colours from data attributes (set by PHP) — CSS custom property, not style attr
document.querySelectorAll('.training-accordion-icon-wrap').forEach(wrap => {
  const color = wrap.dataset.colorTheme;
  if (color) wrap.classList.add('theme-' + color);
});
document.querySelectorAll('.training-accordion-header').forEach(header => {
  header.addEventListener('click', () => {
    const item    = header.closest('.training-accordion-item');
    const wasOpen = item.classList.contains('open');
    document.querySelectorAll('.training-accordion-item.open').forEach(el => el.classList.remove('open'));
    if (!wasOpen) item.classList.add('open');
  });
});

// ── Activities Accordion ──────────────────────────────────────
document.querySelectorAll('.activities-accordion-header').forEach(header => {
  header.addEventListener('click', () => {
    const item    = header.closest('.activities-accordion-item');
    const wasOpen = item.classList.contains('open');
    document.querySelectorAll('.activities-accordion-item.open').forEach(el => el.classList.remove('open'));
    if (!wasOpen) item.classList.add('open');
  });
});

// ── Activities Modal ──────────────────────────────────────────
const modal   = document.getElementById('activities-modal');
const openBtn = document.getElementById('open-activities-modal');
const closeBtn= document.getElementById('close-activities-modal');

function openActivitiesModal() {
  modal.classList.add('open');
  document.body.style.overflow = 'hidden';
  modal.scrollTop = 0;
}
function closeActivitiesModal() {
  modal.classList.remove('open');
  document.body.style.overflow = '';
}

if (openBtn)  openBtn.addEventListener('click',  openActivitiesModal);
if (closeBtn) closeBtn.addEventListener('click', closeActivitiesModal);
modal.addEventListener('click', e => { if (e.target === modal) closeActivitiesModal(); });

// ── Leaflet Map ───────────────────────────────────────────────
window.addEventListener('load', () => {
  setTimeout(() => {
    const lat = <?= (float)($location['lat'] ?? 6.2167) ?>;
    const lng = <?= (float)($location['lng'] ?? 125.0750) ?>;
    const map = L.map('leaflet-map', {
      center: [lat, lng], zoom: 16, zoomControl: true,
      scrollWheelZoom: false, tap: true, tapTolerance: 15
    });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      subdomains: 'abc', maxZoom: 20
    }).addTo(map);

    // Use CSS classes on the icon HTML — no inline styles on the outer element
    const icon = L.divIcon({
      className: '',  // prevent Leaflet's own inline size styles on wrapper
      html: '<div class="falcon-marker-wrap"><div class="falcon-marker-inner">🦅</div></div>',
      iconSize:   [40, 40],
      iconAnchor: [20, 40]
    });

    // Popup content uses plain text, Leaflet sets its own popup styles
    L.marker([lat, lng], { icon })
      .addTo(map)
      .bindPopup(
        '<b>🦅 Padol Pickleball Court</b><br>' +
        '<?= addslashes(h($location['address'] ?? 'Purok Sagrado Valencia Site, Polomolok')) ?><br>' +
        '🕐 <?= addslashes(h($location['hours'] ?? '10AM – 12MN Daily')) ?>'
      )
      .openPopup();

    // Pulse ring — CSS class handles animation, no inline styles
    const pulseIcon = L.divIcon({
      className: '',
      html: '<div class="falcon-pulse-ring"></div>',
      iconSize:   [80, 80],
      iconAnchor: [40, 40]
    });
    L.marker([lat, lng], { icon: pulseIcon, interactive: false, zIndexOffset: -1 }).addTo(map);
  }, 500);
});

// ── Live courts auto-refresh (60s) ──────────────────────────
function updateCourtsGrid() {
  fetch('/api/courts.php')
    .then(r => r.json())
    .then(data => {
      const grid = document.getElementById('courts-grid');
      if (!grid || !data.courts) return;

      grid.innerHTML = data.courts.map(court => {
        const typeIcon = {covered:'🏠',uncovered:'☀️',indoor:'🏢',outdoor:'🌿'}[court.court_type] || '🏓';
        let statusText = '';
        let statusClass = '';
        switch (court.live_status) {
          case 'available': statusText = '● OPEN FOR PLAY'; statusClass = 'available'; break;
          case 'active': statusText = '🎮 GAME IN PROGRESS'; statusClass = 'active'; break;
          case 'queuing': statusText = '⏳ QUEUING'; statusClass = 'queuing'; break;
          case 'maintenance': statusText = '🔧 UNDER MAINTENANCE'; statusClass = 'maintenance'; break;
          case 'closed': statusText = '✗ CLOSED'; statusClass = 'closed'; break;
          case 'reserved': statusText = '📅 RESERVED'; statusClass = 'reserved'; break;
          default: statusText = '○ UNKNOWN'; statusClass = 'unknown'; break;
        }

        return `
          <div class="court-card reveal" data-court-id="${court.id}">
            <div class="court-header">
              <div class="court-name">${typeIcon} ${court.name}</div>
              <div class="court-type">${court.court_type.toUpperCase()}</div>
            </div>
            <hr class="court-divider">
            <div class="court-status">
              <div class="status-text ${statusClass}">${statusText}</div>
            </div>
            <div class="court-action">
              <a href="/player/schedule.php?court=${court.id}" class="btn-primary btn-sm">Book This Court →</a>
            </div>
          </div>
        `;
      }).join('');

      // Re-observe new reveal elements
      grid.querySelectorAll('.reveal').forEach(el => revealObserver.observe(el));
    })
    .catch(() => {});
}

setInterval(() => {
  if (!document.hidden) updateCourtsGrid();
}, 60000);
</script>

<?php require_once __DIR__ . '/../includes/chat_widget.php'; ?>
</body>
</html>