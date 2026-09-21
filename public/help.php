<?php
// ============================================================
//  FILE: public/help.php
//  Help Center / FAQ page.
//
//  Same story as public/leaderboard.php and public/tournaments.php:
//  this file is linked from the header nav, footer, dashboards,
//  and even the pre-login page (auth/login.php) -- but it never
//  actually existed on disk, so every one of those links 404'd.
//
//  Unlike leaderboard.php/tournaments.php this page does NOT call
//  requireLogin(), on purpose: it's linked from the login screen's
//  footer too, so a signed-out visitor needs to be able to open it.
//  includes/header.php already handles the logged-out case fine
//  (it checks isset($user) && $user throughout).
//
//  No DB/engine dependency -- purely static content, grouped into
//  <details> sections so nothing needs extra CSS beyond what
//  assets/css/app.css already provides for .card/.badge/etc.
//  The "How to top up" links elsewhere point at #top-up, so that
//  anchor id is kept stable.
// ============================================================
require_once __DIR__ . '/../config/app.php';

$pageTitle = 'Help Center';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;">

  <div class="card" style="margin-bottom:24px;">
    <div class="card-header">
      <h1 style="margin:0 0 6px;">❓ Help Center</h1>
      <p style="color:var(--muted);margin:0;">
        Quick answers for players, plus a few pointers for admins and staff.
        Can't find what you need? Reach out to a staff member at the counter.
      </p>
    </div>
  </div>

  <!-- ══ Getting started ══════════════════════════════════════ -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header"><h2 style="margin:0;">🏓 Getting Started</h2></div>
    <div style="padding:4px 0;">

      <details open style="padding:12px 0;border-bottom:1px solid var(--border,rgba(255,255,255,0.08));">
        <summary style="cursor:pointer;font-weight:600;">How do I book a court?</summary>
        <p style="margin:10px 0 0;color:var(--muted);">
          Go to <strong>Schedule</strong> from your player dashboard, pick an open time slot,
          and confirm. Your reservation will show up under
          <a href="<?= APP_URL ?>/player/schedule.php">My Schedule</a>. Courts require enough
          wallet balance to cover the session's credit cost — see the top-up section below if
          you need to load funds first.
        </p>
      </details>

      <details style="padding:12px 0;border-bottom:1px solid var(--border,rgba(255,255,255,0.08));">
        <summary style="cursor:pointer;font-weight:600;">How does the Open Play queue work?</summary>
        <p style="margin:10px 0 0;color:var(--muted);">
          Open Play now runs through the live queue system instead of QR check-ins. Join from
          <a href="<?= APP_URL ?>/public/open_play.php">Open Play</a>, watch your position in the
          queue, and staff manage the active matches from the Open Play control screens.
        </p>
      </details>

      <details style="padding:12px 0;">
        <summary style="cursor:pointer;font-weight:600;">Where do I see my game history?</summary>
        <p style="margin:10px 0 0;color:var(--muted);">
          Check <a href="<?= APP_URL ?>/player/history.php">History</a> for past games and
          <a href="<?= APP_URL ?>/player/tournament_history.php">Tournament History</a> for
          past tournament results.
        </p>
      </details>

    </div>
  </div>

  <!-- ══ Wallet / top-up ══════════════════════════════════════ -->
  <div class="card" id="top-up" style="margin-bottom:16px;scroll-margin-top:80px;">
    <div class="card-header"><h2 style="margin:0;">💳 Wallet &amp; Top-Up</h2></div>
    <div style="padding:4px 0;">

      <details open style="padding:12px 0;border-bottom:1px solid var(--border,rgba(255,255,255,0.08));">
        <summary style="cursor:pointer;font-weight:600;">How do I load credits into my wallet?</summary>
        <p style="margin:10px 0 0;color:var(--muted);">
          Go to <a href="<?= APP_URL ?>/player/topup.php">Load Credits</a>, choose an amount,
          and pay via GCash — scan the QR code shown on that page and upload your payment
          screenshot as proof. An admin reviews and approves it, and your balance updates
          automatically once approved. You can check the status of a pending top-up any time
          on the same page.
        </p>
      </details>

      <details style="padding:12px 0;border-bottom:1px solid var(--border,rgba(255,255,255,0.08));">
        <summary style="cursor:pointer;font-weight:600;">How long does approval take?</summary>
        <p style="margin:10px 0 0;color:var(--muted);">
          Usually just a few minutes during staffed hours. If it's been a while, check
          <a href="<?= APP_URL ?>/player/topup_history.php">Credit History</a> for the current
          status, or ask a staff member to check the pending queue.
        </p>
      </details>

      <details style="padding:12px 0;">
        <summary style="cursor:pointer;font-weight:600;">Why was my top-up declined?</summary>
        <p style="margin:10px 0 0;color:var(--muted);">
          Usually because the payment screenshot didn't clearly show the reference number or
          amount. Submit a new top-up with a clearer screenshot, or ask a staff member to
          verify your payment manually.
        </p>
      </details>

    </div>
  </div>

  <!-- ══ Tournaments ══════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header"><h2 style="margin:0;">🎯 Tournaments</h2></div>
    <div style="padding:4px 0;">

      <details open style="padding:12px 0;border-bottom:1px solid var(--border,rgba(255,255,255,0.08));">
        <summary style="cursor:pointer;font-weight:600;">How do I join a tournament?</summary>
        <p style="margin:10px 0 0;color:var(--muted);">
          Open <a href="<?= APP_URL ?>/public/tournaments.php">Tournaments</a>, find one under
          the "Open" tab, and click <strong>Join</strong>. You can leave again from the same
          page as long as the tournament hasn't started yet.
        </p>
      </details>

      <details style="padding:12px 0;">
        <summary style="cursor:pointer;font-weight:600;">How are brackets and seeding decided?</summary>
        <p style="margin:10px 0 0;color:var(--muted);">
          Once registration closes, players are seeded by their current leaderboard rank and
          the bracket is generated automatically based on the tournament's format
          (single elimination, double elimination, or Swiss). You'll see your matches on the
          tournament page as they're scheduled.
        </p>
      </details>

    </div>
  </div>

  <!-- ══ Food ordering ════════════════════════════════════════ -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header"><h2 style="margin:0;">🍔 Food &amp; Drinks</h2></div>
    <div style="padding:4px 0;">

      <details open style="padding:12px 0;">
        <summary style="cursor:pointer;font-weight:600;">How do I order food?</summary>
        <p style="margin:10px 0 0;color:var(--muted);">
          Browse the menu at <a href="<?= APP_URL ?>/player/food_menu.php">Food Menu</a>, add
          items, and choose pickup at the counter or delivery to your court. Pay by wallet
          (deducted instantly) or cash at the counter. Track your order status and number at
          Food Orders from your dashboard.
        </p>
      </details>

    </div>
  </div>

  <!-- ══ Account & other ══════════════════════════════════════ -->
  <div class="card">
    <div class="card-header"><h2 style="margin:0;">⚙️ Account</h2></div>
    <div style="padding:4px 0;">

      <details open style="padding:12px 0;border-bottom:1px solid var(--border,rgba(255,255,255,0.08));">
        <summary style="cursor:pointer;font-weight:600;">I forgot my password. What now?</summary>
        <p style="margin:10px 0 0;color:var(--muted);">
          Ask a staff member or admin at the counter to reset it for you — there's currently
          no self-service password reset.
        </p>
      </details>

      <details style="padding:12px 0;">
        <summary style="cursor:pointer;font-weight:600;">Still stuck?</summary>
        <p style="margin:10px 0 0;color:var(--muted);">
          Come find a staff member at the counter, or ask whoever manages this system to take
          a look — mention the page you were on and what you were trying to do.
        </p>
      </details>

    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
