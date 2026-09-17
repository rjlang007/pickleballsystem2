<?php
// ============================================================
//  FILE: includes/subscription_helpers.php
//
//  Subscription status helpers consumed by:
//    • auth/login.php            (gate check after login)
//    • auth/subscription.php     (renewal UI)
//    • includes/header.php       (grace-period banner)
//    • Any page requiring requireActiveSubscription()
//
//  Dependencies: config/app.php must already be loaded.
// ============================================================

if (!function_exists('getSubscriptionStatus')) :

/**
 * Returns a normalised status array for a user's subscription.
 *
 * Return shape:
 * [
 *   'status'         => 'active' | 'grace' | 'expired' | 'none',
 *   'plan'           => 'basic' | 'medium' | 'premium' | null,
 *   'paid_until'     => DateTime | null,
 *   'days_left'      => int,          // negative when expired
 *   'grace_days_left'=> int,          // 0 when not in grace
 *   'auto_renew'     => bool,
 *   'paymongo_sub_id'=> string | null,
 *   'sub_id'         => int | null,
 *   'row'            => array | null, // raw DB row
 * ]
 *
 * Grace period: 3 days after paid_until before we hard-block.
 */
function getSubscriptionStatus(PDO $db, int $userId): array
{
    $empty = [
        'status'          => 'none',
        'plan'            => null,
        'paid_until'      => null,
        'days_left'       => 0,
        'grace_days_left' => 0,
        'auto_renew'      => false,
        'paymongo_sub_id' => null,
        'sub_id'          => null,
        'row'             => null,
    ];

    try {
        $stmt = $db->prepare("
            SELECT id, plan, status, paid_until, auto_renew, paymongo_sub_id
              FROM falcon.subscriptions
             WHERE user_id = ?
             LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[getSubscriptionStatus] ' . $e->getMessage());
        return $empty;
    }

    if (!$row) return $empty;

    $now       = new DateTimeImmutable();
    $paidUntil = null;
    $daysLeft  = 0;

    if (!empty($row['paid_until'])) {
        $paidUntil = new DateTimeImmutable($row['paid_until']);
        $diff      = $now->diff($paidUntil);
        // diff->days is always positive; invert sign when in the past
        $daysLeft  = ($now <= $paidUntil)
            ? (int)$diff->days
            : -(int)$diff->days;
    }

    // Grace period constant — adjust here if you want more/fewer days
    $graceDays      = 3;
    $graceDaysLeft  = 0;
    $status         = $row['status'];   // DB value: active|inactive|expired|cancelled

    if ($paidUntil) {
        if ($now <= $paidUntil) {
            // Still within paid window
            $status = 'active';
        } elseif ($daysLeft >= -$graceDays) {
            // Within grace period
            $status       = 'grace';
            $graceDaysLeft = $graceDays + $daysLeft; // days_left is negative here
        } else {
            // Hard-expired
            $status = 'expired';
        }
    } else {
        // No paid_until means never paid
        $status = ($row['status'] === 'active') ? 'expired' : 'none';
    }

    return [
        'status'          => $status,
        'plan'            => $row['plan'],
        'paid_until'      => $paidUntil,
        'days_left'       => $daysLeft,
        'grace_days_left' => $graceDaysLeft,
        'auto_renew'      => (bool)$row['auto_renew'],
        'paymongo_sub_id' => $row['paymongo_sub_id'],
        'sub_id'          => (int)$row['id'],
        'row'             => $row,
    ];
}

endif;


if (!function_exists('isSubscriptionExempt')) :

/**
 * Roles that bypass subscription checks entirely.
 * super_admin + admin are always exempt.
 */
function isSubscriptionExempt(string $role): bool
{
    return in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN], true);
}

endif;


if (!function_exists('requireActiveSubscription')) :

/**
 * Gate function — call at the top of any page that requires
 * an active or grace-period subscription.
 *
 * Behaviour:
 *   • Exempt roles       → pass through
 *   • Active / grace     → pass through (plants notice in session for grace)
 *   • Expired / none     → redirect to auth/subscription.php
 *   • Not logged in      → redirect to auth/login.php
 */
