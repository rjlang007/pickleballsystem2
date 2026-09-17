<?php
// ============================================================
//  FILE: includes/logo.php
//  Single source of truth for the Padol pickleball SVG logo.
//  Include this wherever you need the logo outside of header.php
//  (header.php already defines pickleballLogo() itself).
//
//  Usage:
//    require_once __DIR__ . '/../includes/logo.php';
//    echo pickleballLogo(28);   // 28 px
// ============================================================
if (!function_exists('pickleballLogo')) {
    function pickleballLogo(int $size = 30): string {
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="{$size}" height="{$size}" aria-hidden="true" class="pb-logo" style="display:inline-block;vertical-align:middle;flex-shrink:0;">
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
SVG;
    }
}