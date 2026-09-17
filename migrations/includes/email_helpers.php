<?php
// ============================================================
//  FILE: includes/email_helpers.php
//  Email notification system for topup approvals, booking confirmations, etc.
//  Supports SMTP (SendGrid/Gmail) or PHP mail() fallback
// ============================================================

if (defined('EMAIL_HELPERS_LOADED')) return;
define('EMAIL_HELPERS_LOADED', true);

// Email configuration — move to config/email.php later
define('SMTP_HOST',     getenv('SMTP_HOST')     ?: 'smtp.gmail.com');
define('SMTP_PORT',     getenv('SMTP_PORT')     ?: 587);
define('SMTP_USER',     getenv('SMTP_USER')     ?: '');
define('SMTP_PASS',     getenv('SMTP_PASS')     ?: '');
define('SMTP_ENCRYPT',  getenv('SMTP_ENCRYPT')  ?: 'tls'); // tls or ssl
define('FROM_EMAIL',    getenv('FROM_EMAIL')    ?: 'noreply@pickleball.com');
define('FROM_NAME',     getenv('FROM_NAME')     ?: 'Falcon Pickleball');
define('EMAIL_ENABLED', getenv('EMAIL_ENABLED') ?: 'true');

// Use SMTP if credentials provided, otherwise PHP mail()
define('USE_SMTP', !empty(SMTP_HOST) && !empty(SMTP_USER) && !empty(SMTP_PASS));

/**
 * Send an email using SMTP or PHP mail()
 */
function sendEmail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
    if (EMAIL_ENABLED !== 'true') {
        error_log("Email disabled, skipping: $to - $subject");
        return true; // Don't fail silently in dev
    }

    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid email address: $to");
        return false;
    }

    // Add unsubscribe footer
    $unsubscribeUrl = APP_URL . '/unsubscribe.php?email=' . urlencode($to);
    $htmlBody .= "\n\n<hr><p style='font-size:12px;color:#666;'>Don't want these emails? <a href='$unsubscribeUrl'>Unsubscribe</a></p>";
    $textBody .= "\n\n---\nDon't want these emails? Unsubscribe: $unsubscribeUrl";

    if (USE_SMTP) {
        return sendSmtpEmail($to, $subject, $htmlBody, $textBody);
    } else {
        return sendPhpMail($to, $subject, $htmlBody, $textBody);
    }
}

/**
 * Send email via SMTP
 */
function sendSmtpEmail(string $to, string $subject, string $htmlBody, string $textBody): bool {
    try {
        // Use PHPMailer if available, otherwise basic SMTP
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return sendWithPHPMailer($to, $subject, $htmlBody, $textBody);
        } else {
            return sendBasicSmtp($to, $subject, $htmlBody, $textBody);
        }
    } catch (Exception $e) {
        error_log("SMTP email failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email using PHPMailer (if installed)
 */
function sendWithPHPMailer(string $to, string $subject, string $htmlBody, string $textBody): bool {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = SMTP_ENCRYPT === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)SMTP_PORT;

    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    $mail->addAddress($to);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $htmlBody;
    $mail->AltBody = $textBody ?: strip_tags($htmlBody);

    return $mail->send();
}

/**
 * Send email using basic SMTP connection
 */
function sendBasicSmtp(string $to, string $subject, string $htmlBody, string $textBody): bool {
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . FROM_NAME . ' <' . FROM_EMAIL . '>',
        'Reply-To: ' . FROM_EMAIL,
        'X-Mailer: PHP/' . phpversion()
    ];

    // Basic SMTP connection (simplified)
    $smtpConn = fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 10);
    if (!$smtpConn) {
        error_log("SMTP connection failed: $errstr ($errno)");
        return false;
    }

    // SMTP handshake (simplified)
    fwrite($smtpConn, "EHLO localhost\r\n");
    // ... (full SMTP implementation would be complex)

    fclose($smtpConn);

    // Fallback to PHP mail for now
    return sendPhpMail($to, $subject, $htmlBody, $textBody);
}

/**
 * Send email using PHP mail() function
 */
function sendPhpMail(string $to, string $subject, string $htmlBody, string $textBody): bool {
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . FROM_NAME . ' <' . FROM_EMAIL . '>',
        'Reply-To: ' . FROM_EMAIL,
        'X-Mailer: PHP/' . phpversion()
    ];

    $success = mail($to, $subject, $htmlBody, implode("\r\n", $headers));

    if (!$success) {
        error_log("PHP mail() failed for: $to - $subject");
    }

    return $success;
}

/**
 * Send topup approval/rejection email
 */
