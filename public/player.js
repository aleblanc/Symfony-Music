/*
 * Audio player + queue + per-track "⋮" context menu.
 *
 * Any element with `data-track` participates in the queue. Clicking a track (or
 * its play button) builds a queue from every `data-track` on the page, starting
 * at the clicked one. A listen is reported to the server once 30s of a track
 * have played, mirroring the legacy behaviour.
 */

const cfg = (() => {
    const el = document.getElementById('player-config');
    try { return el ? JSON.parse(el.textContent) : {}; } catch { return {}; }
})();

// Icon path data (single-path Material-ish glyphs) for the context menu.
const ICON = {
    play: 'M8 5v14l11-7z',
    next: 'M4 6h11v2H4zm0 4h11v2H4zm0 4h7v2H4zm13-8v12l5-6z',
    queue: 'M4 6h12v2H4zm0 4h12v2H4zm0 4h8v2H4zm10 .5v6l5-3z',
    artist: 'M12 12a5 5 0 100-10 5 5 0 000 10zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5z',
    album: 'M12 2a10 10 0 100 20 10 10 0 000-20zm0 13a3 3 0 110-6 3 3 0 010 6z',
    info: 'M12 2a10 10 0 100 20 10 10 0 000-20zm1 15h-2v-6h2zm0-8h-2V7h2z',
    download: 'M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z',
    heart: 'M12 21s-6.7-4.35-9.33-8.24C.9 10.06 1.4 6.5 4.2 5.2 6.2 4.3 8.6 5 12 8.5 15.4 5 17.8 4.3 19.8 5.2c2.8 1.3 3.3 4.86 1.53 7.56C18.7 16.65 12 21 12 21z',
    clock: 'M12 2a10 10 0 100 20 10 10 0 000-20zm1 11H8v-2h3V7h2z',
    cloud: 'M19.35 10.04A7.49 7.49 0 0012 4a7.5 7.5 0 00-6.96 4.73A6 6 0 006 20h13a5 5 0 00.35-9.96zM12 11l4 4h-3v3h-2v-3H8z',
    edit: 'M3 17.25V21h3.75L17.8 9.94l-3.75-3.75zM20.7 7.04a1 1 0 000-1.41l-2.34-2.34a1 1 0 00-1.41 0l-1.83 1.83 3.75 3.75z',
    ban: 'M12 2a10 10 0 100 20 10 10 0 000-20zM4 12a8 8 0 0112.9-6.3L5.7 16.9A7.9 7.9 0 014 12zm8 8a7.9 7.9 0 01-4.9-1.7L18.3 7.1A8 8 0 0112 20z',
};
const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * LRC → [{time (s), text}] sorted by time; [] when the text has no
 * timestamps (plain lyrics). Handles several stamps per line, [mm:ss],
 * [mm:ss.xx] / [mm:ss:xx], word-level <mm:ss.xx> tags and [offset:±ms].
 */
function parseLrc(text) {
    if (!text) return [];
    const stamp = /\[(\d{1,3}):(\d{2})(?:[.:](\d{1,3}))?\]/g;
    let offset = 0;
    const lines = [];
    for (const raw of text.split(/\r?\n/)) {
        const off = raw.match(/^\s*\[offset:\s*([+-]?\d+)\s*\]/i);
        if (off) { offset = parseInt(off[1], 10) / 1000; continue; }
        const stamps = [...raw.matchAll(stamp)];
        if (stamps.length === 0) continue;
        const lineText = raw.replace(stamp, '').replace(/<\d{1,3}:\d{2}(?:[.:]\d{1,3})?>/g, '').trim();
        for (const m of stamps) {
            const frac = m[3] ? parseInt(m[3], 10) / Math.pow(10, m[3].length) : 0;
            lines.push({ time: parseInt(m[1], 10) * 60 + parseInt(m[2], 10) + frac, text: lineText });
        }
    }
    // A positive offset shows the lyrics earlier.
    return lines.map((l) => ({ time: Math.max(0, l.time - offset), text: l.text })).sort((a, b) => a.time - b.time);
}
function iconSvg(d) {
    const s = document.createElementNS(SVG_NS, 'svg');
    s.setAttribute('viewBox', '0 0 24 24');
    const p = document.createElementNS(SVG_NS, 'path');
    p.setAttribute('d', d);
    s.appendChild(p);
    return s;
}

