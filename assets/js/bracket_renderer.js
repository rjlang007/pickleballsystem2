/* ============================================================
   bracket_renderer.js
   Pickleball Tournament System — Bracket Rendering Engine
   Location: assets/js/bracket_renderer.js
   ============================================================ */

'use strict';

class BracketRenderer {

  /* ── Constructor ─────────────────────────────────────── */
  constructor(container, options = {}) {
    this.container = typeof container === 'string'
      ? document.querySelector(container)
      : container;

    if (!this.container) {
      console.error('[BracketRenderer] Container not found.');
      return;
    }

    this.options = Object.assign({
      type:           'single',       // 'single' | 'double' | 'roundrobin' | 'swiss'
      tournamentId:   null,
      data:           null,           // Pre-loaded bracket data (skip fetch)
      apiBase:        (typeof window !== 'undefined' && window.APP_URL ? window.APP_URL : '') + '/api',
      cardWidth:      200,
      cardHeight:     74,             // 2 players × ~34px + status bar
      colGap:         80,
      rowGap:         20,
      animateOnLoad:  true,
      onMatchClick:   null,           // callback(matchData)
      onPlayerHover:  null,
    }, options);

    this.data        = null;
    this.tooltip     = null;
    this._rendered   = false;

    this._init();
  }

  /* ── Init ────────────────────────────────────────────── */
  async _init() {
    this._buildTooltip();

    if (this.options.data) {
      this.data = this.options.data;
      this._render();
    } else if (this.options.tournamentId) {
      await this._fetchData();
      this._render();
    }
  }

  /* ── Data Fetching ───────────────────────────────────── */
  async _fetchData() {
    try {
      this._showLoading();
      const url = `${this.options.apiBase}/brackets.php?tournament_id=${this.options.tournamentId}`;
      const res = await fetch(url);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const json = await res.json();
      this.data = json.data || json;
    } catch (err) {
      this._showError(err.message);
      throw err;
    }
  }

  /* ── Master Render ───────────────────────────────────── */
  _render() {
    this.container.innerHTML = '';

    const type = (this.data?.bracket_type || this.options.type || 'single').toLowerCase();

    switch (type) {
      case 'double_elimination':
      case 'double':
        this._renderDouble();
        break;
      case 'round_robin':
      case 'roundrobin':
        this._renderRoundRobin();
        break;
      case 'swiss':
        this._renderSwiss();
        break;
      default:
        this._renderSingle();
    }

    this._rendered = true;
    if (this.options.animateOnLoad) this._animateIn();
  }