function requireActiveSubscription(): void
{
    if (!isLoggedIn()) {
        redirect('auth/login.php');
    }

    $role = $_SESSION['role'] ?? '';
    if (isSubscriptionExempt($role)) return;

    $db  = getDB();
    $uid = (int)$_SESSION['user_id'];
    $sub = getSubscriptionStatus($db, $uid);

    if (in_array($sub['status'], ['active', 'grace'], true)) {
        if ($sub['status'] === 'grace' && empty($_SESSION['_sub_notice'])) {
            $_SESSION['_sub_notice'] = [
                'type'    => 'warning',
                'message' => '⚠️ Your subscription expired '
                           . abs($sub['days_left']) . ' day(s) ago. '
                           . 'You have ' . $sub['grace_days_left']
                           . ' grace day(s) left. Please renew soon.',
                'sub'     => $sub,
            ];
        }
        return; // allowed through
    }

    // Block — plant notice and redirect
    $_SESSION['_sub_notice'] = [
        'type'    => 'expired',
        'message' => $sub['status'] === 'none'
            ? "🔒 You don't have an active subscription yet. Choose a plan to get started."
            : "🔒 Your subscription has expired. Please renew to continue.",
        'sub'     => $sub,
    ];
    redirect('auth/subscription.php?reason=' . $sub['status']);
}

endif;


if (!function_exists('getAndClearSubNotice')) :

/**
 * Pull the subscription notice from the session (and clear it).
 * Returns null if none is set.
 * Use in header.php to render the grace-period banner.
 */
function getAndClearSubNotice(): ?array
{
    if (isset($_SESSION['_sub_notice'])) {
        $notice = $_SESSION['_sub_notice'];
        unset($_SESSION['_sub_notice']);
        return $notice;
    }
    return null;
}

endif;


if (!function_exists('getPlanLabel')) :

/**
 * Human-readable plan label for display.
 */
function getPlanLabel(?string $plan): string
{
    return match ($plan) {
        PLAN_PREMIUM => '🏆 Premium',
        PLAN_MEDIUM  => '⭐ Medium',
        PLAN_BASIC   => '🎯 Basic',
        default      => '—',
    };
}

endif;


if (!function_exists('getPlanPrice')) :

/**
 * Return the PHP price for a plan slug.
 */
function getPlanPrice(?string $plan): float
{
    return match ($plan) {
        PLAN_PREMIUM => PLAN_PRICE_PREMIUM,
        PLAN_MEDIUM  => PLAN_PRICE_MEDIUM,
        PLAN_BASIC   => PLAN_PRICE_BASIC,
        default      => 0.0,
    };
}

endif;


if (!function_exists('formatPaidUntil')) :

/**
 * Friendly date string for paid_until.
 */
function formatPaidUntil(?DateTimeImmutable $paidUntil): string
{
    if (!$paidUntil) return 'Never';
    return $paidUntil->format('F j, Y');
}

endif;


if (!function_exists('bustSubscriptionCache')) :

/**
 * Invalidate any cached subscription status for a user.
 *
 * getSubscriptionStatus() currently always reads straight from the
 * database, so there's no cache to clear yet — this is a no-op that
 * exists so callers (e.g. auth/subscription.php, right before it
 * shows a user their live status) don't fail if a caching layer
 * (session, APCu, etc.) gets added here later. Keeping the call site
 * intact means adding real caching later is a one-function change.
 */
function bustSubscriptionCache(int $userId): void
{
    // No-op: no cache exists yet.
}

endif;


if (!function_exists('subscriptionBadge')) :

/**
 * Small status badge (class/icon/label) for a getSubscriptionStatus()
 * result, used next to a user's plan name — e.g. on auth/subscription.php.
 * CSS classes (badge-success/warning/danger/muted) are defined on the
 * pages that render this.
 */
function subscriptionBadge(array $sub): array
{
    return match ($sub['status'] ?? 'none') {
        'active' => ['class' => 'badge-success', 'icon' => '✅', 'label' => 'Active'],
        'grace'  => ['class' => 'badge-warning', 'icon' => '⚠️', 'label' => 'Grace Period'],
        'expired' => ['class' => 'badge-danger', 'icon' => '⛔', 'label' => 'Expired'],
        default  => ['class' => 'badge-muted', 'icon' => '➖', 'label' => 'No Plan'],
    };
}

endif;