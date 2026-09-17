# FALCON PICKLEBALL COURT
## System Maintenance & Operations Guide

**System:** Falcon Pickleball Management System
**Facility:** [FILL IN - Your Court Name]
**Maintenance Contact:** [FILL IN - Developer Email]
**Prepared Date:** April 2026

---

## 1. DAILY CHECKS (5 MINUTES)

**Perform every morning before opening the facility**

### Checklist

- [ ] **Website loads** — Go to https://[YOUR URL] — Does it load in < 3 seconds?
- [ ] **Scanner works** — Go to https://[YOUR URL]/court/scanner.php — Can you see the queue?
- [ ] **Database connected** — Check admin dashboard → Should show live games
- [ ] **No error messages** — Admin dashboard should not show any red errors
- [ ] **Courts available** — Check court schedule → Are courts showing as available?

### What to Do If Something is Wrong

| Problem | Fix | Time |
|---------|-----|------|
| Website won't load | Check internet. Restart router. Contact developer. | 2-3 min |
| Scanner shows "Database Error" | Refresh page. If still broken, restart tablet. | 1 min |
| Dashboard shows "Connection Timeout" | Wait 2 minutes. Refresh. If persists, contact developer. | 2 min |
| Courts not showing up | Refresh page. Check if any recent admin changes. | 1 min |

---

## 2. WEEKLY CHECKS (30 MINUTES)

**Every Monday morning after daily check**

### Week Review Checklist

- [ ] **Login to admin dashboard** — Go to Admin → Dashboard
- [ ] **Check this week's statistics:**
  - Total games played (should be > 0)
  - Total players registered
  - Total revenue this week
  - Any unusual patterns?
- [ ] **Review player registrations** — Admin → Players
  - Any new players? (verify if needed)
  - Any suspicious accounts?
  - Any banned players you recognize?
- [ ] **Check pending top-ups** — Admin → Top-up Requests
  - All pending requests reviewed?
  - Any payment proofs look suspicious?
  - Approve/reject as appropriate
- [ ] **Review pending reservations** — Admin → Reservations
  - Any conflicts or issues?
  - Confirm all seem legitimate
- [ ] **Check payment methods** — Admin → Payment Settings
  - Are all methods active?
  - QR codes still visible?
  - Any payment method issues?

### Weekly Reporting Template

| Metric | This Week | Last Week | Trend |
|--------|-----------|-----------|-------|
| Games Played | [FILL IN] | [FILL IN] | ↑↓→ |
| Revenue | ₱[FILL IN] | ₱[FILL IN] | ↑↓→ |
| New Players | [FILL IN] | [FILL IN] | ↑↓→ |
| Top-ups Approved | [FILL IN] | [FILL IN] | ↑↓→ |
| Avg Players/Game | [FILL IN] | [FILL IN] | ↑↓→ |

### Weekly Data Export

1. Go to Admin → Reports
2. Select this week's date range
3. Click "Export CSV"
4. Save to: `C:\Pickleball\Reports\Week_[DATE].csv`
5. Keep for record

---

## 3. MONTHLY TASKS (1 HOUR)

**On the 1st of every month (except months you do 60-day review)**

### Month-End Review

- [ ] **Generate monthly report** — Admin → Reports → Select full month
- [ ] **Export all data** — Admin → Players → Export CSV
- [ ] **Review revenue** — Should match expected revenue
- [ ] **Check top players** — Admin → Reports → Top Players
- [ ] **Review hours of operation** — Admin → Court Hours
  - Are settings still correct for season?
  - Any holidays coming up?
- [ ] **Check court settings** — Admin → Court Settings
  - Credit cost still correct?
  - Game duration still correct?
- [ ] **Review failed logins** — Admin → Audit Log
  - Any suspicious activity?
  - Brute force attempts?
- [ ] **Database size check** — Ask: Is website running slow?
  - If yes, may need database optimization

### Monthly Report Template

**Month:** [FILL IN - Month/Year]