const audio = document.getElementById('audio');
const bar = document.getElementById('player');
if (audio && bar) {
    const els = {
        cover: document.getElementById('player-cover'),
        title: document.getElementById('player-title'),
        artist: document.getElementById('player-artist'),
        album: document.getElementById('player-album'),
        albumSep: document.getElementById('player-album-sep'),
        toggle: document.getElementById('player-toggle'),
        prev: document.getElementById('player-prev'),
        next: document.getElementById('player-next'),
        seek: document.getElementById('player-seek'),
        current: document.getElementById('player-current'),
        duration: document.getElementById('player-duration'),
        volume: document.getElementById('player-volume'),
        pip: document.getElementById('player-pip'),
    };

    let queue = [];
    let index = -1;
    let recorded = false;

    const store = {
        get(k, d) { try { return localStorage.getItem(k) ?? d; } catch { return d; } },
        set(k, v) { try { localStorage.setItem(k, v); } catch { /* storage unavailable */ } },
    };

    // Per-track data fetched when a track starts (/song/{id}/playback): lyrics,
    // ReplayGain, the viewer's volume multiplier + EQ. Null while loading.
    let info = null;
    let infoAbort = null;

    function fetchInfo(t) {
        info = null;
        if (infoAbort) infoAbort.abort();
        applyProcessing();
        renderLyrics();
        if (!cfg.playbackUrl || !t.id) return;
        infoAbort = new AbortController();
        fetch(cfg.playbackUrl.replace(/\/0\/playback$/, `/${t.id}/playback`), {
            headers: { 'X-Requested-With': 'fetch' }, signal: infoAbort.signal,
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => {
                if (!d || queue[index] !== t) return;
                info = d;
                if (d.rating != null) {
                    t.rating = Number(d.rating);
                    if (playerRate) fillStars(playerRate, t);
                    renderQueue();
                }
                applyProcessing();
                renderLyrics();
            })
            .catch(() => {});
    }

    // ---------------- Audio processing: volume / EQ + ReplayGain ----------------
    // <audio> → gain (per-track volume × ReplayGain) → 5 EQ bands (the legacy
    // app's) → speakers. Built lazily, the first time a track needs it: once
    // an element is routed through an AudioContext it can't go back, and a
    // suspended context means silence, so a library without EQ settings or
    // ReplayGain tags never touches Web Audio.
    const EQ_FREQS = [60, 230, 910, 3600, 14000];
    const RG_MODES = ['track', 'album', 'off'];
    let rgMode = store.get('player.replayGain', 'track');
    if (!RG_MODES.includes(rgMode)) rgMode = 'track';
    const engine = { ctx: null, gain: null, bands: [], ready: false, waiting: false, failed: false };

    function connectGraph() {
        try {
            const ctx = engine.ctx;
            const source = ctx.createMediaElementSource(audio);
            engine.gain = ctx.createGain();
            source.connect(engine.gain);
            let prev = engine.gain;
            engine.bands = EQ_FREQS.map((freq, i) => {
                const band = ctx.createBiquadFilter();
                band.type = i === 0 ? 'lowshelf' : (i === EQ_FREQS.length - 1 ? 'highshelf' : 'peaking');
                band.frequency.value = freq;
                band.gain.value = 0;
                prev.connect(band);
                prev = band;
                return band;
            });
            prev.connect(ctx.destination);
            engine.ready = true;
        } catch {
            engine.failed = true;
        }
        return engine.ready;
    }

    function ensureGraph() {
        if (engine.ready) return true;
        if (engine.failed) return false;
        if (!engine.ctx) {
            const AC = window.AudioContext || window.webkitAudioContext;
            try { engine.ctx = AC ? new AC() : null; } catch { engine.ctx = null; }
            if (!engine.ctx) { engine.failed = true; return false; }
        }
        if (engine.ctx.state === 'running') return connectGraph();
        // Created outside a user gesture: routing the element now would mute
        // it until the next click. Keep it unprocessed until the context runs.
        if (!engine.waiting) {
            engine.waiting = true;
            engine.ctx.resume().then(() => {
                engine.waiting = false;
                if (engine.ctx.state === 'running' && connectGraph()) applyProcessing();
            }).catch(() => { engine.waiting = false; });
        }
        return false;
    }
    document.addEventListener('pointerdown', () => {
        if (engine.ctx && engine.ctx.state !== 'running') engine.ctx.resume().catch(() => {});
    });

    // Linear gain for the current ReplayGain mode. Album mode falls back to
    // the track values and vice versa; the peak keeps it from clipping.
    function replayGainScale(rg) {
        if (!rg || rgMode === 'off') return 1;
        const scope = rgMode === 'album'
            ? (rg.albumGain != null ? 'album' : 'track')
            : (rg.trackGain != null ? 'track' : 'album');
        const gain = rg[scope + 'Gain'];
        if (gain == null) return 1;
        const peak = rg[scope + 'Peak'];
        let scale = Math.pow(10, gain / 20);
        if (peak && scale * peak > 1) scale = 1 / peak;
        return Math.max(0.1, Math.min(scale, 3));
    }

    function applyProcessing() {
        const volume = info && typeof info.volumeMultiplier === 'number' ? info.volumeMultiplier : 1;
        const eq = info && Array.isArray(info.eqBands) && info.eqBands.length === EQ_FREQS.length
            ? info.eqBands.map((v) => parseFloat(v) || 0)
            : EQ_FREQS.map(() => 0);
        const scale = volume * replayGainScale(info ? info.replayGain : null);
        const neutral = Math.abs(scale - 1) < 0.001 && eq.every((v) => v === 0);
        if (!engine.ready && (neutral || !ensureGraph())) return;
        if (engine.ctx.state === 'suspended') engine.ctx.resume().catch(() => {});
        const now = engine.ctx.currentTime;
        engine.gain.gain.setTargetAtTime(scale, now, 0.05);
        engine.bands.forEach((band, i) => band.gain.setTargetAtTime(eq[i], now, 0.05));
    }

    const rgBtn = document.getElementById('player-rg');
    const RG_TITLE = {
        track: 'js.player.rg.title_track',
        album: 'js.player.rg.title_album',
        off: 'js.player.rg.title_off',
    };
    function renderRgButton() {
        if (!rgBtn) return;
        rgBtn.textContent = rgMode === 'off' ? 'RG' : (rgMode === 'album' ? 'RG·A' : 'RG·T');
        rgBtn.title = I18N.t(RG_TITLE[rgMode]);
        rgBtn.classList.toggle('active', rgMode !== 'off');
    }
    if (rgBtn) {
        renderRgButton();
        rgBtn.addEventListener('click', () => {
            rgMode = RG_MODES[(RG_MODES.indexOf(rgMode) + 1) % RG_MODES.length];
            store.set('player.replayGain', rgMode);
            renderRgButton();
            applyProcessing();
            toast(rgMode === 'off' ? I18N.t('js.player.rg.toast_off') : I18N.t('js.player.rg.toast_mode', { mode: rgMode }));
        });
    }

    // Live preview from the song page's volume / EQ sliders (and after a reset).
    window.appPlayer = {
        previewSettings(songId, volume, eq) {
            const t = queue[index];
            if (!info || !t || String(t.id) !== String(songId)) return;
            info.volumeMultiplier = volume;
            info.eqBands = eq;
            applyProcessing();
        },
    };

    // ---------------- Lyrics panel (synced LRC or plain text) ----------------
    const lyricsBtn = document.getElementById('player-lyrics');
    const lyricsPanel = document.getElementById('lyrics-panel');
    const lyricsBody = document.getElementById('lyrics-body');
    let lrc = []; // [{time, el}] of the current track, sorted by time
    let lrcActive = -1;

    function centerLine(el, behavior) {
        lyricsBody.scrollTo({ top: el.offsetTop - (lyricsBody.clientHeight - el.offsetHeight) / 2, behavior });
    }

    function renderLyrics() {
        if (!lyricsBody) return;
        lrc = [];
        lrcActive = -1;
        lyricsBody.replaceChildren();
        lyricsBody.classList.remove('synced');
        if (lyricsBtn) lyricsBtn.classList.toggle('has-lyrics', !!(info && info.lyrics));
        const message = (text) => {
            const p = document.createElement('p');
            p.className = 'lyrics-empty';
            p.textContent = text;
            lyricsBody.appendChild(p);
        };
        if (!info) { message(index < 0 ? I18N.t('js.player.lyrics.nothing_playing') : I18N.t('js.common.loading')); return; }
        if (!info.lyrics) { message(I18N.t('js.player.lyrics.none')); return; }

        const lines = parseLrc(info.lyrics);
        if (lines.length === 0) {
            const plain = document.createElement('div');
            plain.className = 'lyrics-plain';
            plain.textContent = info.lyrics;
            lyricsBody.appendChild(plain);
            return;
        }
        lyricsBody.classList.add('synced');
        lrc = lines.map((line) => {
            const el = document.createElement('button');
            el.type = 'button';
            el.className = 'lyric-line';
            el.textContent = line.text || '♪';
            el.dataset.time = String(line.time);
            lyricsBody.appendChild(el);
            return { time: line.time, el };
        });
        syncLyrics();
    }

    function syncLyrics() {
        if (lrc.length === 0) return;
        const now = audio.currentTime + 0.15; // timeupdate fires ~4×/s: show a line slightly early
        let i = lrcActive;
        if (i >= 0 && lrc[i].time > now) i = -1; // seeked backwards
        while (i + 1 < lrc.length && lrc[i + 1].time <= now) i++;
        if (i === lrcActive) return;
        if (lrcActive >= 0) lrc[lrcActive].el.classList.remove('active');
        lrcActive = i;
        if (i < 0) return;
        lrc[i].el.classList.add('active');
        if (!lyricsPanel.hidden) centerLine(lrc[i].el, 'smooth');
    }

    function setLyricsOpen(open) {
        if (!lyricsPanel) return;
        if (open) setQueueOpen(false);
        lyricsPanel.hidden = !open;
        if (lyricsBtn) {
            lyricsBtn.classList.toggle('active', open);
            lyricsBtn.setAttribute('aria-expanded', String(open));
        }
        if (open && lrcActive >= 0) centerLine(lrc[lrcActive].el, 'auto');
    }

    if (lyricsPanel && lyricsBody) {
        renderLyrics();
        if (lyricsBtn) lyricsBtn.addEventListener('click', () => setLyricsOpen(lyricsPanel.hidden));
        const close = document.getElementById('lyrics-close');
        if (close) close.addEventListener('click', () => setLyricsOpen(false));
        lyricsBody.addEventListener('click', (e) => {
            const line = e.target.closest('.lyric-line');
            if (line) audio.currentTime = parseFloat(line.dataset.time);
        });
    }

    // ---------------- Play queue panel ----------------
    // What plays now and next: click a track to jump to it, × to remove it,
    // "Clear upcoming" to drop everything after the current track. Same place
    // as the lyrics panel (one or the other); Q toggles it.
    const queueBtn = document.getElementById('player-queue');
    const queuePanel = document.getElementById('queue-panel');
    const queueBody = document.getElementById('queue-body');
    const queueCount = document.getElementById('queue-count');

    // ---------------- Ratings of the playing / queued tracks ----------------
    // Same star boxes as partials/_rating.html.twig (the click handler below
    // saves them), built here for the player bar and the queue panel.
    const playerRate = document.getElementById('player-rate');
    const rateUrl = (id) => (cfg.ratingUrl && cfg.ratingCsrf && id ? cfg.ratingUrl.replace(/\/0$/, '/' + id) : '');
    // Known rating: the queue entry's, else a star box of the page, else none.
    function ratingOf(t) {
        if (t.rating != null) return Number(t.rating);
        const box = document.querySelector(`[data-rate-url="${rateUrl(t.id)}"]:not(#player-rate)`);
        return box ? Number(box.dataset.stars || 0) : 0;
    }
    function fillStars(box, t) {
        const url = rateUrl(t.id);
        box.hidden = !url;
        if (!url) return box;
        const v = ratingOf(t);
        box.dataset.rateUrl = url;
        box.dataset.stars = String(v);
        box.classList.toggle('rated', v > 0);
        box.replaceChildren(...[1, 2, 3, 4, 5].map((n) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = '★';
            b.dataset.rate = String(n);
            b.className = n <= v ? 'on' : '';
            b.setAttribute('role', 'radio');
            b.setAttribute('aria-checked', n === v ? 'true' : 'false');
            b.title = I18N.t('js.player.rating.stars', { count: n });
            return b;
        }));
        return box;
    }

    function renderQueue() {
        if (queueBtn) queueBtn.classList.toggle('has-queue', queue.length > 1);
        if (!queuePanel || !queueBody || queuePanel.hidden) return;
        const upcoming = Math.max(0, queue.length - index - 1);
        if (queueCount) queueCount.textContent = queue.length ? I18N.t('js.player.queue.count', { position: index + 1, total: queue.length, upcoming }) : '';
        queueBody.replaceChildren(...queue.map((t, i) => {
            const li = document.createElement('li');
            li.className = 'queue-item' + (i === index ? ' current' : '') + (i < index ? ' played' : '');
            li.dataset.queueIndex = String(i);
            const img = document.createElement('img');
            img.alt = '';
            img.loading = 'lazy';
            if (t.cover) img.src = t.cover;
            const text = document.createElement('span');
            text.className = 'queue-text';
            const title = document.createElement('strong');
            title.textContent = t.title;
            const artist = document.createElement('span');
            artist.className = 'muted';
            artist.textContent = t.artist;
            text.append(title, artist);
            li.append(img, text);
            if (rateUrl(t.id)) {
                const rate = document.createElement('span');
                rate.className = 'rate queue-rate';
                rate.setAttribute('role', 'radiogroup');
                rate.setAttribute('aria-label', I18N.t('js.player.queue.rating'));
                li.append(fillStars(rate, t));
            }
            if (i !== index) {
                const rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'queue-remove';
                rm.title = I18N.t('js.player.queue.remove_title');
                rm.setAttribute('aria-label', I18N.t('js.player.queue.remove_aria', { title: t.title }));
                rm.textContent = '×';
                li.append(rm);
            }
            return li;
        }));
    }

    function setQueueOpen(open) {
        if (!queuePanel) return;
        if (open && lyricsPanel && !lyricsPanel.hidden) setLyricsOpen(false);
        queuePanel.hidden = !open;
        if (queueBtn) {
            queueBtn.classList.toggle('active', open);
            queueBtn.setAttribute('aria-expanded', String(open));
        }
        if (open) {
            renderQueue();
            const cur = queueBody && queueBody.querySelector('.current');
            if (cur) cur.scrollIntoView({ block: 'center' });
        }
    }

    if (queuePanel && queueBody) {
        if (queueBtn) queueBtn.addEventListener('click', () => setQueueOpen(queuePanel.hidden));
        const closeQueue = document.getElementById('queue-close');
        if (closeQueue) closeQueue.addEventListener('click', () => setQueueOpen(false));
        const clearQueue = document.getElementById('queue-clear');
        if (clearQueue) clearQueue.addEventListener('click', () => { queue.splice(index + 1); renderQueue(); });
        queueBody.addEventListener('click', (e) => {
            const li = e.target.closest('[data-queue-index]');
            if (!li || e.target.closest('[data-rate]')) return;
            const i = Number(li.dataset.queueIndex);
            if (e.target.closest('.queue-remove')) {
                queue.splice(i, 1);
                if (i < index) index -= 1;
                renderQueue();
                return;
            }
            if (i !== index) load(i);
        });
    }

    const fmt = (s) => {
        if (!isFinite(s)) return '0:00';
        const m = Math.floor(s / 60);
        const sec = Math.floor(s % 60).toString().padStart(2, '0');
        return `${m}:${sec}`;
    };

    function trackData(el) {
        return {
            id: el.dataset.trackId,
            title: el.dataset.trackTitle || I18N.t('js.common.unknown'),
            artist: el.dataset.trackArtist || '',
            album: el.dataset.trackAlbum || '',
            url: el.dataset.trackUrl || '',
            artistUrl: el.dataset.trackArtistUrl || '',
            albumUrl: el.dataset.trackAlbumUrl || '',
            stream: el.dataset.trackStream,
            cover: el.dataset.trackCover || '',
            el,
        };
    }

    function collectQueue() {
        return Array.from(document.querySelectorAll('[data-track]')).map(trackData);
    }

    function highlight() {
        document.querySelectorAll('[data-track].playing').forEach((e) => e.classList.remove('playing'));
        const cur = queue[index];
        if (cur && cur.el) cur.el.classList.add('playing');
    }

    function load(i) {
        if (i < 0 || i >= queue.length) return;
        index = i;
        recorded = false;
        const t = queue[i];
        audio.src = t.stream;
        fetchInfo(t);
        // Title → track details, artist / album → their pages (nav.js keeps
        // playback going while browsing there).
        const link = (a, text, href) => {
            a.textContent = text;
            if (href) a.setAttribute('href', href); else a.removeAttribute('href');
        };
        link(els.title, t.title, t.url);
        link(els.artist, t.artist, t.artistUrl);
        if (els.album) {
            link(els.album, t.album, t.albumUrl);
            els.album.hidden = !t.album;
            els.albumSep.hidden = !t.album || !t.artist;
        }
        if (t.cover) { els.cover.src = t.cover; els.cover.style.visibility = 'visible'; }
        else { els.cover.removeAttribute('src'); }
        if (playerRate) fillStars(playerRate, t);
        bar.hidden = false;
        audio.play().catch(() => {});
        highlight();
        updateMediaSession(t);
        renderQueue();
    }

    // Metadata + action handlers for the OS media controls and the PiP window.
    // Re-applied on every track change AND when entering PiP, because the muted
    // canvas <video> that drives PiP can otherwise steal the media session and
    // drop the previous/next-track buttons.
    function updateMediaSession(t) {
        if (!('mediaSession' in navigator)) return;
        try {
            navigator.mediaSession.metadata = new MediaMetadata({
                title: t ? t.title : (els.title.textContent || ''),
                artist: t ? t.artist : (els.artist.textContent || ''),
                album: t ? (t.album || '') : (els.album ? els.album.textContent : ''),
                artwork: (t && t.cover) ? [{ src: t.cover, sizes: '512x512' }] : [],
            });
        } catch { /* MediaMetadata unavailable */ }
        const set = (action, fn) => {
            try { navigator.mediaSession.setActionHandler(action, fn); } catch { /* unsupported action */ }
        };
        set('play', () => audio.play());
        set('pause', () => audio.pause());
        set('previoustrack', () => load(index - 1));
        set('nexttrack', () => load(index + 1));
        set('seekto', (e) => { if (e.seekTime != null && isFinite(e.seekTime)) audio.currentTime = e.seekTime; });
    }

    function playFrom(el) {
        queue = collectQueue();
        const id = el.dataset.trackId;
        const i = queue.findIndex((t) => t.id === id && t.el === el);
        load(i === -1 ? 0 : i);
    }

    // Queue inserts used by the context menu. When nothing is playing yet,
    // start playback immediately; otherwise splice into / append to the queue.
    function playNext(t) {
        if (index < 0) { queue = [t]; load(0); return; }
        queue.splice(index + 1, 0, t);
        renderQueue();
        toast(I18N.t('js.player.playing_next', { title: t.title }));
    }
    function addToQueue(t) {
        if (index < 0) { queue = [t]; load(0); return; }
        queue.push(t);
        renderQueue();
        toast(I18N.t('js.player.added_to_queue', { title: t.title }));
    }

    function recordListen() {
        if (recorded || !cfg.recordUrl) return;
        recorded = true;
        const t = queue[index];
        if (!t) return;
        const url = cfg.recordUrl.replace(/\/\d+$/, '/' + t.id);
        fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'fetch' } }).catch(() => {});
    }

    // Lightweight transient toast.
    let toastTimer = null;
    function toast(msg) {
        let t = document.getElementById('app-toast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'app-toast';
            t.style.cssText = 'position:fixed;left:50%;bottom:calc(var(--player-h) + 16px);transform:translateX(-50%);'
                + 'background:#2a2a2a;color:#fff;padding:10px 18px;border-radius:999px;font-size:13px;font-weight:600;'
                + 'box-shadow:0 8px 28px rgba(0,0,0,.55);z-index:300;opacity:0;transition:opacity .2s;pointer-events:none;';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        requestAnimationFrame(() => { t.style.opacity = '1'; });
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { t.style.opacity = '0'; }, 2200);
    }

    // Delegate clicks on tracks / play buttons.
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-play]');
        if (trigger) {
            e.preventDefault();
            const host = trigger.closest('[data-track]') || trigger;
            playFrom(host);
        }
    });

    // "Play all" / "Add all to queue" buttons: data-play-all or
    // data-queue-all holds a CSS selector of the block whose tracks they take.
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-play-all], [data-queue-all]');
        if (!btn) return;
        e.preventDefault();
        const scope = document.querySelector(btn.dataset.playAll || btn.dataset.queueAll || 'main') || document;
        const tracks = Array.from(scope.querySelectorAll('[data-track]')).map(trackData);
        if (tracks.length === 0) return;
        if (btn.hasAttribute('data-play-all') || index < 0) {
            queue = tracks;
            load(0);
            return;
        }
        queue.push(...tracks);
        renderQueue();
        toast(I18N.t('js.player.tracks_added_to_queue', { count: tracks.length }));
    });

    els.toggle.addEventListener('click', () => (audio.paused ? audio.play() : audio.pause()));
    els.prev.addEventListener('click', () => load(index - 1));
    els.next.addEventListener('click', () => load(index + 1));

    const setPlaybackState = (s) => { if ('mediaSession' in navigator) navigator.mediaSession.playbackState = s; };
    audio.addEventListener('play', () => {
        els.toggle.textContent = '⏸';
        setPlaybackState('playing');
        if (engine.ctx && engine.ctx.state !== 'running') engine.ctx.resume().catch(() => {});
    });
    audio.addEventListener('pause', () => { els.toggle.textContent = '▶'; setPlaybackState('paused'); });
    audio.addEventListener('ended', () => load(index + 1));
    audio.addEventListener('loadedmetadata', () => { els.duration.textContent = fmt(audio.duration); });
    audio.addEventListener('timeupdate', () => {
        els.current.textContent = fmt(audio.currentTime);
        if (audio.duration) els.seek.value = String((audio.currentTime / audio.duration) * 100);
        if (audio.currentTime >= 30) recordListen();
        syncLyrics();
        // Feed the real position to the OS/PiP scrubber.
        if ('mediaSession' in navigator && navigator.mediaSession.setPositionState && audio.duration) {
            try {
                navigator.mediaSession.setPositionState({
                    duration: audio.duration,
                    position: Math.min(audio.currentTime, audio.duration),
                    playbackRate: audio.playbackRate || 1,
                });
            } catch { /* invalid state during transitions */ }
        }
    });
    els.seek.addEventListener('input', () => {
        if (audio.duration) audio.currentTime = (els.seek.value / 100) * audio.duration;
    });
    els.volume.addEventListener('input', () => { audio.volume = els.volume.value / 100; });

    document.addEventListener('keydown', (e) => {
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable) return;
        if (e.code === 'Space') { e.preventDefault(); audio.paused ? audio.play() : audio.pause(); }
        if (e.code === 'KeyL' && !e.metaKey && !e.ctrlKey && !e.altKey && lyricsPanel && !bar.hidden) setLyricsOpen(lyricsPanel.hidden);
        if (e.key === 'Escape' && lyricsPanel && !lyricsPanel.hidden) setLyricsOpen(false);
        if (e.code === 'KeyQ' && !e.metaKey && !e.ctrlKey && !e.altKey && queuePanel && !bar.hidden) setQueueOpen(queuePanel.hidden);
        if (e.key === 'Escape' && queuePanel && !queuePanel.hidden) setQueueOpen(false);
    });

    // ---------------- Mini player (Picture-in-Picture) ----------------
    // Renders cover + title/artist + progress onto an offscreen canvas, captures
    // it as a video stream, and pops it into the OS-level PiP window (stays
    // above every tab and app). Ported from the legacy canvas→video→PiP trick;
    // the button hides itself where video PiP is unavailable.
    (function initPip() {
        const btn = els.pip;
        if (!btn) return;
        const supported = document.pictureInPictureEnabled
            && typeof HTMLCanvasElement.prototype.captureStream === 'function';
        if (!supported) { btn.hidden = true; return; }
        btn.hidden = false;

        const canvas = document.createElement('canvas');
        canvas.width = 500; canvas.height = 500;
        const ctx = canvas.getContext('2d');
        const video = document.createElement('video');
        video.muted = true; video.playsInline = true;
        video.style.cssText = 'position:fixed;left:-10px;bottom:-10px;width:1px;height:1px;opacity:0;pointer-events:none;';
        document.body.appendChild(video);

        const cover = new Image(); // same-origin (/cover/{id}) → canvas not tainted
        let coverSrc = '';
        let drawTimer = null;

        const ellipsize = (text, maxWidth) => {
            if (ctx.measureText(text).width <= maxWidth) return text;
            let s = text;
            while (s.length > 1 && ctx.measureText(s + '…').width > maxWidth) s = s.slice(0, -1);
            return s + '…';
        };

        function draw() {
            const w = canvas.width, h = canvas.height;
            const src = els.cover.getAttribute('src');
            if (src && src !== coverSrc) { coverSrc = src; cover.src = src; }

            ctx.fillStyle = '#0f0f0f'; ctx.fillRect(0, 0, w, h);
            if (cover.complete && cover.naturalWidth) {
                const ar = cover.naturalWidth / cover.naturalHeight;
                let dw = w, dh = w / ar;
                if (dh < h) { dh = h; dw = h * ar; }
                ctx.drawImage(cover, (w - dw) / 2, (h - dh) / 2, dw, dh);
            }
            ctx.fillStyle = 'rgba(0,0,0,0.5)'; ctx.fillRect(0, 0, w, h);

            ctx.textAlign = 'center';
            ctx.fillStyle = '#fff';
            ctx.font = 'bold 34px Roboto, Arial, sans-serif';
            ctx.fillText(ellipsize(els.title.textContent || I18N.t('js.player.not_playing'), w - 60), w / 2, h / 2 - 4);
            ctx.fillStyle = 'rgba(255,255,255,0.75)';
            ctx.font = '24px Roboto, Arial, sans-serif';
            ctx.fillText(ellipsize(els.artist.textContent || '', w - 60), w / 2, h / 2 + 34);

            const pct = audio.duration ? audio.currentTime / audio.duration : 0;
            const bx = 40, bw = w - 80, by = h - 46;
            ctx.fillStyle = 'rgba(255,255,255,0.25)'; ctx.fillRect(bx, by, bw, 6);
            ctx.fillStyle = '#ff0033'; ctx.fillRect(bx, by, bw * pct, 6);
            ctx.fillStyle = 'rgba(255,255,255,0.7)';
            ctx.font = '18px Roboto, Arial, sans-serif';
            ctx.textAlign = 'left'; ctx.fillText(fmt(audio.currentTime), bx, by - 12);
            ctx.textAlign = 'right'; ctx.fillText(fmt(audio.duration || 0), bx + bw, by - 12);
        }

        const startDraw = () => { if (!drawTimer) { draw(); drawTimer = setInterval(draw, 500); } };
        const stopDraw = () => { clearInterval(drawTimer); drawTimer = null; };

        btn.addEventListener('click', () => {
            if (document.pictureInPictureElement) { document.exitPictureInPicture().catch(() => {}); return; }
            if (!video.srcObject) video.srcObject = canvas.captureStream();
            startDraw();
            video.play().then(() => video.requestPictureInPicture()).catch(() => stopDraw());
        });
        video.addEventListener('enterpictureinpicture', () => {
            btn.classList.add('active');
            startDraw();
            // Re-assert metadata + prev/next handlers: starting the muted video
            // can steal the media session, which is what drops the prev/next
            // buttons from the PiP window. Re-applying restores them.
            updateMediaSession(queue[index]);
        });
        video.addEventListener('leavepictureinpicture', () => { btn.classList.remove('active'); stopDraw(); });
    })();

    // Favorite toggling (data-fav-toggle with data-fav-url). SVG-based buttons
    // only get a class flip; legacy text buttons (song page) still get their
    // glyph/label swapped so nothing there regresses.
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-fav-toggle]');
        if (!btn) return;
        e.preventDefault();
        fetch(btn.dataset.favUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'fetch', 'X-CSRF-TOKEN': cfg.favoriteCsrf || '' },
        })
            .then((r) => r.json())
            .then((d) => {
                btn.classList.toggle('fav-on', !!d.favorited);
                if (!btn.querySelector('svg')) btn.textContent = d.favorited ? '♥' : '♡';
            })
            .catch(() => {});
    });

    // Star rating (partials/_rating.html.twig): a click on the Nth star rates
    // the track N; on its current value it removes the rating. Optimistic UI,
    // reverted if the server refuses.
    document.addEventListener('click', (e) => {
        const star = e.target.closest('[data-rate]');
        const box = star && star.closest('[data-rate-url]');
        if (!box) return;
        e.preventDefault();
        e.stopPropagation();
        const before = Number(box.dataset.stars || 0);
        const n = Number(star.dataset.rate);
        const stars = n === before ? 0 : n;
        // Every box of this track (list, player bar, queue) and its queue entries.
        const url = box.dataset.rateUrl;
        const paint = (v) => {
            queue.forEach((t) => { if (rateUrl(t.id) === url) t.rating = v; });
            document.querySelectorAll('[data-rate-url]').forEach((other) => {
                if (other.dataset.rateUrl !== url) return;
                other.dataset.stars = String(v);
                other.classList.toggle('rated', v > 0);
                other.querySelectorAll('[data-rate]').forEach((b) => {
                    const k = Number(b.dataset.rate);
                    b.classList.toggle('on', k <= v);
                    b.setAttribute('aria-checked', k === v ? 'true' : 'false');
                });
            });
        };
        paint(stars);
        fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'fetch', 'X-CSRF-TOKEN': cfg.ratingCsrf || '', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'stars=' + stars,
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r)))
            .then((d) => paint(Number(d.stars)))
            .catch(() => paint(before));
    });

    // Listen Later / Offline toggling for the standalone buttons on the song
    // detail page (data-ll-toggle / data-off-toggle). The kebab menu uses its
    // own handler (toggleByUrl) below.
    function bindToggle(attr, urlTpl, csrfToken, cssClass, onIcon, offIcon, resultKey) {
        if (!urlTpl) return;
        document.addEventListener('click', (e) => {
            const btn = e.target.closest(`[${attr}]`);
            if (!btn) return;
            e.preventDefault();
            const url = urlTpl.replace(/\/\d+$/, '/' + btn.dataset.trackId);
            fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'fetch', 'X-CSRF-TOKEN': csrfToken } })
                .then((r) => r.json())
                .then((d) => {
                    const on = !!d[resultKey];
                    btn.classList.toggle(cssClass, on);
                    if (!btn.querySelector('svg')) btn.textContent = on ? onIcon : offIcon;
                })
                .catch(() => {});
        });
    }
    bindToggle('data-ll-toggle', cfg.listenLaterToggleUrl, cfg.listenLaterCsrf, 'll-on', '⏰', '🕐', 'listenLater');
    bindToggle('data-off-toggle', cfg.offlineToggleUrl, cfg.offlineCsrf, 'off-on', '⬇', '☁', 'offline');

    function toggleByUrl(urlTpl, csrf, id) {
        if (!urlTpl) return;
        fetch(urlTpl.replace(/\/\d+$/, '/' + id), {
            method: 'POST', headers: { 'X-Requested-With': 'fetch', 'X-CSRF-TOKEN': csrf },
        }).then((r) => r.json()).then((d) => {
            if (d && d.listenLater !== undefined) toast(I18N.t(d.listenLater ? 'js.player.listen_later.added' : 'js.player.listen_later.removed'));
            else if (d && d.offline !== undefined) toast(I18N.t(d.offline ? 'js.player.offline.saved' : 'js.player.offline.removed'));
        }).catch(() => {});
    }

    /* ---------------- Context menu (⋮) ---------------- */
    const menu = document.getElementById('track-menu');

    function closeMenu() {
        if (!menu) return;
        menu.classList.remove('open');
        menu.replaceChildren();
    }

    function menuItem(iconPath, label, fn) {
        const b = document.createElement('button');
        b.type = 'button';
        const ic = document.createElement('span');
        ic.className = 'cm-icon';
        ic.appendChild(iconSvg(iconPath));
        const lb = document.createElement('span');
        lb.textContent = label;
        b.append(ic, lb);
        b.addEventListener('click', () => { closeMenu(); fn(); });
        return b;
    }
    function menuLink(iconPath, label, href, download) {
        const a = document.createElement('a');
        a.href = href;
        if (download) a.setAttribute('download', '');
        const ic = document.createElement('span');
        ic.className = 'cm-icon';
        ic.appendChild(iconSvg(iconPath));
        const lb = document.createElement('span');
        lb.textContent = label;
        a.append(ic, lb);
        a.addEventListener('click', () => closeMenu());
        return a;
    }
    const menuSep = () => document.createElement('hr');

    function buildMenu(k) {
        const row = k.closest('[data-track]');
        const d = k.dataset;
        menu.replaceChildren();

        const title = document.createElement('div');
        title.className = 'cm-title';
        title.textContent = d.title || I18N.t('js.player.menu.track_fallback');
        menu.appendChild(title);

        if (row) {
            menu.appendChild(menuItem(ICON.play, I18N.t('js.common.play'), () => playFrom(row)));
            menu.appendChild(menuItem(ICON.next, I18N.t('js.player.menu.play_next'), () => playNext(trackData(row))));
            menu.appendChild(menuItem(ICON.queue, I18N.t('js.player.menu.add_to_queue'), () => addToQueue(trackData(row))));
        }
        menu.appendChild(menuSep());
        if (d.artistUrl) menu.appendChild(menuLink(ICON.artist, I18N.t('js.player.menu.go_to_artist'), d.artistUrl));
        if (d.albumUrl) menu.appendChild(menuLink(ICON.album, I18N.t('js.player.menu.go_to_album'), d.albumUrl));
        if (d.songUrl) menu.appendChild(menuLink(ICON.info, I18N.t('js.player.menu.view_metadata'), d.songUrl));
        if (d.downloadUrl) menu.appendChild(menuLink(ICON.download, I18N.t('js.common.download'), d.downloadUrl, true));

        if (d.user) {
            menu.appendChild(menuSep());
            const favBtn = row ? row.querySelector('.track-fav') : null;
            if (favBtn) {
                const on = favBtn.classList.contains('fav-on');
                menu.appendChild(menuItem(ICON.heart, I18N.t(on ? 'js.player.menu.remove_favorite' : 'js.player.menu.add_favorite'), () => favBtn.click()));
            }
            if (cfg.listenLaterToggleUrl) {
                menu.appendChild(menuItem(ICON.clock, I18N.t(d.llOn ? 'js.player.menu.remove_listen_later' : 'js.player.menu.add_listen_later'),
                    () => toggleByUrl(cfg.listenLaterToggleUrl, cfg.listenLaterCsrf, d.id)));
            }
            if (cfg.offlineToggleUrl) {
                menu.appendChild(menuItem(ICON.cloud, I18N.t(d.offOn ? 'js.player.menu.remove_offline' : 'js.player.menu.save_offline'),
                    () => toggleByUrl(cfg.offlineToggleUrl, cfg.offlineCsrf, d.id)));
            }
            if (d.editUrl) {
                menu.appendChild(menuSep());
                menu.appendChild(menuLink(ICON.edit, I18N.t('js.player.menu.edit_metadata'), d.editUrl));
            }
            if (cfg.banUrl && d.id) {
                if (!d.editUrl) menu.appendChild(menuSep());
                menu.appendChild(menuItem(ICON.ban, I18N.t('js.player.menu.ban'), () => banTrack(d.id, d.title, row)));
            }
        }
    }

    // "Not this song": its recording is fingerprinted and refused from now on
    // for this track (BanController); the file leaves the library.
    function banTrack(id, title, row) {
        if (!window.confirm(I18N.t('js.player.ban.confirm', { title: title || I18N.t('js.player.ban.this_track') }))) return;
        fetch(cfg.banUrl.replace(/\/0\/ban$/, `/${id}/ban`), {
            method: 'POST',
            headers: { 'X-Requested-With': 'fetch', 'X-CSRF-TOKEN': cfg.banCsrf || '' },
        })
            .then((r) => r.json().then((d) => (r.ok ? d : Promise.reject(d))))
            .then((d) => {
                if (d.removed) {
                    if (row && !row.classList.contains('song-detail')) row.remove();
                    for (let i = queue.length - 1; i >= 0; i--) {
                        if (String(queue[i].id) === String(id) && i !== index) {
                            queue.splice(i, 1);
                            if (i < index) index -= 1;
                        }
                    }
                    renderQueue();
                }
                toast(d.message || I18N.t('js.player.ban.done'));
            })
            .catch((d) => toast(I18N.t('js.player.ban.failed', { error: (d && d.error) || I18N.t('js.player.ban.error') })));
    }

    function openMenu(k) {
        buildMenu(k);
        menu.classList.add('open');
        const r = k.getBoundingClientRect();
        const mw = menu.offsetWidth || 240;
        const mh = menu.offsetHeight || 300;
        let left = r.right - mw;
        let top = r.bottom + 6;
        if (left < 8) left = 8;
        if (left + mw > window.innerWidth - 8) left = window.innerWidth - mw - 8;
        if (top + mh > window.innerHeight - 8) top = r.top - mh - 6;
        if (top < 8) top = 8;
        menu.style.left = `${left}px`;
        menu.style.top = `${top}px`;
    }

    if (menu) {
        document.addEventListener('click', (e) => {
            const k = e.target.closest('[data-menu]');
            if (k) {
                e.preventDefault();
                if (menu.classList.contains('open') && menu.dataset.for === (k.dataset.id || '')) { closeMenu(); return; }
                menu.dataset.for = k.dataset.id || '';
                openMenu(k);
                return;
            }
            if (!e.target.closest('#track-menu')) closeMenu();
        });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenu(); });
        window.addEventListener('resize', closeMenu);
        window.addEventListener('scroll', closeMenu, true);
    }
}

