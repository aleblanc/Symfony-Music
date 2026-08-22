/*
 * Rhythm Phase 2 — client-side audio analysis (v3, onset-first).
 *
 * Exposes window.rhythmAnalyze(streamUrl)
 *   -> Promise<{v:3, bpm, beats:[sec…], bands:{low:[[t,s]…], mid:[[t,s]…], high:[[t,s]…]}} | null>
 *
 * v3 rationale (playtest feedback: taps must land on the bass/drum hits —
 * "3 big drum hits in a row = 3 taps"):
 *   - THREE bands, filtered with pure-JS biquads (RBJ cookbook), so the exact
 *     production DSP also runs under Node for tests:
 *       low  = kick + bass      (2x low-pass 150 Hz)  -> the main taps
 *       mid  = snare/toms body  (high-pass 180 Hz + low-pass 2.5 kHz)
 *       high = hats/cymbals     (2x high-pass 5 kHz)  -> master/demon filler
 *   - Onset STRENGTHS are kept and normalised per band (0..1): the server
 *     picks notes by strength threshold per difficulty, so the "gros coups"
 *     always survive and weak mix noise is dropped.
 *   - The essentia.js beat grid (RhythmExtractor2013, vendored WASM) is now
 *     OPTIONAL and only used server-side to de-jitter (snap) onset times; if
 *     essentia fails the analysis still succeeds without beats.
 *   - The old "grid coverage" reliability gate is gone: onsets ARE the truth
 *     now, so off-grid (syncopated) music no longer falls back to Phase 1.
 *
 * All timestamps are RAW audio seconds (the game clock is
 * audioPlayback.currentTime). Works over plain HTTP.
 */