function sendTopupNotification(int $topupId): bool {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT t.*, u.email, u.full_name
        FROM falcon.topup_requests t
        JOIN falcon.users u ON t.user_id = u.id
        WHERE t.id = ?
    ");
    $stmt->execute([$topupId]);
    $topup = $stmt->fetch();

    if (!$topup) return false;

    $amount = number_format($topup['amount'], 2);
    $status = ucfirst($topup['status']);

    if ($topup['status'] === 'approved') {
        $subject = "Topup Approved - ₱$amount credited to your account";
        $htmlBody = "
            <h2>Topup Approved!</h2>
            <p>Hi {$topup['full_name']},</p>
            <p>Your topup request for ₱$amount has been approved and credited to your wallet.</p>
            <p>You can now use your credits to book games and join tournaments.</p>
            <p><a href='" . APP_URL . "/player/dashboard.php'>View Dashboard</a></p>
        ";
    } else {
        $subject = "Topup Request Rejected";
        $htmlBody = "
            <h2>Topup Request Update</h2>
            <p>Hi {$topup['full_name']},</p>
            <p>Your topup request for ₱$amount has been rejected.</p>
            <p>Reason: {$topup['review_note']}</p>
            <p>Please contact support if you have questions.</p>
        ";
    }

    return sendEmail($topup['email'], $subject, $htmlBody);
}

/**
 * Send booking confirmation/rejection email
 */
function sendBookingNotification(int $reservationId): bool {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT r.*, u.email, u.full_name, c.name as court_name
        FROM falcon.reservations r
        JOIN falcon.users u ON r.user_id = u.id
        JOIN falcon.courts c ON r.court_id = c.id
        WHERE r.id = ?
    ");
    $stmt->execute([$reservationId]);
    $booking = $stmt->fetch();

    if (!$booking) return false;

    $date = date('M j, Y', strtotime($booking['slot_date']));
    $time = date('g:i A', strtotime($booking['slot_time']));

    if ($booking['status'] === 'confirmed') {
        $subject = "Booking Confirmed - $date at $time";
        $htmlBody = "
            <h2>Booking Confirmed!</h2>
            <p>Hi {$booking['full_name']},</p>
            <p>Your court reservation has been confirmed:</p>
            <ul>
                <li><strong>Court:</strong> {$booking['court_name']}</li>
                <li><strong>Date:</strong> $date</li>
                <li><strong>Time:</strong> $time</li>
                <li><strong>Party Size:</strong> {$booking['party_size']}</li>
            </ul>
            <p>Please arrive 10 minutes early. Have fun!</p>
        ";
    } else {
        $subject = "Booking Request Update";
        $htmlBody = "
            <h2>Booking Update</h2>
            <p>Hi {$booking['full_name']},</p>
            <p>Your booking request for $date at $time has been {$booking['status']}.</p>
            " . ($booking['admin_note'] ? "<p>Note: {$booking['admin_note']}</p>" : "") . "
            <p><a href='" . APP_URL . "/player/schedule.php'>Make New Booking</a></p>
        ";
    }

    return sendEmail($booking['email'], $subject, $htmlBody);
}

/**
 * Send login attempt notification (failed logins)
 */
function sendLoginAlert(string $email, string $ip, int $attempts): bool {
    $subject = "Security Alert: Failed Login Attempts";
    $htmlBody = "
        <h2>Security Alert</h2>
        <p>We detected $attempts failed login attempts to your account from IP $ip.</p>
        <p>If this was you, you can ignore this email. If not, please change your password immediately.</p>
        <p><a href='" . APP_URL . "/auth/change_password.php'>Change Password</a></p>
        <p><a href='" . APP_URL . "/auth/logout_all.php'>Logout All Sessions</a></p>
    ";

    return sendEmail($email, $subject, $htmlBody);
}

/**
 * Send tournament invitation/registration confirmation
 */
function sendTournamentNotification(int $userId, int $tournamentId, string $type = 'registered'): bool {
    $db = getDB();

    // Get user and tournament info
    $stmt = $db->prepare("
        SELECT u.email, u.full_name, t.name as tournament_name, t.start_date
        FROM falcon.users u
        CROSS JOIN falcon.tournaments t
        WHERE u.id = ? AND t.id = ?
    ");
    $stmt->execute([$userId, $tournamentId]);
    $data = $stmt->fetch();

    if (!$data) return false;

    $date = date('M j, Y', strtotime($data['start_date']));

    if ($type === 'registered') {
        $subject = "Tournament Registration Confirmed";
        $htmlBody = "
            <h2>Welcome to {$data['tournament_name']}!</h2>
            <p>Hi {$data['full_name']},</p>
            <p>Your registration for the tournament on $date has been confirmed.</p>
            <p>Get ready to play!</p>
            <p><a href='" . APP_URL . "/tournaments.php?id=$tournamentId'>View Tournament</a></p>
        ";
    } elseif ($type === 'match_scheduled') {
        $subject = "Match Scheduled";
        $htmlBody = "
            <h2>Your Match is Scheduled</h2>
            <p>Hi {$data['full_name']},</p>
            <p>Your next match in {$data['tournament_name']} has been scheduled.</p>
            <p>Check the tournament page for details.</p>
            <p><a href='" . APP_URL . "/tournaments.php?id=$tournamentId'>View Tournament</a></p>
        ";
    }

    return sendEmail($data['email'], $subject, $htmlBody);
}