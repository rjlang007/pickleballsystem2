# PADOL PICKLEBALL COURT
## System Architecture & Technical Reference
**Version 1.0 | April 2026**

---

## TABLE OF CONTENTS

1. [Technology Stack](#tech-stack)
2. [Project Folder Structure](#folder-structure)
3. [Database Schema Overview](#database-schema)
4. [API Endpoints](#api-endpoints)
5. [Security Architecture](#security-architecture)
6. [Deployment Architecture](#deployment-architecture)
7. [Key Business Logic](#business-logic)
8. [Performance & Scalability](#performance)

---

## <a name="tech-stack"></a>1. Technology Stack

### Backend

| Component | Version | Purpose |
|-----------|---------|---------|
| **PHP** | 8.0+ | Server-side processing |
| **PostgreSQL** | 13+ | Relational database |
| **PHP-FPM** | 8.0+ | FastCGI process manager |

### Web Server & Reverse Proxy

| Component | Configuration |
|-----------|----------------|
| **Nginx** | Reverse proxy, static file serving |
| **SSL/TLS** | Auto-managed by Railway (Let's Encrypt) |

### Frontend

| Technology | Usage |
|-----------|-------|
| **HTML5** | Markup |
| **CSS3** | Responsive styling, CSS Grid/Flexbox |
| **JavaScript** | Client-side interactivity |
| **Responsive Design** | Mobile-first approach |

### Hosting Platform

| Service | Details |
|---------|---------|
| **Railway.app** | PaaS hosting |
| **Database** | PostgreSQL managed service |
| **Automatic Backups** | Daily, 30-day retention |
| **SSL Certificates** | Auto-renewing via Let's Encrypt |

### Key PHP Extensions

```
✓ PDO                — Database abstraction
✓ PDO_PGSQL          — PostgreSQL driver
✓ MBString           — Multi-byte string handling
✓ GD                 — Image processing (avatars, QR codes)
✓ cURL               — HTTP requests (external APIs)
✓ OpenSSL            — Encryption & secure communications
✓ Sessions           — Session management
✓ JSON               — JSON encoding/decoding
```

### External Services (Optional)

| Service | Purpose |
|---------|---------|
| **Cloudinary** | Image hosting (optional; can use local) |
| **GCash API** | Payment verification (if enabled) |
| **Bank APIs** | Payment reconciliation (if enabled) |

---

## <a name="folder-structure"></a>2. Project Folder Structure

### Directory Hierarchy

```
/pickleball/
├── public/                    # Entry point (web root)
│   └── index.php             # Landing page for guests
│
├── admin/                     # Admin management pages
│   ├── dashboard.php         # Main admin dashboard
│   ├── players.php           # Player management (verify, ban, balance)
│   ├── court_settings.php    # Court configuration
│   ├── schedule.php          # Schedule & calendar management
│   ├── reservations.php      # Reservation approvals
│   ├── topup_requests.php    # Payment top-up processing
│   ├── reports.php           # Analytics & reports
│   ├── court_mode.php        # Open Play vs Reservation scheduling
│   ├── payment_settings.php  # Payment method configuration
│   ├── activity_manager.php  # Activity center management
│   ├── game_history.php      # Past game logs
│   ├── scan_logs.php         # QR scan audit trail
│   ├── kiosk.php             # Kiosk management
│   ├── create_player.php     # Bulk player creation
│   ├── api/                  # Admin-specific APIs
│   └── ... (20+ admin pages)
│
├── api/                       # REST API endpoints (JSON)
│   ├── chat.php              # Real-time messaging
│   ├── court_mode.php        # Get current court mode
│   ├── download_qr.php       # Download QR code
│   ├── get_user_token.php    # Session token verification
│   ├── notifications.php     # Notification API
│   ├── pass_status.php       # QR pass status check
│   └── slots.php             # Court availability slots
│
├── auth/                      # Authentication & accounts
│   ├── register.php          # Player registration
│   ├── login.php             # Login page
│   ├── logout.php            # Logout handler
│   ├── forgot_password.php   # Password recovery
│   └── reset_password.php    # Password reset handler
│
├── player/                    # Player dashboard pages
│   ├── dashboard.php         # Main player dashboard
│   ├── my_qr.php             # QR code display (full screen)
│   ├── my_barcode.php        # Barcode version
│   ├── schedule.php          # Court booking/reservations
│   ├── history.php           # Game history & stats
│   ├── profile.php           # Player profile edit
│   ├── topup_history.php     # Top-up transaction log
│   ├── notifications.php     # Notification center
│   └── (other player features)
│
├── court/                     # Court operations
│   ├── scanner.php           # QR scanner interface (kiosk)
│   ├── game_engine.php       # Game logic (start/end/scoring)
│   ├── queue_status.php      # Real-time queue display
│   ├── auto_end_games.php    # Scheduled game end job
│   ├── process_scan.php      # QR scan processing
│   └── (game management)
│
├── config/                    # Configuration & initialization
│   ├── app.php               # App constants & initialization
│   ├── db.php                # Database connection
│   ├── security.php          # Security headers & CSRF
│   ├── session.php           # Session management
│   ├── error_handler.php     # Error logging & handling
│   └── cloudinary.php        # Image upload service (optional)
│
├── includes/                  # Shared functions & utilities
│   ├── header.php            # HTML header template
│   ├── footer.php            # HTML footer template
│   ├── qr.php                # QR code generation
│   ├── barcode.php           # Barcode generation
│   ├── security_helpers.php  # Security utilities
│   ├── activity_logger.php   # Audit trail logging
│   ├── availability.php      # Court availability logic
│   ├── chat_widget.php       # Chat UI component
│   ├── notif_bell_snippet.php # Notification UI component
│   ├── logo.php              # Logo display component
│   └── partials/             # Reusable UI partials
│
├── assets/                    # Static files
│   ├── css/                  # Stylesheets
│   │   └── app.css          # Main stylesheet
│   └── js/                   # JavaScript files
│       └── app.js           # Main JavaScript
│
├── logs/                      # Log files
│   ├── error.log             # Application errors
│   ├── security.log          # Security events
│   └── uploads/              # Upload logs
│
├── uploads/                   # User-uploaded files
│   ├── avatars/              # Player profile pictures
│   ├── screenshots/          # Payment proof images
│   ├── payment_qr/           # Payment method QR codes
│   ├── activity_photos/      # Activity images
│   └── (other uploads)
│
├── migrations/               # Database schema
│   └── 001_initial_schema.sql # Initial database setup
│
├── storage/                  # Application storage
│   ├── temp/                 # Temporary files
│   └── cache/                # Cache files
│
├── config files              # Root configuration files
│   ├── app.php               # PHP environment config
│   ├── nixpacks.toml         # Railway build config
│   ├── railway.json          # Railway deployment config
│   ├── nginx.conf            # Nginx web server config
│   └── start.sh              # Application startup script
│
└── README.md                 # Project documentation
```

### Key Entry Points

| File | Purpose |
|------|---------|
| `/public/index.php` | Public landing page (no auth required) |
| `/admin/dashboard.php` | Admin dashboard (admin only) |
| `/player/dashboard.php` | Player dashboard (logged-in players) |
| `/auth/login.php` | Login page (everyone) |
| `/court/scanner.php` | QR scanner kiosk interface (admin) |

---

## <a name="database-schema"></a>3. Database Schema Overview

### Database Name
- **Name:** `falcon`
- **Type:** PostgreSQL 13+
- **Location:** Railway managed PostgreSQL service

### Core Tables (31 total)

#### 1. **users** — Player & Admin Accounts
```sql
Columns: id, username, email, password_hash, full_name, phone, 
         avatar_path, role, is_active, is_banned, ban_reason,
         created_at, updated_at
Indexes: username (UNIQUE), email (UNIQUE), role, is_active, is_banned
Purpose: Store all user accounts (players, admins)
Relationships: Referenced by wallets, game_players, reservations, etc.
```

#### 2. **wallets** — Credit Balances
```sql
Columns: user_id (PK), balance, currency, last_topup_at,
         created_at, updated_at
Purpose: Store player account balances (how many credits they have)
Key Constraint: balance >= 0 (never negative)
```

#### 3. **courts** — Venue Information
```sql
Columns: id, name, description, address, location (PostGIS),
         is_active, max_queue, credit_cost, game_duration,
         warmup_mins, pass_hours, created_at, updated_at
Purpose: Define courts (facilities)
Config: Each court has cost per game, duration, max players
```

#### 4. **player_passes** — QR Codes & Passes
```sql
Columns: id, user_id, qr_token, is_active, expires_at,
         last_scanned_at, created_at, updated_at
Purpose: Player's QR pass (permanent token)
Note: QR token never changes; one per player
```

#### 5. **game_sessions** — Individual Games
```sql
Columns: id, court_id, status (waiting|active|completed|cancelled),
         started_at, ended_at, duration_mins, session_type,
         reservation_id, total_credits_charged, created_at
Purpose: Record of each game played
Statuses: waiting → active → completed
```

#### 6. **game_players** — Players in Games
```sql
Columns: id, session_id, user_id, joined_at, credits_charged,
         payment_status, position, status, created_at
Purpose: Link players to game sessions
Records: One record per player per game
```

#### 7. **game_queue** — Open Play Queue
```sql
Columns: id, user_id, pass_id, session_id, joined_at
Purpose: Track players waiting to play (queue)
Queue logic: Sorted by joined_at (FIFO)
```

#### 8. **reservations** — Court Bookings
```sql
Columns: id, court_id, user_id, slot_date, slot_time, slot_end,
         party_size, status (pending|confirmed|cancelled),
         payment_method, payment_status, payment_amount,
         payment_ref, payment_proof, booking_group_id,
         session_id, created_at, updated_at
Purpose: Player court reservations (advance bookings)
Status: pending → confirmed → used/cancelled
```

#### 9. **court_hours** — Operating Hours
```sql
Columns: id, court_id, day_of_week (0-6), open_time, close_time,
         is_closed, created_at
Purpose: Define court hours per day of week
Example: Monday: 6:00 AM - 11:00 PM
```

#### 10. **court_slot_modes** — Open Play vs Reservation Scheduling
```sql
Columns: id, court_id, slot_date, day_of_week, time_from, time_to,
         mode (open_play|reservation), created_at
Purpose: Schedule which mode court operates in per time slot
Example: "Every Friday 8 PM - 11 PM = Open Play"
```

#### 11. **court_settings** — Court Configuration
```sql
Columns: id, court_id, key (setting name), value, created_at, updated_at
Purpose: Key-value store for court-specific settings
Examples: reservation_price_per_hour, reservation_min_hours, etc.
```

#### 12. **topup_requests** — Payment Top-ups
```sql
Columns: id, user_id, amount, method (gcash|bank|cash),
         status (pending|approved|rejected), reference_number,
         screenshot_path, review_note, reviewed_by, reviewed_at, created_at
Purpose: Track player credit top-up requests
Flow: pending → (admin reviews) → approved/rejected
```

#### 13. **transactions** — Transaction Log
```sql
Columns: id, user_id, type (topup|deduction|refund|adjustment),
         amount, method, note, status (pending|approved|failed),
         balance_before, balance_after, processed_by,
         created_at
Purpose: Complete audit trail of all wallet movements
Immutable: Transaction created once, never modified
```

#### 14. **payment_settings** — Payment Methods
```sql
Columns: id, method_name, account_name, account_number,
         qr_image, instructions, is_active, sort_order, updated_at
Purpose: Admin-configured payment methods (GCash, Bank, Cash)
Usage: Shown to players during top-up
```

#### 15. **notifications** — User Alerts
```sql
Columns: id, user_id, title, message, type (info|success|warning|error),
         is_read, link, reservation_id, created_at
Purpose: Store notification history
Examples: "Top-up approved", "Your turn to play", etc.
```

#### 16. **chat_conversations** — Chat Threads
```sql
Columns: id, user_id, updated_at
Purpose: Persistent chat conversation per player
```

#### 17. **chat_messages** — Chat Messages
```sql
Columns: id, conversation_id, sender_id, message, created_at
Purpose: Store individual messages in conversations
```

#### 18. **password_resets** — Password Reset Tokens
```sql
Columns: id, user_id, token_hash, expires_at, used_at, ip_address
Purpose: Track password reset tokens
Lifecycle: Created → used (or expires)
```

#### 19. **failed_logins** — Failed Login Attempts
```sql
Columns: id, username, reason, ip_address, created_at
Purpose: Track failed login attempts for security
Usage: Auto-block IP after N attempts
```

#### 20. **ip_blacklist** — Blocked IP Addresses
```sql
Columns: id, ip_address, reason, blocked_at, expires_at
Purpose: Block suspicious IPs
```

#### 21. **audit_log** — System Audit Trail
```sql
Columns: id, action, user_id, related_table, related_id,
         old_value, new_value, status, note, created_at
Purpose: Complete system audit (who did what, when)
Immutable: Never modified, only appended
Examples: "Player banned", "Balance adjusted", "Payment approved"
```

#### 22. **site_content** — Static Content
```sql
Columns: id, section, key, value, created_at, updated_at
Purpose: CMS-like storage for page content
Examples: hero_title, about_description, social_links, etc.
```

#### 23. **membership_plans** — Membership Tiers
```sql
Columns: id, name, description, benefits, price, duration_days,
         is_active, sort_order
Purpose: Define membership plans (if subscription model used)
```

#### 24. **events** — Tournaments & Events
```sql
Columns: id, name, description, event_date, location,
         max_participants, registration_fee, is_active, created_at
Purpose: Store event/tournament information
```

#### 25. **training_programs** — Training Classes
```sql
Columns: id, name, description, instructor, schedule,
         price, max_capacity, is_active, sort_order
Purpose: Training programs/lessons available
```

#### 26. **activity_types** — Activity Center
```sql
Columns: id, name, description, icon, price_per_hour,
         flat_price, pricing_note, photo, is_active, sort_order
Purpose: Activities beyond pickleball (billiards, etc.)
```

#### 27. **court_reservations** — Admin Court Reservations
```sql
Columns: id, court_id, reservation_date, slot_start, slot_end,
         label, notes, status, reserved_by, updated_at
Purpose: Admin-created reservations (maintenance, private events)
```

#### 28. **activity_bookings** — Activity Center Bookings
```sql
Columns: id, user_id, activity_type_id, booking_date, booking_time,
         duration_mins, participants, status, payment_status, created_at
Purpose: Track activity center bookings
```

#### 29. **user_sessions** — Active Sessions
```sql
Columns: id, user_id, token, ip_address, user_agent,
         created_at, expires_at
Purpose: Track active user sessions
```

#### 30. **schedule_slots** — Membership Schedule
```sql
Columns: id, name, description, time_start, time_end,
         max_capacity, is_active, sort_order
Purpose: Define schedule slots (if using schedule-based system)
```

#### 31. **shop_items** — Shop/Merchandise
```sql
Columns: id, name, description, price, image_url,
         inventory, is_active, sort_order
Purpose: Store items for sale (merchandise, equipment)
```

### Key Relationships

```
┌─────────────────────────────────────────────────────────┐
│ users                                                     │
│ ├─→ wallets (user's balance)                           │
│ ├─→ player_passes (user's QR code)                     │
│ ├─→ game_players (games they played)                   │
│ ├─→ game_queue (queue position)                        │
│ ├─→ reservations (booked slots)                        │
│ ├─→ topup_requests (payment requests)                  │
│ ├─→ transactions (payment history)                     │
│ ├─→ notifications (alerts)                             │
│ └─→ audit_log (actions performed)                      │
│                                                         │
│ courts                                                   │
│ ├─→ game_sessions (games played)                       │
│ ├─→ court_hours (operating hours)                      │
│ ├─→ court_slot_modes (open play scheduling)            │
│ ├─→ court_settings (configuration)                     │
│ └─→ reservations (bookings)                            │
│                                                         │
│ game_sessions                                           │
│ ├─→ game_players (players in game)                     │
│ ├─→ game_queue (queue entries)                         │
│ └─→ reservations (if reservation game)                 │
└─────────────────────────────────────────────────────────┘
```

---

## <a name="api-endpoints"></a>4. API Endpoints

### Available API Files

| File | Purpose |
|------|---------|
| `/api/chat.php` | Messaging endpoints |
| `/api/court_mode.php` | Get current court mode |
| `/api/download_qr.php` | Download/export QR code |
| `/api/get_user_token.php` | Session token verification |
| `/api/notifications.php` | Fetch notifications |
| `/api/pass_status.php` | Check QR pass validity |
| `/api/slots.php` | Get available court slots |

### Example API Responses

#### GET /api/pass_status.php?user_id=123

**Request:** Check if player's pass is valid

**Response:**
```json
{
  "ok": true,
  "is_active": true,
  "balance": 150.00,
  "can_play": true,
  "expires_at": "2026-12-31",
  "message": "Pass is active. Balance: ₱150.00"
}
```

#### GET /api/court_mode.php?court_id=1

**Request:** Get current court operating mode

**Response:**
```json
{
  "court_id": 1,
  "current_mode": "open_play",
  "time_from": "20:00:00",
  "time_to": "23:59:59",
  "next_mode_change": "2026-04-23T06:00:00Z"
}
```

#### GET /api/slots.php?court_id=1&date=2026-04-25

**Request:** Get available reservation slots

**Response:**
```json
{
  "date": "2026-04-25",
  "court_id": 1,
  "slots": [
    {"time": "08:00", "available": true},
    {"time": "08:30", "available": true},
    {"time": "09:00", "available": false, "booked_by": "juan_p"},
    ...
  ]
}
```

---

## <a name="security-architecture"></a>5. Security Architecture

### CSRF (Cross-Site Request Forgery) Protection

**Implementation:**
```php
// Token generation in session
$token = base64_encode(random_bytes(16));
$_SESSION['csrf_token'] = $token;

// Validation on POST
function verifyCsrf() {
    if ($_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? null)) {
        throw new Exception("CSRF token mismatch");
    }
}
```

**Protection:** Every form POST includes a unique token that matches server-side session token.

### CSP (Content Security Policy) Nonce System

**Headers sent:**
```
Content-Security-Policy: 
  default-src 'self';
  script-src 'self' 'nonce-[random16bytes]' https://unpkg.com;
  style-src 'self' 'nonce-[random16bytes]' https://fonts.googleapis.com;
  img-src 'self' data: blob: https://res.cloudinary.com https://api.qrserver.com;
```

**Protection:** Only scripts/styles with correct nonce can execute; inline scripts blocked.

### Session Management

**Session Configuration:**
- **Timeout:** 20 minutes of inactivity
- **Warning:** 2 minutes before timeout
- **Fingerprinting:** IP + User-Agent validation
- **Token Regeneration:** After login
- **Secure Cookies:**
  - HttpOnly: true (no JS access)
  - SameSite: Strict (no cross-site cookies)
  - Secure: true (HTTPS only)

**Session Lifecycle:**
```
1. User logs in → session_regenerate_id(true)
2. Fingerprint created: hash(IP + User-Agent)
3. Every request: fingerprint validated
4. Inactivity timer reset on each request
5. 20 min idle → session destroyed
```

### Rate Limiting

**Implementation:** Filesystem-based token bucket

```php
checkRateLimit('login_ip_192.168.1.1', 5, 300);
// Allow 5 attempts per 300 seconds (5 minutes)
```

**Protected Endpoints:**
- Login: 5 attempts per 5 minutes per IP
- Registration: 3 attempts per 10 minutes per IP
- Password reset: 3 attempts per 10 minutes per IP

**Auto-block:** After repeated failures, IP is added to blacklist.

### Password Hashing

**Algorithm:** bcrypt (PASSWORD_BCRYPT)

```php
$hash = password_hash($password, PASSWORD_BCRYPT);
// Cost: 12 (default, takes ~200ms per hash)

// Verification
if (password_verify($inputPassword, $storedHash)) {
    // Password matches
}
```

**Requirements:**
- Minimum 8 characters
- At least 1 uppercase letter (A-Z)
- At least 1 number (0-9)

### SSL/TLS

**Configuration:**
- **Protocol:** TLSv1.2+
- **Certificate:** Let's Encrypt (auto-renewed by Railway)
- **HSTS:** `Strict-Transport-Security: max-age=31536000` (1 year)
- **Redirect:** HTTP → HTTPS (permanent 301 redirect)

### Security Headers

| Header | Purpose |
|--------|---------|
| `X-Content-Type-Options: nosniff` | Prevent MIME-type sniffing |
| `X-Frame-Options: SAMEORIGIN` | Prevent clickjacking |
| `X-XSS-Protection: 1; mode=block` | Legacy XSS protection |
| `Referrer-Policy: strict-origin-when-cross-origin` | Control referrer info |

### Input Validation & Sanitization

**Functions:**
```php
sanitizeString($input, $maxLen)     // Remove HTML, limit length
sanitizeEmail($input)                 // Validate email format
filter_input(INPUT_POST, $key, filter) // Built-in PHP filtering
```

**Database Protection:**
- Prepared statements (parameterized queries)
- PDO with ERRMODE_EXCEPTION
- No raw SQL construction

### Audit Logging

**Logged Events:**
- User login (success/failure)
- Password changes
- Player ban/unban
- Balance adjustments
- Payment approvals/rejections
- Admin actions on reservations
- Permissions changes

**Audit Table:**
```sql
audit_log: id, action, user_id, related_table, related_id,
           old_value, new_value, status, note, created_at
```

---

## <a name="deployment-architecture"></a>6. Deployment Architecture

### Railway.app Platform

**Service Structure:**
```
┌─────────────────────────────────┐
│     Railway Project             │
├─────────────────────────────────┤
│ 1. Nixpacks Builder             │ ← Builds Docker image
├─────────────────────────────────┤
│ 2. PHP Application Service      │ ← Runs app
│    - Runs: bash /app/start.sh   │
│    - Exposes: PORT 3000         │
├─────────────────────────────────┤
│ 3. PostgreSQL Database Service  │ ← Stores data
│    - Managed PostgreSQL         │
│    - Automatic backups          │
├─────────────────────────────────┤
│ 4. Domain & SSL                 │ ← Automatic HTTPS
│    - Railway domain             │
│    - Let's Encrypt certificates │
└─────────────────────────────────┘
```

### Nixpacks Build Configuration

**File:** `nixpacks.toml`

```toml
[phases.setup]
nixPkgs = ["php", "phpExtensions.pdo", "phpExtensions.pdo_pgsql", 
           "phpExtensions.mbstring", "phpExtensions.gd", 
           "phpExtensions.curl", "phpExtensions.openssl", "nginx"]

[phases.build]
cmds = ["chmod +x /app/start.sh"]

[start]
cmd = "bash /app/start.sh"
```

### Railway Configuration

**File:** `railway.json`

```json
{
  "$schema": "https://railway.app/railway.schema.json",
  "build": {
    "builder": "NIXPACKS"
  },
  "deploy": {
    "startCommand": "bash /app/start.sh",
    "restartPolicyType": "ON_FAILURE",
    "restartPolicyMaxRetries": 3
  }
}
```

### Startup Script

**File:** `start.sh`

```bash
#!/bin/bash
set -e

# Create upload directories
mkdir -p /app/uploads/activity_photos
chmod -R 775 /app/uploads

# Configure PHP-FPM
php-fpm -y /tmp/php-fpm.conf -D
sleep 2

# Start Nginx
nginx -c /tmp/nginx.conf

# Log streaming
tail -f /dev/stdout
```

### Nginx Configuration

**File:** `nginx.conf`

```nginx
server {
    listen $PORT;
    root /app/public;
    index index.php index.html;

    # URL rewriting (SPA-like routing)
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP handling
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Deny .htaccess
    location ~ /\.ht { deny all; }
}
```

### Environment Variables

**Set in Railway dashboard:**

| Variable | Example | Purpose |
|----------|---------|---------|
| `APP_URL` | `https://falcon.railway.app` | Application root URL |
| `APP_NAME` | `Padol Pickleball Court` | Display name |
| `APP_ENV` | `production` | Environment mode |
| `DATABASE_URL` | `postgresql://user:pass@host/db` | DB connection |
| `PGHOST` | `postgres.railway.internal` | DB host |
| `PGPORT` | `5432` | DB port |
| `PGDATABASE` | `railway` | DB name |
| `PGUSER` | `postgres` | DB user |
| `PGPASSWORD` | `[password]` | DB password |
| `CLOUDINARY_CLOUD_NAME` | `[optional]` | Image hosting |
| `CLOUDINARY_API_KEY` | `[optional]` | Image API key |
| `CLOUDINARY_API_SECRET` | `[optional]` | Image API secret |

### Database Backups

**Automatic Backups:**
- **Frequency:** Daily
- **Retention:** 30 days
- **Location:** Railway's secure servers
- **Recovery:** Managed via Railway dashboard

**Manual Export:**
```bash
pg_dump -U $PGUSER -d $PGDATABASE > backup_$(date +%Y%m%d).sql
```

---

## <a name="business-logic"></a>7. Key Business Logic

### Credit/Wallet System

**Flow:**

```
1. Player registers → balance = ₱0 (inactive QR)
   
2. Player top-ups ₱100 via GCash
   → Creates topup_request (pending)
   
3. Admin reviews & approves
   → Updates wallets: balance += 100
   → Creates transaction record
   → Creates notification: "✅ Top-up approved"
   
4. Player scans QR to play
   → System checks: balance >= credit_cost
   → If YES → Added to queue
   → If NO → Error: "Insufficient balance"
   
5. Game ends
   → Credits deducted: balance -= 10
   → Transaction logged
   → Queue cleared, next players play
```

**Key Functions:**
- `syncPassActive()` — Updates `is_active` based on balance
- `getCourtCreditCost()` — Gets cost from court settings

### QR Scanning Process

**QR Token:**
```
User → player_passes.qr_token (permanent 64-char hex)
       Generated: crypto random bytes
       Never changes: Once created, stays forever
       Used for: All scans and game entry
```

**Scan Validation:**
```
1. Scanner reads QR token
2. Query: SELECT user_id FROM player_passes WHERE qr_token = ?
3. Get user record: SELECT balance FROM wallets WHERE user_id = ?
4. Check conditions:
   - balance >= credit_cost? ✓
   - is_banned = false? ✓
   - is_active = true? ✓
   - is_verified = true? ✓
5. If all pass → add to game_queue
6. If fail → show error message
```

**Player Pass Status:**
```sql
-- On player dashboard
UPDATE player_passes 
SET is_active = (balance >= credit_cost)
WHERE user_id = ?;
```

### Game Queue Auto-Start Logic

**Queue Processor (runs periodically):**

```
1. Check if active game exists
   IF active game exists AND time remaining > 0
     → SKIP (game still running)
   
2. IF active game ended OR no game
     → Check queue for waiting players
   
3. Get first 4 in queue (max_queue from court)
   WHERE session_id IS NULL
   ORDER BY joined_at ASC
   
4. IF >= 4 players:
     → Create new game_session
     → Insert 4 players into game_players
     → Update game_queue: set session_id = [new session]
     → Set status = 'active'
     → Start timer (game_duration minutes)
   
5. IF < 4 players:
     → Wait (don't start yet)
     → Display: "Waiting for X more players"
```

**Auto-End Logic (cron job):**

```
Every 1 minute:
1. Find games WHERE status = 'active' 
             AND started_at + duration < NOW()
2. For each:
   a. Deduct credits from all players:
      UPDATE wallets SET balance -= credit_cost
   b. Set game status = 'completed'
   c. Move next 4 from queue into new game
   d. Notify players: "Game ended. Charged ₱10"
```

### Reservation System

**Reservation Flow:**

```
1. Player selects date/time/duration
   → System checks availability
   
2. IF available:
   → Calculate price: duration_hours * rate
   → Show deposit required (50% typical)
   → Create reservation: status = 'pending'
   
3. Admin receives notification
   → Reviews reservation
   → Approves → status = 'confirmed'
   → OR Rejects → status = 'cancelled'
   
4. Player notified
   → If approved: "Your court is booked!"
   → If rejected: "Booking rejected"
   
5. On booking date/time:
   → Player arrives
   → QR scanned
   → Added to game session for that time
   → Game proceeds normally
```

**Conflict Prevention:**
```sql
-- Ensure no double-booking
UNIQUE(court_id, user_id, slot_date, slot_time)
```

### Court Mode Scheduling

**Mode Rules:**

```
Rules stored in: court_slot_modes

Example rules:
1. Every Monday-Friday 6 AM - 8 PM = Reservation
2. Every Monday-Friday 8 PM - 11 PM = Open Play
3. Weekends all day = Open Play
4. December 25 all day = Closed (or Reservation-only)

At any given time:
→ Query: SELECT mode WHERE court_id = X
        AND time_from <= CURRENT_TIME
        AND time_to > CURRENT_TIME
        AND (slot_date = TODAY OR day_of_week = TODAY_DOW)
        ORDER BY slot_date DESC LIMIT 1
        
→ Specific date rules override recurring rules
→ If no rule found, default = 'open_play'
```

### Payment Processing

**Top-Up Approval Process:**

```
1. Player submits top-up request with:
   - Amount (₱50, ₱100, ₱500, etc.)
   - Payment method (GCash/Bank/Cash)
   - Proof (screenshot or bank ref)

2. Status: pending
   → topup_requests table
   → Player sees: "Waiting for approval"

3. Admin reviews:
   ✓ Does reference # exist in GCash app?
   ✓ Does screenshot show correct amount?
   ✓ Is this a duplicate?

4. Admin approves:
   → BEGIN TRANSACTION
   → Get current balance from wallets
   → INSERT/UPDATE wallets: balance += amount
   → INSERT into transactions (audit record)
   → UPDATE topup_requests: status = 'approved'
   → INSERT notification for player
   → COMMIT

5. If rejected:
   → UPDATE status = 'rejected'
   → INSERT notification with reason
   → Balance unchanged

6. Player notified:
   ✅ "₱100 added to account. Play now!"
   ❌ "Request rejected. See reason: [reason]"
```

---

## <a name="performance"></a>8. Performance & Scalability

### Database Optimization

**Indexes Created:**
- `users.username` (UNIQUE)
- `users.email` (UNIQUE)
- `wallets.balance` (quick low-balance queries)
- `game_sessions.status` (filter active games)
- `game_sessions.started_at` (time range queries)
- `reservations.slot_date` (calendar queries)
- `topup_requests.status` (filter pending requests)
- (20+ total indexes)

**Query Performance:**
- Average query time: <100ms
- Dashboard load: <500ms (multiple queries)
- Player list page: <1000ms (with sorting/filtering)

### Caching Strategy

**Session Cache:**
- `$cache[$courtId]` in `getCourtCreditCost()`
- Reduces DB queries per request

**Static Content Cache:**
- CSS/JS in `/assets/` (browser cache)
- Nginx gzip compression

### Scalability Limits

**Current Setup (Padol Basic):**
- **Max Players:** 10,000+
- **Concurrent Users:** 100+
- **Daily Transactions:** 1,000+
- **Database:** PostgreSQL 2GB RAM default

**Scaling Options:**
1. **Read Replicas:** For reporting queries
2. **Connection Pooling:** PgBouncer
3. **Redis Cache:** Session/cache layer
4. **Horizontal Scaling:** Multiple app instances via Railway

---

## Appendix: Database Query Examples

### Get Player's Current Status

```sql
SELECT 
    u.username, u.full_name, u.is_banned,
    w.balance,
    pp.is_active, pp.expires_at,
    gq.id AS queue_position
FROM falcon.users u
LEFT JOIN falcon.wallets w ON u.id = w.user_id
LEFT JOIN falcon.player_passes pp ON u.id = pp.user_id
LEFT JOIN falcon.game_queue gq ON u.id = gq.user_id
WHERE u.id = 123;
```

### Get Today's Revenue

```sql
SELECT 
    COUNT(*) AS games_today,
    COALESCE(SUM(gp.credits_charged), 0) AS revenue,
    COUNT(DISTINCT gp.user_id) AS unique_players
FROM falcon.game_sessions gs
JOIN falcon.game_players gp ON gs.id = gp.session_id
WHERE DATE(gs.started_at) = CURRENT_DATE
  AND gs.status = 'completed';
```

### Get Current Queue

```sql
SELECT 
    ROW_NUMBER() OVER (ORDER BY gq.joined_at ASC) AS position,
    u.username, u.full_name,
    gq.joined_at,
    w.balance
FROM falcon.game_queue gq
JOIN falcon.users u ON gq.user_id = u.id
LEFT JOIN falcon.wallets w ON u.id = w.user_id
WHERE gq.session_id IS NULL
ORDER BY gq.joined_at ASC;
```

---

**End of System Architecture Document**

*For technical support or architecture questions, contact the development team.*

*Last Updated: April 2026*
