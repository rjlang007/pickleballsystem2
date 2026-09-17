# PADOL PICKLEBALL COURT
## Owner's Manual — Complete Administrator Guide
**Version 1.0 | Generated: April 2026**

---

## TABLE OF CONTENTS

1. [System Overview](#1-system-overview)
2. [Getting Started — First Login](#2-getting-started--first-login)
3. [Admin Dashboard Guide](#3-admin-dashboard-guide)
4. [Managing Players](#4-managing-players)
5. [Court Settings & Configuration](#5-court-settings--configuration)
6. [Schedule & Reservations Management](#6-schedule--reservations-management)
7. [Court Mode (Open Play vs Reservation)](#7-court-mode-open-play-vs-reservation)
8. [Payment Settings & Top-up Management](#8-payment-settings--top-up-management)
9. [Reports & Analytics](#9-reports--analytics)
10. [Court Scanner Operations](#10-court-scanner-operations)
11. [Security & User Roles](#11-security--user-roles)
12. [Backup & Data Management](#12-backup--data-management)
13. [Troubleshooting & FAQs](#13-troubleshooting--faqs)
14. [Support & Contact Information](#14-support--contact-information)

---

## 1. System Overview

### What is Padol Pickleball Court?

Padol Pickleball Court is a complete management system for running a modern pickleball facility. It handles:

- **Player Management** — Registration, verification, banning, account adjustments
- **Credit Wallet System** — Players load credits (like prepaid cards) to play games
- **Open Play Queue** — Players scan their QR code and join a waiting list
- **Court Reservations** — Players book specific time slots in advance
- **Payment Processing** — Admin approves top-up requests and manages payment methods
- **Live Court Monitor** — Real-time tracking of active games and player queues
- **Reports & Analytics** — Revenue tracking, player statistics, peak hours analysis
- **Scanner Integration** — Kiosk-based QR code scanning for game entry

### Key Business Model

1. **Players register** → **Load credits via top-up request** → **Pay through GCash/Bank/Cash** → **Admin approves** → **Credits appear in wallet**
2. **Players scan QR code** to join open play queue OR **book court in advance** for reservations
3. **System deducts ₱10.00 credits per game** (configurable per court)
4. **Admin monitors reports** for revenue, player activity, and peak times

### Technology Used

- **Backend:** PHP 8.0+
- **Database:** PostgreSQL
- **Web Server:** Nginx
- **Hosting:** Railway.app
- **Frontend:** HTML5, CSS3, JavaScript (responsive mobile-first)

---

## 2. Getting Started — First Login

### Initial Setup Checklist

#### Step 1: Access the Admin Panel

1. Go to your Railway deployment URL (provided by developer)
2. Append `/pickleball/admin/dashboard.php` to the URL
3. Example: `https://falcon-production-1.up.railway.app/pickleball/admin/dashboard.php`

#### Step 2: Login with Super Admin Credentials

- **Username:** [FILL IN - provided by developer]
- **Password:** [FILL IN - provided by developer]
- Click **"Sign In"**
- You'll be redirected to the Admin Dashboard

#### Step 3: Change Your Password Immediately

1. Look for **"Profile"** or **"Settings"** in the top navigation
2. Click **Change Password**
3. Enter your current password, new password (min 8 characters, must include uppercase letter and number)
4. Confirm new password
5. Click **Save**

#### Step 4: Configure Basic Settings

Before opening for players, configure these three critical areas:

**a) Court Settings** ([FILL IN]/admin/court_settings.php)
- Court name
- Daily credit cost per game (default: ₱10.00)
- Game duration in minutes (default: 60 minutes)
- Maximum players per game (default: 4)

**b) Payment Methods** ([FILL IN]/admin/payment_settings.php)
- Add GCash account with QR code
- Add Bank transfer details
- Add Cash payment option (if accepting at counter)

**c) Court Hours & Mode** ([FILL IN]/admin/court_mode.php)
- Set opening hours (e.g., 6 AM - 11 PM)
- Default mode: Open Play all day OR Reservations all day
- Special rules (e.g., 8 PM onwards = Open Play)

---

## 3. Admin Dashboard Guide

### Dashboard URL
**[FILL IN]/admin/dashboard.php**

### Dashboard Overview

[Dashboard showing: Key metrics grid at top, live games in middle, pending actions on right]

The dashboard is your command center. It displays:

### Top Statistics Panel

| Metric | What It Shows | Why It Matters |
|--------|--------------|----------------|
| **Total Players** | How many players have registered | Growth indicator |
| **New This Week** | Fresh registrations | Player acquisition rate |
| **Total Courts** | Number of courts in system | Venue capacity |
| **Active Courts** | How many courts are open now | Current operations |
| **Live Sessions** | Games happening right now | Real-time activity |
| **Games Today** | Completed games so far | Daily volume |
| **Pending Top-ups** | Awaiting your approval | Revenue in limbo |
| **Pending Reservations** | Waiting for confirmation | Booking queue |
| **Today's Revenue** | ₱ earned from games today | Daily income |
| **Monthly Revenue** | ₱ earned this month | Performance tracking |

### Live Court Monitor

Shows the **active game right now** with:
- **Court Name** — Which court is in use
- **Players On Court** — How many people playing
- **Time Remaining** — Minutes left until game ends
- **Queue Length** — How many players waiting
- **Session Type** — Open Play or Reservation

**What to do:**
- Click on the active game to see player list
- If a game has problems, you can **manually end the game** here
- Watch the queue count to see if it's growing (more demand)

### Pending Actions Section

#### Pending Top-Up Requests (Left Panel)
Shows the **5 most recent** top-up requests awaiting approval.

**Each request shows:**
- Player name & username
- Amount requested (₱)
- Payment method (GCash/Bank/Cash)
- Date/time submitted

**Actions:**
- **✅ Approve** — Adds credits to player's wallet, activates their QR pass
- **❌ Reject** — Player must try again
- **View Receipt** — See payment proof (screenshot for GCash, bank transfer reference)

#### Pending Reservations (Left Panel)
Shows the **5 most recent** court reservation requests.

**Each shows:**
- Player name
- Date & time slot
- Party size (how many people)
- Court name

**Actions:**
- **✅ Approve** — Confirms the booking (player sees it in their schedule)
- **❌ Reject** — Frees the slot for other players

### Recent Games Summary (Bottom)

Table of the **8 most recent completed games** showing:
- **Start Time** — When game began
- **Court** — Which court was used
- **Players** — How many played
- **Revenue** — Total credits charged (each player × credit cost)

---

## 4. Managing Players

### Players List URL
**[FILL IN]/admin/players.php**

### Accessing Player List

1. Click **"Players"** in the admin menu
2. You'll see a table of all registered players

### Player Table Columns

| Column | Meaning |
|--------|---------|
| **Username** | Player's login name |
| **Full Name** | Real name |
| **Email** | Contact email |
| **Phone** | Mobile number (09171234567) |
| **Verified** | ✅ Yes or ❌ No |
| **Banned** | ✅ Yes or ❌ No |
| **Balance** | Current credits in wallet (₱) |
| **Games** | Total games played |
| **Joined** | Registration date |

### Search & Filter

**Search Bar:** Type player username, name, email, or phone to find them

**Filters:**
- Filter by verification status
- Filter by ban status
- Sort by join date, games played, or balance

### Player Actions

#### ✅ Verify a Player

Unverified players can't play games or make reservations. To verify:

1. Find player in list (look for ❌ in Verified column)
2. Click on their row or find **"Verify"** button
3. Confirm modal appears
4. Click **"Verify Player"**
5. Player now shows ✅ and receives notification

**Why verify?** To ensure the person actually owns that phone/email.

#### 🚫 Ban a Player

Ban problematic players (violence, theft, harassment, non-payment).

1. Click **"Ban"** button on player's row
2. Enter **Ban Reason** (e.g., "Violent behavior on court")
3. Click **"Confirm Ban"**
4. Player is immediately locked out
5. Player receives notification with reason

**Banned players:**
- Can't login
- Can't scan QR code
- Can't make reservations
- Can still contact you to appeal

#### 🔓 Unban a Player

To lift a ban:

1. Look for banned player in list (look for ✅ in Banned column)
2. Click **"Unban"** button
3. Confirm
4. Player can login again
5. Player receives notification

#### 🔑 Reset Player Password

Player forgot their password or you need to force a change:

1. Click **"Reset Password"** on player's row
2. Enter a **temporary password** (min 8 characters, 1 uppercase, 1 number)
   - Example: `TempPass123`
3. Click **"Set Password"**
4. Player receives notification with their new temporary password
5. Next time they login, they'll be **forced to change it**

#### 💰 Adjust Player Balance

Add or deduct credits for various reasons:

1. Click **"Adjust Balance"** on player's row
2. Select **Operation:**
   - **Add Credits** — Top-up manually (e.g., loyalty bonus, refund)
   - **Deduct Credits** — Remove credits (e.g., equipment damage)
3. Enter **Amount** (₱)
4. Enter **Reason** (e.g., "Loyalty reward" or "Damaged racket")
5. Click **"Apply"**
6. Balance updates immediately
7. Player receives notification with the adjustment

**Example Use Cases:**
- **Add ₱100** "New player signup bonus"
- **Add ₱50** "Referral bonus"
- **Deduct ₱200** "Equipment damage — broken net"
- **Add ₱150** "Refund for cancelled reservation"

### CSV Export

To export all player data for accounting:

1. Click **"Export to CSV"** button (usually at bottom)
2. File downloads: `players_YYYY-MM-DD.csv`
3. Open in Excel with columns: ID, Username, Name, Email, Phone, Verified, Banned, Balance, Games, Join Date

---

## 5. Court Settings & Configuration

### Court Settings URL
**[FILL IN]/admin/court_settings.php**

### What You Can Configure

#### Basic Information

**Court Name**
- Default: "Padol Court"
- Change to your facility name

**Description**
- Optional text describing the court
- Example: "Outdoor premier court with lighting"

**Address**
- Physical location
- Example: "123 Sports Avenue, Manila"

#### Game Settings

**Daily Credit Cost Per Game** ₱
- How many credits each player loses per game
- Default: ₱10.00
- Example: If set to ₱15, each 60-minute game costs 15 credits per player

**Game Duration** (minutes)
- How long each game lasts
- Default: 60 minutes
- Examples: 45, 50, 60, 90 minutes
- ⚠️ Changing this affects future games only

**Warmup Time** (minutes)
- Grace period before game officially starts
- Players can arrive this many minutes early to warm up
- Default: 5 minutes

**Players Per Game**
- Minimum: 2, Maximum: 40
- Default: 4 (standard doubles)
- Determines queue size

#### Pass/Membership Settings

**Pass Duration** (hours)
- How long a player's QR pass remains active
- Default: 8 hours
- Even with ₱0 balance, pass stays active this many hours after first scan

#### How to Save Changes

1. Scroll to bottom
2. Click **"Save Settings"** (blue button)
3. Success message appears
4. Changes take effect immediately ✅

---

## 6. Schedule & Reservations Management

### Schedule URL
**[FILL IN]/admin/schedule.php**

### What is Schedule?

Schedule is where players **book courts in advance** (like reserving a hotel room). As admin, you:
- View all reservations
- Approve or reject bookings
- See court availability
- Manage conflicts

### Navigation

**Date Picker** (top left)
- Choose any date to view
- Arrow buttons: Previous/Next day
- Click date to jump to specific date

**Court Selector** (top left)
- If you have multiple courts, select which one to view
- Shows only that court's schedule

**Month Calendar** (left sidebar)
- Quick month view
- Dates with reservations are highlighted
- Click date to jump to that date

### Schedule Grid Display

Shows **time slots** (6 AM to 11 PM by default) with:

- **Time slots in 30-minute intervals**
- **Green slots** — Available (not booked)
- **Blue slots** — Reserved (booked)
- **Gray slots** — Outside operating hours

### Viewing a Reservation

**Click on any blue slot** to see reservation details:

- Player name & username
- Party size (how many people)
- Contact phone
- Admin notes
- Payment status

### Approving a Reservation

1. Click on the blue/pending reservation
2. Modal popup appears
3. Review the details
4. Click **"✅ Approve"**
5. Confirmation message
6. Player receives notification
7. Slot now shows as **confirmed** (darker blue)

⚠️ **Important:** Approving doesn't charge the player yet. They may be charged deposit when system is enabled for payments.

### Rejecting a Reservation

1. Click reservation
2. Click **"❌ Reject"**
3. Optional: Add rejection reason (e.g., "Court maintenance scheduled")
4. Player receives notification
5. Slot becomes available again

### Manual Slot Creation (Admin-Only)

Admins can create their own reservations (non-paying):

1. Click on **empty/green slot**
2. Modal appears: "Create Reservation"
3. Enter:
   - **Court** (which court)
   - **Date** (which day)
   - **Start Time** (what time)
   - **Duration** (how long, e.g., 1 hour = 2 slots)
   - **Label** (e.g., "Court Maintenance" or "Staff Practice")
   - **Notes** (optional)
4. Click **"Create"**
5. Slot is now blocked for players

**Use cases:**
- "Court Maintenance 2-4 PM"
- "Staff Training Session"
- "Sponsorship Event"

### Force Sync

If reservations seem out of sync with the database:

1. Click **"Force Sync"** button (bottom right)
2. System recalculates all slots
3. Success message

---

## 7. Court Mode (Open Play vs Reservation)

### Court Mode URL
**[FILL IN]/admin/court_mode.php**

### What is Court Mode?

At any given time, a court can operate in one of two modes:

| Mode | What Happens | Who Plays |
|------|--------------|-----------|
| **Open Play** | Players scan QR → join queue → play when their turn | Walk-ins, no booking |
| **Reservation** | Only pre-booked players can use court during that time | Players with confirmed bookings |

### Why Have Both?

- **Evenings (after 8 PM):** Open Play — more casual players, walk-ins welcome
- **Daytime (6 AM - 8 PM):** Reservation — serious players who book in advance
- **Weekends:** Open Play all day — high walk-in traffic

### How to Set Court Mode

#### Step 1: Select Court

1. Go to Court Mode page
2. In dropdown, select which court to configure
3. Only 1 court? It shows automatically

#### Step 2: Add a New Mode Rule

**Click "Add New Rule"**

Enter:

1. **Rule Type:**
   - ☐ **Specific Date** — Applies only to that one date (e.g., Dec 25)
   - ☐ **Day of Week** — Applies every week (e.g., every Friday)

2. **Select Date or Day:**
   - If "Specific Date": Pick a date from calendar
   - If "Day of Week": Select Monday-Sunday

3. **Time Range:**
   - ☐ **Whole Day** — Mode applies 12:00 AM to 11:59 PM
   - ☐ **Custom Hours** — Specify start time and end time
     - Example: 8:00 PM to 11:59 PM

4. **Select Mode:**
   - ☝️ **Open Play** — Players scan QR and join queue
   - 📅 **Reservation** — Only booked players can play

5. **Optional Note:**
   - Add reason (e.g., "Special open play Friday night")

6. **Click "Save Rule"**

#### Examples of Rules

**Example 1: Every Friday 8 PM - Close, Open Play**
```
Rule Type: Day of Week
Day: Friday
Time: 8:00 PM to 11:59 PM
Mode: Open Play
Note: "Friday Night Open Play"
```

**Example 2: Christmas Day, All Day, Reservations Only**
```
Rule Type: Specific Date
Date: December 25, 2026
Time: Whole Day
Mode: Reservation
Note: "Holiday — reservations only"
```

**Example 3: Every Day, 6 AM - 8 PM, Reservations**
```
Rule Type: Day of Week (repeat for Mon-Sun)
Time: 6:00 AM to 8:00 PM
Mode: Reservation
Note: "Business hours — bookings only"
```

### Viewing Rules

All rules are listed below the "Add Rule" form:

| Date | Time | Mode | Created By | Actions |
|------|------|------|-----------|---------|
| 2026-12-25 | 12:00 AM - 11:59 PM | Open Play | admin_name | 🗑 Delete |
| Every Friday | 8:00 PM - 11:59 PM | Open Play | admin_name | 🗑 Delete |

### Deleting a Rule

1. Find the rule in the list
2. Click **"🗑 Delete"**
3. Confirm deletion
4. Rule is removed

### What Happens When Mode Changes?

When you switch a time slot to **Open Play:**

1. All players get a **notification** 📬 "Open Play Announced"
2. Message tells them when the open play is available
3. Players see it on their home screen
4. Players can then scan QR to join that time slot

---

## 8. Payment Settings & Top-up Management

### Payment Settings URL
**[FILL IN]/admin/payment_settings.php**

### Top-Up Request Management URL
**[FILL IN]/admin/topup_requests.php**

### What is Top-Up?

Players need credits to play. Here's the flow:

```
Player goes to Dashboard 
  → Clicks "Top-Up"
  → Chooses payment method (GCash/Bank/Cash)
  → Sends payment
  → Takes screenshot as proof
  → Submits request
  → [YOU review and approve]
  → Credits appear in wallet
```

### Step 1: Configure Payment Methods

#### Add GCash Account

1. Go to Payment Settings
2. Click **"Add Payment Method"**
3. Fill in:
   - **Method Name:** "GCash" (or "GCash - Juan")
   - **Account Name:** "Padol Pickleball Court"
   - **Account Number:** Your GCash mobile number (e.g., "09171234567")
   - **Instructions:** "Send ₱ to this GCash number. Text 'TOPUP' with screenshot."
   - **QR Code Image:** Upload a photo of your GCash QR code
   - **Is Active:** ☑ Check this box to enable
   - **Sort Order:** Leave as 1 (determines display order)

4. Click **"Save"**

#### Add Bank Transfer

1. Click **"Add Payment Method"**
2. Fill in:
   - **Method Name:** "Bank Transfer" (or "BDO Transfer")
   - **Account Name:** "Padol Pickleball Court"
   - **Account Number:** Your bank account number
   - **Instructions:** "Transfer to BDO 123-456-789. Reference: Your Username"
   - **QR Code Image:** (optional) Bank transfer QR
   - **Is Active:** ☑ Check
3. Click **"Save"**

#### Add Cash Payment (Counter)

1. Click **"Add Payment Method"**
2. Fill in:
   - **Method Name:** "Cash at Counter"
   - **Account Name:** "Padol Pickleball Court"
   - **Account Number:** "In-Person Payment"
   - **Instructions:** "Visit the court and pay in cash to staff"
   - **Is Active:** ☑ Check
3. Click **"Save"**

### Step 2: Process Top-Up Requests

#### View Pending Requests

1. Go to **Top-Up Requests** page
2. Default view shows **Pending** requests
3. Table shows:
   - Player name & username
   - Amount (₱)
   - Payment method used
   - Reference number (for GCash/Bank)
   - Date submitted
   - Current balance

#### Reviewing a Request

**Click on any request** to expand and see:

- Full payment details
- Screenshot of proof (if GCash/Bank)
- Player's current balance
- Status

**Check for:**
- ✅ Amount matches what player claims
- ✅ Reference number is real (match with your GCash/Bank app)
- ✅ Screenshot shows correct amount
- ✅ Not a duplicate (same ref number used twice?)

#### Approve Single Request

1. Click request row
2. Look for **"✅ Approve"** button
3. Optional: Add review note (e.g., "Ref verified in GCash app")
4. Click **"Approve"**

**What happens:**
- Credits added to player's wallet
- Player receives notification: "✅ Top-Up Approved! ₱[amount] added to your account"
- Player's QR pass is activated
- Request shows in Approved tab

#### Approve Multiple Requests at Once

1. Click **"Select All"** checkbox at top
   - OR check individual checkboxes
2. Click **"Bulk Approve"** button (bottom left)
3. Confirmation modal
4. Click **"Approve All"**

**Saves time when you have many requests!**

#### Reject a Request

1. Click request row
2. Click **"❌ Reject"** button
3. Optional: Enter rejection reason
4. Confirm

**What happens:**
- Credits NOT added
- Player receives notification: "❌ Request rejected. Reason: [your reason]"
- Player can submit new request or contact support

#### Handling Disputed Requests

**Duplicate Reference Number:** Same ref used twice (fraud attempt)?
- ❌ Reject the duplicate

**Amount Mismatch:** Player says they sent ₱500 but receipt shows ₱300?
- Contact player for clarification
- Ask for corrected screenshot
- If unsure, reject and ask player to resubmit

**No Screenshot:** Player submitted without proof?
- Reject with reason: "No payment proof provided. Please resubmit with screenshot."

### Monthly Top-Up Summary

In the top section, you'll see:

- **Total Approved This Month:** How much money players have loaded
- **Pending Requests:** How much is waiting for approval
- **Pending Requests (₱):** Total value pending

**Example:**
- Approved: 15 requests, ₱7,500
- Pending: 3 requests, ₱1,200 total

---

## 9. Reports & Analytics

### Reports URL
**[FILL IN]/admin/reports.php**

### Dashboard Statistics

The reports page shows comprehensive business analytics:

#### Monthly Summary

Shows aggregate data for the selected month:

| Metric | What It Means |
|--------|--------------|
| **Games Completed** | Total finished games this month |
| **Games Cancelled** | Games that were cancelled |
| **Total Revenue (₱)** | Credits earned from games |
| **Total Refunded (₱)** | Credits returned to players |
| **Unique Players** | How many different people played |

#### Daily Breakdown

A table showing **each day of the month**:

- **Date**
- **Sessions** — How many games that day
- **Completed** — How many finished
- **Revenue (₱)** — Money earned that day

**Tip:** Look for patterns. Is Friday busier than Tuesday?

#### Top 10 Players by Games

Shows your most active/loyal players:

- **Username & Full Name**
- **Games Played** — Total count
- **Total Spent (₱)** — Total credits used

**Example:** "juan_dela_cruz" — 45 games, ₱450 spent

**Why care?** Identify VIPs for loyalty rewards or special events.

#### Peak Hours Analysis

Graph/chart showing **what time of day** is busiest:

- 6-7 AM: 2 games
- 7-8 AM: 5 games
- 8-9 AM: 3 games
- ... (continues through day)
- 8-9 PM: 12 games ← **PEAK**
- 9-10 PM: 8 games

**Use this to:**
- Schedule staff for peak times
- Plan maintenance during slow hours
- Offer promotions during slow times

#### Revenue by Court

If you have multiple courts:

| Court Name | Sessions | Revenue |
|------------|----------|---------|
| Court 1 | 48 | ₱480 |
| Court 2 | 52 | ₱520 |
| Court 3 | 31 | ₱310 |

**Use this to:** Identify which courts are most popular.

#### Credits Flow (All-Time)

- **Total Credits Distributed:** All credits ever approved in top-ups (₱)
- **Total Credits Spent:** Credits actually used in games (₱)
- **Pending Credits:** Players with credits sitting in wallets

### Date Selection

At the top: **Month selector** (e.g., "March 2026")

- Use arrows to go previous/next month
- Click month name to jump to specific month
- Reports recalculate for that month

### Export Functionality

Most reports have an **"Export to CSV"** button:

- Downloads spreadsheet file
- Open in Excel/Google Sheets
- Use for accounting, board reports, etc.

---

## 10. Court Scanner Operations

### Scanner URL
**[FILL IN]/court/scanner.php**

### What is the Scanner?

The Scanner is a **kiosk interface** (displayed on a tablet or PC at the court). Players scan their QR code here to:
- Join the open play queue
- Check in for their reservation
- Get added to active game

### Scanner Hardware Requirements

- **Device:** Tablet (iPad) or PC
- **QR Scanner:** USB barcode scanner OR phone camera
- **Display:** Large screen visible to waiting players
- **Location:** At court entrance or control desk

### Scanner Page Display

The scanner shows:

#### Top Section: Court Status

- **Court Name:** "Padol Court"
- **Current Game Status:**
  - No game: "Waiting for players..."
  - Game active: "Game in progress — [X] minutes remaining"
- **Current Mode:** "🎮 Open Play" or "📅 Reservation"

#### Queue Section (Left)

Shows **next 4 players waiting** to play:

```
Queue (4 waiting)
━━━━━━━━━━━━━━━━━
1. juan_dela_cruz    ✓ ACTIVE
2. maria_santos
3. carlos_reyes
4. anna_martinez
```

- **Green circle (✓):** Player QR verified
- **Red ✗:** Player QR NOT valid (insufficient credits)

#### Active Game Section (Middle)

If a game is playing right now:

```
🎮 ACTIVE GAME
Court: Court 1
Players: 4/4
Time: 45 minutes played
Remaining: 15 minutes
```

**Action Buttons:**
- **⏹ End Game** — Force end game (e.g., player got injured)
- **🔄 Refresh** — Update display

#### Today's Stats (Right)

Quick snapshot:

- **Games Today:** 12
- **Players Today:** 24
- **Queue Now:** 4 waiting
- **Time:** [current time]

### Using the Scanner

#### For Open Play

**Player walks up with phone:**

1. Player shows QR code on their phone (or passes/card if using printed QR)
2. Staff scans QR code with barcode scanner / camera
3. System checks:
   - ✅ QR is valid?
   - ✅ Player has ₱10+ balance?
   - ✅ Player is not banned?
4. **If all good:**
   - Player added to queue
   - Queue updates on screen
   - Player gets on-screen message: "✓ You've been added to queue. Position: #3"
5. **If problem:**
   - Screen shows error (e.g., "Insufficient balance. Load ₱50 credits first.")
   - Player cannot play

#### For Reservation

**Player arrives for their booked time:**

1. Check the **Schedule** for that day
2. Find their reservation in the time slot
3. Confirm they're actually here
4. If not showing in scanner system, manually add them
5. Player joins the active game for that slot

### Starting a New Game

When you have 4 players in queue:

1. Click **"🎮 Start Game"** button (if available)
2. First 4 players move to active game
3. Game timer starts counting down
4. Screen shows countdown

**Auto-start:** System can be set to auto-start when 4 players are queued.

### Ending a Game

After game duration (default 60 minutes):

1. Timer hits 0:00
2. Game automatically ends
3. Screen shows "Game Ended"
4. Credits are deducted from all 4 players' wallets
5. Next players in queue become active

**Manual end:** If injury or problem, click **"⏹ End Game"** to force end early.

### Troubleshooting Scanner

**QR won't scan:**
- Check scanner connectivity
- Make sure QR code on phone screen is visible and not dirty
- Try different angle / lighting

**"Player not found" error:**
- Player might not be registered yet
- Ask player to go to landing page and register first
- Then return and try again

**"Insufficient balance":**
- Player doesn't have enough credits
- Direct them to wallet to top-up
- Once approved by admin, they can try scanning again

**Game won't start:**
- May need ≥4 players for "auto-start"
- Click **"Start Game"** manually
- Verify all players' QR codes are valid (green checkmarks)

---

## 11. Security & User Roles

### User Roles & Permissions

There are 3 roles in the system:

| Role | Permissions | Can | Cannot |
|------|------------|-----|--------|
| **Super Admin** | Full system access | Everything | Nothing restricted |
| **Admin** | Most functions | Dashboard, players, reservations, reports | Can't delete system, can't create other admins |
| **Player** | Player functions only | Register, play, book courts, check balance | Access admin functions |

### Your Super Admin Account

You were created as **Super Admin**. This means:

- ✅ Access to all admin pages
- ✅ Can manage other admin accounts (if needed)
- ✅ Can delete players / data
- ✅ Emergency access to all features

**Responsibility:** Protect your Super Admin password.

### Session Security

#### Auto-Logout

For security, you're automatically logged out after:

- **20 minutes of inactivity** (no mouse/keyboard)
- **Warning:** 18 minutes in, you get a warning: "Session expiring in 2 minutes. Click to extend."
- You must click to stay logged in

#### What to Do If You Step Away

- **Never leave your computer unlocked** while logged in
- Minimize browser or lock your workstation
- Log out manually: Click your name (top right) → "Logout"

### Session Timeout Settings

- **Idle Timeout:** 20 minutes
- **Warning Before:** 2 minutes before timeout
- These are system-wide defaults (developer can adjust)

### Password Requirements

When you change your password or reset a player's password:

**Your password must:**
- ✅ Be at least 8 characters long
- ✅ Contain at least 1 uppercase letter (A-Z)
- ✅ Contain at least 1 number (0-9)
- ✅ NOT be the same as your last 5 passwords

**Examples:**
- ✅ `SecurePass123`
- ✅ `Padol2026Pro`
- ❌ `password123` (no uppercase)
- ❌ `PassWord` (no number)
- ❌ `Pass1` (too short)

### Account Security Checklist

- [ ] Changed password on first login
- [ ] Password is strong (8+ chars, uppercase, number)
- [ ] Never share admin password with players
- [ ] Don't leave logged-in computer unattended
- [ ] Change password every 90 days
- [ ] Report any suspicious activity to developer

---

## 12. Backup & Data Management

### Why Backups Matter

Your database contains:
- Player accounts & phone numbers
- Wallet balances & transaction history
- Reservation & booking data
- Payment records
- Revenue reports

**If lost:** You lose everything. **Always backup.**

### Automatic Backups

Railway (hosting platform) **automatically backs up your database:**

- Backups happen: Daily
- Retention: 30 days of backups available
- Location: Secure Railway servers

**You don't need to do anything — it's automatic!**

### Manual Backup (Database Export)

If you need to export data manually:

#### Via Railway Dashboard

1. Go to Railway.app
2. Login with your Railway account [FILL IN]
3. Select "Padol Pickleball" project
4. Go to "PostgreSQL" service
5. Click "Connect" → "Terminal"
6. Run command: `pg_dump -U [user] -d [database] > backup.sql`
7. Download the `.sql` file

⚠️ **Requires technical knowledge.** Contact developer if unsure.

#### Via Admin Export CSV

Simple way to export **player data**:

1. Go to **Admin Dashboard** → **Players**
2. Click **"Export to CSV"** button (bottom right)
3. File downloads: `players_2026-04-22.csv`
4. This includes: Username, name, email, phone, balance, games, join date

### Where Data is Stored

**Uploads/Files:**
- Player avatars: `/uploads/avatars/`
- Payment QR codes: `/uploads/payment_qr/`
- Top-up screenshots: `/uploads/screenshots/`
- Activity photos: `/uploads/activity_photos/`

**Database:**
- All tables in PostgreSQL `falcon` schema
- Hosted on Railway servers

### Data Deletion Policy

**Players can request deletion of their account:**

1. Ask player to email you request
2. Go to Admin → Players
3. Find player
4. Click "Delete Account" (if available)
5. Confirm deletion
6. Player data removed (though transaction history may remain for auditing)

**Admins can force delete:**
- Login to database and delete player record
- Not recommended unless authorized

---

## 13. Troubleshooting & FAQs

### Common Issues & Solutions

#### Issue: "Database Connection Error"

**Error Message:** "Cannot connect to database. Please check your connection."

**Causes:** 
- Railway server is down
- Network connection lost
- DATABASE_URL environment variable is wrong

**Solution:**
1. Check if Railway.app is up (railway.app/status)
2. Try refreshing the page (Ctrl+R)
3. Restart the application:
   - Go to Railway.app
   - Click "Padol Pickleball" project
   - Click "Redeploy"
   - Wait 2 minutes

#### Issue: "Player Can't Login"

**Problem:** Player says "Invalid username or password" but they're sure it's correct

**Causes:**
- Player is banned
- Account is not verified
- Username/password is truly wrong
- Account locked after 5 failed attempts

**Solution:**
1. Go to Admin → Players
2. Search for player
3. Check if **Banned:** ✅ → Unban if needed
4. Check if **Verified:** ❌ → Verify if needed
5. If still not working: Reset their password
6. Send them new temporary password
7. They'll be forced to change it on next login

#### Issue: "QR Scanner Not Working"

**Problem:** Barcode scanner doesn't scan QR codes

**Causes:**
- Scanner not connected
- QR code too faint/dirty
- Wrong angle or lighting
- QR code is expired

**Solution:**
1. Try manual typing: Scanner might have USB cable issue
   - Unplug and replug USB scanner
   - Try another USB port
2. Clean the phone screen or QR printout
3. Improve lighting at scanner station
4. Try different angle (45 degrees usually works best)
5. If using phone camera instead of barcode scanner, make sure camera is in focus

#### Issue: "Player Balance Shows Wrong Amount"

**Problem:** Player says they have ₱500 but dashboard shows ₱300

**Causes:**
- Balance not yet synced after recent transaction
- Wallet record corrupted in database
- Admin manually adjusted balance

**Solution:**
1. Refresh the page (Ctrl+R)
2. If still wrong:
   - Go to Admin → Players
   - Find player
   - Click "Adjust Balance"
   - Manually correct to ₱500
   - Enter reason: "Balance correction — sync issue"
   - Apply

#### Issue: "Top-Up Request Stuck as 'Pending'"

**Problem:** Player submitted request 2 days ago, still pending

**Causes:**
- You haven't reviewed it yet
- Admin forgot to approve
- Payment reference couldn't be verified

**Solution:**
1. Go to Admin → Top-Up Requests
2. Find the request (sorted by date)
3. Check payment screenshot
4. If valid: Click **Approve**
5. If payment not received: Click **Reject** with reason

#### Issue: "Reservation Not Showing in Schedule"

**Problem:** Player says they booked a court but it's not in schedule

**Causes:**
- Reservation is still "pending" (not yet approved)
- Reservation is for wrong date
- Database sync issue

**Solution:**
1. Go to Admin → Schedule
2. Change date to match booking date
3. Look for **blue slot** (pending) or **dark blue** (confirmed)
4. Click to expand details
5. If pending: Approve it
6. If not found: Ask player for confirmation email with date/time
7. Check if they booked different court

#### Issue: "System Slow / Laggy"

**Problem:** Pages loading very slowly, buttons lag

**Causes:**
- Too many active games or queue entries
- Database getting large
- Railway server CPU/memory maxed
- Browser cache full

**Solution:**
1. Clear browser cache: Ctrl+Shift+Delete
2. Check Railway dashboard:
   - railway.app → Padol Pickleball project
   - Look at CPU/Memory usage (should be <80%)
3. If usage high, try restarting:
   - Click "Redeploy" in Railway
   - Wait 2 minutes
4. If still slow: Contact developer for database optimization

#### Issue: "Email/Notifications Not Sending"

**Problem:** Players aren't getting approval notifications

**Causes:**
- Email service not configured
- Player email is invalid in database
- Notification service offline

**Solution:**
1. Check player email in Admin → Players
2. Is email valid? (e.g., juan@gmail.com)
3. Try manually sending test:
   - Adjust player balance by ₱1 with note "Test"
   - Player should get notification
4. If still not working: Contact developer

### FAQ

**Q: Can I have multiple admin accounts?**
A: Yes. Contact the developer to create additional admin accounts for your staff.

**Q: How do I recover a deleted player account?**
A: From Railway backups (ask developer). Data is recoverable up to 30 days.

**Q: Can I change the credit cost mid-month?**
A: Yes. Go to Court Settings and change "Daily Credit Cost Per Game". Only affects new games — doesn't refund old games.

**Q: What if a player disputes a charge?**
A: You can adjust their balance in Admin → Players → Adjust Balance. Deduct the credit amount with reason "Dispute refund".

**Q: How many players can the system handle?**
A: System tested for 10,000+ players. Currently limited only by your plan on Railway.

**Q: Can I set different credit costs for different times?**
A: Currently no. All times use same credit cost. Contact developer for time-based pricing.

**Q: Is there a mobile app for players?**
A: No separate app. Players use responsive website on their phones.

**Q: Can players see other players' profiles?**
A: No. Each player only sees their own dashboard and history.

**Q: What happens if system crashes during a game?**
A: Game data is safe in database. System will recover game status when restarted.

---

## 14. Support & Contact Information

### Getting Help

#### For Technical Issues

**Contact:** [FILL IN - Developer name and email]

**What to include in your message:**
- What were you doing when issue happened?
- What error message did you see?
- When did it start?
- Screenshots if possible

**Response time:** Usually within 24 hours

#### For General Questions

1. Check this manual first (search Ctrl+F)
2. Ask your staff if they know
3. Contact the developer

#### Emergency (System Down)

**If the entire system is not loading:**

1. Check Railway.app status
2. Try refreshing (Ctrl+R)
3. Try different browser (Firefox, Chrome, Safari)
4. If still down: Contact developer immediately (may be emergency number)

### Feedback & Feature Requests

Have an idea to improve the system?

1. Contact developer
2. Describe the feature clearly
3. Explain why you need it
4. Developer will advise if it's possible

### Scheduled Maintenance

Railway may perform maintenance (updates, security patches):

- **Notification:** Usually announced 24 hours in advance
- **Duration:** Usually 15-30 minutes
- **During maintenance:** System unavailable; players can't play
- **Plan around this:** Avoid scheduling big tournaments during announced maintenance

### Emergency Contacts

**Developer/Technical Support:**
- Name: [FILL IN]
- Email: [FILL IN]
- Phone: [FILL IN]
- Hours: [FILL IN]

**System Status:**
- Railway Status Page: https://railway.app/status

**Database Hosting:**
- Railway.app support: https://railway.app/support

---

## Appendix A: Quick Reference

### Frequently Used URLs

| Page | URL |
|------|-----|
| Admin Dashboard | [FILL IN]/admin/dashboard.php |
| Players Management | [FILL IN]/admin/players.php |
| Court Settings | [FILL IN]/admin/court_settings.php |
| Schedule & Reservations | [FILL IN]/admin/schedule.php |
| Court Mode | [FILL IN]/admin/court_mode.php |
| Payment Settings | [FILL IN]/admin/payment_settings.php |
| Top-Up Requests | [FILL IN]/admin/topup_requests.php |
| Reports | [FILL IN]/admin/reports.php |
| Court Scanner | [FILL IN]/court/scanner.php |

### Keyboard Shortcuts

- **Ctrl+R:** Refresh page (clear cache)
- **Ctrl+Shift+Delete:** Clear browser cache and cookies
- **Ctrl+F:** Search this page for text

### Default Values

- **Default Credit Cost:** ₱10.00 per game
- **Default Game Duration:** 60 minutes
- **Default Warmup Time:** 5 minutes
- **Default Players Per Game:** 4
- **Default Pass Duration:** 8 hours
- **Session Timeout:** 20 minutes
- **Low Credit Alert:** ₱10.00

---

**End of Owner's Manual**

*For the latest updates and support, contact the development team at [FILL IN].*

*Last Updated: April 2026*
