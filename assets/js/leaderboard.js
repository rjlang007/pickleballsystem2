/* ============================================================
   leaderboard.js
   Pickleball Tournament System — Leaderboard interactions
   Location: assets/js/leaderboard.js
   ============================================================ */

'use strict';

const LeaderboardUI = (() => {
  const toggleSort = (container, sortKey) => {
    const rows = Array.from(container.querySelectorAll('.leaderboard-row'));
    rows.sort((a, b) => {
      const aVal = Number(a.dataset[sortKey] || '0');
      const bVal = Number(b.dataset[sortKey] || '0');
      return bVal - aVal;
    });
    rows.forEach(row => container.appendChild(row));
  };

  const setupSearch = (inputSelector, containerSelector) => {
    const input = document.querySelector(inputSelector);
    const container = document.querySelector(containerSelector);
    if (!input || !container) return;

    input.addEventListener('input', () => {
      const term = input.value.trim().toLowerCase();
      container.querySelectorAll('.leaderboard-row').forEach(row => {
        const name = row.querySelector('.leaderboard-player-name')?.textContent.toLowerCase() || '';
        row.style.display = name.includes(term) ? '' : 'none';
      });
    });
  };

  const setupFilter = (selectSelector, containerSelector) => {
    const select = document.querySelector(selectSelector);
    const container = document.querySelector(containerSelector);
    if (!select || !container) return;

    select.addEventListener('change', () => {
      const value = select.value;
      container.querySelectorAll('.leaderboard-row').forEach(row => {
        const skill = row.dataset.skillLevel || '';
        row.style.display = value === 'all' || value === skill ? '' : 'none';
      });
    });
  };

  const setupShare = (buttonSelector) => {
    document.querySelectorAll(buttonSelector).forEach(button => {
      button.addEventListener('click', async () => {
        const text = button.dataset.shareText || document.title;
        try {
          await navigator.clipboard.writeText(text);
          button.textContent = 'Copied!';
          setTimeout(() => button.textContent = 'Share', 1400);
        } catch (err) {
          console.error(err);
        }
      });
    });
  };

  const init = () => {
    setupSearch('#leaderboard-search', '.leaderboard-list');
    setupFilter('#leaderboard-filter', '.leaderboard-list');
    setupShare('.leaderboard-share-btn');
  };

  return { init, toggleSort };
})();

window.addEventListener('load', () => {
  LeaderboardUI.init();
});
