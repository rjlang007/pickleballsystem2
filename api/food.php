<?php
// ============================================================
//  FILE: api/food.php
//  Mini-restaurant / food ordering API.
//
//  GET  ?menu=1                    → public: list available food items
//  GET  ?menu=1&all=1              → admin: list ALL items (incl. hidden)
//  GET  ?categories=1              → any logged-in user: managed category list
//  GET  ?courts=1                  → any logged-in user: active courts (for "deliver to court")
//  GET  ?order=<id>                → owner or admin: single order + items
//  GET  ?orders=1                  → player: my order history
//  GET  ?orders=1&queue=1          → admin/staff: live queue (pending/preparing/ready)
//  POST ?action=checkout           → player: place an order, pay wallet or cash
//  POST ?action=save_item          → admin: create/update a menu item
//  POST ?action=delete_item        → admin: remove a menu item
//  POST ?action=save_category      → admin: create/update/reorder a menu category
//  POST ?action=delete_category    → admin: remove a menu category (must be empty)
//  POST ?action=update_status      → admin/staff: advance an order's status
//  POST ?action=mark_paid          → admin/staff: mark a "pay at counter" order paid
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/api_response.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$rlKey = 'api_food_' . getClientIp();
if (!checkRateLimit($rlKey, 60, 60)) {
    header('Retry-After: 60');
    apiError('RATE_LIMITED', 'Too many requests. Please wait.', [], 429);
}

if (!isLoggedIn()) {
    apiError('UNAUTHORIZED', 'Not logged in.', [], 401);
}

$db     = getDB();
$uid    = (int)$_SESSION['user_id'];
$admin  = isAdmin();
$staff  = isStaff(); // staff, admin, and super_admin can all run the food counter
$method = $_SERVER['REQUEST_METHOD'];
verifySameOrigin();

