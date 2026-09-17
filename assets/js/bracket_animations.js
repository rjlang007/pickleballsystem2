/* ============================================================
   bracket_animations.js
   Pickleball Tournament System — Bracket Animation Engine
   Location: assets/js/bracket_animations.js
   ============================================================ */

'use strict';

const BracketAnimations = (() => {

  /* ── CSS injection (keyframes not easily done inline) ── */
  const _injectStyles = (() => {
    let injected = false;
    return () => {
      if (injected) return;
      injected = true;
      const style = document.createElement('style');
      style.id = 'bracket-animations-css';
      style.textContent = `
        /* ── Winner Burst ───────────────────────────────── */
        @keyframes ba-winner-burst {
          0%   { box-shadow: 0 0 0 0 rgba(255,215,0,0.7); }
          40%  { box-shadow: 0 0 0 18px rgba(255,215,0,0.2); }
          100% { box-shadow: 0 0 0 32px rgba(255,215,0,0); }
        }
        @keyframes ba-winner-shimmer {
          0%   { background-position: -200% center; }
          100% { background-position:  200% center; }
        }
        @keyframes ba-score-pop {
          0%   { transform: scale(1); }
          40%  { transform: scale(1.45); color: var(--color-gold, #FFD700); }
          70%  { transform: scale(0.9); }
          100% { transform: scale(1); }
        }
        @keyframes ba-card-glow {
          0%,100% { border-color: rgba(255,215,0,0.4); }
          50%     { border-color: rgba(255,215,0,0.95);
                    box-shadow: 0 0 24px rgba(255,215,0,0.35); }
        }
        /* ── Match Update ───────────────────────────────── */
        @keyframes ba-update-flash {
          0%   { background: rgba(0,229,160,0.22); }
          100% { background: transparent; }
        }
        @keyframes ba-connector-draw {
          from { stroke-dashoffset: 1; }
          to   { stroke-dashoffset: 0; }
        }
        /* ── Crown entrance ─────────────────────────────── */
        @keyframes ba-crown-drop {
          0%   { transform: translateY(-14px) scale(0.5); opacity: 0; }
          60%  { transform: translateY(3px)  scale(1.2); opacity: 1; }
          100% { transform: translateY(0)    scale(1);   opacity: 1; }
        }
        /* ── Loser fade ─────────────────────────────────── */
        @keyframes ba-loser-fade {
          from { opacity: 1; }
          to   { opacity: 0.38; filter: grayscale(0.6); }
        }
        /* ── Round reveal ───────────────────────────────── */
        @keyframes ba-round-slide-in {
          from { opacity: 0; transform: translateX(28px); }
          to   { opacity: 1; transform: translateX(0); }
        }
        /* ── Live pulse ring ────────────────────────────── */
        @keyframes ba-live-ring {
          0%   { transform: scale(1);   opacity: 0.9; }
          100% { transform: scale(2.4); opacity: 0; }
        }
        /* ── Connector winner path ──────────────────────── */
        @keyframes ba-path-glow {
          0%,100% { stroke-opacity: 0.5; }
          50%     { stroke-opacity: 1;   filter: drop-shadow(0 0 4px rgba(255,215,0,0.7)); }
        }
        /* ── Podium rise ────────────────────────────────── */
        @keyframes ba-podium-rise {
          from { transform: scaleY(0); transform-origin: bottom; opacity: 0; }
          to   { transform: scaleY(1); transform-origin: bottom; opacity: 1; }
        }
        /* ── Confetti particle ──────────────────────────── */
        @keyframes ba-confetti-fall {
          0%   { transform: translateY(-20px) rotate(0deg);   opacity: 1; }
          100% { transform: translateY(220px) rotate(720deg); opacity: 0; }
        }
        /* ── Toast slide ────────────────────────────────── */
        @keyframes ba-toast-in {
          from { transform: translateX(110%); opacity: 0; }
          to   { transform: translateX(0);    opacity: 1; }
        }
        @keyframes ba-toast-out {
          from { transform: translateX(0);    opacity: 1; }
          to   { transform: translateX(110%); opacity: 0; }
        }
        /* ── Shimmer skeleton ───────────────────────────── */
        @keyframes ba-skeleton {
          0%   { background-position: -400px 0; }
          100% { background-position:  400px 0; }
        }

        /* ── Utility classes applied by JS ─────────────── */
        .ba-winner-card {
          animation: ba-winner-burst 0.7s ease-out,
                     ba-card-glow    2s ease-in-out 0.7s infinite;
        }
        .ba-winner-score {
          animation: ba-score-pop 0.5s cubic-bezier(0.34,1.56,0.64,1);
        }
        .ba-winner-crown {
          animation: ba-crown-drop 0.5s cubic-bezier(0.34,1.56,0.64,1) forwards;
        }
        .ba-loser-slot {
          animation: ba-loser-fade 0.6s ease forwards;
        }
        .ba-updated-card {
          animation: ba-update-flash 1s ease-out forwards;
        }
        .ba-round-reveal {
          animation: ba-round-slide-in 0.4s cubic-bezier(0.16,1,0.3,1) both;
        }
        .ba-live-ring {
          position: absolute;
          inset: -4px;
          border-radius: inherit;
          border: 2px solid var(--color-success, #22C55E);
          animation: ba-live-ring 1.4s ease-out infinite;
          pointer-events: none;
        }
        .ba-winner-shimmer {
          background: linear-gradient(
            105deg,
            transparent 30%,
            rgba(255,215,0,0.18) 50%,
            transparent 70%
          );
          background-size: 200% auto;
          animation: ba-winner-shimmer 2.2s linear infinite;
        }
        .ba-skeleton-pulse {
          background: linear-gradient(
            90deg,
            var(--color-surface, #1E2330) 25%,
            var(--color-surface-2, #252B3B) 50%,
            var(--color-surface, #1E2330) 75%
          );
          background-size: 400px 100%;
          animation: ba-skeleton 1.4s ease-in-out infinite;
        }
        .ba-podium-bar { animation: ba-podium-rise 0.6s cubic-bezier(0.34,1.56,0.64,1) both; }

        /* ── Toast container ─────────────────────────────── */
        #ba-toast-container {
          position: fixed;
          bottom: max(24px, env(safe-area-inset-bottom));
          right: 24px;
          z-index: 99999;
          display: flex;
          flex-direction: column;
          gap: 10px;
          pointer-events: none;
        }
        .ba-toast {
          display: flex;
          align-items: center;
          gap: 10px;
          padding: 12px 18px;
          border-radius: 12px;
          font-size: 13px;
          font-weight: 600;
          min-width: 220px;
          max-width: 340px;
          pointer-events: all;
          animation: ba-toast-in 0.4s cubic-bezier(0.16,1,0.3,1) forwards;
          cursor: pointer;
          word-break: break-word;
        }
        .ba-toast.gold    { background: var(--color-surface,#1E2330); border: 1px solid rgba(255,215,0,0.5);  color: #FFD700; }
        .ba-toast.success { background: var(--color-surface,#1E2330); border: 1px solid rgba(34,197,94,0.5);  color: #22C55E; }
        .ba-toast.info    { background: var(--color-surface,#1E2330); border: 1px solid rgba(56,189,248,0.5); color: #38BDF8; }
        .ba-toast.danger  { background: var(--color-surface,#1E2330); border: 1px solid rgba(239,68,68,0.5);  color: #EF4444; }
        .ba-toast-icon    { font-size: 18px; flex-shrink: 0; }
        .ba-toast-body    { flex: 1; }
        .ba-toast-title   { font-weight: 700; margin-bottom: 1px; }
        .ba-toast-msg     { font-weight: 400; font-size: 12px; opacity: 0.8; }

        @media (max-width: 480px) {
          #ba-toast-container { right: 12px; left: 12px; }
          .ba-toast { min-width: 0; width: 100%; }
        }
      `;
      document.head.appendChild(style);
    };
  })();

  /* ── Toast container singleton ──────────────────────── */
  let _toastContainer = null;
  const _getToastContainer = () => {
    if (!_toastContainer) {
      _toastContainer = document.getElementById('ba-toast-container');
      if (!_toastContainer) {
        _toastContainer = document.createElement('div');
        _toastContainer.id = 'ba-toast-container';
        document.body.appendChild(_toastContainer);
      }
    }
    return _toastContainer;
  };

  /* ── Confetti canvas singleton ──────────────────────── */
  let _confettiRAF = null;

  /* ══════════════════════════════════════════════════════
     PUBLIC API
  ══════════════════════════════════════════════════════ */

  /**
   * Animate a match card when a result is recorded.
   * Highlights the winner slot, fades the loser, pops scores.
   *
   * @param {HTMLElement} card      - .match-card element
   * @param {string|number} winnerId - ID of the winning player
   */
  const animateMatchResult = (card, winnerId) => {
    if (!card) return;
    _injectStyles();

    // Flash the whole card
    card.classList.remove('ba-updated-card');
    void card.offsetWidth; // reflow
    card.classList.add('ba-updated-card');

    const slots = card.querySelectorAll('.player-slot');
    slots.forEach(slot => {
      const playerId = slot.dataset.playerId || slot.dataset.id;
      const isWinner = playerId && String(playerId) === String(winnerId);

      if (isWinner) {
        // Winner slot
        slot.classList.add('is-winner');
        card.classList.add('ba-winner-card');

        // Add shimmer overlay
        if (!slot.querySelector('.ba-winner-shimmer')) {
          const shimmer = document.createElement('div');
          shimmer.className = 'ba-winner-shimmer';
          shimmer.style.cssText = 'position:absolute;inset:0;pointer-events:none;border-radius:inherit;';
          slot.style.position = 'relative';
          slot.appendChild(shimmer);
        }

        // Animate score
        const score = slot.querySelector('.player-score');
        if (score) {
          score.classList.remove('ba-winner-score');
          void score.offsetWidth;
          score.classList.add('winner-score', 'ba-winner-score');
        }

        // Animate/insert crown
        const crown = slot.querySelector('.winner-crown');
        if (crown) {
          crown.classList.remove('ba-winner-crown');
          void crown.offsetWidth;
          crown.classList.add('ba-winner-crown');
        }

        // Avatar glow
        const avatar = slot.querySelector('.player-avatar');
        if (avatar) {
          avatar.classList.add('winner-avatar');
        }

        // Name styling
        const name = slot.querySelector('.player-name');
        if (name) {
          name.style.color = 'var(--color-gold,#FFD700)';
          name.style.fontWeight = '700';
        }
      } else if (winnerId) {
        // Loser slot
        slot.classList.add('is-loser');
        slot.classList.remove('ba-loser-slot');
        void slot.offsetWidth;
        slot.classList.add('ba-loser-slot');
      }
    });
  };

  /**
   * Animate a round column into view (staggered card entrance).
   *
   * @param {NodeList|Array} cards  - .match-card elements in the round
   * @param {number} baseDelay      - ms before first card animates (default 0)
   * @param {number} stagger        - ms between each card (default 60)
   */
  const animateRoundReveal = (cards, baseDelay = 0, stagger = 60) => {
    if (!cards || !cards.length) return;
    _injectStyles();

    Array.from(cards).forEach((card, i) => {
      card.style.opacity   = '0';
      card.style.transform = 'translateX(20px)';
      card.style.transition = `opacity 320ms ease ${baseDelay + i * stagger}ms,
                                transform 320ms cubic-bezier(0.16,1,0.3,1) ${baseDelay + i * stagger}ms`;
      // Trigger paint, then animate
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          card.style.opacity   = '1';
          card.style.transform = 'translateX(0)';
        });
      });
    });
  };

  /**
   * Animate the entire bracket container on first load
   * (staggered left-to-right round reveal).
   *
   * @param {HTMLElement} container - .bracket-wrapper or parent
   * @param {number} staggerPerRound - ms between round columns
   */
  const animateBracketLoad = (container, staggerPerRound = 120) => {
    if (!container) return;
    _injectStyles();

    // Group cards by their x position (each column = one round)
    const cards = Array.from(container.querySelectorAll('.match-card'));
    if (!cards.length) return;

    // Build round buckets from foreignObject x attributes (SVG) or column parents
    const xGroups = {};
    cards.forEach(card => {
      const fo = card.closest('foreignObject');
      const x  = fo ? Math.round(parseFloat(fo.getAttribute('x'))) : 0;
      if (!xGroups[x]) xGroups[x] = [];
      xGroups[x].push(card);
    });

    const sortedX = Object.keys(xGroups).map(Number).sort((a, b) => a - b);
    sortedX.forEach((x, rIdx) => {
      animateRoundReveal(xGroups[x], rIdx * staggerPerRound, 50);
    });
  };

  /**
   * Add a pulsing "live" ring to an active match card.
   *
   * @param {HTMLElement} card
   */
  const addLiveRing = (card) => {
    if (!card) return;
    _injectStyles();
    if (!card.querySelector('.ba-live-ring')) {
      const ring = document.createElement('div');
      ring.className = 'ba-live-ring';
      card.style.position = 'relative';
      card.style.overflow = 'visible';
      card.appendChild(ring);
    }
  };

  /**
   * Remove a live ring from a card.
   *
   * @param {HTMLElement} card
   */
  const removeLiveRing = (card) => {
    const ring = card?.querySelector('.ba-live-ring');
    if (ring) ring.remove();
  };

  /**
   * Animate SVG connector path as if it's being drawn (winner path).
   *
   * @param {SVGPathElement} path
   * @param {number} duration - ms
   */
  const animateConnector = (path, duration = 600) => {
    if (!path) return;
    _injectStyles();

    const length = path.getTotalLength?.() || 200;
    path.style.strokeDasharray  = length;
    path.style.strokeDashoffset = length;
    path.style.animation = 'none';
    void path.getBoundingClientRect();
    path.style.transition = `stroke-dashoffset ${duration}ms cubic-bezier(0.16,1,0.3,1)`;
    path.style.strokeDashoffset = '0';
  };

  /**
   * Animate winner connector paths with a gold glow.
   *
   * @param {HTMLElement|SVGElement} container
   */
  const animateWinnerPaths = (container) => {
    if (!container) return;
    _injectStyles();

    container.querySelectorAll('.bracket-connector.winner-path').forEach((path, i) => {
      setTimeout(() => {
        animateConnector(path, 500);
        path.style.animation = `ba-path-glow 2s ease-in-out ${i * 120}ms infinite`;
      }, i * 80);
    });
  };

  /**
   * Show a winner celebration: confetti + toast + card glow.
   *
   * @param {HTMLElement} card        - the winning match card
   * @param {string} winnerName
   * @param {string} roundLabel       - e.g. "FINAL", "SEMI"
   */
  const celebrateWinner = (card, winnerName, roundLabel = '') => {
    _injectStyles();

    // Toast
    const isFinal = /final/i.test(roundLabel);
    showToast(
      isFinal ? '🏆 Champion!' : '✅ Match Complete',
      `${winnerName} advances${isFinal ? ' as Champion' : ''}!`,
      isFinal ? 'gold' : 'success',
      isFinal ? 5000 : 3500
    );

    // Confetti only on finals
    if (isFinal) {
      _launchConfetti(card);
    }

    // Scroll card into view
    card?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  /**
   * Animate podium bars on final results page.
   *
   * @param {NodeList|Array} platformEls - .podium-platform elements
   */
  const animatePodium = (platformEls) => {
    if (!platformEls?.length) return;
    _injectStyles();

    const order = [1, 0, 2]; // animate 2nd, 1st, 3rd for dramatic effect
    Array.from(platformEls).forEach((el, i) => {
      el.style.opacity   = '0';
      el.style.transform = 'scaleY(0)';
      el.style.transformOrigin = 'bottom';
    });

    order.forEach((idx, step) => {
      const el = platformEls[idx];
      if (!el) return;
      setTimeout(() => {
        el.style.transition = 'opacity 0.4s ease, transform 0.5s cubic-bezier(0.34,1.56,0.64,1)';
        el.style.opacity   = '1';
        el.style.transform = 'scaleY(1)';
      }, 200 + step * 220);
    });
  };

  /**
   * Show a skeleton loading state over a bracket container.
   *
   * @param {HTMLElement} container
   * @param {number} cardCount - how many skeleton cards to render
   */
  const showSkeletonLoader = (container, cardCount = 8) => {
    if (!container) return;
    _injectStyles();

    container.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;gap:48px;padding:24px;overflow-x:auto;';

    for (let col = 0; col < 3; col++) {
      const colEl = document.createElement('div');
      colEl.style.cssText = 'display:flex;flex-direction:column;gap:16px;';
      const rows = Math.max(1, Math.ceil(cardCount / (col + 1)));
      for (let row = 0; row < Math.ceil(rows / Math.pow(2, col)); row++) {
        const card = document.createElement('div');
        card.className = 'ba-skeleton-pulse';
        card.style.cssText = `width:200px;height:74px;border-radius:10px;
          animation-delay:${(col * 3 + row) * 80}ms;`;
        colEl.appendChild(card);
      }
      wrap.appendChild(colEl);
    }
    container.appendChild(wrap);
  };

  /**
   * Flash a score value when updated live.
   *
   * @param {HTMLElement} scoreEl
   * @param {string|number} newValue
   */
  const animateScoreUpdate = (scoreEl, newValue) => {
    if (!scoreEl) return;
    _injectStyles();

    scoreEl.style.transition = 'transform 0.15s ease, color 0.15s ease';
    scoreEl.style.transform  = 'scale(0.7)';
    scoreEl.style.opacity    = '0';

    setTimeout(() => {
      scoreEl.textContent      = newValue;
      scoreEl.style.transform  = 'scale(1)';
      scoreEl.style.opacity    = '1';
      scoreEl.classList.remove('ba-winner-score');
      void scoreEl.offsetWidth;
      scoreEl.classList.add('ba-winner-score');
    }, 150);
  };

  /**
   * Highlight a match card briefly (e.g. after WebSocket update).
   *
   * @param {HTMLElement} card
   */
  const pulseCard = (card) => {
    if (!card) return;
    _injectStyles();
    card.classList.remove('ba-updated-card');
    void card.offsetWidth;
    card.classList.add('ba-updated-card');
  };

  /**
   * Show a toast notification.
   *
   * @param {string} title
   * @param {string} message
   * @param {'gold'|'success'|'info'|'danger'} type
   * @param {number} duration - ms before auto-dismiss (0 = sticky)
   */
  const showToast = (title, message = '', type = 'info', duration = 4000) => {
    _injectStyles();
    const container = _getToastContainer();

    const icons = { gold: '🏆', success: '✅', info: 'ℹ️', danger: '⚠️' };

    const toast = document.createElement('div');
    toast.className = `ba-toast ${type}`;
    toast.innerHTML = `
      <span class="ba-toast-icon">${icons[type] || 'ℹ️'}</span>
      <div class="ba-toast-body">
        <div class="ba-toast-title">${title}</div>
        ${message ? `<div class="ba-toast-msg">${message}</div>` : ''}
      </div>
    `;

    container.appendChild(toast);

    const dismiss = () => {
      toast.style.animation = 'ba-toast-out 0.35s cubic-bezier(0.4,0,1,1) forwards';
      setTimeout(() => toast.remove(), 380);
    };

    toast.addEventListener('click', dismiss);
    if (duration > 0) setTimeout(dismiss, duration);

    return { dismiss };
  };

  /**
   * Stagger-animate a list of leaderboard rows.
   *
   * @param {NodeList|Array} rows
   * @param {number} stagger - ms between each
   */
  const animateLeaderboardRows = (rows, stagger = 45) => {
    if (!rows?.length) return;
    _injectStyles();

    Array.from(rows).forEach((row, i) => {
      row.style.opacity   = '0';
      row.style.transform = 'translateY(12px)';
      setTimeout(() => {
        row.style.transition = 'opacity 300ms ease, transform 300ms cubic-bezier(0.16,1,0.3,1)';
        row.style.opacity    = '1';
        row.style.transform  = 'translateY(0)';
      }, i * stagger);
    });
  };

  /**
   * Animate rank badge for top-3 (sparkle + scale on enter).
   *
   * @param {HTMLElement} badgeEl
   * @param {number} rank
   */
  const animateRankBadge = (badgeEl, rank) => {
    if (!badgeEl) return;
    _injectStyles();

    badgeEl.style.transform = 'scale(0)';
    badgeEl.style.opacity   = '0';

    const delay = rank === 1 ? 600 : rank === 2 ? 400 : 200;
    setTimeout(() => {
      badgeEl.style.transition = 'transform 0.5s cubic-bezier(0.34,1.56,0.64,1), opacity 0.3s ease';
      badgeEl.style.transform  = 'scale(1)';
      badgeEl.style.opacity    = '1';

      if (rank === 1) {
        badgeEl.classList.add('sparkle');
      }
    }, delay);
  };

  /* ══════════════════════════════════════════════════════
     PRIVATE — Confetti
  ══════════════════════════════════════════════════════ */
  const _launchConfetti = (anchorEl) => {
    _injectStyles();

    const colors = ['#FFD700','#00E5A0','#38BDF8','#F97316','#A78BFA','#F472B6'];
    const canvas  = document.createElement('canvas');
    const rect    = anchorEl?.getBoundingClientRect() || { left: window.innerWidth / 2, top: window.innerHeight / 2 };
    const originX = rect.left + (rect.width  || 0) / 2;
    const originY = rect.top  + (rect.height || 0) / 2;

    canvas.style.cssText = `
      position: fixed; inset: 0;
      width: 100vw; height: 100vh;
      pointer-events: none; z-index: 99998;
    `;
    document.body.appendChild(canvas);

    const ctx    = canvas.getContext('2d');
    canvas.width  = window.innerWidth;
    canvas.height = window.innerHeight;

    const particles = Array.from({ length: 90 }, () => ({
      x:    originX + (Math.random() - 0.5) * 60,
      y:    originY,
      vx:   (Math.random() - 0.5) * 12,
      vy:   -(Math.random() * 14 + 6),
      color: colors[Math.floor(Math.random() * colors.length)],
      size: Math.random() * 7 + 4,
      rot:  Math.random() * 360,
      rotV: (Math.random() - 0.5) * 8,
      life: 1,
      decay: Math.random() * 0.012 + 0.008,
    }));

    if (_confettiRAF) cancelAnimationFrame(_confettiRAF);

    const draw = () => {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      let alive = false;

      particles.forEach(p => {
        if (p.life <= 0) return;
        alive = true;
        p.x  += p.vx;
        p.y  += p.vy;
        p.vy += 0.45; // gravity
        p.vx *= 0.98;
        p.rot += p.rotV;
        p.life -= p.decay;

        ctx.save();
        ctx.globalAlpha = Math.max(0, p.life);
        ctx.translate(p.x, p.y);
        ctx.rotate((p.rot * Math.PI) / 180);
        ctx.fillStyle = p.color;
        ctx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2);
        ctx.restore();
      });

      if (alive) {
        _confettiRAF = requestAnimationFrame(draw);
      } else {
        canvas.remove();
        _confettiRAF = null;
      }
    };

    _confettiRAF = requestAnimationFrame(draw);

    // Clean up after 4.5s regardless
    setTimeout(() => {
      cancelAnimationFrame(_confettiRAF);
      canvas.remove();
    }, 4500);
  };

  /* ── Init: inject styles as soon as module loads ──── */
  _injectStyles();

  /* ── Public interface ─────────────────────────────── */
  return {
    animateMatchResult,
    animateRoundReveal,
    animateBracketLoad,
    addLiveRing,
    removeLiveRing,
    animateConnector,
    animateWinnerPaths,
    celebrateWinner,
    animatePodium,
    showSkeletonLoader,
    animateScoreUpdate,
    pulseCard,
    showToast,
    animateLeaderboardRows,
    animateRankBadge,
  };

})();

/* ── Global export ────────────────────────────────────── */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = BracketAnimations;
}