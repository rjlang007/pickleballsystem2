# PADOL PICKLEBALL COURT
## Credentials & System Access Handover Guide

**Date:** April 2026
**From:** [FILL IN - Developer Name]
**To:** [FILL IN - Client Name]
**System:** Padol Pickleball Management System

---

## 1. SYSTEM ACCESS INFORMATION

### Production Website URLs

**Player/Public Site:**
```
https://[FILL IN - Your Railway App Name].up.railway.app/
```
- Players use this to register, login, top-up, book courts
- Public landing page
- QR code display

**Admin Dashboard:**
```
https://[FILL IN - Your Railway App Name].up.railway.app/admin/dashboard.php
```
- Court owner management
- Player management
- Reports and analytics

**Court Scanner (Kiosk):**
```
https://[FILL IN - Your Railway App Name].up.railway.app/court/scanner.php
```
- QR scanning interface
- Queue display
- Game monitoring
- Use at the kiosk/tablet by the courts

---

## 2. ADMIN ACCOUNT SETUP

### Super Admin Credentials (CHANGE IMMEDIATELY)

**Initial Super Admin Account:**

| Field | Value |
|-------|-------|
| Username | [FILL IN] |
| Email | [FILL IN] |
| Password | [FILL IN] |
| Role | Super Admin |
| Access | Full system access |

**⚠️ CRITICAL: Change Password Immediately**

1. Log in with above credentials
2. Click your username (top right) → Profile
3. Click "Change Password"
4. Enter a NEW strong password
5. Save

### Creating Additional Admin Users

**Only do this if you want another staff member with admin access:**

1. Go to Admin Dashboard
2. Click "Players" (left menu)
3. Click "Create Player"
4. Fill in: Username, name, phone, email, password
5. After creation, click player's name
6. Change "Role" to "Admin" or "Super Admin"
7. Click Save

**Role Definitions:**
- **Player:** Can only view own profile, QR, balance
- **Admin:** Can manage players, approve top-ups, run reports
- **Super Admin:** All admin features + manage other admins

---

## 3. RAILWAY HOSTING ACCESS

### Railway.app Account Setup

**Your Railway Project:**
- URL: `https://railway.app/project/[FILL IN - Project ID]`

### How to Access Your Hosting Dashboard

**Step 1: Log Into Railway**
1. Go to https://railway.app
2. Log in with your account: [FILL IN - Your Railway Email]
3. Password: [FILL IN - Your Railway Password]

**Step 2: View Application Status**
1. Click on your project name
2. See active deployments (green = running, red = stopped)
3. View real-time logs

**Step 3: Manage Environment Variables**
1. In project, click "Variables"
2. See all configuration (database URL, app settings)
3. **Never edit these unless instructed by developer**

### Common Railway Tasks

| Task | Steps |
|------|-------|
| **Check if app is running** | Project page → green checkmark = running |
| **View error logs** | Click "Logs" → see recent activity |
| **Restart app** | Click "Deploy" → select latest → "Redeploy" |
| **Check database backup status** | PostgreSQL plugin → "Backups" tab |

---

## 4. DATABASE ACCESS

### PostgreSQL Database Connection

**Database Details:**

| Item | Value |
|------|-------|
| Host | [FILL IN - from Railway environment] |
| Port | [FILL IN - typically 5432] |
| Database Name | `falcon` |
| Username | [FILL IN - from Railway environment] |
| Password | [FILL IN - from Railway environment] |

**Complete Connection String (for reference):**
```
postgres://[USERNAME]:[PASSWORD]@[HOST]:[PORT]/falcon?sslmode=require
```

### Connecting to Database

**Option A: Using pgAdmin (Recommended for Non-Technical Users)**

1. Go to pgAdmin 4: https://pgadmin.railway.app (or your provider)
2. Click "Add Server"
3. Name: "Padol"
4. Connection tab:
   - Host: [FILL IN]
   - Port: 5432
   - Database: falcon
   - Username: [FILL IN]
   - Password: [FILL IN]
5. Click Save
6. Navigate: Servers → Padol → Databases → falcon → Tables

**Option B: Using Command Line (For Developers)**

```bash
psql -h [HOST] -p 5432 -U [USERNAME] -d falcon
```

Then enter password: [FILL IN]

**Option C: Using DBeaver (Desktop App)**