/** Generates a short, human-friendly order number like "A-0042". */
function generateOrderNumber(PDO $db): string {
    $n = (int)$db->query("SELECT nextval('falcon.food_order_number_seq')")->fetchColumn();
    return 'A-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

// ─────────────────────────────────────────────────────────────
//  GET
// ─────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // GET ?menu=1
    if (isset($_GET['menu'])) {
        $showAll = isset($_GET['all']) && $admin;
        $sql = "SELECT fi.id, fi.name, fi.description, fi.price, fi.image, fi.is_available, fi.sort_order,
                       fi.category_id, COALESCE(fc.name, fi.category, 'Main') AS category,
                       COALESCE(fc.sort_order, 999) AS category_sort_order
                  FROM falcon.food_items fi
             LEFT JOIN falcon.food_categories fc ON fc.id = fi.category_id";
        if (!$showAll) {
            $sql .= " WHERE fi.is_available = TRUE";
        }
        $sql .= " ORDER BY category_sort_order, category, fi.sort_order, fi.name";
        apiSuccess(['data' => $db->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // GET ?categories=1 — the managed category list (used by the admin
    // menu editor's dropdown, and available to anyone for display purposes)
    if (isset($_GET['categories'])) {
        $rows = $db->query(
            "SELECT id, name, sort_order FROM falcon.food_categories ORDER BY sort_order, name"
        )->fetchAll(PDO::FETCH_ASSOC);
        apiSuccess(['data' => $rows]);
    }

    // GET ?courts=1 — active courts, for the "deliver to my court" picker
    if (isset($_GET['courts'])) {
        $rows = $db->query(
            "SELECT id, name, short_code FROM falcon.courts WHERE is_active = TRUE ORDER BY sort_order, id"
        )->fetchAll(PDO::FETCH_ASSOC);
        apiSuccess(['data' => $rows]);
    }

    // GET ?order=<id>
    if (isset($_GET['order'])) {
        $orderId = (int)$_GET['order'];
        $stmt = $db->prepare(
            "SELECT o.*, u.username, u.full_name, c.name AS court_name
               FROM falcon.food_orders o
               JOIN falcon.users u ON u.id = o.user_id
          LEFT JOIN falcon.courts c ON c.id = o.court_id
              WHERE o.id = ? LIMIT 1"
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) apiError('NOT_FOUND', 'Order not found.', [], 404);
        if (!$admin && (int)$order['user_id'] !== $uid) {
            apiError('FORBIDDEN', 'Not your order.', [], 403);
        }
        $itemsStmt = $db->prepare(
            "SELECT item_name, unit_price, quantity, subtotal
               FROM falcon.food_order_items WHERE order_id = ? ORDER BY id"
        );
        $itemsStmt->execute([$orderId]);
        $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        apiSuccess(['data' => $order]);
    }

    // GET ?orders=1
    if (isset($_GET['orders'])) {
        $queueOnly = isset($_GET['queue']) && $staff;

        if ($queueOnly) {
            $stmt = $db->query(
                "SELECT o.id, o.order_number, o.status, o.payment_method, o.payment_status,
                        o.fulfillment_type, o.court_id, c.name AS court_name,
                        o.total_amount, o.notes, o.created_at, o.ready_at,
                        u.username, u.full_name,
                        ROUND(EXTRACT(EPOCH FROM (NOW() - o.created_at)) / 60)::int AS age_minutes
                   FROM falcon.food_orders o
                   JOIN falcon.users u ON u.id = o.user_id
              LEFT JOIN falcon.courts c ON c.id = o.court_id
                  WHERE o.status IN ('pending','preparing','ready')
                  ORDER BY o.created_at ASC"
            );
        } else {
            $stmt = $db->prepare(
                "SELECT o.id, o.order_number, o.status, o.payment_method, o.payment_status,
                        o.fulfillment_type, o.court_id, c.name AS court_name,
                        o.total_amount, o.notes, o.created_at, o.ready_at, o.completed_at
                   FROM falcon.food_orders o
              LEFT JOIN falcon.courts c ON c.id = o.court_id
                  WHERE o.user_id = ?
                  ORDER BY o.created_at DESC
                  LIMIT 50"
            );
            $stmt->execute([$uid]);
        }
        apiSuccess(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    apiError('BAD_REQUEST', 'Unknown GET action.', [], 400);
}

// ─────────────────────────────────────────────────────────────
//  POST
// ─────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $body['action'] ?? ($_GET['action'] ?? '');

    // ── Player: place an order ──────────────────────────────
    if ($action === 'checkout') {
        $cart = $body['items'] ?? [];
        $payMethod = ($body['payment_method'] ?? 'wallet') === 'cash' ? 'cash' : 'wallet';
        $notes = trim((string)($body['notes'] ?? ''));
        if ($notes !== '') $notes = mb_substr($notes, 0, 255);

        // ── Fulfillment: pickup at the counter, or delivered to their court ──
        $fulfillment = ($body['fulfillment_type'] ?? 'pickup') === 'court' ? 'court' : 'pickup';
        $courtId = null;
        if ($fulfillment === 'court') {
            $courtId = (int)($body['court_id'] ?? 0);
            if ($courtId <= 0) {
                apiError('VALIDATION', 'Please select which court to deliver to.', [], 422);
            }
            $courtCheck = $db->prepare("SELECT id FROM falcon.courts WHERE id = ? AND is_active = TRUE LIMIT 1");
            $courtCheck->execute([$courtId]);
            if (!$courtCheck->fetchColumn()) {
                apiError('VALIDATION', 'Selected court is not available.', [], 422);
            }
        }

        if (!is_array($cart) || empty($cart)) {
            apiError('VALIDATION', 'Your cart is empty.', [], 422);
        }

        // Re-price every item server-side from the DB (never trust client prices)
        $ids = array_map(fn($c) => (int)($c['id'] ?? 0), $cart);
        $ids = array_values(array_filter($ids));
        if (empty($ids)) apiError('VALIDATION', 'Invalid cart.', [], 422);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT id, name, price, is_available FROM falcon.food_items WHERE id IN ($placeholders)"
        );
        $stmt->execute($ids);
        $menuById = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $menuById[(int)$row['id']] = $row;
        }

        $lineItems = [];
        $total = 0.0;
        foreach ($cart as $c) {
            $itemId = (int)($c['id'] ?? 0);
            $qty    = max(1, min(50, (int)($c['quantity'] ?? 1)));
            if (!isset($menuById[$itemId])) {
                apiError('VALIDATION', 'One of the items is no longer on the menu.', [], 422);
            }
            $item = $menuById[$itemId];
            if (!$item['is_available']) {
                apiError('VALIDATION', "\"{$item['name']}\" is currently unavailable.", [], 422);
            }
            $unitPrice = (float)$item['price'];
            $subtotal  = round($unitPrice * $qty, 2);
            $total    += $subtotal;
            $lineItems[] = [
                'food_item_id' => $itemId,
                'item_name'    => $item['name'],
                'unit_price'   => $unitPrice,
                'quantity'     => $qty,
                'subtotal'     => $subtotal,
            ];
        }
        $total = round($total, 2);

        try {
            $db->beginTransaction();

            $paymentStatus = 'unpaid';

            if ($payMethod === 'wallet') {
                // Check + deduct wallet balance atomically (mirrors booking_state_machine.php)
                $walletStmt = $db->prepare(
                    "SELECT COALESCE(balance,0) AS balance FROM falcon.wallets WHERE user_id = ? FOR UPDATE"
                );
                $walletStmt->execute([$uid]);
                $balanceBefore = (float)($walletStmt->fetchColumn() ?: 0);

                if ($balanceBefore < $total) {
                    $db->rollBack();
                    apiError('INSUFFICIENT_FUNDS', 'Insufficient wallet balance. Please top up or choose Pay at Counter.', [
                        'balance' => $balanceBefore,
                        'required' => $total,
                    ], 402);
                }

                $upd = $db->prepare(
                    "UPDATE falcon.wallets SET balance = balance - :amt, updated_at = NOW()
                      WHERE user_id = :uid AND balance >= :amt"
                );
                $upd->execute([':amt' => $total, ':uid' => $uid]);
                if ($upd->rowCount() !== 1) {
                    $db->rollBack();
                    apiError('INSUFFICIENT_FUNDS', 'Insufficient wallet balance.', [], 402);
                }
                $paymentStatus = 'paid';
            }

            $orderNumber = generateOrderNumber($db);
            $orderStmt = $db->prepare(
                "INSERT INTO falcon.food_orders
                    (order_number, user_id, status, payment_method, payment_status,
                     fulfillment_type, court_id, subtotal, total_amount, notes, created_at, updated_at)
                 VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                 RETURNING id"
            );
            $orderStmt->execute([$orderNumber, $uid, $payMethod, $paymentStatus, $fulfillment, $courtId, $total, $total, $notes ?: null]);
            $orderId = (int)$orderStmt->fetchColumn();

            $itemStmt = $db->prepare(
                "INSERT INTO falcon.food_order_items (order_id, food_item_id, item_name, unit_price, quantity, subtotal)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($lineItems as $li) {
                $itemStmt->execute([$orderId, $li['food_item_id'], $li['item_name'], $li['unit_price'], $li['quantity'], $li['subtotal']]);
            }

            if ($payMethod === 'wallet') {
                $balanceAfter = $balanceBefore - $total;
                $db->prepare(
                    "INSERT INTO falcon.transactions
                        (user_id, type, amount, reason, related_table, related_id, balance_before, balance_after, created_at)
                     VALUES (?, 'food_order_charge', ?, ?, 'food_orders', ?, ?, ?, NOW())"
                )->execute([$uid, $total, "Food order {$orderNumber}", $orderId, $balanceBefore, $balanceAfter]);
            }

            $fulfillmentLabel = $fulfillment === 'court' ? 'deliver to court' : 'pickup at counter';
            notifyAdmins($db, '🍔 New Food Order', "Order {$orderNumber} placed ({$fulfillmentLabel}) — ₱" . number_format($total, 2));

            $db->commit();

            // Confirmation notification back to the customer, so the order
            // number is available both on-screen (checkout response) and
            // in their notification bell / history.
            notifyUser(
                $db, $uid,
                '🧾 Order placed',
                "Order {$orderNumber} received — we'll notify you when it's ready.",
                null,
                APP_URL . '/player/food_orders.php'
            );

            apiSuccess(['data' => [
                'order_id'         => $orderId,
                'order_number'     => $orderNumber,
                'total_amount'     => $total,
                'payment_status'   => $paymentStatus,
                'fulfillment_type' => $fulfillment,
            ]]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('food checkout failed: ' . $e->getMessage());
            apiError('SERVER_ERROR', 'Could not place your order. Please try again.', [], 500);
        }
    }

    // ── Menu management: admin (owner) only ─────────────────
    if (in_array($action, ['save_item', 'delete_item', 'save_category', 'delete_category'], true) && !$admin) {
        apiError('FORBIDDEN', 'Admin access required.', [], 403);
    }

    // ── Order fulfillment: staff running the counter, or admin ──
    if (in_array($action, ['update_status', 'mark_paid'], true) && !$staff) {
        apiError('FORBIDDEN', 'Staff access required.', [], 403);
    }

    if ($action === 'save_item') {
        $id          = (int)($body['id'] ?? 0);
        $name        = trim((string)($body['name'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $price       = (float)($body['price'] ?? 0);
        $categoryId  = (int)($body['category_id'] ?? 0);
        $isAvailable = !empty($body['is_available']);
        $sortOrder   = (int)($body['sort_order'] ?? 0);

        if ($name === '' || $price < 0) {
            apiError('VALIDATION', 'Item name and a valid price are required.', [], 422);
        }

        if ($categoryId <= 0) {
            apiError('VALIDATION', 'Please choose a category.', [], 422);
        }
        $catCheck = $db->prepare("SELECT name FROM falcon.food_categories WHERE id = ? LIMIT 1");
        $catCheck->execute([$categoryId]);
        $categoryName = $catCheck->fetchColumn();
        if ($categoryName === false) {
            apiError('VALIDATION', 'Selected category no longer exists.', [], 422);
        }

        // ── Optional image upload ────────────────────────────
        $existingImage = trim((string)($body['existing_image'] ?? ''));
        $image = $existingImage !== '' ? $existingImage : null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $foodUploadDir = APP_ROOT . '/Uploads/food/';
            if (!is_dir($foodUploadDir)) @mkdir($foodUploadDir, 0755, true);
            $saved = moveUploadedImageSafe($_FILES['image'], $foodUploadDir, 'food');
            if ($saved === null) {
                apiError('VALIDATION', 'Image upload failed. Please use a JPG, PNG, WEBP, or GIF under 5MB.', [], 422);
            }
            // Remove the old image file once the new one is safely saved
            if ($existingImage !== '' && $existingImage !== $saved) {
                $oldPath = $foodUploadDir . basename($existingImage);
                if (is_file($oldPath)) @unlink($oldPath);
            }
            $image = $saved;
        }

        // `category` text column is kept in sync for backward compatibility
        // (older reports/queries), but `category_id` is the source of truth now.
        if ($id > 0) {
            $db->prepare(
                "UPDATE falcon.food_items
                    SET name=?, description=?, price=?, category=?, category_id=?, image=?, is_available=?, sort_order=?, updated_at=NOW()
                  WHERE id=?"
            )->execute([$name, $description, $price, $categoryName, $categoryId, $image, $isAvailable, $sortOrder, $id]);
        } else {
            $stmt = $db->prepare(
                "INSERT INTO falcon.food_items
                    (name, description, price, category, category_id, image, is_available, sort_order, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                 RETURNING id"
            );
            $stmt->execute([$name, $description, $price, $categoryName, $categoryId, $image, $isAvailable, $sortOrder, $uid]);
            $id = (int)$stmt->fetchColumn();
        }

        apiSuccess(['data' => ['id' => $id, 'image' => $image]]);
    }

    if ($action === 'delete_item') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) apiError('VALIDATION', 'Missing item id.', [], 422);
        $row = $db->prepare("SELECT image FROM falcon.food_items WHERE id = ?");
        $row->execute([$id]);
        $existing = $row->fetch(PDO::FETCH_ASSOC);
        $db->prepare("DELETE FROM falcon.food_items WHERE id = ?")->execute([$id]);
        if ($existing && !empty($existing['image'])) {
            $imgPath = APP_ROOT . '/Uploads/food/' . basename($existing['image']);
            if (is_file($imgPath)) @unlink($imgPath);
        }
        apiSuccess(['data' => ['deleted' => $id]]);
    }

    // ── Category management: admin only ──────────────────────
    if ($action === 'save_category') {
        $id        = (int)($body['id'] ?? 0);
        $name      = trim((string)($body['name'] ?? ''));
        $sortOrder = (int)($body['sort_order'] ?? 0);

        if ($name === '') {
            apiError('VALIDATION', 'Category name is required.', [], 422);
        }
        $name = mb_substr($name, 0, 100);

        // Case-insensitive duplicate check (this is the whole point of
        // moving off free-text categories — no more "Drinks" / "drinks")
        $dupStmt = $db->prepare(
            "SELECT id FROM falcon.food_categories WHERE LOWER(name) = LOWER(?) AND id <> ? LIMIT 1"
        );
        $dupStmt->execute([$name, $id]);
        if ($dupStmt->fetchColumn()) {
            apiError('VALIDATION', 'A category with that name already exists.', [], 422);
        }

        try {
            if ($id > 0) {
                $db->prepare(
                    "UPDATE falcon.food_categories SET name=?, sort_order=?, updated_at=NOW() WHERE id=?"
                )->execute([$name, $sortOrder, $id]);

                // Keep the denormalized text column on food_items in sync
                $db->prepare(
                    "UPDATE falcon.food_items SET category=? WHERE category_id=?"
                )->execute([$name, $id]);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO falcon.food_categories (name, sort_order, created_at, updated_at)
                     VALUES (?, ?, NOW(), NOW()) RETURNING id"
                );
                $stmt->execute([$name, $sortOrder]);
                $id = (int)$stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            error_log('food save_category failed: ' . $e->getMessage());
            apiError('SERVER_ERROR', 'Could not save the category.', [], 500);
        }

        apiSuccess(['data' => ['id' => $id, 'name' => $name, 'sort_order' => $sortOrder]]);
    }

    if ($action === 'delete_category') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) apiError('VALIDATION', 'Missing category id.', [], 422);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM falcon.food_items WHERE category_id = ?");
        $countStmt->execute([$id]);
        if ((int)$countStmt->fetchColumn() > 0) {
            apiError('VALIDATION', 'Move or delete the items in this category first.', [], 422);
        }

        $totalStmt = $db->query("SELECT COUNT(*) FROM falcon.food_categories");
        if ((int)$totalStmt->fetchColumn() <= 1) {
            apiError('VALIDATION', 'You need at least one category.', [], 422);
        }

        $db->prepare("DELETE FROM falcon.food_categories WHERE id = ?")->execute([$id]);
        apiSuccess(['data' => ['deleted' => $id]]);
    }

    if ($action === 'update_status') {
        $orderId   = (int)($body['order_id'] ?? 0);
        $newStatus = (string)($body['status'] ?? '');
        $allowed   = ['pending', 'preparing', 'ready', 'completed', 'cancelled'];

        if ($orderId <= 0 || !in_array($newStatus, $allowed, true)) {
            apiError('VALIDATION', 'Invalid order or status.', [], 422);
        }

        $stmt = $db->prepare("SELECT * FROM falcon.food_orders WHERE id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) apiError('NOT_FOUND', 'Order not found.', [], 404);

        try {
            $db->beginTransaction();

            $extraSql = '';
            if ($newStatus === 'ready')     $extraSql = ", ready_at = NOW()";
            if ($newStatus === 'completed') $extraSql = ", completed_at = NOW()";
            if ($newStatus === 'cancelled') $extraSql = ", cancelled_at = NOW()";

            $db->prepare("UPDATE falcon.food_orders SET status = ?, updated_at = NOW() $extraSql WHERE id = ?")
               ->execute([$newStatus, $orderId]);

            // Refund to wallet if a paid order is cancelled
            if ($newStatus === 'cancelled' && $order['payment_method'] === 'wallet' && $order['payment_status'] === 'paid') {
                $walletStmt = $db->prepare("SELECT COALESCE(balance,0) AS balance FROM falcon.wallets WHERE user_id = ? FOR UPDATE");
                $walletStmt->execute([$order['user_id']]);
                $balanceBefore = (float)($walletStmt->fetchColumn() ?: 0);
                $refund = (float)$order['total_amount'];

                $db->prepare("UPDATE falcon.wallets SET balance = balance + ?, updated_at = NOW() WHERE user_id = ?")
                   ->execute([$refund, $order['user_id']]);

                $db->prepare(
                    "INSERT INTO falcon.transactions
                        (user_id, type, amount, reason, related_table, related_id, balance_before, balance_after, created_at)
                     VALUES (?, 'food_order_refund', ?, ?, 'food_orders', ?, ?, ?, NOW())"
                )->execute([$order['user_id'], $refund, "Refund for cancelled order {$order['order_number']}", $orderId, $balanceBefore, $balanceBefore + $refund]);

                $db->prepare("UPDATE falcon.food_orders SET payment_status='refunded' WHERE id=?")->execute([$orderId]);
            }

            $db->commit();

            // Notify the customer once their order is ready to pick up
            if ($newStatus === 'ready') {
                notifyUser(
                    $db, (int)$order['user_id'],
                    '🍽️ Your order is ready!',
                    "Order {$order['order_number']} is ready for pickup.",
                    null,
                    APP_URL . '/player/food_orders.php'
                );
            } elseif ($newStatus === 'cancelled') {
                notifyUser(
                    $db, (int)$order['user_id'],
                    'Order Cancelled',
                    "Order {$order['order_number']} was cancelled." . ($order['payment_method'] === 'wallet' ? ' Your credits have been refunded.' : ''),
                    null,
                    APP_URL . '/player/food_orders.php'
                );
            }

            apiSuccess(['data' => ['order_id' => $orderId, 'status' => $newStatus]]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('food status update failed: ' . $e->getMessage());
            apiError('SERVER_ERROR', 'Could not update order status.', [], 500);
        }
    }

    // ── Staff: mark a "pay at counter" (cash) order as paid ──
    if ($action === 'mark_paid') {
        $orderId = (int)($body['order_id'] ?? 0);
        if ($orderId <= 0) apiError('VALIDATION', 'Missing order id.', [], 422);

        $stmt = $db->prepare("SELECT id, payment_method, payment_status FROM falcon.food_orders WHERE id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) apiError('NOT_FOUND', 'Order not found.', [], 404);
        if ($order['payment_method'] !== 'cash') {
            apiError('VALIDATION', 'Only cash orders can be marked paid this way.', [], 422);
        }
        if ($order['payment_status'] === 'paid') {
            apiSuccess(['data' => ['order_id' => $orderId, 'payment_status' => 'paid']]);
        }

        $db->prepare("UPDATE falcon.food_orders SET payment_status = 'paid', updated_at = NOW() WHERE id = ?")
           ->execute([$orderId]);

        apiSuccess(['data' => ['order_id' => $orderId, 'payment_status' => 'paid']]);
    }

    apiError('BAD_REQUEST', 'Unknown action.', [], 400);
}

apiError('METHOD_NOT_ALLOWED', 'Method not allowed.', [], 405);