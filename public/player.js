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
};
const SVG_NS = 'http://www.w3.org/2000/svg';
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

    const fmt = (s) => {
        if (!isFinite(s)) return '0:00';
        const m = Math.floor(s / 60);
        const sec = Math.floor(s % 60).toString().padStart(2, '0');
        return `${m}:${sec}`;
    };

    function trackData(el) {
        return {
            id: el.dataset.trackId,
            title: el.dataset.trackTitle || 'Unknown',
            artist: el.dataset.trackArtist || '',
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
        els.title.textContent = t.title;
        els.artist.textContent = t.artist;
        if (t.cover) { els.cover.src = t.cover; els.cover.style.visibility = 'visible'; }
        else { els.cover.removeAttribute('src'); }
        bar.hidden = false;
        audio.play().catch(() => {});
        highlight();
        updateMediaSession(t);
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
        toast(`Playing next: ${t.title}`);
    }
    function addToQueue(t) {
        if (index < 0) { queue = [t]; load(0); return; }
        queue.push(t);
        toast(`Added to queue: ${t.title}`);
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

    els.toggle.addEventListener('click', () => (audio.paused ? audio.play() : audio.pause()));
    els.prev.addEventListener('click', () => load(index - 1));
    els.next.addEventListener('click', () => load(index + 1));

    const setPlaybackState = (s) => { if ('mediaSession' in navigator) navigator.mediaSession.playbackState = s; };
    audio.addEventListener('play', () => { els.toggle.textContent = '⏸'; setPlaybackState('playing'); });
    audio.addEventListener('pause', () => { els.toggle.textContent = '▶'; setPlaybackState('paused'); });
    audio.addEventListener('ended', () => load(index + 1));
    audio.addEventListener('loadedmetadata', () => { els.duration.textContent = fmt(audio.duration); });
    audio.addEventListener('timeupdate', () => {
        els.current.textContent = fmt(audio.currentTime);
        if (audio.duration) els.seek.value = String((audio.currentTime / audio.duration) * 100);
        if (audio.currentTime >= 30) recordListen();
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
        if (['INPUT', 'TEXTAREA'].includes(e.target.tagName)) return;
        if (e.code === 'Space') { e.preventDefault(); audio.paused ? audio.play() : audio.pause(); }
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
            ctx.fillText(ellipsize(els.title.textContent || 'Not playing', w - 60), w / 2, h / 2 - 4);
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
            if (d && d.listenLater !== undefined) toast(d.listenLater ? 'Added to Listen Later' : 'Removed from Listen Later');
            else if (d && d.offline !== undefined) toast(d.offline ? 'Saved offline' : 'Removed from offline');
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
        title.textContent = d.title || 'Track';
        menu.appendChild(title);

        if (row) {
            menu.appendChild(menuItem(ICON.play, 'Play', () => playFrom(row)));
            menu.appendChild(menuItem(ICON.next, 'Play next', () => playNext(trackData(row))));
            menu.appendChild(menuItem(ICON.queue, 'Add to queue', () => addToQueue(trackData(row))));
        }
        menu.appendChild(menuSep());
        if (d.artistUrl) menu.appendChild(menuLink(ICON.artist, 'Go to artist', d.artistUrl));
        if (d.albumUrl) menu.appendChild(menuLink(ICON.album, 'Go to album', d.albumUrl));
        if (d.songUrl) menu.appendChild(menuLink(ICON.info, 'View metadata', d.songUrl));
        if (d.downloadUrl) menu.appendChild(menuLink(ICON.download, 'Download', d.downloadUrl, true));

        if (d.user) {
            menu.appendChild(menuSep());
            const favBtn = row ? row.querySelector('.track-fav') : null;
            if (favBtn) {
                const on = favBtn.classList.contains('fav-on');
                menu.appendChild(menuItem(ICON.heart, on ? 'Remove from favorites' : 'Add to favorites', () => favBtn.click()));
            }
            if (cfg.listenLaterToggleUrl) {
                menu.appendChild(menuItem(ICON.clock, d.llOn ? 'Remove from Listen Later' : 'Add to Listen Later',
                    () => toggleByUrl(cfg.listenLaterToggleUrl, cfg.listenLaterCsrf, d.id)));
            }
            if (cfg.offlineToggleUrl) {
                menu.appendChild(menuItem(ICON.cloud, d.offOn ? 'Remove offline copy' : 'Save offline',
                    () => toggleByUrl(cfg.offlineToggleUrl, cfg.offlineCsrf, d.id)));
            }
            if (d.editUrl) {
                menu.appendChild(menuSep());
                menu.appendChild(menuLink(ICON.edit, 'Edit metadata', d.editUrl));
            }
        }
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

    volumeInput.addEventListener('input', () => {
        volumeOut.textContent = parseFloat(volumeInput.value).toFixed(2);
    });

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
            .then((d) => { status.textContent = d.status === 'success' ? 'Saved.' : (d.error || 'Failed to save.'); })
            .catch(() => { status.textContent = 'Failed to save.'; });
    });

    resetBtn.addEventListener('click', () => {
        fetch(host.dataset.settingsResetUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': host.dataset.settingsCsrf },
            body: '{}',
        })
            .then((r) => r.json())
            .then((d) => {
                if (d.status === 'success') { applyValues(1.0, [0, 0, 0, 0, 0]); status.textContent = 'Reset to default.'; }
                else { status.textContent = d.error || 'Failed to reset.'; }
            })
            .catch(() => { status.textContent = 'Failed to reset.'; });
    });
}

// Page-specific initialisation hook. nav.js calls this after every partial
// navigation; we also run it once for the initial full page load.
window.appPageInit = function appPageInit() {
    initSongAudioSettings();
};
window.appPageInit();
