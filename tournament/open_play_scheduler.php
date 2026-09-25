<?php
// ============================================================
//  FILE: tournament/open_play_scheduler.php
//
//  Recurring daily Open Play. Admins set a schedule once (time
//  window + capacity + fee) in admin/open_play_settings.php and
//  this file keeps a fresh Open Play post available EVERY day,
//  with no staff involvement:
//
//    1. As soon as the previous session has ENDED — finalized by
//       staff, auto-finalized after its end time, or cancelled —
//       the next session's post is created immediately, so players
//       can sign up right away instead of waiting for the next day.
//    2. If today's session hasn't been posted yet (first run of
//       the day, app was down, schedule just enabled) it is created.
//    3. Sessions that are still running keep running: a session is
//       auto-finalized only after its end time (+ grace) once every
//       game on the courts is finished. The old 4 AM safety net
//       (cancel leftovers + finalize) is unchanged.
//
//  It is driven by runOpenPlayScheduler(), called from
//    - scripts/open_play_cron.php, a background worker started by
//      start.sh every 60 s (does not depend on anyone visiting), and
//    - ensureNightlyOpenPlayEvent(), the throttled page-load hook in
//      config/app.php, kept as a fallback.
//  Both are safe to run at the same time: a Postgres advisory lock
//  guarantees only one run executes at once, so a post can never be
//  duplicated.
//
//  Turning the schedule off (Enabled = off) stops NEW posts only; it
//  never touches a session that is already posted or in progress.
//  Cancelling a session closes only THAT date — the next day's post
//  still goes up automatically.
// ============================================================

const OPEN_PLAY_SCHEDULE_SECTION = 'open_play_schedule';
const OPEN_PLAY_DAY_CUTOVER_HOUR = 4;      // business day rolls over at 4:00 AM Manila
const OPEN_PLAY_TZ               = 'Asia/Manila';
const OPEN_PLAY_LOCK_KEY         = 74210031; // pg advisory lock: one scheduler run at a time

/**
 * "Business date" for Open Play purposes — the day doesn't roll over at
 * midnight, it rolls over at 4:00 AM. Open Play sessions routinely run
 * past midnight (default schedule is 6 PM – 12 AM, and some nights run
 * later), so anything before 4 AM still belongs to the previous calendar
 * day's event. Without this, ensureNightlyOpenPlayEvent() would see the
 * calendar date tick over at 00:00 while last night's event is still in
 * progress and try to auto-create *tomorrow's* event on top of it. Every
 * "what date is Open Play on" decision in this file goes through here so
 * the whole schedule — which event exists, which day-of-week hours apply,
 * whether the schedule is closed for a date — agrees on the same cutover.
 */
/** "Now" in Manila time (or the given moment converted to it) — injectable so the scheduler can be tested. */
function openPlayNow(?DateTimeImmutable $now = null): DateTimeImmutable
{
    $tz = new DateTimeZone(OPEN_PLAY_TZ);
    return $now ? $now->setTimezone($tz) : new DateTimeImmutable('now', $tz);
}

function openPlayBusinessDate(?DateTimeImmutable $now = null): string
{
    $now = openPlayNow($now);
    if ((int)$now->format('H') < OPEN_PLAY_DAY_CUTOVER_HOUR) {
        $now = $now->modify('-1 day');
    }
    return $now->format('Y-m-d');
}

/** Defaults if the admin has never saved settings yet. */
function openPlayScheduleDefaults(): array
{
    $defaults = [
        'enabled'      => '1',       // on by default: a new post every day (admins can switch it off)
        'start_time'   => '18:00',   // 6:00 PM
        'end_time'     => '00:00',   // 12:00 AM (midnight — spans past the start time)
        'max_players'  => '24',
        'price'        => '100',     // registration fee charged for each auto-posted event
        'format'       => 'doubles',
        'court_scope'  => 'all',
        'court_ids'    => '[]',      // JSON-encoded list, same shape saveOpenPlaySchedule() stores
        'name'         => 'Nightly Open Play',
        'description'  => 'Walk-in open play — join the queue any time and get paired as courts free up.',
        'closed_for_date' => '',
        'auto_finalize'          => '1',  // close a session automatically after its end time
        'finalize_grace_minutes' => '30', // ...once this many minutes have passed since the end time
        'notify_regulars'        => '1',  // tell last session's players when the next post goes up
    ];

    foreach (range(1, 7) as $day) {
        $defaults["day_{$day}_start_time"] = '18:00';
        $defaults["day_{$day}_end_time"] = '00:00';
    }

    return $defaults;
}

