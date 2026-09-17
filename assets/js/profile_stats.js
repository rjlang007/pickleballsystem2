/* ============================================================
   profile_stats.js
   Pickleball Tournament System — Profile statistics interactions
   Location: assets/js/profile_stats.js
   ============================================================ */

'use strict';

const ProfileStatsUI = (() => {
  const bindTabs = () => {
    document.querySelectorAll('[data-profile-tab]').forEach(button => {
      button.addEventListener('click', () => {
        const target = button.dataset.profileTab;
        document.querySelectorAll('[data-profile-panel]').forEach(panel => {
          panel.classList.toggle('active', panel.dataset.profilePanel === target);
        });
        document.querySelectorAll('[data-profile-tab]').forEach(btn => btn.classList.toggle('active', btn === button));
      });
    });
  };

  const init = () => {
    bindTabs();
  };

  return { init };
})();

window.addEventListener('load', () => {
  ProfileStatsUI.init();
});
