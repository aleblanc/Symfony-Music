/*
 * Rhythm Phase 2 — client-side audio analysis.
 *
 * Exposes window.rhythmAnalyze(streamUrl)
 *   -> Promise<{bpm, beats:[sec…], bass:[sec…], drums:[sec…]} | null>.
 *
 * BEATS come from essentia.js (Essentia/UPF compiled to WebAssembly, AGPL,
 * vendored in /vendor/ — no CDN, no build): RhythmExtractor2013 is a
 * research-grade beat tracker, replacing the hand-rolled one whose grids
 * didn't follow real music (playtest 2026-08-21). The ~2.2 MB wasm is lazy
 * loaded only when a song actually needs analysing.
 *
 * BAND ONSETS (bass low-pass ~160 Hz, drums high-pass ~2 kHz, rectified
 * amplitude rise, weak half dropped) are still computed with plain Web Audio:
 * the chart generator uses them only to decide WHICH beat slots get a note
 * and on which side of the lane layout — not as note times.
 *
 * Reliability gate: if the strong band onsets don't line up with the fitted
 * beat grid (< 35 % on beats/half-beats), return null so the server falls
 * back to the Phase 1 pseudo-random generator instead of shipping a wrong
 * grid. All timestamps are RAW audio seconds (the game clock is
 * audioPlayback.currentTime). Works over plain HTTP.
 */
