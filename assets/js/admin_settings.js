/* ============================================================
   admin_settings.js
   Simple admin UI helpers.
   Location: assets/js/admin_settings.js
   ============================================================ */

'use strict';

window.addEventListener('load', () => {
  const forms = document.querySelectorAll('#leaderboard-settings-form');
  forms.forEach(form => {
    form.addEventListener('submit', event => {
      const input = form.querySelector('[name="point_distribution"]');
      if (input && input.value.trim() === '') {
        event.preventDefault();
        alert('Please enter a valid point distribution.');
      }
    });
  });
});