  /* ════════════════════════════════════════════════════════
     SINGLE ELIMINATION
  ════════════════════════════════════════════════════════ */
  _renderSingle() {
    const matches   = this._normalizeMatches(this.data?.matches || this.data || []);
    const rounds    = this._groupByRound(matches);
    const numRounds = rounds.length;

    if (numRounds === 0) { this._showEmpty(); return; }

    // Layout dimensions
    const { cardWidth, cardHeight, colGap, rowGap } = this.options;
    const colWidth   = cardWidth + colGap;
    const maxInCol   = Math.max(...rounds.map(r => r.length));
    const totalH     = maxInCol * (cardHeight + rowGap) + rowGap + 48; // +48 round label
    const totalW     = numRounds * colWidth + colGap;

    const wrapper = this._el('div', 'bracket-wrapper');
    const svg     = this._svgEl('svg', {
      class:   'bracket-canvas',
      viewBox: `0 0 ${totalW} ${totalH}`,
      width:   totalW,
      height:  totalH,
    });

    // Store positions for connector drawing
    const positions = {}; // matchId → { x, y, cx, cy }

    // Pass 1: Calculate positions & draw round labels
    rounds.forEach((roundMatches, rIdx) => {
      const totalSlots  = Math.pow(2, numRounds - 1 - rIdx) <= 0
        ? 1 : Math.pow(2, numRounds - 1 - rIdx);
      const slotHeight  = (totalH - 48) / totalSlots;
      const x           = colGap / 2 + rIdx * colWidth;

      // Round label
      const labelY = 24;
      const label  = this._svgEl('text', {
        class: 'bracket-round-label',
        x: x + cardWidth / 2,
        y: labelY,
      });
      label.textContent = this._roundLabel(rIdx, numRounds, roundMatches.length);
      svg.appendChild(label);

      roundMatches.forEach((match, mIdx) => {
        const slotIndex = mIdx * (totalSlots / roundMatches.length);
        const y = 48 + slotIndex * slotHeight + (slotHeight - cardHeight) / 2;
        const cx = x + cardWidth / 2;
        const cy = y + cardHeight / 2;

        positions[match.id] = { x, y, cx, cy };
        match._pos = { x, y, cx, cy };
      });
    });

    // Pass 2: Draw connectors (before cards so cards appear on top)
    rounds.forEach((roundMatches, rIdx) => {
      if (rIdx === 0) return;
      const prevRound = rounds[rIdx - 1];

      roundMatches.forEach((match) => {
        // Find the two source matches from previous round
        const sources = prevRound.filter(m =>
          m.next_match_id === match.id ||
          m.winner_goes_to === match.id
        );

        sources.forEach(src => {
          if (!src._pos || !match._pos) return;
          const x1 = src._pos.x + cardWidth;
          const y1 = src._pos.cy;
          const x2 = match._pos.x;
          const y2 = match._pos.cy;
          const mx = (x1 + x2) / 2;

          const isWinner = src.winner_id && String(src.winner_id) !== '';
          const path = this._svgEl('path', {
            class: `bracket-connector${isWinner ? ' winner-path' : ''}`,
            d: `M${x1},${y1} C${mx},${y1} ${mx},${y2} ${x2},${y2}`,
          });
          svg.appendChild(path);
        });
      });
    });

    // Pass 3: Draw match cards as foreignObjects
    rounds.forEach((roundMatches) => {
      roundMatches.forEach((match) => {
        const { x, y } = match._pos;
        const fo = this._svgEl('foreignObject', {
          x, y,
          width:  cardWidth,
          height: cardHeight,
          class:  'bracket-fo',
        });
        fo.appendChild(this._buildMatchCard(match));
        svg.appendChild(fo);
      });
    });

    wrapper.appendChild(svg);
    this.container.appendChild(wrapper);
  }

  /* ════════════════════════════════════════════════════════
     DOUBLE ELIMINATION
  ════════════════════════════════════════════════════════ */
  _renderDouble() {
    const matches  = this._normalizeMatches(this.data?.matches || this.data || []);
    const winners  = matches.filter(m => !m.bracket_section || m.bracket_section === 'winners');
    const losers   = matches.filter(m => m.bracket_section === 'losers');
    const finals   = matches.filter(m => m.bracket_section === 'grand_finals');

    const container = this._el('div', 'double-elim-container');

    // Winners bracket
    const wLabel = this._el('div', 'double-elim-section-label');
    wLabel.innerHTML = '<span>🏆</span> Winners Bracket';
    container.appendChild(wLabel);

    const wWrapper = this._el('div', 'winners-bracket');
    this._renderBracketSection(wWrapper, winners);
    container.appendChild(wWrapper);

    // Losers bracket
    if (losers.length > 0) {
      const lLabel = this._el('div', 'double-elim-section-label');
      lLabel.innerHTML = '<span>🔴</span> Losers Bracket';
      container.appendChild(lLabel);

      const lWrapper = this._el('div', 'losers-bracket');
      this._renderBracketSection(lWrapper, losers);
      container.appendChild(lWrapper);
    }

    // Grand Finals
    if (finals.length > 0) {
      const fLabel = this._el('div', 'double-elim-section-label');
      fLabel.innerHTML = '<span>⚡</span> Grand Finals';
      container.appendChild(fLabel);

      const fWrapper = this._el('div', '');
      fWrapper.style.cssText = 'display:flex;gap:16px;flex-wrap:wrap;';
      finals.forEach(m => fWrapper.appendChild(this._buildMatchCard(m, true)));
      container.appendChild(fWrapper);
    }

    this.container.appendChild(container);
  }

  _renderBracketSection(wrapper, matches) {
    // Temporarily override container and render as single bracket
    const saved = this.container;
    const savedData = this.data;
    this.container = wrapper;
    this.data = { matches, bracket_type: 'single' };
    this._renderSingle();
    this.container = saved;
    this.data = savedData;
  }

