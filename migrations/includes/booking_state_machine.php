<?php
// ============================================================
//  FILE: includes/booking_state_machine.php
// ============================================================
if (defined('BOOKING_STATE_MACHINE_LOADED')) return;
define('BOOKING_STATE_MACHINE_LOADED', true);

class BookingStateMachine {
    public static function transition(int $reservationId, string $targetStatus, array $options = []): array {
        try {
            $db = getDB();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB_ERROR', 'message' => 'Unable to connect to the database.'];
        }

        $actorId = isset($options['actor_id']) ? (int)$options['actor_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
        $actorRole = currentUserRole();
        $actorIp = getClientIp();
        $actorAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        if (($actorId === null || $actorId <= 0) && empty($options['auto'])) {
            return ['ok' => false, 'error' => 'UNAUTHORIZED', 'message' => 'Actor must be logged in.'];
        }

        $reservationStmt = $db->prepare(
            "SELECT r.*, c.credit_cost, c.max_queue
               FROM falcon.reservations r
               JOIN falcon.courts c ON c.id = r.court_id
              WHERE r.id = ?
              LIMIT 1"
        );
        $reservationStmt->execute([$reservationId]);
        $reservation = $reservationStmt->fetch(PDO::FETCH_ASSOC);

        if (!$reservation) {
            return ['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'Reservation not found.'];
        }

        $currentStatus = $reservation['status'];
        $playerId = (int)$reservation['user_id'];
        $isOwner = $actorId === $playerId;
        $isAdmin = in_array($actorRole, ADMIN_ROLES, true);

        $slotDateTime = null;
        try {
            $slotDateTime = new DateTime("{$reservation['slot_date']} {$reservation['slot_time']}");
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'INVALID_RESERVATION', 'message' => 'Reservation slot data is invalid.'];
        }

        $now = new DateTime();
        $minutesUntilSlot = (int)round(($slotDateTime->getTimestamp() - $now->getTimestamp()) / 60);
        $isWithin24h = $minutesUntilSlot < 24 * 60;
        $isWithin48hAfter = ($now->getTimestamp() - $slotDateTime->getTimestamp()) <= 48 * 3600;

        $targetStatus = strtolower(trim($targetStatus));
        $validStatuses = ['pending', 'confirmed', 'cancelled', 'completed', 'no_show', 'disputed'];
        if (!in_array($targetStatus, $validStatuses, true)) {
            return ['ok' => false, 'error' => 'INVALID_STATUS', 'message' => 'Status is not valid.'];
        }

        $oldData = $reservation;
        $db->beginTransaction();

        try {
            $notificationTitle = '';
            $notificationMessage = '';
            $transactionPayload = null;
            $auditNotes = [];

            switch ("{$currentStatus}_to_{$targetStatus}") {
                case 'pending_to_confirmed':
                    if (!$isAdmin) {
                        throw new RuntimeException('Only admins can confirm pending bookings.', 403);
                    }

                    $totalCost = (float)$reservation['credit_cost'] * (int)$reservation['party_size'];
                    $walletStmt = $db->prepare(
                        "SELECT balance FROM falcon.wallets WHERE user_id = ? FOR UPDATE"
                    );
                    $walletStmt->execute([$playerId]);
                    $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
                    $balanceBefore = $wallet ? (float)$wallet['balance'] : 0.0;

                    if ($balanceBefore < $totalCost) {
                        throw new RuntimeException('Insufficient wallet balance to confirm reservation.', 402);
                    }

                    $walletUpdate = $db->prepare(
                        "UPDATE falcon.wallets SET balance = balance - :cost, updated_at = NOW() WHERE user_id = :uid AND balance >= :cost"
                    );
                    $walletUpdate->execute([':cost' => $totalCost, ':uid' => $playerId]);
                    if ($walletUpdate->rowCount() !== 1) {
                        throw new RuntimeException('Unable to deduct reservation credits.', 402);
                    }

                    $balanceAfter = $balanceBefore - $totalCost;
                    $db->prepare(
                        "INSERT INTO falcon.transactions
                            (user_id, type, amount, reason, related_table, related_id, balance_before, balance_after, created_at)
                         VALUES
                            (:uid, 'reservation_charge', :amt, :reason, 'reservations', :rid, :before, :after, NOW())"
                    )->execute([
                        ':uid' => $playerId,
                        ':amt' => $totalCost,
                        ':reason' => 'Confirmed reservation charge',
                        ':rid' => $reservationId,
                        ':before' => $balanceBefore,
                        ':after' => $balanceAfter,
                    ]);

                    $notificationTitle = 'Booking Confirmed';
                    $notificationMessage = 'Your reservation has been confirmed.';
                    $transactionPayload = ['type' => 'reservation_charge', 'amount' => $totalCost];
                    $db->prepare(
                        "UPDATE falcon.reservations
                            SET status = 'confirmed', booking_confirmed_at = NOW(), booking_confirmed_by = ?, payment_status = 'paid', updated_at = NOW()
                          WHERE id = ?"
                    )->execute([$actorId, $reservationId]);
                    break;

                case 'pending_to_cancelled':
                    if (!$isOwner && !$isAdmin) {
                        throw new RuntimeException('Only the player or admin may cancel this reservation.', 403);
                    }

                    $notificationTitle = 'Booking Cancelled';
                    $notificationMessage = 'Your pending reservation has been cancelled.';
                    $db->prepare(
                        "UPDATE falcon.reservations
                            SET status = 'cancelled', updated_at = NOW()
                          WHERE id = ?"
                    )->execute([$reservationId]);
                    break;

                case 'confirmed_to_cancelled':
                    if (!$isAdmin && !$isOwner) {
                        throw new RuntimeException('Only the player or admin may cancel this confirmed reservation.', 403);
                    }

                    $shouldRefund = !$isWithin24h;
                    if ($shouldRefund) {
                        $totalCost = (float)$reservation['credit_cost'] * (int)$reservation['party_size'];
                        $walletStmt = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ? FOR UPDATE");
                        $walletStmt->execute([$playerId]);
                        $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
                        $balanceBefore = $wallet ? (float)$wallet['balance'] : 0.0;
                        $balanceAfter = $balanceBefore + $totalCost;

                        $db->prepare(
                            "UPDATE falcon.wallets SET balance = balance + :amount, updated_at = NOW() WHERE user_id = :uid"
                        )->execute([':amount' => $totalCost, ':uid' => $playerId]);

                        $db->prepare(
                            "INSERT INTO falcon.transactions
                                (user_id, type, amount, reason, related_table, related_id, balance_before, balance_after, created_at)
                             VALUES
                                (:uid, 'reservation_refund', :amt, :reason, 'reservations', :rid, :before, :after, NOW())"
                        )->execute([
                            ':uid' => $playerId,
                            ':amt' => $totalCost,
                            ':reason' => 'Refund for cancelled reservation',
                            ':rid' => $reservationId,
                            ':before' => $balanceBefore,
                            ':after' => $balanceAfter,
                        ]);

                        $transactionPayload = ['type' => 'reservation_refund', 'amount' => $totalCost];
                        $notificationTitle = 'Booking Cancelled';
                        $notificationMessage = 'Your confirmed reservation was cancelled and refunded.';
                    } else {
                        $notificationTitle = 'Booking Cancelled';
                        $notificationMessage = 'Your confirmed reservation was cancelled. No refund was issued due to the cancellation window.';
                        $walletStmt = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ? FOR UPDATE");
                        $walletStmt->execute([$playerId]);
                        $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
                        $balanceBefore = $wallet ? (float)$wallet['balance'] : 0.0;

                        $db->prepare(
                            "INSERT INTO falcon.transactions
                                (user_id, type, amount, reason, related_table, related_id, balance_before, balance_after, created_at)
                             VALUES
                                (:uid, 'reservation_no_refund', 0.00, :reason, 'reservations', :rid, :before, :after, NOW())"
                        )->execute([
                            ':uid' => $playerId,
                            ':reason' => 'Cancelled reservation within 24 hours, no refund issued',
                            ':rid' => $reservationId,
                            ':before' => $balanceBefore,
                            ':after' => $balanceBefore,
                        ]);
                    }

                    $db->prepare(
                        "UPDATE falcon.reservations
                            SET status = 'cancelled', updated_at = NOW()
                          WHERE id = ?"
                    )->execute([$reservationId]);
                    break;

                case 'confirmed_to_completed':
                    if (!$isAdmin && empty($options['auto'])) {
                        throw new RuntimeException('Only admins may complete this reservation.', 403);
                    }

                    $notificationTitle = 'Booking Completed';
                    $notificationMessage = 'Your booking has been marked completed.';

                    self::releaseCourtOwnerPayment($db, $reservation);

                    $db->prepare(
                        "UPDATE falcon.reservations
                            SET status = 'completed', updated_at = NOW()
                          WHERE id = ?"
                    )->execute([$reservationId]);
                    break;

                case 'confirmed_to_no_show':
                    if (!$isAdmin) {
                        throw new RuntimeException('Only admins may mark no-show.', 403);
                    }

                    $notificationTitle = 'Marked No-Show';
                    $notificationMessage = 'Your booking was marked as no-show.';
                    $db->prepare(
                        "INSERT INTO falcon.transactions
                            (user_id, type, amount, reason, related_table, related_id, balance_before, balance_after, created_at)
                         VALUES
                            (:uid, 'no_show', 0.00, :reason, 'reservations', :rid, :before, :after, NOW())"
                    )->execute([
                        ':uid' => $playerId,
                        ':reason' => 'No refund issued for no-show reservation',
                        ':rid' => $reservationId,
                        ':before' => (float)($reservation['payment_amount'] ?? 0.0),
                        ':after' => (float)($reservation['payment_amount'] ?? 0.0),
                    ]);

                    $db->prepare(
                        "UPDATE falcon.reservations
                            SET status = 'no_show', updated_at = NOW()
                          WHERE id = ?"
                    )->execute([$reservationId]);
                    break;

                case 'no_show_to_disputed':
                    if (!$isOwner) {
                        throw new RuntimeException('Only the original player may file a dispute.', 403);
                    }
                    if (!$isWithin48hAfter) {
                        throw new RuntimeException('Disputes must be filed within 48 hours.', 409);
                    }

                    $reason = trim((string)($options['reason'] ?? '')); 
                    if ($reason === '') {
                        throw new RuntimeException('A dispute reason is required.', 422);
                    }

                    $existsStmt = $db->prepare(
                        "SELECT id FROM falcon.disputes WHERE reservation_id = ? LIMIT 1"
                    );
                    $existsStmt->execute([$reservationId]);
                    if ($existsStmt->fetch()) {
                        throw new RuntimeException('A dispute has already been filed for this reservation.', 409);
                    }

                    $db->prepare(
                        "INSERT INTO falcon.disputes
                            (reservation_id, filed_by, reason, status, created_at)
                         VALUES (?, ?, ?, 'open', NOW())"
                    )->execute([$reservationId, $actorId, $reason]);

                    $notificationTitle = 'Dispute Filed';
                    $notificationMessage = 'Your dispute has been submitted for review.';
                    $db->prepare(
                        "UPDATE falcon.reservations
                            SET status = 'disputed', updated_at = NOW()
                          WHERE id = ?"
                    )->execute([$reservationId]);
                    break;

                default:
                    throw new RuntimeException('Transition not allowed.', 400);
            }

            $db->prepare(
                "INSERT INTO falcon.notifications
                    (user_id, title, message, type, reservation_id, created_at)
                 VALUES (?, ?, ?, 'info', ?, NOW())"
            )->execute([$playerId, $notificationTitle, $notificationMessage, $reservationId]);

            $newReservation = $db->prepare("SELECT * FROM falcon.reservations WHERE id = ? LIMIT 1");
            $newReservation->execute([$reservationId]);
            $newData = $newReservation->fetch(PDO::FETCH_ASSOC) ?: [];

            $db->prepare(
                "INSERT INTO falcon.audit_log
                    (user_id, ip_address, user_agent, action, table_name, record_id, old_data, new_data, result, notes, created_at)
                 VALUES
                    (:actor_id, CAST(:ip_address AS inet), :user_agent, :action, 'reservations', :record_id, :old_data, :new_data, :result, :notes, NOW())"
            )->execute([
                ':actor_id' => ($actorId !== null && $actorId > 0) ? $actorId : null,
                ':ip_address' => $actorIp,
                ':user_agent' => $actorAgent,
                ':action' => "reservation_status_{$currentStatus}_to_{$targetStatus}",
                ':record_id' => $reservationId,
                ':old_data' => json_encode($oldData, JSON_UNESCAPED_UNICODE),
                ':new_data' => json_encode($newData, JSON_UNESCAPED_UNICODE),
                ':result' => 'success',
                ':notes' => implode(' | ', $auditNotes),
            ]);

            $db->commit();
            return ['ok' => true, 'message' => 'Reservation status updated.', 'data' => ['reservation_id' => $reservationId, 'status' => $targetStatus]];
        } catch (RuntimeException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $code = match ($e->getCode()) {
                402 => 'INSUFFICIENT_FUNDS',
                403 => 'FORBIDDEN',
                409 => 'CONFLICT',
                422 => 'VALIDATION_ERROR',
                default => 'INVALID_TRANSITION',
            };
            return ['ok' => false, 'error' => $code, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[BookingStateMachine] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'SERVER_ERROR', 'message' => 'Failed to transition reservation.'];
        }
    }

    private static function releaseCourtOwnerPayment(PDO $db, array $reservation): void {
        try {
            $ownerColumn = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'falcon' AND table_name = 'courts' AND column_name = 'owner_user_id'")->fetchColumn();
            if (!$ownerColumn) {
                return;
            }

            $ownerStmt = $db->prepare("SELECT owner_user_id FROM falcon.courts WHERE id = ? LIMIT 1");
            $ownerStmt->execute([$reservation['court_id']]);
            $ownerId = (int)$ownerStmt->fetchColumn();
            if ($ownerId <= 0) {
                return;
            }

            $amount = (float)$reservation['credit_cost'] * (int)$reservation['party_size'];
            $db->prepare("UPDATE falcon.wallets SET balance = balance + :amount, updated_at = NOW() WHERE user_id = :uid")->execute([':amount' => $amount, ':uid' => $ownerId]);
            $db->prepare(
                "INSERT INTO falcon.transactions
                    (user_id, type, amount, reason, related_table, related_id, balance_before, balance_after, created_at)
                 VALUES
                    (:uid, 'court_owner_payout', :amt, :reason, 'reservations', :rid, NULL, NULL, NOW())"
            )->execute([
                ':uid' => $ownerId,
                ':amt' => $amount,
                ':reason' => 'Court payout for completed reservation',
                ':rid' => $reservation['id'],
            ]);
        } catch (Throwable $e) {
            error_log('[BookingStateMachine] court owner payment skipped: ' . $e->getMessage());
        }
    }
}
