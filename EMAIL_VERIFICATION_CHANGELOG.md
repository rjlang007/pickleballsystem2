# Email Verification + Password Reset Codes — What Changed

## 1. Run the new migration
```
migrations/013_email_verification_and_reset_codes.sql
```
This adds:
- `falcon.email_verifications` — 6-digit registration codes
- `falcon.password_reset_codes` — 6-digit "forgot password" codes
- `falcon.users.email_verified` — new column (kept separate from the
  existing `is_verified` admin "Verify Player" flag on purpose — see
  the comments in the migration for why)
- `falcon.php_sessions.user_id` — fixes a pre-existing bug where
  "log out all sessions" on password reset silently didn't work
  (it referenced a table, `falcon.user_sessions`, that never existed)
- Repairs `falcon.password_resets` so its columns match what the old
  code already expected (this table is now unused going forward, kept
  only for historical rows)

## 2. Set your SMTP credentials
Copy the new variables from `.env.example` into your real `.env`:
```
EMAIL_ENABLED=true
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=youraccount@gmail.com
SMTP_PASS=your-16-char-app-password   # NOT your normal Gmail password
SMTP_ENCRYPT=tls
FROM_EMAIL=noreply@yourdomain.com
FROM_NAME=Padol Pickleball Court
```
Gmail App Passwords: https://myaccount.google.com/apppasswords
(Requires 2-Step Verification enabled on the Gmail account.)
Any other SMTP provider (SendGrid, Mailgun, your hosting provider's
SMTP, etc.) works too — just fill in their host/port/user/pass.

## 3. What players now see

**Registration** — email is now required (it was previously optional
even though the database always required it). After signing up, the
player is sent to a code-entry screen and can't log in until they
enter the 6-digit code emailed to them. Codes expire in 15 minutes,
allow 5 wrong attempts, and can be resent (rate-limited).

**Forgot Password** — instead of clicking a link, the player enters
their email, receives a 6-digit code, then enters the code + new
password on one screen. This is more reliable on mobile (no broken
deep-links, no "link already used" confusion) and matches what you
asked for.

## 4. Backward compatibility
- Everyone who registered **before** this migration is automatically
  marked `email_verified = TRUE` so nobody currently on the system
  gets locked out.
- Accounts an admin creates in person (`admin/create_player.php`,
  `admin/create_staff.php`) skip the email-code step entirely, since
  the admin already verified them at the counter.
- The existing "Verify Player" admin approval feature in
  Admin → Players is untouched and still works exactly as before.

## 5. New/changed files
- `includes/PHPMailer/src/` — bundled PHPMailer (no Composer needed)
- `includes/email_helpers.php` — now actually uses PHPMailer
- `includes/verification_helpers.php` — new, shared code logic
- `migrations/013_email_verification_and_reset_codes.sql` — new
- `auth/register.php`, `auth/login.php` — updated
- `auth/verify_email.php` — new
- `auth/forgot_password.php`, `auth/reset_password.php` — rewritten
- `admin/create_player.php`, `admin/create_staff.php` — updated
- `config/session.php` — bugfix (see migration notes)
- `.env.example` — documented SMTP variables
