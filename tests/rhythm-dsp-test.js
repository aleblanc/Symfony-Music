/*
 * Node test for the pure-DSP core of public/rhythm-analyze.js.
 *
 * Synthesises a mono 16 kHz signal with KNOWN hits, runs the exact production
 * band analysis, and asserts the contract the game relies on:
 *   - 3 consecutive big kicks  -> exactly 3 low-band onsets at the right times
 *   - big kicks score higher strengths than quiet bass notes
 *   - snares land in the mid band, hats in the high band
 *
 * Run:  node tests/rhythm-dsp-test.js   (exit 0 = pass)
 */
'use strict';

const dsp = require('../public/rhythm-analyze.js');

const RATE = dsp.ONSET_RATE; // 16000
const DUR = 20;
const pcm = new Float32Array(RATE * DUR);

function addKick(t, amp, buf = pcm) {
    // 55 Hz sine burst, 5 ms attack ramp, 150 ms exponential decay.
    const start = Math.round(t * RATE);
    const len = Math.round(0.15 * RATE);
    for (let i = 0; i < len && start + i < buf.length; i++) {
        const env = Math.min(1, i / (0.005 * RATE)) * Math.exp(-i / (0.04 * RATE));
        buf[start + i] += amp * env * Math.sin(2 * Math.PI * 55 * (i / RATE));
    }
}

function noiseBurst(t, amp, lenS) {
    const start = Math.round(t * RATE);
    const len = Math.round(lenS * RATE);
    const burst = new Float32Array(len);
    for (let i = 0; i < len; i++) {
        const env = Math.min(1, i / (0.002 * RATE)) * Math.exp(-i / (lenS * 0.25 * RATE));
        burst[i] = amp * env * (Math.random() * 2 - 1);
    }
    return { start, burst };
}

function addSnare(t, amp) {
    // Band-limited noise (300–2500 Hz) + 200 Hz body, using the production biquad.
    const { start, burst } = noiseBurst(t, amp, 0.09);
    const shaped = dsp.biquad(dsp.biquad(burst, 'highpass', 300, RATE, 0.707), 'lowpass', 2500, RATE, 0.707);
    for (let i = 0; i < shaped.length && start + i < pcm.length; i++) {
        const env = Math.exp(-i / (0.03 * RATE));
        pcm[start + i] += shaped[i] + amp * 0.4 * env * Math.sin(2 * Math.PI * 200 * (i / RATE));
    }
}

function addHat(t, amp) {
    // High-passed noise tick (> 6 kHz).
    const { start, burst } = noiseBurst(t, amp, 0.03);
    const shaped = dsp.biquad(dsp.biquad(burst, 'highpass', 6000, RATE, 0.707), 'highpass', 6000, RATE, 0.707);
    for (let i = 0; i < shaped.length && start + i < pcm.length; i++) {
        pcm[start + i] += shaped[i];
    }
}

// --- Fixture: the musical scenario under test -------------------------------
const BIG_KICKS = [10.0, 10.5, 11.0]; // "3 gros coups de batterie d'affilée"
const QUIET_BASS = [5.0, 6.0];
const SNARES = [12.0, 12.5];

for (const t of BIG_KICKS) addKick(t, 0.9);
for (const t of QUIET_BASS) addKick(t, 0.18);
for (const t of SNARES) addSnare(t, 0.7);
for (let t = 2.0; t < 18.0; t += 0.25) addHat(t, 0.3);

// --- Run the production analysis --------------------------------------------
const bands = dsp.analyzeBands(pcm);

let failures = 0;
function check(label, ok, detail) {
    if (ok) {
        console.log(`  ok  ${label}`);
    } else {
        failures++;
        console.error(`FAIL  ${label}${detail ? ' — ' + detail : ''}`);
    }
}
const near = (list, t, tol) => list.filter((p) => Math.abs(p[0] - t) <= tol);

console.log(`low=${bands.low.length} mid=${bands.mid.length} high=${bands.high.length} onsets`);

// 1. The 3 big kicks: exactly 3 distinct low-band onsets, each within ±30 ms.
for (const t of BIG_KICKS) {
    check(`big kick @${t}s detected in low band`, near(bands.low, t, 0.03).length === 1,
        `matches=${JSON.stringify(near(bands.low, t, 0.05))}`);
}
const inRun = bands.low.filter((p) => p[0] >= 9.9 && p[0] <= 11.1);
check('3 consecutive big kicks -> exactly 3 taps', inRun.length === 3, `got ${inRun.length}: ${JSON.stringify(inRun)}`);

// 2. Strength ordering: every big kick outranks every quiet bass note.
const kickS = BIG_KICKS.map((t) => (near(bands.low, t, 0.03)[0] || [0, 0])[1]);
const bassS = QUIET_BASS.map((t) => (near(bands.low, t, 0.03)[0] || [0, 0])[1]);
check('big kicks are strong (>= 0.5 normalised)', kickS.every((s) => s >= 0.5), JSON.stringify(kickS));
check('quiet bass notes rank below the kicks', bassS.every((s) => s < Math.min(...kickS)),
    `bass=${JSON.stringify(bassS)} kicks=${JSON.stringify(kickS)}`);

// 3. Band separation: snares in mid, hats in high, hats NOT flooding low.
for (const t of SNARES) {
    check(`snare @${t}s detected in mid band`, near(bands.mid, t, 0.03).length >= 1);
}
check('hat ticks land in the high band (many)', bands.high.length >= 30, `got ${bands.high.length}`);
const lowBetweenHits = bands.low.filter((p) => p[0] > 1.5 && p[0] < 4.5);
check('hats do not leak into the low band', lowBetweenHits.length === 0, JSON.stringify(lowBetweenHits));

// --- Attack sharpness: legato bassline transitions are demoted ---------------
// A kick jumps out of silence (sharp attack -> full strength); a legato bass
// note change rises from an already-loud sustain -> heavily demoted, so the
// bass lane locks to real hits, not note transitions.
const pcm2 = new Float32Array(RATE * 12);
addKick(3.0, 0.8, pcm2);
// Sustained bass 6.0 -> 7.5 s (120 ms slow attack), legato switch 60 -> 80 Hz
// at 7.0 s with continuous phase (no click) and no amplitude dip.
{
    const start = Math.round(6.0 * RATE);
    const len = Math.round(1.5 * RATE);
    let phase = 0;
    for (let i = 0; i < len && start + i < pcm2.length; i++) {
        const tRel = i / RATE;
        const freq = tRel < 1.0 ? 60 : 80;
        phase += (2 * Math.PI * freq) / RATE;
        const env = Math.min(1, tRel / 0.12);
        pcm2[start + i] += 0.6 * env * Math.sin(phase);
    }
}
const bands2 = dsp.analyzeBands(pcm2);
const kick2 = near(bands2.low, 3.0, 0.03);
check('sharp kick from silence keeps full strength', kick2.length === 1 && kick2[0][1] >= 0.6, JSON.stringify(kick2));
const legato = bands2.low.filter((p) => p[0] >= 6.9 && p[0] <= 7.1 && p[1] >= 0.4);
check('legato bass note change is demoted (no strong onset)', legato.length === 0, JSON.stringify(legato));

process.exit(failures === 0 ? 0 : 1);