  /* ════════════════════════════════════════════════════════
     ROUND ROBIN
  ════════════════════════════════════════════════════════ */
  _renderRoundRobin() {
    const matches  = this._normalizeMatches(this.data?.matches || this.data || []);
    const players  = this._extractPlayers(matches);
    const standings = this._calcStandings(matches, players);

    const wrapper = this._el('div', '');

    // Standings table
    const standTitle = this._el('div', 'double-elim-section-label');
    standTitle.innerHTML = '<span>📊</span> Standings';
    wrapper.appendChild(standTitle);

    const tableWrap = this._el('div', 'rr-table-wrapper');
    const table     = this._el('table', 'rr-table');
    const thead     = this._el('thead', '');
    const hRow      = this._el('tr', '');

    ['Rank', 'Player', 'W', 'L', 'Points', 'Win %'].forEach(h => {
      const th = this._el('th', '');
      th.textContent = h;
      hRow.appendChild(th);
    });
    thead.appendChild(hRow);
    table.appendChild(thead);

    const tbody = this._el('tbody', '');
    standings.forEach((s, i) => {
      const tr = this._el('tr', '');
      const rank = i + 1;
      const rankCell = this._el('td', 'rank-col');
      rankCell.innerHTML = this._rankBadgeHTML(rank);

      const playerCell = this._el('td', '');
      playerCell.innerHTML = `<div style="display:flex;align-items:center;gap:8px;">
        ${this._avatarHTML(s.player, 26)}
        <span>${this._esc(s.player?.name || 'TBD')}</span>
      </div>`;

      const wCell  = this._el('td', 'cell-win');  wCell.textContent  = s.wins;
      const lCell  = this._el('td', 'cell-loss'); lCell.textContent  = s.losses;
      const ptCell = this._el('td', '');          ptCell.textContent = s.points;
      const wpCell = this._el('td', '');
      wpCell.textContent = s.wins + s.losses > 0
        ? Math.round(s.wins / (s.wins + s.losses) * 100) + '%'
        : '—';

      [rankCell, playerCell, wCell, lCell, ptCell, wpCell].forEach(c => tr.appendChild(c));
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    tableWrap.appendChild(table);
    wrapper.appendChild(tableWrap);

    // Match grid
    const matchTitle = this._el('div', 'double-elim-section-label mt-xl');
    matchTitle.style.marginTop = '32px';
    matchTitle.innerHTML = '<span>🎾</span> Match Results';
    wrapper.appendChild(matchTitle);

    const grid = this._el('div', '');
    grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;';
    matches.forEach(m => grid.appendChild(this._buildMatchCard(m, true)));
    wrapper.appendChild(grid);

    this.container.appendChild(wrapper);
  }

  /* ════════════════════════════════════════════════════════
     SWISS SYSTEM
  ════════════════════════════════════════════════════════ */
  _renderSwiss() {
    const matches = this._normalizeMatches(this.data?.matches || this.data || []);
    const rounds  = this._groupByRound(matches);

    const wrapper = this._el('div', '');

    const label = this._el('div', 'double-elim-section-label');
    label.innerHTML = '<span>🔄</span> Swiss Rounds';
    wrapper.appendChild(label);

    const swissWrap = this._el('div', 'swiss-rounds');

    rounds.forEach((roundMatches, rIdx) => {
      const col = this._el('div', 'swiss-round-col');
      const hdr = this._el('div', 'swiss-round-header');
      hdr.textContent = `Round ${rIdx + 1}`;
      col.appendChild(hdr);

      roundMatches.forEach(m => {
        const card = this._buildMatchCard(m, true);
        card.style.marginBottom = '10px';
        col.appendChild(card);
      });

      swissWrap.appendChild(col);
    });

    wrapper.appendChild(swissWrap);
    this.container.appendChild(wrapper);
  }

  /* ════════════════════════════════════════════════════════
     MATCH CARD BUILDER
  ════════════════════════════════════════════════════════ */
  _buildMatchCard(match, standalone = false) {
    const status = this._matchStatus(match);
    const card   = this._el('div', `match-card status-${status}`);
    if (standalone) card.style.cssText = 'width:200px;';

    // Match number
    if (match.match_number) {
      const num = this._el('span', 'match-number');
      num.textContent = `#${match.match_number}`;
      card.appendChild(num);
    }

    // Player slots
    const p1 = match.player1 || match.home_player || {};
    const p2 = match.player2 || match.away_player || {};
    const winnerId = match.winner_id;

    card.appendChild(this._buildPlayerSlot(p1, match.score_p1 ?? match.score1, winnerId, standalone));
    card.appendChild(this._buildPlayerSlot(p2, match.score_p2 ?? match.score2, winnerId, standalone));

    // Status bar
    const statusBar = this._el('div', `match-status ${status}`);
    statusBar.innerHTML = this._statusHTML(status, match);
    card.appendChild(statusBar);

    // Events
    card.addEventListener('mouseenter', (e) => this._showTooltip(e, match));
    card.addEventListener('mouseleave', ()  => this._hideTooltip());
    card.addEventListener('mousemove',  (e) => this._moveTooltip(e));
    card.addEventListener('click', () => {
      if (typeof this.options.onMatchClick === 'function') {
        this.options.onMatchClick(match);
      } else {
        this._showMatchDetail(match);
      }
    });

    return card;
  }

  _buildPlayerSlot(player, score, winnerId, showScore = false) {
    const playerId = player?.id || player?.player_id;
    const isWinner = winnerId && String(playerId) === String(winnerId);
    const isLoser  = winnerId && playerId && !isWinner;
    const isTBD    = !player || !player.name;

    const slot = this._el('div',
      `player-slot${isWinner ? ' is-winner' : ''}${isLoser ? ' is-loser' : ''}`
    );

    // Avatar
    slot.appendChild(this._buildAvatar(player, isWinner));

    // Seed
    if (player?.seed) {
      const seed = this._el('span', 'player-seed');
      seed.textContent = player.seed;
      slot.appendChild(seed);
    }

    // Name
    const nameEl = this._el('span', `player-name${isTBD ? ' tbd' : ''}`);
    nameEl.textContent = isTBD ? 'TBD' : (player.name || player.display_name || 'Unknown');
    nameEl.title = nameEl.textContent;
    slot.appendChild(nameEl);

    // Score
    if (score !== null && score !== undefined && score !== '') {
      const scoreEl = this._el('span', `player-score${isWinner ? ' winner-score' : ''}`);
      scoreEl.textContent = score;
      slot.appendChild(scoreEl);
    }

    // Crown
    if (isWinner) {
      const crown = this._el('span', 'winner-crown');
      crown.textContent = '👑';
      slot.appendChild(crown);
    }

    return slot;
  }

  _buildAvatar(player, isWinner) {
    const avatar = this._el('div', `player-avatar${isWinner ? ' winner-avatar' : ''}`);

    if (player?.avatar_url) {
      const img = document.createElement('img');
      img.src   = player.avatar_url;
      img.alt   = player.name || '';
      img.onerror = () => {
        img.remove();
        avatar.textContent = this._initials(player.name);
      };
      avatar.appendChild(img);
    } else {
      avatar.textContent = this._initials(player?.name);
    }

    return avatar;
  }

  /* ════════════════════════════════════════════════════════
     TOOLTIP
  ════════════════════════════════════════════════════════ */
  _buildTooltip() {
    this.tooltip = this._el('div', 'bracket-tooltip');
    document.body.appendChild(this.tooltip);
  }

  _showTooltip(e, match) {
    const p1 = match.player1 || match.home_player || {};
    const p2 = match.player2 || match.away_player || {};

    this.tooltip.innerHTML = `
      <div class="tooltip-name">${this._esc(p1.name || 'TBD')} vs ${this._esc(p2.name || 'TBD')}</div>
      ${p1.seed ? `<div class="tooltip-row"><span>Seeds</span><span class="tooltip-val">#${p1.seed} vs #${p2.seed || '?'}</span></div>` : ''}
      ${p1.record ? `<div class="tooltip-row"><span>${this._esc(p1.name)}</span><span class="tooltip-val">${p1.record}</span></div>` : ''}
      ${p2.record ? `<div class="tooltip-row"><span>${this._esc(p2.name)}</span><span class="tooltip-val">${p2.record}</span></div>` : ''}
      ${match.scheduled_at ? `<div class="tooltip-row"><span>When</span><span class="tooltip-val">${this._formatDate(match.scheduled_at)}</span></div>` : ''}
      ${match.court ? `<div class="tooltip-row"><span>Court</span><span class="tooltip-val">${this._esc(match.court)}</span></div>` : ''}
      <div class="tooltip-row"><span>Status</span><span class="tooltip-val" style="text-transform:capitalize">${this._matchStatus(match)}</span></div>
    `;

    this.tooltip.classList.add('visible');
    this._moveTooltip(e);
  }

  _hideTooltip() {
    this.tooltip.classList.remove('visible');
  }

  _moveTooltip(e) {
    const x = e.clientX + 14;
    const y = e.clientY + 14;
    const tw = this.tooltip.offsetWidth;
    const th = this.tooltip.offsetHeight;
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    this.tooltip.style.left = `${Math.min(x, vw - tw - 8)}px`;
    this.tooltip.style.top  = `${Math.min(y, vh - th - 8)}px`;
  }

  /* ════════════════════════════════════════════════════════
     MATCH DETAIL MODAL
  ════════════════════════════════════════════════════════ */
  _showMatchDetail(match) {
    const existing = document.getElementById('bracket-match-modal');
    if (existing) existing.remove();

    const p1     = match.player1 || match.home_player || {};
    const p2     = match.player2 || match.away_player || {};
    const status = this._matchStatus(match);

    const overlay = this._el('div', 'modal-overlay');
    overlay.id = 'bracket-match-modal';

    const modal = this._el('div', 'modal');
    modal.innerHTML = `
      <div class="card-header" style="margin-bottom:16px;">
        <h5 style="font-family:var(--font-display);letter-spacing:.05em;">
          Match ${match.match_number ? '#' + match.match_number : 'Details'}
        </h5>
        <button class="btn btn-ghost btn-icon btn-sm" id="bracket-modal-close">✕</button>
      </div>

      <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:20px;">
        ${this._modalPlayerRow(p1, match.score_p1 ?? match.score1, match.winner_id)}
        <div style="text-align:center;color:var(--color-text-3);font-size:.75rem;letter-spacing:.1em;">VS</div>
        ${this._modalPlayerRow(p2, match.score_p2 ?? match.score2, match.winner_id)}
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.85rem;">
        ${match.round_name || match.bracket_round ? `
          <div style="background:var(--color-bg-3);border-radius:8px;padding:10px;">
            <div style="color:var(--color-text-3);font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px;">Round</div>
            <div style="color:var(--color-text);font-weight:600;">${this._esc(match.round_name || 'Round ' + match.bracket_round)}</div>
          </div>` : ''}
        ${match.court ? `
          <div style="background:var(--color-bg-3);border-radius:8px;padding:10px;">
            <div style="color:var(--color-text-3);font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px;">Court</div>
            <div style="color:var(--color-text);font-weight:600;">${this._esc(match.court)}</div>
          </div>` : ''}
        ${match.scheduled_at ? `
          <div style="background:var(--color-bg-3);border-radius:8px;padding:10px;">
            <div style="color:var(--color-text-3);font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px;">Scheduled</div>
            <div style="color:var(--color-text);font-weight:600;">${this._formatDate(match.scheduled_at)}</div>
          </div>` : ''}
        <div style="background:var(--color-bg-3);border-radius:8px;padding:10px;">
          <div style="color:var(--color-text-3);font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px;">Status</div>
          <div style="color:var(--color-text);font-weight:600;text-transform:capitalize;">${status}</div>
        </div>
      </div>
    `;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) overlay.remove();
    });
    modal.querySelector('#bracket-modal-close').addEventListener('click', () => overlay.remove());
  }

  _modalPlayerRow(player, score, winnerId) {
    const isWinner = winnerId && player?.id && String(player.id) === String(winnerId);
    const name = player?.name || player?.display_name || 'TBD';
    return `
      <div style="display:flex;align-items:center;gap:12px;padding:12px;
                  border-radius:10px;background:${isWinner ? 'rgba(255,215,0,0.07)' : 'var(--color-bg-3)'};
                  border:1px solid ${isWinner ? 'rgba(255,215,0,0.3)' : 'var(--color-border)'};">
        ${this._avatarHTML(player, 36)}
        <div style="flex:1;">
          <div style="font-weight:700;color:${isWinner ? 'var(--color-gold)' : 'var(--color-text)'};">${this._esc(name)}</div>
          ${player?.seed ? `<div style="font-size:.75rem;color:var(--color-text-3);">Seed #${player.seed}</div>` : ''}
          ${player?.record ? `<div style="font-size:.75rem;color:var(--color-text-2);">${player.record}</div>` : ''}
        </div>
        ${score != null ? `<div style="font-family:var(--font-mono);font-size:1.5rem;font-weight:700;color:${isWinner ? 'var(--color-gold)' : 'var(--color-text-2)'};">${score}</div>` : ''}
        ${isWinner ? '<span style="font-size:1.2rem;">👑</span>' : ''}
      </div>
    `;
  }

  /* ════════════════════════════════════════════════════════
     ANIMATIONS
  ════════════════════════════════════════════════════════ */
  _animateIn() {
    const cards = this.container.querySelectorAll('.match-card');
    cards.forEach((card, i) => {
      card.style.opacity   = '0';
      card.style.transform = 'translateY(8px)';
      card.style.transition = `opacity 300ms ease ${i * 30}ms, transform 300ms ease ${i * 30}ms`;
      requestAnimationFrame(() => {
        card.style.opacity   = '1';
        card.style.transform = 'translateY(0)';
      });
    });
  }

  /* ════════════════════════════════════════════════════════
     PUBLIC API
  ════════════════════════════════════════════════════════ */

  /** Reload bracket data from API */
  async refresh() {
    if (!this.options.tournamentId) return;
    this.data = null;
    await this._fetchData();
    this._render();
  }

  /** Update with new data (e.g. from WebSocket) */
  update(newData) {
    this.data = newData;
    this._render();
  }

  /** Highlight a specific match (e.g. current round) */
  highlightMatch(matchId) {
    this.container.querySelectorAll('.match-card').forEach(c => {
      c.style.outline = '';
    });
    const match = this.container.querySelector(`[data-match-id="${matchId}"]`);
    if (match) match.style.outline = '2px solid var(--color-gold)';
  }

  /** Destroy instance and clean up */
  destroy() {
    if (this.tooltip) this.tooltip.remove();
    this.container.innerHTML = '';
  }

  /* ════════════════════════════════════════════════════════
     HELPERS — DATA
  ════════════════════════════════════════════════════════ */
  _normalizeMatches(raw) {
    if (!Array.isArray(raw)) return [];
    return raw.map((m, i) => ({
      id:              m.id ?? m.match_id ?? i,
      match_number:    m.match_number ?? m.match_order ?? null,
      bracket_round:   m.bracket_round ?? m.round ?? m.round_number ?? 1,
      bracket_section: m.bracket_section ?? m.section ?? null,
      player1:         m.player1 ?? { id: m.player1_id, name: m.player1_name, seed: m.player1_seed, avatar_url: m.player1_avatar, record: m.player1_record },
      player2:         m.player2 ?? { id: m.player2_id, name: m.player2_name, seed: m.player2_seed, avatar_url: m.player2_avatar, record: m.player2_record },
      score_p1:        m.score_p1 ?? m.score1 ?? m.player1_score ?? null,
      score_p2:        m.score_p2 ?? m.score2 ?? m.player2_score ?? null,
      winner_id:       m.winner_id ?? null,
      status:          m.status ?? 'pending',
      court:           m.court ?? m.court_name ?? null,
      scheduled_at:    m.scheduled_at ?? m.start_time ?? null,
      next_match_id:   m.next_match_id ?? m.winner_goes_to ?? null,
      winner_goes_to:  m.winner_goes_to ?? m.next_match_id ?? null,
      round_name:      m.round_name ?? null,
    }));
  }

  _groupByRound(matches) {
    const map = {};
    matches.forEach(m => {
      const r = m.bracket_round ?? 1;
      if (!map[r]) map[r] = [];
      map[r].push(m);
    });
    return Object.keys(map)
      .sort((a, b) => Number(a) - Number(b))
      .map(k => map[k]);
  }

  _extractPlayers(matches) {
    const players = {};
    matches.forEach(m => {
      if (m.player1?.id) players[m.player1.id] = m.player1;
      if (m.player2?.id) players[m.player2.id] = m.player2;
    });
    return Object.values(players);
  }

  _calcStandings(matches, players) {
    const stats = {};
    players.forEach(p => {
      stats[p.id] = { player: p, wins: 0, losses: 0, points: 0 };
    });
    matches.forEach(m => {
      if (!m.winner_id) return;
      const loserId = String(m.player1?.id) === String(m.winner_id)
        ? m.player2?.id : m.player1?.id;
      if (stats[m.winner_id]) { stats[m.winner_id].wins++;   stats[m.winner_id].points += 2; }
      if (stats[loserId])     { stats[loserId].losses++; }
    });
    return Object.values(stats).sort((a, b) => b.points - a.points || b.wins - a.wins);
  }

  _matchStatus(match) {
    const s = (match.status || '').toLowerCase();
    if (s === 'completed' || s === 'done' || s === 'finished' || match.winner_id) return 'completed';
    if (s === 'active' || s === 'in_progress' || s === 'live') return 'active';
    if (s === 'bye') return 'bye';
    return 'pending';
  }

  _roundLabel(rIdx, total, matchCount) {
    const fromEnd = total - 1 - rIdx;
    if (fromEnd === 0) return 'FINAL';
    if (fromEnd === 1) return 'SEMI';
    if (fromEnd === 2) return 'QUARTER';
    return `ROUND ${rIdx + 1}`;
  }

  /* ════════════════════════════════════════════════════════
     HELPERS — DOM / SVG
  ════════════════════════════════════════════════════════ */
  _el(tag, cls) {
    const el = document.createElement(tag);
    if (cls) el.className = cls;
    return el;
  }

  _svgEl(tag, attrs = {}) {
    const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v));
    return el;
  }

  _avatarHTML(player, size = 26) {
    const initials = this._initials(player?.name);
    if (player?.avatar_url) {
      return `<img src="${this._esc(player.avatar_url)}" alt="${this._esc(player.name || '')}"
               style="width:${size}px;height:${size}px;border-radius:50%;object-fit:cover;border:1.5px solid var(--color-border-2);"
               onerror="this.outerHTML='<div style=\\'width:${size}px;height:${size}px;border-radius:50%;background:var(--color-surface-2);display:flex;align-items:center;justify-content:center;font-size:${Math.round(size*0.35)}px;font-weight:700;color:var(--color-text-2);border:1.5px solid var(--color-border-2);\\'>${initials}</div>'"
              >`;
    }
    return `<div style="width:${size}px;height:${size}px;border-radius:50%;background:var(--color-surface-2);
             display:flex;align-items:center;justify-content:center;font-size:${Math.round(size*0.35)}px;
             font-weight:700;color:var(--color-text-2);border:1.5px solid var(--color-border-2);flex-shrink:0;">${initials}</div>`;
  }

  _rankBadgeHTML(rank) {
    const medals = { 1: '🥇', 2: '🥈', 3: '🥉' };
    if (medals[rank]) return `<span style="font-size:1.2rem;">${medals[rank]}</span>`;
    const cls = rank <= 3 ? `rank-${rank}` : 'rank-n';
    return `<span class="rank-medal ${cls}">${rank}</span>`;
  }

  _statusHTML(status, match) {
    if (status === 'active')    return '<span class="status-dot active"></span> LIVE';
    if (status === 'completed') return '✓ COMPLETE';
    if (status === 'bye')       return 'BYE';
    if (match.scheduled_at)     return `🕐 ${this._formatDate(match.scheduled_at, true)}`;
    return '⏳ PENDING';
  }

  _initials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    if (parts.length === 1) return parts[0][0].toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }

  _formatDate(dateStr, short = false) {
    if (!dateStr) return '';
    try {
      const d = new Date(dateStr);
      if (short) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      return d.toLocaleDateString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch { return dateStr; }
  }

  _esc(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /* ════════════════════════════════════════════════════════
     LOADING / ERROR / EMPTY STATES
  ════════════════════════════════════════════════════════ */
  _showLoading() {
    this.container.innerHTML = `
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;
                  padding:64px;gap:16px;color:var(--color-text-3);">
        <div style="width:36px;height:36px;border:3px solid var(--color-border-2);
                    border-top-color:var(--color-gold);border-radius:50%;
                    animation:spin 0.8s linear infinite;"></div>
        <span style="font-size:.9rem;">Loading bracket…</span>
      </div>
      <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
    `;
  }

  _showError(msg) {
    this.container.innerHTML = `
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;
                  padding:64px;gap:12px;color:var(--color-danger);">
        <span style="font-size:2rem;">⚠️</span>
        <span style="font-size:.9rem;">Failed to load bracket: ${this._esc(msg)}</span>
        <button class="btn btn-ghost btn-sm" onclick="this.closest('.bracket-wrapper, [class]').dispatchEvent(new Event('retry'))">Retry</button>
      </div>
    `;
  }

  _showEmpty() {
    this.container.innerHTML = `
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;
                  padding:64px;gap:12px;color:var(--color-text-3);">
        <span style="font-size:2.5rem;">🏆</span>
        <span style="font-size:.95rem;">No bracket data yet.</span>
        <span style="font-size:.8rem;">The bracket will appear once the tournament is set up.</span>
      </div>
    `;
  }
}

/* ── Export ──────────────────────────────────────────────── */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = BracketRenderer;
}