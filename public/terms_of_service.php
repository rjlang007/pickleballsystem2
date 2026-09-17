<?php
// ============================================================
//  FILE: public/terms_of_service.php
//  Static Terms of Service page. Linked from includes/footer.php
//  on every page but never existed on disk.
//
//  NOTE: this is boilerplate written to match what the app
//  actually does (court booking, wallet top-ups via GCash,
//  tournaments, food ordering) -- it is a starting point, not
//  reviewed legal advice. Have an actual lawyer look this over
//  before relying on it, especially the payment/refund section.
// ============================================================
require_once __DIR__ . '/../config/app.php';

$pageTitle = 'Terms of Service';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;max-width:800px;">
  <div class="card">
    <div class="card-header">
      <h1 style="margin:0 0 6px;">Terms of Service</h1>
      <p style="color:var(--muted);margin:0;">Last updated: <?= date('F Y') ?></p>
    </div>

    <div style="line-height:1.7;color:var(--muted);">

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">1. Acceptance of Terms</h2>
      <p>By creating an account or using <?= clean(APP_NAME) ?> ("the platform"), you agree to
        these Terms of Service. If you don't agree with any part of these terms, please don't
        use the platform.</p>

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">2. Accounts</h2>
      <p>You're responsible for keeping your login credentials secure and for all activity
        under your account. Provide accurate information when registering, and let us know if
        you believe your account has been accessed without your permission.</p>

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">3. Court Bookings &amp; Check-In</h2>
      <p>Court sessions and reservations are subject to availability and the operating hours
        set for each court. Your personal QR code is how staff check you in for a session — keep
        it private, as anyone with access to it could check in under your account.</p>

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">4. Wallet &amp; Payments</h2>
      <p>Credits are loaded to your wallet via GCash and reviewed by staff before being applied
        to your balance. Please make sure your payment reference and screenshot are clear —
        top-ups can't be approved without proof of payment. Credits are deducted automatically
        when you check in for a paid session or place a food order.</p>
      <p>Refunds for declined top-ups, cancelled reservations, or cancelled food orders are
        handled on a case-by-case basis by staff — ask at the counter or contact an admin.</p>

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">5. Tournaments</h2>
      <p>Joining a tournament means committing to play your scheduled matches. Withdrawing
        after a bracket has been generated may affect other players' matchups, so please only
        register if you intend to play. Tournament staff/admins have final say on scoring
        disputes and scheduling conflicts.</p>

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">6. Conduct</h2>
      <p>Be respectful of other players, staff, and referees. Harassment, cheating, or abuse of
        the wallet/booking system may result in suspension or termination of your account at
        our discretion.</p>

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">7. Changes to These Terms</h2>
      <p>We may update these terms from time to time. Continued use of the platform after a
        change means you accept the updated terms.</p>

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">8. Contact</h2>
      <p>Questions about these terms? Visit the <a href="<?= APP_URL ?>/public/help.php">Help
        Center</a> or ask a staff member at the counter.</p>

    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
