<?php
// ============================================================
//  FILE: public/privacy_policy.php
//  Static Privacy Policy page. Linked from includes/footer.php
//  on every page but never existed on disk.
//
//  NOTE: same disclaimer as terms_of_service.php -- this is
//  boilerplate matched to what the app actually collects/stores
//  (accounts, wallet/payment proofs, scan logs, game/tournament
//  history, uploaded photos), not reviewed legal advice.
// ============================================================
require_once __DIR__ . '/../config/app.php';

$pageTitle = 'Privacy Policy';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;max-width:800px;">
  <div class="card">
    <div class="card-header">
      <h1 style="margin:0 0 6px;">Privacy Policy</h1>
      <p style="color:var(--muted);margin:0;">Last updated: <?= date('F Y') ?></p>
    </div>

    <div style="line-height:1.7;color:var(--muted);">

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">1. What We Collect</h2>
      <p>To run <?= clean(APP_NAME) ?>, we store the information you'd expect for a court
        booking and wallet system:</p>
      <ul style="padding-left:20px;">
        <li>Account details — username, email, full name, phone number, avatar</li>
        <li>Wallet activity — top-up requests, payment screenshots/QR proofs, balance history</li>
        <li>Court activity — reservations, check-in scan logs, game sessions and scores</li>
        <li>Tournament activity — registrations, seeds, match results</li>
        <li>Food orders — items ordered, payment method, order status</li>
      </ul>

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">2. How We Use It</h2>
      <p>This information is used to run the core features of the platform: booking courts,
        checking you in via your QR code, tracking your wallet balance, running tournaments and
        the leaderboard, and processing food orders. We don't sell your information to third
        parties.</p>

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">3. Payment Screenshots</h2>
      <p>When you top up your wallet, the GCash payment screenshot you upload is stored so
        staff can verify the payment before approving it. Only staff/admins can view these
        proofs.</p>

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">4. Who Can See Your Data</h2>
      <p>Your username, display name, and leaderboard/tournament stats are visible to other
        players as part of the normal function of a leaderboard and tournament bracket. Your
        contact details, wallet balance, and payment proofs are only visible to you and to
        staff/admins.</p>

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">5. Data Retention</h2>
      <p>We keep account and activity history for as long as your account is active, so your
        game history, tournament record, and leaderboard stats stay accurate. If you'd like your
        account and associated data removed, contact an admin.</p>

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">6. Security</h2>
      <p>We take reasonable steps to protect your data, including hashed passwords and
        restricted admin-only access to sensitive records like payment proofs and scan logs. No
        system is perfectly secure, so please use a unique password and keep your QR code
        private.</p>

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">7. Changes to This Policy</h2>
      <p>We may update this policy from time to time. Continued use of the platform after a
        change means you accept the updated policy.</p>

      <h2 style="color:var(--text,inherit);font-size:16px;margin-top:24px;">8. Contact</h2>
      <p>Questions about your data? Visit the <a href="<?= APP_URL ?>/public/help.php">Help
        Center</a> or ask a staff member at the counter.</p>

    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