| Metric | Value |
|--------|-------|
| Games Completed | [COUNT] |
| Games Cancelled | [COUNT] |
| Total Revenue | ₱[AMOUNT] |
| Unique Players | [COUNT] |
| New Registrations | [COUNT] |
| Top Player | [NAME] ([GAMES] games) |
| Peak Hour | [HOUR] AM/PM |
| Avg Players/Game | [AVERAGE] |

---

## 4. DATABASE BACKUP PROCEDURES

### Automatic Backups (You Don't Need to Do Anything)

**Daily Backups Configured:**
- Frequency: Every day at 2 AM
- Retention: 30 days of backups kept
- Location: Railway cloud storage
- Cost: Included in hosting plan
- Manual Override: You can trigger manual backup anytime

### How to Verify Backups Are Working

**Step 1: Check Railway Dashboard**
1. Log into Railway: https://railway.app
2. Go to your project
3. Click "PostgreSQL" (database plugin)
4. Click "Backups" tab
5. Should see list of daily backups (green = successful)

**Step 2: Verify Recent Backup**
- Look for today's date
- Status should say "Ready" or "Completed" (not "Failed")
- If backup says "Failed" — Contact developer

### Manual Backup (Optional - For Extra Safety)

**If you want to back up RIGHT NOW (before major changes):**

