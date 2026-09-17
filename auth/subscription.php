<?php
// ============================================================
//  FILE: auth/subscription.php
//
//  Shown when:
//   (a) login detects expired/no subscription → ?reason=expired
//   (b) login detects no plan yet             → ?reason=none
//   (c) user manually visits to manage plan
//
//  Displays:
//   - Clear notice of what happened (expired / no plan)
//   - Available membership plans (from DB)
//   - "Renew / Subscribe" CTA per plan  → api/subscription.php
//   - Current subscription status if they have one
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/subscription_helpers.php';

// Must be logged in to reach this page
if (!isLoggedIn()) {
    redirect('auth/login.php');
}

$db     = getDB();
$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'] ?? 'player';
$reason = $_GET['reason'] ?? 'manage';   // 'expired' | 'none' | 'manage'

// Superadmins don't need a subscription — bounce them away
if (isSubscriptionExempt($role)) {
    redirect(roleDashboard());
}

// Fresh status — bust cache so this page always shows live data
bustSubscriptionCache($userId);
$sub    = getSubscriptionStatus($db, $userId);
$notice = $_SESSION['_sub_notice'] ?? null;

// ── Load available plans ─────────────────────────────────────
try {
    $planStmt = $db->query("
        SELECT id, name, price, tagline, features, period, is_featured
        FROM falcon.membership_plans
        WHERE is_active = TRUE
        ORDER BY price ASC
    ");
    $plans = $planStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[SUB PAGE] Failed to load plans: ' . $e->getMessage());
    $plans = [];
}

$pageTitle = 'Subscription';
require_once __DIR__ . '/../includes/header.php';

// ── Decide top-banner message ────────────────────────────────
$bannerType = 'info';
$bannerMsg  = '';
if ($reason === 'expired' || $sub['status'] === 'expired') {
    $bannerType = 'error';
    $bannerMsg  = '🔒 Your subscription has expired. Your account is currently limited. '
                . 'Please renew below to restore full access.';
} elseif ($reason === 'none' || $sub['status'] === 'none') {
    $bannerType = 'info';
    $bannerMsg  = '👋 Welcome! You don\'t have an active subscription yet. '
                . 'Choose a plan below to get started.';
} elseif ($sub['status'] === 'grace') {
    $bannerType = 'warning';
    $bannerMsg  = "⚠️ Your subscription expired {$sub['days_left']} day(s) ago. "
                . "You have {$sub['grace_days_left']} grace day(s) remaining. Please renew now.";
} elseif ($sub['status'] === 'active') {
    $bannerType = 'success';
    $bannerMsg  = "✅ Your <strong>" . clean($sub['plan_name'] ?? 'plan') . "</strong> subscription is active"
                . ($sub['paid_until'] ? " until <strong>" . $sub['paid_until']->format('F j, Y') . "</strong>." : ".");
}

$badge = subscriptionBadge($sub);
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Page wrapper ───────────────────────────────────────────── */
.sub-page {
    max-width: 900px;
    margin: 0 auto;
    padding: clamp(24px, 5vw, 48px) clamp(16px, 4vw, 24px);
    padding-bottom: max(clamp(24px, 5vw, 48px), env(safe-area-inset-bottom, 24px));
}

/* ── Section heading ────────────────────────────────────────── */
.sub-page h1 {
    font-size: clamp(22px, 5vw, 30px);
    margin: 0 0 6px;
}
.sub-page .page-sub {
    color: var(--muted);
    font-size: 14px;
    margin: 0 0 28px;
}

/* ── Status banner ──────────────────────────────────────────── */
.sub-banner {
    border-radius: 14px;
    padding: 16px 20px;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 32px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
}
.sub-banner.error   { background: rgba(239,68,68,.12);  border: 1px solid rgba(239,68,68,.3); }
.sub-banner.warning { background: rgba(251,191,36,.12); border: 1px solid rgba(251,191,36,.3); }
.sub-banner.info    { background: rgba(99,179,237,.12); border: 1px solid rgba(99,179,237,.3); }
.sub-banner.success { background: rgba(0,229,160,.1);   border: 1px solid rgba(0,229,160,.3); }

.sub-banner-icon { font-size: 20px; flex-shrink: 0; margin-top: 1px; }
.sub-banner-body { flex: 1; }
.sub-banner-body strong { font-weight: 700; }

/* ── Current plan card (if any) ─────────────────────────────── */
.current-plan-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px 24px;
    margin-bottom: 36px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.current-plan-label { font-size: 13px; color: var(--muted); margin-bottom: 4px; }
.current-plan-name  { font-size: 20px; font-weight: 700; }
.current-plan-date  { font-size: 13px; color: var(--muted); margin-top: 4px; }

.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 99px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}
.badge-success { background: rgba(0,229,160,.15);  color: #00e5a0; }
.badge-warning { background: rgba(251,191,36,.15); color: #f59e0b; }
.badge-danger  { background: rgba(239,68,68,.15);  color: #ef4444; }
.badge-muted   { background: var(--surface2);      color: var(--muted); }

/* ── Plans grid ─────────────────────────────────────────────── */
.plans-heading {
    font-size: 18px;
    font-weight: 700;
    margin: 0 0 20px;
}

.plans-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}

.plan-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 24px 22px 20px;
    display: flex;
    flex-direction: column;
    transition: border-color 0.2s, transform 0.15s;
    position: relative;
}

.plan-card:hover {
    border-color: var(--accent);
    transform: translateY(-2px);
}

.plan-card.featured {
    border-color: var(--accent);
    box-shadow: 0 0 0 2px rgba(0,229,160,.25);
}

.plan-featured-badge {
    position: absolute;
    top: -12px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--accent);
    color: #000;
    font-size: 11px;
    font-weight: 800;
    padding: 3px 14px;
    border-radius: 99px;
    white-space: nowrap;
    letter-spacing: .5px;
}

