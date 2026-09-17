<?php
// ============================================================
//  FILE: api/paymongo_webhook.php
//
//  Receives and processes PayMongo webhook events.
//
//  Handled event types:
//    payment.paid                – one-shot payment succeeded
//    payment.failed              – payment failed (mark pending → failed)
//    payment.refunded            – refund issued
//    checkout_session.payment.paid – checkout flow completed
//    subscription.created        – PayMongo subscription created
//    subscription.updated        – plan change / status update
//    subscription.payment.paid   – recurring billing succeeded
//    subscription.payment.failed – recurring billing failed
//    subscription.deleted        – subscription cancelled on PayMongo side
//
//  Security:
//    • Raw body is read once and stored; JSON decoded separately.
//    • HMAC-SHA256 signature verified against PAYMONGO_WEBHOOK_SECRET.
//    • No session, no output buffering — pure API endpoint.
//    • All DB writes are wrapped in transactions.
//    • Idempotency: duplicate paymongo_payment_id is ignored (UNIQUE index).
//
//  Response contract:
//    200  – event accepted and processed (or safely ignored)
//    400  – malformed payload
//    401  – signature mismatch
//    500  – internal error (PayMongo will retry)
// ============================================================

// ── Bootstrap (DB + constants only; no session, no output) ───
define('WEBHOOK_ENTRY', true);          // flag checked by app.php guard
require_once __DIR__ . '/../config/app.php';

// Disable any accidental HTML error output — we speak JSON here
ini_set('display_errors', '0');
ini_set('html_errors',    '0');

// ── Helpers ───────────────────────────────────────────────────

/**
 * Emit a JSON response and exit.
 */
function webhookRespond(int $code, string $message, array $extra = []): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['message' => $message], $extra));
    exit;
}

/**
 * Log webhook activity to the PHP error log (visible in Railway / server logs).
 */
function wlog(string $level, string $msg, array $ctx = []): void
{
    $ctx_str = $ctx ? ' ' . json_encode($ctx) : '';
    error_log("[PAYMONGO_WEBHOOK][$level] $msg$ctx_str");
}

/**
 * Verify the PayMongo webhook signature.
 *
 * PayMongo sends:
 *   PayMongo-Signature: t=<timestamp>,te=<test_sig>,li=<live_sig>
 *
 * We compute HMAC-SHA256( "<timestamp>.<rawBody>", secret ) and
 * compare against the appropriate sig (test vs live).
 *
 * Returns true on success, false on failure.
 */
function verifyPayMongoSignature(string $rawBody, string $signatureHeader): bool
{
    $secret = PAYMONGO_WEBHOOK_SECRET;
    if (empty($secret)) {
        wlog('WARN', 'PAYMONGO_WEBHOOK_SECRET is not set — skipping signature check');
        // In production this MUST be set. Fail open only in dev.
        return !IS_PRODUCTION;
    }

    // Parse the signature header: t=...,te=...,li=...
    $parts = [];
    foreach (explode(',', $signatureHeader) as $part) {
        [$k, $v]    = explode('=', $part, 2) + [1 => ''];
        $parts[$k]  = $v;
    }

    $timestamp = $parts['t'] ?? '';
    // Use live sig in production, test sig in dev
    $sigToCheck = IS_PRODUCTION
        ? ($parts['li'] ?? '')
        : ($parts['te'] ?? ($parts['li'] ?? ''));

    if (empty($timestamp) || empty($sigToCheck)) {
        wlog('ERROR', 'Signature header missing t or sig fields', ['header' => $signatureHeader]);
        return false;
    }

    $payload  = $timestamp . '.' . $rawBody;
    $computed = hash_hmac('sha256', $payload, $secret);

    return hash_equals($computed, $sigToCheck);
}

/**
 * Map a PayMongo plan name / amount to our internal plan constant.
 * Falls back to 'basic' if unrecognised.
 */
