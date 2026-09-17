/* ============================================================
   achievements.js
   Pickleball Tournament System — Achievement interactions
   Location: assets/js/achievements.js
   ============================================================ */

'use strict';

const AchievementUI = (() => {
  const setupProgressToggle = () => {
    document.querySelectorAll('.achievement-card').forEach(card => {
      card.addEventListener('click', () => {
        if (!card.classList.contains('locked')) return;
        card.classList.toggle('expanded');
      });
    });
  };

  const init = () => {
    setupProgressToggle();
  };

  return { init };
})();

window.addEventListener('load', () => {
  AchievementUI.init();
});