/** Court ids are stored as a JSON string in site_content; tolerate an already-decoded array too. */
function openPlayDecodeCourtIds($raw): array
{
    if (is_array($raw)) {
        return array_values(array_map('intval', $raw));
    }
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
}

/** Read the saved nightly-schedule settings, filled in with defaults for anything unset. */
function getOpenPlaySchedule(PDO $db): array
{
    $stmt = $db->prepare("SELECT key, value FROM falcon.site_content WHERE section = :s");
    $stmt->execute([':s' => OPEN_PLAY_SCHEDULE_SECTION]);
    $saved = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $saved[$row['key']] = $row['value'];
    }
    return array_merge(openPlayScheduleDefaults(), $saved);
}

function getOpenPlayClosedDate(PDO $db): ?string
{
    $row = $db->prepare("SELECT value FROM falcon.site_content WHERE section = :s AND key = 'closed_for_date' LIMIT 1");
    $row->execute([':s' => OPEN_PLAY_SCHEDULE_SECTION]);
    $value = trim((string)($row->fetchColumn() ?: ''));
    return $value !== '' ? $value : null;
}

function setOpenPlayClosedDate(PDO $db, ?string $dateYmd): void
{
    $value = $dateYmd ? trim((string)$dateYmd) : '';
    if ($value === '') {
        $db->prepare("DELETE FROM falcon.site_content WHERE section = :s AND key = 'closed_for_date'")->execute([':s' => OPEN_PLAY_SCHEDULE_SECTION]);
        return;
    }

    $db->prepare(
        "INSERT INTO falcon.site_content (section, key, value, updated_at)
         VALUES (:s, 'closed_for_date', :v, NOW())
         ON CONFLICT (section, key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()"
    )->execute([':s' => OPEN_PLAY_SCHEDULE_SECTION, ':v' => $value]);
}

function getOpenPlayScheduleForDate(PDO $db, ?string $date = null): array
{
    $schedule = getOpenPlaySchedule($db);
    $dt = new DateTimeImmutable($date ?? 'now', new DateTimeZone('Asia/Manila'));
    $day = (int)$dt->format('N');

    $schedule['start_time'] = trim((string)($schedule["day_{$day}_start_time"] ?? $schedule['start_time'] ?? '18:00'));
    $schedule['end_time']   = trim((string)($schedule["day_{$day}_end_time"] ?? $schedule['end_time'] ?? '00:00'));

    return $schedule;
}

/**
 * Validate and persist the schedule settings. Throws RuntimeException
 * with a human-readable message on bad input.
 */
function saveOpenPlaySchedule(PDO $db, array $data, int $actorId): array
{
    $enabled = !empty($data['enabled']) ? '1' : '0';

    $startTime = trim((string)($data['start_time'] ?? ''));
    $endTime   = trim((string)($data['end_time'] ?? ''));
    if ($startTime !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
        throw new RuntimeException('Start time must be a valid 24-hour HH:MM time.');
    }
    if ($endTime !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $endTime)) {
        throw new RuntimeException('End time must be a valid 24-hour HH:MM time.');
    }

    $maxPlayers = (int)($data['max_players'] ?? 0);
    if ($maxPlayers < 4 || $maxPlayers > 200) {
        throw new RuntimeException('Capacity must be between 4 and 200 players.');
    }

    $price = round((float)($data['price'] ?? 100), 2);
    if ($price < 0 || $price > 100000) {
        throw new RuntimeException('Registration fee must be between 0 and 100,000.');
    }

    $format = in_array($data['format'] ?? 'doubles', ['singles', 'doubles'], true)
        ? $data['format'] : 'doubles';

    $courtScope = ($data['court_scope'] ?? 'all') === 'selected' ? 'selected' : 'all';
    $courtIds = array_values(array_unique(array_filter(array_map('intval', (array)($data['court_ids'] ?? [])), fn($id) => $id > 0)));
    if ($courtScope === 'selected') {
        if (!$courtIds) throw new RuntimeException('Select at least one court, or choose all active courts.');
        $placeholders = implode(',', array_fill(0, count($courtIds), '?'));
        $courtCheck = $db->prepare("SELECT id FROM falcon.courts WHERE id IN ($placeholders) AND is_active = TRUE");
        $courtCheck->execute($courtIds);
        $courtIds = array_map('intval', $courtCheck->fetchAll(PDO::FETCH_COLUMN));
        if (!$courtIds) throw new RuntimeException('The selected courts are not active.');
        sort($courtIds);
    } else {
        $courtIds = [];
    }

    $graceMinutes = (int)($data['finalize_grace_minutes'] ?? 30);
    if ($graceMinutes < 0 || $graceMinutes > 240) {
        throw new RuntimeException('Auto-close grace period must be between 0 and 240 minutes.');
    }

    $name = trim((string)($data['name'] ?? '')) ?: openPlayScheduleDefaults()['name'];
    $name = substr($name, 0, 150);

    $description = trim((string)($data['description'] ?? ''));
    $description = substr($description, 0, 1000);

    $fields = [
        'enabled'     => $enabled,
        'start_time'  => $startTime ?: '18:00',
        'end_time'    => $endTime ?: '00:00',
        'max_players' => (string)$maxPlayers,
        'price'       => (string)$price,
        'format'      => $format,
        'court_scope' => $courtScope,
        'court_ids'   => json_encode($courtIds),
        'name'        => $name,
        'description' => $description,
        'auto_finalize'          => !empty($data['auto_finalize']) ? '1' : '0',
        'finalize_grace_minutes' => (string)$graceMinutes,
        'notify_regulars'        => !empty($data['notify_regulars']) ? '1' : '0',
    ];

    foreach (range(1, 7) as $day) {
        $dayStart = trim((string)($data["day_{$day}_start_time"] ?? $fields['start_time']));
        $dayEnd   = trim((string)($data["day_{$day}_end_time"] ?? $fields['end_time']));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $dayStart)) {
            throw new RuntimeException("Day {$day} start time must be a valid 24-hour HH:MM time.");
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $dayEnd)) {
            throw new RuntimeException("Day {$day} end time must be a valid 24-hour HH:MM time.");
        }
        $fields["day_{$day}_start_time"] = $dayStart;
        $fields["day_{$day}_end_time"] = $dayEnd;
    }

    $clearClosedForDate = !empty($data['clear_closed_for_date']) || !empty($data['reopen_schedule']);
    if ($clearClosedForDate) {
        setOpenPlayClosedDate($db, null);
    }

    $stmt = $db->prepare("
        INSERT INTO falcon.site_content (section, key, value, updated_at)
        VALUES (:s, :k, :v, NOW())
        ON CONFLICT (section, key)
        DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()
    ");
    $db->beginTransaction();
    foreach ($fields as $k => $v) {
        $stmt->execute([':s' => OPEN_PLAY_SCHEDULE_SECTION, ':k' => $k, ':v' => $v]);
    }
    $db->commit();

    if (function_exists('logActivity')) {
        try {
            logActivity('open_play_schedule_saved', 'tournament', 'normal', json_encode($fields), $actorId);
        } catch (Throwable $e) {
            // Non-critical — never let audit logging break settings saves.
        }
    }

    return $fields;
}
// ════════════════════════════════════════════════════════════
//  EVENT ↔ BUSINESS-DATE HELPERS
// ════════════════════════════════════════════════════════════