1. Download: https://dbeaver.io
2. Click "+" → New Database Connection
3. Select "PostgreSQL" → Next
4. Fill in connection details (above)
5. Click "Test Connection" → Finish

### Important: Backup Access

**Automatic Backups:**
- Location: Railway → PostgreSQL → Backups tab
- Frequency: Daily
- Retention: 30 days
- Cost: Included in Railway plan

**To Download a Backup:**
1. Go to Railway → PostgreSQL
2. Click "Backups"
3. Find backup you need
4. Click "Download"
5. File saves as `.sql.gz` (compressed SQL)

---

## 5. ENVIRONMENT VARIABLES

### All Configuration Variables

**These are stored in Railway and used by the application:**

| Variable | Purpose | Value |
|----------|---------|-------|
| `DATABASE_URL` | PostgreSQL connection | `postgres://...` |
| `APP_NAME` | System name | `Padol Pickleball` |
| `APP_URL` | Website base URL | `https://...railway.app` |
| `ENVIRONMENT` | Dev/Staging/Production | `production` |
| `SESSION_IDLE_TIMEOUT` | Minutes before logout | `20` |
| `CREDIT_PER_GAME` | Cost in PHP currency | `10.00` |
| `PLAYERS_PER_GAME` | Players required for auto-start | `4` |
| `PASS_DURATION_HRS` | Hours QR pass stays active | `8` |
| `MAX_UPLOAD_MB` | Max file upload size | `25` |
| `TOPUP_QR_MINS` | Top-up QR code validity | `15` |
| `LOW_CREDIT_ALERT` | Balance warning threshold | `10.00` |

### Never Edit These

❌ Do NOT modify environment variables unless you understand what they do
❌ Wrong values can break the entire system
❌ Contact developer if you need to change any

---

## 6. IMPORTANT FILE LOCATIONS

### Files You Need to Know About

**Configuration Files** (on Railway server):
- `/app/config/app.php` — Game settings, constants
- `/app/config/db.php` — Database connection (auto-configured)
- `/app/config/security.php` — Session timeout, CSRF settings
- `/app/config/cloudinary.php` — Image storage (if enabled)

**Upload Directories:**
- `/uploads/avatars/` — Player profile pictures
- `/uploads/payment_qr/` — Payment method QR codes
- `/uploads/topup_proofs/` — Player payment proof screenshots

**Database Schema:**
- `/migrations/001_initial_schema.sql` — 31 tables definition

**Important:** These files are already deployed. You don't edit them unless adding features.

---

## 7. RENEWAL REMINDERS

### Important Dates to Remember

