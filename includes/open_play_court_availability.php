<?php
// Shared Open Play court-scope checks used by booking and availability views.

if (!function_exists('openPlayEventUsesCourt')) {
    function openPlayEventUsesCourt(array $event, int $courtId): bool
    {
        $settings = json_decode($event['settings'] ?? '{}', true) ?: [];
        if (($settings['court_scope'] ?? 'all') !== 'selected') return true;

        $courtIds = array_map('intval', (array)($settings['court_ids'] ?? []));
        return in_array($courtId, $courtIds, true);
    }
}

if (!function_exists('isCourtInOpenPlay')) {
    /** Return true when a court is allocated to an active Open Play event at a slot. */
    function isCourtInOpenPlay(PDO $db, int $courtId, string $slotDate, string $slotStart, ?string $slotEnd = null): bool
    {
        if ($courtId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $slotDate)) return false;

        $slotStart = strlen($slotStart) === 5 ? $slotStart . ':00' : $slotStart;
        if (!$slotEnd) {
            $slotEnd = date('H:i:s', strtotime($slotDate . ' ' . $slotStart . ' +' . (defined('SLOT_DURATION_MIN') ? SLOT_DURATION_MIN : 120) . ' minutes'));
        }
        if (strlen($slotEnd) === 5) $slotEnd .= ':00';

        static $eventsByDb = [];
        $dbKey = spl_object_id($db);
        if (!isset($eventsByDb[$dbKey])) {
            $eventsByDb[$dbKey] = $db->query(
                "SELECT id, settings, start_date, end_date
                   FROM falcon.tournaments
                  WHERE bracket_type = 'open_play'
                    AND status IN ('registration_open', 'registration_closed', 'in_progress', 'paused')"
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        $slotStartTs = strtotime($slotDate . ' ' . $slotStart);
        $slotEndTs = strtotime($slotDate . ' ' . $slotEnd);
        foreach ($eventsByDb[$dbKey] as $event) {
            if (!openPlayEventUsesCourt($event, $courtId)) continue;

            $eventStart = strtotime((string)$event['start_date']);
            $eventEnd = !empty($event['end_date'])
                ? strtotime((string)$event['end_date'])
                : strtotime($slotDate . ' 23:59:59');
            if ($eventStart < $slotEndTs && $eventEnd >= $slotStartTs) return true;
        }

        return false;
    }
}