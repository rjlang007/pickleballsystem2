<?php
// ============================================================
//  PARTIAL: includes/partials/player_availability_widget.php
//  Drop into player/dashboard.php to show live court status.
//  Requires: includes/availability.php to be loaded first.
//
//  Usage in player/dashboard.php (before $pageTitle line):
//    require_once __DIR__ . '/../includes/availability.php';
//  Then after the game/queue section card, include:
//    require_once __DIR__ . '/../includes/partials/player_availability_widget.php';
// ============================================================

$todaySlots = getPublicSlotSummary($db, date('Y-m-d'));

// Get courts count for display
$courtsCount = (int)$db->query("SELECT COUNT(*) FROM falcon.courts")->fetchColumn();

// Any reservations today that player should see
$playerReservationsStmt = $db->prepare("
    SELECT r.label, r.notes, r.slot_start, r.slot_end, c.name AS court_name, r.status
    FROM falcon.court_reservations r
    JOIN falcon.courts c ON c.id = r.court_id
    WHERE r.reservation_date = CURRENT_DATE AND r.status = 'confirmed'
    ORDER BY r.slot_start
");
$playerReservationsStmt->execute();
$todayReservations = $playerReservationsStmt->fetchAll();
?>

<!-- ============================================================
     LIVE COURT AVAILABILITY — Player View
     ============================================================ -->
<div class="card" style="margin-top:0;">
  <div class="flex-between mb-2">
    <div>
      <div class="card-title">🏟️ Court Availability</div>
      <div class="card-subtitle" id="availSubtitle">Today's schedule · <?= $courtsCount ?> court<?= $courtsCount > 1 ? 's' : '' ?></div>
    </div>
    <!-- Live indicator -->
    <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--accent);font-family:monospace;">
      <span style="width:7px;height:7px;border-radius:50%;background:var(--accent);
                   animation:pulse 2s infinite;display:inline-block;"></span>
      <span id="availLiveTag">LIVE</span>
    </div>
  </div>

  <?php if (!empty($todayReservations)): ?>
  <!-- Admin reservation notices -->
  <?php foreach ($todayReservations as $res): ?>
  <div style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);
              border-radius:10px;padding:10px 14px;margin-bottom:10px;
              display:flex;align-items:center;gap:10px;font-size:13px;">
    <span style="font-size:18px;">⚠️</span>
    <div>
      <strong style="color:#fbbf24;"><?= clean($res['label']) ?></strong>
      — <?= clean($res['court_name']) ?> ·
      <span style="color:var(--muted);">
        <?= date('g:i A', strtotime($res['slot_start'])) ?>
        – <?= date('g:i A', strtotime($res['slot_end'])) ?>
      </span>
      <?php if ($res['notes']): ?>
        <span style="color:var(--muted);"> · <?= clean($res['notes']) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- Slot cards grid -->
  <div id="playerAvailGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;">
    <?php if (!empty($todaySlots)): ?>
      <?php foreach ($todaySlots as $slot):
        $bgColors = [
          'open'        => 'rgba(0,229,160,0.08)',
          'filling'     => 'rgba(245,158,11,0.08)',
          'busy'        => 'rgba(245,158,11,0.08)',
          'full'        => 'rgba(239,68,68,0.08)',
          'unavailable' => 'rgba(107,127,163,0.06)',
          'active'      => 'rgba(0,184,255,0.08)',
          'reserved'    => 'rgba(245,158,11,0.1)',
        ];
        $borderColors = [
          'open'        => 'rgba(0,229,160,0.3)',
          'filling'     => 'rgba(245,158,11,0.3)',
          'busy'        => 'rgba(245,158,11,0.3)',
          'full'        => 'rgba(239,68,68,0.3)',
          'unavailable' => 'var(--border)',
          'active'      => 'rgba(0,184,255,0.35)',
          'reserved'    => 'rgba(245,158,11,0.35)',
        ];
        $textColors = [
          'open'        => '#00e5a0',
          'filling'     => '#fbbf24',
          'busy'        => '#fbbf24',
          'full'        => '#f87171',
          'unavailable' => '#6b7fa3',
          'active'      => '#00b8ff',
          'reserved'    => '#fbbf24',
        ];
        $statusIcons = [
          'open'        => '✓',
          'filling'     => '⚡',
          'busy'        => '⚡',
          'full'        => '✗',
          'unavailable' => '—',
          'active'      => '🎮',
          'reserved'    => '🔒',
        ];
        $bg     = $bgColors[$slot['status']] ?? 'rgba(0,229,160,0.08)';
        $border = $borderColors[$slot['status']] ?? 'var(--border)';
        $color  = $textColors[$slot['status']] ?? '#00e5a0';
        $icon   = $statusIcons[$slot['status']] ?? '✓';
      ?>
      <div class="player-avail-slot"
           data-slot-id="<?= (int)$slot['id'] ?>"
           data-status="<?= h($slot['status']) ?>"
           style="background:<?= $bg ?>;border:1px solid <?= $border ?>;
                  border-radius:12px;padding:14px 12px;text-align:center;
                  transition:all 0.3s ease;">
        <div style="font-family:var(--font-mono,monospace);font-size:12px;
                    color:var(--text);font-weight:700;margin-bottom:6px;">
          <?= h($slot['time_label']) ?>
        </div>
        <div style="font-size:20px;margin-bottom:4px;"><?= $icon ?></div>
        <div style="font-size:11px;font-weight:700;color:<?= $color ?>;
                    text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;">
          <span class="avail-status-label"><?= h($slot['status_label']) ?></span>
        </div>
        <div style="font-size:11px;color:var(--muted);">
          <span class="avail-players-cur"><?= (int)$slot['players_current'] ?></span>
          / <?= (int)$slot['max_players'] ?>
        </div>
        <?php if ($slot['status'] === 'open' && $slot['available_courts'] > 0): ?>
          <div style="font-size:10px;color:var(--accent);margin-top:4px;opacity:0.9;">
            <?= $slot['available_courts'] ?> court<?= $slot['available_courts'] > 1 ? 's' : '' ?> free
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="text-muted" style="grid-column:1/-1;font-size:13px;">
        No slots configured yet.
      </p>
    <?php endif; ?>
  </div>

  <!-- Last refreshed -->
  <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border);
              display:flex;align-items:center;justify-content:space-between;
              font-size:12px;color:var(--muted);">
    <span>🔄 Auto-refreshes every 30s</span>
    <span id="availLastRefresh">—</span>
  </div>
