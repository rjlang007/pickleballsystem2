<?php
// ============================================================
//  FILE: api/subscription.php
//  Step 4 — Subscription checkout API
//
//  Actions:
//    select_plan (POST) → create PayMongo checkout session
//    status (GET)       → get current subscription status
//
//  Flow:
//    1. User selects a plan on auth/subscription.php
//    2. Form POSTs to here with plan_id
//    3. We create a PayMongo source/checkout session
//    4. Redirect user to PayMongo checkout URL
//    5. After payment, PayMongo POSTs to api/paymongo_webhook.php
//    6. Webhook updates falcon.subscriptions.paid_until
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/subscription_helpers.php';

header('Content-Type: application/json');

function jsonOut(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ── Must be logged in ──────────────────────────────────────────
if (!isLoggedIn()) {
    jsonOut(['ok' => false, 'error' => 'Not authenticated'], 401);
}

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── GET: status ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'status';

    if ($action === 'status') {
        $sub = getSubscriptionStatus($db, $uid);
        jsonOut([
            'ok'   => true,
            'data' => $sub,
        ]);
    }

    jsonOut(['ok' => false, 'error' => 'Unknown action'], 400);
}

// ── POST: select_plan ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? 'select_plan';
    $planId   = (int)($_POST['plan_id'] ?? 0);
    $planName = trim($_POST['plan_name'] ?? '');

    if ($action === 'select_plan') {
        // Fetch the plan details
        $stmt = $db->prepare("
            SELECT id, name, price, period
              FROM falcon.membership_plans
             WHERE id = ? AND is_active = TRUE
             LIMIT 1
        ");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            jsonOut(['ok' => false, 'error' => 'Plan not found'], 404);
        }

        // Get user details
        $stmt = $db->prepare("
            SELECT id, email, full_name
              FROM falcon.users
             WHERE id = ?
             LIMIT 1
        ");
        $stmt->execute([$uid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            jsonOut(['ok' => false, 'error' => 'User not found'], 404);
        }

        // ── Create PayMongo Source (for one-shot payment) ───────────
        //
        // PayMongo Checkout creates a one-time payment source.
        // For recurring subscriptions, we'd use their Subscriptions API.
        // For now, this creates a one-shot checkout → webhook updates paid_until.
        //
        // Docs: https://developers.paymongo.com/docs/payments-api
        //

        if (empty(PAYMONGO_PUBLIC_KEY) || empty(PAYMONGO_SECRET_KEY)) {
            error_log('[subscription.php] PayMongo keys missing!');
            jsonOut([
                'ok'    => false,
                'error' => 'Payment gateway not configured. Contact support.',
            ], 500);
        }

        // ── Prepare checkout data ──────────────────────────────────
        $amount        = (int)(($plan['price'] * 100));  // Convert PHP to centavos
        $reference      = 'SUB-' . $uid . '-' . time();
        $successUrl     = APP_URL . '/auth/subscription.php?status=success';
        $failureUrl     = APP_URL . '/auth/subscription.php?status=failed&plan=' . $planId;
        $webhookUrl     = PAYMONGO_WEBHOOK_URL;

        $checkoutData = [
            'data' => [
                'attributes' => [
                    'amount'                => $amount,
                    'currency'              => 'PHP',
                    'description'           => $plan['name'] . ' Monthly Subscription',
                    'statement_descriptor'  => 'PADOL PICKLEBALL',
                    'reference_number'      => $reference,
                    'success_url'           => $successUrl,
                    'failure_url'           => $failureUrl,
                    'cancel_url'            => $failureUrl,
                    'line_items'            => [
                        [
                            'name'        => $plan['name'] . ' Subscription',
                            'description' => 'Monthly access to Padol Pickleball system',
                            'amount'      => $amount,
                            'currency'    => 'PHP',
                            'quantity'    => 1,
                        ]
                    ],
                    'payment_method_types'  => [
                        'gcash',
                        'paymaya',
                        'card',
                        'doku',
                        'bank_transfer',
                    ],
                    'customer_email'        => $user['email'],
                    'customer_name'         => $user['full_name'],
                ]
            ]
        ];

        // ── Call PayMongo API ──────────────────────────────────────
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.paymongo.com/v1/checkout_sessions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($checkoutData));
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, PAYMONGO_SECRET_KEY . ':');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log('[subscription.php] PayMongo curl error: ' . $curlErr);
            jsonOut([
                'ok'    => false,
                'error' => 'Payment gateway connection failed. Try again later.',
            ], 500);
        }

        $result = json_decode($response, true);

        if ($httpCode !== 200 && $httpCode !== 201) {
            error_log('[subscription.php] PayMongo API error (' . $httpCode . '): ' . $response);
            jsonOut([
                'ok'    => false,
                'error' => 'Payment gateway error. Try again later.',
            ], 500);
        }

        if (empty($result['data']['attributes']['checkout_url'])) {
            error_log('[subscription.php] No checkout URL in PayMongo response: ' . $response);
            jsonOut([
                'ok'    => false,
                'error' => 'Payment gateway returned invalid response.',
            ], 500);
        }

        $checkoutUrl = $result['data']['attributes']['checkout_url'];
        $sessionId   = $result['data']['id'] ?? null;

        // ── Store pending subscription record ───────────────────────
        // This acts as a temporary record until webhook confirms payment.
        // If payment succeeds, webhook updates paid_until.
        // If payment fails or expires, this record remains but marked as failed.

        try {
            $planKeyMap = [
                'basic'   => 'basic',
                'medium'  => 'medium',
                'premium' => 'premium',
            ];
            $planKey = strtolower(str_replace(' ', '_', $plan['name']));
            $planKey = $planKeyMap[$planKey] ?? 'basic';

            $stmt = $db->prepare("
                INSERT INTO falcon.subscriptions
                    (user_id, plan, status, paymongo_sub_id, auto_renew, created_at, updated_at)
                VALUES (?, ?, 'inactive', ?, FALSE, NOW(), NOW())
                ON CONFLICT (user_id) DO UPDATE SET
                    paymongo_sub_id = EXCLUDED.paymongo_sub_id,
                    updated_at = NOW()
            ");
            $stmt->execute([$uid, $planKey, $sessionId]);
        } catch (Throwable $e) {
            error_log('[subscription.php] Failed to create subscription record: ' . $e->getMessage());
            // Don't fail the checkout — just log and continue
        }

        // ── Redirect to PayMongo checkout ──────────────────────────
        jsonOut([
            'ok'            => true,
            'checkout_url'  => $checkoutUrl,
            'session_id'    => $sessionId,
            'message'       => 'Redirecting to PayMongo checkout…',
        ]);
    }

    jsonOut(['ok' => false, 'error' => 'Unknown action'], 400);
}

// ── Unsupported method ──────────────────────────────────────────
http_response_code(405);
jsonOut(['ok' => false, 'error' => 'Method not allowed'], 405);