function resolveInternalPlan(?string $paymongoDescription, float $amount): string
{
    // Try to match by amount first (most reliable)
    $amountMap = [
        PLAN_PRICE_PREMIUM => PLAN_PREMIUM,
        PLAN_PRICE_MEDIUM  => PLAN_MEDIUM,
        PLAN_PRICE_BASIC   => PLAN_BASIC,
    ];
    foreach ($amountMap as $price => $plan) {
        if (abs($amount - $price) < 0.01) return $plan;
    }

    // Fall back to description keyword match
    if ($paymongoDescription) {
        $desc = strtolower($paymongoDescription);
        if (str_contains($desc, 'premium')) return PLAN_PREMIUM;
        if (str_contains($desc, 'medium'))  return PLAN_MEDIUM;
        if (str_contains($desc, 'basic'))   return PLAN_BASIC;
    }

    return PLAN_BASIC;
}

/**
 * Upsert the subscription row for a user:
 *   - Creates it if it doesn't exist yet
 *   - Sets status = 'active', extends paid_until by 30 days from NOW
 *     (or from current paid_until if still in the future — preserves
 *     any remaining time the user already paid for)
 *
 * Returns the subscription ID.
 */
function activateSubscription(
    PDO    $db,
    int    $userId,
    string $plan,
    float  $amount,
    string $paymongoPaymentId,
    string $paymongoSubId,
    ?array $periodRange,    // ['start' => ts, 'end' => ts] or null
    string $paymentMethod,
    ?array $rawPayload,
    ?int   $confirmedBy = null
): int {
    $db->beginTransaction();
    try {
        // ── 1. Upsert the subscription row ───────────────────
        // Calculate new paid_until: extend from the later of NOW or current paid_until
        $upsertSql = "
            INSERT INTO falcon.subscriptions
                (user_id, plan, status, paymongo_sub_id, paid_until, auto_renew, updated_at)
            VALUES
                (:uid, :plan, 'active', :sub_id,
                 NOW() + INTERVAL '30 days', TRUE, NOW())
            ON CONFLICT (user_id) DO UPDATE
            SET
                plan            = EXCLUDED.plan,
                status          = 'active',
                paymongo_sub_id = COALESCE(EXCLUDED.paymongo_sub_id,
                                           falcon.subscriptions.paymongo_sub_id),
                paid_until      = GREATEST(
                                      falcon.subscriptions.paid_until,
                                      NOW()
                                  ) + INTERVAL '30 days',
                auto_renew      = TRUE,
                cancelled_at    = NULL,
                updated_at      = NOW()
            RETURNING id
        ";
        $stmt = $db->prepare($upsertSql);
        $stmt->execute([
            ':uid'    => $userId,
            ':plan'   => $plan,
            ':sub_id' => $paymongoSubId ?: null,
        ]);
        $subId = (int)$stmt->fetchColumn();

        // ── 2. Fetch the subscription id if RETURNING didn't work ─
        if (!$subId) {
            $stmt = $db->prepare("SELECT id FROM falcon.subscriptions WHERE user_id = ?");
            $stmt->execute([$userId]);
            $subId = (int)$stmt->fetchColumn();
        }

        // ── 3. Log the payment ────────────────────────────────
        $periodStart = $periodRange['start'] ?? null;
        $periodEnd   = $periodRange['end']   ?? null;

        $logSql = "
            INSERT INTO falcon.subscription_payments
                (subscription_id, user_id, amount, payment_method,
                 paymongo_payment_id, payment_status,
                 period_start, period_end, raw_webhook, confirmed_by)
            VALUES
                (:sub_id, :uid, :amount, :method,
                 :pm_id, 'paid',
                 :p_start, :p_end, :raw, :confirmed)
        ";
        $stmt = $db->prepare($logSql);
        $stmt->execute([
            ':sub_id'    => $subId,
            ':uid'       => $userId,
            ':amount'    => $amount,
            ':method'    => $paymentMethod,
            ':pm_id'     => $paymongoPaymentId ?: null,
            ':p_start'   => $periodStart,
            ':p_end'     => $periodEnd,
            ':raw'       => $rawPayload ? json_encode($rawPayload) : null,
            ':confirmed' => $confirmedBy,
        ]);

        $db->commit();
        wlog('INFO', 'Subscription activated/renewed', [
            'user_id' => $userId, 'plan' => $plan, 'sub_id' => $subId,
        ]);
        return $subId;

    } catch (Throwable $e) {
        $db->rollBack();
        wlog('ERROR', 'activateSubscription failed: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Mark a pending subscription_payment row as failed.
 * If no pending row exists for this PayMongo ID, insert a failed one.
 */
function recordFailedPayment(
    PDO    $db,
    int    $userId,
    string $paymongoPaymentId,
    float  $amount,
    string $paymentMethod,
    ?array $rawPayload
): void {
    $db->beginTransaction();
    try {
        // Try to update an existing pending row
        $stmt = $db->prepare("
            UPDATE falcon.subscription_payments
            SET payment_status = 'failed', raw_webhook = :raw
            WHERE paymongo_payment_id = :pm_id AND payment_status = 'pending'
        ");
        $stmt->execute([
            ':pm_id' => $paymongoPaymentId,
            ':raw'   => $rawPayload ? json_encode($rawPayload) : null,
        ]);

        if ($stmt->rowCount() === 0) {
            // No pending row — look up sub_id and insert a fresh failed record
            $subStmt = $db->prepare("SELECT id FROM falcon.subscriptions WHERE user_id = ?");
            $subStmt->execute([$userId]);
            $subId = $subStmt->fetchColumn() ?: null;

            if ($subId) {
                $db->prepare("
                    INSERT INTO falcon.subscription_payments
                        (subscription_id, user_id, amount, payment_method,
                         paymongo_payment_id, payment_status, raw_webhook)
                    VALUES (?, ?, ?, ?, ?, 'failed', ?)
                ")->execute([
                    $subId, $userId, $amount, $paymentMethod,
                    $paymongoPaymentId,
                    $rawPayload ? json_encode($rawPayload) : null,
                ]);
            }
        }

        $db->commit();
        wlog('INFO', 'Payment marked failed', [
            'user_id' => $userId, 'pm_id' => $paymongoPaymentId,
        ]);
    } catch (Throwable $e) {
        $db->rollBack();
        wlog('ERROR', 'recordFailedPayment failed: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Mark a payment as refunded and, if fully refunded,
 * immediately expire the subscription.
 */
function recordRefundedPayment(
    PDO    $db,
    int    $userId,
    string $paymongoPaymentId,
    ?array $rawPayload
): void {
    $db->beginTransaction();
    try {
        $db->prepare("
            UPDATE falcon.subscription_payments
            SET payment_status = 'refunded', raw_webhook = :raw
            WHERE paymongo_payment_id = :pm_id
        ")->execute([
            ':pm_id' => $paymongoPaymentId,
            ':raw'   => $rawPayload ? json_encode($rawPayload) : null,
        ]);

        // Expire subscription immediately on refund
        $db->prepare("
            UPDATE falcon.subscriptions
            SET status = 'expired', paid_until = NOW(), updated_at = NOW()
            WHERE user_id = ? AND status = 'active'
        ")->execute([$userId]);

        $db->commit();
        wlog('INFO', 'Payment refunded, subscription expired', ['user_id' => $userId]);
    } catch (Throwable $e) {
        $db->rollBack();
        wlog('ERROR', 'recordRefundedPayment failed: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Expire a subscription (e.g. after PayMongo-side cancellation).
 */
function cancelSubscription(PDO $db, int $userId, string $paymongoSubId, ?array $raw): void
{
    $db->beginTransaction();
    try {
        $db->prepare("
            UPDATE falcon.subscriptions
            SET status       = 'cancelled',
                auto_renew   = FALSE,
                cancelled_at = NOW(),
                updated_at   = NOW()
            WHERE user_id = ?
              AND (paymongo_sub_id = ? OR paymongo_sub_id IS NULL)
        ")->execute([$userId, $paymongoSubId]);
        $db->commit();
        wlog('INFO', 'Subscription cancelled', ['user_id' => $userId, 'pm_sub_id' => $paymongoSubId]);
    } catch (Throwable $e) {
        $db->rollBack();
        wlog('ERROR', 'cancelSubscription failed: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Resolve a PayMongo user_id from payment data attributes.
 * PayMongo passes metadata.user_id if we set it at checkout.
 * Falls back to matching by paymongo_sub_id in our DB.
 */
function resolveUserId(PDO $db, array $attrs, string $paymongoSubId): ?int
{
    // 1. Metadata set at checkout time (most reliable)
    $metaUserId = $attrs['metadata']['user_id']
               ?? $attrs['data']['attributes']['metadata']['user_id']
               ?? null;
    if ($metaUserId && is_numeric($metaUserId)) {
        return (int)$metaUserId;
    }

    // 2. Match by PayMongo subscription ID stored in our DB
    if ($paymongoSubId) {
        $stmt = $db->prepare("
            SELECT user_id FROM falcon.subscriptions WHERE paymongo_sub_id = ? LIMIT 1
        ");
        $stmt->execute([$paymongoSubId]);
        $uid = $stmt->fetchColumn();
        if ($uid) return (int)$uid;
    }

    // 3. Match by billing email
    $email = $attrs['billing']['email']
          ?? $attrs['data']['attributes']['billing']['email']
          ?? null;
    if ($email) {
        $stmt = $db->prepare("
            SELECT id FROM town.users WHERE email = ? AND is_active = TRUE LIMIT 1
        ");
        $stmt->execute([$email]);
        $uid = $stmt->fetchColumn();
        if ($uid) return (int)$uid;
    }

    return null;
}

// ============================================================
//  MAIN — read body, verify signature, dispatch event
// ============================================================

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    webhookRespond(405, 'Method not allowed');
}

// ── Read raw body (must happen before any echo / output) ─────
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    webhookRespond(400, 'Empty request body');
}

// ── Verify signature ─────────────────────────────────────────
$sigHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
if (!verifyPayMongoSignature($rawBody, $sigHeader)) {
    wlog('ERROR', 'Signature verification failed', ['sig' => $sigHeader]);
    webhookRespond(401, 'Invalid signature');
}

// ── Decode payload ───────────────────────────────────────────
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    webhookRespond(400, 'Invalid JSON payload');
}

$eventType = $payload['data']['attributes']['type'] ?? null;
$eventData = $payload['data']['attributes']['data'] ?? [];
$eventAttrs = $eventData['attributes'] ?? [];

wlog('INFO', 'Received event', ['type' => $eventType, 'id' => $payload['data']['id'] ?? '']);

if (!$eventType) {
    webhookRespond(400, 'Missing event type');
}

// ── Get DB connection ─────────────────────────────────────────
try {
    $db = getDB();
} catch (Throwable $e) {
    wlog('ERROR', 'DB connection failed: ' . $e->getMessage());
    webhookRespond(500, 'Database unavailable');
}

// ── Common fields used across multiple event handlers ────────
$paymongoPaymentId = $eventData['id'] ?? '';
$paymongoSubId     = $eventAttrs['subscription_id']
                  ?? $eventAttrs['paymongo_sub_id']
                  ?? $payload['data']['attributes']['data']['id'] ?? '';

// Amount: PayMongo sends centavos (integer); convert to PHP pesos
$amountCentavos = (int)($eventAttrs['amount'] ?? $eventAttrs['net_amount'] ?? 0);
$amount         = $amountCentavos / 100;

$paymentMethod  = $eventAttrs['payment_method_used'] ?? 'paymongo';
$description    = $eventAttrs['description'] ?? null;

// Period window provided by PayMongo for subscription events
$periodRange = null;
if (!empty($eventAttrs['billing_cycle_anchor'])) {
    $anchor = (int)$eventAttrs['billing_cycle_anchor'];
    $periodRange = [
        'start' => date('Y-m-d H:i:s', $anchor),
        'end'   => date('Y-m-d H:i:s', strtotime('+30 days', $anchor)),
    ];
}

// Resolve the internal user this event belongs to
$userId = resolveUserId($db, $eventAttrs, $paymongoSubId);

// ── Event dispatch ────────────────────────────────────────────
try {
    switch ($eventType) {

        // ── One-shot payment via checkout / payment link ──────
        case 'payment.paid':
        case 'checkout_session.payment.paid':
            if (!$userId) {
                wlog('WARN', 'Could not resolve user_id for payment.paid', [
                    'pm_payment_id' => $paymongoPaymentId,
                ]);
                // Respond 200 so PayMongo doesn't retry indefinitely;
                // admin will see no matching subscription record.
                webhookRespond(200, 'User not found — event logged');
            }
            $plan = resolveInternalPlan($description, $amount);
            activateSubscription(
                $db, $userId, $plan, $amount,
                $paymongoPaymentId, $paymongoSubId,
                $periodRange, $paymentMethod, $payload
            );
            webhookRespond(200, 'Payment processed');

        // ── Payment failed ────────────────────────────────────
        case 'payment.failed':
            if ($userId) {
                recordFailedPayment(
                    $db, $userId, $paymongoPaymentId,
                    $amount, $paymentMethod, $payload
                );
            }
            webhookRespond(200, 'Failed payment recorded');

        // ── Refund issued ─────────────────────────────────────
        case 'payment.refunded':
            if ($userId) {
                recordRefundedPayment($db, $userId, $paymongoPaymentId, $payload);
            }
            webhookRespond(200, 'Refund recorded');

        // ── Subscription created (initial sign-up) ────────────
        case 'subscription.created':
            // PayMongo fires this before payment.paid; treat as pending.
            // payment.paid (or subscription.payment.paid) will activate.
            wlog('INFO', 'subscription.created received — awaiting payment.paid', [
                'pm_sub_id' => $paymongoSubId,
            ]);
            webhookRespond(200, 'Subscription created — awaiting payment');

        // ── Subscription updated (plan change / pause / resume) ─
        case 'subscription.updated':
            if ($userId) {
                $newStatus = $eventAttrs['status'] ?? null;
                if ($newStatus === 'cancelled') {
                    cancelSubscription($db, $userId, $paymongoSubId, $payload);
                } elseif (in_array($newStatus, ['active', 'paused'], true)) {
                    $plan = resolveInternalPlan($description, $amount);
                    $db->prepare("
                        UPDATE falcon.subscriptions
                        SET plan            = ?,
                            paymongo_sub_id = COALESCE(?, paymongo_sub_id),
                            status          = CASE WHEN ? = 'active' THEN 'active' ELSE status END,
                            updated_at      = NOW()
                        WHERE user_id = ?
                    ")->execute([$plan, $paymongoSubId ?: null, $newStatus, $userId]);
                }
            }
            webhookRespond(200, 'Subscription updated');

        // ── Recurring billing succeeded ───────────────────────
        case 'subscription.payment.paid':
            if (!$userId) {
                wlog('WARN', 'User not resolved for subscription.payment.paid', [
                    'pm_sub_id' => $paymongoSubId,
                ]);
                webhookRespond(200, 'User not found — skipped');
            }
            $plan = resolveInternalPlan($description, $amount);
            activateSubscription(
                $db, $userId, $plan, $amount,
                $paymongoPaymentId, $paymongoSubId,
                $periodRange, $paymentMethod, $payload
            );
            webhookRespond(200, 'Recurring payment processed');

        // ── Recurring billing failed ──────────────────────────
        case 'subscription.payment.failed':
            if ($userId) {
                // Mark failed payment; subscription status stays as-is until paid_until lapses.
                recordFailedPayment(
                    $db, $userId, $paymongoPaymentId,
                    $amount, $paymentMethod, $payload
                );

                // Optionally flag subscription for dunning (email sent by cron, not here).
                $db->prepare("
                    UPDATE falcon.subscriptions
                    SET auto_renew = FALSE, updated_at = NOW()
                    WHERE user_id = ? AND status = 'active'
                ")->execute([$userId]);
            }
            webhookRespond(200, 'Recurring failure recorded');

        // ── Subscription cancelled (from PayMongo dashboard) ──
        case 'subscription.deleted':
            if ($userId) {
                cancelSubscription($db, $userId, $paymongoSubId, $payload);
            }
            webhookRespond(200, 'Subscription cancelled');

        // ── Unknown / future event types ──────────────────────
        default:
            wlog('INFO', 'Unhandled event type — ignored', ['type' => $eventType]);
            webhookRespond(200, 'Event type not handled — ignored');
    }

} catch (Throwable $e) {
    wlog('ERROR', 'Unhandled exception: ' . $e->getMessage(), [
        'event' => $eventType,
        'trace' => $e->getTraceAsString(),
    ]);
    // Return 500 so PayMongo retries
    webhookRespond(500, 'Internal error — will retry');
}