(function () {
    const AC = window.AudioContext || window.webkitAudioContext;
    const OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    if (!AC || !OAC) {
        return; // No Web Audio -> window.rhythmAnalyze stays undefined (caller falls back).
    }

    const ONSET_RATE = 16000;   // band-onset analysis sample rate (mono)
    const BEAT_RATE = 44100;    // essentia's RhythmExtractor2013 expects 44.1 kHz
    const MAX_SECONDS = 8 * 60; // safety cap
    const HOP_S = 0.011;        // ~11 ms frames
    const hop = Math.max(1, Math.round(ONSET_RATE * HOP_S));

    // ---- essentia.js lazy loader (global-defining classic scripts) ----
    let essentiaPromise = null;

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = src;
            s.onload = resolve;
            s.onerror = () => reject(new Error('failed to load ' + src));
            document.head.appendChild(s);
        });
    }

    function getEssentia() {
        if (!essentiaPromise) {
            essentiaPromise = (async () => {
                if (typeof window.EssentiaWASM !== 'function') {
                    await loadScript('/vendor/essentia-wasm.web.js');
                }
                if (typeof window.Essentia !== 'function') {
                    await loadScript('/vendor/essentia.js-core.umd.js');
                }
                const wasm = await window.EssentiaWASM();
                return new window.Essentia(wasm);
            })();
            essentiaPromise.catch(() => { essentiaPromise = null; }); // allow retry
        }
        return essentiaPromise;
    }

    async function decode(streamUrl) {
        const buf = await (await fetch(streamUrl)).arrayBuffer();
        const ctx = new AC();
        try {
            return await ctx.decodeAudioData(buf);
        } finally {
            if (ctx.close) {
                ctx.close();
            }
        }
    }

    // Render the decoded audio (optionally through a biquad chain) into mono
    // at the given sample rate.
    async function render(decoded, rate, filters) {
        const seconds = Math.min(decoded.duration, MAX_SECONDS);
        const off = new OAC(1, Math.ceil(seconds * rate), rate);
        const src = off.createBufferSource();
        src.buffer = decoded;
        let node = src;
        for (const spec of filters) {
            const f = off.createBiquadFilter();
            f.type = spec.type;
            f.frequency.value = spec.freq;
            node.connect(f);
            node = f;
        }
        node.connect(off.destination);
        src.start();
        const rendered = await off.startRendering();
        return rendered.getChannelData(0);
    }

    // Rectified amplitude RISE per frame — an attack detector (raw energy peaks
    // would smear on sustained bass lines).
    function fluxSeries(pcm) {
        const amp = [];
        for (let i = 0; i < pcm.length; i += hop) {
            let e = 0;
            for (let j = i, end = Math.min(i + hop, pcm.length); j < end; j++) {
                e += Math.abs(pcm[j]);
            }
            amp.push(e);
        }
        const out = [0];
        for (let k = 1; k < amp.length; k++) {
            out.push(Math.max(0, amp[k] - amp[k - 1]));
        }
        return out;
    }

    // Adaptive peak picking with strength (excess over the local moving average).
    function pickPeaks(series, sensitivity, minGapS) {
        const win = Math.max(1, Math.round(0.25 / HOP_S));
        const peaks = [];
        let last = -1e9;
        for (let k = 1; k < series.length - 1; k++) {
            let sum = 0;
            let cnt = 0;
            for (let w = Math.max(0, k - win), wend = Math.min(series.length - 1, k + win); w <= wend; w++) {
                sum += series[w];
                cnt++;
            }
            const avg = sum / cnt;
            if (series[k] > avg * sensitivity && series[k] >= series[k - 1] && series[k] > series[k + 1] && series[k] > 1e-7) {
                const t = (k * hop) / ONSET_RATE;
                if (t - last >= minGapS) {
                    peaks.push({ t: Math.round(t * 1000) / 1000, s: series[k] - avg });
                    last = t;
                }
            }
        }
        return peaks;
    }

    // Keep only the stronger half of the peaks — the weak tail is mix noise.
    function strongOnly(peaks) {
        if (peaks.length < 8) {
            return peaks.slice();
        }
        const ss = peaks.map((p) => p.s).sort((a, b) => a - b);
        const thr = ss[Math.floor(ss.length * 0.5)];
        return peaks.filter((p) => p.s >= thr);
    }

    // Fraction of strong onsets that sit on the grid (beats or half-beats).
    function gridCoverage(peaks, beats) {
        if (!beats || beats.length < 4 || peaks.length === 0) {
            return 0;
        }
        const grid = [];
        for (let i = 0; i < beats.length; i++) {
            grid.push(beats[i]);
            if (i + 1 < beats.length) {
                grid.push((beats[i] + beats[i + 1]) / 2);
            }
        }
        let hit = 0;
        for (const p of peaks) {
            let lo = 0;
            let hi = grid.length - 1;
            while (lo < hi) {
                const mid = (lo + hi) >> 1;
                if (grid[mid] < p.t) {
                    lo = mid + 1;
                } else {
                    hi = mid;
                }
            }
            const d = Math.min(Math.abs(grid[lo] - p.t), lo > 0 ? Math.abs(grid[lo - 1] - p.t) : 1e9);
            if (d < 0.07) {
                hit++;
            }
        }
        return hit / peaks.length;
    }

    // Core: analyse an already-decoded AudioBuffer. Exposed for testing.
    async function analyzeBuffer(decoded) {
        // 1. Beat tracking (essentia, 44.1 kHz mono). 'degara' is the fast
        //    variant; reliability is checked ourselves via onset coverage.
        const essentia = await getEssentia();
        const beatPcm = await render(decoded, BEAT_RATE, []);
        let beats = null;
        let bpm = null;
        const vec = essentia.arrayToVector(beatPcm);
        try {
            const res = essentia.RhythmExtractor2013(vec, 208, 'degara', 40);
            beats = Array.from(essentia.vectorToArray(res.ticks), (t) => Math.round(t * 1000) / 1000);
            bpm = res.bpm ? Math.round(res.bpm) : null;
            if (res.ticks && res.ticks.delete) {
                res.ticks.delete();
            }
        } finally {
            if (vec.delete) {
                vec.delete();
            }
        }
        if (!beats || beats.length < 8) {
            return null;
        }

        // 2. Per-band strong onsets (slot gating + lane side), plain Web Audio.
        const bassPcm = await render(decoded, ONSET_RATE, [{ type: 'lowpass', freq: 160 }]);
        const drumPcm = await render(decoded, ONSET_RATE, [{ type: 'highpass', freq: 2000 }]);
        const bassPeaks = strongOnly(pickPeaks(fluxSeries(bassPcm), 1.5, 0.12));
        const drumPeaks = strongOnly(pickPeaks(fluxSeries(drumPcm), 1.6, 0.09));
        const all = bassPeaks.concat(drumPeaks).sort((a, b) => a.t - b.t);

        // 3. Reliability gate: the music's own attacks must follow the grid.
        if (all.length < 8 || gridCoverage(all, beats) < 0.35) {
            return null; // unreliable -> Phase 1 fallback
        }

        return {
            bpm,
            beats,
            bass: bassPeaks.map((p) => p.t),
            drums: drumPeaks.map((p) => p.t),
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