1. Log into Railway
2. Click PostgreSQL
3. Click "Backups" tab
4. Click "Create Backup" button
5. Wait 2-5 minutes
6. Should see new backup in list (marked with today's date)

---

## 5. DATABASE RESTORE PROCEDURES

### Only Do This If Something Goes Wrong

**⚠️ WARNING: Restore will replace current database with backup copy**

### When to Restore

✅ **DO restore if:**
- You accidentally deleted important data
- A staff member made wrong changes
- You suspect data corruption
- A major bug occurred that corrupted data

❌ **DON'T restore if:**
- You just want to go back in time to see old data
- You want to undo a recent legitimate transaction
- It's just a minor issue

### How to Restore from Backup

**Step 1: Stop All Operations**
1. Tell staff to stop using system
2. Post message to players: "Scheduled maintenance, back soon"
3. Don't make any new transactions

**Step 2: Choose Which Backup**
1. Go to Railway → PostgreSQL → Backups
2. Look at list of backups
3. Choose the one from BEFORE the problem happened
   - Example: If bad data was entered today, choose yesterday's backup
4. Click on that backup

**Step 3: Restore**
1. Click "Restore" button
2. Confirm: "Are you sure?" → Click "Yes, restore"
3. System will show progress
4. Wait 5-10 minutes for restore to complete
5. Don't refresh or interrupt

**Step 4: Verify Data**
1. Log into admin dashboard
2. Check: Do player balances look correct?
3. Check: Is revenue showing correctly?
4. Check: Are recent games visible?
5. If good → Continue to Step 5
6. If bad → Contact developer immediately

**Step 5: Resume Operations**
1. Tell staff system is restored
2. Remove maintenance message
3. Monitor closely for next hour
4. Report issue to developer

---

## 6. UPDATE COURT SETTINGS

### Changing the Credit Cost Per Game

**Example: Change from ₱10 to ₱15 per game**

1. Log in as admin
2. Go to Admin → Court Settings
3. Find "Credit Cost" field
4. Change from `10` to `15`
5. Click "Save"

**When Does It Take Effect?**
- New games will charge ₱15
- Games already in progress: Not affected
- Players who already paid: Not affected
- Only applies to future games

### Changing Game Duration

**Example: Change from 60 minutes to 45 minutes**

1. Go to Admin → Court Settings
2. Find "Game Duration (minutes)" field
3. Change from `60` to `45`
4. Click "Save"

**Effect:**
- Games will now auto-end after 45 minutes (instead of 60)
- Does not affect games already running
- All new games will be 45 minutes

### Changing Players Required to Start

**Example: Change from 4 to 3 players per game**

1. Go to Admin → Court Settings
2. Find "Players Per Game" field
3. Change from `4` to `3`
4. Click "Save"

**Effect:**
- Queue will auto-start after 3 players (not 4)
- Only affects future games

### Court Hours

**Example: Open 7 AM, Close 11 PM (every day)**

1. Go to Admin → Court Hours
2. For each day (Mon-Sun):
   - Open Time: `07:00`
   - Close Time: `23:00`
   - Is Closed: Unchecked
3. Click "Save"

**Days Closed:**
- To close a day (e.g., Monday):
  - Click "Is Closed" checkbox for Monday
  - Click "Save"
  - Court won't be bookable on Mondays

---

## 7. MANAGE PLAYERS

### Verify New Players

**New unverified players can't join games.**

**Step 1: See unverified players**
1. Admin → Players
2. Filter by: Status = "Unverified"
3. See list of new registrations

**Step 2: Verify each player**
1. Click on player's name
2. Review their info (name matches, valid phone, etc.)
3. Click "Verify" button
4. Click "Save"

**Effect:** Player can now join games and use the system

### Ban a Player (For Rule Violations)

**Example: Player was rude, violating rules**

1. Admin → Players
2. Find the player
3. Click on their name
4. Click "Ban" button
5. Enter reason: "Aggressive behavior, yelling at other players"
6. Click "Save"

**Effect:**
- Player gets notification: "Your account has been suspended"
- Player cannot login
- Player's QR code won't work
- Player cannot join any games

### Unban a Player

**If player appeals or promise to follow rules**

1. Admin → Players
2. Find the banned player
3. Click on their name
4. Click "Unban" button
5. Leave reason blank (or enter: "Appeal granted")
6. Click "Save"

**Effect:**
- Player can login again
- QR code works
- Can join games

### Reset Player Password

**If player forgot password:**

1. Admin → Players
2. Find player
3. Click on their name
4. Click "Reset Password" button
5. Enter temporary password (e.g., "Temp123456")
6. Click "Save"
7. Tell player their temp password
8. Player must change it on first login

### Adjust Player Balance

**Add credits (top-up manually):**

1. Admin → Players
2. Find player (e.g., "Juan Dela Cruz")
3. Click on their name
4. Click "Adjust Balance"
5. Select "Add Credits"
6. Enter amount: `500`
7. Reason: "Cash top-up at counter"
8. Click "Save"

**Effect:** Player's balance increases by ₱500

**Deduct credits (refund or correction):**

1. Same as above, but select "Deduct Credits"
2. Enter amount
3. Reason: "Refund - cancelled reservation" or "Correction - double-charged"
4. Click "Save"

**Effect:** Player's balance decreases

---

## 8. COMMON ERROR MESSAGES & SOLUTIONS

### Error Reference Table

| Error Message | Cause | Solution |
|-------|--------|----------|
| **"Connection Timeout"** | Database unreachable | Wait 2 min. If persists, restart railway app. Contact dev. |
| **"Insufficient Balance"** | Player has < ₱10 | Player must top-up credits. Show top-up page. |
| **"Account Suspended"** | Player was banned | Check admin → players. Unban if needed. |
| **"Invalid QR Code"** | QR not recognized by scanner | Clean barcode scanner lens. Try manual entry. |
| **"Password Too Weak"** | Password doesn't meet requirements | Need: 8+ chars, 1 UPPERCASE, 1 number. Example: `Juan123` ✓ |
| **"Username Already Taken"** | Someone else has username | Try different username. Add numbers (e.g., `juan_2024`) |
| **"Email Already Used"** | Email registered to another account | Verify email. Can only use one account per email. |
| **"File Too Large"** | Avatar > 25MB | Use smaller file. Compress image before uploading. |
| **"Payment Proof Required"** | Player didn't attach screenshot | Ask player to take photo of GCash/bank transfer. |
| **"No Available Courts"** | All courts booked | Check calendar. Show player available dates. |
| **"Session Expired"** | Logged out after 20 minutes idle | Click Login. Re-enter credentials. |
| **"Access Denied"** | Trying to access wrong role (e.g., player accessing admin) | Log in with correct account role. |
| **"Cannot Connect to Email"** | Email service down | Not critical. Use email as fallback. |

---

## 9. PERFORMANCE MONITORING

### Is Your System Running Slow?

**Step 1: Identify the Problem**

Test on different browsers:
- [ ] Try Chrome
- [ ] Try Firefox
- [ ] Try Safari
- [ ] Try Edge

Is it slow on all browsers or just one?

**Step 2: Check Internet Connection**

- [ ] Run speed test: https://speedtest.net
- [ ] Your upload speed should be > 10 Mbps
- [ ] Download speed should be > 50 Mbps

**Step 3: Check Server Status**

1. Go to https://status.railway.app
2. Is Railway having issues? (green = OK, red = issues)
3. If red = wait for Railway to fix

**Step 4: Check Admin Dashboard**

1. Admin → Dashboard
2. Look at "Live Games" counter
3. If > 100 games running simultaneously = system may slow down (normal limit is ~50 concurrent games)

**Step 5: Contact Developer If:**

- Slow on all browsers
- Internet speed is good
- Railway status is green
- No unusual number of live games

### Optimizing Performance

**Temporary Speed-Ups:**

1. Clear browser cache:
   - Ctrl + Shift + Delete (Windows)
   - Cmd + Shift + Delete (Mac)
   - Select "All time"
   - Clear cache
2. Close unused tabs
3. Restart browser
4. Restart device if very slow

**Permanent Optimization (Developer Only):**

- Upgrade Railway plan (more CPU/memory)
- Optimize database indexes
- Enable caching
- Scale to multiple servers

---

## 10. WHEN TO CONTACT DEVELOPER

### Emergency Support (24-Hour Response)

**Contact immediately if:**
- ✅ Website completely down
- ✅ Database offline / can't access any data
- ✅ All player accounts showing ₱0 (data corruption)
- ✅ Money being charged incorrectly (critical bug)
- ✅ Security breach suspected (unauthorized access)

**Email:** [FILL IN]
**Phone:** [FILL IN]
**Subject Line:** `[URGENT] Falcon System Issue — [Brief description]`

---

### Standard Support (5-Day Response)

**Contact for:**
- ✅ Minor bugs (UI issue, typo, misalignment)
- ✅ Feature questions (how does X work?)
- ✅ Performance optimization
- ✅ New feature requests
- ✅ System capacity planning (need to handle 5,000 players?)

**Email:** [FILL IN]
**Subject Line:** `[STANDARD] Falcon Question — [Brief description]`

---

### How to Write a Good Support Email

**Include:**
1. What were you doing?
2. What did you expect to happen?
3. What actually happened?
4. When did it happen?
5. How many times have you seen it?
6. Screenshot (if possible)
7. Your browser/device info

**Example:**

```
Subject: [URGENT] QR Scanner Not Working

Hi [Developer],

This morning at 10:30 AM, I tried to scan a player's QR code at the court 
(court #2). The scanner displayed "Invalid QR Code" even though the player 
has a ₱500 balance.

I tried:
- Cleaning the scanner lens
- Restarting the tablet
- Scanning a different player's QR (same error)

The scanner worked fine yesterday. This is preventing players from joining 
games.

Browser: Chrome (latest)
Device: iPad Air 2024
Screenshots attached: [error-screen.png]

What should I do?

Thanks,
[Your Name]
```

---

## 11. MONTHLY MAINTENANCE SUMMARY

### Checklist Template (Copy & Fill Monthly)

**Month: [FILL IN]**

| Task | Date Done | Status | Notes |
|------|-----------|--------|-------|
| Daily checks done (5 min x 30 days) | [Date] | ✅ | [Any issues?] |
| Weekly check done (Mon mornings) | [Date] | ✅ | [Revenue trend?] |
| Players managed (verified, banned, etc.) | [Date] | ✅ | [How many verified?] |
| Top-ups reviewed & approved | [Date] | ✅ | [Total amount?] |
| Court settings reviewed | [Date] | ✅ | [Any changes?] |
| Database backups verified | [Date] | ✅ | [30 recent backups?] |
| Monthly report generated | [Date] | ✅ | [Revenue ↑↓→?] |
| Issues reported to developer | [Date] | ✅ | [Any urgent issues?] |

---

**END OF MAINTENANCE GUIDE**

**Prepared By:** [FILL IN - Developer Name]
**Date:** April 2026
**Version:** 1.0

*Keep this guide accessible to all staff members. Reference it regularly to keep the system running smoothly.*
