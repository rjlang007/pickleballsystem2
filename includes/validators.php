<?php
// ============================================================
//  FILE: includes/validators.php
// ============================================================
if (defined('VALIDATORS_LOADED')) return;
define('VALIDATORS_LOADED', true);

function validateReview(array $data): array {
    $errors = [];
    $reservationId = isset($data['reservation_id']) ? (int)$data['reservation_id'] : 0;
    $rating        = isset($data['rating'])         ? (int)$data['rating']         : 0;
    $comment       = trim((string)($data['comment'] ?? ''));

    if ($reservationId <= 0) {
        $errors['reservation_id'] = 'Reservation ID is required.';
    }
    if ($rating < 1 || $rating > 5) {
        $errors['rating'] = 'Rating must be between 1 and 5.';
    }
    if (strlen($comment) < 10 || strlen($comment) > 500) {
        $errors['comment'] = 'Comment must be 10–500 characters.';
    }

    return $errors;
}

function validateWithdrawal(array $data, float $walletBalance): array {
    $errors        = [];
    $amount        = isset($data['amount'])         ? (float)$data['amount']               : 0.0;
    $bankName      = trim((string)($data['bank_name']      ?? ''));
    $accountNumber = trim((string)($data['account_number'] ?? ''));
    $accountName   = trim((string)($data['account_name']   ?? ''));

    if ($amount < 100) {
        $errors['amount'] = 'Amount must be at least ₱100.';
    } elseif ($amount > $walletBalance) {
        $errors['amount'] = 'Amount cannot exceed your available balance.';
    }

    if ($bankName === '') {
        $errors['bank_name'] = 'Bank name is required.';
    } elseif (mb_strlen($bankName) > 100) {
        $errors['bank_name'] = 'Bank name cannot exceed 100 characters.';
    }

    if ($accountNumber === '') {
        $errors['account_number'] = 'Account number is required.';
    } elseif (mb_strlen($accountNumber) > 50) {
        $errors['account_number'] = 'Account number cannot exceed 50 characters.';
    }

    if ($accountName === '') {
        $errors['account_name'] = 'Account name is required.';
    } elseif (mb_strlen($accountName) > 150) {
        $errors['account_name'] = 'Account name cannot exceed 150 characters.';
    }

    return $errors;
}

function validateCommunityPost(array $data): array {
    $errors   = [];
    $content  = trim((string)($data['content']   ?? ''));
    $mediaUrl = trim((string)($data['media_url'] ?? ''));

    if ($content === '') {
        $errors['content'] = 'Content is required.';
    } elseif (mb_strlen($content) > 1000) {
        $errors['content'] = 'Content cannot exceed 1000 characters.';
    }

    if ($mediaUrl !== '' && !filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
        $errors['media_url'] = 'Media URL must be a valid URL.';
    }

    return $errors;
}

function validateComment(array $data): array {
    $errors  = [];
    $content = trim((string)($data['content'] ?? ''));
    $postId  = isset($data['post_id']) ? (int)$data['post_id'] : 0;

    if ($postId <= 0) {
        $errors['post_id'] = 'Post ID is required.';
    }
    if ($content === '') {
        $errors['content'] = 'Comment is required.';
    } elseif (mb_strlen($content) > 500) {
        $errors['content'] = 'Comment cannot exceed 500 characters.';
    }

    return $errors;
}

function validateDispute(array $data): array {
    $errors        = [];
    $reservationId = isset($data['reservation_id']) ? (int)$data['reservation_id'] : 0;
    $reason        = trim((string)($data['reason'] ?? ''));

    if ($reservationId <= 0) {
        $errors['reservation_id'] = 'Reservation ID is required.';
    }
    if ($reason === '') {
        $errors['reason'] = 'Reason is required.';
    } elseif (mb_strlen($reason) < 20 || mb_strlen($reason) > 1000) {
        $errors['reason'] = 'Reason must be 20–1000 characters.';
    }

    return $errors;
}

function validateBookingCreate(array $data, array $court): array {
    $errors    = [];
    $courtId   = isset($data['court_id'])   ? (int)$data['court_id']   : 0;
    $slotDate  = trim((string)($data['slot_date']  ?? ''));
    $slotTime  = trim((string)($data['slot_time']  ?? ''));
    $partySize = isset($data['party_size']) ? (int)$data['party_size'] : 0;
    $note      = trim((string)($data['note']       ?? ''));

    if ($courtId <= 0) {
        $errors['court_id'] = 'Court ID is required.';
    }

    // ── Slot date ─────────────────────────────────────────────
    if ($slotDate === '') {
        $errors['slot_date'] = 'Slot date is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $slotDate)) {
        $errors['slot_date'] = 'Slot date must be in YYYY-MM-DD format.';
    } else {
        $dateObj = DateTime::createFromFormat('Y-m-d', $slotDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $slotDate) {
            $errors['slot_date'] = 'Slot date is not a valid calendar date.';
        } else {
            $dateTs  = $dateObj->setTime(0, 0)->getTimestamp();
            $todayTs = (new DateTime())->setTime(0, 0)->getTimestamp();
            $maxTs   = strtotime('+30 days', $todayTs);
            if ($dateTs < $todayTs) {
                $errors['slot_date'] = 'Slot date cannot be in the past.';
            } elseif ($dateTs > $maxTs) {
                $errors['slot_date'] = 'Slot date cannot be more than 30 days ahead.';
            }
        }
    }

    // ── Slot time — strict HH:MM, rejects 99:99 ─────────────
    if ($slotTime === '') {
        $errors['slot_time'] = 'Slot time is required.';
    } else {
        $t = DateTime::createFromFormat('H:i', $slotTime);
        if (!$t || $t->format('H:i') !== $slotTime) {
            $errors['slot_time'] = 'Slot time must be a valid time in HH:MM format (e.g. 09:00).';
        }
    }

    // ── Party size ────────────────────────────────────────────
    if ($partySize < 1) {
        $errors['party_size'] = 'Party size must be at least 1.';
    } elseif ($partySize > ($court['max_queue'] ?? 1)) {
        $errors['party_size'] = 'Party size cannot exceed the court maximum of ' . ($court['max_queue'] ?? 1) . '.';
    }

    // ── Optional note ─────────────────────────────────────────
    if ($note !== '' && mb_strlen($note) > 300) {
        $errors['note'] = 'Note cannot exceed 300 characters.';
    }

    return $errors;
}