// Per-track audio settings (volume multiplier + 5-band EQ), scoped to the
// song detail page. All URLs/tokens come from data-* attributes rendered by
// Twig — never mix JS state into a {{ }} expression.
//
// This is page-specific init: it must run on the initial load AND again after
// every partial navigation (nav.js swaps <main> without reloading player.js).
// It is therefore exposed via window.appPageInit() rather than an IIFE. The
// document-level delegated handlers above (play / favorite / context menu /
// toggles) are bound once and automatically see swapped-in content, so they
// must NOT be re-registered here.
function initSongAudioSettings() {
    const host = document.querySelector('.song-detail[data-settings-get-url]');
    const saveBtn = document.getElementById('sas-save-btn');
    const resetBtn = document.getElementById('sas-reset-btn');
    if (!host || !saveBtn || !resetBtn) return;

    const volumeInput = document.getElementById('sas-volume');
    const volumeOut = document.getElementById('sas-volume-out');
    const eqInputs = Array.from(document.querySelectorAll('.sas-eq-band'));
    const status = document.getElementById('sas-status');

    const applyValues = (volume, eq) => {
        const vol = typeof volume === 'number' ? volume : 1.0;
        volumeInput.value = String(vol);
        volumeOut.textContent = vol.toFixed(2);
        const bands = Array.isArray(eq) && eq.length === eqInputs.length ? eq : [0, 0, 0, 0, 0];
        eqInputs.forEach((input, i) => { input.value = String(bands[i] ?? 0); });
    };

    // Live preview in the player when this song is the one playing.
    const songId = (host.dataset.settingsGetUrl.match(/\/song\/(\d+)\//) || [])[1];
    const preview = () => {
        if (window.appPlayer) {
            window.appPlayer.previewSettings(songId, parseFloat(volumeInput.value), eqInputs.map((input) => parseFloat(input.value)));
        }
    };

    volumeInput.addEventListener('input', () => {
        volumeOut.textContent = parseFloat(volumeInput.value).toFixed(2);
        preview();
    });
    eqInputs.forEach((input) => input.addEventListener('input', preview));

    fetch(host.dataset.settingsGetUrl, { headers: { 'X-Requested-With': 'fetch' } })
        .then((r) => r.json())
        .then((d) => applyValues(d.volume_multiplier, d.eq_bands))
        .catch(() => {});

    saveBtn.addEventListener('click', () => {
        const eq = eqInputs.map((input) => parseFloat(input.value));
        fetch(host.dataset.settingsSaveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': host.dataset.settingsCsrf },
            body: JSON.stringify({ volume: parseFloat(volumeInput.value), eq }),
        })
            .then((r) => r.json())
            .then((d) => { status.textContent = d.status === 'success' ? I18N.t('js.player.audio_settings.saved') : (d.error || I18N.t('js.player.audio_settings.save_failed')); })
            .catch(() => { status.textContent = I18N.t('js.player.audio_settings.save_failed'); });
    });

    resetBtn.addEventListener('click', () => {
        fetch(host.dataset.settingsResetUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': host.dataset.settingsCsrf },
            body: '{}',
        })
            .then((r) => r.json())
            .then((d) => {
                if (d.status === 'success') { applyValues(1.0, [0, 0, 0, 0, 0]); preview(); status.textContent = I18N.t('js.player.audio_settings.reset_done'); }
                else { status.textContent = d.error || I18N.t('js.player.audio_settings.reset_failed'); }
            })
            .catch(() => { status.textContent = I18N.t('js.player.audio_settings.reset_failed'); });
    });
}

// Song page: synced lyrics are shown as plain text (timestamps stripped);
// the player's lyrics panel is the one that scrolls them.
function initSongLyrics() {
    const body = document.querySelector('.lyrics-body');
    if (!body || body.dataset.lrcDone) return;
    body.dataset.lrcDone = '1';
    const lines = parseLrc(body.textContent);
    if (lines.length > 0) body.textContent = lines.map((l) => l.text).join('\n');
}

// Page-specific initialisation hook. nav.js calls this after every partial
// navigation; we also run it once for the initial full page load.
window.appPageInit = function appPageInit() {
    initSongAudioSettings();
    initSongLyrics();
};
window.appPageInit();