.plan-name {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 4px;
}

.plan-tagline {
    font-size: 13px;
    color: var(--muted);
    margin-bottom: 16px;
    min-height: 36px;
}

.plan-price {
    font-size: 30px;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 4px;
}

.plan-price span {
    font-size: 14px;
    font-weight: 500;
    color: var(--muted);
}

.plan-period {
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 20px;
}

.plan-features {
    list-style: none;
    padding: 0;
    margin: 0 0 24px;
    flex: 1;
}

.plan-features li {
    font-size: 13px;
    padding: 5px 0;
    display: flex;
    gap: 8px;
    align-items: flex-start;
    color: var(--text);
    border-bottom: 1px solid var(--border);
}

.plan-features li:last-child { border-bottom: none; }

.plan-features li::before {
    content: '✓';
    color: var(--accent);
    font-weight: 700;
    flex-shrink: 0;
    margin-top: 1px;
}

.plan-cta {
    display: block;
    width: 100%;
    padding: 12px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    text-align: center;
    cursor: pointer;
    border: none;
    transition: opacity 0.2s, transform 0.15s;
}

.plan-cta:active { transform: scale(.98); }

.plan-cta.primary {
    background: var(--accent);
    color: #000;
}

.plan-cta.secondary {
    background: var(--surface2);
    color: var(--text);
    border: 1px solid var(--border);
}

.plan-cta:hover { opacity: .88; }
.plan-cta:disabled { opacity: .45; cursor: not-allowed; }

/* ── Current plan indicator on card ────────────────────────── */
.plan-card.is-current-plan { border-color: var(--accent); }
.plan-current-label {
    display: inline-block;
    background: rgba(0,229,160,.12);
    color: var(--accent);
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 99px;
    margin-bottom: 10px;
}

/* ── Back link ──────────────────────────────────────────────── */
.sub-back {
    text-align: center;
    margin-top: 12px;
}

.sub-back a {
    font-size: 13px;
    color: var(--muted);
    text-decoration: none;
}

.sub-back a:hover { color: var(--accent); text-decoration: underline; }

/* ── No plans fallback ──────────────────────────────────────── */
.no-plans {
    text-align: center;
    padding: 48px 24px;
    color: var(--muted);
    font-size: 14px;
}

@media (max-width: 480px) {
    .current-plan-card { flex-direction: column; }
    .plans-grid { grid-template-columns: 1fr; }
}
</style>