| Item | Renewal Date | Cost | Action |
|------|--------------|------|--------|
| **Railway Hosting** | [FILL IN - Billing date] | Billed monthly or annually | Auto-renews (check billing) |
| **SSL Certificate** | Auto-renews | Free (Let's Encrypt) | No action needed |
| **Database Backups** | Continuous | Included in Railway | No action needed |
| **Domain Registration** | [FILL IN - If using custom domain] | ₱[FILL IN]/year | Renew annually |
| **Support Warranty** | Expires: June 1, 2026 | N/A (included) | See SUPPORT_WARRANTY.md |

### Annual Maintenance Checklist

**Every January:**
- [ ] Review hosting costs and renewal dates
- [ ] Check domain registration expiration
- [ ] Verify SSL certificate is valid
- [ ] Request developer update any dependencies (if security updates available)
- [ ] Review backup retention settings

---

## 8. EMERGENCY PROCEDURES

### System Goes Down

**Is the website not loading?**

**Step 1: Check Railway Status**
1. Go to https://status.railway.app
2. Is Railway having issues? (Wait for them to fix)
3. If Railway is UP but your app is DOWN → Step 2

**Step 2: Check if App is Running**
1. Log into Railway project
2. Look at project status (green = running, red = stopped)
3. If red: Click "Deploy" → Select latest → "Redeploy"
4. Wait 2-3 minutes for restart
5. Test website

**Step 3: Check Database Connection**
1. In Railway, click PostgreSQL
2. Check if database is running (green)
3. If red: Click restart (or contact Railway support)

**Step 4: Contact Developer**
If still not working:
- Email: [FILL IN]
- Phone: [FILL IN]
- Explain: What were you doing when it went down?

### Database Gets Corrupted or Data Lost

**Don't Panic — You Have Backups**

**Step 1: Stop Operations**
1. Tell players to stop using system immediately (post notice)
2. Don't make any transactions or changes
3. Document what happened and when

**Step 2: Restore from Backup**
1. Go to Railway → PostgreSQL → Backups
2. Find most recent backup before the problem
3. Click "Restore"
4. Confirm restore (will overwrite current database)
5. Wait 5-10 minutes for restore to complete

**Step 3: Verify Data**
1. Log into admin dashboard
2. Check player balances look correct
3. Check recent games are there
4. If data looks good → Resume operations

**Step 4: Notify Players**
1. Post message about brief downtime
2. Apologize for inconvenience
3. Explain system is restored

**Step 5: Report to Developer**
1. Email developer: What happened?
2. When did you notice the problem?
3. What changes were made before it happened?
4. Help prevent recurrence

### Payment Processing Not Working

**Top-ups not being approved or balance not updating?**

1. Check if player actually submitted the request
   - Go to Admin → Top-up Requests
   - Is the request visible?
2. Check if top-up is in "pending" status
   - Click the request
   - Is it showing pending?
3. Try approving manually
   - Click "Approve" button
   - Does balance update?
4. If nothing works:
   - Contact developer immediately
   - Manually add credits: Admin → Players → Find player → Adjust Balance

---

## 9. CHANGE LOG & HANDOVER TRACKING

### What Was Changed / Installed

| Item | Date | Changed By | Status |
|------|------|-----------|--------|
| System deployed to Railway | [FILL IN] | [FILL IN] | ✅ Complete |
| SSL certificate configured | [FILL IN] | [FILL IN] | ✅ Complete |
| Database backups enabled | [FILL IN] | [FILL IN] | ✅ Complete |
| Super Admin account created | [FILL IN] | [FILL IN] | ⚠️ Change password now |
| Payment methods configured | [FILL IN] | [FILL IN] | ✅ Complete |
| Court settings initialized | [FILL IN] | [FILL IN] | ✅ Complete |
| Staff trained | [FILL IN] | [FILL IN] | ✅ Complete |
| Documentation provided | [FILL IN] | [FILL IN] | ✅ Complete |
| [FILL IN] | [FILL IN] | [FILL IN] | [FILL IN] |

### First-Time Setup Checklist

Complete these immediately after receiving this document:

- [ ] Change Super Admin password
- [ ] Create staff admin account(s)
- [ ] Test player registration and QR scanning
- [ ] Set court hours and operating days
- [ ] Configure court credit cost (if not ₱10)
- [ ] Set up payment methods (GCash, Bank, Cash)
- [ ] Upload payment QR codes
- [ ] Test top-up process
- [ ] Configure court mode (open play vs reservation)
- [ ] Train staff on scanner operation
- [ ] Announce system launch to players
- [ ] Monitor first week for issues

### Monthly Checklist

First Monday of each month:

- [ ] Log into admin dashboard
- [ ] Check active game count (should be reasonable)
- [ ] Check number of registered players
- [ ] Review top-up requests (are they being processed?)
- [ ] Check database backup status (should see green)
- [ ] Look for error messages in logs
- [ ] Note any unusual activity or requests

---

## 10. QUICK REFERENCE

### Useful URLs

| Item | URL |
|------|-----|
| Main website | [FILL IN - Your Railway URL] |
| Admin dashboard | [FILL IN]/admin/dashboard.php |
| Scanner/Kiosk | [FILL IN]/court/scanner.php |
| Railway project | https://railway.app/project/[PROJECT ID] |
| Database backups | https://railway.app/project/[PROJECT ID] → PostgreSQL → Backups |

### Support Contact

| Issue | Contact | Response Time |
|-------|---------|----------------|
| Critical (system down) | [FILL IN - Phone] | 24 hours |
| Bug or error | [FILL IN - Email] | 48 hours |
| Questions | [FILL IN - Email] | 5 business days |

### Default Values (Don't Modify Without Reason)

- Game cost: ₱10 per 60 minutes
- Pass duration: 8 hours
- Players needed to start: 4
- Session timeout: 20 minutes
- Low balance alert: ₱10
- Max upload: 25 MB

---

**END OF HANDOVER GUIDE**

**Handover Date: April 2026**
**Version: 1.0**

*Keep this document in a safe place. You will need it if you hire a new developer or if you need to troubleshoot the system.*
