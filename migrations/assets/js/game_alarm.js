// ============================================================
//  FILE: includes/game_alarm.js
//  Synthesized audio alarm system for Falcon Pickleball Court
//  VERSION: 3.0 — Maximum Impact Edition
//
//  PHASES & SOUNDS:
//  🟡 WARMUP      — Marimba cascade rising 2 octaves + sparkle chord (~4s)
//  🟢 START       — Triple air-horn blasts + drum cannonade + power chord (~6s)
//  🟠 ALMOST_END  — Siren chirps + double referee whistle + tension sweep (~5s)
//  🔴 END         — 5-stage finale: buzzer × 3 → siren wail → crowd roar
//                   → deep foghorn × 2 → closing gong toll (~9s)
//
//  USAGE (unchanged — drop-in replacement):
//    GameAlarm.warmup();
//    GameAlarm.start();
//    GameAlarm.almostEnd();
//    GameAlarm.end();
//    GameAlarm.unlock(); // call once on first user interaction
// ============================================================

const GameAlarm = (() => {
  let ctx = null;

  function getCtx() {
    if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }

  function unlock() { getCtx(); }

  // ── Master bus: gain boost → compressor → destination ──────
  function masterBus(ac) {
    const comp = ac.createDynamicsCompressor();
    comp.threshold.value = -6;
    comp.knee.value      = 3;
    comp.ratio.value     = 8;
    comp.attack.value    = 0.001;
    comp.release.value   = 0.1;
    const gain = ac.createGain();
    gain.gain.value = 1.8; // push everything LOUD before compressor
    gain.connect(comp);
    comp.connect(ac.destination);
    return gain;
  }

  // ── Oscillator with smooth ADSR envelope ───────────────────
  function osc(ac, dest, type, freq, t0, t1, pk = 0.5, freqTo = null, freqRampT = null) {
    const o = ac.createOscillator();
    const g = ac.createGain();
    o.type = type;
    o.frequency.setValueAtTime(freq, t0);
    if (freqTo !== null)
      o.frequency.linearRampToValueAtTime(freqTo, freqRampT ?? t1);
    const att = 0.01, rel = Math.min(0.18, (t1 - t0) * 0.2);
    g.gain.setValueAtTime(0, t0);
    g.gain.linearRampToValueAtTime(pk, t0 + att);
    g.gain.setValueAtTime(pk, t1 - rel);
    g.gain.linearRampToValueAtTime(0, t1);
    o.connect(g); g.connect(dest);
    o.start(t0); o.stop(t1 + 0.1);
  }

  // ── Exponential-decay kick drum ─────────────────────────────
  function kick(ac, dest, t0, fStart, fEnd, pk, dur) {
    const o = ac.createOscillator();
    const g = ac.createGain();
    o.type = 'sine';
    o.frequency.setValueAtTime(fStart, t0);
    o.frequency.exponentialRampToValueAtTime(fEnd, t0 + dur);
    g.gain.setValueAtTime(pk, t0);
    g.gain.exponentialRampToValueAtTime(0.001, t0 + dur + 0.06);
    o.connect(g); g.connect(dest);
    o.start(t0); o.stop(t0 + dur + 0.12);
  }

  // ── Filtered noise burst ────────────────────────────────────
  function noise(ac, dest, t0, dur, pk, ffreq, fq = 1, ftype = 'bandpass') {
    const len = Math.ceil(ac.sampleRate * (dur + 0.15));
    const buf = ac.createBuffer(1, len, ac.sampleRate);
    const d   = buf.getChannelData(0);
    for (let i = 0; i < len; i++) d[i] = Math.random() * 2 - 1;
    const src = ac.createBufferSource(); src.buffer = buf;
    const flt = ac.createBiquadFilter();
    flt.type = ftype; flt.frequency.value = ffreq; flt.Q.value = fq;
    const g   = ac.createGain();
    const rel = Math.min(0.15, dur * 0.18);
    g.gain.setValueAtTime(0, t0);
    g.gain.linearRampToValueAtTime(pk, t0 + 0.012);
    g.gain.setValueAtTime(pk, t0 + dur - rel);
    g.gain.linearRampToValueAtTime(0, t0 + dur);
    src.connect(flt); flt.connect(g); g.connect(dest);
    src.start(t0); src.stop(t0 + dur + 0.1);
  }

  // ── Marimba tone (woody bell character) ────────────────────
  function marimba(ac, dest, freq, t0, dur, pk = 0.4) {
    osc(ac, dest, 'sine',     freq,        t0, t0 + dur,       pk);
    osc(ac, dest, 'sine',     freq * 3.01, t0, t0 + dur * 0.28, pk * 0.28);
    osc(ac, dest, 'triangle', freq * 2,    t0, t0 + 0.045,     pk * 0.45);
  }

  // ── Stadium air-horn (7-layer wall of sound) ────────────────
  function airhorn(ac, dest, t0, dur, pk = 0.8) {
    osc(ac, dest, 'sawtooth', 233.1, t0, t0 + dur, pk);
    osc(ac, dest, 'sawtooth', 349.2, t0, t0 + dur, pk * 0.7);
    osc(ac, dest, 'sawtooth', 466.2, t0, t0 + dur, pk * 0.5);
    osc(ac, dest, 'square',   116.5, t0, t0 + dur, pk * 0.4);
    osc(ac, dest, 'sawtooth', 237.0, t0, t0 + dur, pk * 0.3);
    noise(ac, dest, t0, dur, pk * 0.35, 800, 0.4);
    kick(ac, dest, t0, 180, 35, pk * 0.9, 0.4);
  }

  // ── Referee whistle (sine + vibrato) ───────────────────────
  function whistle(ac, dest, freq, t0, dur, pk = 0.6) {
    const o   = ac.createOscillator();
    const g   = ac.createGain();
    const vib = ac.createOscillator();
    const vG  = ac.createGain();
    o.type = 'sine'; o.frequency.setValueAtTime(freq, t0);
    vib.type = 'sine'; vib.frequency.value = 8;
    vG.gain.value = freq * 0.018;
    vib.connect(vG); vG.connect(o.frequency);
    g.gain.setValueAtTime(0, t0);
    g.gain.linearRampToValueAtTime(pk, t0 + 0.014);
    g.gain.setValueAtTime(pk, t0 + dur - 0.04);
    g.gain.linearRampToValueAtTime(0, t0 + dur);
    o.connect(g); g.connect(dest);
    vib.start(t0); vib.stop(t0 + dur + 0.05);
    o.start(t0);   o.stop(t0 + dur + 0.05);
  }

  // ── Crowd roar (filtered noise sweep) ──────────────────────
  function crowd(ac, dest, t0, dur, pk, fStart, fEnd) {
    const len = Math.ceil(ac.sampleRate * (dur + 0.2));
    const buf = ac.createBuffer(1, len, ac.sampleRate);
    const d   = buf.getChannelData(0);
    for (let i = 0; i < len; i++) d[i] = Math.random() * 2 - 1;
    const src = ac.createBufferSource(); src.buffer = buf;
    const flt = ac.createBiquadFilter();
    flt.type = 'lowpass';
    flt.frequency.setValueAtTime(fStart, t0);
    flt.frequency.linearRampToValueAtTime(fEnd, t0 + dur);
    const g   = ac.createGain();
    const rel = dur * 0.3;
    g.gain.setValueAtTime(0, t0);
    g.gain.linearRampToValueAtTime(pk, t0 + 0.3);
    g.gain.setValueAtTime(pk, t0 + dur - rel);
    g.gain.linearRampToValueAtTime(0, t0 + dur);
    src.connect(flt); flt.connect(g); g.connect(dest);
    src.start(t0); src.stop(t0 + dur + 0.1);
  }

  // ── 🟡 WARMUP — Marimba Cascade + Sparkle Chord (~4s) ──────
  function warmup() {
    const ac = getCtx(); const out = masterBus(ac); const now = ac.currentTime;

    // Pentatonic run up 2 octaves: C D E G A C D E
    [261.6, 293.7, 329.6, 392, 440, 523.3, 587.3, 659.3].forEach((f, i) => {
      marimba(ac, out, f, now + i * 0.12, 0.34 - i * 0.015, 0.42);
    });

    // Big arrival chord: Cmaj7 (C E G B)
    const aT = now + 1.05;
    [523.3, 659.3, 784, 987.8].forEach(f => marimba(ac, out, f, aT, 1.0, 0.32));
    noise(ac, out, aT,        0.1,  0.18, 6000, 0.4, 'highpass');
    noise(ac, out, aT + 0.1,  0.7,  0.07, 10000, 0.3, 'highpass');

    // High pings
    osc(ac, out, 'sine', 1046.5, now + 1.9, now + 2.6, 0.38);
    osc(ac, out, 'sine', 1318.5, now + 2.0, now + 2.5, 0.22);
    osc(ac, out, 'sine', 2093,   now + 2.1, now + 2.4, 0.12);
    noise(ac, out, now + 1.9, 0.05, 0.15, 9000, 0.2, 'highpass');

    kick(ac, out, now, 120, 40, 0.55, 0.3);
  }

  // ── 🟢 START — Triple Air-Horn + Power Chord (~6s) ─────────
  function start() {
    const ac = getCtx(); const out = masterBus(ac); const now = ac.currentTime;

    // Three horn blasts — each bigger than the last
    airhorn(ac, out, now + 0.0,  0.55, 0.75);
    airhorn(ac, out, now + 0.75, 0.55, 0.85);
    airhorn(ac, out, now + 1.55, 0.80, 1.0);

    // Snare rolls between blasts
    for (let i = 0; i < 6; i++) noise(ac, out, now + 0.56 + i * 0.03, 0.05, 0.4,  3000, 2);
    for (let i = 0; i < 6; i++) noise(ac, out, now + 1.36 + i * 0.03, 0.05, 0.45, 3000, 2);

    // Rising excitement sweep
    osc(ac, out, 'sawtooth', 200, now + 2.45, now + 3.3, 0.55, 600);
    osc(ac, out, 'sawtooth', 100, now + 2.45, now + 3.3, 0.35, 300);

    // Crowd roar building
    crowd(ac, out, now + 2.6, 2.0, 0.5, 300, 2500);

    // Triumphant finale — F major chord, 7 voices
    const fT = now + 3.5;
    [87.3, 174.6, 220, 261.6, 349.2, 440, 523.3].forEach(f => {
      osc(ac, out, 'sawtooth', f, fT, fT + 1.5, f < 200 ? 0.5 : 0.38);
      osc(ac, out, 'sine',     f, fT, fT + 1.5, 0.18);
    });
    kick(ac, out, fT, 200, 25, 1.2, 0.6);
    noise(ac, out, fT,        0.12, 0.55, 1500, 1.2);
    noise(ac, out, fT + 0.12, 0.6,  0.2,  3000, 0.6);
  }

  // ── 🟠 ALMOST_END — Siren + Whistle + Tension (~5s) ────────
  function almostEnd() {
    const ac = getCtx(); const out = masterBus(ac); const now = ac.currentTime;

    // Rapid siren chirp pairs × 4
    [880, 1100, 880, 1100, 880, 1100, 880, 1100].forEach((f, i) => {
      const t = now + i * 0.2;
      osc(ac, out, 'square', f,       t, t + 0.14, 0.5);
      osc(ac, out, 'sine',   f * 0.5, t, t + 0.14, 0.22);
      noise(ac, out, t, 0.06, 0.22, 5000, 1.5);
    });

    // Sub heartbeat
    [0, 0.4, 0.8, 1.2, 1.6].forEach(t => kick(ac, out, now + t, 100, 40, 0.6, 0.22));

    // Double referee whistle
    whistle(ac, out, 2500, now + 1.8, 0.65, 0.7);
    noise(ac, out, now + 1.8, 0.65, 0.25, 3000, 0.8);
    whistle(ac, out, 2800, now + 2.6, 0.35, 0.6);
    noise(ac, out, now + 2.6, 0.35, 0.2, 3500, 0.8);

    // Descending tension sweep
    osc(ac, out, 'sawtooth', 660, now + 3.1, now + 4.5, 0.4, 220);
    osc(ac, out, 'sine',     440, now + 3.1, now + 4.5, 0.2, 110);

    // Final urgent blip
    osc(ac, out, 'square', 1320, now + 4.6, now + 4.85, 0.5);
    noise(ac, out, now + 4.6, 0.25, 0.3, 4000, 1.5);
  }

  // ── 🔴 END — 5-Stage Game Over Finale (~9s) ────────────────
  //
  //  Stage 1 (0.0s) — TRIPLE BUZZER BLASTS (NBA-style × 3)
  //  Stage 2 (2.2s) — FALLING SIREN WAIL × 2
  //  Stage 3 (4.5s) — CROWD ROAR EXPLOSION
  //  Stage 4 (5.2s) — DEEP FOGHORN × 2
  //  Stage 5 (7.5s) — CLOSING GONG TOLL + reverb bloom
  //
  function end() {
    const ac = getCtx(); const out = masterBus(ac); const now = ac.currentTime;

    // ── Stage 1: Triple buzzer blasts ──────────────────────────
    [0, 0.7, 1.4].forEach((tOff, idx) => {
      const t   = now + tOff;
      const dur = idx === 2 ? 0.6 : 0.5;
      osc(ac, out, 'square',   150, t, t + dur, 0.85);
      osc(ac, out, 'square',   75,  t, t + dur, 0.65);
      osc(ac, out, 'sawtooth', 225, t, t + dur, 0.5);
      kick(ac, out, t, 250, 30, 1.3, 0.55);
      noise(ac, out, t,       dur,  0.55, 400,  0.6);
      noise(ac, out, t,       0.08, 0.7,  4000, 1.5);
    });

    // ── Stage 2: Falling siren wail × 2 ───────────────────────
    const s2 = now + 2.2;
    osc(ac, out, 'sawtooth', 1200, s2,      s2 + 1.1, 0.65, 180);
    osc(ac, out, 'sawtooth', 600,  s2,      s2 + 1.1, 0.45, 90);
    osc(ac, out, 'square',   1200, s2,      s2 + 1.1, 0.35, 180);
    noise(ac, out, s2, 1.1, 0.3, 600, 0.5);
    const w2 = s2 + 1.0;
    osc(ac, out, 'sawtooth', 1400, w2, w2 + 1.2, 0.75, 160);
    osc(ac, out, 'sawtooth', 700,  w2, w2 + 1.2, 0.55, 80);
    osc(ac, out, 'square',   1400, w2, w2 + 1.2, 0.4,  160);
    noise(ac, out, w2, 1.2, 0.35, 700, 0.5);
    kick(ac, out, w2, 200, 30, 0.9, 0.5);

    // ── Stage 3: Crowd roar explosion ─────────────────────────
    const s3 = now + 4.5;
    crowd(ac, out, s3, 2.0, 0.65, 250, 3000);
    noise(ac, out, s3 + 0.3, 1.5, 0.2, 6000, 0.3, 'highpass');

    // ── Stage 4: Deep foghorn × 2 ─────────────────────────────
    const s4 = now + 5.2;
    osc(ac, out, 'sawtooth', 87.3,  s4, s4 + 1.5, 0.8);
    osc(ac, out, 'sawtooth', 130.8, s4, s4 + 1.5, 0.65);
    osc(ac, out, 'square',   65.4,  s4, s4 + 1.5, 0.5);
    osc(ac, out, 'sawtooth', 43.6,  s4, s4 + 1.5, 0.4);
    noise(ac, out, s4, 1.5, 0.45, 200, 0.4);
    kick(ac, out, s4, 180, 20, 1.2, 0.7);
    const f2 = s4 + 1.8;
    osc(ac, out, 'sawtooth', 110,   f2, f2 + 1.4, 0.75);
    osc(ac, out, 'sawtooth', 164.8, f2, f2 + 1.4, 0.6);
    osc(ac, out, 'square',   82.4,  f2, f2 + 1.4, 0.45);
    noise(ac, out, f2, 1.4, 0.4, 250, 0.4);
    kick(ac, out, f2, 160, 22, 1.0, 0.6);

    // ── Stage 5: Closing gong toll ─────────────────────────────
    const s5 = now + 7.5;
    const gF = 55; // A1 — deep and final
    [
      { r: 1.00, g: 0.75, d: 5.0 }, { r: 1.47, g: 0.55, d: 3.5 },
      { r: 2.05, g: 0.40, d: 2.5 }, { r: 3.01, g: 0.28, d: 1.8 },
      { r: 4.22, g: 0.16, d: 1.2 }, { r: 5.80, g: 0.09, d: 0.8 },
    ].forEach(p => {
      const o = ac.createOscillator(); const g = ac.createGain();
      o.type = 'sine'; o.frequency.value = gF * p.r;
      g.gain.setValueAtTime(p.g * 1.5, s5);
      g.gain.exponentialRampToValueAtTime(0.001, s5 + p.d);
      o.connect(g); g.connect(out);
      o.start(s5); o.stop(s5 + p.d + 0.1);
    });
    [600, 1100, 1800, 2800, 4200].forEach(f =>
      osc(ac, out, 'sine', f, s5, s5 + 0.1, 0.2));
    noise(ac, out, s5,        0.1, 0.65, 2000, 0.8);
    kick(ac, out, s5, 200, 18, 1.4, 0.7);
    noise(ac, out, s5 + 0.1, 3.5, 0.18, 350,  0.4);
  }

  // ── Public API (identical to v1 — zero breaking changes) ───
  return { unlock, warmup, start, almostEnd, end };
})();

// Auto-unlock on any interaction
['click', 'touchstart', 'keydown'].forEach(ev =>
  document.addEventListener(ev, () => GameAlarm.unlock(), { once: true })
);