<div class="sub-page">
    <h1>Subscription</h1>
    <p class="page-sub">Manage your Falcon Pickleball membership.</p>

    <!-- ── Top notice banner ──────────────────────────────────── -->
    <?php if ($bannerMsg): ?>
    <div class="sub-banner <?= $bannerType ?>">
        <div class="sub-banner-icon">
            <?php
            echo match($bannerType) {
                'error'   => '🔒',
                'warning' => '⚠️',
                'success' => '✅',
                default   => 'ℹ️',
            };
            ?>
        </div>
        <div class="sub-banner-body"><?= $bannerMsg ?></div>
    </div>
    <?php endif; ?>

    <!-- ── Current plan summary (if any) ─────────────────────── -->
    <?php if ($sub['status'] !== 'none'): ?>
    <div class="current-plan-card">
        <div>
            <div class="current-plan-label">Current Plan</div>
            <div class="current-plan-name"><?= clean($sub['plan_name'] ?? 'Unknown Plan') ?></div>
            <?php if ($sub['paid_until']): ?>
            <div class="current-plan-date">
                <?php if ($sub['status'] === 'active'): ?>
                    Renews on <?= $sub['paid_until']->format('F j, Y') ?>
                <?php elseif ($sub['status'] === 'grace'): ?>
                    Expired <?= $sub['paid_until']->format('F j, Y') ?> · <?= $sub['grace_days_left'] ?> grace day(s) left
                <?php else: ?>
                    Expired on <?= $sub['paid_until']->format('F j, Y') ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="badge <?= $badge['class'] ?>">
            <?= $badge['icon'] ?> <?= $badge['label'] ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Plans grid ─────────────────────────────────────────── -->
    <div class="plans-heading">
        <?= ($sub['status'] === 'active') ? 'Change or Renew Plan' : 'Choose a Plan' ?>
    </div>

    <?php if (empty($plans)): ?>
        <div class="no-plans">
            No subscription plans are available right now. Please contact the court owner.
        </div>
    <?php else: ?>
    <div class="plans-grid">
        <?php foreach ($plans as $plan):
            $features    = is_string($plan['features'])
                ? (json_decode($plan['features'], true) ?? [])
                : ($plan['features'] ?? []);
            $isFeatured  = !empty($plan['is_featured']);
            $isCurrentPlan = ($sub['plan_id'] === (int)$plan['id'] && $sub['status'] === 'active');
            $price       = number_format((float)$plan['price'], 0);
        ?>
        <div class="plan-card <?= $isFeatured ? 'featured' : '' ?> <?= $isCurrentPlan ? 'is-current-plan' : '' ?>">
            <?php if ($isFeatured): ?>
                <div class="plan-featured-badge">⭐ Most Popular</div>
            <?php endif; ?>

            <?php if ($isCurrentPlan): ?>
                <div class="plan-current-label">✓ Your current plan</div>
            <?php endif; ?>

            <div class="plan-name"><?= clean($plan['name']) ?></div>
            <div class="plan-tagline"><?= clean($plan['tagline'] ?? '') ?></div>

            <div class="plan-price">
                ₱<?= $price ?><span> / <?= clean($plan['period'] ?? 'month') ?></span>
            </div>
            <div class="plan-period">Billed monthly · Cancel anytime</div>

            <?php if (!empty($features)): ?>
            <ul class="plan-features">
                <?php foreach ($features as $feature): ?>
                    <li><?= clean($feature) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <!-- CTA posts to api/subscription.php which handles PayMongo initiation -->
            <form method="POST" action="<?= APP_URL ?>/api/subscription.php">
                <?= csrfField() ?>
                <input type="hidden" name="action"  value="subscribe">
                <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                <button type="submit"
                        class="plan-cta <?= $isFeatured ? 'primary' : 'secondary' ?>">
                    <?php
                    if ($isCurrentPlan)              echo 'Renew This Plan';
                    elseif ($sub['status'] === 'none') echo 'Get Started';
                    else                              echo 'Switch to ' . clean($plan['name']);
                    ?>
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Back link — only shown if not hard-blocked ─────────── -->
    <?php if (in_array($sub['status'], ['active', 'grace'], true)): ?>
    <div class="sub-back">
        <a href="<?= roleDashboard() ?>">← Back to dashboard</a>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>