/**
 * Which business date an Open Play event belongs to. Auto-posted events
 * carry an explicit `scheduled_for` marker in their settings (immune to
 * odd start times and to staff editing the start date); older/manual
 * events fall back to the 4 AM cutover applied to their start_date.
 */
function openPlayEventBusinessDate(array $event): string
{
    $settings = $event['settings'] ?? [];
    if (!is_array($settings)) {
        $settings = json_decode((string)$settings, true) ?: [];
    }
    $marker = $settings['scheduled_for'] ?? null;
    if (is_string($marker) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $marker)) {
        return $marker;
    }
    return openPlayBusinessDate(
        new DateTimeImmutable((string)($event['start_date'] ?? 'now'), new DateTimeZone(OPEN_PLAY_TZ))
    );
}

/** Every non-cancelled Open Play event that belongs to the given business date. */
function findOpenPlayEventsForDate(PDO $db, string $date): array
{
    $cutover = (int)OPEN_PLAY_DAY_CUTOVER_HOUR;
    $stmt = $db->prepare("
        SELECT id, name, status, start_date, end_date, max_players, settings
          FROM falcon.tournaments
         WHERE bracket_type = 'open_play'
           AND status <> 'cancelled'
           AND COALESCE(settings->>'scheduled_for',
                        ((start_date - INTERVAL '{$cutover} hours')::date)::text) = :d
         ORDER BY id DESC
    ");
    $stmt->execute([':d' => $date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Start/end of the scheduled session for a business date, honouring the
 * per-weekday overrides. An end time at or before the start time runs
 * past midnight into the next calendar day (e.g. 18:00 → 00:00).
 *
 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: array}
 */
function openPlayScheduleWindow(PDO $db, string $date): array
{
    $tz       = new DateTimeZone(OPEN_PLAY_TZ);
    $schedule = getOpenPlayScheduleForDate($db, $date);
    $start    = new DateTimeImmutable($date . ' ' . $schedule['start_time'] . ':00', $tz);
    $endDay   = ($schedule['end_time'] <= $schedule['start_time'])
        ? (new DateTimeImmutable($date, $tz))->modify('+1 day')->format('Y-m-d')
        : $date;
    $end      = new DateTimeImmutable($endDay . ' ' . $schedule['end_time'] . ':00', $tz);
    return [$start, $end, $schedule];
}

/**
 * Has this session ended? Finalized counts, and so does being past its end
 * time (+ grace). Events without an end time (manually created ones) only
 * end when someone finalizes them or the 4 AM safety net does.
 */
function openPlayEventHasEnded(array $event, DateTimeImmutable $now, int $graceMinutes): bool
{
    if (($event['status'] ?? '') === 'completed') {
        return true;
    }
    if (($event['status'] ?? '') === 'cancelled') {
        return true;
    }
    if (empty($event['end_date'])) {
        return false;
    }
    $end = new DateTimeImmutable((string)$event['end_date'], new DateTimeZone(OPEN_PLAY_TZ));
    return $now >= $end->modify('+' . max(0, $graceMinutes) . ' minutes');
}

/** Small key/value helpers for scheduler bookkeeping in site_content. */
function openPlaySchedulerSet(PDO $db, string $key, string $value): void
{
    $db->prepare("
        INSERT INTO falcon.site_content (section, key, value, updated_at)
        VALUES (:s, :k, :v, NOW())
        ON CONFLICT (section, key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()
    ")->execute([':s' => OPEN_PLAY_SCHEDULE_SECTION, ':k' => $key, ':v' => $value]);
}

function openPlaySchedulerGet(PDO $db, string $key): ?string
{
    $stmt = $db->prepare("SELECT value FROM falcon.site_content WHERE section = :s AND key = :k LIMIT 1");
    $stmt->execute([':s' => OPEN_PLAY_SCHEDULE_SECTION, ':k' => $key]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string)$v;
}

// ════════════════════════════════════════════════════════════
//  THE SCHEDULER
// ════════════════════════════════════════════════════════════

/**
 * Page-load hook (config/app.php). Fallback for when the background
 * worker (scripts/open_play_cron.php) isn't running — throttled to one
 * run per minute so it's cheap on busy pages.
 */
function ensureNightlyOpenPlayEvent(): void
{
    if (!defined('DB_LOADED') || !defined('APP_NAME')) {
        return; // bootstrap not far enough along yet — mirrors auto_end_games.php's guard
    }
    try {
        runOpenPlayScheduler(false);
    } catch (Throwable $e) {
        error_log('[open_play_scheduler] run failed: ' . $e->getMessage());
    }
}

/**
 * One full scheduler pass:
 *   1. sweep no-shows on live events,
 *   2. auto-finalize sessions that are over,
 *   3. post the next session if the previous one has ended (or none is posted).
 *
 * @param bool                   $force Skip the page-load throttle (worker / "Run now" button).
 * @param DateTimeImmutable|null $now   Override "now" (tests).
 * @return array{ran: bool, reason: string, created: int[], finalized: int[]}
 */
function runOpenPlayScheduler(bool $force = false, ?DateTimeImmutable $now = null): array
{
    $result = ['ran' => false, 'reason' => '', 'created' => [], 'finalized' => []];

    try {
        $db = getDB();
    } catch (Throwable $e) {
        $result['reason'] = 'no database';
        return $result;
    }

    // ── Throttle (page-load path only) ──────────────────────
    if (!$force) {
        try {
            $last = (int)(openPlaySchedulerGet($db, 'last_check') ?? 0);
            if ((time() - $last) < 60) {
                $result['reason'] = 'throttled';
                return $result;
            }
            openPlaySchedulerSet($db, 'last_check', (string)time());
        } catch (Throwable $e) {
            error_log('[open_play_scheduler] throttle check failed: ' . $e->getMessage());
            $result['reason'] = 'throttle error';
            return $result;
        }
    }

    // ── One run at a time (worker + page loads + Run-now button) ──
    // Session-level advisory lock: a concurrent run simply skips instead
    // of racing us into creating a duplicate post.
    $locked = false;
    try {
        $stmt = $db->prepare('SELECT pg_try_advisory_lock(:k)');
        $stmt->execute([':k' => OPEN_PLAY_LOCK_KEY]);
        $locked = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[open_play_scheduler] lock failed: ' . $e->getMessage());
    }
    if (!$locked) {
        $result['reason'] = 'another run in progress';
        return $result;
    }

    try {
        $result['ran'] = true;
        $now = openPlayNow($now);
        openPlaySchedulerSet($db, 'last_run', (string)$now->getTimestamp());

        $today = openPlayBusinessDate($now);

        // Drop a stale "closed" marker from a past date so it can't linger.
        $closedDate = getOpenPlayClosedDate($db);
        if ($closedDate !== null && $closedDate < $today) {
            setOpenPlayClosedDate($db, null);
            $closedDate = null;
        }

        $schedule = getOpenPlaySchedule($db);
        $grace    = max(0, min(240, (int)($schedule['finalize_grace_minutes'] ?? 30)));

        // One admin/superadmin account to attribute automatic system actions to
        // (no-show sweeps, auto-finalize, auto-created events).
        $systemActorId = (int)($db->query(
            "SELECT id FROM falcon.users WHERE role IN ('super_admin','admin') ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($systemActorId <= 0) {
            error_log('[open_play_scheduler] no admin account found — nothing to do.');
            $result['reason'] = 'no admin account';
            return $result;
        }

        require_once __DIR__ . '/open_play_engine.php';
        $engine = new OpenPlayEngine();

        // 1) No-shows on every live event, so they're handled even when staff
        //    don't press Draw after a match expires.
        $active = $db->query(
            "SELECT id FROM falcon.tournaments
              WHERE bracket_type = 'open_play'
                AND status IN ('registration_open','in_progress','registration_closed','paused')"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($active as $activeEventId) {
            try { $engine->sweepNoShows((int)$activeEventId, $systemActorId); }
            catch (Throwable $e) { error_log('[open_play_scheduler] no-show sweep failed: ' . $e->getMessage()); }
        }

        // 2) Close out sessions that are over.
        $result['finalized'] = autoFinalizeStaleOpenPlayEvents(
            $db, $engine, $today, $systemActorId, $now,
            ($schedule['auto_finalize'] ?? '1') === '1', $grace
        );

        // 3) Post the next session.
        if (($schedule['enabled'] ?? '0') !== '1') {
            $result['reason'] = 'schedule disabled';
            return $result;
        }

        $tomorrow = (new DateTimeImmutable($today, new DateTimeZone(OPEN_PLAY_TZ)))->modify('+1 day')->format('Y-m-d');

        foreach ([$today, $tomorrow] as $sessionDate) {
            if ($closedDate !== null && $closedDate === $sessionDate) {
                continue; // this one date was closed by staff — tomorrow's post is unaffected
            }

            $events = findOpenPlayEventsForDate($db, $sessionDate);
            if ($events) {
                $stillOpen = array_filter(
                    $events,
                    static fn(array $e) => !openPlayEventHasEnded($e, $now, $grace)
                );
                if ($stillOpen) {
                    $result['reason'] = "session for {$sessionDate} is still open";
                    break; // that's the current post — nothing to add yet
                }
                continue; // that session is over — look at the next date
            }

            // Nothing posted for this date. Skip it if its window is already over
            // (e.g. schedule switched on at 1 AM) — go straight to the next one.
            [$start, $end, $sessionSchedule] = openPlayScheduleWindow($db, $sessionDate);
            if ($now >= $end->modify("+{$grace} minutes")) {
                continue;
            }

            $created = createScheduledOpenPlayEvent($db, $engine, $sessionSchedule, $sessionDate, $start, $end, $systemActorId);
            if ($created) {
                $result['created'][] = (int)$created['id'];
                $result['reason']    = "posted session for {$sessionDate}";
                error_log("[open_play_scheduler] posted Open Play #{$created['id']} for {$sessionDate}");
                if (($schedule['notify_regulars'] ?? '1') === '1') {
                    notifyOpenPlayRegulars($db, $created, $sessionDate, $start);
                }
            }
            break;
        }
    } catch (Throwable $e) {
        error_log('[open_play_scheduler] run failed: ' . $e->getMessage());
        $result['reason'] = 'error: ' . $e->getMessage();
    } finally {
        try {
            $db->prepare('SELECT pg_advisory_unlock(:k)')->execute([':k' => OPEN_PLAY_LOCK_KEY]);
        } catch (Throwable $e) {
            // The lock is session-scoped, so it's released when the connection closes anyway.
        }
    }

    return $result;
}

/**
 * Create the post for one session date. A brand-new event row means a
 * brand-new roster and payment collection every day — nobody carries over.
 */
function createScheduledOpenPlayEvent(
    PDO $db,
    OpenPlayEngine $engine,
    array $schedule,
    string $sessionDate,
    DateTimeImmutable $start,
    DateTimeImmutable $end,
    int $systemActorId
): ?array {
    $event = $engine->createEvent([
        'name'           => $schedule['name'],
        'description'    => $schedule['description'],
        'format'         => $schedule['format'],
        'court_scope'    => $schedule['court_scope'],
        'court_ids'      => openPlayDecodeCourtIds($schedule['court_ids'] ?? '[]'),
        'max_players'    => (int)$schedule['max_players'],
        'price'          => (float)($schedule['price'] ?? 0),
        'start_date'     => $start->format('Y-m-d H:i:s'),
        'end_date'       => $end->format('Y-m-d H:i:s'),
        'settings_extra' => ['scheduled_for' => $sessionDate, 'auto_posted' => true],
    ], $systemActorId);

    return !empty($event['id']) ? $event : null;
}

/**
 * Tell the people who played in the most recent finished session that the
 * next post is up. Best-effort — never lets a notification problem stop
 * the post itself.
 */
function notifyOpenPlayRegulars(PDO $db, array $newEvent, string $sessionDate, DateTimeImmutable $start): void
{
    try {
        $prev = $db->prepare("
            SELECT id FROM falcon.tournaments
             WHERE bracket_type = 'open_play' AND status = 'completed' AND id <> :new
             ORDER BY start_date DESC NULLS LAST, id DESC LIMIT 1
        ");
        $prev->execute([':new' => (int)$newEvent['id']]);
        $prevId = (int)($prev->fetchColumn() ?: 0);
        if ($prevId <= 0) {
            return; // first ever session — nobody to tell
        }

        $players = $db->prepare("
            SELECT DISTINCT tp.player_id
              FROM falcon.tournament_players tp
              JOIN falcon.users u ON u.id = tp.player_id
             WHERE tp.tournament_id = :tid
               AND tp.status = 'active'
               AND COALESCE(u.is_guest, FALSE) = FALSE
               AND COALESCE(u.is_active, TRUE) = TRUE
             LIMIT 300
        ");
        $players->execute([':tid' => $prevId]);

        $when    = $start->format('D, M j') . ' at ' . $start->format('g:i A');
        $message = "\"{$newEvent['name']}\" is posted for {$when}. Join the queue to get a spot.";
        $link    = (defined('APP_URL') ? APP_URL : '') . '/public/open_play.php';
        foreach ($players->fetchAll(PDO::FETCH_COLUMN) as $playerId) {
            notifyUser($db, (int)$playerId, '🎲 New Open Play is up', $message, null, $link);
        }
    } catch (Throwable $e) {
        error_log('[open_play_scheduler] regular notifications failed: ' . $e->getMessage());
    }
}

/**
 * Close out Open Play sessions that are over, exactly like a manual
 * "Finalize" (final standings written, podium rolled into the season
 * leaderboard) — just run by the system instead of a person.
 *
 *   • 4 AM safety net (unchanged): any session left open from a PREVIOUS
 *     business day is closed even if games are still marked live — those
 *     are cancelled first, since the venue is closed and no score is coming.
 *   • End-time close (new, on by default): once a session's end time +
 *     grace has passed and no game is still on a court, it is finalized.
 *     If a game is still running it waits — nobody's game gets cut off.
 *
 * Operations gets a notification either way.
 *
 * @return int[] ids of the events that were finalized
 */
function autoFinalizeStaleOpenPlayEvents(
    PDO $db,
    OpenPlayEngine $engine,
    string $today,
    int $systemActorId,
    ?DateTimeImmutable $now = null,
    bool $autoEndAtEndTime = true,
    int $graceMinutes = 30
): array {
    $now       = openPlayNow($now);
    $finalized = [];

    try {
        $candidates = $db->query("
            SELECT id, name, status, start_date, end_date, settings FROM falcon.tournaments
             WHERE bracket_type = 'open_play'
               AND status IN ('registration_open','in_progress','registration_closed','paused')
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[open_play_scheduler] stale-event lookup failed: ' . $e->getMessage());
        return $finalized;
    }

    foreach ($candidates as $row) {
        $tournamentId = (int)$row['id'];

        $isStale = openPlayEventBusinessDate($row) < $today; // left over from a previous business day
        $pastEnd = $autoEndAtEndTime
            && !empty($row['end_date'])
            && openPlayEventHasEnded($row, $now, $graceMinutes);

        if (!$isStale && !$pastEnd) {
            continue;
        }

        try {
            $stuck = $db->prepare("
                SELECT id FROM falcon.open_play_matches
                 WHERE tournament_id = :tid AND status IN ('ready','in_progress','paused')
            ");
            $stuck->execute([':tid' => $tournamentId]);
            $liveMatchIds = $stuck->fetchAll(PDO::FETCH_COLUMN);

            if ($liveMatchIds) {
                if (!$isStale) {
                    continue; // past its end time but games are still being played — let them finish
                }
                // Cancel them all in one go, with no replacement games drawn —
                // otherwise freed players would be re-paired and the event
                // could never be finalized.
                $engine->cancelAllOpenMatches($tournamentId, $systemActorId);
            }

            $standings = $engine->finalizeEvent($tournamentId, $systemActorId);
            $finalized[] = $tournamentId;
            $reason = $isStale ? '4am_cutover_not_finalized_by_staff' : 'end_time_reached';
            error_log("[open_play_scheduler] auto-finalized Open Play #{$tournamentId} ({$reason})");

            if (function_exists('logActivity')) {
                try {
                    logActivity('open_play_auto_finalized', 'tournament', 'normal', json_encode([
                        'tournament_id' => $tournamentId,
                        'players'       => count($standings),
                        'reason'        => $reason,
                    ]), $systemActorId);
                } catch (Throwable $e) {
                    // non-critical
                }
            }

            if (function_exists('notifyOperations')) {
                $eventName = (string)($row['name'] ?? 'Open Play');
                $why = $isStale
                    ? "wasn't finalized before 4 AM, so it was closed out automatically"
                    : 'reached its scheduled end time, so it was closed out automatically';
                notifyOperations(
                    $db,
                    '🌙 Open Play auto-finalized',
                    "\"{$eventName}\" {$why} — final standings were recorded and the podium was posted to the season leaderboard."
                );
            }
        } catch (Throwable $e) {
            error_log("[open_play_scheduler] auto-finalize failed for tournament {$tournamentId}: " . $e->getMessage());
        }
    }

    return $finalized;
}
