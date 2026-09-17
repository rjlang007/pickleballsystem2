<?php
// ============================================================
//  FILE: includes/footer.php
//  Shared HTML footer + closing tags
//  FIXED: inline styles replaced with CSS classes
// ============================================================
?>
</main><!-- /main-content -->

<button id="back-to-top" type="button" aria-label="Back to top" title="Back to top">↑</button>

<footer class="footer">
    <div class="footer-inner">
        <nav class="footer-links" aria-label="Footer">
            <a href="<?= APP_URL ?>/leaderboard.php">Leaderboard</a>
            <span class="sep">&middot;</span>
            <a href="<?= APP_URL ?>/tournaments.php">Tournaments</a>
            <span class="sep">&middot;</span>
            <a href="<?= APP_URL ?>/public/help.php">Help</a>
            <span class="sep">&middot;</span>
            <a href="<?= APP_URL ?>/public/terms_of_service.php">Terms</a>
            <span class="sep">&middot;</span>
            <a href="<?= APP_URL ?>/public/privacy_policy.php">Privacy</a>
        </nav>
        <p class="footer-brand">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="18" height="18" aria-hidden="true" class="pb-logo footer-logo">
              <rect x="44" y="63" width="13" height="30" rx="6.5" fill="#00e5a0"/>
              <rect x="44" y="69" width="13" height="2.5" rx="1.2" fill="#003d2a" opacity="0.45"/>
              <rect x="44" y="75" width="13" height="2.5" rx="1.2" fill="#003d2a" opacity="0.45"/>
              <rect x="44" y="81" width="13" height="2.5" rx="1.2" fill="#003d2a" opacity="0.45"/>
              <rect x="20" y="8" width="58" height="60" rx="29" fill="#00e5a0"/>
              <circle cx="36" cy="22" r="3.8" fill="#003d2a" opacity="0.32"/>
              <circle cx="50" cy="22" r="3.8" fill="#003d2a" opacity="0.32"/>
              <circle cx="64" cy="22" r="3.8" fill="#003d2a" opacity="0.32"/>
              <circle cx="43" cy="33" r="3.8" fill="#003d2a" opacity="0.32"/>
              <circle cx="57" cy="33" r="3.8" fill="#003d2a" opacity="0.32"/>
              <circle cx="36" cy="44" r="3.8" fill="#003d2a" opacity="0.32"/>
              <circle cx="50" cy="44" r="3.8" fill="#003d2a" opacity="0.32"/>
              <circle cx="64" cy="44" r="3.8" fill="#003d2a" opacity="0.32"/>
              <circle cx="43" cy="55" r="3.8" fill="#003d2a" opacity="0.32"/>
              <circle cx="57" cy="55" r="3.8" fill="#003d2a" opacity="0.32"/>
              <circle cx="80" cy="18" r="14" fill="#f5e642" stroke="#00e5a0" stroke-width="2.5"/>
              <circle cx="74" cy="13" r="2.2" fill="#d4c820" opacity="0.8"/>
              <circle cx="83" cy="11" r="2.2" fill="#d4c820" opacity="0.8"/>
              <circle cx="88" cy="19" r="2.2" fill="#d4c820" opacity="0.8"/>
              <circle cx="84" cy="26" r="2.2" fill="#d4c820" opacity="0.8"/>
              <circle cx="75" cy="25" r="2.2" fill="#d4c820" opacity="0.8"/>
              <path d="M67 16 Q80 10 92 18" stroke="#c8b800" stroke-width="1.2" fill="none" opacity="0.6"/>
              <path d="M68 22 Q80 28 92 20" stroke="#c8b800" stroke-width="1.2" fill="none" opacity="0.6"/>
            </svg>
            <strong class="footer-appname"><?= APP_NAME ?></strong>
        </p>
        <p class="footer-copy">
            &copy; <?= date('Y') ?> &nbsp;&middot;&nbsp;
            Developed by Engr. Randall James Oculam
        </p>
    </div>
</footer>

<script src="<?= APP_URL ?>/assets/js/app.js"></script>

<script nonce="<?= csrfNonce() ?>">
/* ── Game timer helper (used by admin dashboard) ─────────── */
function startGameTimer(endIso, elementId) {
    const el = document.getElementById(elementId);
    if (!el) return;
    function tick() {
        const diff = Math.max(0, Math.floor((new Date(endIso) - Date.now()) / 1000));
        const m = Math.floor(diff / 60);
        const s = diff % 60;
        el.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        el.style.color = diff <= 60 ? 'var(--danger)' : diff <= 300 ? 'var(--warn)' : 'var(--accent)';
        if (diff > 0) setTimeout(tick, 1000);
    }
    tick();
}

/* ── Back-to-top button ───────────────────────────────────── */
(function () {
    const btn = document.getElementById('back-to-top');
    if (!btn) return;
    const toggle = () => btn.classList.toggle('visible', window.scrollY > 420);
    window.addEventListener('scroll', toggle, { passive: true });
    toggle();
    btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
})();
</script>
</body>
</html>