</div>

<script>
(function playerAvailPoller() {
    const APP_BASE = '<?= rtrim(APP_URL ?? '', '/') ?>';

    const bgMap = {
        open:        'rgba(0,229,160,0.08)',
        filling:     'rgba(245,158,11,0.08)',
        busy:        'rgba(245,158,11,0.08)',
        full:        'rgba(239,68,68,0.08)',
        unavailable: 'rgba(107,127,163,0.06)',
        active:      'rgba(0,184,255,0.08)',
        reserved:    'rgba(245,158,11,0.1)',
        closed:      'rgba(107,127,163,0.06)',
    };
    const borderMap = {
        open:        'rgba(0,229,160,0.3)',
        filling:     'rgba(245,158,11,0.3)',
        busy:        'rgba(245,158,11,0.3)',
        full:        'rgba(239,68,68,0.3)',
        unavailable: 'rgba(107,127,163,0.2)',
        active:      'rgba(0,184,255,0.35)',
        reserved:    'rgba(245,158,11,0.35)',
        closed:      'rgba(107,127,163,0.2)',
    };
    const colorMap = {
        open:        '#00e5a0',
        filling:     '#fbbf24',
        busy:        '#fbbf24',
        full:        '#f87171',
        unavailable: '#6b7fa3',
        active:      '#00b8ff',
        reserved:    '#fbbf24',
        closed:      '#6b7fa3',
    };
    const iconMap = {
        open: '✓', filling: '⚡', busy: '⚡', full: '✗',
        unavailable: '—', active: '🎮', reserved: '🔒', closed: '—'
    };

    function update(slots) {
        if (!slots) return;
        slots.forEach(slot => {
            const el = document.querySelector(`[data-slot-id="${slot.id}"]`);
            if (!el) return;

            el.dataset.status = slot.status;
            el.style.background = bgMap[slot.status] || bgMap.open;
            el.style.borderColor = borderMap[slot.status] || borderMap.open;

            const iconEl   = el.querySelector('div:nth-child(2)');
            const labelEl  = el.querySelector('.avail-status-label');
            const curEl    = el.querySelector('.avail-players-cur');
            const colorEl  = labelEl?.parentElement;

            if (iconEl)  iconEl.textContent  = iconMap[slot.status] || '✓';
            if (labelEl) labelEl.textContent = slot.status_label || slot.status;
            if (curEl)   curEl.textContent   = slot.players_current ?? 0;
            if (colorEl) colorEl.style.color = colorMap[slot.status] || '#00e5a0';
        });

        const ts = document.getElementById('availLastRefresh');
        if (ts) {
            const now = new Date();
            ts.textContent = 'Last updated: ' + now.toLocaleTimeString('en-US', {
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            });
        }
    }

    async function poll() {
        try {
            const r = await fetch(`${APP_BASE}/api/availability.php`, { cache: 'no-store' });
            if (!r.ok) return;
            const d = await r.json();
            if (d.ok && d.slots) update(d.slots);
        } catch(e) {
            console.warn('Availability poll failed:', e);
        }
    }

    poll();
    setInterval(poll, 30000);
})();
</script>