(function () {
    'use strict';

    var ONSET_RATE = 16000;   // band-analysis sample rate (mono)
    var HOP_S = 0.011;        // ~11 ms envelope frames
    var HOP = Math.max(1, Math.round(ONSET_RATE * HOP_S));

    /* ------------------------------------------------------------------ *
     *  Pure DSP core (no browser APIs) — shared with the Node test suite. *
     * ------------------------------------------------------------------ */

    // RBJ audio-EQ-cookbook biquad, direct form 1. Returns a new Float32Array.
    function biquad(pcm, type, fc, fs, q) {
        var w0 = 2 * Math.PI * (fc / fs);
        var cosW0 = Math.cos(w0);
        var sinW0 = Math.sin(w0);
        var alpha = sinW0 / (2 * q);
        var b0;
        var b1;
        var b2;
        if (type === 'lowpass') {
            b0 = (1 - cosW0) / 2; b1 = 1 - cosW0; b2 = (1 - cosW0) / 2;
        } else { // highpass
            b0 = (1 + cosW0) / 2; b1 = -(1 + cosW0); b2 = (1 + cosW0) / 2;
        }
        var a0 = 1 + alpha;
        var a1 = -2 * cosW0;
        var a2 = 1 - alpha;
        b0 /= a0; b1 /= a0; b2 /= a0; a1 /= a0; a2 /= a0;

        var out = new Float32Array(pcm.length);
        var x1 = 0, x2 = 0, y1 = 0, y2 = 0;
        for (var i = 0; i < pcm.length; i++) {
            var x = pcm[i];
            var y = b0 * x + b1 * x1 + b2 * x2 - a1 * y1 - a2 * y2;
            out[i] = y;
            x2 = x1; x1 = x;
            y2 = y1; y1 = y;
        }
        return out;
    }

    // Rectified amplitude RISE per frame — an attack detector (raw energy
    // would smear on sustained bass lines).
    function fluxSeries(pcm) {
        var amp = [];
        for (var i = 0; i < pcm.length; i += HOP) {
            var e = 0;
            for (var j = i, end = Math.min(i + HOP, pcm.length); j < end; j++) {
                e += Math.abs(pcm[j]);
            }
            amp.push(e);
        }
        var out = [0];
        for (var k = 1; k < amp.length; k++) {
            out.push(Math.max(0, amp[k] - amp[k - 1]));
        }
        return out;
    }

    // Envelope (per-frame rectified amplitude) — needed alongside the flux to
    // measure attack sharpness.
    function ampSeries(pcm) {
        var amp = [];
        for (var i = 0; i < pcm.length; i += HOP) {
            var e = 0;
            for (var j = i, end = Math.min(i + HOP, pcm.length); j < end; j++) {
                e += Math.abs(pcm[j]);
            }
            amp.push(e);
        }
        return amp;
    }

    // Adaptive peak picking. Returns [{t, s}] with s = excess over the local
    // moving average (raw, normalised later).
    //
    // When `ampArr` is provided (low band), each strength is additionally
    // weighted by ATTACK SHARPNESS = rise relative to the energy just before
    // the attack. A kick drum jumps out of near-silence (weight ~1) while a
    // legato bassline note rises from an already-loud sustain (weight << 1) —
    // this is what keeps the bass lane locked to real hits instead of mushy
    // note transitions (playtest 2026-08-22: bass felt sloppier than drums).
    function pickPeaks(series, sensitivity, minGapS, ampArr) {
        var win = Math.max(1, Math.round(0.25 / HOP_S));
        var globalMax = 0;
        for (var g = 0; g < series.length; g++) {
            if (series[g] > globalMax) {
                globalMax = series[g];
            }
        }
        var floor = globalMax * 0.01; // ignore silence-level jitter
        var ampEps = 0;
        if (ampArr) {
            var ampMax = 0;
            for (var a = 0; a < ampArr.length; a++) {
                if (ampArr[a] > ampMax) {
                    ampMax = ampArr[a];
                }
            }
            ampEps = ampMax * 0.02;
        }
        var peaks = [];
        var last = -1e9;
        for (var k = 1; k < series.length - 1; k++) {
            var sum = 0;
            var cnt = 0;
            for (var w = Math.max(0, k - win), wend = Math.min(series.length - 1, k + win); w <= wend; w++) {
                sum += series[w];
                cnt++;
            }
            var avg = sum / cnt;
            if (series[k] > avg * sensitivity && series[k] >= series[k - 1] && series[k] > series[k + 1] && series[k] > floor) {
                var t = (k * HOP) / ONSET_RATE;
                if (t - last >= minGapS) {
                    var s = series[k] - avg;
                    if (ampArr) {
                        var before = ampArr[Math.max(0, k - 2)] + ampEps;
                        s *= Math.min(1, series[k] / before);
                    }
                    peaks.push({ t: Math.round(t * 1000) / 1000, s: s });
                    last = t;
                }
            }
        }
        return peaks;
    }

    // Normalise strengths against the band's 95th percentile -> 0..1, and
    // emit the compact [t, s] pair format the server stores.
    function normalizePairs(peaks) {
        if (peaks.length === 0) {
            return [];
        }
        var ss = peaks.map(function (p) { return p.s; }).sort(function (a, b) { return a - b; });
        var p95 = ss[Math.min(ss.length - 1, Math.floor(ss.length * 0.95))] || 1;
        return peaks.map(function (p) {
            return [p.t, Math.round(Math.min(1, p.s / p95) * 100) / 100];
        });
    }

    // Full band analysis of a mono PCM buffer at ONSET_RATE.
    function analyzeBands(pcm) {
        var low = biquad(biquad(pcm, 'lowpass', 150, ONSET_RATE, 0.707), 'lowpass', 150, ONSET_RATE, 0.707);
        var mid = biquad(biquad(pcm, 'highpass', 180, ONSET_RATE, 0.707), 'lowpass', 2500, ONSET_RATE, 0.707);
        var high = biquad(biquad(pcm, 'highpass', 5000, ONSET_RATE, 0.707), 'highpass', 5000, ONSET_RATE, 0.707);
        // The low band gets attack-sharpness weighting (see pickPeaks) so
        // kicks/plucked hits outrank legato bassline transitions.
        return {
            low: normalizePairs(pickPeaks(fluxSeries(low), 1.5, 0.10, ampSeries(low))),
            mid: normalizePairs(pickPeaks(fluxSeries(mid), 1.6, 0.08)),
            high: normalizePairs(pickPeaks(fluxSeries(high), 1.7, 0.05)),
        };
    }

    var RhythmDsp = {
        ONSET_RATE: ONSET_RATE,
        HOP_S: HOP_S,
        biquad: biquad,
        fluxSeries: fluxSeries,
        ampSeries: ampSeries,
        pickPeaks: pickPeaks,
        normalizePairs: normalizePairs,
        analyzeBands: analyzeBands,
    };

    /* eslint-disable-next-line no-undef */
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = RhythmDsp; // Node test harness
    }
    if (typeof window === 'undefined') {
        return; // Node: DSP only, no browser glue
    }
    window.rhythmDsp = RhythmDsp;

    /* ------------------------------------------------------------------ *
     *  Browser glue: decode, essentia beat tracking, public entry point.  *
     * ------------------------------------------------------------------ */

    var AC = window.AudioContext || window.webkitAudioContext;
    var OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    if (!AC || !OAC) {
        return; // no Web Audio -> window.rhythmAnalyze stays undefined (caller falls back)
    }

    var MAX_SECONDS = 8 * 60; // safety cap
    var BEAT_RATE = 44100;    // essentia's RhythmExtractor2013 expects 44.1 kHz

    // ---- essentia.js lazy loader (global-defining classic scripts) ----
    var essentiaPromise = null;

    function loadScript(src) {
        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = src;
            s.onload = resolve;
            s.onerror = function () { reject(new Error('failed to load ' + src)); };
            document.head.appendChild(s);
        });
    }

    function getEssentia() {
        if (!essentiaPromise) {
            essentiaPromise = (async function () {
                if (typeof window.EssentiaWASM !== 'function') {
                    await loadScript('/vendor/essentia-wasm.web.js');
                }
                if (typeof window.Essentia !== 'function') {
                    await loadScript('/vendor/essentia.js-core.umd.js');
                }
                var wasm = await window.EssentiaWASM();
                return new window.Essentia(wasm);
            })();
            essentiaPromise.catch(function () { essentiaPromise = null; }); // allow retry
        }
        return essentiaPromise;
    }

    async function decode(streamUrl) {
        var buf = await (await fetch(streamUrl)).arrayBuffer();
        var ctx = new AC();
        try {
            return await ctx.decodeAudioData(buf);
        } finally {
            if (ctx.close) {
                ctx.close();
            }
        }
    }

    // Render the decoded audio into mono at the given sample rate (resampling
    // only — band filtering is done by the pure-JS DSP above).
    async function render(decoded, rate) {
        var seconds = Math.min(decoded.duration, MAX_SECONDS);
        var off = new OAC(1, Math.ceil(seconds * rate), rate);
        var src = off.createBufferSource();
        src.buffer = decoded;
        src.connect(off.destination);
        src.start();
        var rendered = await off.startRendering();
        return rendered.getChannelData(0);
    }

    // Beats are optional in v3: they only de-jitter note times server-side.
    async function trackBeats(decoded) {
        try {
            var essentia = await getEssentia();
            var beatPcm = await render(decoded, BEAT_RATE);
            var vec = essentia.arrayToVector(beatPcm);
            try {
                var res = essentia.RhythmExtractor2013(vec, 208, 'degara', 40);
                var beats = Array.from(essentia.vectorToArray(res.ticks), function (t) {
                    return Math.round(t * 1000) / 1000;
                });
                var bpm = res.bpm ? Math.round(res.bpm) : null;
                if (res.ticks && res.ticks.delete) {
                    res.ticks.delete();
                }
                return { beats: beats, bpm: bpm };
            } finally {
                if (vec.delete) {
                    vec.delete();
                }
            }
        } catch (e) {
            console.warn('[rhythm] beat tracking unavailable, onsets only', e);
            return { beats: [], bpm: null };
        }
    }

    // Core: analyse an already-decoded AudioBuffer. Exposed for testing.
    async function analyzeBuffer(decoded) {
        var onsetPcm = await render(decoded, ONSET_RATE);
        var bands = analyzeBands(onsetPcm);
        var total = bands.low.length + bands.mid.length + bands.high.length;
        if (bands.low.length + bands.mid.length < 8 || total < 12) {
            return null; // near-silent / percussion-free -> Phase 1 fallback
        }
        var tracked = await trackBeats(decoded);
        return {
            // v4 = attack-sharpness-weighted low band; older grids re-analyse
            // (beatAware flags check the exact version).
            v: 4,
            bpm: tracked.bpm,
            beats: tracked.beats,
            bands: bands,
        };
    }
    window.rhythmAnalyzeBuffer = analyzeBuffer;

    window.rhythmAnalyze = async function (streamUrl) {
        try {
            return await analyzeBuffer(await decode(streamUrl));
        } catch (e) {
            console.warn('[rhythm] audio analysis failed', e);
            return null;
        }
    };
})();
