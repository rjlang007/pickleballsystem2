<?php
// ============================================================
//  PARTIAL: includes/partials/schedule_section.php
//  Drop-in replacement for the #schedule section in index.php
//  Uses includes/availability.php for real-time data.
//
//  In index.php, replace the #schedule section with:
//    require_once 'includes/availability.php';
//    require_once 'includes/partials/schedule_section.php';
// ============================================================

// Load live availability (public summary — no auth needed)
$liveSlots = getPublicSlotSummary($db, date('Y-m-d'));
?>

<!-- ============================================================
     SCHEDULE SECTION — Live Synced
     ============================================================ -->
<section id="schedule" class="section">
  <div class="section-inner">
    <div class="schedule-header reveal">
      <div>
        <div class="section-label">Court Schedule</div>
        <h2 class="section-title">Book Your Slot</h2>
        <p class="section-desc" style="margin-bottom:0">
          Live availability — updates in real time.
        </p>
      </div>
      <div class="hours-card">
        <div class="hours-icon">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
          </svg>
        </div>
        <div>
          <div class="hours-time"><?= h($location['hours'] ?? '10AM — 12MN') ?></div>
          <div class="hours-days">Monday to Sunday · Daily</div>
        </div>
      </div>
    </div>

    <!-- Live status bar -->
    <div id="schedLiveBar" style="
        display:flex; align-items:center; gap:10px;
        background:rgba(0,229,160,0.06);
        border:1px solid rgba(0,229,160,0.15);
        border-radius:10px; padding:10px 18px;
        font-size:13px; margin-bottom:24px;
        transition: opacity 0.3s;">
      <span style="width:8px;height:8px;border-radius:50%;background:var(--accent);
                   animation:pulse 2s infinite;flex-shrink:0;display:inline-block;"></span>
      <span id="schedLiveText" style="color:var(--muted);">Loading live data…</span>
      <span id="schedLastUpdate" style="margin-left:auto;font-family:monospace;font-size:11px;color:var(--muted);"></span>
    </div>

    <!-- Slot grid — populated by JS -->
    <div class="time-slots reveal" style="transition-delay:0.15s" id="schedSlotsGrid">
      <?php if (!empty($liveSlots)): ?>
        <?php foreach ($liveSlots as $slot):
          $cssClass  = match($slot['status']) {
            'busy'        => 'busy',
            'full'        => 'unavailable',
            'unavailable' => 'unavailable',
            'active'      => 'busy',
            default       => 'available',
          };
          $statusLabel = match($slot['status']) {
            'open'        => 'Available',
            'filling'     => 'Filling Up',
            'busy'        => 'Filling Up',
            'full'        => 'Full',
            'unavailable' => 'Closed',
            'active'      => 'In Progress',
            'reserved'    => 'Reserved',
            default       => 'Available',
          };
          $dotBg = match($slot['status']) {
            'filling','busy' => 'background:var(--accent3)',
            'full'           => 'background:#ef4444',
            'unavailable'    => 'background:var(--muted)',
            'active'         => 'background:var(--accent2)',
            'reserved'       => 'background:#f59e0b',
            default          => 'background:var(--accent)',
          };
        ?>
        <div class="time-slot <?= $cssClass ?>"
             data-slot-id="<?= (int)$slot['id'] ?>"
             data-status="<?= h($slot['status']) ?>">
          <div class="slot-time"><?= h($slot['time_label']) ?></div>
          <div class="slot-status">
            <span class="slot-dot" style="<?= $dotBg ?>"></span>
            <span class="slot-status-text"><?= $statusLabel ?></span>
          </div>
          <div class="slot-players">
            <span class="slot-current"><?= (int)$slot['players_current'] ?></span>
            / <?= (int)$slot['max_players'] ?> players
          </div>
          <?php if ($slot['available_courts'] > 0 && $slot['status'] === 'open'): ?>
            <div style="font-size:11px;color:var(--accent);margin-top:4px;opacity:0.8;">
              <?= $slot['available_courts'] ?> court<?= $slot['available_courts'] > 1 ? 's' : '' ?> free
            </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <!-- Fallback if no slots configured -->
        <div class="time-slot available">
          <div class="slot-time">10:00 AM</div>
          <div class="slot-status"><span class="slot-dot"></span>Available</div>
          <div class="slot-players">0 / 4 players</div>
        </div>
      <?php endif; ?>
    </div>

    <div style="margin-top:32px;text-align:center;" class="reveal">
      <a href="auth/login.php" class="btn btn-primary btn-md">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2.5">
          <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
          <line x1="16" y1="2" x2="16" y2="6"/>
          <line x1="8" y1="2" x2="8" y2="6"/>
          <line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
        View Full Schedule &amp; Book
      </a>
    </div>
  </div>
</section>

<!-- Live polling for schedule section -->
<script>
(function schedulePoller() {
    const APP_BASE = '<?= rtrim(APP_URL ?? '', '/') ?>';

    function updateSlotDOM(slots) {
        if (!slots || !slots.length) return;

        const cssMap = {
            open: 'available', filling: 'busy', busy: 'busy',
            full: 'unavailable', unavailable: 'unavailable',
            active: 'busy', reserved: 'unavailable', closed: 'unavailable'
        };
        const labelMap = {
            open: 'Available', filling: 'Filling Up', busy: 'Filling Up',
            full: 'Full', unavailable: 'Closed', active: 'In Progress',
            reserved: 'Reserved', closed: 'Closed'
        };
        const dotMap = {
            filling: '#ff6b35', busy: '#ff6b35', full: '#ef4444',
            unavailable: '#6b7fa3', active: '#00b8ff', reserved: '#f59e0b'
        };

        slots.forEach(slot => {
            const el = document.querySelector(`[data-slot-id="${slot.id}"]`);
            if (!el) return;

            const newCss = cssMap[slot.status] || 'available';
            el.className = `time-slot ${newCss}`;
            el.dataset.status = slot.status;

            const statusTxt = el.querySelector('.slot-status-text');
            if (statusTxt) statusTxt.textContent = labelMap[slot.status] || slot.status_label || 'Available';

            const dot = el.querySelector('.slot-dot');
            if (dot) dot.style.background = dotMap[slot.status] || '#00e5a0';

            const currentEl = el.querySelector('.slot-current');
            if (currentEl) currentEl.textContent = slot.players_current ?? 0;
        });

        const now = new Date();
        const timeStr = now.toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit', second:'2-digit'});

        const liveText = document.getElementById('schedLiveText');
        if (liveText) liveText.textContent = 'Live availability — refreshes automatically';

        const lastUp = document.getElementById('schedLastUpdate');
        if (lastUp) lastUp.textContent = 'Updated ' + timeStr;
    }

    async function fetchAndUpdate() {
        try {
            const r = await fetch(`${APP_BASE}/api/availability.php`, {cache:'no-store'});
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const d = await r.json();
            if (d.ok && d.slots) updateSlotDOM(d.slots);
        } catch(e) {
            const lt = document.getElementById('schedLiveText');
            if (lt) lt.textContent = 'Could not refresh — please reload';
        }
    }

    // Initial fetch on load + every 30 seconds
    fetchAndUpdate();
    setInterval(fetchAndUpdate, 30000);
})();
</script>