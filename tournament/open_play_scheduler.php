<?php
// ============================================================
//  FILE: tournament/open_play_scheduler.php
//
//  Recurring nightly Open Play. Rather than staff manually
//  creating an Open Play event from staff/open_play_control.php
//  every single day, admins set a schedule once (time window +
//  capacity) in admin/open_play_settings.php, and this file's
//  ensureNightlyOpenPlayEvent() makes sure today's event exists —
//  self-healing, the same pattern already used for auto-ending
//  games (see court/auto_end_games.php): checked on normal page
//  loads, rate-limited so it isn't a heavy query on every request,
//  with settings/state kept in falcon.site_content.
//
//  Turning the schedule off (e.g. the whole venue is rented out
//  for a private event tonight) simply stops new events from
//  being auto-created — it never touches an event that's already
//  been posted or is in progress.
// ============================================================

const OPEN_PLAY_SCHEDULE_SECTION = 'open_play_schedule';

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
function openPlayBusinessDate(?DateTimeImmutable $now = null): string
{
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    if ((int)$now->format('H') < 4) {
        $now = $now->modify('-1 day');
    }
    return $now->format('Y-m-d');
}

/** Defaults if the admin has never saved settings yet. */
function openPlayScheduleDefaults(): array
{
    $defaults = [
        'enabled'      => '0',       // off until an admin turns it on
        'start_time'   => '18:00',   // 6:00 PM
        'end_time'     => '00:00',   // 12:00 AM (midnight — spans past the start time)
        'max_players'  => '24',
        'price'        => '0',       // registration fee charged for each auto-posted event
        'format'       => 'doubles',
        'court_scope'  => 'all',
        'court_ids'    => [],
        'name'         => 'Nightly Open Play',
        'description'  => 'Walk-in open play — join the queue any time and get paired as courts free up.',
        'closed_for_date' => '',
    ];

    foreach (range(1, 7) as $day) {
        $defaults["day_{$day}_start_time"] = '18:00';
        $defaults["day_{$day}_end_time"] = '00:00';
    }

    return $defaults;
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

    $price = round((float)($data['price'] ?? 0), 2);
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

/**
 * Self-healing check: if the nightly schedule is enabled and today
 * doesn't have an Open Play event yet, create one. Safe to call on
 * every request — internally rate-limited to roughly once every
 * 5 minutes via falcon.site_content, exactly like auto_end_games.php's
 * _autoend_should_run().
 */
function ensureNightlyOpenPlayEvent(): void
{
    if (!defined('DB_LOADED') || !defined('APP_NAME')) {
        return; // bootstrap not far enough along yet — mirrors auto_end_games.php's guard
    }

    try {
        $db = getDB();
    } catch (Throwable $e) {
        return;
    }

    try {
        $row = $db->query("
            SELECT value FROM falcon.site_content
            WHERE section = 'open_play_schedule' AND key = 'last_check'
            LIMIT 1
        ")->fetch();
        $last = $row ? (int)$row['value'] : 0;
        if ((time() - $last) < 300) {
            return; // checked recently, nothing to do yet
        }
        $db->prepare("
            INSERT INTO falcon.site_content (section, key, value, updated_at)
            VALUES ('open_play_schedule', 'last_check', :v, NOW())
            ON CONFLICT (section, key)
            DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()
        ")->execute([':v' => (string)time()]);
    } catch (Throwable $e) {
        error_log('[open_play_scheduler] rate-limit check failed: ' . $e->getMessage());
        return;
    }

    try {
        // Business date, not calendar date — see openPlayBusinessDate()
        // above. Keeps this check from rolling over to "tomorrow" at
        // midnight while tonight's event is still running.
        $today = openPlayBusinessDate();
        $closedForDate = getOpenPlayClosedDate($db);

        // One admin/superadmin account to attribute automatic system
        // actions to (no-show sweeps, auto-finalize, auto-created events) —
        // there's always at least one in a working install.
        $systemActorId = (int)($db->query(
            "SELECT id FROM falcon.users WHERE role IN ('super_admin','admin') ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);

        if ($systemActorId > 0) {
            require_once __DIR__ . '/open_play_engine.php';
            $engine = new OpenPlayEngine();

            // Sweep every active Open Play event on the same five-minute
            // cadence as event creation, so no-shows are handled even when
            // staff do not press Draw again after a match expires.
            $activeEvents = $db->query("SELECT id FROM falcon.tournaments WHERE bracket_type = 'open_play' AND status IN ('registration_open','in_progress','registration_closed','paused')")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($activeEvents as $activeEventId) {
                try { $engine->sweepNoShows((int)$activeEventId, $systemActorId); }
                catch (Throwable $e) { error_log('[open_play_scheduler] no-show sweep failed: ' . $e->getMessage()); }
            }

            // Safety net for the night an admin/operator forgets to hit
            // "Finalize": once the business date has rolled past 4 AM,
            // any Open Play event still open from a *previous* business
            // day is closed out automatically — final standings written,
            // podium recorded to the season leaderboard — exactly like a
            // manual finalize, just run by the system instead of a person.
            autoFinalizeStaleOpenPlayEvents($db, $engine, $today, $systemActorId);
        }

        if ($closedForDate !== null && $today >= $closedForDate) {
            return; // master schedule disabled from the closed date onward
        }

        $schedule = getOpenPlayScheduleForDate($db, $today);
        if ($schedule['enabled'] !== '1') {
            return; // admin has this turned off (e.g. venue rented out tonight)
        }

        // Already have one for today (any status other than cancelled)? Don't duplicate.
        $exists = $db->prepare("
            SELECT id FROM falcon.tournaments
             WHERE bracket_type = 'open_play'
               AND DATE(start_date) = :today
               AND status != 'cancelled'
             LIMIT 1
        ");
        $exists->execute([':today' => $today]);
        if ($exists->fetchColumn()) {
            return;
        }

        $startDate = $today . ' ' . $schedule['start_time'] . ':00';
        // End time is same-night unless it's earlier than the start time,
        // in which case it rolls into the next calendar day (e.g. 18:00 -> 00:00).
        $endDay  = ($schedule['end_time'] <= $schedule['start_time'])
            ? date('Y-m-d', strtotime($today . ' +1 day'))
            : $today;
        $endDate = $endDay . ' ' . $schedule['end_time'] . ':00';

        // tournaments.created_by is a real FK to falcon.users(id), so the
        // auto-created event still needs to be attributed to a real admin
        // account — it's just not tied to whichever staff member happens
        // to be on shift when the nightly check fires.
        if ($systemActorId <= 0) {
            error_log('[open_play_scheduler] no admin account found — skipping auto-create.');
            return;
        }

        require_once __DIR__ . '/open_play_engine.php';
        $engine = $engine ?? new OpenPlayEngine();

        // Fresh post for the new day: a brand-new event row means a brand
        // new roster (nobody carries over from last night — everyone who
        // wants in joins and pays again) and, since price comes from the
        // schedule settings rather than the finished event, a fresh
        // registration fee/payment collection too.
        $event = $engine->createEvent([
            'name'         => $schedule['name'],
            'description'  => $schedule['description'],
            'format'       => $schedule['format'],
            'court_scope'  => $schedule['court_scope'],
            'court_ids'    => json_decode($schedule['court_ids'] ?? '[]', true) ?: [],
            'max_players'  => (int)$schedule['max_players'],
            'price'        => (float)($schedule['price'] ?? 0),
            'start_date'   => $startDate,
        ], $systemActorId);

        if (!empty($event['id'])) {
            $db->prepare("UPDATE falcon.tournaments SET end_date = :end WHERE id = :id")
               ->execute([':end' => $endDate, ':id' => $event['id']]);
        }
    } catch (Throwable $e) {
        error_log('[open_play_scheduler] auto-create failed: ' . $e->getMessage());
    }
}

/**
 * Safety net for a forgotten "Finalize": any Open Play event that's still
 * open (registration_open / in_progress / registration_closed / paused)
 * from a *previous* business day gets closed out automatically —
 *
 *   1. Anything still sitting on a court (ready/in_progress/paused) is
 *      cancelled — the venue is closed by 4 AM, so there's no final score
 *      coming for those games.
 *   2. finalizeEvent() runs exactly as it would if staff had clicked
 *      "Finalize" themselves: final standings are written to
 *      tournament_scores, the event is marked 'completed', and the podium
 *      (1st/2nd/3rd) is rolled into the season leaderboard via
 *      LeaderboardEngine::processTournamentCompletion().
 *   3. Operations gets a notification either way, so staff can see it
 *      happened and review it rather than being surprised by it later.
 *
 * Called from ensureNightlyOpenPlayEvent() on its existing 5-minute
 * rate-limited cadence, so this runs shortly after 4 AM without needing
 * its own cron entry.
 */
function autoFinalizeStaleOpenPlayEvents(PDO $db, OpenPlayEngine $engine, string $today, int $systemActorId): void
{
    try {
        $stmt = $db->query("
            SELECT id, name, start_date FROM falcon.tournaments
             WHERE bracket_type = 'open_play'
               AND status IN ('registration_open','in_progress','registration_closed','paused')
        ");
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[open_play_scheduler] stale-event lookup failed: ' . $e->getMessage());
        return;
    }

    foreach ($candidates as $row) {
        $tournamentId = (int)$row['id'];

        // Same 4 AM cutover as everything else in this file — an event
        // that started tonight (or is still within tonight's window) is
        // left alone; only a *previous* business day's leftover event
        // gets swept up.
        $eventBusinessDate = openPlayBusinessDate(
            new DateTimeImmutable((string)($row['start_date'] ?? 'now'), new DateTimeZone('Asia/Manila'))
        );
        if ($eventBusinessDate >= $today) {
            continue;
        }

        try {
            // Clear anything still "in play" so finalizeEvent() doesn't
            // reject the close for having unfinished games.
            $stuck = $db->prepare("
                SELECT id FROM falcon.open_play_matches
                 WHERE tournament_id = :tid AND status IN ('ready','in_progress','paused')
            ");
            $stuck->execute([':tid' => $tournamentId]);
            foreach ($stuck->fetchAll(PDO::FETCH_COLUMN) as $matchId) {
                try {
                    $engine->cancelMatch((int)$matchId, $systemActorId);
                } catch (Throwable $e) {
                    error_log("[open_play_scheduler] auto-cancel match {$matchId} failed: " . $e->getMessage());
                }
            }

            $standings = $engine->finalizeEvent($tournamentId, $systemActorId);

            if (function_exists('logActivity')) {
                try {
                    logActivity('open_play_auto_finalized', 'tournament', 'normal', json_encode([
                        'tournament_id' => $tournamentId,
                        'players'       => count($standings),
                        'reason'        => '4am_cutover_not_finalized_by_staff',
                    ]), $systemActorId);
                } catch (Throwable $e) {
                    // non-critical
                }
            }

            if (function_exists('notifyOperations')) {
                $eventName = (string)($row['name'] ?? 'Open Play');
                notifyOperations(
                    $db,
                    '🌙 Open Play auto-finalized',
                    "\"{$eventName}\" wasn't finalized before 4 AM, so it was closed out automatically — final standings were recorded and the podium was posted to the season leaderboard."
                );
            }
        } catch (Throwable $e) {
            error_log("[open_play_scheduler] auto-finalize failed for tournament {$tournamentId}: " . $e->getMessage());
        }
    }
}
